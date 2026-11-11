<?php
declare (strict_types = 1);

namespace app\admin\controller;

use app\admin\controller\Base;
use app\common\model\StrategyGroup;
use app\common\model\User;
use think\facade\Db;

/**
 * 用户管理：列表 / 禁用启用 / 改策略组 / 重置密码
 */
class UserManage extends Base
{
    public function index()
    {
        $page = max(1, (int)$this->request->param('page', 1));
        $kw   = trim((string)$this->request->param('kw', ''));

        $q = User::order('id', 'desc');
        if ($kw !== '') {
            $q->where('email', 'like', '%' . $kw . '%');
        }

        $list  = $q->page($page, 20)->select();
        $total = User::where('email', 'like', '%' . $kw . '%')->count();

        return $this->adminView('admin/user', [
            'page'    => 'user',
            'list'    => $list,
            'kw'      => $kw,
            'page_no' => $page,
            'total'   => $total,
            'pages'   => (int)ceil($total / 20) ?: 1,
            'groups'  => StrategyGroup::select(),
        ]);
    }

    public function toggle(int $id)
    {
        $user = User::find($id);
        if (!$user) {
            return json(['status' => false, 'message' => '用户不存在']);
        }
        $new = (int)$user->status === 1 ? 2 : 1;
        Db::name('users')->where('id', $id)->update(['status' => $new, 'update_time' => time()]);
        return json(['status' => true, 'message' => $new === 1 ? '已启用' : '已禁用（登录与上传立即失效）']);
    }

    public function changeGroup(int $id)
    {
        $groupId = (int)$this->request->post('group_id', 0);
        if (!StrategyGroup::where('id', $groupId)->where('status', 1)->find()) {
            return json(['status' => false, 'message' => '策略组不存在']);
        }
        Db::name('users')->where('id', $id)->update(['group_id' => $groupId, 'update_time' => time()]);
        return json(['status' => true, 'message' => '已切换策略组']);
    }

    /** 重置密码：返回一次随机密码 */
    public function resetPwd(int $id)
    {
        $user = User::find($id);
        if (!$user) {
            return json(['status' => false, 'message' => '用户不存在']);
        }
        $new = substr(bin2hex(random_bytes(5)), 0, 10);
        Db::name('users')->where('id', $id)->update([
            'password'    => password_hash($new, PASSWORD_BCRYPT),
            'update_time' => time(),
        ]);
        return json(['status' => true, 'message' => '已重置', 'data' => ['password' => $new]]);
    }
}
