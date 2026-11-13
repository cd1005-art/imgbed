<?php
declare (strict_types = 1);

namespace app\admin\controller;

use app\admin\controller\Base;
use app\common\service\NavService;

/** 导航栏管理：增删改 / 排序 / 显示条件 */
class NavManage extends Base
{
    public function index()
    {
        $links = NavService::all();
        usort($links, fn($a, $b) => $a['sort'] <=> $b['sort']);
        return $this->adminView('admin/nav', [
            'page'  => 'nav',
            'links' => $links,
            'pos_map'  => ['left' => '站名右侧', 'right' => '右侧导航'],
            'auth_map' => ['all' => '所有人', 'login' => '仅登录', 'guest' => '仅未登录'],
        ]);
    }

    /** 保存整组（前端用动态行提交 JSON） */
    public function save()
    {
        $raw = (string)$this->request->post('links_json', '');
        $list = json_decode($raw, true);
        if (!is_array($list)) {
            return json(['status' => false, 'message' => '数据格式错误']);
        }
        $clean = [];
        foreach ($list as $it) {
            $name = trim((string)($it['name'] ?? ''));
            $url  = trim((string)($it['url'] ?? ''));
            if ($name === '' || $url === '') {
                continue;
            }
            $clean[] = [
                'name'    => mb_substr($name, 0, 20),
                'url'     => mb_substr($url, 0, 300),
                'pos'     => ($it['pos'] ?? 'right') === 'left' ? 'left' : 'right',
                'auth'    => in_array(($it['auth'] ?? 'all'), ['all', 'login', 'guest'], true) ? $it['auth'] : 'all',
                'blank'   => (int)($it['blank'] ?? 0) === 1 ? 1 : 0,
                'sort'    => (int)($it['sort'] ?? 50),
                'enabled' => (int)($it['enabled'] ?? 1) === 1 ? 1 : 0,
            ];
        }
        NavService::save($clean);
        return json(['status' => true, 'message' => '已保存 ' . count($clean) . ' 个导航项']);
    }

    /** 恢复默认 */
    public function reset()
    {
        NavService::reset();
        return json(['status' => true, 'message' => '已恢复默认导航']);
    }
}
