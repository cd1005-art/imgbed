<?php
declare (strict_types = 1);

namespace app\common\model;

use think\Model;

/**
 * 订单：order_no 前缀 V=VIP / C=容量包
 */
class Order extends Model
{
    // 强制 int 时间戳：TP8 默认 datetime 转换会把 int 字段读成日期字符串
    protected $autoWriteTimestamp = 'int';

    protected $table = 'img_orders';

    const STATUS_UNPAID = 0;
    const STATUS_PAID   = 1;
    const STATUS_CANCEL = 2;
    const STATUS_REFUND = 3;
}
