<?php
declare (strict_types = 1);

use think\facade\Route;

// API v1（Token 鉴权在控制器内完成，兼容 Lsky 生态：Authorization: Bearer / X-API-TOKEN）
Route::group('v1', function () {

    // 上传（Lsky 兼容路径 /api/v1/upload）
    Route::post('upload', 'V1/upload');

    // 图片信息
    Route::get('info/:id', 'V1/info');

    // 列表
    Route::get('list', 'V1/list');

    // 删除
    Route::post('delete/:id', 'V1/delete');

})->allowCrossDomain();
