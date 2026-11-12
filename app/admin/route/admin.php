<?php
declare (strict_types = 1);

use think\facade\Route;

// 登录（无需登录态）
Route::get('login', 'Index/loginPage');
Route::post('login', 'Index/login');

// 管理路由（全部挂登录校验）
Route::group(function () {

    Route::get('/', 'Index/dashboard');
    Route::get('logout', 'Index/logout');

    Route::get('image', 'ImageManage/index');
    Route::post('image/toggle/:id', 'ImageManage/toggle');
    Route::post('image/delete/:id', 'ImageManage/delete');
    Route::post('image/batch', 'ImageManage/batch');

    Route::get('user', 'UserManage/index');
    Route::post('user/toggle/:id', 'UserManage/toggle');
    Route::post('user/group/:id', 'UserManage/changeGroup');
    Route::post('user/resetpwd/:id', 'UserManage/resetPwd');

    Route::get('group', 'GroupManage/index');
    Route::post('group/save', 'GroupManage/save');
    Route::post('group/delete/:id', 'GroupManage/delete');

    Route::get('storage', 'StorageManage/index');
    Route::post('storage/save', 'StorageManage/save');
    Route::post('storage/probe/:id', 'StorageManage/probe');
    Route::post('storage/delete/:id', 'StorageManage/delete');

    Route::get('payment', 'PaymentManage/index');
    Route::post('payment/gateway', 'PaymentManage/saveGateway');
    Route::post('payment/plan', 'PaymentManage/savePlan');
    Route::post('payment/plan/delete/:id', 'PaymentManage/deletePlan');

    Route::get('ad', 'AdManage/index');
    Route::post('ad/slot', 'AdManage/saveSlot');
    Route::post('ad/settings', 'AdManage/saveSettings');

    Route::get('log', 'LogManage/index');
    Route::get('token', 'LogManage/tokens');
    Route::post('token/toggle/:id', 'LogManage/toggleToken');

})->middleware(\app\admin\middleware\CheckAdmin::class);
