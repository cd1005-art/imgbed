<?php
declare (strict_types = 1);

namespace app\admin\controller;

use app\admin\controller\Base;
use app\common\model\Image;
use app\common\service\FingerprintService;
use think\facade\Db;

/** 举报管理：列表 / 复核下架 / 驳回 */
class ReportManage extends Base
{
    public function index()
    {
        $page   = max(1, (int)$this->request->param('page', 1));
        $status = trim((string)$this->request->param('status', ''));

        $q = Db::name('reports')->alias('r')
            ->leftJoin('img_images i', 'i.id = r.image_id')
            ->leftJoin('img_users u', 'u.id = r.reporter_id')
            ->field('r.*, i.name as image_name, i.path as image_path, i.is_public, i.ban_public, i.status as image_status, u.email as reporter_email');
        if ($status !== '') {
            $q->where('r.status', $status);
        }
        $list  = $q->order('r.id', 'desc')->page($page, 30)->select()->toArray();
        $total = (int)Db::name('reports')->when($status !== '', function ($qq) use ($status) { $qq->where('status', $status); })->count();

        return $this->adminView('admin/report', [
            'page'    => 'report',
            'list'    => $list,
            'status'  => $status,
            'total'   => $total,
            'page_no' => $page,
            'pages'   => (int)ceil($total / 30) ?: 1,
        ]);
    }

    /** 判定下架（人工复核成立） */
    public function confirm(int $id)
    {
        $rep = Db::name('reports')->where('id', $id)->find();
        if (!$rep) {
            return json(['status' => false, 'message' => '举报不存在']);
        }
        $img = Image::find((int)$rep['image_id']);
        if ($img) {
            \app\common\service\ReportService::ban($img, '人工复核成立：' . (string)$rep['reason'], 'admin');
        }
        Db::name('reports')->where('id', $id)->update([
            'status' => 'manual', 'handled_by' => (int)session('admin_id'), 'update_time' => time(),
        ]);
        return json(['status' => true, 'message' => '已下架并加入违规指纹库']);
    }

    /** 驳回举报 */
    public function reject(int $id)
    {
        $rep = Db::name('reports')->where('id', $id)->find();
        if (!$rep) {
            return json(['status' => false, 'message' => '举报不存在']);
        }
        Db::name('reports')->where('id', $id)->update([
            'status' => 'closed', 'handled_by' => (int)session('admin_id'), 'update_time' => time(),
        ]);
        return json(['status' => true, 'message' => '已驳回']);
    }

    /** 解除图片下架限制（管理员） */
    public function unbanImage(int $imageId)
    {
        Db::name('images')->where('id', $imageId)->update(['ban_public' => 0, 'update_time' => time()]);
        return json(['status' => true, 'message' => '已解除「不可再公开」限制']);
    }
}
