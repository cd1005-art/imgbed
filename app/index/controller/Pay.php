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

        $order = Order::where('order_no', (string)($params['out_trade_no'] ?? ''))->find();
        if ($order && (int)$order->status === Order::STATUS_PAID) {
            return redirect('/vip')->with(['msg' => 'VIP 开通成功']);
        }
        return redirect('/vip')->with(['msg' => '支付未完成，如已扣款请稍后刷新查看']);
    }
}
