<?php
declare (strict_types = 1);

namespace app\common\service\audit;

use app\common\model\Setting;
use think\facade\Db;

/**
 * 多模型审核驱动
 *
 * 两种协同模式（settings.audit_mode）：
 *  - failover（默认）：按 sort 顺序优先调用，失败自动切下一个；全部失败 → review（人工）
 *  - parallel：并行请求所有启用模型投票：任一 reject 即 reject；全部 pass 才 pass；否则 review
 *
 * audit_models 为空时回退 settings 单模型配置。
 */
class MultiModelAuditDriver implements AuditDriverInterface
{
    protected int $timeout = 20;

    public function audit(string $localPath, string $mime): array
    {
        $models = Db::name('audit_models')
            ->where('status', 1)
            ->where('purpose', 'audit')   // 只取审核模型（生成模型不参与审核）
            ->order('sort', 'asc')
            ->order('id', 'asc')
            ->select()
            ->toArray();

        if (!$models) {
            return (new LlmVlmDriver([
                'endpoint' => (string)Setting::get('llm_audit_endpoint', ''),
                'key'      => (string)Setting::get('llm_audit_key', ''),
                'model'    => (string)Setting::get('llm_audit_model', ''),
            ]))->audit($localPath, $mime);
        }

        $mode = (string)Setting::get('audit_mode', 'failover');
        return $mode === 'parallel'
            ? $this->parallel($models, $localPath, $mime)
            : $this->failover($models, $localPath, $mime);
    }

    /** 顺序切换：第一个成功判定的模型即采用 */
    protected function failover(array $models, string $localPath, string $mime): array
    {
        $errors = [];
        foreach ($models as $m) {
            $driver = (($m['type'] ?? 'llm') === 'local_nsfw')
                ? new LocalNsfwDriver(['endpoint' => (string)$m['endpoint']])
                : new LlmVlmDriver([
                    'endpoint' => (string)$m['endpoint'],
                    'key'      => (string)$m['api_key'],
                    'model'    => (string)$m['model_name'],
                ]);
            $result = $driver->audit($localPath, $mime);

            $failed = isset($result['detail']) && str_starts_with((string)$result['detail'], 'LLM 调用失败');
            if (!$failed) {
                Db::name('audit_models')->where('id', $m['id'])->update(['last_ok_time' => time(), 'fail_count' => 0]);
                $result['detail'] = '[' . $m['name'] . '] ' . ($result['detail'] ?? '');
                return $result;
            }
            Db::name('audit_models')->where('id', $m['id'])->inc('fail_count')->update();
            $errors[] = $m['name'] . '：' . ($result['detail'] ?? '失败');
        }
        return ['result' => 'review', 'score' => 0, 'detail' => '全部审核模型不可用，转人工（' . mb_substr(implode('; ', $errors), 0, 160) . '）'];
    }

