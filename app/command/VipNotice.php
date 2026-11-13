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
 * VIP 到期提醒：php think monitor:vip
 * 提前 3 天内到期 → 发提醒（同用户 3 天内不重复）
 */
class VipNotice extends Command
{
    protected function configure()
    {
        $this->setName('monitor:vip')->setDescription('VIP 到期提醒邮件');
    }

    protected function execute(Input $input, Output $output)
    {
        \app\common\service\CronService::beat('vip', 'started');
        $now = time();
        $end = $now + 3 * 86400;

        $users = Db::name('users')
            ->where('vip_expire_time', '>', $now)
            ->where('vip_expire_time', '<=', $end)
            ->where('status', 1)
            ->select()->toArray();

        $sent = 0;
        foreach ($users as $u) {
            // 3 天内已发过则不重复
            $recent = Db::name('mail_logs')
                ->where('to_email', $u['email'])
                ->where('template', 'vip_expire')
                ->where('create_time', '>', $now - 3 * 86400)
                ->count();
            if ($recent > 0) {
                continue;
            }
            $ok = MailerService::notify(
                'vip_expire',
                (string)$u['email'],
                '会员即将到期 · ' . (string)Setting::get('site_name', '逍遥图床'),
                'vip_expire',
                ['name' => (string)$u['nickname'], 'link' => date('Y-m-d', (int)$u['vip_expire_time'])]
            );
            if ($ok) {
                $sent++;
            }
        }
        $output->writeln("检查 {$users} 个即将到期用户，发送 {$sent} 封提醒");
        \app\common\service\CronService::beat('vip', 'CLI 执行完成');
        return 0;
    }
}
