<?php
declare (strict_types = 1);

namespace app\admin\controller;

use think\facade\Db;

/**
 * 审核日志：模型/白名单/系统对每张图的判定记录
 */
class AuditLogManage extends Base
{
    public function index()
    {
        $result = (string)$this->request->param('result', '');
        $driver = (string)$this->request->param('driver', '');
        $kw     = trim((string)$this->request->param('kw', ''));
        $page   = max(1, (int)$this->request->param('page', 1));
        $size   = (int)$this->request->param('per_page', 20);
        if (!in_array($size, [20, 50, 100, 200], true)) { $size = 20; }

        $q = Db::name('audit_logs')->alias('a')
            ->leftJoin('images i', 'i.id = a.image_id')
            ->field('a.*, i.name AS img_name, i.path AS img_path');
        if ($result !== '') { $q->where('a.result', $result); }
        if ($driver !== '') { $q->where('a.driver', $driver); }
        if ($kw !== '') {
            $q->where(function ($w) use ($kw) {
                $w->whereLike('i.name', '%' . $kw . '%')->whereOr('a.detail', 'like', '%' . $kw . '%');
            });
        }
        $total = (clone $q)->count();
        $list  = $q->order('a.id', 'desc')->page($page, $size)->select()->toArray();

        $drivers = Db::name('audit_logs')->distinct(true)->column('driver');

        return $this->adminView('admin/audit_log', [
            'page'     => 'audit_log',
            'list'     => $list,
            'total'    => $total,
            'result'   => $result,
            'driver'   => $driver,
            'kw'       => $kw,
            'drivers'  => $drivers,
            'page_no'  => $page,
            'pages'    => (int)ceil($total / $size) ?: 1,
            'per_page' => $size,
            'qs'       => http_build_query(['result' => $result, 'driver' => $driver, 'kw' => $kw, 'per_page' => $size]),
        ]);
    }

    public function delete(int $id)
    {
        Db::name('audit_logs')->where('id', $id)->delete();
        return json(['status' => true, 'message' => '已删除该条日志']);
    }

    public function clear()
    {
        $n = Db::name('audit_logs')->where('id', '>', 0)->delete();
        return json(['status' => true, 'message' => "已清空审核日志（{$n} 条）"]);
    }
}
