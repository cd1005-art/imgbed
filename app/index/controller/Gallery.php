<?php
declare (strict_types = 1);

namespace app\index\controller;

use app\index\controller\Base;
use app\common\model\ApiToken;
use app\common\model\Image;
use think\facade\Db;

/**
 * 我的图片（登录后）
 *
 * @property \app\common\model\User $loginUser 由 CheckLogin 中间件挂载
 */
class Gallery extends Base
{
    /** 列表页 */
    public function index()
    {
        $user  = $this->request->loginUser;
        $page  = max(1, (int)$this->request->param('page', 1));
        $size  = 24;

        $list = Image::where('user_id', $user->id)
            ->where('status', Image::ST_NORMAL)
            ->order('id', 'desc')
            ->page($page, $size)
            ->select();

        $total = Image::where('user_id', $user->id)->where('status', Image::ST_NORMAL)->count();

        return $this->siteView('gallery/index', [
            'user'  => $user,
            'list'  => $list,
            'page'  => $page,
            'total' => $total,
            'pages' => (int)ceil($total / $size),
        ]);
    }

    /** 删除（本人） */
    public function delete(int $id)
    {
        $user = $this->request->loginUser;
        $image = Image::where('id', $id)
            ->where('user_id', $user->id)
            ->where('status', Image::ST_NORMAL)
            ->find();

        if (!$image) {
            return json(['status' => false, 'message' => '图片不存在']);
        }

        $image->save(['status' => Image::ST_DELETED, 'delete_time' => time()]);
        // 配额回退
        Db::name('users')->where('id', $user->id)->dec('quota_used', (int)$image->size)->update();

        return json(['status' => true, 'message' => '已删除']);
    }
}
