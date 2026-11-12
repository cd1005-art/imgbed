<?php
declare (strict_types = 1);

use think\facade\Route;

// ---- 首页 = 上传页 ----
Route::get('/', 'UploadController/index');
Route::post('upload/save', 'UploadController/save');

// ---- 认证 ----
// API 文档页（页脚 API 链接）
Route::get('api-doc', 'Index/apiDoc');

Route::get('login', 'Auth/loginPage');
Route::post('login', 'Auth/login');
Route::get('register', 'Auth/registerPage');
Route::post('register', 'Auth/register');
Route::get('logout', 'Auth/logout');

// ---- VIP 购买（具体规则在前，避免被 vip 短规则抢占） ----
Route::get('vip/buy/:id', 'Vip/buy');
Route::get('vip', 'Vip/index');

// ---- 支付回调 ----
Route::post('pay/notify', 'Pay/notify');
Route::get('pay/return', 'Pay/returnBack');

// ---- 登录后 ----
Route::group(function () {

    Route::get('gallery', 'Gallery/index');
    Route::post('gallery/delete/:id', 'Gallery/delete');

    Route::get('console', 'Console/index');
    Route::post('console/token/create', 'Console/tokenCreate');
    Route::post('console/token/delete/:id', 'Console/tokenDelete');
    Route::post('console/profile', 'Console/profile');

})->middleware(\app\index\middleware\CheckLogin::class);
