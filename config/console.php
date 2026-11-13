<?php
// +----------------------------------------------------------------------
// | 控制台配置
// +----------------------------------------------------------------------
return [
    // 指令定义
    'commands' => [
        'monitor:storage' => \app\command\StorageAlert::class,
        'monitor:vip'     => \app\command\VipNotice::class,
    ],
];
