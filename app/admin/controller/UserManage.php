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
     * 彻底删除用户（物理删除：清除其图片记录与文件、订单、流水、解锁、举报、Token）
     * 有未处理提现时拒绝删除（财务安全）
     */
    public function delete(int $id)
    {
        $user = User::find($id);
        if (!$user) {
            return json(['status' => false, 'message' => '用户不存在']);
        }
        return $this->purgeUser($id);
    }

    /** 批量删除：逐个彻底删除 */
    protected function purgeUser(int $id): \think\response\Json
    {
        // 未处理提现 → 拒绝
        $pending = Db::name('withdrawals')->where('user_id', $id)
            ->whereIn('status', ['pending', 'approved'])->count();
        if ($pending > 0) {
            return json(['status' => false, 'message' => "该用户有 {$pending} 笔未处理提现，请先处理后再删除"]);
        }

        try {
            Db::startTrans();

            $imageIds = Db::name('images')->where('user_id', $id)->column('id');
            $images   = Db::name('images')->where('user_id', $id)->select()->toArray();

            if ($imageIds) {
                Db::name('image_tags')->whereIn('image_id', $imageIds)->delete();
                Db::name('unlocks')->whereIn('image_id', $imageIds)->delete();
                Db::name('audit_logs')->whereIn('image_id', $imageIds)->delete();
                Db::name('banned_fingerprints')->whereIn('image_id', $imageIds)->delete();
            }
            Db::name('reports')->where('reporter_id', $id)->delete();
            if ($imageIds) {
                Db::name('reports')->whereIn('image_id', $imageIds)->delete();
            }
            Db::name('unlocks')->where('user_id', $id)->delete();
            Db::name('credit_logs')->where('user_id', $id)->delete();
            Db::name('orders')->where('user_id', $id)->delete();
            Db::name('withdrawals')->where('user_id', $id)->delete();
            Db::name('api_tokens')->where('user_id', $id)->delete();
            Db::name('gen_tasks')->where('target_user_id', $id)->delete();
            Db::name('images')->where('user_id', $id)->delete();
            Db::name('users')->where('id', $id)->delete();

            Db::commit();
        } catch (\Throwable $e) {
            Db::rollback();
            return json(['status' => false, 'message' => '删除失败：' . $e->getMessage()]);
        }

        // 物理文件删除（仅本地存储；对象存储请到云控制台清理）
        $removed = 0;
        foreach ($images as $img) {
            try {
                $row = Db::name('storage_configs')->where('id', (int)$img['storage_id'])->find();
                if (!$row || $row['driver'] !== 'local') {
                    continue;
                }
                $cfg = json_decode((string)$row['config'], true) ?: [];
                $rootRel = trim(str_replace('\\', '/', (string)($cfg['root'] ?? 'storage/images')), '/');
                $local = rtrim(public_path(), '/\\') . '/' . $rootRel . '/' . ltrim((string)$img['path'], '/');
                if (@is_file($local) && @unlink($local)) {
                    $removed++;
                }
            } catch (\Throwable $e) {
            }
        }

        return json([
            'status'  => true,
            'message' => "已彻底删除（图片 " . count($images) . " 条记录、文件 {$removed} 个，订单/流水/举报已清理）",
        ]);
    }

    /** 调整站币（管理员加/减，写入流水） */
    public function adjustCredits(int $id)
    {
        $user = User::find($id);
        if (!$user) {
            return json(['status' => false, 'message' => '用户不存在']);
        }
        $amount = (int)$this->request->post('amount', 0);
        $remark = mb_substr(trim((string)$this->request->post('remark', '')), 0, 100);
        if ($amount === 0) {
            return json(['status' => false, 'message' => '请输入变动数量（正数增加，负数扣减）']);
        }
        try {
            $balance = \app\common\service\CreditsService::change(
                $id, $amount, 'admin_adjust', 0, $remark !== '' ? $remark : '管理员调整'
            );
        } catch (\Throwable $e) {
            return json(['status' => false, 'message' => $e->getMessage()]);
        }
        return json([
            'status'  => true,
            'message' => ($amount > 0 ? "已增加 {$amount}" : '已扣减 ' . abs($amount)) . "，当前余额 {$balance}",
        ]);
    }

    /** 管理员手动标记邮箱已验证（收不到验证码时的兜底） */
    public function verifyEmail(int $id)
    {
        $u = User::find($id);
        if (!$u) {
            return json(['status' => false, 'message' => '用户不存在']);
        }
        Db::name('users')->where('id', $id)->update([
            'email_verified' => 1, 'verify_token' => '', 'verify_tries' => 0, 'update_time' => time(),
        ]);
        return json(['status' => true, 'message' => '已标记邮箱为已验证']);
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
            $ok = 0;
            $skip = [];
            foreach ($ids as $uid) {
                if (!User::find($uid)) {
                    continue;
                }
                $r = $this->purgeUser($uid);
                $data = json_decode($r->getContent(), true);
                if (!empty($data['status'])) {
                    $ok++;
                } else {
                    $skip[] = '#' . $uid . ': ' . ($data['message'] ?? '失败');
                }
            }
            return json([
                'status'  => true,
                'message' => "已彻底删除 {$ok} 个用户" . ($skip ? '；跳过：' . implode('；', $skip) : ''),
            ]);
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
