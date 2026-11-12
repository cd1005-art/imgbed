<?php
declare (strict_types = 1);

namespace app\common\service\storage;

/**
 * 存储驱动统一接口
 *
 * 所有存储后端（local / webdav / s3 / imagehost）实现本接口。
 * url_mode：1 代理中转（流量走图床，防盗链有效） / 2 直链302（流量走后端，省带宽）
 */
interface StorageDriverInterface
{
    /**
     * @param array $config  驱动配置（storage_configs.config JSON 解出）
     * @param int   $urlMode 外链模式 1代理 2直链
     */
    public function __construct(array $config, int $urlMode = 1);

    /** 写入文件：$localPath 本地临时文件，$relativePath 存储相对路径。成功返回 true */
    public function put(string $localPath, string $relativePath): bool;

    /** 删除文件 */
    public function delete(string $relativePath): bool;

    /** 读取文件内容（代理模式输出用），不存在返回 null */
    public function getContent(string $relativePath): ?string;

    /** 直链地址（302 模式用），不支持直链的驱动返回空串 */
    public function getDirectUrl(string $relativePath): string;

    /** 文件是否存在 */
    public function exists(string $relativePath): bool;

    /** 连通性测试，返回 ['ok' => bool, 'msg' => string] */
    public function probe(): array;

    /**
     * 列出存储根下全部文件（用于孤立文件扫描）
     * @return array<int, array{path: string, mtime: int}>
     */
    public function listFiles(): array;
}
