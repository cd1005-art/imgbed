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
        // 邮箱验证：register 模式必须验证后才能登录
        if ((string)Setting::get('verify_mode', 'none') === 'register' && (int)$user->email_verified !== 1) {
            return $this->authFail('请先完成邮箱验证（查看注册时收到的邮件，或联系站长重发）');
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
                'email'          => $email,
                'password'       => password_hash($password, PASSWORD_BCRYPT),
                'nickname'       => mb_substr($nickname !== '' ? $nickname : explode('@', $email)[0], 0, 30),
                'group_id'       => $regGroup,
                'status'         => 1,
                'email_verified' => 0,
                'create_time'    => time(),
                'update_time'    => time(),
            ]);
        } catch (\Throwable $e) {
            Log::record('注册失败: ' . $e->getMessage(), 'error');
            return $this->authFail('注册没写进数据库，稍后再试');
        }

        $new = User::where('email', $email)->find();

        // 生成验证 token（不验证模式也生成，便于以后开通验证）
        $token = bin2hex(random_bytes(16));
        $hours = max(1, (int)Setting::get('verify_expire', '24'));
        Db::name('users')->where('id', $new->id)->update([
            'verify_token'  => $token,
            'verify_expire' => time() + $hours * 3600,
        ]);

        $mode = (string)Setting::get('verify_mode', 'none');
        if ($mode !== 'none') {
            $link = $this->request->domain() . '/verify/' . $token;
            \app\common\service\MailerService::sendTemplate(
                $email,
                '验证你的邮箱 · ' . (string)Setting::get('site_name', '逍遥图床'),
                'verify',
                ['name' => (string)$new->nickname, 'link' => $link]
            );
        }

        if ($mode === 'register') {
            // 需验证后才能登录
            return $this->siteView('auth/verify_sent', ['email' => $email]);
        }

        // none / lazy：注册即登录
        Session::regenerate();
        session('user_id', (int)$new->id);
        return redirect('/console');
    }

    /** 邮箱验证链接 */
    public function verify(string $token)
    {
        $user = User::where('verify_token', $token)->find();
        if (!$user || (int)$user->verify_expire < time()) {
            return $this->siteView('auth/verify_result', [
                'ok' => false, 'msg' => '验证链接无效或已过期，请到个人中心重新发送',
            ]);
        }
        Db::name('users')->where('id', $user->id)->update([
            'email_verified' => 1, 'verify_token' => '', 'verify_expire' => 0,
        ]);
        if (!session('user_id')) {
            Session::regenerate();
            session('user_id', (int)$user->id);
        }
        return $this->siteView('auth/verify_result', ['ok' => true, 'msg' => '邮箱验证成功，欢迎使用']);
    }

    /** 忘记密码页 */
    public function forgotPage()
    {
        return $this->siteView('auth/forgot');
    }

    /** 发送重置邮件（防枚举：一律显示已发送） */
    public function forgot()
    {
        $email = trim((string)$this->request->post('email', ''));
        $user  = filter_var($email, FILTER_VALIDATE_EMAIL) ? User::where('email', $email)->find() : null;
        if ($user) {
            $token = bin2hex(random_bytes(16));
            Db::name('users')->where('id', $user->id)->update([
                'reset_token'  => $token,
                'reset_expire' => time() + 3600,
            ]);
            $link = $this->request->domain() . '/reset/' . $token;
            \app\common\service\MailerService::sendTemplate(
                $email,
                '重置密码 · ' . (string)Setting::get('site_name', '逍遥图床'),
                'reset',
                ['name' => (string)$user->nickname, 'link' => $link]
            );
        }
        return $this->siteView('auth/forgot', ['sent' => 1, 'email' => $email]);
    }

    /** 重置密码页 */
    public function resetPage(string $token)
    {
        $user = User::where('reset_token', $token)->find();
        if (!$user || (int)$user->reset_expire < time()) {
            return $this->siteView('auth/verify_result', [
                'ok' => false, 'msg' => '重置链接无效或已过期，请重新申请',
            ]);
        }
        return $this->siteView('auth/reset', ['token' => $token]);
    }

    /** 提交新密码 */
    public function reset(string $token)
    {
        $user = User::where('reset_token', $token)->find();
        if (!$user || (int)$user->reset_expire < time()) {
            return $this->siteView('auth/verify_result', ['ok' => false, 'msg' => '链接已失效，请重新申请']);
        }
        $password = (string)$this->request->post('password', '');
        if (strlen($password) < 8) {
            return $this->siteView('auth/reset', ['token' => $token, 'error' => '密码至少 8 位']);
        }
        Db::name('users')->where('id', $user->id)->update([
            'password'     => password_hash($password, PASSWORD_BCRYPT),
            'reset_token'  => '',
            'reset_expire' => 0,
            'update_time'  => time(),
        ]);
        return $this->siteView('auth/verify_result', ['ok' => true, 'msg' => '密码已重置，请用新密码登录']);
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
