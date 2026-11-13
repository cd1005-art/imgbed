<?php
declare (strict_types = 1);

namespace app\common\service;

use think\facade\Db;

/**
 * 计划任务心跳：命令执行时写入 last_run，后台据此亮灯
 */
class CronService
{
    /** 任务定义：key => [名称, 命令, 间隔秒, 说明] */
    public const TASKS = [
        'gen' => ['name' => '造图机器人', 'cmd' => 'gen:run', 'interval' => 300, 'desc' => '每 5 分钟', 'why' => '定时生成图片并走审核/公开'],
        'storage' => ['name' => '存储告警', 'cmd' => 'monitor:storage', 'interval' => 3600, 'desc' => '每小时', 'why' => '磁盘/配额/网盘阈值检查'],
        'vip' => ['name' => 'VIP 到期提醒', 'cmd' => 'monitor:vip', 'interval' => 86400, 'desc' => '每天', 'why' => '到期前 3 天提醒邮件'],
        'site' => ['name' => '站点健康巡检', 'cmd' => 'monitor:site', 'interval' => 600, 'desc' => '每 10 分钟', 'why' => '首页可用性 + HTTPS 证书到期检查'],
    ];

    /**
     * 记录心跳
     * @param string $source cli=命令行计划任务 / url=URL计划任务 / manual=后台手动测试
     * 说明：manual 只更新时间与次数，不影响「接入状态」（灯色）
     */
    public static function beat(string $key, string $result = 'ok', string $source = 'cli', int $durationMs = 0): void
    {
        try {
            $row = Db::name('cron_heartbeats')->where('task_key', $key)->find();
            if (!$row) {
                Db::name('cron_heartbeats')->insert([
                    'task_key' => $key, 'last_run' => 0, 'last_result' => '',
                    'last_source' => '', 'source_time' => 0, 'manual_time' => 0,
                    'manual_count' => 0, 'run_count' => 0,
                ]);
                $row = Db::name('cron_heartbeats')->where('task_key', $key)->find();
            }
            // 所有来源都记账：灯色 = 最近一次执行的成败
            $failed = stripos($result, 'fail') !== false || stripos($result, 'error') !== false;
            $update = [
                'last_run'      => time(),
                'last_result'   => mb_substr($result, 0, 200),
                'last_source'   => $source,
                'source_time'   => time(),
                'run_count'     => (int)$row['run_count'] + 1,
                'last_duration' => max(0, $durationMs),
                'fail_streak'   => $failed ? ((int)$row['fail_streak'] + 1) : 0,
                'last_ok_time'  => $failed ? (int)$row['last_ok_time'] : time(),
            ];
            if ($source === 'manual') {
                // 手动执行额外记录统计（用于展示"手动验证 N 次"）
                $update['manual_time']  = time();
                $update['manual_count'] = (int)$row['manual_count'] + 1;
                $update['manual_result'] = mb_substr($result, 0, 200);
            }
            Db::name('cron_heartbeats')->where('task_key', $key)->update($update);

            // 执行历史（30 天清理）
            try {
                Db::name('cron_runs')->insert([
                    'task_key'    => $key, 'ok' => $failed ? 0 : 1,
                    'duration_ms' => max(0, $durationMs), 'result' => mb_substr($result, 0, 200),
                    'source'      => $source, 'create_time' => time(),
                ]);
                if (mt_rand(1, 20) === 1) {
                    Db::name('cron_runs')->where('create_time', '<', time() - 30 * 86400)->delete();
                }
            } catch (\Throwable $e) {
            }

            // 连续失败告警（每满 3 次发一封）/ 恢复通知
            $streak = (int)$update['fail_streak'];
            $name = self::TASKS[$key]['name'] ?? $key;
            if ($failed && $streak > 0 && $streak % 3 === 0) {
                self::notifyAdmin('cron_fail', '【计划任务连续失败】' . $name, 'cron_fail',
                    ['name' => $name, 'reason' => "已连续失败 {$streak} 次。最近：" . mb_substr($result, 0, 150)]);
            }
            if (!$failed && (int)$row['fail_streak'] >= 3) {
                self::notifyAdmin('cron_fail', '【计划任务已恢复】' . $name, 'cron_recover', ['name' => $name]);
            }
        } catch (\Throwable $e) {
        }
    }

