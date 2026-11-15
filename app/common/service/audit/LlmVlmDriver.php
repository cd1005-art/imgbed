<?php
declare (strict_types = 1);

namespace app\common\service\audit;

/**
 * 多模态大模型审核驱动（OpenAI 兼容 chat completions + 图片 base64）
 *
 * 后台配置：llm_audit_endpoint / llm_audit_key / llm_audit_model
 * 任何可接图片的多模态模型都可用（qwen-vl / gpt-4o-mini / glm-4v 等）
 *
 * 提示词要求模型只输出 JSON：{"verdict":"pass|reject|review","score":0-100,"reason":"..."}
 */
class LlmVlmDriver implements AuditDriverInterface
{
    protected string $endpoint;
    protected string $apiKey;
    protected string $model;
    protected int $timeout = 12;

    public function __construct(array $config)
    {
        $this->endpoint = trim((string)($config['endpoint'] ?? ''));
        // 兼容只填 /v1 的写法：自动补全 chat completions 路径
        if ($this->endpoint !== '' && !str_contains($this->endpoint, '/chat/completions')) {
            $this->endpoint = rtrim($this->endpoint, '/') . '/chat/completions';
        }
        $this->apiKey   = trim((string)($config['key'] ?? ''));
        $this->model    = trim((string)($config['model'] ?? ''));
    }

    public function audit(string $localPath, string $mime): array
    {
        $this->timeout = max(3, min(120, (int)\app\common\model\Setting::get('audit_timeout', 12)));
        if ($this->endpoint === '' || $this->model === '') {
            // 未配置完整 → 降级人工复审，不放行
            return ['result' => 'review', 'score' => 0, 'detail' => 'LLM 审核端点未配置完整，转人工'];
        }

        // 审核前缩图：模型判断内容性质不需要原分辨率，整图 base64 会撑爆请求体（4000px 原图 ≈ 18MB）
        [$content, $mime] = $this->shrinkForAudit($localPath, $mime);
        if ($content === false || $content === null || $content === '') {
            return ['result' => 'review', 'score' => 0, 'detail' => '图片读取失败，转人工'];
        }

        $basePayload = [
            'model'    => $this->model,
            'messages' => [
                [
                    'role'    => 'user',
                    'content' => [
                        ['type' => 'text', 'text' => $this->prompt()],
                        ['type' => 'image_url', 'image_url' => ['url' => 'data:' . $mime . ';base64,' . base64_encode($content)]],
                    ],
                ],
            ],
            'temperature' => 0,
            'max_tokens'  => max(200, (int)\app\common\model\Setting::get('audit_max_tokens', 1024)),
        ];

        // 优先关闭思考模式（deepseek-v4.1 支持 thinking.type=disabled，返回更快更稳）
        $payload = $basePayload + ['thinking' => ['type' => 'disabled']];
        $res = $this->post($payload);

        // 部分模型不认识 thinking 参数会返回 400 → 回退不带该参数的请求
        if ($res === false) {
            $res = $this->post($basePayload);
        }
        if ($res === false) {
            return ['result' => 'review', 'score' => 0, 'detail' => 'LLM 调用失败（网络或接口错误）'];
        }

        return $this->parseResponse((string)$res);
    }

    /** 发送请求，失败返回 false */
    /**
     * 缩图后再 base64：最长边 1024（约 1k tokens / 150KB 请求体）
     * 缩放失败时回退原图，绝不阻断审核
     */
    protected function shrinkForAudit(string $localPath, string $mime): array
    {
        $maxSide = (int)\app\common\model\Setting::get('audit_max_side', 1024);
        if ($maxSide <= 0) {
            // 0 = 不缩图（原图送审，超大图容易 413/超时，不推荐）
            $raw = @file_get_contents($localPath);
            return [$raw === false ? false : $raw, $mime];
        }
        $maxSide = max(256, $maxSide);

        $raw = @file_get_contents($localPath);
        if ($raw === false) {
            return [false, $mime];
        }
        $info = @getimagesize($localPath);
        if (!$info) {
            return [$raw, $mime];               // 非标准图片 → 原样送审
        }
        [$w, $h] = [(int)$info[0], (int)$info[1]];
        if (max($w, $h) <= $maxSide) {
            return [$raw, $mime];               // 已经够小
        }

        $src = @imagecreatefromstring($raw);
        if (!$src) {
            return [$raw, $mime];
        }
        $scale = $maxSide / max($w, $h);
        $nw    = max(1, (int)round($w * $scale));
        $nh    = max(1, (int)round($h * $scale));
        $dst   = @imagescale($src, $nw, $nh, IMG_BICUBIC);
        imagedestroy($src);
        if (!$dst) {
            return [$raw, $mime];
        }

        ob_start();
        $ok = @imagejpeg($dst, null, 85);
        $bin = (string)ob_get_clean();
        imagedestroy($dst);

        return ($ok && $bin !== '') ? [$bin, 'image/jpeg'] : [$raw, $mime];
    }

