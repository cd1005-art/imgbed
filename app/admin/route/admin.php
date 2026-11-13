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

    Route::post('image/orphan/scan', 'ImageManage/orphanScan');
    Route::post('image/orphan/purge', 'ImageManage/orphanPurge');
    Route::get('image/export', 'ImageManage/export');
    Route::post('tag/delete', 'TagManage/delete');
    Route::post('tag/batch-status', 'TagManage/batchStatus');
    Route::post('tag/toggle/:id', 'TagManage/toggle');
    Route::post('tag/merge', 'TagManage/merge');
    Route::post('report/confirm/:id', 'ReportManage/confirm');
    Route::post('report/reject/:id', 'ReportManage/reject');
    Route::post('report/unban/:imageId', 'ReportManage/unbanImage');
    Route::post('withdraw/approve/:id', 'WithdrawManage/approve');
    Route::post('withdraw/paid/:id', 'WithdrawManage/paid');
    Route::post('withdraw/reject/:id', 'WithdrawManage/reject');
    Route::post('gen/prompt/gen', 'GenManage/promptGen');
    Route::post('gen/prompt/template', 'GenManage/promptTemplate');
    Route::post('gen/prompt/reverse', 'GenManage/promptReverse');
    Route::post('gen/prompt/append', 'GenManage/promptAppend');
    Route::post('gen/prompt/usage', 'GenManage/promptUsage');
    Route::get('gen/prompt/export/:id', 'GenManage/promptExport');
    Route::get('gen/detail/:id', 'GenManage/detail');
    Route::post('gen/copy/:id', 'GenManage/copy');
    Route::post('gen/run/:id', 'GenManage/runNow');
    Route::post('gen/batch', 'GenManage/batch');
    Route::post('gen/settings', 'GenManage/saveSettings');
    Route::post('gen/delete-logs', 'GenManage/deleteLogs');
    Route::post('gen/clear-logs', 'GenManage/clearLogs');
    Route::post('gen/save', 'GenManage/save');
    Route::post('gen/toggle/:id', 'GenManage/toggle');
    Route::post('gen/delete/:id', 'GenManage/delete');
    Route::get('gen', 'GenManage/index');
    Route::post('withdraw/delete', 'WithdrawManage/delete');
    Route::get('withdraw', 'FinanceManage/index');
    Route::post('report/batch-confirm', 'ReportManage/batchConfirm');
    Route::post('report/delete', 'ReportManage/delete');
    Route::get('report', 'ReportManage/index');
    Route::get('tag', 'TagManage/index');
    Route::get('image', 'ImageManage/index');
    Route::post('image/update/:id', 'ImageManage/update');
    Route::post('image/approve/:id', 'ImageManage/approve');
    Route::post('image/reject/:id', 'ImageManage/reject');
    Route::post('image/toggle/:id', 'ImageManage/toggle');
    Route::post('image/delete/:id', 'ImageManage/delete');
    Route::post('image/batch', 'ImageManage/batch');

    Route::post('user/delete/:id', 'UserManage/delete');
    Route::post('user/verify-email/:id', 'UserManage/verifyEmail');
    Route::post('user/credits/:id', 'UserManage/adjustCredits');
    Route::post('credit/delete', 'CreditManage/delete');
    Route::get('credit', 'FinanceManage/index');
    Route::get('user', 'UserManage/index');
    Route::post('user/save/:id', 'UserManage/save');
    Route::post('user/batch', 'UserManage/batch');
    Route::post('user/toggle/:id', 'UserManage/toggle');
    Route::post('user/group/:id', 'UserManage/changeGroup');
    Route::post('user/resetpwd/:id', 'UserManage/resetPwd');

    Route::get('group', 'GroupManage/index');
    Route::post('group/toggle/:id', 'GroupManage/toggle');
    Route::post('group/save', 'GroupManage/save');
    Route::post('group/delete/:id', 'GroupManage/delete');

    Route::get('storage', 'StorageManage/index');
    Route::post('storage/save', 'StorageManage/save');
    Route::post('storage/probe/:id', 'StorageManage/probe');
    Route::post('storage/delete/:id', 'StorageManage/delete');

    Route::get('finance', 'FinanceManage/index');
    Route::get('payment', 'FinanceManage/index');  // 旧入口兼容
    Route::post('payment/gateway', 'PaymentManage/saveGateway');
    Route::post('payment/plan', 'PaymentManage/savePlan');
    Route::post('order/pay/:id', 'PaymentManage/orderPay');
    Route::post('order/close/:id', 'PaymentManage/orderClose');
    Route::get('order/detail/:id', 'PaymentManage/orderDetail');
    Route::post('payment/delete-orders', 'PaymentManage/deleteOrders');
    Route::post('payment/plan/delete/:id', 'PaymentManage/deletePlan');

    Route::post('mail/test', 'MailManage/test');
    Route::post('mail/delete-logs', 'MailManage/deleteLogs');
    Route::post('mail/clear-logs', 'MailManage/clearLogs');
    Route::post('mail/save', 'MailManage/save');
    Route::post('cron/run/:task', 'CronManage/run');
    Route::get('cron/log/:task', 'CronManage/log');
    Route::post('cron/rotate', 'CronManage/rotateKey');
    Route::get('cron', 'CronManage/index');
    Route::get('mail', 'MailManage/index');

    Route::post('nav/save', 'NavManage/save');
    Route::post('nav/reset', 'NavManage/reset');
    Route::get('nav', 'NavManage/index');
    Route::get('site', 'AdManage/site');
    Route::post('site/save', 'AdManage/siteSave');
    Route::get('ad', 'AdManage/index');
    Route::post('ad/slot', 'AdManage/saveSlot');
    Route::post('model/save', 'ModelManage/save');
    Route::post('model/delete/:id', 'ModelManage/delete');
    Route::post('model/move/:id/:dir', 'ModelManage/move');
    Route::post('model/test/:id', 'ModelManage/test');
    Route::post('model/toggle/:id', 'ModelManage/toggle');
    Route::post('model/driver', 'ModelManage/driverSave');
    Route::get('model', 'ModelManage/index');

    Route::post('log/delete/:id', 'LogManage/delete');
    Route::post('log/batch', 'LogManage/batch');
    Route::get('log', 'LogManage/index');
    Route::get('token', 'LogManage/tokens');
    Route::post('token/save/:id', 'LogManage/saveToken');
    Route::post('token/batch', 'LogManage/batchToken');
    Route::post('token/toggle/:id', 'LogManage/toggleToken');

})->middleware(\app\admin\middleware\CheckAdmin::class);
