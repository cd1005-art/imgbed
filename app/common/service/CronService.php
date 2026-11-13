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
                    $msg = self::taskGen(1); // web 触发每次限 1 张，避免超时
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

    /** 造图（与 gen:run 同逻辑的精简版，$limit 限制本次张数） */
    public static function taskGen(int $limit = 1): string
    {
        $tasks = Db::name('gen_tasks')->where('status', 1)->select()->toArray();
        if (!$tasks) {
            return '无任务';
        }
        $total = 0;
        foreach ($tasks as $task) {
            $user  = \app\common\model\User::find((int)$task['target_user_id']);
            $group = $user ? \app\common\model\StrategyGroup::find((int)$user->group_id) : null;
            if (!$user || !$group) {
                continue;
            }
            $prompts = json_decode((string)$task['prompts'], true) ?: ['abstract gradient'];
            for ($i = 0; $i < min($limit, max(1, (int)$task['count_per_run'])); $i++) {
                $prompt = $prompts[array_rand($prompts)];
                $tmp = sys_get_temp_dir() . '/gen_' . uniqid() . '.jpg';
                $im = @imagecreatetruecolor(1024, 768);
                if (!$im) {
                    continue;
                }
                $c1 = [mt_rand(20, 120), mt_rand(40, 140), mt_rand(60, 160)];
                $c2 = [mt_rand(80, 200), mt_rand(60, 180), mt_rand(90, 210)];
                for ($y = 0; $y < 768; $y++) {
                    $k = $y / 768;
                    imageline($im, 0, $y, 1024, $y, imagecolorallocate($im,
                        (int)($c1[0] + ($c2[0] - $c1[0]) * $k), (int)($c1[1] + ($c2[1] - $c1[1]) * $k), (int)($c1[2] + ($c2[2] - $c1[2]) * $k)));
                }
                for ($j = 0; $j < 5; $j++) {
                    imagefilledellipse($im, mt_rand(0, 1024), mt_rand(0, 768), mt_rand(120, 420), mt_rand(120, 420),
                        imagecolorallocatealpha($im, mt_rand(120, 255), mt_rand(120, 255), mt_rand(120, 255), 90));
                }
                @imagejpeg($im, $tmp, 85);
                @imagedestroy($im);
                try {
                    $f = new \think\file\UploadedFile($tmp, 'gen_' . date('YmdHis') . '_' . $i . '.jpg', 'image/jpeg', null, true);
                    $r = (new \app\common\service\UploadService())->upload($f, (int)$user->id, $group, '127.0.0.1', 'gen-bot');
                    \think\facade\Db::name('images')->where('id', $r['image']->id)->update(['origin' => 'gen_bot']);
                    $fresh = \think\facade\Db::name('images')->where('id', $r['image']->id)->find();
                    if ((int)$task['auto_public'] === 1 && $fresh && (int)$fresh['audit_status'] === 1) {
                        \think\facade\Db::name('images')->where('id', $r['image']->id)->update(['is_public' => 1]);
                    }
                    $total++;
                } catch (\Throwable $e) {
                } finally {
                    @unlink($tmp);
                }
            }
            Db::name('gen_tasks')->where('id', $task['id'])->update(['last_run' => time(), 'total_generated' => (int)$task['total_generated'] + 1]);
        }
        return "生成 {$total} 张";
    }

    /** 站点健康巡检：应用可用性 + 证书到期（本机 127.0.0.1 探测，规避 DNS 问题） */
    public static function taskSite(): string
    {
        $domain = (string)\app\common\model\Setting::get('site_domain', '');
        if ($domain === '') {
            $domain = (string)request()->domain();
        }
        // 兼容设置里不带协议前缀（如 "oddpp.com"）
        $host = parse_url($domain, PHP_URL_HOST) ?: trim($domain);
        if ($host === '' || filter_var($host, FILTER_VALIDATE_IP)) {
            $host = $host !== '' ? $host : '127.0.0.1';
        }
        $msgs = [];
        $bad = false;

        $ch = @curl_init('https://127.0.0.1/');
        if ($ch) {
            @curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 15,
                CURLOPT_SSL_VERIFYPEER => false, CURLOPT_SSL_VERIFYHOST => false,
                CURLOPT_HTTPHEADER => ['Host: ' . $host],
            ]);
            @curl_exec($ch);
            $code = (int)@curl_getinfo($ch, CURLINFO_HTTP_CODE);
            @curl_close($ch);
            if ($code !== 200) {
                $bad = true;
                $msgs[] = "首页返回 {$code}（非 200）";
            } else {
                $msgs[] = '首页 200 正常';
            }
        } else {
            $msgs[] = 'curl 不可用';
        }

        $certDays = null;
        $ctx = @stream_context_create(['ssl' => [
            'capture_peer_cert' => true, 'verify_peer' => false,
            'verify_peer_name' => false, 'SNI_enabled' => true, 'peer_name' => $host,
        ]]);
        $sock = @stream_socket_client('ssl://127.0.0.1:443', $errno, $errstr, 8, STREAM_CLIENT_CONNECT, $ctx);
        if ($sock) {
            $params = @stream_context_get_params($sock);
            $cert = $params['options']['ssl']['peer_certificate'] ?? null;
            if ($cert) {
                $info = @openssl_x509_parse($cert);
                if ($info && isset($info['validTo_time_t'])) {
                    $certDays = (int)floor(($info['validTo_time_t'] - time()) / 86400);
                }
            }
            @fclose($sock);
        }
        if ($certDays !== null) {
            $msgs[] = "证书剩余 {$certDays} 天";
            if ($certDays < 15) {
                $bad = true;
                $msgs[] = '⚠ 证书即将到期';
            }
        } else {
            $msgs[] = '证书读取失败';
        }

        // 3) 域名到期检查（RDAP，剩余 < 30 天告警）
        try {
            $rootDomain = self::rootDomain($host);
            if ($rootDomain !== '') {
                $rdap = '';
                // 重试 2 次：国外 RDAP 端点在国内网络偶发超时
                for ($try = 0; $try < 2 && $rdap === ''; $try++) {
                    $ch = @curl_init('https://rdap.org/domain/' . $rootDomain);
                    if (!$ch) {
                        break;
                    }
                    @curl_setopt_array($ch, [
                        CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 20, CURLOPT_CONNECTTIMEOUT => 8,
                        CURLOPT_FOLLOWLOCATION => true,
                        CURLOPT_SSL_VERIFYPEER => false, CURLOPT_USERAGENT => 'imgbed-monitor',
                    ]);
                    $rdap = (string)@curl_exec($ch);
                    @curl_close($ch);
                }
                if ($rdap !== '') {
                    $jd = json_decode($rdap, true);
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
                            self::notifyAdmin('domain_expire', '【域名即将到期】' . $rootDomain, 'domain_expire',
                                ['name' => $rootDomain, 'reason' => $days . ' 天']);
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

    /** 取主域名（xxx.yyy.com → yyy.com，支持常见二级后缀） */
    protected static function rootDomain(string $host): string
    {
        $parts = array_values(array_filter(explode('.', strtolower(trim($host)))));
        if (count($parts) < 2) {
            return '';
        }
        $twoLevel = ['com.cn', 'net.cn', 'org.cn', 'gov.cn', 'edu.cn', 'co.uk', 'com.hk', 'com.tw'];
        $last2 = $parts[count($parts) - 2] . '.' . $parts[count($parts) - 1];
        if (in_array($last2, $twoLevel, true) && count($parts) >= 3) {
            return $parts[count($parts) - 3] . '.' . $last2;
        }
        return $last2;
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
