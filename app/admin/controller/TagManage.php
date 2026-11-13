<?php
declare (strict_types = 1);

namespace app\admin\controller;

use app\admin\controller\Base;
use think\facade\Db;

/** 标签管理：列表 / 停用 / 合并 */
class TagManage extends Base
{
    public function index()
    {
        $page   = max(1, (int)$this->request->param('page', 1));
        $kw     = trim((string)$this->request->param('kw', ''));
        $status = (string)$this->request->param('tstatus', '');
        $minUse = (int)$this->request->param('min_use', 0);
        $order  = (string)$this->request->param('torder', 'use_desc');

        $build = function () use ($kw, $status, $minUse) {
            $q = Db::name('tags');
            if ($kw !== '') {
                $q->where('name', 'like', '%' . $kw . '%');
            }
            if ($status !== '') {
                $q->where('status', (int)$status);
            }
            if ($minUse > 0) {
                $q->where('use_count', '>=', $minUse);
            }
            return $q;
        };
        $orderBy = $order === 'name' ? 'name asc' : ($order === 'use_asc' ? 'use_count asc' : 'use_count desc');
        $list  = $build()->order($orderBy)->page($page, 40)->select()->toArray();
        $total = $build()->count();

        return $this->adminView('admin/tag', [
            'page'    => 'tag',
            'list'    => $list,
            'kw'      => $kw,
            'tstatus' => $status,
            'min_use' => $minUse,
            'torder'  => $order,
            'total'   => $total,
            'page_no' => $page,
            'pages'   => (int)ceil($total / 40) ?: 1,
        ]);
    }

    /** 删除标签（单条/批量，ids 逗号分隔） */
    public function delete()
    {
        $ids = array_filter(array_map('intval', explode(',', (string)$this->request->post('ids', ''))));
        if (!$ids) {
            return json(['status' => false, 'message' => '未选择标签']);
        }
        $n = Db::name('tags')->whereIn('id', $ids)->delete();
        Db::name('image_tags')->whereIn('tag_id', $ids)->delete();
        return json(['status' => true, 'message' => "已删除 {$n} 个标签（图片关联已清理）"]);
    }

    /** 批量启用/停用（ids 逗号分隔，status=1 启用 / 0 停用） */
    public function batchStatus()
    {
        $ids = array_filter(array_map('intval', explode(',', (string)$this->request->post('ids', ''))));
        if (!$ids) {
            return json(['status' => false, 'message' => '未选择标签']);
        }
        $status = (int)$this->request->post('status', 1) === 1 ? 1 : 0;
        $n = Db::name('tags')->whereIn('id', $ids)->update(['status' => $status]);
        return json(['status' => true, 'message' => '已' . ($status ? '启用' : '停用') . " {$n} 个标签"]);
    }

    public function toggle(int $id)
    {
        $t = Db::name('tags')->where('id', $id)->find();
        if (!$t) {
            return json(['status' => false, 'message' => '标签不存在']);
        }
        Db::name('tags')->where('id', $id)->update(['status' => (int)$t['status'] === 1 ? 0 : 1]);
        return json(['status' => true, 'message' => '已切换状态']);
    }

    /** 合并：来源标签并入目标（关联迁移 + 别名登记） */
    public function merge()
    {
        $fromName = trim((string)$this->request->post('from', ''));
        $toId     = (int)$this->request->post('to_id', 0);
        $to       = Db::name('tags')->where('id', $toId)->find();
        $from     = Db::name('tags')->where('name', $fromName)->find();

        if (!$to || !$from || (int)$to['id'] === (int)$from['id']) {
            return json(['status' => false, 'message' => '参数错误（检查来源名与目标 ID）']);
        }

        $imageIds = Db::name('image_tags')->where('tag_id', $from['id'])->column('image_id');
        foreach ($imageIds as $iid) {
            $exists = Db::name('image_tags')->where('image_id', $iid)->where('tag_id', $to['id'])->find();
            if (!$exists) {
                Db::name('image_tags')->insert([
                    'image_id' => $iid, 'tag_id' => $to['id'], 'source' => 'auto', 'create_time' => time(),
                ]);
            }
        }
        Db::name('image_tags')->where('tag_id', $from['id'])->delete();
        if (!Db::name('tag_aliases')->where('alias', $from['name'])->find()) {
            Db::name('tag_aliases')->insert([
                'tag_id' => $to['id'], 'alias' => $from['name'], 'source' => 'admin', 'create_time' => time(),
            ]);
        }
        Db::name('tags')->where('id', $to['id'])->inc('use_count', (int)$from['use_count'])->update();
        Db::name('tags')->where('id', $from['id'])->delete();

        return json(['status' => true, 'message' => "已把「{$fromName}」并入「{$to['name']}」"]);
    }
}
