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
        $page  = max(1, (int)$this->request->param('page', 1));
        $size  = 20;
        $kw    = trim((string)$this->request->param('kw', ''));
        $uid   = (int)$this->request->param('uid', 0);

        $q = Image::where('status', '>', 0);
        if ($kw !== '') {
            $q->where('name', 'like', '%' . $kw . '%');
        }
        if ($uid > 0) {
            $q->where('user_id', $uid);
        }

        $list  = $q->order('id', 'desc')->page($page, $size)->select();
        $total = $q->count();

        return $this->adminView('admin/image', [
            'page'  => 'image',
            'list'  => $list,
            'kw'    => $kw,
            'uid'   => $uid,
            'page_no' => $page,
            'total' => $total,
            'pages' => (int)ceil($total / $size) ?: 1,
        ]);
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
