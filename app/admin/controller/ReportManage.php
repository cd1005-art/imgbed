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
        $kw     = trim((string)$this->request->param('kw', ''));
        $dfrom  = trim((string)$this->request->param('dfrom', ''));
        $dto    = trim((string)$this->request->param('dto', ''));

        $q = Db::name('reports')->alias('r')
            ->leftJoin('img_images i', 'i.id = r.image_id')
            ->leftJoin('img_users u', 'u.id = r.reporter_id')
            ->field('r.*, i.name as image_name, i.path as image_path, i.is_public, i.ban_public, i.status as image_status, u.email as reporter_email');
        if ($status !== '') {
            $q->where('r.status', $status);
        }
        if ($kw !== '') {
            $q->where(function ($qq) use ($kw) {
                $qq->where('r.reason', 'like', '%' . $kw . '%')
                   ->whereOr('r.image_id', (int)$kw)
                   ->whereOr('r.reporter_id', (int)$kw);
            });
        }
        if ($dfrom !== '') { $q->where('r.create_time', '>=', strtotime($dfrom . ' 00:00:00')); }
        if ($dto !== '')   { $q->where('r.create_time', '<=', strtotime($dto . ' 23:59:59')); }
        $list  = $q->order('r.id', 'desc')->page($page, 30)->select()->toArray();
        $total = (int)Db::name('reports')->when($status !== '', function ($qq) use ($status) { $qq->where('status', $status); })->count();

        return $this->adminView('admin/report', [
            'page'    => 'report',
            'list'    => $list,
            'status'  => $status,
            'kw'      => $kw,
            'dfrom'   => $dfrom,
            'dto'     => $dto,
            'total'   => $total,
            'page_no' => $page,
            'pages'   => (int)ceil($total / 30) ?: 1,
        ]);
    }

    /** 判定下架（人工复核成立） */
    public function confirm(int $id)
    {
        if (!$this->confirmCore($id)) {
            return json(['status' => false, 'message' => '举报不存在']);
        }
        return json(['status' => true, 'message' => '已下架并加入违规指纹库']);
    }

    /** 批量下架（逐条复用人工复核逻辑） */
    public function batchConfirm()
    {
        $ids = array_filter(array_map('intval', explode(',', (string)$this->request->post('ids', ''))));
        if (!$ids) {
            return json(['status' => false, 'message' => '未选择举报记录']);
        }
        $ok = 0;
        foreach ($ids as $id) {
            if ($this->confirmCore($id)) {
                $ok++;
            }
        }
        return json(['status' => true, 'message' => "已下架 {$ok} 条举报对应的图片并加入违规指纹库"]);
    }

    /** 人工复核核心：下架图片 + 标记状态 + 通知举报人 */
    protected function confirmCore(int $id): bool
    {
        $rep = Db::name('reports')->where('id', $id)->find();
        if (!$rep) {
            return false;
        }
        $img = Image::find((int)$rep['image_id']);
        if ($img) {
            \app\common\service\ReportService::ban($img, '人工复核成立：' . (string)$rep['reason'], 'admin');
        }
        Db::name('reports')->where('id', $id)->update([
            'status' => 'manual', 'handled_by' => (int)session('admin_id'), 'update_time' => time(),
        ]);
        $this->notifyReporter((int)$rep['reporter_id'], '举报成立，图片已下架', (string)$rep['reason']);
        return true;
    }

    /** 驳回举报 */
    public function reject(int $id)
    {
        $rep = Db::name('reports')->where('id', $id)->find();
        if (!$rep) {
            return json(['status' => false, 'message' => '举报不存在']);
        }
        if ((string)$rep['status'] !== 'pending') {
            return json(['status' => false, 'message' => '仅「待处理」记录可驳回']);
        }
        Db::name('reports')->where('id', $id)->update([
            'status' => 'closed', 'handled_by' => (int)session('admin_id'), 'update_time' => time(),
        ]);
        $this->notifyReporter((int)$rep['reporter_id'], '举报未成立，已驳回', (string)$rep['reason']);
        return json(['status' => true, 'message' => '已驳回']);
    }

    /** 删除举报记录（单条/批量） */
    public function delete()
    {
        $ids = array_filter(array_map('intval', explode(',', (string)$this->request->post('ids', ''))));
        if (!$ids) {
            return json(['status' => false, 'message' => '未选择记录']);
        }
        $n = Db::name('reports')->whereIn('id', $ids)->delete();
        return json(['status' => true, 'message' => "已删除 {$n} 条举报记录（已下架的图片不受影响）"]);
    }

    /** 通知举报人处理结果 */
    protected function notifyReporter(int $uid, string $verdict, string $reason): void
    {
        try {
            $u = Db::name('users')->where('id', $uid)->find();
            if ($u) {
                \app\common\service\MailerService::notify('report_result', (string)$u['email'],
                    '举报处理结果 · ' . (string)\app\common\model\Setting::get('site_name', '逍遥图床'),
                    'report_result', ['name' => $verdict, 'reason' => mb_substr($reason, 0, 140)]);
            }
        } catch (\Throwable $e) {
        }
    }

    /** 解除图片下架限制（管理员） */
    public function unbanImage(int $imageId)
    {
        Db::name('images')->where('id', $imageId)->update(['ban_public' => 0, 'update_time' => time()]);
        return json(['status' => true, 'message' => '已解除「不可再公开」限制']);
    }
}
