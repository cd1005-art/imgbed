<?php
declare (strict_types = 1);

namespace app\admin\controller;

use app\admin\controller\Base;
use app\common\model\PaymentConfigModel;
use app\common\model\Setting;
use app\common\service\PaymentService;
use app\common\model\VipPlan;
use app\common\model\Order;
use think\facade\Db;

/**
 * 支付管理：网关配置（易支付协议）+ 套餐 + 订单
 */
class PaymentManage extends Base
{
    public function index()
    {
        $config  = PaymentConfigModel::where('payment_code', 'yipay')->find();
        $gateway = $config && is_array($config->config) ? $config->config : [];
        $gateway += ['api_url' => '', 'pid' => '', 'key' => '', 'gateway_type' => 'xpay'];
        $gateway['pay_channels'] = (isset($gateway['pay_channels']) && is_array($gateway['pay_channels']))
            ? $gateway['pay_channels'] + ['alipay' => '', 'wxpay' => '', 'qqpay' => '']
            : ['alipay' => '', 'wxpay' => '', 'qqpay' => ''];
        $isOpen  = $config ? (int)$config->is_open : 0;

        return $this->adminView('admin/payment', [
            'page'    => 'payment',
            'gateway' => $gateway,
            'is_open' => $isOpen,
            'plans'   => VipPlan::order('sort', 'asc')->select(),
            'orders'  => Order::order('id', 'desc')->limit(20)->select(),
        ]);
    }

    /** 网关配置保存（三通道：支付宝/微信/QQ 钱包，每通道独立开关与通道号） */
    public function saveGateway()
    {
        $data = [
            'api_url'      => rtrim(trim((string)$this->request->post('api_url', '')), '/'),
            'pid'          => trim((string)$this->request->post('pid', '')),
            'key'          => trim((string)$this->request->post('key', '')),
            'gateway_type' => (string)$this->request->post('gateway_type', 'xpay'),
            // 三通道映射：站内通道 => 网关 type（alipay/wxpay/qqpay 或逍遥PAY 通道 ID）
            'pay_channels' => [
                'alipay' => trim((string)$this->request->post('channel_alipay', '')),
                'wxpay'  => trim((string)$this->request->post('channel_wxpay', '')),
                'qqpay'  => trim((string)$this->request->post('channel_qqpay', '')),
            ],
        ];

        if ($data['api_url'] !== '' && ($data['pid'] === '' || $data['key'] === '')) {
            return json(['status' => false, 'message' => '填了网关地址就必须填商户ID和密钥']);
        }

        $exist = PaymentConfigModel::where('payment_code', 'yipay')->find();
        if ($exist) {
            Db::name('payment_config')->where('id', $exist->id)->update([
                'config'      => json_encode($data, JSON_UNESCAPED_SLASHES),
                'is_open'     => (int)$this->request->post('is_open', 0),
                'update_time' => time(),
            ]);
        } else {
            Db::name('payment_config')->insert([
                'payment_code' => 'yipay',
                'name'         => '易支付协议',
                'config'       => json_encode($data, JSON_UNESCAPED_SLASHES),
                'is_open'      => (int)$this->request->post('is_open', 0),
                'create_time'  => time(),
                'update_time'  => time(),
            ]);
        }
        return json(['status' => true, 'message' => '已保存']);
    }

    /** 套餐保存 */
    public function savePlan()
    {
        $id   = (int)$this->request->post('id', 0);
        $data = [
            'name'        => trim((string)$this->request->post('name', '')),
            'days'        => (int)$this->request->post('days', 30),
            'price'       => (int)round(((float)$this->request->post('price_yuan', 0)) * 100),
            'group_id'    => (int)$this->request->post('group_id', 3),
            'description' => trim((string)$this->request->post('description', '')),
            'sort'        => (int)$this->request->post('sort', 0),
            'status'      => (int)$this->request->post('status', 1),
            'update_time' => time(),
        ];
        if ($data['name'] === '') {
            return json(['status' => false, 'message' => '套餐名必填']);
        }
        if ($id > 0) {
            Db::name('vip_plans')->where('id', $id)->update($data);
        } else {
            $data['create_time'] = time();
            Db::name('vip_plans')->insert($data);
        }
        return json(['status' => true, 'message' => '已保存']);
    }

    /** 套餐删除 */
    public function deletePlan(int $id)
    {
        Db::name('vip_plans')->where('id', $id)->delete();
        return json(['status' => true, 'message' => '已删除']);
    }
}
