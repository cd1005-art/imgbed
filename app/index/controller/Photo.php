<?php
declare (strict_types = 1);

namespace app\index\controller;

use app\common\model\Image;
use app\common\model\Setting;
use app\common\model\User;
use app\index\controller\Base;
use think\facade\Db;

/** 公开图片详情页 /p/{id} */
class Photo extends Base
{
    public function show(int $id)
    {
        $img = Image::find($id);
        if (!$img || (int)$img->status !== Image::ST_NORMAL) {
            return $this->siteView('photo/show', ['image' => null, 'notfound' => true]);
        }
        $uid = (int)(session('user_id') ?: 0);
        $isOwner = $uid > 0 && $uid === (int)$img->user_id;
        if ((int)$img->is_public !== 1 && !$isOwner) {
            return $this->siteView('photo/show', ['image' => null, 'notfound' => true]);
        }

        Db::name('images')->where('id', $id)->inc('view_count')->update();

        $author = null;
        if ((int)$img->is_anonymous !== 1 && (int)$img->user_id > 0) {
            $author = User::find((int)$img->user_id);
        }

        $tags = json_decode((string)($img->tags ?? '[]'), true) ?: [];

        // 相关推荐：同标签的公开图
        $related = [];
        if ($tags) {
            try {
                $tagIds = Db::name('tags')->whereIn('name', $tags)->column('id');
                if ($tagIds) {
                    $ids = Db::name('image_tags')->whereIn('tag_id', $tagIds)->column('image_id');
                    $related = Image::whereIn('id', $ids ?: [0])
                        ->where('id', '<>', $id)
                        ->where('is_public', 1)
                        ->where('audit_status', Image::AUDIT_PASS)
                        ->where('status', Image::ST_NORMAL)
                        ->order('view_count', 'desc')
                        ->limit(8)->select();
                }
            } catch (\Throwable $e) {
            }
        }

        return $this->siteView('photo/show', [
            'image'    => $img,
            'author'   => $author,
            'tags'     => $tags,
            'related'  => $related,
            'is_owner' => $isOwner,
            'notfound' => false,
            'protect'  => [
                'right_click' => (string)Setting::get('block_right_click', '1') === '1',
                'drag'        => (string)Setting::get('block_drag_save', '1') === '1',
                'long_press'  => (string)Setting::get('block_long_press', '1') === '1',
            ],
        ]);
    }

    /** 按标签聚合页 /tag/{name} */
    public function tag()
    {
        $name = urldecode(trim((string)request()->param('name', '')));
        $page = max(1, (int)request()->param('page', 1));
        $size = 24;

        $tag = Db::name('tags')->where('name', $name)->find();
        $list = [];
        $total = 0;
        if ($tag) {
            $ids = Db::name('image_tags')->where('tag_id', $tag['id'])->column('image_id');
            $list = Image::whereIn('id', $ids ?: [0])
                ->where('is_public', 1)
                ->where('audit_status', Image::AUDIT_PASS)
                ->where('status', Image::ST_NORMAL)
                ->order('id', 'desc')
                ->page($page, $size)->select();
            $total = Image::whereIn('id', $ids ?: [0])
                ->where('is_public', 1)->where('audit_status', Image::AUDIT_PASS)
                ->where('status', Image::ST_NORMAL)->count();
        }

        return $this->siteView('photo/tag', [
            'tag_name' => $name,
            'tag'      => $tag,
            'list'     => $list,
            'total'    => $total,
            'page'     => $page,
            'pages'    => (int)ceil($total / $size),
        ]);
    }
}
