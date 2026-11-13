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
        $users  = Db::name('users')->order('id', 'asc')->limit(50)->select()->toArray();
        $logs   = Db::name('gen_task_logs')->order('id', 'desc')->limit(15)->select()->toArray();

        return $this->adminView('admin/gen', [
            'page'   => 'gen',
            'list'   => $list,
            'models' => $models,
            'users'  => $users,
            'logs'   => $logs,
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
        $data = [
            'name'            => mb_substr(trim((string)$this->request->post('name', '')), 0, 60) ?: '造图任务',
            'prompts'         => json_encode(array_slice($arr, 0, 50), JSON_UNESCAPED_UNICODE),
            'category'        => mb_substr(trim((string)$this->request->post('category', '')), 0, 30),
            'count_per_run'   => max(1, min(20, (int)$this->request->post('count_per_run', 1))),
            'interval_minutes' => max(5, min(1440, (int)$this->request->post('interval_minutes', 60))),
            'model_id'        => max(0, (int)$this->request->post('model_id', 0)),
            'target_user_id'  => max(0, (int)$this->request->post('target_user_id', 0)),
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
        return json(['status' => true, 'message' => '已保存']);
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
