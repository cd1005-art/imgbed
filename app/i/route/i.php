<?php
declare (strict_types = 1);

use think\facade\Route;

// 外链应用路由：/i/{存储相对路径}（应用名 i 已被 multi-app 剥离）
Route::get(':path', 'ImageController/serve')
    ->pattern(['path' => '[\w\-\/]+\.[\w]{1,10}']);
