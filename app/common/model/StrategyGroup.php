<?php
declare (strict_types = 1);

namespace app\common\model;

use think\Model;

class StrategyGroup extends Model
{
    // 强制 int 时间戳：TP8 默认 datetime 转换会把 int 字段读成日期字符串
    protected $autoWriteTimestamp = 'int';

    protected $table = 'img_strategy_groups';

    // allow_ext 存储为逗号分隔字符串，不用 array cast（json_decode 对逗号串会变 null 导致回填丢失）

    /** 扩展名白名单（数组），兼容 array/string 两种取值形态 */
    public function allowedExt(): array
    {
        $v = $this->allow_ext;
        if (is_array($v)) {
            $v = implode(',', $v);
        }
        $exts = array_filter(array_map('trim', explode(',', (string)$v)));
        return $exts ?: ['jpg', 'jpeg', 'png', 'gif', 'webp'];
    }

    /** 供后台编辑回填的扩展名字符串 */
    public function allowExtString(): string
    {
        $v = $this->allow_ext;
        if (is_array($v)) {
            $v = implode(',', $v);
        }
        return (string)$v ?: 'jpg,jpeg,png,gif,webp';
    }
}