    /** 管理员通知（按事件开关） */
    protected static function notifyAdmin(string $event, string $subject, string $template, array $vars = []): void
    {
        $to = trim((string)\app\common\model\Setting::get('admin_mail_to', ''));
        if ($to === '') {
            return;
        }
        try {
            MailerService::notify($event, $to, $subject . ' · ' . (string)\app\common\model\Setting::get('site_name', '逍遥图床'), $template, $vars);
        } catch (\Throwable $e) {
        }
    }

    /**
     * 各任务状态：state = green|gray|red
     * green: 按期执行；gray: 从未执行；red: 超期（>3×间隔）或最近结果含 fail/error
     */
    public static function status(): array
    {
        $rows = Db::name('cron_heartbeats')->select()->toArray();
        $map = [];
        foreach ($rows as $r) {
            $map[$r['task_key']] = $r;
        }
        $out = [];
        foreach (self::TASKS as $key => $def) {
            $r = $map[$key] ?? null;
            $state = 'gray';       // 未接入（从未被 CLI/URL 计划任务触发）
            $lastRun = 0;
            $lastResult = '';
            $runCount = 0;
            $source = '';
            $manualTime = 0;
            $manualCount = 0;
            $overdue = false;
            if ($r) {
                $lastRun = (int)$r['last_run'];
                $lastResult = (string)$r['last_result'];
                $runCount = (int)$r['run_count'];
                $source = (string)($r['last_source'] ?? '');
                $manualTime = (int)($r['manual_time'] ?? 0);
                $manualCount = (int)($r['manual_count'] ?? 0);

                if ($lastRun > 0) {
                    // 红 = 最近一次执行失败；绿 = 最近一次执行成功；灰 = 从未执行
                    if (stripos($lastResult, 'fail') !== false || stripos($lastResult, 'error') !== false) {
                        $state = 'red';
                    } else {
                        $state = 'green';
                    }
                }
                $overdue = $lastRun > 0 && (time() - $lastRun) > $def['interval'] * 3;
            }
            $out[] = [
                'key' => $key, 'name' => $def['name'], 'cmd' => $def['cmd'],
                'desc' => $def['desc'], 'why' => $def['why'], 'interval' => $def['interval'],
                'status' => $state, 'last_run' => $lastRun, 'last_result' => $lastResult,
                'run_count' => $runCount, 'last_source' => $source,
                'manual_time' => $manualTime, 'manual_count' => $manualCount,
                'manual_result' => (string)($r['manual_result'] ?? ''),
                'last_duration' => (int)($r['last_duration'] ?? 0),
                'fail_streak'   => (int)($r['fail_streak'] ?? 0),
                'next_eta'      => $lastRun > 0 ? $lastRun + (int)$def['interval'] : 0,
                'overdue' => $overdue,
            ];
        }
        return $out;
    }


    /**
     * 同步执行任务（web 环境 disable_functions 禁用 exec，直接同进程跑）
     * @param string $source manual=后台手动测试 / url=URL 计划任务
     */
    public static function runNow(string $key, string $source = 'manual'): array
    {
        @set_time_limit(300);
        $t0 = microtime(true);
        try {
            switch ($key) {
                case 'storage':
                    $msg = self::taskStorage();
                    break;
                case 'vip':
                    $msg = self::taskVip();
                    break;
                case 'gen':
                    $msg = self::taskGen(2); // web 手动触发限 2 张（防 nginx 超时）；定时任务走 CLI 跑全量
                    break;
                case 'site':
                    $msg = self::taskSite();
                    break;
                default:
                    return ['ok' => false, 'msg' => 'unknown task'];
            }
            self::beat($key, $msg, $source, (int)round((microtime(true) - $t0) * 1000));
            return ['ok' => true, 'msg' => $msg];
        } catch (\Throwable $e) {
            self::beat($key, 'fail: ' . mb_substr($e->getMessage(), 0, 120), $source, (int)round((microtime(true) - $t0) * 1000));
            return ['ok' => false, 'msg' => '执行失败：' . mb_substr($e->getMessage(), 0, 200)];
        }
    }

