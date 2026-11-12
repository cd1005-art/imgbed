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

        // 套餐权益：实时读「生效策略组」，后台调整策略组后前台自动同步
        $planList = [];
        foreach ($plans as $plan) {
            $g = \app\common\model\StrategyGroup::where('id', $plan->group_id)->where('status', 1)->find();
            $plan['rights'] = [
                'quota_str'    => ($g && (int)$g->quota_total > 0)
                    ? round((int)$g->quota_total / 1073741824, 1) . ' GB 容量' : '容量不限',
                'daily_count'  => ($g && (int)$g->daily_count > 0) ? (string)(int)$g->daily_count . ' 次' : '不限',
                'max_size_str' => $g ? \app\common\service\UploadService::formatSize((int)$g->max_size) : '-',
                'keep_text'    => ($g && (int)$g->keep_days > 0) ? ((int)$g->keep_days . ' 天后清理') : '永久保留',
                'ext_str'      => $g ? implode(' / ', array_map('strtoupper', $g->allowedExt())) : '-',
                'ad_exempt'    => (bool)($g && (int)$g->ad_exempt === 1),
                'max_tokens'   => ($g && (int)$g->max_tokens > 0) ? ((int)$g->max_tokens . ' 个') : '不限',
            ];
            $planList[] = $plan;
        }

        // 只展示后台已配置通道的支付方式
        $channels = [];
        $conf = \app\common\model\PaymentConfigModel::openYipay();
        if ($conf && is_array($conf->config) && !empty($conf->config['pay_channels'])) {
            $names = ['alipay' => '支付宝', 'wxpay' => '微信', 'qqpay' => 'QQ 钱包'];
            foreach ($conf->config['pay_channels'] as $k => $v) {
                if (trim((string)$v) !== '' && isset($names[$k])) {
                    $channels[$k] = $names[$k];
                }
            }
        }

        return $this->siteView('vip/index', [
            'page_title'    => 'VIP 套餐',
            'plans'         => $planList,
            'channels'      => $channels,
            'first_channel' => $channels ? array_key_first($channels) : '',
            'msg'           => $msg,
        ]);
    }

    /** 购买：创建订单 → 跳支付网关（channel=alipay/wxpay/qqpay） */
    public function buy(int $id)
    {
        $channel = in_array(($this->request->param('channel', 'alipay')), ['alipay', 'wxpay', 'qqpay'], true)
            ? (string)$this->request->param('channel', 'alipay') : 'alipay';
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
            $result = (new PaymentService())->createVipOrder($user, $plan, $channel);
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
