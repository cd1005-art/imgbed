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
        $usage = Db::name('images')->where('status', 1)
            ->field('storage_id, count(*) as c, sum(size) as s')
            ->group('storage_id')
            ->select()->toArray();
        $usageMap = [];
        foreach ($usage as $u) {
            $usageMap[(int)$u['storage_id']] = ['c' => (int)$u['c'], 's' => (int)$u['s']];
        }

        $list = StorageConfig::order('id', 'asc')->select();
        foreach ($list as $item) {
            $cfg = is_array($item->config) ? $item->config : [];
            $usedBytes = $usageMap[(int)$item->id]['s'] ?? 0;
            $item['used_str'] = \app\common\service\UploadService::formatSize($usedBytes);
            $item['disk_free_str'] = '';
            $item['disk_total_str'] = '';

            // 配额阈值（local/webdav 通用）：用于停止上传与「配额剩余」展示
            $quotaGb = (int)($cfg['quota_gb'] ?? 0);
            $item['quota_set'] = $quotaGb > 0;
            if ($quotaGb > 0) {
                $qTotalB = $quotaGb * 1073741824;
                $item['quota_total_str'] = \app\common\service\UploadService::formatSize($qTotalB);
                $item['quota_free_str']  = \app\common\service\UploadService::formatSize(max(0, $qTotalB - $usedBytes));
                $item['quota_pct'] = $qTotalB > 0 ? (int)round($usedBytes / $qTotalB * 100) : 0;
            }

            $item['space_unlimited'] = false;
            if ($item->driver === 'local') {
                // 本地磁盘：实时读取剩余/总量
                $rootRel = trim(str_replace('\\', '/', (string)($cfg['root'] ?? 'storage/images')), '/');
                $root = rtrim(public_path(), '/\\') . '/' . $rootRel;
                $probeDir = is_dir($root) ? $root : public_path();
                $free = @disk_free_space($probeDir);
                $total = @disk_total_space($probeDir);
                if ($free !== false && $total !== false) {
                    $item['disk_free_str'] = \app\common\service\UploadService::formatSize((int)$free);
                    $item['disk_total_str'] = \app\common\service\UploadService::formatSize((int)$total);
                    $item['disk_pct'] = $total > 0 ? (int)round(($total - $free) / $total * 100) : 0;
                }
            } else {
                // 网盘：按手工「网盘空间」（GB）展示剩余；0 = 不限
                $spaceGb = (int)($cfg['space_gb'] ?? 0);
                if ($spaceGb > 0) {
                    $sTotalB = $spaceGb * 1073741824;
                    $item['disk_free_str'] = \app\common\service\UploadService::formatSize(max(0, $sTotalB - $usedBytes));
                    $item['disk_total_str'] = \app\common\service\UploadService::formatSize($sTotalB);
                    $item['disk_pct'] = $sTotalB > 0 ? (int)round($usedBytes / $sTotalB * 100) : 0;
                } else {
                    $item['space_unlimited'] = true;
                }
            }
        }

        return $this->adminView('admin/storage', [
            'page'  => 'storage',
            'list'  => $list,
            'usage' => $usageMap,
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

        // 新增时防重复：同名同驱动的实例已存在则转为更新（连点/误点保护）
        if ($id === 0) {
            $dupId = Db::name('storage_configs')->where('name', $name)->where('driver', $driver)->value('id');
            if ($dupId) {
                $id = (int)$dupId;
            }
        }

        if ($driver === 'local') {
            $cfg = [
                'root'     => trim((string)$this->request->post('root', 'storage/images'), '/\\'),
                'quota_gb' => max(0, (int)$this->request->post('quota_gb', 0)),
            ];
        } else {
            $cfg = [
                'base_url'   => rtrim(trim((string)$this->request->post('base_url', '')), '/'),
                'username'   => trim((string)$this->request->post('username', '')),
                'password'   => trim((string)$this->request->post('password', '')),
                'direct_url' => trim((string)$this->request->post('direct_url', '')),
                // 手工配额上限（GB），0 = 不限；网盘无法自动读取剩余，按图床已用/上限估算
                'quota_gb'   => max(0, (int)$this->request->post('quota_gb', 0)),
                // 网盘空间（GB），0 = 不限；用于展示「网盘剩余」
                'space_gb'   => max(0, (int)$this->request->post('space_gb', 0)),
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