    /** 存储告警检查（与 monitor:storage 同逻辑） */
    public static function taskStorage(): string
    {
        $to = trim((string)\app\common\model\Setting::get('admin_mail_to', ''));
        // 统一口径：alert_unit = pct（剩余百分比）或 gb（剩余容量）
        $unit    = (string)\app\common\model\Setting::get('alert_unit', 'pct');
        $diskTh  = (float)\app\common\model\Setting::get('alert_disk_left_pct', 10);
        $quotaTh = (float)\app\common\model\Setting::get('alert_quota_left_pct', 10);
        $spaceTh = (float)\app\common\model\Setting::get('alert_space_left_pct', 10);

        $storages = Db::name('storage_configs')->where('status', 1)->select()->toArray();
        $alerts = [];
        foreach ($storages as $s) {
            $cfg  = json_decode((string)$s['config'], true) ?: [];
            $used = (int)Db::name('images')->where('storage_id', $s['id'])->where('status', 1)->sum('size');
            $name = (string)$s['name'];
            if ($s['driver'] === 'local') {
                $rootRel = trim(str_replace('\\', '/', (string)($cfg['root'] ?? 'storage/images')), '/');
                $root = rtrim(public_path(), '/\\') . '/' . $rootRel;
                $probe = @is_dir($root) ? $root : public_path();
                $free  = @disk_free_space($probe);
                $totalDisk = @disk_total_space($probe);
                if ($free !== false && $totalDisk !== false && $totalDisk > 0) {
                    $leftPct = $free / $totalDisk * 100;
                    $leftGb  = $free / 1073741824;
                    $leftVal = $unit === 'gb' ? $leftGb : $leftPct;
                    if ($leftVal < $diskTh) {
                        $alerts[] = sprintf('【磁盘】%s 剩余 %.1f%%（%.1f GB / 共 %.1f GB，阈值 %s %s）',
                            $name, $leftPct, $leftGb, $totalDisk / 1073741824,
                            $unit === 'gb' ? number_format($diskTh, 1) : (string)(int)$diskTh, $unit === 'gb' ? 'GB' : '%');
                    }
                }
            }
            $quotaGb = (int)($cfg['quota_gb'] ?? 0);
            if ($quotaGb > 0) {
                $leftBytes = max(0, $quotaGb * 1073741824 - $used);
                $left = $leftBytes / ($quotaGb * 1073741824) * 100;
                $leftVal = $unit === 'gb' ? $leftBytes / 1073741824 : $left;
                if ($leftVal < $quotaTh) {
                    $alerts[] = sprintf('【配额】%s 剩余 %.1f%%（%.1f GB，阈值 %s %s）', $name, $left,
                        $leftBytes / 1073741824, $unit === 'gb' ? number_format($quotaTh, 1) : (string)(int)$quotaTh, $unit === 'gb' ? 'GB' : '%');
                }
            }
            $spaceGb = (int)($cfg['space_gb'] ?? 0);
            if ($spaceGb > 0) {
                $leftBytes = max(0, $spaceGb * 1073741824 - $used);
                $left = $leftBytes / ($spaceGb * 1073741824) * 100;
                $leftVal = $unit === 'gb' ? $leftBytes / 1073741824 : $left;
                if ($leftVal < $spaceTh) {
                    $alerts[] = sprintf('【网盘空间】%s 剩余 %.1f%%（%.1f GB，阈值 %s %s）', $name, $left,
                        $leftBytes / 1073741824, $unit === 'gb' ? number_format($spaceTh, 1) : (string)(int)$spaceTh, $unit === 'gb' ? 'GB' : '%');
                }
            }
        }
        if (!$alerts) {
            return '存储正常，无告警';
        }
        $fresh = [];
        foreach ($alerts as $msg) {
            $k = 'alert_sent_' . md5($msg);
            if (time() - (int)\app\common\model\Setting::get($k, '0') < 86400) {
                continue;
            }
            \app\common\model\Setting::saveKey($k, (string)time());
            $fresh[] = $msg;
        }
        if (!$fresh) {
            return '告警存在但 24 小时内已发送';
        }
        if ($to === '') {
            return '发现告警但未配置管理员邮箱：' . implode('；', $fresh);
        }
        $ok = \app\common\service\MailerService::notify('storage_alert', $to, '【存储告警】' . (string)\app\common\model\Setting::get('site_name', '逍遥图床'),
            'storage_alert', ['reason' => implode('<br>', array_map('htmlspecialchars', $fresh))]);
        return $ok ? '告警邮件已发送' : '告警邮件发送失败';
    }

