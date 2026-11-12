<?php
declare (strict_types = 1);

namespace app\admin\controller;

use app\admin\controller\Base;
use app\common\model\Image;
use app\common\model\UploadLog;
use think\facade\Db;

/**
 * 图片管理：列表 / 搜索 / 冻结 / 解冻 / 删除
 */
class ImageManage extends Base
{
    public function index()
    {
        $page   = max(1, (int)$this->request->param('page', 1));
        $size   = 20;
        $kw     = trim((string)$this->request->param('kw', ''));
        $uid    = (int)$this->request->param('uid', 0);
        $sid    = (int)$this->request->param('sid', 0);       // 存储实例
        $audit  = (string)$this->request->param('audit', ''); // 审核状态 0/1/2/3
        $status = (string)$this->request->param('status', '');// 状态 1正常/2冻结
        $dfrom  = (string)$this->request->param('dfrom', '');  // 起始日期 Y-m-d
        $dto    = (string)$this->request->param('dto', '');    // 结束日期 Y-m-d

        $build = function () use ($kw, $uid, $sid, $audit, $status, $dfrom, $dto) {
            $q = Image::alias('i')->leftJoin('img_users u', 'u.id = i.user_id')
                ->field('i.*, u.nickname as user_nickname, u.email as user_email')
                ->where('i.status', '>', 0);
            if ($kw !== '')     { $q->where('i.name', 'like', '%' . $kw . '%'); }
            if ($uid > 0)       { $q->where('i.user_id', $uid); }
            if ($sid > 0)       { $q->where('i.storage_id', $sid); }
            if ($audit !== '')  { $q->where('i.audit_status', (int)$audit); }
            if ($status !== '') { $q->where('i.status', (int)$status); }
            if ($dfrom !== '')  { $q->where('i.create_time', '>=', strtotime($dfrom . ' 00:00:00')); }
            if ($dto !== '')    { $q->where('i.create_time', '<=', strtotime($dto . ' 23:59:59')); }
            return $q;
        };

        $list  = $build()->order('i.id', 'desc')->page($page, $size)->select();
        $total = $build()->count();

        // 用户下拉（筛选用）
        $users = Db::name('users')->field('id,nickname,email')->order('id', 'asc')->limit(500)->select()->toArray();

        // 存储实例名映射（列表展示用）
        $storages = [];
        foreach (Db::name('storage_configs')->field('id,name,driver')->select()->toArray() as $r) {
            $storages[(int)$r['id']] = $r['name'] . '（' . $r['driver'] . '）';
        }

        return $this->adminView('admin/image', [
            'page'     => 'image',
            'list'     => $list,
            'kw'       => $kw,
            'uid'      => $uid,
            'sid'      => $sid,
            'audit'    => $audit,
            'status'   => $status,
            'dfrom'    => $dfrom,
            'dto'      => $dto,
            'storages' => $storages,
            'users'    => $users,
            'page_no'  => $page,
            'total'    => $total,
            'pages'    => (int)ceil($total / $size) ?: 1,
        ]);
    }

    /** 批量操作：action=freeze/unfreeze/delete，ids 逗号分隔 */
    public function batch()
    {
        $action = (string)$this->request->post('action', '');
        $ids    = array_filter(array_map('intval', explode(',', (string)$this->request->post('ids', ''))));

        if (!$ids || !in_array($action, ['freeze', 'unfreeze', 'delete'], true)) {
            return json(['status' => false, 'message' => '参数错误']);
        }

        $count = 0;
        foreach ($ids as $id) {
            $image = Image::find($id);
            if (!$image) { continue; }
            if ($action === 'delete') {
                $image->save(['status' => Image::ST_DELETED, 'delete_time' => time()]);
                if ((int)$image->user_id > 0) {
                    Db::name('users')->where('id', $image->user_id)->dec('quota_used', (int)$image->size)->update();
                }
            } else {
                $image->save(['status' => $action === 'freeze' ? Image::ST_FROZEN : Image::ST_NORMAL, 'update_time' => time()]);
            }
            $count++;
        }

        $label = ['freeze' => '冻结', 'unfreeze' => '解冻', 'delete' => '删除'][$action];
        return json(['status' => true, 'message' => "已{$label} {$count} 张"]);
    }

