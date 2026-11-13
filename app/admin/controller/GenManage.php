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
            $t['model_name']  = $modelName[(int)$t['model_id']] ?? 'GD 占位图';
            $t['target_name'] = $userName[(int)$t['target_user_id']] ?? ('#' . (int)$t['target_user_id']);
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
        $arr = array_values(array_filter(array_map('trim', preg_split('/[\n,，]+/u', $prompts) ?: [])));
        if (!$arr) {
            return json(['status' => false, 'message' => '至少填一条提示词']);
        }

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

        $data = [
            'name'            => mb_substr(trim((string)$this->request->post('name', '')), 0, 60) ?: '造图任务',
            'prompts'         => json_encode(array_slice($arr, 0, 50), JSON_UNESCAPED_UNICODE),
            'category'        => mb_substr(trim((string)$this->request->post('category', '')), 0, 30),
            'count_per_run'   => max(1, min(20, (int)$this->request->post('count_per_run', 1))),
            'interval_minutes' => max(5, min(1440, (int)$this->request->post('interval_minutes', 60))),
            'model_id'        => max(0, (int)$this->request->post('model_id', 0)),
            'target_user_id'  => (int)$target['id'],
            'auto_public'     => (int)$this->request->post('auto_public', 1) === 1 ? 1 : 0,
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
        return json(['status' => true, 'message' => '已保存（目标账号：' . ($target['nickname'] ?: $target['email']) . '）']);
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
