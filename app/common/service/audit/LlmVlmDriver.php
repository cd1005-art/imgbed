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
    protected int $timeout = 20;

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
        if ($this->endpoint === '' || $this->model === '') {
            // 未配置完整 → 降级人工复审，不放行
            return ['result' => 'review', 'score' => 0, 'detail' => 'LLM 审核端点未配置完整，转人工'];
        }

        $content = file_get_contents($localPath);
        if ($content === false) {
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
            'max_tokens'  => 1024,   // 思考模式会消耗大量 token，120 会导致 content 为空
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
            'detail' => mb_substr((string)($verdict['reason'] ?? ''), 0, 200),
        ];
    }

    protected function prompt(): string
    {
        return <<<'TXT'
你是图床站点的内容审核员。审核这张图片是否允许公网托管。
判定标准：色情低俗、暴恐血腥、赌博诈骗宣传、涉政有害内容一律 reject；拿不准的给 review；正常图片 pass。
只输出一行 JSON，格式：{"verdict":"pass|reject|review","score":0-100,"reason":"简短理由"}
TXT;
    }
}
