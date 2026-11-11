<?php
declare (strict_types = 1);

namespace app\common\service\audit;

/**
 * 本地 NSFW 模型驱动：调独立 Python 推理服务（HTTP）
 *
 * 约定的推理服务接口（部署者自建，如 Falconsai/nsfw_image_detection）：
 *   POST {endpoint}  body: {"image_base64": "..."}
 *   响应:            {"score": 0-100, "label": "..."}   // score 越高越可能是 NSFW
 *
 * 后台 llm_audit_endpoint 无关；本驱动端点通过 settings.nsfw_endpoint 配置。
 */
class LocalNsfwDriver implements AuditDriverInterface
{
    protected string $endpoint;
    protected int $threshold = 70;   // score >= 70 判拒绝
    protected int $timeout   = 5;

    public function __construct(array $config)
    {
        $this->endpoint = trim((string)($config['endpoint'] ?? ''));
        if (isset($config['threshold'])) {
            $this->threshold = max(1, min(99, (int)$config['threshold']));
        }
    }

    public function audit(string $localPath, string $mime): array
    {
        if ($this->endpoint === '') {
            return ['result' => 'review', 'score' => 0, 'detail' => 'NSFW 推理服务端点未配置，转人工'];
        }

        $content = file_get_contents($localPath);
        if ($content === false) {
            return ['result' => 'review', 'score' => 0, 'detail' => '图片读取失败，转人工'];
        }

        $ch = curl_init($this->endpoint);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_TIMEOUT        => $this->timeout,
            CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
            CURLOPT_POSTFIELDS     => json_encode(['image_base64' => base64_encode($content)]),
        ]);
        $res = curl_exec($ch);
        $err = curl_error($ch);
        curl_close($ch);

        if ($res === false || $err !== '') {
            return ['result' => 'review', 'score' => 0, 'detail' => '推理服务不可达：' . $err];
        }

        $data = json_decode((string)$res, true);
        if (!is_array($data) || !isset($data['score'])) {
            return ['result' => 'review', 'score' => 0, 'detail' => '推理响应无法解析'];
        }

        $score  = max(0, min(100, (int)$data['score']));
        $result = $score >= $this->threshold ? 'reject' : 'pass';

        return ['result' => $result, 'score' => $score, 'detail' => 'NSFW 得分 ' . $score . '（阈值 ' . $this->threshold . '）'];
    }
}
