<?php
declare (strict_types = 1);

namespace app\index\controller;

use app\index\controller\Base;
use app\common\model\Order;
use app\common\service\PaymentService;
use think\facade\Log;

/**
 * 支付回调（易支付协议双回调）
 * notify：异步 POST，返回纯文本 success/fail（网关以文本判定）
 * return：同步 GET，验签后跳转
 */
class Pay extends Base
{
    /** 异步通知：POST /pay/notify */
    public function notify()
    {
        $params = $this->request->param();
        Log::record('支付回调参数：' . json_encode($params, JSON_UNESCAPED_UNICODE), 'info');

        try {
            $result = (new PaymentService())->handleNotify($params);
            return $result ? 'success' : 'fail';
        } catch (\Throwable $e) {
            Log::record('支付回调异常：' . $e->getMessage(), 'error');
            return 'fail';
        }
    }

    /** 同步返回：GET /pay/return */
    public function returnBack()
    {
        $params = $this->request->param();
        Log::record('支付同步返回：' . json_encode($params, JSON_UNESCAPED_UNICODE), 'info');

        $service = new PaymentService();
        if (!$service->verifySign($params)) {
            return redirect('/console')->with(['msg' => '支付验证失败']);
        }

        $orderNo = (string)($params['out_trade_no'] ?? '');

        // 同步返回往往早于异步通知（notify）到达，这里短暂重试等待订单状态更新
        $order = null;
        for ($i = 0; $i < 5; $i++) {
            $order = Order::where('order_no', $orderNo)->find();
            if ($order && (int)$order->status === Order::STATUS_PAID) {
                break;
            }
            sleep(1);
        }

        $backUrl = ($order && (string)$order->type === 'credits') ? '/console#credits' : '/vip';
        if ($order && (int)$order->status === Order::STATUS_PAID) {
            $msg = (string)$order->type === 'credits' ? '充值成功，站币已到账' : 'VIP 开通成功';
            return redirect($backUrl)->with(['msg' => $msg]);
        }
        // 已扣款但回调未到：给中性提示，避免"未完成"造成误解
        return redirect($backUrl)->with(['msg' => '支付结果确认中，如已扣款请稍后刷新（通常几秒内到账）']);
    }
}
