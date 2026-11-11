<?php
declare (strict_types = 1);

namespace app\common\service;

use app\common\model\Order;
use app\common\model\PaymentConfigModel;
use app\common\model\Setting;
use app\common\model\User;
use app\common\model\VipPlan;
use think\facade\Db;
use think\facade\Log;

/**
 * 支付服务（易支付协议，平移自 cx PaymentService）
 *
 * 网关：逍遥PAY {api_url}/submit.php / 标准易支付 {api_url}/api/submit.php
 * 签名：剔除 sign/sign_type → ksort → a=b&c=d（空值跳过）→ 拼 key → MD5
 * 订单号前缀：V=VIP 套餐（C=容量包 二期）
 */
class PaymentService
{
    /** @var array 网关配置 api_url/pid/key/gateway_type */
    protected array $config = [];

    public function __construct()
    {
        $open = PaymentConfigModel::openYipay();
        if ($open && is_array($open->config)) {
            $this->config = $open->config;
        }
    }

    public function isAvailable(): bool
    {
        return ($this->config['api_url'] ?? '') !== ''
            && ($this->config['pid'] ?? '') !== ''
            && ($this->config['key'] ?? '') !== '';
    }

    /** 下单接口地址（api_url 已含 .php 原样使用） */
    public function resolveSubmitUrl(): string
    {
        $apiUrl = rtrim((string)($this->config['api_url'] ?? ''), '/');
        if (stripos($apiUrl, '.php') !== false) {
            return $apiUrl;
        }
        return $apiUrl . (($this->config['gateway_type'] ?? 'xpay') === 'yipay' ? '/api/submit.php' : '/submit.php');
    }

    /** 签名（cx 同款实现） */
    public function generateSign(array $params, string $key = ''): string
    {
        $key = $key !== '' ? $key : (string)($this->config['key'] ?? '');
        unset($params['sign'], $params['sign_type']);
        ksort($params);

        $signStr = '';
        foreach ($params as $k => $v) {
            if ($v === '' || $v === null || is_array($v)) {
                continue;
            }
            $signStr .= ($signStr !== '' ? '&' : '') . $k . '=' . $v;
        }
        return md5($signStr . $key);
    }

    /** 回调验签 */
    public function verifySign(array $params): bool
    {
        if (!isset($params['sign'])) {
            return false;
        }
        return (string)$params['sign'] === $this->generateSign($params);
    }

    /**
     * 创建 VIP 订单并返回支付跳转 URL
     * @return array{order:Order, pay_url:string}
     * @throws \RuntimeException
     */
    public function createVipOrder(User $user, VipPlan $plan): array
    {
        if (!$this->isAvailable()) {
            throw new \RuntimeException('支付通道未配置，联系站长');
        }
        if ((int)$plan->status !== 1) {
            throw new \RuntimeException('套餐已下架');
        }

        $orderNo = 'V' . date('YmdHis') . str_pad((string)random_int(0, 9999), 4, '0', STR_PAD_LEFT);

        $order = Order::create([
            'order_no'    => $orderNo,
            'user_id'     => (int)$user->id,
            'plan_id'     => (int)$plan->id,
            'type'        => 'vip',
            'amount'      => (int)$plan->price,
            'status'      => Order::STATUS_UNPAID,
            'create_time' => time(),
            'update_time' => time(),
        ]);

        $params = [
            'pid'          => (string)$this->config['pid'],
            // 支付通道：alipay/wxpay/qqpay 或商户后台的数字通道 ID（后台 pay_type 可配）
            'type'         => (string)($this->config['pay_type'] ?? 'alipay'),
            'out_trade_no' => $orderNo,
            'notify_url'   => rtrim(request()->domain(), '/') . '/pay/notify',
            'return_url'   => rtrim(request()->domain(), '/') . '/pay/return',
            'name'         => Setting::get('site_name', '逍遥图床') . ' - ' . $plan->name,
            'money'        => number_format((float)($plan->price / 100), 2, '.', ''),
        ];
        $params['sign'] = $this->generateSign($params);
        $params['sign_type'] = 'MD5';

        return ['order' => $order, 'pay_url' => $this->resolveSubmitUrl() . '?' . http_build_query($params)];
    }

    /**
     * 异步回调处理：验签 → 状态 → 金额 → VIP 生效
     * 幂等：已支付订单重复回调直接返回 true
     */
    public function handleNotify(array $params): bool
    {
        if (!$this->verifySign($params)) {
            Log::record('支付回调验签失败：' . json_encode($params), 'error');
            return false;
        }
        if (($params['trade_status'] ?? '') !== 'TRADE_SUCCESS') {
            Log::record('支付回调状态非成功：' . ($params['trade_status'] ?? ''), 'info');
            return false;
        }

        Db::startTrans();
        try {
            $order = Order::where('order_no', (string)$params['out_trade_no'])->lock(true)->find();
            if (!$order) {
                Db::rollback();
                Log::record('回调订单不存在：' . ($params['out_trade_no'] ?? ''), 'error');
                return false;
            }

            if ((int)$order->status === Order::STATUS_PAID) {
                Db::commit();
                return true; // 幂等
            }

            // 金额校验（元 vs 分）
            $paidYuan  = (float)($params['money'] ?? 0);
            $orderYuan = (int)$order->amount / 100;
            if (abs($paidYuan - $orderYuan) > 0.01) {
                Db::rollback();
                Log::record('回调金额不匹配：' . $order->order_no . ' 回调 ' . $paidYuan . ' 订单 ' . $orderYuan, 'error');
                return false;
            }

            $order->save([
                'status'     => Order::STATUS_PAID,
                'gateway'    => (string)($this->config['gateway_type'] ?? 'xpay'),
                'trade_no'   => (string)($params['trade_no'] ?? ''),
                'pay_time'   => time(),
                'notify_raw' => json_encode($params, JSON_UNESCAPED_UNICODE),
                'update_time'=> time(),
            ]);

            $this->activateVip($order);

            Db::commit();
            Log::record('VIP 订单支付成功：' . $order->order_no, 'info');
            return true;
        } catch (\Throwable $e) {
            Db::rollback();
            Log::record('支付回调处理异常：' . $e->getMessage(), 'error');
            return false;
        }
    }

    /** VIP 生效：到期时间取 max(now, 现有到期) + 套餐天数；策略组切到套餐组 */
    protected function activateVip(Order $order): void
    {
        $plan = VipPlan::find((int)$order->plan_id);
        if (!$plan || (string)$order->type !== 'vip') {
            return;
        }
        $user  = User::find((int)$order->user_id);
        if (!$user) {
            return;
        }
        $base    = max(time(), (int)$user->vip_expire_time);
        $expires = $base + (int)$plan->days * 86400;

        Db::name('users')->where('id', $user->id)->update([
            'vip_expire_time' => $expires,
            'group_id'        => (int)$plan->group_id,
            'update_time'     => time(),
        ]);
        Log::record('VIP 已生效：user=' . $user->id . ' 至 ' . date('Y-m-d', $expires) . ' 组=' . $plan->group_id, 'info');
    }
}
