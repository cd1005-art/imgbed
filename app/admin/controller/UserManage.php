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
        $page   = max(1, (int)$this->request->param('page', 1));
        $kw     = trim((string)$this->request->param('kw', ''));
        $gid    = (int)$this->request->param('gid', 0);
        $status = (string)$this->request->param('status', '');
        $dfrom  = (string)$this->request->param('dfrom', '');
        $dto    = (string)$this->request->param('dto', '');

        $build = function () use ($kw, $gid, $status, $dfrom, $dto) {
            $q = User::order('id', 'desc');
            if ($kw !== '') {
                $q->where(function ($qq) use ($kw) {
                    if (ctype_digit($kw)) {
                        $qq->whereOr('id', (int)$kw);
                    }
                    $qq->whereOr('email', 'like', '%' . $kw . '%')
                       ->whereOr('nickname', 'like', '%' . $kw . '%');
                });
            }
            if ($gid > 0)       { $q->where('group_id', $gid); }
            if ($status !== '') { $q->where('status', (int)$status); }
            if ($dfrom !== '')  { $q->where('create_time', '>=', strtotime($dfrom . ' 00:00:00')); }
            if ($dto !== '')    { $q->where('create_time', '<=', strtotime($dto . ' 23:59:59')); }
            return $q;
        };

        $groupMax = [];
        foreach (StrategyGroup::field('id,max_tokens')->select()->toArray() as $g) {
            $groupMax[(int)$g['id']] = (int)$g['max_tokens'];
        }

        $list  = $build()->page($page, 20)->select()->each(function ($item) use ($groupMax) {
            $count = (int)Db::name('api_tokens')->where('user_id', $item['id'])->count();
            $limit = $groupMax[(int)$item['group_id']] ?? 0;
            $item['token_display'] = $count . '/' . ($limit > 0 ? $limit : '不限');
            return $item;
        });
        $total = $build()->count();

        return $this->adminView('admin/user', [
            'page'    => 'user',
            'list'    => $list,
            'kw'      => $kw,
            'gid'     => $gid,
            'status'  => $status,
            'dfrom'   => $dfrom,
            'dto'     => $dto,
            'page_no' => $page,
            'total'   => $total,
            'pages'   => (int)ceil($total / 20) ?: 1,
            'groups'  => StrategyGroup::select(),
        ]);
    }

    /** 编辑保存（昵称/邮箱/策略组/状态/可选新密码） */
    public function save(int $id)
    {
        $user = User::find($id);
        if (!$user) {
            return json(['status' => false, 'message' => '用户不存在']);
        }

        $nickname = trim((string)$this->request->post('nickname', ''));
        $email    = trim((string)$this->request->post('email', ''));
        $groupId  = (int)$this->request->post('group_id', $user->group_id);
        $status   = (int)$this->request->post('status', $user->status);
        $password = (string)$this->request->post('password', '');

        if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return json(['status' => false, 'message' => '邮箱格式不对']);
        }
        if ($email !== '' && $email !== $user->email) {
            if (User::where('email', $email)->where('id', '<>', $id)->find()) {
                return json(['status' => false, 'message' => '该邮箱已被其他用户使用']);
            }
        }
        if (!StrategyGroup::where('id', $groupId)->where('status', 1)->find()) {
            return json(['status' => false, 'message' => '策略组不存在']);
        }

        $data = [
            'nickname'    => mb_substr($nickname !== '' ? $nickname : $user->nickname, 0, 30),
            'group_id'    => $groupId,
            'status'      => in_array($status, [1, 2], true) ? $status : $user->status,
            'update_time' => time(),
        ];
        if ($email !== '') { $data['email'] = $email; }
        if ($password !== '') {
            if (strlen($password) < 8) {
                return json(['status' => false, 'message' => '新密码至少 8 位']);
            }
            $data['password'] = password_hash($password, PASSWORD_BCRYPT);
        }

        Db::name('users')->where('id', $id)->update($data);
        return json(['status' => true, 'message' => '已保存']);
    }

    /** 批量：action=disable/enable/group */
    public function batch()
    {
        $action = (string)$this->request->post('action', '');
        $ids    = array_filter(array_map('intval', explode(',', (string)$this->request->post('ids', ''))));
        if (!$ids) {
            return json(['status' => false, 'message' => '未选择用户']);
        }

        if ($action === 'group') {
            $gid = (int)$this->request->post('group_id', 0);
            if (!StrategyGroup::where('id', $gid)->where('status', 1)->find()) {
                return json(['status' => false, 'message' => '策略组不存在']);
            }
            Db::name('users')->whereIn('id', $ids)->update(['group_id' => $gid, 'update_time' => time()]);
            return json(['status' => true, 'message' => '已将 ' . count($ids) . ' 个用户切换到该策略组']);
        }

        if (!in_array($action, ['disable', 'enable'], true)) {
            return json(['status' => false, 'message' => '参数错误']);
        }
        $newStatus = $action === 'disable' ? 2 : 1;
        Db::name('users')->whereIn('id', $ids)->update(['status' => $newStatus, 'update_time' => time()]);
        $label = $action === 'disable' ? '禁用' : '启用';
        return json(['status' => true, 'message' => "已{$label} " . count($ids) . ' 个用户']);
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
