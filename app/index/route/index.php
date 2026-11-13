<?php
declare (strict_types = 1);

use think\facade\Route;

// ---- 首页 = 上传页 ----
Route::get('/', 'UploadController/index');
Route::post('upload/save', 'UploadController/save');

// ---- 认证 ----
// 邮箱验证 / 找回密码（具体路由在前）
Route::post('verify-code', 'Auth/verifyCode');
Route::post('resend-code', 'Auth/resendCode');
Route::get('verify-sent', 'Auth/verifySentPage');
Route::post('verify-sent', 'Auth/verifyRequest');
Route::post('reset-code', 'Auth/resetByCode');
Route::get('verify/:token', 'Auth/verify');
Route::get('forgot', 'Auth/forgotPage');
Route::post('forgot', 'Auth/forgot');
Route::get('reset/:token', 'Auth/resetPage');
Route::post('reset/:token', 'Auth/reset');

Route::get('login', 'Auth/loginPage');
Route::post('login', 'Auth/login');
Route::get('register', 'Auth/registerPage');
Route::post('register', 'Auth/register');
Route::get('logout', 'Auth/logout');

// ---- 公开页面：广场 / 图片详情 / 标签聚合（无需登录） ----
Route::get('p/:id', 'Photo/show');
Route::get('tag', 'Photo/tag');
Route::get('explore', 'Explore/index');

// ---- 计划任务 URL 触发（key 校验，无需登录） ----
Route::get('cron/:task', 'Cron/run');

// ---- VIP 购买（具体规则在前，避免被 vip 短规则抢占） ----
Route::get('vip/buy/:id', 'Vip/buy');
Route::get('vip', 'Vip/index');

// ---- 支付回调 ----
Route::post('pay/notify', 'Pay/notify');
Route::get('pay/return', 'Pay/returnBack');

// ---- 登录后 ----
Route::group(function () {

    Route::post('gallery/visibility/:id', 'Gallery/visibility');
Route::post('gallery/batch-visibility', 'Gallery/batchVisibility');
Route::post('gallery/all-visibility', 'Gallery/allVisibility');
Route::post('gallery/auto-public', 'Gallery/autoPublic');
Route::get('gallery', 'Gallery/index');

    Route::post('gallery/delete/:id', 'Gallery/delete');
    Route::post('gallery/price/:id', 'Gallery/price');
    Route::post('report/:id', 'Photo/report');
    Route::post('unlock/:id', 'Photo/unlock');

    Route::post('console/resend-verify', 'Console/resendVerify');
    Route::post('console/recharge', 'Console/recharge');
    Route::post('console/withdraw', 'Console/withdraw');
Route::get('console', 'Console/index');
    Route::post('console/token/create', 'Console/tokenCreate');
    Route::post('console/token/delete/:id', 'Console/tokenDelete');
    Route::post('console/profile', 'Console/profile');

})->middleware(\app\index\middleware\CheckLogin::class);
