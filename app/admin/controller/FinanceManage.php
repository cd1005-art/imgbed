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
        if ((string)$this->request->param('export', '') === '1') {
            return $this->exportCsv();
        }

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
        foreach ($orders as $o1) { $uids[(int)$o1['user_id']] = (int)$o1['user_id']; }
        $uMap = [];
        if ($uids) {
            foreach (Db::name('users')->whereIn('id', array_values($uids))->field('id,nickname,email,status')->select()->toArray() as $u) {
                $label = $u['nickname'] !== '' ? $u['nickname'] : $u['email'];
                if ((int)$u['status'] === 3 || strpos((string)$u['email'], '#del') !== false) { $label .= '（已删除）'; }
                $uMap[(int)$u['id']] = $label;
            }
        }

        // 站币统计（今日）与销售排行
        $today = strtotime(date('Y-m-d'));
        $creditStats = [
            'recharge' => (int)Db::name('orders')->where('type', 'credits')->where('status', 'paid')
                ->where('pay_time', '>=', $today)->sum('amount') / 100,
            'consume'  => abs((int)Db::name('credit_logs')->where('type', 'unlock')
                ->where('create_time', '>=', $today)->sum('amount')),
            'share'    => (int)Db::name('credit_logs')->where('type', 'share')
                ->where('create_time', '>=', $today)->sum('amount'),
            'pending'  => (int)Db::name('withdrawals')->whereIn('status', ['pending', 'approved'])->sum('real_amount'),
        ];
        $topImages = Db::name('unlocks')->alias('u')->join('images i', 'i.id = u.image_id')
            ->field('u.image_id, i.name, COUNT(*) AS cnt, SUM(u.price) AS total')
            ->group('u.image_id')->order('total', 'desc')->limit(8)->select()->toArray();

        // 订单：用户显示名
        foreach ($orders as &$or) {
            $uid = (int)$or['user_id'];
            $or['user_label'] = $uMap[$uid] ?? ('#' . $uid);
        }
        unset($or);

        // 站币流水：预计算用户显示名与类型名（模板不做数组下标运算）
        foreach ($credits as &$cr) {
            $uid = (int)$cr['user_id'];
            $cr['user_label'] = $uMap[$uid] ?? ('#' . $uid);
            $cr['type_label'] = self::TYPES[$cr['type']] ?? (string)$cr['type'];
        }
        unset($cr);

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
            'credit_stats' => $creditStats, 'top_images' => $topImages,
            'settings' => \app\common\model\Setting::allSettings(),
            // 充提套利测算：充 1 元得 N 币 -> 可提 N/M 元 -> 扣手续费后实得 (N/M)*(1-fee%)
            'arbitrage_pct' => (function () {
                $n   = max(1, (int)\app\common\model\Setting::get('credits_per_yuan', 10));
                $m   = max(1, (int)\app\common\model\Setting::get('withdraw_rate', 10));
                $fee = max(0, min(100, (int)\app\common\model\Setting::get('withdraw_fee_pct', 0)));
                return round(($n / $m) * (1 - $fee / 100) * 100 - 100, 2);
            })(),
            'credit_plans' => Db::name('credit_plans')->order('sort', 'asc')->order('id', 'asc')->select()->toArray(),
            'recharge_bonus_list' => Db::name('recharge_bonus')->order('amount', 'asc')->select()->toArray(),
            'page_no' => $page,
        ]);
    }
    /** 站币流水导出（沿用财务页站币筛选参数） */
    private function exportCsv()
    {
        $cuw   = trim((string)$this->request->param('cuw', ''));
        $ctype = trim((string)$this->request->param('ctype', ''));
        $cfrom = trim((string)$this->request->param('cfrom', ''));
        $cto   = trim((string)$this->request->param('cto', ''));

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

        $rows = $q->order('id', 'desc')->limit(20000)->select()->toArray();
        $ids = array_values(array_unique(array_map(fn($r) => (int)$r['user_id'], $rows)));
        $uMap = [];
        if ($ids) {
            foreach (Db::name('users')->whereIn('id', $ids)->field('id,nickname,email')->select()->toArray() as $u) {
                $uMap[(int)$u['id']] = ($u['nickname'] !== '' ? $u['nickname'] : $u['email']);
            }
        }

        $out = "ID,用户,账户,类型,变动,变动后余额,备注,时间\n";
        foreach ($rows as $r) {
            $uid = (int)$r['user_id'];
            $out .= implode(',', [
                $r['id'],
                '"' . str_replace('"', '""', $uMap[$uid] ?? ('#' . $uid)) . '"',
                ($r['account'] ?? 'credits') === 'earnings' ? '收益余额' : '充值余额',
                self::TYPES[$r['type']] ?? $r['type'],
                $r['amount'],
                $r['balance_after'],
                '"' . str_replace('"', '""', (string)$r['remark']) . '"',
                date('Y-m-d H:i:s', (int)$r['create_time']),
            ]) . "\n";
        }
        return response("\xEF\xBB\xBF" . $out, 200, [
            'Content-Type'        => 'text/csv; charset=utf-8',
            'Content-Disposition' => 'attachment; filename="credit-logs-' . date('Ymd-His') . '.csv"',
        ]);
    }
}
