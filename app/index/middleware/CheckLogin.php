<?php
declare (strict_types = 1);

namespace app\index\middleware;

use app\common\model\User;
use think\Response;

/**
 * 前台登录态校验（gallery / console 页面）
 * 未登录 → 302 到 /login?redirect=原地址
 */
class CheckLogin
{
    public function handle($request, \Closure $next)
    {
        $userId = (int)session('user_id');
        $user   = $userId > 0 ? User::findUser($userId) : null;

        if (!$user) {
            $redirect = urlencode('/' . ltrim($request->pathinfo(), '/'));
            return redirect('/login?redirect=' . $redirect);
        }

        // 挂到 request 供控制器使用
        $request->loginUser = $user;

        return $next($request);
    }
}
