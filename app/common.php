<?php
// 应用公共文件

/**
 * 通用时间戳解析：兼容 int 时间戳 / 日期字符串（TP8 model 可能把 int 字段读成 datetime 字符串）
 */
function ts($value): int
{
    if ($value === null || $value === '') {
        return 0;
    }
    if (is_numeric($value)) {
        return (int)$value;
    }
    $t = strtotime((string)$value);
    return $t === false ? 0 : $t;
}
