<?php
declare (strict_types = 1);

namespace app\common\service\storage;

use app\common\model\StorageConfig;

/**
 * 存储管理器：按 storage_configs 表实例化驱动（内部缓存，单请求单例）
 */
class StorageManager
{
    /** @var array<int, StorageDriverInterface> */
    protected static array $instances = [];

    /**
     * 获取存储驱动实例
     * @param int|null $storageId 存储实例ID，null 取默认（ID最小且启用）
     */
    public static function driver(?int $storageId = null): StorageDriverInterface
    {
        $config = $storageId
            ? StorageConfig::where('id', $storageId)->where('status', 1)->find()
            : StorageConfig::where('status', 1)->order('sort', 'asc')->order('id', 'asc')->find();

        if (!$config) {
            throw new \RuntimeException('无可用存储实例，请检查后台存储配置');
        }

        $key = (int)$config['id'];
        if (!isset(self::$instances[$key])) {
            // model 的 json cast 可能已把 config 转成数组，兼容两种形态
            $cfg   = is_array($config['config'])
                ? $config['config']
                : (json_decode((string)($config['config'] ?? ''), true) ?: []);
            $class = self::driverClass((string)$config['driver']);
            self::$instances[$key] = new $class($cfg, (int)$config['url_mode']);
        }

        return self::$instances[$key];
    }

    /** 按驱动类型映射实现类（新驱动在此注册） */
    protected static function driverClass(string $driver): string
    {
        return match ($driver) {
            'local'  => LocalStorage::class,
            'webdav' => WebdavStorage::class,
            // 's3'       => S3Storage::class,        // 二期
            // 'imagehost'=> ImagehostStorage::class, // 二期
            default  => throw new \RuntimeException('未知存储驱动类型: ' . $driver),
        };
    }
}