    /** 按当前筛选导出 CSV */
    public function export()
    {
        $kw     = trim((string)$this->request->param('kw', ''));
        $uid    = (int)$this->request->param('uid', 0);
        $sid    = (int)$this->request->param('sid', 0);
        $audit  = (string)$this->request->param('audit', '');
        $status = (string)$this->request->param('status', '');
        $dfrom  = (string)$this->request->param('dfrom', '');
        $dto    = (string)$this->request->param('dto', '');

        $q = Image::where('status', '>', 0);
        if ($kw !== '')     { $q->where('name', 'like', '%' . $kw . '%'); }
        if ($uid > 0)       { $q->where('user_id', $uid); }
        if ($sid > 0)       { $q->where('storage_id', $sid); }
        if ($audit !== '')  { $q->where('audit_status', (int)$audit); }
        if ($status !== '') { $q->where('status', (int)$status); }
        if ($dfrom !== '')  { $q->where('create_time', '>=', strtotime($dfrom . ' 00:00:00')); }
        if ($dto !== '')    { $q->where('create_time', '<=', strtotime($dto . ' 23:59:59')); }

        $storages = [];
        foreach (Db::name('storage_configs')->field('id,name,driver')->select()->toArray() as $r) {
            $storages[(int)$r['id']] = $r['name'] . '（' . $r['driver'] . '）';
        }

        $rows = $q->order('id', 'desc')->limit(5000)->select();
        $domain = request()->domain();

        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="images_' . date('Ymd_His') . '.csv"');
        header('Cache-Control: no-store');
        // BOM 保证 Excel 中文不乱码
        echo "\xEF\xBB\xBF";
        $out = fopen('php://output', 'w');
        fputcsv($out, ['ID', '文件名', '外链URL', '存储路径', '用户ID', '大小(字节)', '存储实例', '审核状态', '状态', 'IP', '上传时间']);
        $auditMap  = [0 => '待审', 1 => '通过', 2 => '拒绝', 3 => '人工复审'];
        $statusMap = [1 => '正常', 2 => '冻结'];

        foreach ($rows as $img) {
            fputcsv($out, [
                (int)$img->id,
                (string)$img->name,
                $domain . '/i/' . $img->path,
                (string)$img->path,
                (int)$img->user_id,
                (int)$img->size,
                $storages[(int)$img->storage_id] ?? '-',
                $auditMap[(int)$img->audit_status] ?? '-',
                $statusMap[(int)$img->status] ?? '-',
                (string)$img->ip,
                date('Y-m-d H:i:s', ts($img->create_time)),
            ]);
        }
        fclose($out);
        exit;
    }

    /** 冻结 / 解冻（外链即断） */
    public function toggle(int $id)
    {
        $image = Image::find($id);
        if (!$image) {
            return json(['status' => false, 'message' => '图片不存在']);
        }
        $new = (int)$image->status === Image::ST_NORMAL ? Image::ST_FROZEN : Image::ST_NORMAL;
        $image->save(['status' => $new, 'update_time' => time()]);
        return json(['status' => true, 'message' => $new === Image::ST_FROZEN ? '已冻结（外链已断）' : '已解冻']);
    }

    /** 软删除 */
    public function delete(int $id)
    {
        $image = Image::find($id);
        if (!$image) {
            return json(['status' => false, 'message' => '图片不存在']);
        }
        $image->save(['status' => Image::ST_DELETED, 'delete_time' => time()]);
        if ((int)$image->user_id > 0) {
            Db::name('users')->where('id', $image->user_id)->dec('quota_used', (int)$image->size)->update();
        }
        return json(['status' => true, 'message' => '已删除']);
    }
}
