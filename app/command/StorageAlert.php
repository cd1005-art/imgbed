<?php
declare (strict_types = 1);

namespace app\command;

use app\common\service\CronService;
use think\console\Command;
use think\console\Input;
use think\console\Output;

/**
 * 存储告警检查：php think monitor:storage
 * 逻辑统一在 CronService::taskStorage()（口径由后台 alert_unit 控制：pct/gb）
 */
class StorageAlert extends Command
{
    protected function configure()
    {
        $this->setName('monitor:storage')->setDescription('检查存储空间并发送告警邮件');
    }

    protected function execute(Input $input, Output $output)
    {
        $t0 = microtime(true);
        CronService::beat('storage', 'started', 'cli');
        $msg = CronService::taskStorage();
        $output->writeln($msg);
        CronService::beat('storage', $msg, 'cli', (int)round((microtime(true) - $t0) * 1000));
        return 0;
    }
}
