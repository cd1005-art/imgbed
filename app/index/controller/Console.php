<?php
declare (strict_types = 1);

namespace app\index\controller;

use app\index\controller\Base;
use app\common\model\ApiToken;
use app\common\model\StrategyGroup;
use app\common\model\User;
use think\facade\Db;

/**
 * 个人中心：配额总览 + API Token 管理
 *
 * @property \app\common\model\User $loginUser 由 CheckLogin 中间件挂载
 */
class Console extends Base
{
    public function index()
    {
        $user  = $this->request->loginUser;
        $group = StrategyGroup::find((int)$user->group_id);

        // 用户配额覆盖优先，其次策略组
        $quotaTotal = (int)$user->quota_total > 0 ? (int)$user->quota_total : (int)($group->quota_total ?? 0);
        $used       = (int)$user->quota_used;

        $tokens = ApiToken::where('user_id', $user->id)->order('id', 'desc')->select();
        // Token 保护：列表只显示中间截断值（完整值仅生成时展示一次）
        foreach ($tokens as $tk) {
            $full = (string)$tk->token;
            $tk['token_masked'] = mb_strlen($full) > 20
                ? mb_substr($full, 0, 6) . str_repeat('•', 16) . mb_substr($full, -6)
                : $full;
        }

        return $this->siteView('console/index', [
            'user'        => $user,
            'group'       => $group,
            'quota_total' => $quotaTotal,
            'quota_used'  => $used,
            'quota_pct'   => $quotaTotal > 0 ? min(100, (int)($used / $quotaTotal * 100)) : 0,
            'tokens'      => $tokens,
            // 权益面板（与后台策略组同步展示）
            'rights' => [
                'group_name'   => $group->name ?? '默认',
                'max_size_str' => $group ? \app\common\service\UploadService::formatSize((int)$group->max_size) : '-',
                'daily_count'  => ($group && (int)$group->daily_count > 0) ? (string)(int)$group->daily_count : '不限',
                'keep_text'    => ($group && (int)$group->keep_days > 0) ? ((int)$group->keep_days . ' 天后清理') : '永久保留',
                'ext_str'      => $group ? implode(' / ', array_map('strtoupper', $group->allowedExt())) : '-',
                'ad_exempt'    => ($group && (int)$group->ad_exempt === 1),
                'max_tokens'   => ($group && (int)$group->max_tokens > 0) ? ('最多 ' . (int)$group->max_tokens . ' 个') : '不限（系统上限 10 个）',
            ],
        ]);
    }

    /** 生成 Token */
    public function tokenCreate()
    {
        $user = $this->request->loginUser;
        $name = trim((string)$this->request->post('name', ''));

        // 数量上限：策略组 max_tokens（0=不限）与系统硬上限 10 取较小值
        $group = StrategyGroup::find((int)$user->group_id);
        $groupLimit = $group ? (int)$group->max_tokens : 0;
        $limit = $groupLimit > 0 ? min($groupLimit, 10) : 10;
        $count = ApiToken::where('user_id', $user->id)->count();
        if ($count >= $limit) {
            return json(['status' => false, 'message' => '当前策略最多 ' . $limit . ' 个 Token，先删除不用的再生成']);
        }

        $token = bin2hex(random_bytes(32));
        ApiToken::create([
            'user_id'     => $user->id,
            'name'        => mb_substr($name !== '' ? $name : 'Token ' . ($count + 1), 0, 30),
            'token'       => $token,
            'status'      => 1,
            'create_time' => time(),
            'update_time' => time(),
        ]);

        return json(['status' => true, 'message' => '已生成', 'data' => ['token' => $token]]);
    }

    /** 删除 Token */
    public function tokenDelete(int $id)
    {
        $user = $this->request->loginUser;
        $ok = ApiToken::where('id', $id)->where('user_id', $user->id)->delete();
        return json(['status' => (bool)$ok, 'message' => $ok ? '已删除' : 'Token 不存在']);
    }

    /** 修改昵称/密码 */
    public function profile()
    {
        $user     = $this->request->loginUser;
        $nickname = trim((string)$this->request->post('nickname', ''));
        $old      = (string)$this->request->post('old_password', '');
        $new      = (string)$this->request->post('new_password', '');

        $update = [];
        if ($nickname !== '') {
            $update['nickname'] = mb_substr($nickname, 0, 30);
        }
        if ($new !== '') {
            if (strlen($new) < 8) {
                return json(['status' => false, 'message' => '新密码至少 8 位']);
            }
            if (!password_verify($old, (string)$user->password)) {
                return json(['status' => false, 'message' => '原密码不对']);
            }
            $update['password'] = password_hash($new, PASSWORD_BCRYPT);
        }

        if ($update) {
            $update['update_time'] = time();
            Db::name('users')->where('id', $user->id)->update($update);
        }

        return json(['status' => true, 'message' => '已保存']);
    }
}
