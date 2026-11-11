<?php
declare (strict_types = 1);

namespace app\common\model;

use think\Model;

/**
 * 支付配置（平移 cx：config 字段 JSON 含 api_url/pid/key/gateway_type）
 */
class PaymentConfigModel extends Model
{
    protected $table = 'img_payment_config';

    protected $type = [
        'config' => 'json',
    ];

    /** 取已开启的易支付配置（返回 Model 或 null） */
    public static function openYipay(): ?self
    {
        return static::where('payment_code', 'yipay')->where('is_open', 1)->find() ?: null;
    }
}
