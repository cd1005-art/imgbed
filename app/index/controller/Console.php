<?php
declare (strict_types = 1);

namespace app\index\controller;

use app\index\controller\Base;
use app\common\model\ApiToken;
use app\common\model\Setting;
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
            'credits'        => (int)$user->credits,
            'credits_total'  => (int)$user->credits_total,
            'credits_rate'   => max(1, (int)Setting::get('credits_per_yuan', 10)),
            'min_withdraw'   => (int)Setting::get('min_withdraw', 50),
            'withdraws'      => Db::name('withdrawals')->where('user_id', $user->id)->order('id', 'desc')->limit(5)->select(),
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

    /** 站币充值（创建支付订单并跳转收银台） */
    public function recharge()
    {
        $user = $this->currentUser();
        if (!$user) {
            return redirect('/login');
        }
        $yuan = max(1, min(10000, (int)$this->request->post('yuan', 10)));
        try {
            $svc = new \app\common\service\PaymentService();
            $r = $svc->createCreditsOrder($user, $yuan, 'alipay');
            return redirect($r['submit_url']);
        } catch (\Throwable $e) {
            return json(['status' => false, 'message' => '创建订单失败：' . $e->getMessage()]);
        }
    }

    /** 提现申请 */
    public function withdraw()
    {
        $user = $this->currentUser();
        if (!$user) {
            return json(['status' => false, 'message' => '请先登录']);
        }
        $amount = (int)$this->request->post('amount', 0);
        $name   = trim((string)$this->request->post('alipay_name', ''));
        $acct   = trim((string)$this->request->post('alipay_account', ''));

        $min = (int)Setting::get('min_withdraw', 50);
        if ($amount < $min) {
            return json(['status' => false, 'message' => "最低提现 {$min} 元"]);
        }
        if ($name === '' || $acct === '') {
            return json(['status' => false, 'message' => '请填写支付宝姓名与账号']);
        }
        $rate = max(1, (int)Setting::get('credits_per_yuan', 10));
        $need = $amount * $rate;
        if ((int)$user->credits < $need) {
            return json(['status' => false, 'message' => "余额不足（需 {$need} 站币）"]);
        }
        $monthly = (int)Setting::get('withdraw_monthly_limit', 2);
        $cnt = Db::name('withdrawals')->where('user_id', $user->id)
            ->where('create_time', '>', strtotime(date('Y-m-01')))->count();
        if ($monthly > 0 && $cnt >= $monthly) {
            return json(['status' => false, 'message' => "本月提现次数已达上限（{$monthly} 次）"]);
        }

        $feePct = (int)Setting::get('withdraw_fee_pct', 0);
        $fee = (int)floor($amount * $feePct / 100);

        try {
            Db::startTrans();
            $wid = (int)Db::name('withdrawals')->insertGetId([
                'user_id' => (int)$user->id, 'amount' => $amount, 'fee' => $fee,
                'real_amount' => $amount - $fee, 'alipay_name' => $name, 'alipay_account' => $acct,
                'status' => 'pending', 'create_time' => time(), 'update_time' => time(),
            ]);
            \app\common\service\CreditsService::change((int)$user->id, -$need, 'withdraw', $wid, '提现申请冻结（' . $amount . ' 元）');
            Db::name('users')->where('id', $user->id)->update(['alipay_name' => $name, 'alipay_account' => $acct]);
            Db::commit();
        } catch (\Throwable $e) {
            Db::rollback();
            return json(['status' => false, 'message' => $e->getMessage()]);
        }
        return json(['status' => true, 'message' => '提现申请已提交，等待审核打款']);
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
