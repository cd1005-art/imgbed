<?php
declare (strict_types = 1);

namespace app\index\controller;

use app\index\controller\Base;
use app\common\model\Setting;
use app\common\model\StrategyGroup;
use app\common\model\User;
use think\facade\Db;
use think\facade\Log;
use think\facade\Session;

/**
 * 登录 / 注册 / 登出
 *
 * 安全要点：bcrypt、session 固定防护（登录成功重置 session id）、
 * 注册限流（同 IP 日注册上限）、防枚举（登录失败统一文案）。
 */
class Auth extends Base
{
    /** 登录页 */
    public function loginPage()
    {
        if (session('user_id')) {
            return redirect('/console');
        }
        return $this->siteView('auth/login', [
            'redirect' => (string)$this->request->param('redirect', ''),
        ]);
    }

    /** 注册页 */
    public function registerPage()
    {
        if (session('user_id')) {
            return redirect('/console');
        }
        if (Setting::get('allow_register', '1') !== '1') {
            return $this->siteView('auth/register_closed');
        }
        return $this->siteView('auth/register');
    }

    /** 登录提交 */
    public function login()
    {
        $email    = trim((string)$this->request->post('email', ''));
        $password = (string)$this->request->post('password', '');
        $redirect = (string)$this->request->post('redirect', '');

        if ($email === '' || $password === '') {
            return $this->authFail('邮箱和密码都要填');
        }

        $user = User::where('email', $email)->find();

        // 防枚举：用户不存在与密码错误统一文案
        if (!$user || !password_verify($password, (string)$user->password)) {
            return $this->authFail('邮箱或密码不对');
        }
        if ((int)$user->status !== 1) {
            return $this->authFail('账号已被禁用，联系站长');
        }

        // VIP 到期检查：过期自动回落注册组
        if ($user->vip_expire_time > 0 && $user->vip_expire_time < time()) {
            $regGroup = (int)Setting::get('register_group_id', 2);
            if ((int)$user->group_id !== $regGroup) {
                Db::name('users')->where('id', $user->id)->update(['group_id' => $regGroup, 'update_time' => time()]);
            }
        }

        // session 固定防护
        Session::regenerate();
        session('user_id', (int)$user->id);

        Db::name('users')->where('id', $user->id)->update([
            'last_login_time' => time(),
            'last_login_ip'   => $this->clientIp(),
        ]);

        // 仅允许站内相对路径跳转（防开放重定向）
        $target = ($redirect !== '' && str_starts_with($redirect, '/') && !str_starts_with($redirect, '//'))
            ? $redirect : '/console';
        return redirect($target);
    }

    /** 注册提交 */
    public function register()
    {
        if (Setting::get('allow_register', '1') !== '1') {
            return $this->authFail('当前未开放注册');
        }

        $email    = trim((string)$this->request->post('email', ''));
        $password = (string)$this->request->post('password', '');
        $nickname = trim((string)$this->request->post('nickname', ''));

        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return $this->authFail('邮箱格式不对');
        }
        if (strlen($password) < 8) {
            return $this->authFail('密码至少 8 位');
        }

        // 注册限流：同 IP 每日 3 个
        $ip = $this->clientIp();
        $regToday = Db::name('users')
            ->where('last_login_ip', $ip)
            ->whereTime('create_time', 'today')
            ->count();
        if ($regToday >= 3) {
            return $this->authFail('今天这个 IP 注册次数到顶了，明天再来');
        }

        if (User::where('email', $email)->find()) {
            return $this->authFail('这个邮箱已经注册过，直接登录');
        }

        $regGroup = (int)Setting::get('register_group_id', 2);
        if (!StrategyGroup::where('id', $regGroup)->where('status', 1)->find()) {
            return $this->authFail('注册通道配置异常，联系站长');
        }

        try {
            User::create([
                'email'       => $email,
                'password'    => password_hash($password, PASSWORD_BCRYPT),
                'nickname'    => mb_substr($nickname !== '' ? $nickname : explode('@', $email)[0], 0, 30),
                'group_id'    => $regGroup,
                'status'      => 1,
                'create_time' => time(),
                'update_time' => time(),
            ]);
        } catch (\Throwable $e) {
            Log::record('注册失败: ' . $e->getMessage(), 'error');
            return $this->authFail('注册没写进数据库，稍后再试');
        }

        // 注册即登录
        $new = User::where('email', $email)->find();
        Session::regenerate();
        session('user_id', (int)$new->id);

        return redirect('/console');
    }

    /** 登出 */
    public function logout()
    {
        session('user_id', null);
        Session::regenerate();
        return redirect('/');
    }

    /** 登录/注册失败：回到表单页带错误提示 */
    protected function authFail(string $msg)
    {
        $isRegister = str_contains($this->request->pathinfo(), 'register');
        $view = $isRegister ? 'auth/register' : 'auth/login';
        return $this->siteView($view, [
            'error'    => $msg,
            'email'    => (string)$this->request->post('email', ''),
            'redirect' => (string)$this->request->post('redirect', ''),
        ]);
    }

    protected function clientIp(): string
    {
        return (string)($this->request->header('x-real-ip', '')
            ?: $this->request->header('x-forwarded-for', '')
            ?: $this->request->ip());
    }
}
