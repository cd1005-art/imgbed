<?php
declare (strict_types = 1);

namespace app\index\controller;

use app\common\model\VipPlan;
use app\common\service\PaymentService;
use think\facade\Session;

/**
 * VIP 套餐购买页
 */
class Vip extends Base
{
    public function index()
    {
        $plans = VipPlan::where('status', 1)->order('sort', 'asc')->select();
        $msg   = (string)($this->request->session('msg') ?? '');

        return $this->siteView('vip/index', [
            'page_title' => 'VIP 套餐',
            'plans'      => $plans,
            'msg'        => $msg,
        ]);
    }

    /** 购买：创建订单 → 跳支付网关 */
    public function buy(int $id)
    {
        \think\facade\Log::record('buy 被调用 id=' . $id . ' uid=' . (int)Session::get('user_id'), 'info');
        $userId = (int)Session::get('user_id');
        if ($userId <= 0) {
            return redirect('/login?redirect=/vip');
        }

        $user = \app\common\model\User::findUser($userId);
        $plan = VipPlan::where('id', $id)->where('status', 1)->find();
        if (!$user || !$plan) {
            return redirect('/vip');
        }

        try {
            $result = (new PaymentService())->createVipOrder($user, $plan);
        } catch (\RuntimeException $e) {
            return $this->siteView('vip/index', [
                'page_title' => 'VIP 套餐',
                'plans'      => VipPlan::where('status', 1)->order('sort', 'asc')->select(),
                'error'      => $e->getMessage(),
            ]);
        }

        return redirect($result['pay_url']);
    }
}
