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
            'token_used'  => ApiToken::where('user_id', $user->id)->count(),
            'email_verified' => (int)$user->email_verified === 1,
            'verify_mode'    => (string)Setting::get('verify_mode', 'none'),
            'token_limit' => ($group && (int)$group->max_tokens > 0) ? (int)$group->max_tokens : 0,
            // 权益面板（与后台策略组同步展示）
            'rights' => [
                'group_name'   => $group->name ?? '默认',
                'max_size_str' => $group ? \app\common\service\UploadService::formatSize((int)$group->max_size) : '-',
                'daily_count'  => ($group && (int)$group->daily_count > 0) ? (string)(int)$group->daily_count : '不限',
                'keep_text'    => ($group && (int)$group->keep_days > 0) ? ((int)$group->keep_days . ' 天后清理') : '永久保留',
                'ext_str'      => $group ? implode(' / ', array_map('strtoupper', $group->allowedExt())) : '-',
                'ad_exempt'    => ($group && (int)$group->ad_exempt === 1),
                'max_tokens'   => ($group && (int)$group->max_tokens < 0) ? '未开放'
                    : (($group && (int)$group->max_tokens > 0) ? ('最多 ' . (int)$group->max_tokens . ' 个') : '不限'),
            ],
        ]);
    }

    /** 重发验证邮件 */
    public function resendVerify()
    {
        $user = $this->currentUser();
        if (!$user) {
            return json(['status' => false, 'message' => '请先登录']);
        }
        if ((int)$user->email_verified === 1) {
            return json(['status' => false, 'message' => '邮箱已验证，无需重发']);
        }
        $token = bin2hex(random_bytes(16));
        $hours = max(1, (int)Setting::get('verify_expire', '24'));
        Db::name('users')->where('id', $user->id)->update([
            'verify_token'  => $token,
            'verify_expire' => time() + $hours * 3600,
        ]);
        $ok = \app\common\service\MailerService::sendTemplate(
            (string)$user->email,
            '验证你的邮箱 · ' . (string)Setting::get('site_name', '逍遥图床'),
            'verify',
            ['name' => (string)$user->nickname, 'link' => $this->request->domain() . '/verify/' . $token]
        );
        return json([
            'status'  => $ok,
            'message' => $ok ? '验证邮件已发送，请查收（含垃圾箱）' : '发送失败：请检查后台 SMTP 配置',
        ]);
    }

    /** 生成 Token */
    public function tokenCreate()
    {
        $user = $this->request->loginUser;
        $name = trim((string)$this->request->post('name', ''));

        // Token 生成权限：-1=未开放（禁止生成）；0=不限；>0=数量上限
        $group = StrategyGroup::find((int)$user->group_id);
        $limit = $group ? (int)$group->max_tokens : -1;
        if ($limit < 0) {
            return json(['status' => false, 'message' => '当前策略未开放 Token 生成']);
        }
        if ($limit > 0) {
            $count = ApiToken::where('user_id', $user->id)->count();
            if ($count >= $limit) {
                return json(['status' => false, 'message' => '当前策略最多 ' . $limit . ' 个 Token，先删除不用的再生成']);
            }
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
