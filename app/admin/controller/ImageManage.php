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

        $q = Image::where('status', '>', 0);
        if ($kw !== '')     { $q->where('name', 'like', '%' . $kw . '%'); }
        if ($uid > 0)       { $q->where('user_id', $uid); }
        if ($sid > 0)       { $q->where('storage_id', $sid); }
        if ($audit !== '')  { $q->where('audit_status', (int)$audit); }
        if ($status !== '') { $q->where('status', (int)$status); }

        $list  = $q->order('id', 'desc')->page($page, $size)->select();
        $total = (clone $q)->count();

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
            'storages' => $storages,
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
