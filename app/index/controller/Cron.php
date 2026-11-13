<?php
declare (strict_types = 1);

namespace app\index\controller;

use app\common\model\Setting;
use app\common\service\CronService;
use app\index\controller\Base;

/** 计划任务 URL 触发（宝塔「访问URL」类型计划任务用） */
class Cron extends Base
{
    /** /cron/{task}?key=xxx */
    public function run(string $task)
    {
        $key = (string)request()->param('key', '');
        $expect = (string)Setting::get('cron_key', '');
        if ($expect === '' || !hash_equals($expect, $key)) {
            return json(['status' => false, 'message' => 'forbidden'], 403);
        }
        if (!isset(CronService::TASKS[$task])) {
            return json(['status' => false, 'message' => 'unknown task'], 404);
        }
        $r = CronService::runNow($task);
        return json(['status' => $r['ok'], 'message' => $r['msg']]);
    }
}
