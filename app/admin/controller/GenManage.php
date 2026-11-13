<?php
declare (strict_types = 1);

namespace app\admin\controller;

use app\admin\controller\Base;
use think\facade\Db;

/** 造图任务管理 */
class GenManage extends Base
{
    public function index()
    {
        $list = Db::name('gen_tasks')->order('id', 'desc')->select()->toArray();
        $models = Db::name('audit_models')->where('purpose', 'image_gen')->order('sort', 'asc')->select()->toArray();

        // 模型名 / 目标账号昵称（列表展示与编辑回填用）
        $modelName = [];
        foreach ($models as $m) { $modelName[(int)$m['id']] = (string)$m['name']; }
        $uids = []; $muids = [];
        foreach ($list as &$t) {
            $uids[(int)$t['target_user_id']] = (int)$t['target_user_id'];
            $muids[(int)$t['model_id']] = (int)$t['model_id'];
        }
        unset($t);
        $userName = [];
        if ($uids) {
            foreach (Db::name('users')->whereIn('id', array_values($uids))->field('id,nickname,email,status')->select()->toArray() as $u) {
                $label = $u['nickname'] !== '' ? $u['nickname'] : $u['email'];
                if ((int)$u['status'] === 3 || strpos((string)$u['email'], '#del') !== false) { $label .= '（已删除）'; }
                $userName[(int)$u['id']] = $label;
            }
        }
        foreach ($list as &$t) {
            $t['model_name']   = $modelName[(int)$t['model_id']] ?? 'GD 占位图';
            $t['target_name']  = $userName[(int)$t['target_user_id']] ?? ('#' . (int)$t['target_user_id']);
            $pl = json_decode((string)$t['prompts'], true);
            $t['prompt_count'] = is_array($pl) ? count($pl) : 0;
        }
        unset($t);

        // 执行日志筛选：任务 / 结果 / 日期
        $lgTask = (int)$this->request->param('lg_task', 0);
        $lgOk   = (string)$this->request->param('lg_ok', '');
        $lgFrom = trim((string)$this->request->param('lg_from', ''));
        $lgTo   = trim((string)$this->request->param('lg_to', ''));

        $lq = Db::name('gen_task_logs');
        if ($lgTask > 0)   { $lq->where('task_id', $lgTask); }
        if ($lgOk !== '')  { $lgOk === '1' ? $lq->where('fail_count', 0) : $lq->where('fail_count', '>', 0); }
        if ($lgFrom !== '') { $lq->where('create_time', '>=', strtotime($lgFrom . ' 00:00:00')); }
        if ($lgTo !== '')   { $lq->where('create_time', '<=', strtotime($lgTo . ' 23:59:59')); }
        $logs = $lq->order('id', 'desc')->limit(100)->select()->toArray();
        $logsTotal = (int)Db::name('gen_task_logs')->count();

        return $this->adminView('admin/gen', [
            'page'   => 'gen',
            'list'   => $list,
            'models' => $models,
            'logs'   => $logs,
            'logs_total' => $logsTotal,
            'gen_daily_limit' => (int)\app\common\model\Setting::get('gen_daily_limit', 100),
            'lg_task' => $lgTask, 'lg_ok' => $lgOk, 'lg_from' => $lgFrom, 'lg_to' => $lgTo,
        ]);
    }

