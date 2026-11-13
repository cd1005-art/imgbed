<?php
declare (strict_types = 1);

namespace app\index\controller;

use app\common\model\Image;
use app\index\controller\Base;
use think\facade\Db;

/** 图片广场：全站公开图 */
class Explore extends Base
{
    public function index()
    {
        $page = max(1, (int)$this->request->param('page', 1));
        $sort = (string)$this->request->param('sort', 'new');
        $size = 30;

        $q = Image::where('is_public', 1)
            ->where('audit_status', Image::AUDIT_PASS)
            ->where('status', Image::ST_NORMAL);

        if ($sort === 'hot') {
            $q->order('view_count', 'desc')->order('id', 'desc');
        } else {
            $q->order('id', 'desc');
        }
        $list = $q->page($page, $size)->select();

        $total = Image::where('is_public', 1)
            ->where('audit_status', Image::AUDIT_PASS)
            ->where('status', Image::ST_NORMAL)->count();

        // 热门标签
        $hotTags = Db::name('tags')->where('status', 1)
            ->order('use_count', 'desc')->limit(20)->select()->toArray();

        return $this->siteView('photo/explore', [
            'list'    => $list,
            'total'   => $total,
            'page'    => $page,
            'pages'   => (int)ceil($total / $size),
            'sort'    => $sort,
            'hot_tags' => $hotTags,
        ]);
    }
}
