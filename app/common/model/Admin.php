<?php
declare (strict_types = 1);

namespace app\common\model;

use think\Model;

class Admin extends Model
{
    // 强制 int 时间戳：TP8 默认 datetime 转换会把 int 字段读成日期字符串
    protected $autoWriteTimestamp = 'int';

    protected $table = 'img_admins';
}
