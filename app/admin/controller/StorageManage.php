<?php
declare (strict_types = 1);

namespace app\admin\controller;

use app\admin\controller\Base;
use app\common\model\StorageConfig;
use app\common\service\storage\StorageManager;
use think\facade\Db;

/**
 * 存储实例管理：local / webdav 配置与连通性测试
 */
class StorageManage extends Base
{
    public function index()
    {
        return $this->adminView('admin/storage', [
            'page'  => 'storage',
            'list'  => StorageConfig::order('id', 'asc')->select(),
            'usage' => Db::name('images')->where('status', 1)->column('count(*) as c,sum(size) as s', 'storage_id'),
        ]);
    }

    /** 保存（新增/编辑）。webdav 的 config 字段由表单拆项组装 */
    public function save()
    {
        $id     = (int)$this->request->post('id', 0);
        $driver = (string)$this->request->post('driver', 'local');
        $name   = trim((string)$this->request->post('name', ''));

        if ($name === '') {
            return json(['status' => false, 'message' => '实例名必填']);
        }
        if (!in_array($driver, ['local', 'webdav'], true)) {
            return json(['status' => false, 'message' => '暂只支持 local / webdav（s3、imagehost 二期）']);
        }

        if ($driver === 'local') {
            $cfg = ['root' => trim((string)$this->request->post('root', 'storage/images'), '/\\')];
        } else {
            $cfg = [
                'base_url'   => rtrim(trim((string)$this->request->post('base_url', '')), '/'),
                'username'   => trim((string)$this->request->post('username', '')),
                'password'   => trim((string)$this->request->post('password', '')),
                'direct_url' => trim((string)$this->request->post('direct_url', '')),
            ];
        }

        $data = [
            'name'       => $name,
            'driver'     => $driver,
            'config'     => json_encode($cfg, JSON_UNESCAPED_SLASHES),
            'url_mode'   => (int)$this->request->post('url_mode', 1),
            'status'     => (int)$this->request->post('status', 1),
            'update_time'=> time(),
        ];

        if ($id > 0) {
            Db::name('storage_configs')->where('id', $id)->update($data);
        } else {
            $data['sort'] = 0;
            $data['create_time'] = time();
            Db::name('storage_configs')->insert($data);
        }
        return json(['status' => true, 'message' => '已保存']);
    }

    /** 连通性测试 */
    public function probe(int $id)
    {
        try {
            $result = StorageManager::driver($id)->probe();
        } catch (\Throwable $e) {
            $result = ['ok' => false, 'msg' => $e->getMessage()];
        }
        return json(['status' => true, 'data' => $result]);
    }

    /** 删除（有图片引用禁止） */
    public function delete(int $id)
    {
        $used = Db::name('images')->where('storage_id', $id)->where('status', 1)->count();
        if ($used > 0) {
            return json(['status' => false, 'message' => "有 {$used} 张图片在该存储上，禁止删除"]);
        }
        Db::name('storage_configs')->where('id', $id)->delete();
        return json(['status' => true, 'message' => '已删除']);
    }
}
