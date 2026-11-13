<?php
declare (strict_types = 1);

namespace app\admin\controller;

use app\admin\controller\Base;
use app\common\model\Order;
use app\common\model\PaymentConfigModel;
use app\common\model\VipPlan;
use think\facade\Db;

/**
 * 财务管理：支付网关 / VIP 套餐 / 最近订单 / 站币流水 / 提现管理
 * 一个入口看全部资金相关配置与流水
 */
class FinanceManage extends Base
{
    public const TYPES = [
        'recharge'        => '充值',
        'unlock'          => '解锁付费图（支出）',
        'author_income'   => '作者收益',
        'withdraw'        => '提现扣减',
        'withdraw_refund' => '提现驳回退回',
        'vip_settle'      => 'VIP 分成结算',
        'admin_adjust'    => '管理员调整',
        'gen_reward'      => '造图奖励',
    ];

    public function index()
    {
        /* ---------- 网关 / 套餐 ---------- */
        $config  = PaymentConfigModel::where('payment_code', 'yipay')->find();
        $gateway = $config && is_array($config->config) ? $config->config : [];
        $gateway += ['api_url' => '', 'pid' => '', 'key' => '', 'gateway_type' => 'xpay'];
        $gateway['pay_channels'] = (isset($gateway['pay_channels']) && is_array($gateway['pay_channels']))
            ? $gateway['pay_channels'] + ['alipay' => '', 'wxpay' => '', 'qqpay' => '']
            : ['alipay' => '', 'wxpay' => '', 'qqpay' => ''];
        $plans = VipPlan::order('sort', 'asc')->select();

        /* ---------- 订单 ---------- */
        $ono     = trim((string)$this->request->param('ono', ''));
        $ouser   = trim((string)$this->request->param('ouser', ''));
        $otype   = trim((string)$this->request->param('otype', ''));
        $ostatus = trim((string)$this->request->param('ostatus', ''));
        $odfrom  = trim((string)$this->request->param('odfrom', ''));
        $odto    = trim((string)$this->request->param('odto', ''));

        $oq = Order::order('id', 'desc');
        if ($ono !== '') { $oq->where('order_no', 'like', '%' . $ono . '%'); }
        if ($ouser !== '') {
            if (ctype_digit($ouser)) {
                $oq->where('user_id', (int)$ouser);
            } else {
                $uids = Db::name('users')->where(function ($qq) use ($ouser) {
                    $qq->where('email', 'like', '%' . $ouser . '%')->whereOr('nickname', 'like', '%' . $ouser . '%');
                })->column('id');
                $oq->whereIn('user_id', $uids ?: [0]);
            }
        }
        if ($otype !== '')   { $oq->where('type', $otype); }
        if ($ostatus !== '') { $oq->where('status', (int)$ostatus); }
        if ($odfrom !== '')  { $oq->where('create_time', '>=', strtotime($odfrom . ' 00:00:00')); }
        if ($odto !== '')    { $oq->where('create_time', '<=', strtotime($odto . ' 23:59:59')); }
        $orders = $oq->limit(100)->select();

        /* ---------- 站币流水 ---------- */
        $cuw    = trim((string)$this->request->param('cuw', ''));
        $ctype  = trim((string)$this->request->param('ctype', ''));
        $cfrom  = trim((string)$this->request->param('cfrom', ''));
        $cto    = trim((string)$this->request->param('cto', ''));
        $page   = max(1, (int)$this->request->param('page', 1));

        $cbuild = function () use ($cuw, $ctype, $cfrom, $cto) {
            $q = Db::name('credit_logs');
            if ($cuw !== '') {
                if (ctype_digit($cuw)) {
                    $q->where('user_id', (int)$cuw);
                } else {
                    $uids = Db::name('users')->where(function ($qq) use ($cuw) {
                        $qq->where('email', 'like', '%' . $cuw . '%')->whereOr('nickname', 'like', '%' . $cuw . '%');
                    })->column('id');
                    $q->whereIn('user_id', $uids ?: [0]);
                }
            }
            if ($ctype !== '') { $q->where('type', $ctype); }
            if ($cfrom !== '') { $q->where('create_time', '>=', strtotime($cfrom . ' 00:00:00')); }
            if ($cto !== '')   { $q->where('create_time', '<=', strtotime($cto . ' 23:59:59')); }
            return $q;
        };
        $credits = $cbuild()->order('id', 'desc')->page($page, 40)->select()->toArray();
        $creditsTotal = $cbuild()->count();
        $creditIn  = (int)$cbuild()->where('amount', '>', 0)->sum('amount');
        $creditOut = (int)$cbuild()->where('amount', '<', 0)->sum('amount');

        /* ---------- 提现 ---------- */
        $wstatus = trim((string)$this->request->param('wstatus', ''));
        $wuw     = trim((string)$this->request->param('wuw', ''));
        $wfrom   = trim((string)$this->request->param('wfrom', ''));
        $wto     = trim((string)$this->request->param('wto', ''));

        $wq = Db::name('withdrawals')->alias('w')
            ->leftJoin('img_users u', 'u.id = w.user_id')
            ->field('w.*, u.email, u.nickname');
        if ($wstatus !== '') { $wq->where('w.status', $wstatus); }
        if ($wuw !== '') {
            if (ctype_digit($wuw)) {
                $wq->where('w.user_id', (int)$wuw);
            } else {
                $uids = Db::name('users')->where(function ($qq) use ($wuw) {
                    $qq->where('email', 'like', '%' . $wuw . '%')->whereOr('nickname', 'like', '%' . $wuw . '%');
                })->column('id');
                $wq->whereIn('w.user_id', $uids ?: [0]);
            }
        }
        if ($wfrom !== '') { $wq->where('w.create_time', '>=', strtotime($wfrom . ' 00:00:00')); }
        if ($wto !== '')   { $wq->where('w.create_time', '<=', strtotime($wto . ' 23:59:59')); }
        $withdraws = $wq->order('w.id', 'desc')->page($page, 30)->select()->toArray();
        $withdrawsTotal = (int)Db::name('withdrawals')->count();

        /* ---------- 用户昵称（流水用） ---------- */
        $uids = [];
        foreach ($credits as $r) { $uids[(int)$r['user_id']] = (int)$r['user_id']; }
        $uMap = [];
        if ($uids) {
            foreach (Db::name('users')->whereIn('id', array_values($uids))->field('id,nickname,email,status')->select()->toArray() as $u) {
                $label = $u['nickname'] !== '' ? $u['nickname'] : $u['email'];
                if ((int)$u['status'] === 3 || strpos((string)$u['email'], '#del') !== false) { $label .= '（已删除）'; }
                $uMap[(int)$u['id']] = $label;
            }
        }

        return $this->adminView('admin/finance', [
            'page'    => 'finance',
            'gateway' => $gateway,
            'is_open' => $config ? (int)$config->is_open : 0,
            'plans'   => $plans,
            'orders'  => $orders,
            'total_orders' => (int)Order::count(),
            'filtered_orders' => count($orders),
            'ono' => $ono, 'ouser' => $ouser, 'otype' => $otype,
            'ostatus' => $ostatus, 'odfrom' => $odfrom, 'odto' => $odto,
            'credits' => $credits, 'credits_total' => $creditsTotal,
            'credit_in' => $creditIn, 'credit_out' => $creditOut,
            'cuw' => $cuw, 'ctype' => $ctype, 'cfrom' => $cfrom, 'cto' => $cto,
            'withdraws' => $withdraws, 'withdraws_total' => $withdrawsTotal,
            'wstatus' => $wstatus, 'wuw' => $wuw, 'wfrom' => $wfrom, 'wto' => $wto,
            'types' => self::TYPES, 'user_map' => $uMap,
            'page_no' => $page,
        ]);
    }
}
