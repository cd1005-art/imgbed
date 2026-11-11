<?php
declare (strict_types = 1);

namespace app\common\model;

use think\Model;

class Image extends Model
{
    protected $table = 'img_images';

    /** 审核状态常量 */
    const AUDIT_PENDING = 0;   // 待审
    const AUDIT_PASS    = 1;   // 通过
    const AUDIT_REJECT  = 2;   // 拒绝
    const AUDIT_REVIEW  = 3;   // 人工复审

    /** 状态常量 */
    const ST_NORMAL  = 1;   // 正常
    const ST_FROZEN  = 2;   // 冻结
    const ST_DELETED = 0;   // 已删
}