    public function save()
    {
        $id = (int)$this->request->post('id', 0);
        $prompts = trim((string)$this->request->post('prompts', ''));
        // 一行一条提示词；保留逗号（逗号属于提示词内容，不能当分隔符）
        $arr = array_values(array_filter(array_map('trim', preg_split('/\r\n|\r|\n/', $prompts) ?: [])));
        if (!$arr) {
            return json(['status' => false, 'message' => '至少填一条提示词']);
        }
        $dropped = count($arr) > 2000 ? count($arr) - 2000 : 0;
        // 打乱顺序：之后按游标顺序轮换，一轮内不重复，整体仍随机
        shuffle($arr);

        // 目标账号：输入框（用户ID / 邮箱 / 昵称 模糊），不用下拉（用户量大时下拉不实用）
        $targetInput = trim((string)$this->request->post('target_user_input', ''));
        if ($targetInput === '') {
            return json(['status' => false, 'message' => '请填写目标上传账号']);
        }
        $uq = Db::name('users')->where('status', '<>', 3);
        if (ctype_digit($targetInput)) {
            $uq->where('id', (int)$targetInput);
        } else {
            $uq->where(function ($qq) use ($targetInput) {
                $qq->where('email', 'like', '%' . $targetInput . '%')
                   ->whereOr('nickname', 'like', '%' . $targetInput . '%');
            });
        }
        $target = $uq->find();
        if (!$target) {
            return json(['status' => false, 'message' => "未找到目标账号：「{$targetInput}」（支持用户ID/邮箱/昵称）"]);
        }

        // 所选模型必须是生图模型（防止误选审核模型导致 400）
        $mid = max(0, (int)$this->request->post('model_id', 0));
        if ($mid > 0) {
            $mm = Db::name('audit_models')->where('id', $mid)->find();
            if (!$mm || (string)$mm['purpose'] !== 'image_gen') {
                return json(['status' => false, 'message' => '所选模型不是生图模型，请在「模型管理」中确认用途为 image_gen']);
            }
        }

        $data = [
            'name'            => mb_substr(trim((string)$this->request->post('name', '')), 0, 60) ?: '造图任务',
            'prompts'         => json_encode(array_slice($arr, 0, 2000), JSON_UNESCAPED_UNICODE),
            'prompt_cursor'   => 0,
            'category'        => mb_substr(trim((string)$this->request->post('category', '')), 0, 30),
            'count_per_run'   => max(1, min(20, (int)$this->request->post('count_per_run', 1))),
            'interval_minutes' => max(5, min(1440, (int)$this->request->post('interval_minutes', 60))),
            'model_id'        => $mid,
            'target_user_id'  => (int)$target['id'],
            'auto_public'     => (int)$this->request->post('auto_public', 1) === 1 ? 1 : 0,
            'retry_on_fail'   => (int)$this->request->post('retry_on_fail', 0) === 1 ? 1 : 0,
            'auto_tag'        => (int)$this->request->post('auto_tag', 0) === 1 ? 1 : 0,
            'status'          => (int)$this->request->post('status', 1) === 1 ? 1 : 0,
            'update_time'     => time(),
        ];
        if ($id > 0) {
            Db::name('gen_tasks')->where('id', $id)->update($data);
        } else {
            $data['next_run'] = time();
            $data['create_time'] = time();
            Db::name('gen_tasks')->insert($data);
        }
        $msg = '已保存 ' . min(count($arr), 2000) . ' 条提示词（目标账号：' . ($target['nickname'] ?: $target['email']) . '）';
        if ($dropped > 0) {
            $msg .= "；超出上限的 {$dropped} 条未保存（单任务最多 2000 条）";
        }
        return json(['status' => true, 'message' => $msg]);
    }

    /** 立即执行一次指定任务（测试用，忽略调度时间） */
    public function runNow(int $id)
    {
        $task = Db::name('gen_tasks')->where('id', $id)->find();
        if (!$task) {
            return json(['status' => false, 'message' => '任务不存在']);
        }
        $msg = \app\common\service\CronService::taskGen(max(1, (int)$task['count_per_run']), $id);
        $ok  = !preg_match('/失败|不存在|已达|无到期/', $msg);
        return json(['status' => $ok, 'message' => $msg]);
    }

    /* ==================== 提示词中心 ==================== */

    /** 提示词模板维度库 */
    private const TPL = [
        'subject' => ['渔夫','芭蕾舞者','老木匠','卖花女孩','登山者','夜班护士','吉他手','陶瓷艺人',
                      '街头摄影师','农夫','小提琴家','消防员','书店老板','钓鱼老人','滑板少年'],
        'scene'   => ['晨雾码头','金色麦田','老式剧场','雨后街角','雪山营地','深夜病房','屋顶天台','陶艺工作室',
                      '地铁站台','田埂','音乐厅后台','火场余烟','旧书店','湖畔','城市天桥'],
        'light'   => ['侧逆光勾轮廓','窗光斜切','霓虹反射','烛光暖调','阴天柔光','舞台侧光','路灯顶光','火光映照',
                      '蓝天直射','晨光低角度'],
        'lens'    => ['35mm 环境人像','85mm 浅景深','135mm 长焦压缩','24mm 广角','50mm 自然透视','微距特写'],
        'style'   => ['电影感','胶片颗粒','写实摄影','纪实风格','高级灰调','冷暖对比','低饱和'],
    ];

    /** 模板随机组合生成 */
    public function promptTemplate()
    {
        $count = max(1, min(500, (int)$this->request->post('count', 50)));
        $out = [];
        $seen = [];
        $guard = 0;
        while (count($out) < $count && $guard < $count * 30) {
            $guard++;
            $s = $this->pick(self::TPL['subject']) . '在' . $this->pick(self::TPL['scene'])
               . '，' . $this->pick(self::TPL['light']) . '，' . $this->pick(self::TPL['lens'])
               . '，' . $this->pick(self::TPL['style']);
            if (isset($seen[$s])) { continue; }
            $seen[$s] = 1;
            $out[] = $s;
        }
        return json(['status' => true, 'list' => $out]);
    }

    private function pick(array $arr)
    {
        return $arr[array_rand($arr)];
    }

