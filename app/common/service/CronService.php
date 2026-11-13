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
    ];

    /**
     * 记录心跳
     * @param string $source cli=命令行计划任务 / url=URL计划任务 / manual=后台手动测试
     * 说明：manual 只更新时间与次数，不影响「接入状态」（灯色）
     */
    public static function beat(string $key, string $result = 'ok', string $source = 'cli'): void
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
            $update = [
                'last_run'    => time(),
                'last_result' => mb_substr($result, 0, 200),
                'last_source' => $source,
                'source_time' => time(),
                'run_count'   => (int)$row['run_count'] + 1,
            ];
            if ($source === 'manual') {
                // 手动执行额外记录统计（用于展示"手动验证 N 次"）
                $update['manual_time']  = time();
                $update['manual_count'] = (int)$row['manual_count'] + 1;
                $update['manual_result'] = mb_substr($result, 0, 200);
            }
            Db::name('cron_heartbeats')->where('task_key', $key)->update($update);
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
                default:
                    return ['ok' => false, 'msg' => 'unknown task'];
            }
            self::beat($key, $msg, $source);
            return ['ok' => true, 'msg' => $msg];
        } catch (\Throwable $e) {
            self::beat($key, 'fail: ' . mb_substr($e->getMessage(), 0, 120));
            return ['ok' => false, 'msg' => '执行失败：' . mb_substr($e->getMessage(), 0, 200)];
        }
    }

    /** 存储告警检查（与 monitor:storage 同逻辑） */
    public static function taskStorage(): string
    {
        $to = trim((string)\app\common\model\Setting::get('admin_mail_to', ''));
        $diskTh  = (int)\app\common\model\Setting::get('alert_disk_free_gb', 10);
        $quotaTh = (int)\app\common\model\Setting::get('alert_quota_left_pct', 10);
        $spaceTh = (int)\app\common\model\Setting::get('alert_space_left_pct', 10);

        $storages = Db::name('storage_configs')->where('status', 1)->select()->toArray();
        $alerts = [];
        foreach ($storages as $s) {
            $cfg  = json_decode((string)$s['config'], true) ?: [];
            $used = (int)Db::name('images')->where('storage_id', $s['id'])->where('status', 1)->sum('size');
            $name = (string)$s['name'];
            if ($s['driver'] === 'local') {
                $rootRel = trim(str_replace('\\', '/', (string)($cfg['root'] ?? 'storage/images')), '/');
                $root = rtrim(public_path(), '/\\') . '/' . $rootRel;
                $free = @disk_free_space(@is_dir($root) ? $root : public_path());
                if ($free !== false && $free / 1073741824 < $diskTh) {
                    $alerts[] = sprintf('【磁盘】%s 剩余 %.1f GB（阈值 %d GB）', $name, $free / 1073741824, $diskTh);
                }
            }
            $quotaGb = (int)($cfg['quota_gb'] ?? 0);
            if ($quotaGb > 0) {
                $left = ($quotaGb * 1073741824 - $used) / ($quotaGb * 1073741824) * 100;
                if ($left < $quotaTh) {
                    $alerts[] = sprintf('【配额】%s 剩余 %.1f%%（阈值 %d%%）', $name, max(0, $left), $quotaTh);
                }
            }
            $spaceGb = (int)($cfg['space_gb'] ?? 0);
            if ($spaceGb > 0) {
                $left = ($spaceGb * 1073741824 - $used) / ($spaceGb * 1073741824) * 100;
                if ($left < $spaceTh) {
                    $alerts[] = sprintf('【网盘空间】%s 剩余 %.1f%%（阈值 %d%%）', $name, max(0, $left), $spaceTh);
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
        $ok = \app\common\service\MailerService::sendTemplate($to, '【存储告警】' . (string)\app\common\model\Setting::get('site_name', '逍遥图床'),
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
            if (\app\common\service\MailerService::sendTemplate((string)$u['email'],
                '会员即将到期 · ' . (string)\app\common\model\Setting::get('site_name', '逍遥图床'),
                'vip_expire', ['name' => (string)$u['nickname'], 'link' => date('Y-m-d', (int)$u['vip_expire_time'])])) {
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
