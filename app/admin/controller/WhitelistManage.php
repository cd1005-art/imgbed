<?php
declare (strict_types = 1);

namespace app\admin\controller;

use app\common\service\ApprovedFingerprintService;
use think\facade\Db;

/**
 * 审核白名单：查看与删除人工复审通过的指纹
 */
class WhitelistManage extends Base
{
    public function index()
    {
        $kw    = trim((string)$this->request->param('kw', ''));
        $page  = max(1, (int)$this->request->param('page', 1));
        $size  = (int)$this->request->param('per_page', 20);
        if (!in_array($size, [20, 50, 100, 200], true)) { $size = 20; }

        $q = Db::name('approved_fingerprints');
        if ($kw !== '') {
            $q->where(function ($w) use ($kw) {
                $w->whereLike('note', '%' . $kw . '%')->whereOr('sha1', 'like', $kw . '%');
            });
        }
        $total = (clone $q)->count();
        $list  = $q->order('id', 'desc')->page($page, $size)->select()->toArray();

        return $this->adminView('admin/whitelist', [
            'page'     => 'whitelist',
            'list'     => $list,
            'total'    => $total,
            'kw'       => $kw,
            'page_no'  => $page,
            'pages'    => (int)ceil($total / $size) ?: 1,
            'per_page' => $size,
            'qs'       => http_build_query(['kw' => $kw, 'per_page' => $size]),
        ]);
    }

    public function delete(int $id)
    {
        ApprovedFingerprintService::remove($id);
        return json(['status' => true, 'message' => '已移出白名单']);
    }

    public function clear()
    {
        $n = ApprovedFingerprintService::clear();
        return json(['status' => true, 'message' => "已清空白名单（{$n} 条）"]);
    }
}
