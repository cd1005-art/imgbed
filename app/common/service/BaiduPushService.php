<?php
declare (strict_types = 1);

namespace app\common\service;

use app\common\model\Setting;
use think\facade\Log;

/**
 * 百度搜索资源平台 - 普通收录 API 主动推送
 * 文档：https://ziyuan.baidu.com/college/courseinfo?id=267&page=2
 * 接口：POST http://data.zz.baidu.com/urls?site={site}&token={token}
 */
class BaiduPushService
{
    /** 单次推送上限（官方建议 ≤1000，这里保守 100，避免一次打满配额） */
    public const BATCH = 100;

    public static function enabled(): bool
    {
        return (int)Setting::get('baidu_push_enable', 0) === 1
            && trim((string)Setting::get('baidu_push_token', '')) !== '';
    }

    /**
     * 推送一批 URL
     * @param string[] $urls
     * @return array{ok:bool,message:string,success:int,remain:int}
     */
    public static function push(array $urls): array
    {
        $urls = array_values(array_filter(array_map('trim', $urls)));
        if (!$urls) {
            return ['ok' => false, 'message' => '没有可推送的 URL', 'success' => 0, 'remain' => 0];
        }
        $site  = rtrim(trim((string)Setting::get('baidu_push_site', 'https://img.oddpp.com')), '/');
        $token = trim((string)Setting::get('baidu_push_token', ''));
        if ($site === '' || $token === '') {
            return ['ok' => false, 'message' => '未配置 site 或 token', 'success' => 0, 'remain' => 0];
        }

        // 百度接口不接受 URL 编码后的 site（编码会返回 400 site init fail），必须原样传
        $endpoint = 'http://data.zz.baidu.com/urls?site=' . $site . '&token=' . $token;
        $body = implode("\n", array_slice($urls, 0, self::BATCH)) . "\n";

        $ch = @curl_init($endpoint);
        if (!$ch) {
            return ['ok' => false, 'message' => 'curl 初始化失败', 'success' => 0, 'remain' => 0];
        }
        @curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_TIMEOUT        => 30,
            CURLOPT_HTTPHEADER     => ['Content-Type: text/plain'],
            CURLOPT_POSTFIELDS     => $body,
        ]);
        $raw  = (string)@curl_exec($ch);
        $code = (int)@curl_getinfo($ch, CURLINFO_HTTP_CODE);
        @curl_close($ch);

        $data = json_decode($raw, true);
        if (is_array($data) && isset($data['success'])) {
            $ret = [
                'ok'      => true,
                'message' => '推送成功 ' . (int)$data['success'] . ' 条，剩余配额 ' . (int)($data['remain'] ?? 0),
                'success' => (int)$data['success'],
                'remain'  => (int)($data['remain'] ?? 0),
            ];
        } else {
            $err = is_array($data)
                ? ('错误 ' . ($data['error'] ?? '?') . '：' . ($data['message'] ?? '未知'))
                : ('HTTP ' . $code . '：' . mb_substr($raw, 0, 200));
            $ret = ['ok' => false, 'message' => '推送失败（' . $err . '）', 'success' => 0, 'remain' => 0];
        }

        Setting::saveKey('baidu_push_last_result', $ret['message']);
        Setting::saveKey('baidu_push_last_time', (string)time());
        Log::record('百度推送：' . $ret['message'], 'info');
        return $ret;
    }

    /** 生成新图后自动推送单条（失败静默，不影响生成） */
    public static function pushOne(string $url): void
    {
        if (!$url || (int)Setting::get('baidu_push_auto', 0) !== 1 || !self::enabled()) {
            return;
        }
        try {
            self::push([$url]);
        } catch (\Throwable $e) {
            Log::record('百度推送异常：' . $e->getMessage(), 'error');
        }
    }
}
