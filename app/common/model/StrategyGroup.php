<?php
declare (strict_types = 1);

namespace app\common\model;

use think\Model;

class StrategyGroup extends Model
{
    protected $table = 'img_strategy_groups';

    protected $type = [
        'allow_ext' => 'array',
    ];

    /** 扩展名白名单（数组） */
    public function allowedExt(): array
    {
        return $this->allow_ext ?: ['jpg', 'jpeg', 'png', 'gif', 'webp'];
    }
}
