<?php
declare (strict_types = 1);

namespace app\common\service\storage;

/**
 * 本地磁盘驱动
 *
 * config: { "root": "storage/images" }   相对 public/ 目录
 * 直链模式：/storage/images/{path}（nginx 直接静态服务，性能最好）
 * 代理模式：PHP 读文件输出（可叠加防盗链逻辑）
 */
class LocalStorage implements StorageDriverInterface
{
    protected string $root;
    protected int $urlMode;

    public function __construct(array $config, int $urlMode = 1)
    {
        // root 相对 public/ 目录；防止配置越权指向任意路径
        $root = trim((string)($config['root'] ?? 'storage/images'), '/\\');
        if (str_contains($root, '..')) {
            $root = 'storage/images';
        }
        $this->root    = public_path() . DIRECTORY_SEPARATOR . str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $root);
        $this->urlMode = $urlMode;
    }

    public function put(string $localPath, string $relativePath): bool
    {
        $target = $this->fullPath($relativePath);
        $dir    = dirname($target);
        if (!is_dir($dir) && !mkdir($dir, 0755, true)) {
            return false;
        }
        return (bool)copy($localPath, $target);
    }

    public function delete(string $relativePath): bool
    {
        $target = $this->fullPath($relativePath);
        return is_file($target) && (bool)unlink($target);
    }

    public function getContent(string $relativePath): ?string
    {
        $target = $this->fullPath($relativePath);
        if (!is_file($target)) {
            return null;
        }
        $content = file_get_contents($target);
        return $content === false ? null : $content;
    }

    public function getDirectUrl(string $relativePath): string
    {
        // root 的相对 URL 路径（即 /storage/images）
        $urlRoot = '/' . trim(str_replace([public_path(), DIRECTORY_SEPARATOR], ['', '/'], $this->root), '/');
        return rtrim($urlRoot, '/') . '/' . ltrim(str_replace('\\', '/', $relativePath), '/');
    }

    public function exists(string $relativePath): bool
    {
        return is_file($this->fullPath($relativePath));
    }

    public function probe(): array
    {
        $testDir = $this->root . '/__probe__';
        $ok      = is_dir($this->root) || mkdir($testDir, 0755, true);
        if ($ok) @rmdir($testDir);
        return ['ok' => (bool)$ok, 'msg' => $ok ? '本地目录可读写' : '目录不可写: ' . $this->root];
    }

    /** 绝对路径 + 路径穿越防护 */
    protected function fullPath(string $relativePath): string
    {
        $clean = str_replace(['..', "\0"], '', $relativePath);
        return $this->root . DIRECTORY_SEPARATOR . ltrim(str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $clean), '/\\');
    }
}