    protected function post(array $payload)
    {
        $ch = curl_init($this->endpoint);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_TIMEOUT        => $this->timeout,
            CURLOPT_HTTPHEADER     => [
                'Content-Type: application/json',
                'Authorization: Bearer ' . $this->apiKey,
            ],
            CURLOPT_POSTFIELDS     => json_encode($payload, JSON_UNESCAPED_UNICODE),
        ]);
        $res  = curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $err  = curl_error($ch);
        curl_close($ch);

        return ($res === false || $err !== '' || $code >= 400) ? false : $res;
    }

    /** 解析 OpenAI 格式响应，提取 verdict JSON（容错截取第一个 {...}） */
    protected function parseResponse(string $raw): array
    {
        $data = json_decode($raw, true);
        $msg  = $data['choices'][0]['message'] ?? [];
        $text = (string)($msg['content'] ?? '');
        // 思考模式下结论可能出现在 reasoning_content 尾部，content 为空时回退
        if (trim($text) === '' && !empty($msg['reasoning_content'])) {
            $text = (string)$msg['reasoning_content'];
        }
        // 剥离 ```json 代码块包裹
        $text = preg_replace('/```(?:json)?\s*|```/', '', $text);

        if (!preg_match('/\{[^{}]*\}/s', $text, $m)) {
            return ['result' => 'review', 'score' => 0, 'detail' => 'LLM 输出无法解析：' . mb_substr(trim($text), 0, 100)];
        }

        $verdict = json_decode($m[0], true);
        $result  = in_array(($verdict['verdict'] ?? ''), ['pass', 'reject', 'review'], true)
            ? $verdict['verdict'] : 'review';

        return [
            'result' => $result,
            'score'  => max(0, min(100, (int)($verdict['score'] ?? 0))),
            'title'  => mb_substr(trim((string)($verdict['title'] ?? '')), 0, 40),
            'detail' => mb_substr((string)($verdict['reason'] ?? ''), 0, 200),
            'tags'   => \app\common\service\TagService::parse($verdict['tags'] ?? [], 5),
        ];
    }

    /** 内置默认提示词（后台提示词留空时使用；也可作为自定义的参考格式） */
    public static function defaultPrompt(): string
    {
        return <<<'TXT'
你是图床站点的内容审核员。审核这张图片是否允许公网托管。
判定标准：色情低俗、暴恐血腥、赌博诈骗宣传、涉政有害内容一律 reject；拿不准的给 review；正常图片 pass。
只输出一行 JSON，格式：
{"verdict":"pass|reject|review","score":0-100,"title":"图片标题","reason":"判定理由","tags":["标签1","标签2"]}

title 要求（会被直接用作图片标题，务必干净）：
- 20 字以内，只描述画面主体、风格、用途或色调，例如「动漫少女坐书桌旁」「深色渐变几何壁纸」
- 严禁出现任何判定/审核用语，如正常、违规、合规、需复核、建议人工、疑似、可能被联想为、无不当内容等
- 不要写句号，不要写"这张图片是…"这类开头

reason 要求：可以写判定理由和不确定点（如"人物着装暴露，建议人工复核"），这部分只进审核日志，不会作为标题。

标签要求：{{N}} 个以内，2~6 个汉字，描述主体/风格/用途/色调（如：猫、插画、壁纸、深色），禁止人名身份隐私；无合适标签输出空数组。
TXT;
    }

    protected function prompt(): string
    {
        // 自定义审核提示词（后台可改），未填则用内置模板
        $custom = trim((string)\app\common\model\Setting::get('audit_prompt', ''));
        if ($custom !== '') {
            $count = max(1, (int)\app\common\model\Setting::get('audit_tag_count', 5));
            return str_replace('{{N}}', (string)$count, $custom);
        }
        $count = max(1, (int)\app\common\model\Setting::get('audit_tag_count', 5));
        $tpl = <<<'TXT'
你是图床站点的内容审核员。审核这张图片是否允许公网托管。
判定标准：色情低俗、暴恐血腥、赌博诈骗宣传、涉政有害内容一律 reject；拿不准的给 review；正常图片 pass。
只输出一行 JSON，格式：{"verdict":"pass|reject|review","score":0-100,"reason":"简短理由","tags":["标签1","标签2"]}
标签要求：{{N}} 个以内，2~6 个汉字，描述主体/风格/用途/色调（如：猫、插画、壁纸、深色），禁止人名身份隐私；无合适标签输出空数组。
TXT;
        return str_replace('{{N}}', (string)$count, $tpl);
    }
}
