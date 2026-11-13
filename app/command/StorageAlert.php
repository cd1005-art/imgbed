<?php
declare (strict_types = 1);

namespace app\command;

use app\common\model\Setting;
use app\common\service\MailerService;
use think\console\Command;
use think\console\Input;
use think\console\Output;
use think\facade\Db;

/**
 * 存储告警检查：php think monitor:storage
 *
 * 阈值（后台邮件设置）：alert_disk_free_gb / alert_quota_left_pct / alert_space_left_pct
 * 去重：同一告警 24 小时内只发一次（settings 键 alert_sent_{hash}）
 */
class StorageAlert extends Command
{
    protected function configure()
    {
        $this->setName('monitor:storage')->setDescription('检查存储空间并发送告警邮件');
    }

    protected function execute(Input $input, Output $output)
    {
        \app\common\service\CronService::beat('storage', 'started');
        $to = trim((string)Setting::get('admin_mail_to', ''));
        $diskTh  = (int)Setting::get('alert_disk_free_gb', 10);
        $quotaTh = (int)Setting::get('alert_quota_left_pct', 10);
        $spaceTh = (int)Setting::get('alert_space_left_pct', 10);

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
                if ($free !== false) {
                    $freeGb = $free / 1073741824;
                    if ($freeGb < $diskTh) {
                        $alerts[] = sprintf('【磁盘】%s 剩余 %.1f GB（阈值 %d GB）', $name, $freeGb, $diskTh);
                    }
                }
            }

            $quotaGb = (int)($cfg['quota_gb'] ?? 0);
            if ($quotaGb > 0) {
                $leftPct = ($quotaGb * 1073741824 - $used) / ($quotaGb * 1073741824) * 100;
                if ($leftPct < $quotaTh) {
                    $alerts[] = sprintf('【配额】%s 剩余 %.1f%%（阈值 %d%%）', $name, max(0, $leftPct), $quotaTh);
                }
            }

            $spaceGb = (int)($cfg['space_gb'] ?? 0);
            if ($spaceGb > 0) {
                $leftPct = ($spaceGb * 1073741824 - $used) / ($spaceGb * 1073741824) * 100;
                if ($leftPct < $spaceTh) {
                    $alerts[] = sprintf('【网盘空间】%s 剩余 %.1f%%（阈值 %d%%）', $name, max(0, $leftPct), $spaceTh);
                }
            }
        }

        if (!$alerts) {
            $output->writeln('存储正常，无告警');
            return 0;
        }

        // 24 小时去重
        $fresh = [];
        foreach ($alerts as $msg) {
            $key = 'alert_sent_' . md5($msg);
            $last = (int)Setting::get($key, '0');
            if (time() - $last < 86400) {
                continue;
            }
            Setting::saveKey($key, (string)time());
            $fresh[] = $msg;
        }

        if (!$fresh) {
            $output->writeln('告警存在但 24 小时内已发送，跳过');
            return 0;
        }

        if ($to === '') {
            $output->writeln('发现告警但未配置管理员邮箱（admin_mail_to）：' . implode('；', $fresh));
            return 0;
        }

        $ok = MailerService::sendTemplate(
            $to,
            '【存储告警】' . (string)Setting::get('site_name', '逍遥图床'),
            'storage_alert',
            ['reason' => implode('<br>', array_map('htmlspecialchars', $fresh))]
        );
        $output->writeln($ok ? '告警邮件已发送' : '告警邮件发送失败（检查 SMTP 与日志）');
        \app\common\service\CronService::beat('storage', 'CLI 执行完成（详见输出/邮件）');
        return 0;
    }
}
