<?php
declare(strict_types=1);

namespace app\command;

use app\common\model\Setting;
use app\common\service\BaiduPushService;
use think\console\Command;
use think\console\Input;
use think\console\Output;

/**
 * 百度主动推送（独立计划任务）
 * 用法：php think push:auto
 * 内部按「累积条数 / 最小间隔 / 每日上限」节流，可放心高频调度
 */
class PushAuto extends Command
{
    protected function configure()
    {
        $this->setName('push:auto')
            ->setDescription('百度主动推送：按累积阈值/间隔/日上限节流提交新图 URL');
    }

    protected function execute(Input $input, Output $output)
    {
        if (!BaiduPushService::enabled()) {
            $output->writeln('未启用推送或未配置 token');
            return 0;
        }
        $before = (int)Setting::get('baidu_push_last_time', 0);
        BaiduPushService::maybeAutoPush();
        $after  = (int)Setting::get('baidu_push_last_time', 0);
        $result = (string)Setting::get('baidu_push_last_result', '');

        if ($after > $before) {
            $output->writeln('推送完成：' . $result);
        } else {
            $count = (int)\app\common\model\Image::where('is_public', 1)
                ->where('audit_status', 1)
                ->where('status', 1)
                ->where('update_time', '>=', (int)Setting::get('baidu_push_since', 0))
                ->count();
            $output->writeln('本轮未触发（待推 ' . $count . ' 条，阈值 '
                . (int)Setting::get('baidu_push_auto_count', 5) . '，间隔 '
                . (int)Setting::get('baidu_push_auto_hours', 6) . ' 小时）');
        }
        return 0;
    }
}
