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
            return $this->authFail(
                '请先完成邮箱验证：去邮箱查收 6 位验证码，或点击下方「重发验证码」重新获取。',
                ['need_resend' => 1, 'resend_email' => (string)$user->email]
            );
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

        $exist = User::where('email', $email)->find();
        if ($exist) {
            // 已注册但未验证 → 允许"重发验证码"并进入验证页（避免死锁：无法登录、无法重注册、无重发入口）
            if ((int)$exist->email_verified !== 1 && (int)$exist->status === 1) {
                $r = self::sendVerifyCode($exist);
                $wait = (int)($r['wait'] ?? 60);
                return $this->siteView('auth/verify_sent', [
                    'email'       => $email,
                    'resend_wait' => $wait,
                    'notice'      => $r['message'],
                ]);
            }
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

        // 生成 6 位数字验证码（纯文字邮件，避免链接被风控）
        $code  = self::makeCode();
        $hours = max(1, (int)Setting::get('verify_expire', '24'));
        Db::name('users')->where('id', $new->id)->update([
            'verify_token'  => $code,
            'verify_expire' => time() + $hours * 3600,
            'verify_tries'  => 0,
            'verify_sent_at'=> time(),
        ]);

        $mode = (string)Setting::get('verify_mode', 'none');
        if ($mode !== 'none') {
            \app\common\service\MailerService::notify(
                'verify',
                $email,
                '邮箱验证码 · ' . (string)Setting::get('site_name', '逍遥图床'),
                'verify',
                ['name' => (string)$new->nickname, 'code' => $code]
            );
        }

        if ($mode === 'register') {
            // 需验证后才能登录：注册成功页内嵌验证码输入
            $interval = max(30, (int)Setting::get('resend_interval', 60));
            return $this->siteView('auth/verify_sent', ['email' => $email, 'resend_wait' => $interval]);
        }

        // none / lazy：注册即登录
        Session::regenerate();
        session('user_id', (int)$new->id);
        return redirect('/console');
    }

    /** 验证页（未登录可访问，用于重发/输码） */
    public function verifySentPage()
    {
        $email = trim((string)$this->request->param('email', ''));
        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            // 未带邮箱：显示"输入邮箱获取验证码"表单
            return $this->siteView('auth/verify_request');
        }
        $user = User::where('email', $email)->find();
        if (!$user) {
            return redirect('/register');
        }
        if ((int)$user->email_verified === 1) {
            return $this->siteView('auth/verify_result', ['ok' => true, 'msg' => '该邮箱已完成验证，请直接登录']);
        }
        $interval = max(30, (int)Setting::get('resend_interval', 60));
        $last = (int)($user->verify_sent_at ?? 0);
        $wait = ($last > 0 && time() - $last < $interval) ? ($interval - (time() - $last)) : 0;
        return $this->siteView('auth/verify_sent', ['email' => $email, 'resend_wait' => $wait]);
    }

    /** 输入邮箱后发送验证码（未登录自助） */
    public function verifyRequest()
    {
        $email = trim((string)$this->request->post('email', ''));
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return $this->siteView('auth/verify_request', ['error' => '邮箱格式不对', 'email' => $email]);
        }
        $user = User::where('email', $email)->find();
        if (!$user) {
            return $this->siteView('auth/verify_request', ['error' => '该邮箱尚未注册', 'email' => $email]);
        }
        if ((int)$user->email_verified === 1) {
            return $this->siteView('auth/verify_result', ['ok' => true, 'msg' => '该邮箱已完成验证，请直接登录']);
        }
        $r = self::sendVerifyCode($user);
        if (!$r['ok'] && ($r['wait'] ?? 0) <= 0) {
            return $this->siteView('auth/verify_request', ['error' => $r['message'], 'email' => $email]);
        }
        return redirect('/verify-sent?email=' . urlencode($email));
    }

    /** 6 位数字验证码 */
    public static function makeCode(): string
    {
        return str_pad((string)random_int(0, 999999), 6, '0', STR_PAD_LEFT);
    }

    /**
     * 生成并发送验证码（公共）：受重发间隔限制
     * @return array ['ok'=>bool,'wait'=>剩余秒数,'message'=>string]
     */
    public static function sendVerifyCode(User $user): array
    {
        $interval = max(30, (int)Setting::get('resend_interval', 60));
        $last = (int)$user->verify_sent_at;
        if ($last > 0 && time() - $last < $interval) {
            $wait = $interval - (time() - $last);
            return ['ok' => false, 'wait' => $wait, 'message' => "请 {$wait} 秒后再试"];
        }

        $code  = self::makeCode();
        $hours = max(1, (int)Setting::get('verify_expire', '24'));
        Db::name('users')->where('id', $user->id)->update([
            'verify_token'   => $code,
            'verify_expire'  => time() + $hours * 3600,
            'verify_tries'   => 0,
            'verify_sent_at' => time(),
        ]);
        $ok = \app\common\service\MailerService::notify(
            'verify',
            (string)$user->email,
            '邮箱验证码 · ' . (string)Setting::get('site_name', '逍遥图床'),
            'verify',
            ['name' => (string)$user->nickname, 'code' => $code]
        );
        return [
            'ok'      => $ok,
            'wait'    => $interval,
            'message' => $ok ? '验证码已发送，请查收（含垃圾箱）' : '发送失败：请检查 SMTP 配置',
        ];
    }

    /** 重发验证码（注册后未登录，凭邮箱） */
    public function resendCode()
    {
        $email = trim((string)$this->request->post('email', ''));
        $user  = User::where('email', $email)->find();
        if (!$user) {
            return json(['status' => false, 'message' => '邮箱不存在', 'wait' => 0]);
        }
        if ((int)$user->email_verified === 1) {
            return json(['status' => true, 'message' => '邮箱已验证', 'wait' => 0]);
        }
        $r = self::sendVerifyCode($user);
        return json(['status' => $r['ok'], 'message' => $r['message'], 'wait' => $r['wait']]);
    }

    /** 验证码校验（注册验证，无需登录；凭 邮箱+验证码） */
    public function verifyCode()
    {
        $email = trim((string)$this->request->post('email', ''));
        $code  = trim((string)$this->request->post('code', ''));
        // 登录态可不传 email（个人中心内验证）
        if ($email === '' && session('user_id')) {
            $email = (string)(User::where('id', (int)session('user_id'))->value('email') ?: '');
        }
        $user  = User::where('email', $email)->find();

        if (!$user) {
            return json(['status' => false, 'message' => '邮箱不存在']);
        }
        if ((int)$user->email_verified === 1) {
            return json(['status' => true, 'message' => '邮箱已验证，无需重复操作']);
        }
        if ((string)$user->verify_token === '' || (int)$user->verify_expire < time()) {
            return json(['status' => false, 'message' => '验证码已过期，请重新获取']);
        }
        if ((int)$user->verify_tries >= 5) {
            return json(['status' => false, 'message' => '尝试次数过多，请重新获取验证码']);
        }
        if (!hash_equals((string)$user->verify_token, $code)) {
            Db::name('users')->where('id', $user->id)->inc('verify_tries')->update();
            return json(['status' => false, 'message' => '验证码不正确（剩余 ' . max(0, 4 - (int)$user->verify_tries) . ' 次）']);
        }

        Db::name('users')->where('id', $user->id)->update([
            'email_verified' => 1, 'verify_token' => '', 'verify_expire' => 0, 'verify_tries' => 0,
        ]);
        if (!session('user_id')) {
            Session::regenerate();
            session('user_id', (int)$user->id);
        }
        return json(['status' => true, 'message' => '邮箱验证成功']);
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
            $code = self::makeCode();
            Db::name('users')->where('id', $user->id)->update([
                'reset_token'  => $code,
                'reset_expire' => time() + 3600,
                'reset_tries'  => 0,
            ]);
            \app\common\service\MailerService::notify(
                'reset',
                $email,
                '密码重置验证码 · ' . (string)Setting::get('site_name', '逍遥图床'),
                'reset',
                ['name' => (string)$user->nickname, 'code' => $code]
            );
        }
        return $this->siteView('auth/forgot', ['stage' => 2, 'email' => $email]);
    }

    /** 验证码重置密码（email + code + password） */
    public function resetByCode()
    {
        $email    = trim((string)$this->request->post('email', ''));
        $code     = trim((string)$this->request->post('code', ''));
        $password = (string)$this->request->post('password', '');
        $user     = User::where('email', $email)->find();

        if (!$user || (string)$user->reset_token === '' || (int)$user->reset_expire < time()) {
            return $this->siteView('auth/forgot', ['stage' => 1, 'email' => $email, 'error' => '验证码已过期，请重新获取']);
        }
        if ((int)$user->reset_tries >= 5) {
            return $this->siteView('auth/forgot', ['stage' => 2, 'email' => $email, 'error' => '尝试次数过多，请重新获取验证码']);
        }
        if (!hash_equals((string)$user->reset_token, $code)) {
            Db::name('users')->where('id', $user->id)->inc('reset_tries')->update();
            return $this->siteView('auth/forgot', ['stage' => 2, 'email' => $email, 'error' => '验证码不正确']);
        }
        if (strlen($password) < 8) {
            return $this->siteView('auth/forgot', ['stage' => 2, 'email' => $email, 'error' => '密码至少 8 位']);
        }

        Db::name('users')->where('id', $user->id)->update([
            'password'     => password_hash($password, PASSWORD_BCRYPT),
            'reset_token'  => '', 'reset_expire' => 0, 'reset_tries' => 0,
            'update_time'  => time(),
        ]);
        return $this->siteView('auth/verify_result', ['ok' => true, 'msg' => '密码已重置，请用新密码登录']);
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
    protected function authFail(string $msg, array $extra = [])
    {
        $isRegister = str_contains($this->request->pathinfo(), 'register');
        $view = $isRegister ? 'auth/register' : 'auth/login';
        return $this->siteView($view, array_merge([
            'error'    => $msg,
            'email'    => (string)$this->request->post('email', ''),
            'redirect' => (string)$this->request->post('redirect', ''),
        ], $extra));
    }

    protected function clientIp(): string
    {
        return (string)($this->request->header('x-real-ip', '')
            ?: $this->request->header('x-forwarded-for', '')
            ?: $this->request->ip());
    }
}
