<?php
declare (strict_types = 1);

namespace app\common\service;

use app\common\exception\UploadException;
use app\common\model\Image;
use app\common\model\Setting;
use app\common\model\StorageConfig;
use app\common\model\StrategyGroup;
use app\common\model\UploadLog;
use app\common\model\User;
use app\common\service\AuditService;
use app\common\service\storage\StorageManager;
use think\facade\Db;
use think\file\UploadedFile;

/**
 * 上传核心服务
 *
 * 流程：文件校验 → 策略组限额 → SHA1 秒传复用 → 落盘存储 → 入库/日志/配额
 * 抛 UploadException 时已保证写 upload_logs（result=0），控制器直接转 JSON。
 */
class UploadService
{
    /**
     * 上传入口
     *
     * @param UploadedFile  $file   上传文件对象
     * @param int           $userId 用户ID（0=游客）
     * @param StrategyGroup $group  策略组
     * @param string        $ip     客户端IP
     * @param string        $ua     客户端UA
     * @return array{image:Image, url:string, direct_url:string, reused:bool}
     * @throws UploadException
     */
    public function upload(UploadedFile $file, int $userId, StrategyGroup $group, string $ip, string $ua): array
    {
        $now = time();

        try {
            $result = $this->process($file, $userId, $group, $ip, $ua, $now);
        } catch (UploadException $e) {
            $this->log($userId, $ip, $ua, 0, 0, $e->getMessage(), $now);
            throw $e;
        }

        $this->log($userId, $ip, $ua, 1, (int)$result['image']->id, '', $now);
        return $result;
    }

    /** 主流程（异常统一由 upload 包装记日志） */
    protected function process(UploadedFile $file, int $userId, StrategyGroup $group, string $ip, string $ua, int $now): array
    {
        // 违规指纹前置匹配（sha1 精确 + ahash 近似）
        try {
            $hit = FingerprintService::match($file->getPathname());
            if ($hit) {
                if ($userId > 0) {
                    Db::name('users')->where('id', $userId)->inc('violation_count')->update();
                    $u = Db::name('users')->where('id', $userId)->find();
                    $limit = max(1, (int)Setting::get('violation_autoban', 3));
                    if ($u && (int)$u['violation_count'] >= $limit && (int)$u['status'] === 1) {
                        Db::name('users')->where('id', $userId)->update(['status' => 2, 'update_time' => time()]);
                    }
                }
                throw new UploadException('该图片与违规样本库匹配，禁止上传');
            }
        } catch (UploadException $e) {
            throw $e;
        } catch (\Throwable $e) {
        }

        // 邮箱验证（lazy 模式：注册可登录，上传前必须验证）
        if ($userId > 0 && (string)Setting::get('verify_mode', 'none') === 'lazy') {
            $u = Db::name('users')->where('id', $userId)->find();
            if ($u && (int)($u['email_verified'] ?? 1) !== 1) {
                throw new UploadException('请先验证邮箱后再上传（个人中心可重发验证邮件）');
            }
        }
        // ---------- 1. 基础校验 ----------
        if (!$file->isValid()) {
            throw new UploadException('上传文件无效或已超过 PHP 限制');
        }

        $ext = strtolower($file->getOriginalExtension());
        if (!in_array($ext, $group->allowedExt(), true)) {
            throw new UploadException('不允许的文件类型: .' . $ext);
        }

        $size = (int)$file->getSize();
        if ($group->max_size > 0 && $size > $group->max_size) {
            throw new UploadException('文件超过单图上限 ' . $this->formatSize($group->max_size));
        }

        // 真图片校验（防伪装扩展名）
        $info = @getimagesize($file->getPathname());
        if ($info === false) {
            throw new UploadException('文件不是有效图片');
        }
        $width  = (int)$info[0];
        $height = (int)$info[1];
        $mime   = (string)($info['mime'] ?? '');

        // ---------- 2. 配额与频率 ----------
        $this->checkQuota($userId, $group, $size, $now, $ip);

        // ---------- 3. SHA1 秒传复用（同用户同文件直接返回） ----------
        $sha1 = sha1_file($file->getPathname());
        if ($sha1 === false) {
            throw new UploadException('文件读取失败');
        }
        $md5 = md5_file($file->getPathname());

        $exist = Image::where('user_id', $userId)
            ->where('sha1', $sha1)
            ->where('size', $size)
            ->where('status', Image::ST_NORMAL)
            ->find();
        if ($exist) {
            return $this->buildResult($exist, true);
        }

        // ---------- 4. 生成存储路径并落盘 ----------
        $relativePath = date('Y/m/d/', $now) . $this->randomName() . '.' . $ext;

        try {
            // 存储停止条件：
            //  1) 配额阈值（quota_gb>0 且 图床已用 >= 阈值）→ 停
            //  2) 网盘空间（space_gb>0 且 已用 >= 空间）→ 停
            //  3) 本地磁盘真实剩余不足 → 停
            $storageRow = Db::name('storage_configs')->where('id', (int)$group->storage_id)->find();
            if ($storageRow) {
                $storageCfg = json_decode((string)$storageRow['config'], true) ?: [];
                $usedB = (int)Db::name('images')
                    ->where('storage_id', (int)$group->storage_id)
                    ->where('status', 1)
                    ->sum('size');

                $quotaGb = (int)($storageCfg['quota_gb'] ?? 0);
                if ($quotaGb > 0 && $usedB >= $quotaGb * 1073741824) {
                    throw new UploadException('存储配额已满，暂停上传（管理员可调整配额上限）');
                }

                $spaceGb = (int)($storageCfg['space_gb'] ?? 0);
                if ($spaceGb > 0 && $usedB >= $spaceGb * 1073741824) {
                    throw new UploadException('网盘空间已满，暂停上传（管理员可调整网盘空间）');
                }

                if ($storageRow['driver'] === 'local') {
                    $rootRel = trim(str_replace('\\', '/', (string)($storageCfg['root'] ?? 'storage/images')), '/');
                    $localRoot = rtrim(public_path(), '/\\') . '/' . $rootRel;
                    $free = @disk_free_space(is_dir($localRoot) ? $localRoot : public_path());
                    if ($free !== false && $free < (int)$file->getSize() * 2) {
                        throw new UploadException('服务器磁盘空间不足，暂停上传');
                    }
                }
            }

            $driver = StorageManager::driver((int)$group->storage_id);
        } catch (\RuntimeException $e) {
            throw new UploadException($e->getMessage());
        }

        if (!$driver->put($file->getPathname(), $relativePath)) {
            throw new UploadException('存储写入失败，请检查存储配置');
        }

        // ---------- 5. 入库 + 配额累加 ----------
        $image = Image::create([
            'user_id'      => $userId,
            'group_id'     => $group->id,
            'name'         => mb_substr($file->getOriginalName(), 0, 200),
            'path'         => $relativePath,
            'sha1'         => $sha1,
            'md5'          => $md5,
            'size'         => $size,
            'width'        => (int)$width,
            'height'       => (int)$height,
            'mime'         => (string)$mime,
            'ext'          => $ext,
            'storage_id'   => (int)$group->storage_id,
            'audit_status' => Image::AUDIT_PENDING,
            'status'       => Image::ST_NORMAL,
            'ip'           => $ip,
            'ua'           => mb_substr($ua, 0, 250),
            'expire_time'  => $group->keep_days > 0 ? $now + $group->keep_days * 86400 : 0,
            'create_time'  => $now,
            'update_time'  => $now,
        ]);

        if ($userId > 0) {
            Db::name('users')->where('id', $userId)->inc('quota_used', $size)->update();
        }

        // ---------- 6. 机审（同步一期，none 驱动零开销；二期异步队列） ----------
        AuditService::audit($image, $file->getPathname(), (string)$mime);

        return $this->buildResult($image, false);
    }

