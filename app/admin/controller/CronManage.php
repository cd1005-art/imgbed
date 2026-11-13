<?php
declare (strict_types = 1);

namespace app\admin\controller;

use app\admin\controller\Base;
use app\common\model\Setting;
use app\common\service\CronService;

/** 计划任务：状态灯 / 命令复制 / 立即执行 */
class CronManage extends Base
{
    public function index()
    {
        $tasks = CronService::status();
        $key   = (string)Setting::get('cron_key', '');
        if ($key === '') {
            $key = bin2hex(random_bytes(16));
            Setting::saveKey('cron_key', $key);
        }
        $domain = request()->domain();
        $root   = rtrim((string)root_path(), '/\\');
        $php    = CronService::phpBinary();

        foreach ($tasks as &$t) {
            // CLI 命令带注释头（复制到宝塔也一眼知道是什么任务；# 注释不影响执行）
            $t['cli_inner'] = ($php !== '' ? $php : 'php') . ' ' . $root . '/think ' . $t['cmd'];
            $t['cli'] = '# ' . $t['name'] . '\n' . $t['cli_inner'];
            $t['url'] = $domain . '/cron/' . $t['key'] . '?key=' . $key;
        }
        unset($t);

        return $this->adminView('admin/cron', [
            'page'     => 'cron',
            'tasks'    => $tasks,
            'key'      => $key,
            'php'      => $php,
            'cli_list' => array_map(fn($t) => $t['cli'], $tasks),
            'url_list' => array_map(fn($t) => $t['url'], $tasks),
        ]);
    }

    /** 立即执行一次（同步，web 无法 exec 时可靠） */
    public function run(string $task)
    {
        $r = CronService::runNow($task, 'manual');
        return json(['status' => $r['ok'], 'message' => $r['msg']]);
    }

    /** 重置密钥 */
    public function rotateKey()
    {
        Setting::saveKey('cron_key', bin2hex(random_bytes(16)));
        return json(['status' => true, 'message' => '密钥已重置，记得更新计划任务地址']);
    }
}