    /** VIP 到期提醒（与 monitor:vip 同逻辑） */
    /** 站点健康巡检：首页可用性 + HTTPS 证书剩余 + 域名到期 */
    public static function taskSite(): string
    {
        $domain = (string)\app\common\model\Setting::get('site_domain', '');
        if ($domain === '') {
            $domain = (string)request()->domain();
        }
        $host = parse_url($domain, PHP_URL_HOST) ?: trim($domain);
        if ($host === '') {
            $host = '127.0.0.1';
        }
        $msgs = [];
        $bad  = false;

        // 1) 首页可用性（走本机 https）
        $ch = @curl_init('https://127.0.0.1/');
        if ($ch) {
            @curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 12, CURLOPT_SSL_VERIFYPEER => false,
                CURLOPT_SSL_VERIFYHOST => false, CURLOPT_HTTPHEADER => ['Host: ' . $host],
                CURLOPT_USERAGENT => 'imgbed-monitor',
            ]);
            @curl_exec($ch);
            $code = (int)@curl_getinfo($ch, CURLINFO_HTTP_CODE);
            @curl_close($ch);
            if ($code >= 200 && $code < 400) {
                $msgs[] = "首页 {$code} 正常";
            } else {
                $bad = true;
                $msgs[] = '首页不可用（HTTP ' . ($code > 0 ? $code : '连接失败') . '）';
                self::notifyAdmin('site_down', '【站点不可用】' . $host, 'storage_alert', ['reason' => $msgs[count($msgs) - 1]]);
            }
        }

        // 2) HTTPS 证书剩余天数（复用首页请求的 CERTINFO，最稳）
        $certDays = null;
        $ch2 = @curl_init('https://127.0.0.1/');
        if ($ch2) {
            @curl_setopt_array($ch2, [
                CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 12, CURLOPT_SSL_VERIFYPEER => false,
                CURLOPT_SSL_VERIFYHOST => false, CURLOPT_CERTINFO => true,
                CURLOPT_HTTPHEADER => ['Host: ' . $host], CURLOPT_USERAGENT => 'imgbed-monitor',
            ]);
            @curl_exec($ch2);
            $ci = @curl_getinfo($ch2, CURLINFO_CERTINFO);
            @curl_close($ch2);
            $expire = '';
            if (is_array($ci) && isset($ci[0])) {
                foreach ($ci[0] as $line) {
                    if (stripos($line, 'Expire date:') === 0) {
                        $expire = trim(substr($line, strlen('Expire date:')));
                        break;
                    }
                }
            }
            if ($expire !== '') {
                $ts = strtotime($expire);
                if ($ts > 0) {
                    $certDays = (int)floor(($ts - time()) / 86400);
                }
            }
        }
        if ($certDays !== null) {
            $msgs[] = "证书剩余 {$certDays} 天";
            if ($certDays < 15) {
                $bad = true;
                $msgs[] = '⚠ 证书即将到期';
                self::notifyAdmin('cert_expire', '【证书即将到期】' . $host, 'storage_alert', ['reason' => $certDays . ' 天']);
            }
        } else {
            $msgs[] = '证书状态未知（不影响巡检结论）';
        }

        // 3) 域名到期（RDAP，0=不限；失败静默）
        try {
            $root = self::rootDomain($host);
            if ($root !== '') {
                $rdap = '';
                for ($try = 0; $try < 2 && $rdap === ''; $try++) {
                    $rch = @curl_init('https://rdap.org/domain/' . $root);
                    if (!$rch) {
                        break;
                    }
                    @curl_setopt_array($rch, [
                        CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 20, CURLOPT_CONNECTTIMEOUT => 8,
                        CURLOPT_FOLLOWLOCATION => true, CURLOPT_SSL_VERIFYPEER => false,
                        CURLOPT_USERAGENT => 'imgbed-monitor',
                    ]);
                    $rdap = (string)@curl_exec($rch);
                    @curl_close($rch);
                }
                if ($rdap !== '') {
                    $jd  = json_decode($rdap, true);
                    $exp = null;
                    foreach (($jd['events'] ?? []) as $ev) {
                        if (($ev['eventAction'] ?? '') === 'expiration') {
                            $exp = strtotime((string)$ev['eventDate']);
                        }
                    }
                    if ($exp) {
                        $days = (int)floor(($exp - time()) / 86400);
                        $msgs[] = "域名剩余 {$days} 天";
                        if ($days < 30) {
                            $bad = true;
                            $msgs[] = '⚠ 域名即将到期，请立即续费';
                            self::notifyAdmin('domain_expire', '【域名即将到期】' . $root, 'domain_expire', ['name' => $root, 'reason' => $days . ' 天']);
                        }
                    }
                }
            }
        } catch (\Throwable $e) {
        }

        $text = implode('；', $msgs);
        if ($bad) {
            self::notifyAdmin('site_down', '【站点巡检异常】' . $host, 'storage_alert', ['reason' => $text]);
            return 'fail: ' . $text;
        }
        return $text;
    }

    public static function taskVip(): string
    {
        $now = time();
        $users = Db::name('users')->where('vip_expire_time', '>', $now)
            ->where('vip_expire_time', '<=', $now + 3 * 86400)->where('status', 1)->select()->toArray();
        $sent = 0;
        foreach ($users as $u) {
            $recent = Db::name('mail_logs')->where('to_email', $u['email'])->where('template', 'vip_expire')
                ->where('create_time', '>', $now - 3 * 86400)->count();
            if ($recent > 0) {
                continue;
            }
            if (MailerService::notify('vip_expire', (string)$u['email'],
                '会员即将到期 · ' . (string)\app\common\model\Setting::get('site_name', '逍遥图床'),
                'vip_expire', ['name' => (string)$u['nickname'], 'expire_at' => date('Y-m-d', (int)$u['vip_expire_time'])])) {
                $sent++;
            }
        }
        return "检查 " . count($users) . " 个到期用户，发送 {$sent} 封提醒";
    }

    /** 判断图片是否"无意义"（过小/纯色/近纯色）；用于拦截模型返回的空白图 */
    protected static function isMeaninglessImage(string $file): bool
    {
        $size = @filesize($file);
        if ($size === false || $size < 10240) {   // <10KB 视为无效
            return true;
        }
        $info = @getimagesize($file);
        if (!$info || (int)$info[0] < 256 || (int)$info[1] < 256) {
            return true;
        }
        $im = @imagecreatefromstring((string)@file_get_contents($file));
        if (!$im) {
            return true;
        }
        $w = imagesx($im); $h = imagesy($im);
        $vals = [];
        for ($i = 0; $i < 60; $i++) {
            $x = (int)mt_rand(0, max(0, $w - 1));
            $y = (int)mt_rand(0, max(0, $h - 1));
            $rgb = imagecolorat($im, $x, $y);
            $vals[] = [($rgb >> 16) & 0xFF, ($rgb >> 8) & 0xFF, $rgb & 0xFF];
        }
        @imagedestroy($im);
        if (!$vals) {
            return true;
        }
        // 各通道方差都很小 → 视为纯色/近乎空白
        $flat = 0;
        for ($c = 0; $c < 3; $c++) {
            $arr = array_map(fn($v) => $v[$c], $vals);
            $mean = array_sum($arr) / count($arr);
            $var = 0;
            foreach ($arr as $v) { $var += ($v - $mean) ** 2; }
            $var /= count($arr);
            if ($var < 25) { $flat++; }   // 标准差 < 5
        }
        return $flat >= 2;   // 至少两个通道几乎无变化
    }

    /** 调用图像生成模型（OpenAI 兼容 /images/generations）；无模型或失败时回退 GD 占位图 */
    protected static function generateImage(int $modelId, string $prompt, string $outFile): string
    {
        if ($modelId > 0) {
            $m = Db::name('audit_models')->where('id', $modelId)->find();
            if ($m && ($m['purpose'] ?? '') === 'image_gen' && (string)$m['endpoint'] !== '') {
                $url = rtrim((string)$m['endpoint'], '/');
                if (!str_contains($url, '/images/generations')) {
                    $url .= '/images/generations';
                }
                $ch = @curl_init($url);
                if ($ch) {
                    @curl_setopt_array($ch, [
                        CURLOPT_RETURNTRANSFER => true, CURLOPT_POST => true, CURLOPT_TIMEOUT => 120,
                        CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Authorization: Bearer ' . (string)$m['api_key']],
                        CURLOPT_POSTFIELDS => json_encode([
                            'model' => (string)$m['model_name'], 'prompt' => $prompt, 'n' => 1, 'size' => '1024x1024',
                        ], JSON_UNESCAPED_UNICODE),
                    ]);
                    $raw = (string)@curl_exec($ch);
                    $code = (int)@curl_getinfo($ch, CURLINFO_HTTP_CODE);
                    @curl_close($ch);

                    $data = json_decode($raw, true);
                    $b64    = (string)($data['data'][0]['b64_json'] ?? '');
                    $imgUrl = (string)($data['data'][0]['url'] ?? '');
                    if ($b64 !== '') {
                        // 兼容 data:image/png;base64,xxx 前缀
                        $b64 = preg_replace('/^data:[^;]+;base64,/', '', $b64);
                        if (@file_put_contents($outFile, base64_decode($b64)) !== false
                            && !self::isMeaninglessImage($outFile)) {
                            Db::name('audit_models')->where('id', $modelId)->update(['last_ok_time' => time()]);
                            return 'model';
                        }
                        @unlink($outFile);
                    }
                    if ($imgUrl !== '') {
                        $bin = @file_get_contents($imgUrl);
                        if ($bin !== false && @file_put_contents($outFile, $bin) !== false
                            && !self::isMeaninglessImage($outFile)) {
                            Db::name('audit_models')->where('id', $modelId)->update(['last_ok_time' => time()]);
                            return 'model';
                        }
                        @unlink($outFile);
                    }
                    if ($code >= 400) {
                        Db::name('audit_models')->where('id', $modelId)->update(['fail_count' => Db::raw('fail_count + 1')]);
                    }
                }
            }
        }
        if (self::placeholderImage($prompt, $outFile) && !self::isMeaninglessImage($outFile)) {
            return 'placeholder';
        }
        return '';
    }

    /** GD 占位图（无可用模型/调用失败时） */
    protected static function placeholderImage(string $prompt, string $outFile): bool
    {
        $im = @imagecreatetruecolor(1024, 768);
        if (!$im) {
            return false;
        }
        mt_srand(abs(crc32($prompt)));
        $c1 = [mt_rand(20, 120), mt_rand(40, 140), mt_rand(60, 160)];
        $c2 = [mt_rand(80, 200), mt_rand(60, 180), mt_rand(90, 210)];
        for ($y = 0; $y < 768; $y++) {
            $k = $y / 768;
            imageline($im, 0, $y, 1024, $y, imagecolorallocate($im,
                (int)($c1[0] + ($c2[0] - $c1[0]) * $k),
                (int)($c1[1] + ($c2[1] - $c1[1]) * $k),
                (int)($c1[2] + ($c2[2] - $c1[2]) * $k)));
        }
        for ($j = 0; $j < 5; $j++) {
            imagefilledellipse($im, mt_rand(0, 1024), mt_rand(0, 768), mt_rand(120, 420), mt_rand(120, 420),
                imagecolorallocatealpha($im, mt_rand(120, 255), mt_rand(120, 255), mt_rand(120, 255), 90));
        }
        $ok = @imagejpeg($im, $outFile, 85);
        @imagedestroy($im);
        return (bool)$ok;
    }

    /** 造图（与 gen:run 同逻辑的精简版，$limit 限制本次张数） */
    /**
     * 造图任务执行（web 触发与 CLI 共用调度语义）
     * 仅执行「到期」任务，执行后推进 next_run（上次执行 + 间隔），并写日志、计失败
     * @param int $limit 单任务单次最多生成张数
     */
    public static function taskGen(int $limit = 0, int $onlyId = 0): string
    {
        // web 触发时可能生成多张（每张约 10 秒），放宽执行时间限制
        @set_time_limit(300);
        $now = time();

        // 单日上限（0 = 不限）
        $dailyLimit = (int)\app\common\model\Setting::get('gen_daily_limit', 100);
        if ($dailyLimit > 0) {
            $today = (int)Db::name('gen_task_logs')->where('create_time', '>=', strtotime(date('Y-m-d')))->sum('gen_count');
            if ($today >= $dailyLimit) {
                return "已达单日上限 {$dailyLimit} 张（设为 0 表示不限）";
            }
        }

        // $onlyId > 0：手动「立即执行」指定任务（忽略 next_run 与暂停状态）
        if ($onlyId > 0) {
            $tasks = Db::name('gen_tasks')->where('id', $onlyId)->select()->toArray();
            if (!$tasks) {
                return '任务不存在';
            }
        } else {
            // 只取到期任务（next_run 为空视为立即执行）
            $tasks = Db::name('gen_tasks')->where('status', 1)
                ->where(function ($q) use ($now) { $q->where('next_run', '<=', $now)->whereOr('next_run', 0); })
                ->select()->toArray();
            if (!$tasks) {
                return '无到期任务';
            }
        }

        $total = 0;
        foreach ($tasks as $task) {
            $gen = 0; $ok = 0; $fail = 0; $detail = [];

            $user  = \app\common\model\User::find((int)$task['target_user_id']);
            $group = $user ? \app\common\model\StrategyGroup::find((int)$user->group_id) : null;
            if (!$user || !$group) {
                Db::name('gen_tasks')->where('id', $task['id'])->update(['status' => 0, 'fail_streak' => (int)$task['fail_streak'] + 1]);
                self::logGenTask((int)$task['id'], 0, 0, 1, '目标用户/策略组不存在，任务已暂停');
                continue;
            }

            $prompts = json_decode((string)$task['prompts'], true) ?: ['abstract gradient'];
            // $limit > 0 时作为单次上限（手动触发可限制），否则按任务配置张数
            $perRun  = max(1, (int)$task['count_per_run']);
            $times   = $limit > 0 ? min($limit, $perRun) : $perRun;

            for ($i = 0; $i < $times; $i++) {
                $prompt = $prompts[array_rand($prompts)];
                $tmp = sys_get_temp_dir() . '/gen_' . uniqid() . '.jpg';

                $made = self::generateImage((int)$task['model_id'], $prompt, $tmp);
                if ($made === '') {
                    $fail++;
                    $detail[] = '生成失败：' . mb_substr($prompt, 0, 20);
                    continue;
                }
                $gen++;

                try {
                    $f = new \think\file\UploadedFile($tmp, 'gen_' . date('YmdHis') . '_' . $i . '.jpg', 'image/jpeg', null, true);
                    $r = (new \app\common\service\UploadService())->upload($f, (int)$user->id, $group, '127.0.0.1', 'gen-bot');
                    \think\facade\Db::name('images')->where('id', $r['image']->id)->update(['origin' => 'gen_bot']);
                    $fresh = \think\facade\Db::name('images')->where('id', $r['image']->id)->find();
                    if ((int)$task['auto_public'] === 1 && $fresh && (int)$fresh['audit_status'] === 1) {
                        \think\facade\Db::name('images')->where('id', $r['image']->id)->update(['is_public' => 1]);
                    }
                    $ok++;
                    $detail[] = "OK #{$r['image']->id} " . mb_substr($prompt, 0, 20) . ($made === 'placeholder' ? '（占位图）' : '');
                } catch (\Throwable $e) {
                    $fail++;
                    $detail[] = '上传失败：' . mb_substr($e->getMessage(), 0, 60);
                } finally {
                    @unlink($tmp);
                }
            }

            $interval = max(5, (int)$task['interval_minutes']);
            $newStreak = $ok === 0 && $fail > 0 ? (int)$task['fail_streak'] + 1 : 0;
            Db::name('gen_tasks')->where('id', $task['id'])->update([
                'last_run'        => $now,
                'next_run'        => $now + $interval * 60,
                'total_generated' => (int)$task['total_generated'] + $ok,
                'fail_streak'     => $newStreak,
                'update_time'     => time(),
            ]);
            if ($newStreak >= 3) {
                Db::name('gen_tasks')->where('id', $task['id'])->update(['status' => 0]);
                $detail[] = '连续失败 3 次，任务已自动暂停';
            }
            self::logGenTask((int)$task['id'], $gen, $ok, $fail, implode('；', array_slice($detail, 0, 10)));
            $total += $ok;
        }

        if ($onlyId > 0) {
            return "任务 #{$onlyId} 已执行，生成 {$total} 张";
        }
        return "生成 {$total} 张（到期任务 " . count($tasks) . ' 个）';
    }

    /** 写造图任务日志 */
    protected static function logGenTask(int $taskId, int $gen, int $ok, int $fail, string $detail): void
    {
        try {
            Db::name('gen_task_logs')->insert([
                'task_id'       => $taskId,
                'gen_count'     => $gen,
                'success_count' => $ok,
                'fail_count'    => $fail,
                'detail'        => mb_substr($detail, 0, 2000),
                'create_time'   => time(),
            ]);
        } catch (\Throwable $e) {
        }
    }

    /** 展示用 CLI php 路径（不探测，避免 disable_functions 限制；按服务器实际修改） */
    public static function phpBinary(): string
    {
        $bin = defined('PHP_BINARY') ? PHP_BINARY : '';
        if ($bin !== '' && stripos($bin, 'fpm') === false && stripos($bin, 'cgi') === false) {
            return $bin;
        }
        $cand = $bin !== '' ? dirname($bin) . '/php' : '';
        if ($cand !== '' && (string)@php_ini_loaded_file() !== '') {
            return $cand;
        }
        return '/www/server/php/83/bin/php';
    }

    /** exec 验证 php 可执行 */
    protected static function probePhp(string $bin): bool
    {
        $out = [];
        $code = 1;
        @exec(escapeshellarg($bin) . ' -r "echo 1;" 2>/dev/null', $out, $code);
        return $code === 0 && in_array('1', $out, true);
    }
}
