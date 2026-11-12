<?php
declare (strict_types = 1);

namespace app\admin\controller;

use app\admin\controller\Base;
use app\common\model\StrategyGroup;
use think\facade\Db;

/**
 * 策略组管理：列表 / 新增 / 编辑 / 删除（有用户引用禁止删）
 */
class GroupManage extends Base
{
    public function index()
    {
        // 键统一转 int，避免 PDO 返回类型差异导致模板键匹配失败
        $storages = [];
        foreach (Db::name('storage_configs')->where('status', 1)->field('id,name')->select()->toArray() as $r) {
            $storages[(int)$r['id']] = $r['name'];
        }

        // 列表注入 storage_name（模板动态键 + default 编译不可靠，改静态键访问）
        $list = StrategyGroup::order('id', 'asc')->select()->each(function ($item) use ($storages) {
            $item['storage_name'] = $storages[(int)$item['storage_id']] ?? '-';
            return $item;
        });

        return $this->adminView('admin/group', [
            'page'     => 'group',
            'list'     => $list,
            'storages' => $storages,
        ]);
    }

    public function save()
    {
        $id = (int)$this->request->post('id', 0);
        $data = [
            'name'        => trim((string)$this->request->post('name', '')),
            'remark'      => trim((string)$this->request->post('remark', '')),
            'max_size'    => (int)$this->request->post('max_size_mb', 0) * 1048576,
            'daily_count' => (int)$this->request->post('daily_count', 0),
            'quota_total' => (int)$this->request->post('quota_gb', 0) * 1073741824,
            'allow_ext'   => strtolower(trim((string)$this->request->post('allow_ext', 'jpg,jpeg,png,gif,webp'))),
            'keep_days'   => (int)$this->request->post('keep_days', 0),
            'storage_id'  => 0,  // 占位，下方校验后回填
            'ad_exempt'   => (int)$this->request->post('ad_exempt', 0),
            'status'      => (int)$this->request->post('status', 1),
            'update_time' => time(),
        ];

        // 存储实例必须存在（防悬空引用导致 '-' 显示）
        $storageId = (int)$this->request->post('storage_id', 1);
        if (!Db::name('storage_configs')->where('id', $storageId)->where('status', 1)->find()) {
            return json(['status' => false, 'message' => '所选存储实例不存在，请重新选择']);
        }
        $data['storage_id'] = $storageId;

        if ($data['name'] === '') {
            return json(['status' => false, 'message' => '组名必填']);
        }

        if ($id > 0) {
            Db::name('strategy_groups')->where('id', $id)->update($data);
        } else {
            $data['create_time'] = time();
            Db::name('strategy_groups')->insert($data);
        }
        return json(['status' => true, 'message' => '已保存']);
    }

    public function delete(int $id)
    {
        $used = Db::name('users')->where('group_id', $id)->count();
        if ($used > 0) {
            return json(['status' => false, 'message' => "有 {$used} 个用户在该组，先转移再删"]);
        }
        Db::name('strategy_groups')->where('id', $id)->delete();
        return json(['status' => true, 'message' => '已删除']);
    }
}
