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
    /** 上传日志（筛选：用户/IP/结果/日期） */
    public function index()
    {
        $page   = max(1, (int)$this->request->param('page', 1));
        $result = (int)$this->request->param('result', -1);
        $ufilter = trim((string)$this->request->param('ufilter', ''));
        $ip     = trim((string)$this->request->param('ip', ''));
        $dfrom  = (string)$this->request->param('dfrom', '');
        $dto    = (string)$this->request->param('dto', '');

        $build = function () use ($result, $ufilter, $ip, $dfrom, $dto) {
            $q = UploadLog::order('id', 'desc');
            if ($result >= 0)   { $q->where('result', $result); }
            if ($ip !== '')     { $q->where('ip', 'like', '%' . $ip . '%'); }
            if ($ufilter !== '') {
                if ($ufilter === '游客' || $ufilter === '0') {
                    $q->where('user_id', 0);
                } elseif (ctype_digit($ufilter)) {
                    $q->where('user_id', (int)$ufilter);
                } else {
                    $uids = Db::name('users')
                        ->where('email', 'like', '%' . $ufilter . '%')
                        ->whereOr('nickname', 'like', '%' . $ufilter . '%')
                        ->column('id');
                    $q->whereIn('user_id', $uids ?: [0]);
                }
            }
            if ($dfrom !== '')  { $q->where('create_time', '>=', strtotime($dfrom . ' 00:00:00')); }
            if ($dto !== '')    { $q->where('create_time', '<=', strtotime($dto . ' 23:59:59')); }
            return $q;
        };

        $list  = $build()->page($page, 30)->select();
        $total = $build()->count();

        return $this->adminView('admin/log', [
            'page'    => 'log',
            'list'    => $list,
            'result'  => $result,
            'ufilter' => $ufilter,
            'ip'      => $ip,
            'dfrom'   => $dfrom,
            'dto'     => $dto,
            'page_no' => $page,
            'total'   => $total,
            'pages'   => (int)ceil($total / 30) ?: 1,
        ]);
    }

    /** 删除单条日志 */
    public function delete(int $id)
    {
        Db::name('upload_logs')->where('id', $id)->delete();
        return json(['status' => true, 'message' => '已删除']);
    }

    /** 批量删除日志 / 按天数清理 */
    public function batch()
    {
        $action = (string)$this->request->post('action', '');
        if ($action === 'clean') {
            $days = max(1, (int)$this->request->post('days', 30));
            $before = time() - $days * 86400;
            $n = Db::name('upload_logs')->where('create_time', '<', $before)->delete();
            return json(['status' => true, 'message' => "已清理 {$days} 天前的 {\$n} 条日志"]);
        }
        $ids = array_filter(array_map('intval', explode(',', (string)$this->request->post('ids', ''))));
        if (!$ids) {
            return json(['status' => false, 'message' => '未选择日志']);
        }
        Db::name('upload_logs')->whereIn('id', $ids)->delete();
        return json(['status' => true, 'message' => '已删除 ' . count($ids) . ' 条日志']);
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

        // 调用统计卡片（按周期聚合调用日志）
        $dayStart = strtotime(date('Y-m-d'));
        $weekStart = $dayStart - 6 * 86400;
        $stats = [
            'today'      => Db::name('api_call_logs')->where('create_time', '>=', $dayStart)->count(),
            'today_up'   => Db::name('api_call_logs')->where('action', 'upload')->where('success', 1)->where('create_time', '>=', $dayStart)->count(),
            'week'       => Db::name('api_call_logs')->where('create_time', '>=', $weekStart)->count(),
            'week_up'    => Db::name('api_call_logs')->where('action', 'upload')->where('success', 1)->where('create_time', '>=', $weekStart)->count(),
            'total'      => Db::name('api_call_logs')->count(),
            'total_up'   => Db::name('api_call_logs')->where('action', 'upload')->where('success', 1)->count(),
        ];

        return $this->adminView('admin/token', [
            'stats'   => $stats,
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