    /** 并行投票：curl_multi 同时请求所有模型 */
    protected function parallel(array $models, string $localPath, string $mime): array
    {
        $content = file_get_contents($localPath);
        if ($content === false) {
            return ['result' => 'review', 'score' => 0, 'detail' => '图片读取失败'];
        }
        $dataUri = 'data:' . $mime . ';base64,' . base64_encode($content);

        $mh = curl_multi_init();
        $handles = [];
        foreach ($models as $m) {
            if (($m['type'] ?? 'llm') === 'local_nsfw') {
                continue; // 并行投票暂只聚合 LLM 模型；NSFW 服务请用顺序模式或独立卡片
            }
            $url = rtrim((string)$m['endpoint'], '/');
            if (!str_contains($url, '/chat/completions')) {
                $url .= '/chat/completions';
            }
            $payload = $this->payload((string)$m['model_name'], $dataUri);
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_POST           => true,
                CURLOPT_TIMEOUT        => $this->timeout,
                CURLOPT_HTTPHEADER     => [
                    'Content-Type: application/json',
                    'Authorization: Bearer ' . (string)$m['api_key'],
                ],
                CURLOPT_POSTFIELDS     => json_encode($payload, JSON_UNESCAPED_UNICODE),
            ]);
            curl_multi_add_handle($mh, $ch);
            $handles[] = ['ch' => $ch, 'model' => $m];
        }

        do {
            $status = curl_multi_exec($mh, $running);
            if ($running) {
                curl_multi_select($mh, 1.0);
            }
        } while ($running && $status === CURLM_OK);

        $votes = [];
        $errors = [];
        foreach ($handles as $h) {
            $raw  = (string)curl_multi_getcontent($h['ch']);
            $code = (int)curl_getinfo($h['ch'], CURLINFO_RESPONSE_CODE);
            $m    = $h['model'];
            curl_multi_remove_handle($mh, $h['ch']);
            curl_close($h['ch']);

            if ($raw === '' || $code >= 400) {
                Db::name('audit_models')->where('id', $m['id'])->inc('fail_count')->update();
                $errors[] = $m['name'] . '(HTTP ' . $code . ')';
                continue;
            }
            $r = $this->parse($raw);
            if ($r === null) {
                Db::name('audit_models')->where('id', $m['id'])->inc('fail_count')->update();
                $errors[] = $m['name'] . '(解析失败)';
                continue;
            }
            Db::name('audit_models')->where('id', $m['id'])->update(['last_ok_time' => time(), 'fail_count' => 0]);
            $votes[] = ['model' => $m['name'], 'result' => $r];
        }
        curl_multi_close($mh);

        if (!$votes) {
            return ['result' => 'review', 'score' => 0, 'detail' => '并行审核全部失败，转人工（' . mb_substr(implode('; ', $errors), 0, 140) . '）'];
        }

        // 投票：任一 reject → reject；全部 pass → pass；否则 review
        $results = array_column($votes, 'result');
        $verdicts = array_column($results, 'result');
        $maxScore = max(array_column($results, 'score') ?: [0]);
        $detail = implode(' | ', array_map(fn($v) => $v['model'] . ':' . $v['result']['result'] . '(' . $v['result']['score'] . ')', $votes));

        if (in_array('reject', $verdicts, true)) {
            return ['result' => 'reject', 'score' => $maxScore, 'detail' => '并行投票拒绝 · ' . mb_substr($detail, 0, 160)];
        }
        if (count(array_unique($verdicts)) === 1 && $verdicts[0] === 'pass') {
            return ['result' => 'pass', 'score' => $maxScore, 'detail' => '并行投票通过 · ' . mb_substr($detail, 0, 160)];
        }
        return ['result' => 'review', 'score' => $maxScore, 'detail' => '并行投票意见不一，转人工 · ' . mb_substr($detail, 0, 160)];
    }

    protected function payload(string $model, string $dataUri): array
    {
        $count = max(1, (int)\app\common\model\Setting::get('audit_tag_count', 5));
        $text = "你是图床站点的内容审核员。审核这张图片是否允许公网托管。\n"
            . "判定标准：色情低俗、暴恐血腥、赌博诈骗宣传、涉政有害内容一律 reject；拿不准的给 review；正常图片 pass。\n"
            . '只输出一行 JSON，格式：{"verdict":"pass|reject|review","score":0-100,"reason":"简短理由","tags":["标签1","标签2"]}' . "\n"
            . "标签要求：{$count} 个以内，2~6 个汉字，描述主体/风格/用途/色调（如：猫、插画、壁纸、深色），禁止人名身份隐私；无合适标签输出空数组。";

        return [
            'model'    => $model,
            'messages' => [[
                'role'    => 'user',
                'content' => [
                    ['type' => 'text', 'text' => $text],
                    ['type' => 'image_url', 'image_url' => ['url' => $dataUri]],
                ],
            ]],
            'temperature' => 0,
            'max_tokens'  => 1024,
            'thinking'    => ['type' => 'disabled'],
        ];
    }

    /** 解析响应（content 空则回退 reasoning_content；剥离代码块） */
    protected function parse(string $raw): ?array
    {
        $data = json_decode($raw, true);
        $msg  = $data['choices'][0]['message'] ?? [];
        $text = (string)($msg['content'] ?? '');
        if (trim($text) === '' && !empty($msg['reasoning_content'])) {
            $text = (string)$msg['reasoning_content'];
        }
        $text = preg_replace('/```(?:json)?\s*|```/', '', $text);
        if (!preg_match('/\{[^{}]*\}/s', $text, $m)) {
            return null;
        }
        $v = json_decode($m[0], true);
        if (!is_array($v)) {
            return null;
        }
        $result = in_array(($v['verdict'] ?? ''), ['pass', 'reject', 'review'], true) ? $v['verdict'] : 'review';
        return [
            'result' => $result,
            'score'  => max(0, min(100, (int)($v['score'] ?? 0))),
            'reason' => (string)($v['reason'] ?? ''),
            'tags'   => \app\common\service\TagService::parse($v['tags'] ?? [], 5),
        ];
    }
}
