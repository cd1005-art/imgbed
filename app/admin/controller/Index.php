<?php
declare (strict_types = 1);

namespace app\admin\controller;

use app\admin\controller\Base;
use app\common\model\Admin;
use app\common\model\Image;
use app\common\model\UploadLog;
use app\common\model\User;
use think\facade\Db;
use think\facade\Session;

/**
 * 登录 / 登出 / 仪表盘
 */
class Index extends Base
{
    /** 登录页 */
    public function loginPage()
    {
        if (session('admin_id')) {
            return redirect('/admin');
        }
        return $this->adminView('admin/login', ['page' => 'login']);
    }

    /** 登录提交 */
    public function login()
    {
        $username = trim((string)$this->request->post('username', ''));
        $password = (string)$this->request->post('password', '');

        if ($username === '' || $password === '') {
            return $this->loginFail('用户名和密码都要填');
        }

        $admin = Admin::where('username', $username)->find();
        if (!$admin || !password_verify($password, (string)$admin->password)) {
            return $this->loginFail('用户名或密码不对');
        }
        if ((int)$admin->status !== 1) {
            return $this->loginFail('账号已被禁用');
        }

        Session::regenerate();
        session('admin_id', (int)$admin->id);

        Db::name('admins')->where('id', $admin->id)->update([
            'last_login_time' => time(),
            'last_login_ip'   => (string)$this->request->ip(),
        ]);

        return redirect('/admin');
    }

    /** 登出 */
    public function logout()
    {
        session('admin_id', null);
        Session::regenerate();
        return redirect('/admin/login');
    }

    /** 仪表盘 */
    public function dashboard()
    {
        $dayStart = strtotime(date('Y-m-d'));

        $stats = [
            'image_total'  => Image::where('status', Image::ST_NORMAL)->count(),
            'user_total'   => User::count(),
            'today_upload' => UploadLog::where('result', 1)->where('create_time', '>=', $dayStart)->count(),
            'today_fail'   => UploadLog::where('result', 0)->where('create_time', '>=', $dayStart)->count(),
            'storage_used' => (int)Image::where('status', Image::ST_NORMAL)->sum('size'),
            'pending_audit'=> Image::where('audit_status', Image::AUDIT_PENDING)->where('status', Image::ST_NORMAL)->count(),
        ];

        $recent = Image::where('status', Image::ST_NORMAL)->order('id', 'desc')->limit(10)->select();

        return $this->adminView('admin/dashboard', [
            'page'   => 'dashboard',
            'stats'  => $stats,
            'recent' => $recent,
        ]);
    }

    protected function loginFail(string $msg)
    {
        return $this->adminView('admin/login', ['error' => $msg]);
    }
}