    /** AI 批量生成提示词（文本模型，OpenAI 兼容） */
    public function promptGen()
    {
        $topic  = trim((string)$this->request->post('topic', ''));
        $style  = trim((string)$this->request->post('style', ''));
        $count  = max(1, min(100, (int)$this->request->post('count', 30)));
        $modelId = (int)$this->request->post('model_id', 0);
        if ($topic === '') {
            return json(['status' => false, 'message' => '请填写主题']);
        }
        $m = Db::name('audit_models')->where('id', $modelId)->find();
        if (!$m || (string)$m['endpoint'] === '') {
            return json(['status' => false, 'message' => '请选择可用的文本模型']);
        }
        $sys = '你是图像生成提示词工程师。只输出提示词本人，每条独占一行，不要编号、不要解释、不要引号。';
        $user = "为图像生成模型写 {$count} 条中文提示词。\n主题：{$topic}\n风格：{$style}\n"
              . "要求：每条 40~80 字，包含主体、场景、光线、镜头（必要时加风格），彼此不重复。";
        $url = rtrim((string)$m['endpoint'], '/');
        if (!str_contains($url, '/chat/completions')) {
            $url .= '/chat/completions';
        }
        $ch = @curl_init($url);
        if (!$ch) {
            return json(['status' => false, 'message' => '网络初始化失败']);
        }
        @curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true, CURLOPT_POST => true, CURLOPT_TIMEOUT => 180,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Authorization: Bearer ' . (string)$m['api_key']],
            CURLOPT_POSTFIELDS => json_encode([
                'model' => (string)$m['model_name'],
                'messages' => [
                    ['role' => 'system', 'content' => $sys],
                    ['role' => 'user', 'content' => $user],
                ],
                'temperature' => 0.9,
            ], JSON_UNESCAPED_UNICODE),
        ]);
        $raw  = (string)@curl_exec($ch);
        $code = (int)@curl_getinfo($ch, CURLINFO_HTTP_CODE);
        @curl_close($ch);
        $d = json_decode($raw, true);
        $txt = (string)($d['choices'][0]['message']['content'] ?? '');
        if ($txt === '') {
            return json(['status' => false, 'message' => "模型未返回内容（HTTP {$code}）"]);
        }
        $lines = preg_split('/\r\n|\r|\n/', $txt) ?: [];
        $out = [];
        foreach ($lines as $ln) {
            $ln = trim(preg_replace('/^[\s\-*\d.、)）]+/u', '', $ln));
            $ln = trim($ln, " \t\"'“”‘’");
            if ($ln === '' || mb_strlen($ln) < 8) { continue; }
            $out[] = $ln;
        }
        $out = array_values(array_unique($out));
        return json(['status' => (count($out) > 0), 'list' => $out,
            'message' => count($out) > 0 ? '生成 ' . count($out) . ' 条' : '未解析到有效提示词']);
    }

    /** 图生文反推提示词（视觉模型） */
    public function promptReverse()
    {
        $imageUrl = trim((string)$this->request->post('image_url', ''));
        $modelId  = (int)$this->request->post('model_id', 0);
        if ($imageUrl === '') {
            return json(['status' => false, 'message' => '请填写图片地址']);
        }
        $m = Db::name('audit_models')->where('id', $modelId)->find();
        if (!$m || (string)$m['endpoint'] === '') {
            return json(['status' => false, 'message' => '请选择视觉模型']);
        }
        $url = rtrim((string)$m['endpoint'], '/');
        if (!str_contains($url, '/chat/completions')) {
            $url .= '/chat/completions';
        }
        $payload = [
            'model' => (string)$m['model_name'],
            'messages' => [[
                'role' => 'user',
                'content' => [
                    ['type' => 'text', 'text' => '观察这张图的画面内容，写出能生成这张图的中文提示词（一条，60~120 字，包含主体、场景、光线、镜头、风格）。只输出提示词本人。'],
                    ['type' => 'image_url', 'image_url' => ['url' => $imageUrl]],
                ],
            ]],
            'temperature' => 0.7,
        ];
        $ch = @curl_init($url);
        if (!$ch) {
            return json(['status' => false, 'message' => '网络初始化失败']);
        }
        @curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true, CURLOPT_POST => true, CURLOPT_TIMEOUT => 120,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Authorization: Bearer ' . (string)$m['api_key']],
            CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_UNICODE),
        ]);
        $raw  = (string)@curl_exec($ch);
        $code = (int)@curl_getinfo($ch, CURLINFO_HTTP_CODE);
        @curl_close($ch);
        $d = json_decode($raw, true);
        $txt = trim((string)($d['choices'][0]['message']['content'] ?? ''));
        if ($txt === '') {
            return json(['status' => false, 'message' => "模型未返回内容（HTTP {$code}）"]);
        }
        return json(['status' => true, 'list' => [$txt], 'message' => '反推完成']);
    }

    /** 追加提示词到指定任务（去重） */
    public function promptAppend()
    {
        $taskId = (int)$this->request->post('task_id', 0);
        $text   = (string)$this->request->post('prompts', '');
        $task = Db::name('gen_tasks')->where('id', $taskId)->find();
        if (!$task) {
            return json(['status' => false, 'message' => '请选择目标任务']);
        }
        $lines = preg_split('/\r\n|\r|\n/', $text) ?: [];
        $add = [];
        foreach ($lines as $ln) {
            $ln = trim($ln);
            if ($ln !== '') { $add[] = $ln; }
        }
        if (!$add) {
            return json(['status' => false, 'message' => '没有可追加的内容']);
        }
        $old = json_decode((string)$task['prompts'], true) ?: [];
        $before = count($old);
        $old = array_values(array_unique(array_merge($old, $add)));
        if (count($old) > 2000) {
            $old = array_slice($old, 0, 2000);
        }
        Db::name('gen_tasks')->where('id', $taskId)->update([
            'prompts' => json_encode($old, JSON_UNESCAPED_UNICODE),
            'update_time' => time(),
        ]);
        $real = count($old) - $before;
        return json(['status' => true, 'message' => "已追加 {$real} 条（当前共 " . count($old) . ' 条）']);
    }

    /** 导出任务提示词为 txt */
    public function promptExport(int $id)
    {
        $task = Db::name('gen_tasks')->where('id', $id)->find();
        if (!$task) {
            return response('任务不存在', 404);
        }
        $arr = json_decode((string)$task['prompts'], true) ?: [];
        $txt = implode("\n", $arr);
        return response($txt, 200, [
            'Content-Type' => 'text/plain; charset=utf-8',
            'Content-Disposition' => 'attachment; filename="task-' . $id . '-prompts.txt"',
        ]);
    }

    /** 批量操作任务：enable / disable / delete */
    public function batch()
    {
        $ids = array_filter(array_map('intval', explode(',', (string)$this->request->post('ids', ''))));
        $action = (string)$this->request->post('action', '');
        if (!$ids) {
            return json(['status' => false, 'message' => '未选择任务']);
        }
        if ($action === 'delete') {
            $n = Db::name('gen_tasks')->whereIn('id', $ids)->delete();
            Db::name('gen_task_logs')->whereIn('task_id', $ids)->delete();
            return json(['status' => true, 'message' => "已删除 {$n} 个任务（含其日志）"]);
        }
        if (!in_array($action, ['enable', 'disable'], true)) {
            return json(['status' => false, 'message' => '参数错误']);
        }
        $status = $action === 'enable' ? 1 : 0;
        $data = ['status' => $status, 'update_time' => time()];
        if ($status === 1) { $data['next_run'] = time(); }
        $n = Db::name('gen_tasks')->whereIn('id', $ids)->update($data);
        return json(['status' => true, 'message' => '已' . ($status ? '启用' : '暂停') . " {$n} 个任务"]);
    }

    /** 保存造图运行设置（单日上限，0=不限） */
    public function saveSettings()
    {
        $v = (int)$this->request->post('gen_daily_limit', 100);
        $v = max(0, min(100000, $v));
        \app\common\model\Setting::saveKey('gen_daily_limit', (string)$v);
        return json([
            'status'  => true,
            'message' => $v === 0 ? '已保存：单日上限为「不限」' : "已保存：单日上限 {$v} 张",
        ]);
    }

    /** 删除执行日志（ids 逗号分隔） */
    public function deleteLogs()
    {
        $ids = array_filter(array_map('intval', explode(',', (string)$this->request->post('ids', ''))));
        if (!$ids) {
            return json(['status' => false, 'message' => '未选择日志']);
        }
        $n = Db::name('gen_task_logs')->whereIn('id', $ids)->delete();
        return json(['status' => true, 'message' => "已删除 {$n} 条日志"]);
    }

    /** 清空全部执行日志 */
    public function clearLogs()
    {
        $n = Db::name('gen_task_logs')->where('id', '>', 0)->delete();
        return json(['status' => true, 'message' => "已清空 {$n} 条日志"]);
    }

    public function toggle(int $id)
    {
        $t = Db::name('gen_tasks')->where('id', $id)->find();
        if (!$t) {
            return json(['status' => false, 'message' => '任务不存在']);
        }
        $on = (int)$t['status'] === 1 ? 0 : 1;
        Db::name('gen_tasks')->where('id', $id)->update(['status' => $on, 'next_run' => $on ? time() : 0, 'update_time' => time()]);
        return json(['status' => true, 'message' => $on ? '已启用' : '已暂停']);
    }

    public function delete(int $id)
    {
        Db::name('gen_tasks')->where('id', $id)->delete();
        return json(['status' => true, 'message' => '已删除']);
    }
}
