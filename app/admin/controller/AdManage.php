<?php
declare (strict_types = 1);

namespace app\admin\controller;

use app\admin\controller\Base;
use app\common\model\AdSlot;
use app\common\model\Setting;
use app\common\model\StrategyGroup;
use think\facade\Db;

/**
 * 广告管理 + 站点设置（运营模式等）
 */
class AdManage extends Base
{
    /** 广告位管理 */
    public function index()
    {
        return $this->adminView('admin/ad', [
            'page'  => 'ad',
            'slots' => AdSlot::order('sort', 'asc')->select(),
        ]);
    }

    /** 站点设置 */
    public function site()
    {
        return $this->adminView('admin/site', [
            'page'     => 'site',
            'settings' => Setting::allSettings(),
            'groups'   => StrategyGroup::where('status', 1)->select(),
        ]);
    }

    /** 站点设置保存 */
    public function siteSave()
    {
        $allowed = ['site_name', 'operation_mode', 'allow_register', 'guest_group_id', 'register_group_id'];
        $all = $this->request->post();
        $saved = 0;
        foreach ($allowed as $key) {
            if (array_key_exists($key, $all)) {
                Setting::saveKey($key, (string)$all[$key]);
                $saved++;
            }
        }
        return json(['status' => true, 'message' => "已保存 {$saved} 项设置"]);
    }

    /** 审核设置 */
    public function audit()
    {
        return $this->adminView('admin/audit', [
            'page'     => 'audit',
            'settings' => Setting::allSettings(),
        ]);
    }

    /** 审核设置保存 */
    public function auditSave()
    {
        $allowed = ['default_audit_driver', 'llm_audit_endpoint', 'llm_audit_key', 'llm_audit_model'];
        $all = $this->request->post();
        $saved = 0;
        foreach ($allowed as $key) {
            if (array_key_exists($key, $all)) {
                Setting::saveKey($key, (string)$all[$key]);
                $saved++;
            }
        }
        return json(['status' => true, 'message' => "已保存 {$saved} 项审核设置"]);
    }

    /** 广告位保存 */
    public function saveSlot()
    {
        $id   = (int)$this->request->post('id', 0);
        $data = [
            'name'     => trim((string)$this->request->post('name', '')),
            'type'     => in_array($this->request->post('type'), ['html', 'image', 'js'], true)
                ? (string)$this->request->post('type') : 'html',
            'content'  => (string)$this->request->post('content', ''),
            'is_open'  => (int)$this->request->post('is_open', 0),
            'update_time' => time(),
        ];
        if ($data['name'] === '') {
            return json(['status' => false, 'message' => '广告位名必填']);
        }
        if ($id > 0) {
            Db::name('ad_slots')->where('id', $id)->update($data);
        } else {
            $data['slot_key'] = 'custom_' . bin2hex(random_bytes(3));
            $data['sort'] = 9;
            Db::name('ad_slots')->insert($data);
        }
        return json(['status' => true, 'message' => '已保存']);
    }

}
