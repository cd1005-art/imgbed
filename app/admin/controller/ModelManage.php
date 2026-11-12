<?php
declare (strict_types = 1);

namespace app\admin\controller;

use app\admin\controller\Base;
use app\common\model\Setting;
use think\facade\Db;

/**
 * 模型设置：多模型审核链（按顺序优先，失败自动切换下一个，全部失败转人工）
 */
class ModelManage extends Base
{
    public function index()
    {
        $models = Db::name('audit_models')->order('sort', 'asc')->order('id', 'asc')->select()->toArray();

        return $this->adminView('admin/model', [
            'page'      => 'model',
            'models'    => $models,
            'settings'  => Setting::allSettings(),
        ]);
    }

    /** 新增 / 编辑模型卡片 */
    public function save()
    {
        $id = (int)$this->request->post('id', 0);
        $data = [
            'name'        => trim((string)$this->request->post('name', '')),
            'endpoint'    => trim((string)$this->request->post('endpoint', '')),
            'api_key'     => trim((string)$this->request->post('api_key', '')),
            'model_name'  => trim((string)$this->request->post('model_name', '')),
            'status'      => (int)$this->request->post('status', 1) === 1 ? 1 : 2,
            'update_time' => time(),
        ];
        if ($data['name'] === '' || $data['endpoint'] === '' || $data['model_name'] === '') {
            return json(['status' => false, 'message' => '名称/端点/模型名必填']);
        }

        if ($id > 0) {
            Db::name('audit_models')->where('id', $id)->update($data);
        } else {
            $maxSort = (int)Db::name('audit_models')->max('sort');
            $data['sort'] = $maxSort + 10;
            $data['create_time'] = time();
            Db::name('audit_models')->insert($data);
        }
        return json(['status' => true, 'message' => '已保存']);
    }

    public function delete(int $id)
    {
        Db::name('audit_models')->where('id', $id)->delete();
        return json(['status' => true, 'message' => '已删除']);
    }

    /** 上下移动（dir=up/down） */
    public function move(int $id, string $dir)
    {
        $cur = Db::name('audit_models')->where('id', $id)->find();
        if (!$cur) {
            return json(['status' => false, 'message' => '模型不存在']);
        }
        $models = Db::name('audit_models')->order('sort', 'asc')->order('id', 'asc')->select()->toArray();
        $idx = null;
        foreach ($models as $i => $m) {
            if ((int)$m['id'] === $id) { $idx = $i; break; }
        }
        $swap = $dir === 'up' ? $idx - 1 : $idx + 1;
        if ($idx === null || $swap < 0 || $swap >= count($models)) {
            return json(['status' => true, 'message' => '已到边界']);
        }
        // 交换 sort 值（重排序号保证稳定）
        $a = $models[$idx];
        $b = $models[$swap];
        Db::name('audit_models')->where('id', $a['id'])->update(['sort' => $b['sort'], 'update_time' => time()]);
        Db::name('audit_models')->where('id', $b['id'])->update(['sort' => $a['sort'], 'update_time' => time()]);
        return json(['status' => true, 'message' => '已调整顺序']);
    }

    public function toggle(int $id)
    {
        $m = Db::name('audit_models')->where('id', $id)->find();
        if (!$m) {
            return json(['status' => false, 'message' => '模型不存在']);
        }
        Db::name('audit_models')->where('id', $id)->update([
            'status'      => (int)$m['status'] === 1 ? 2 : 1,
            'update_time' => time(),
        ]);
        return json(['status' => true, 'message' => '已切换状态']);
    }

    /** 全局审核驱动开关（none / llm_vlm / local_nsfw） */
    public function driverSave()
    {
        $driver = (string)$this->request->post('default_audit_driver', 'none');
        if (!in_array($driver, ['none', 'llm_vlm', 'local_nsfw'], true)) {
            return json(['status' => false, 'message' => '驱动不合法']);
        }
        Setting::saveKey('default_audit_driver', $driver);
        $mode = (string)$this->request->post('audit_mode', 'failover');
        Setting::saveKey('audit_mode', in_array($mode, ['failover', 'parallel'], true) ? $mode : 'failover');
        $nsfw = trim((string)$this->request->post('nsfw_endpoint', ''));
        if ($nsfw !== '') {
            Setting::saveKey('nsfw_endpoint', $nsfw);
        }
        return json(['status' => true, 'message' => '已保存']);
    }
}
