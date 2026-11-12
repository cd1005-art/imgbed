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

    /** Token 列表（筛选：关键词/状态） */
    public function tokens()
    {
        $page   = max(1, (int)$this->request->param('page', 1));
        $kw     = trim((string)$this->request->param('kw', ''));
        $status = (string)$this->request->param('status', '');

        $build = function () use ($kw, $status) {
            $q = Db::name('api_tokens')->alias('t')
                ->leftJoin('img_users u', 'u.id = t.user_id')
                ->field('t.*, u.email as user_email, u.nickname as user_nickname');
            if ($kw !== '') {
                $q->where(function ($qq) use ($kw) {
                    $qq->where('t.name', 'like', '%' . $kw . '%')
                       ->whereOr('u.email', 'like', '%' . $kw . '%')
                       ->whereOr('u.nickname', 'like', '%' . $kw . '%');
                });
            }
            if ($status !== '') { $q->where('t.status', (int)$status); }
            return $q;
        };

        $list  = $build()->order('t.id', 'desc')->page($page, 20)->select();
        $total = $build()->count();

        return $this->adminView('admin/token', [
            'page'    => 'token',
            'list'    => $list,
            'kw'      => $kw,
            'status'  => $status,
            'page_no' => $page,
            'total'   => $total,
            'pages'   => (int)ceil($total / 20) ?: 1,
        ]);
    }

    /** Token 编辑保存：名称/日调用上限/容量上限 */
    public function saveToken(int $id)
    {
        $token = ApiToken::find($id);
        if (!$token) {
            return json(['status' => false, 'message' => 'Token 不存在']);
        }
        Db::name('api_tokens')->where('id', $id)->update([
            'name'        => mb_substr(trim((string)$this->request->post('name', $token->name)), 0, 30) ?: $token->name,
            'daily_limit' => max(0, (int)$this->request->post('daily_limit', 0)),
            'quota_limit' => max(0, (int)$this->request->post('quota_limit_mb', 0)) * 1048576,
            'update_time' => time(),
        ]);
        return json(['status' => true, 'message' => '已保存']);
    }

    /** Token 批量：action=enable/disable/delete */
    public function batchToken()
    {
        $action = (string)$this->request->post('action', '');
        $ids    = array_filter(array_map('intval', explode(',', (string)$this->request->post('ids', ''))));
        if (!$ids) {
            return json(['status' => false, 'message' => '未选择 Token']);
        }
        if ($action === 'delete') {
            Db::name('api_tokens')->whereIn('id', $ids)->delete();
            return json(['status' => true, 'message' => '已删除 ' . count($ids) . ' 个 Token']);
        }
        if (!in_array($action, ['enable', 'disable'], true)) {
            return json(['status' => false, 'message' => '参数错误']);
        }
        Db::name('api_tokens')->whereIn('id', $ids)->update([
            'status'      => $action === 'enable' ? 1 : 2,
            'update_time' => time(),
        ]);
        return json(['status' => true, 'message' => ($action === 'enable' ? '已启用 ' : '已停用 ') . count($ids) . ' 个 Token']);
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
