<?php
declare (strict_types = 1);

namespace app\admin\controller;

use app\admin\controller\Base;
use app\common\model\Setting;
use app\common\service\CronService;
use think\facade\Db;

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
            $t['cli_inner'] = 'cd ' . $root . ' && ' . ($php !== '' ? $php : 'php') . ' think ' . $t['cmd'];
            // 注释头用真实换行（单引号里的 \n 不会转义，会变成字面量导致整行被注释）
            $t['cli'] = '# ' . $t['name'] . "\n" . $t['cli_inner'];
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

    /** 查看日志：runtime/cron_{task}.log 尾部 + 最近 5 次执行 */
    public function log(string $task)
    {
        if (!isset(\app\common\service\CronService::TASKS[$task])) {
            return json(['status' => false, 'message' => 'unknown task']);
        }
        $file = rtrim((string)root_path(), '/\\') . '/runtime/cron_' . $task . '.log';
        $tail = '';
        if (@is_file($file)) {
            $lines = @file($file, FILE_IGNORE_NEW_LINES) ?: [];
            $tail = implode("\n", array_slice($lines, -30));
        }
        $runs = Db::name('cron_runs')->where('task_key', $task)->order('id', 'desc')->limit(5)->select()->toArray();
        foreach ($runs as &$r) {
            $r['time'] = $r['create_time'] ? date('m-d H:i:s', (int)$r['create_time']) : '-';
        }
        unset($r);
        return json(['status' => true, 'tail' => $tail ?: '（无日志文件，可能从未以 CLI/URL 方式执行）', 'runs' => $runs]);
    }

    /** 重置密钥 */
    public function rotateKey()
    {
        Setting::saveKey('cron_key', bin2hex(random_bytes(16)));
        return json(['status' => true, 'message' => '密钥已重置，记得更新计划任务地址']);
    }
}
