<?php
declare (strict_types = 1);

namespace app\admin\controller;

use app\admin\controller\Base;
use app\common\model\ApiToken;
use app\common\model\UploadLog;
use think\facade\Db;

/**
 * API Token 管理 + 上传日志
 */
class LogManage extends Base
{
    /** 上传日志 */
    public function index()
    {
        $page   = max(1, (int)$this->request->param('page', 1));
        $result = (int)$this->request->param('result', -1);

        $q = UploadLog::order('id', 'desc');
        if ($result >= 0) {
            $q->where('result', $result);
        }

        $list  = $q->page($page, 30)->select();
        $total = $result >= 0
            ? UploadLog::where('result', $result)->count()
            : UploadLog::count();

        return $this->adminView('admin/log', [
            'page'    => 'log',
            'list'    => $list,
            'result'  => $result,
            'page_no' => $page,
            'total'   => $total,
            'pages'   => (int)ceil($total / 30) ?: 1,
        ]);
    }

    /** Token 列表 */
    public function tokens()
    {
        $page = max(1, (int)$this->request->param('page', 1));

        $q = Db::name('api_tokens')->alias('t')
            ->leftJoin('users u', 'u.id = t.user_id')
            ->field('t.*, u.email as user_email, u.nickname as user_nickname')
            ->order('t.id', 'desc');

        $list  = $q->page($page, 20)->select();
        $total = Db::name('api_tokens')->count();

        return $this->adminView('admin/token', [
            'page'    => 'token',
            'list'    => $list,
            'page_no' => $page,
            'total'   => $total,
            'pages'   => (int)ceil($total / 20) ?: 1,
        ]);
    }

    /** Token 停用/启用 */
    public function toggleToken(int $id)
    {
        $token = ApiToken::find($id);
        if (!$token) {
            return json(['status' => false, 'message' => 'Token 不存在']);
        }
        $new = (int)$token->status === 1 ? 2 : 1;
        $token->save(['status' => $new, 'update_time' => time()]);
        return json(['status' => true, 'message' => $new === 1 ? '已启用' : '已停用']);
    }
}
