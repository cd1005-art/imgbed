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
            if ($status !== '') {
                $q->where('status', (int)$status);
            } else {
                $q->where('status', '<>', 3);   // 默认不显示已删除用户
            }
            if ($dfrom !== '')  { $q->where('create_time', '>=', strtotime($dfrom . ' 00:00:00')); }
            if ($dto !== '')    { $q->where('create_time', '<=', strtotime($dto . ' 23:59:59')); }
            return $q;
        };

        $groupMap = [];
        foreach (StrategyGroup::field('id,max_tokens,quota_total')->select()->toArray() as $g) {
            $groupMap[(int)$g['id']] = ['max_tokens' => (int)$g['max_tokens'], 'quota_total' => (int)$g['quota_total']];
        }

        $list  = $build()->page($page, 20)->select()->each(function ($item) use ($groupMap) {
            $count = (int)Db::name('api_tokens')->where('user_id', $item['id'])->count();
            $g = $groupMap[(int)$item['group_id']] ?? ['max_tokens' => 0, 'quota_total' => 0];
            $item['token_display'] = $count . '/' . ($g['max_tokens'] > 0 ? $g['max_tokens'] : '不限');
            // 容量：用量/总量（策略组 quota_total）
            $item['img_count'] = (int)Db::name('images')->where('user_id', $item['id'])->count();
            $item['quota_display'] = \app\common\service\UploadService::formatSize((int)$item['quota_used'])
                . ' / ' . ($g['quota_total'] > 0 ? \app\common\service\UploadService::formatSize($g['quota_total']) : '不限');
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
    /**
     * 删除用户（软删除：status=3，登录即刻失效；记录与图片保留，避免孤儿数据）
     * @param int $id
     * purge=1 时同时下架其所有图片（设为私密 + 禁止再公开）
     */
    public function delete(int $id)
    {
        $user = User::find($id);
        if (!$user) {
            return json(['status' => false, 'message' => '用户不存在']);
        }
        if ((int)$user->status === 3) {
            return json(['status' => false, 'message' => '该用户已删除']);
        }
        $purge = (int)$this->request->post('purge', 0) === 1;

        try {
            Db::startTrans();
            // 释放邮箱占用（加 #del{id} 后缀保留可追溯），否则原邮箱无法重新注册
            $email = (string)$user->email;
            if (strpos($email, '#del') === false) {
                Db::name('users')->where('id', $id)->update([
                    'email' => mb_substr($email, 0, 100) . '#del' . $id,
                ]);
            }
            Db::name('users')->where('id', $id)->update(['status' => 3, 'update_time' => time()]);
            // 立即踢下线：清该用户所有 token
            Db::name('api_tokens')->where('user_id', $id)->delete();
            if ($purge) {
                Db::name('images')->where('user_id', $id)->update([
                    'is_public' => 0, 'ban_public' => 1, 'update_time' => time(),
                ]);
            }
            Db::commit();
        } catch (\Throwable $e) {
            Db::rollback();
            return json(['status' => false, 'message' => '删除失败：' . $e->getMessage()]);
        }

        return json([
            'status'  => true,
            'message' => '用户已删除（无法登录）' . ($purge ? '，其图片已全部下架' : '，图片保留'),
        ]);
    }

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

        if ($action === 'delete') {
            foreach ($ids as $uid) {
                $u = User::find($uid);
                if (!$u) {
                    continue;
                }
                $email = (string)$u->email;
                if (strpos($email, '#del') === false) {
                    Db::name('users')->where('id', $uid)->update(['email' => mb_substr($email, 0, 100) . '#del' . $uid]);
                }
                Db::name('users')->where('id', $uid)->update(['status' => 3, 'update_time' => time()]);
            }
            Db::name('api_tokens')->whereIn('user_id', $ids)->delete();
            return json(['status' => true, 'message' => '已删除 ' . count($ids) . ' 个用户（图片保留，邮箱已释放）']);
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