    /** 频率与容量限额（含游客与注册用户） */
    protected function checkQuota(int $userId, StrategyGroup $group, int $size, int $now, string $ip): void
    {
        $dayStart = strtotime(date('Y-m-d', $now));

        // 日上传次数
        if ($group->daily_count > 0) {
            $q = Db::name('upload_logs')->where('result', 1)->where('create_time', '>=', $dayStart);
            $q = $userId > 0 ? $q->where('user_id', $userId) : $q->where('ip', $ip);
            $count = $q->count();
            if ($count >= $group->daily_count) {
                throw new UploadException('已达今日上传次数上限（' . $group->daily_count . ' 次）');
            }
        }

        // 总容量（注册/VIP 用户有 quota_used；游客按当日累计）
        if ($group->quota_total > 0) {
            if ($userId > 0) {
                $used = (int)(User::findUser($userId)->quota_used ?? 0);
            } else {
                $used = (int)Db::name('images')
                    ->where('user_id', 0)->where('ip', $ip)
                    ->where('create_time', '>=', $dayStart)
                    ->where('status', '>', 0)
                    ->sum('size');
            }
            if ($used + $size > $group->quota_total) {
                throw new UploadException('容量配额不足（剩余 ' . $this->formatSize(max(0, $group->quota_total - $used)) . '）');
            }
        }
    }

    /** 审核初始状态：全局/组驱动为 none 或空 → 直接通过；否则待审 */
    protected function initialAuditStatus(StrategyGroup $group): int
    {
        $driver = $group->audit_driver !== '' ? $group->audit_driver : (string)Setting::get('default_audit_driver', 'none');
        return ($driver === '' || $driver === 'none') ? Image::AUDIT_PASS : Image::AUDIT_PENDING;
    }

    /** 组装返回结果（外链模式判定在这里收口） */
    protected function buildResult(Image $image, bool $reused): array
    {
        $storage = StorageConfig::find((int)$image->storage_id);
        $urlMode = (int)($storage['url_mode'] ?? 1);

        $directUrl = '';
        if ($urlMode === 2) {
            try {
                $directUrl = StorageManager::driver((int)$image->storage_id)->getDirectUrl($image->path);
            } catch (\Throwable $e) {
                $directUrl = '';
            }
        }

        // 代理模式走本站外链路由 /i/{path}
        $url = $directUrl !== '' ? $directUrl : '/i/' . $image->path;

        return ['image' => $image, 'url' => $url, 'direct_url' => $directUrl, 'reused' => $reused];
    }

    /** 随机文件名（16 hex，避免可枚举） */
    protected function randomName(): string
    {
        return bin2hex(random_bytes(8));
    }

    /** 上传日志 */
    protected function log(int $userId, string $ip, string $ua, int $result, int $imageId, string $reason, int $now): void
    {
        try {
            UploadLog::create([
                'user_id'     => $userId,
                'ip'          => $ip,
                'ua'          => mb_substr($ua, 0, 250),
                'image_id'    => $imageId,
                'result'      => $result,
                'reason'      => mb_substr($reason, 0, 250),
                'create_time' => $now,
            ]);
        } catch (\Throwable $e) {
            // 日志失败不阻断主流程
        }
    }

    /** 字节转可读 */
    public static function formatSize(int $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $i     = 0;
        $v     = (float)$bytes;
        while ($v >= 1024 && $i < count($units) - 1) {
            $v /= 1024;
            $i++;
        }
        return round($v, 2) . ' ' . $units[$i];
    }
}
