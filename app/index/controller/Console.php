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
    /** 当前登录用户（中间件注入 loginUser） */
    private function currentUser()
    {
        return $this->request->loginUser ?? null;
    }

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
            'earnings'       => (int)($user->earnings ?? 0),
            'withdraw_rate'  => max(1, (int)Setting::get('withdraw_rate', 10)),
            'withdraw_enable' => (int)Setting::get('withdraw_enable', 1) === 1,
            'credits_total'  => (int)$user->credits_total,
            'credits_rate'   => max(1, (int)Setting::get('credits_per_yuan', 10)),
            'pay_available'  => (new \app\common\service\PaymentService())->isAvailable(),
            'min_withdraw'   => (int)Setting::get('min_withdraw', 50),
            'withdraws'      => Db::name('withdrawals')->where('user_id', $user->id)->order('id', 'desc')->limit(20)->select(),
            'has_withdraws'  => (int)Db::name('withdrawals')->where('user_id', $user->id)->count() > 0,
            // 站币四类记录
            'recharge_logs'  => Db::name('credit_logs')->where('user_id', $user->id)->where('type', 'recharge')
                ->order('id', 'desc')->limit(20)->select()->toArray(),
            'earn_logs'      => Db::name('credit_logs')->where('user_id', $user->id)->where('type', 'share')
                ->order('id', 'desc')->limit(20)->select()->toArray(),
            'unlock_logs'    => Db::name('credit_logs')->where('user_id', $user->id)->where('type', 'unlock')
                ->order('id', 'desc')->limit(20)->select()->toArray(),
            'credit_orders'  => (function () use ($user) {
                $rows = Db::name('orders')->where('user_id', $user->id)->where('type', 'credits')
                    ->order('id', 'desc')->limit(20)->select()->toArray();
                $ids = array_map(fn($r) => (int)$r['id'], $rows);
                $coinMap = [];
                if ($ids) {
                    foreach (Db::name('credit_logs')->where('type', 'recharge')->whereIn('ref_id', $ids)
                        ->field('ref_id,amount')->select()->toArray() as $l) {
                        $coinMap[(int)$l['ref_id']] = (int)$l['amount'];
                    }
                }
                foreach ($rows as &$r) {
                    $r['coins'] = $coinMap[(int)$r['id']] ?? 0;
                }
                return $rows;
            })(),
            'alipay_name'    => (string)($user->alipay_name ?? ''),
            'alipay_account' => (string)($user->alipay_account ?? ''),
            'min_recharge'   => (int)Setting::get('min_recharge', 10),
            'withdraw_fee'   => (int)Setting::get('withdraw_fee_pct', 0),
            'withdraw_monthly' => (int)Setting::get('withdraw_monthly_limit', 2),
            'withdraw_step'  => max(1, (int)Setting::get('withdraw_step', 1)),
            'withdraw_used_this_month' => (int)Db::name('withdrawals')->where('user_id', $user->id)
                ->where('create_time', '>', strtotime(date('Y-m-01')))->count(),
            'share_user'     => (int)Setting::get('share_user', 70),
            'share_ai'       => (int)Setting::get('share_ai', 50),
            'split_mode'     => (string)Setting::get('revenue_split_mode', '0'),
            'credit_plans'   => Db::name('credit_plans')->where('status', 1)->order('sort', 'asc')->order('id', 'asc')->select()->toArray(),
            'recharge_bonus' => (int)Setting::get('recharge_bonus_pct', 0),
            'recharge_bonus_list' => Db::name('recharge_bonus')->where('status', 1)
                ->order('amount', 'asc')->select()->toArray(),
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
        $planId = (int)$this->request->post('plan_id', 0);
        $yuan   = max(1, min(10000, (int)$this->request->post('yuan', 10)));
        $minRecharge = max(1, (int)Setting::get('min_recharge', 10));

        // 优先按档位下单
        if ($planId > 0) {
            $plan = \think\facade\Db::name('credit_plans')->where('id', $planId)->where('status', 1)->find();
            if (!$plan) {
                return json(['status' => false, 'message' => '充值档位不存在或已下架']);
            }
            $yuan = (int)$plan['amount'];
        }
        if ($yuan < $minRecharge) {
            return json(['status' => false, 'message' => "最低充值 {$minRecharge} 元"]);
        }

        try {
            $svc = new \app\common\service\PaymentService();
            $r = $svc->createCreditsOrder($user, $yuan, 'alipay', $planId);
            return redirect($r['submit_url']);
        } catch (\Throwable $e) {
            return json(['status' => false, 'message' => $e->getMessage()]);
        }
    }

    /** 保存提现账号（支付宝） */
    public function saveAlipay()
    {
        $user = $this->currentUser();
        if (!$user) {
            return json(['status' => false, 'message' => '请先登录']);
        }
        $name = trim((string)$this->request->post('alipay_name', ''));
        $acct = trim((string)$this->request->post('alipay_account', ''));
        if ($name === '' || $acct === '') {
            return json(['status' => false, 'message' => '请填写支付宝姓名与账号']);
        }
        \think\facade\Db::name('users')->where('id', $user->id)->update([
            'alipay_name' => mb_substr($name, 0, 30),
            'alipay_account' => mb_substr($acct, 0, 60),
            'update_time' => time(),
        ]);
        return json(['status' => true, 'message' => '提现账号已保存']);
    }

    /** 提现申请 */
    public function withdraw()
    {
        $user = $this->currentUser();
        if (!$user) {
            return json(['status' => false, 'message' => '请先登录']);
        }
        if ((int)Setting::get('withdraw_enable', 1) !== 1) {
            return json(['status' => false, 'message' => '提现功能暂未开放']);
        }
        $amount = (int)$this->request->post('amount', 0);
        $name   = trim((string)$this->request->post('alipay_name', ''));
        $acct   = trim((string)$this->request->post('alipay_account', ''));

        $min = (int)Setting::get('min_withdraw', 50);
        if ($amount < $min) {
            return json(['status' => false, 'message' => "最低提现 {$min} 元"]);
        }
        $step = max(1, (int)Setting::get('withdraw_step', 1));
        if ($step > 1 && $amount % $step !== 0) {
            return json(['status' => false, 'message' => "提现金额须为 {$step} 的整数倍"]);
        }
        if ($name === '' || $acct === '') {
            return json(['status' => false, 'message' => '请填写支付宝姓名与账号']);
        }
        // 提现使用独立比例（与充值比例分开配置）
        $rate = max(1, (int)Setting::get('withdraw_rate', 10));
        $need = $amount * $rate;
        $total = (int)$user->credits + (int)($user->earnings ?? 0);
        if ($total < $need) {
            return json(['status' => false, 'message' => "余额不足（需 {$need} 币，可用 {$total} 币）"]);
        }
        $monthly = (int)Setting::get('withdraw_monthly_limit', 2);
        $cnt = Db::name('withdrawals')->where('user_id', $user->id)
            ->where('create_time', '>', strtotime(date('Y-m-01')))->count();
        if ($monthly > 0 && $cnt >= $monthly) {
            return json(['status' => false, 'message' => "本月提现次数已达上限（{$monthly} 次）"]);
        }

        $feePct = (int)Setting::get('withdraw_fee_pct', 0);
        $fee = round($amount * $feePct / 100, 2);   // 精确到分，避免 floor 把 1.5 变成 1

        try {
            Db::startTrans();
            $wid = (int)Db::name('withdrawals')->insertGetId([
                'user_id' => (int)$user->id, 'amount' => $amount, 'fee' => $fee,
                'real_amount' => $amount - $fee, 'alipay_name' => $name, 'alipay_account' => $acct,
                'status' => 'pending', 'create_time' => time(), 'update_time' => time(),
            ]);
            $pay = \app\common\service\CreditsService::withdrawPay((int)$user->id, $need, $wid, '提现申请冻结（' . $amount . ' 元）');
            if (!$pay['ok']) {
                Db::rollback();
                return json(['status' => false, 'message' => $pay['message']]);
            }
            Db::name('withdrawals')->where('id', $wid)->update([
                'from_earnings' => (int)$pay['from_earnings'],
                'from_credits'  => (int)$pay['from_credits'],
            ]);
            Db::name('users')->where('id', $user->id)->update(['alipay_name' => $name, 'alipay_account' => $acct]);
            Db::commit();
        } catch (\Throwable $e) {
            Db::rollback();
            return json(['status' => false, 'message' => $e->getMessage()]);
        }
        try {
            \app\common\service\MailerService::notify('withdraw_apply', (string)$user->email,
                '提现申请已提交 · ' . (string)Setting::get('site_name', '逍遥图床'),
                'withdraw_result', ['amount' => (string)$amount, 'reason' => '审核中']);
        } catch (\Throwable $e) {
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
        $r = \app\index\controller\Auth::sendVerifyCode($user);
        return json(['status' => $r['ok'], 'message' => $r['message']]);
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
