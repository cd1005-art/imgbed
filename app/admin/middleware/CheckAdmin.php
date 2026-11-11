<?php
declare (strict_types = 1);

namespace app\admin\middleware;

use app\common\model\Setting;
use think\Response;

/**
 * 后台登录校验：未登录跳 /admin/login（登录页自身路由白名单处理）
 */
class CheckAdmin
{
    public function handle($request, \Closure $next)
    {
        $adminId = (int)session('admin_id');
        if ($adminId <= 0) {
            return redirect('/admin/login');
        }

        $request->loginAdmin = \app\common\model\Admin::find($adminId);
        if (!$request->loginAdmin || (int)$request->loginAdmin->status !== 1) {
            session('admin_id', null);
            return redirect('/admin/login');
        }

        return $next($request);
    }
}
