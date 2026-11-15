<?php
declare(strict_types=1);

namespace app\common\service;

use app\common\model\Image;
use app\common\model\Setting;
use think\facade\Db;

/**
 * 白名单指纹库
 *
 * 人工复审通过的图片会写入这里（SHA1 精确 + ahash 近似），
 * 后续相同/相似图上传时直接判定通过，不再调用模型，省 token 也省时间。
 */
class ApprovedFingerprintService
{
    /** 命中白名单则返回记录，否则 null */
    public static function match(string $file): ?array
    {
        if ((int)Setting::get('whitelist_on', 1) !== 1) {
            return null;
        }
        if (!is_file($file)) {
            return null;
        }

        // 1) SHA1 精确命中
        $sha1 = @sha1_file($file);
        if ($sha1 !== false && $sha1 !== '') {
            $hit = Db::name('approved_fingerprints')->where('sha1', $sha1)->find();
            if ($hit) {
                self::bump((int)$hit['id']);
                return $hit;
            }
        }

        // 2) ahash 近似命中（阈值可配，默认 4）
        $maxD = (int)Setting::get('whitelist_distance', 4);
        if ($maxD <= 0) {
            return null;
        }
        $ah = FingerprintService::ahash($file);
        if ($ah === '') {
            return null;
        }
        $rows = Db::name('approved_fingerprints')->where('ahash', '<>', '')
            ->field('id,ahash')->order('id', 'desc')->limit(500)->select()->toArray();
        foreach ($rows as $row) {
            if (FingerprintService::distance($ah, (string)$row['ahash']) <= $maxD) {
                self::bump((int)$row['id']);
                return $row;
            }
        }
        return null;
    }

    /** 把一张图写入白名单（批量通过时调用） */
    public static function add(Image $image, string $note = ''): bool
    {
        if ((int)Setting::get('whitelist_on', 1) !== 1) {
            return false;
        }
        $sha1  = trim((string)($image->sha1 ?? ''));
        $ahash = '';
        $file  = self::localFileOf($image);
        $tmp   = '';
        if ($file === '') {
            // 网盘图：下载到临时文件才能算 ahash（否则只能靠 sha1，相似图无法命中）
            try {
                $content = \app\common\service\storage\StorageManager::driver((int)$image->storage_id)
                    ->getContent((string)$image->path);
                if ($content !== null && $content !== '') {
                    $tmp = (string)tempnam(sys_get_temp_dir(), 'wl_');
                    file_put_contents($tmp, $content);
                    $file = $tmp;
                }
            } catch (\Throwable $e) {
            }
        }
        if ($file !== '') {
            if ($sha1 === '') {
                $sha1 = (string)@sha1_file($file);
            }
            $ahash = FingerprintService::ahash($file);
        }
        if ($tmp !== '' && is_file($tmp)) {
            @unlink($tmp);
        }
        if ($sha1 === '' && $ahash === '') {
            return false;
        }
        if ($sha1 !== '' && Db::name('approved_fingerprints')->where('sha1', $sha1)->find()) {
            return false;
        }
        // 连同原图标签一起记下来，命中后新图也能自动带标签
        $tags = [];
        try {
            $tags = Db::name('image_tags')->alias('it')
                ->join('tags t', 't.id = it.tag_id')
                ->where('it.image_id', (int)$image->id)
                ->column('t.name');
        } catch (\Throwable $e) {
        }

        Db::name('approved_fingerprints')->insert([
            'image_id'    => (int)$image->id,
            'sha1'        => $sha1,
            'ahash'       => $ahash,
            'note'        => mb_substr($note !== '' ? $note : (string)$image->name, 0, 60),
            'tags'        => mb_substr(json_encode(array_values(array_filter((array)$tags)), JSON_UNESCAPED_UNICODE), 0, 240),
            'create_time' => time(),
        ]);
        return true;
    }

    public static function remove(int $id): bool
    {
        return Db::name('approved_fingerprints')->where('id', $id)->delete() > 0;
    }

    public static function clear(): int
    {
        return Db::name('approved_fingerprints')->where('id', '>', 0)->delete();
    }

    private static function bump(int $id): void
    {
        try {
            Db::name('approved_fingerprints')->where('id', $id)->inc('hit_count')->update();
        } catch (\Throwable $e) {
        }
    }

    /** 优先本地副本，其次本地存储；网盘图不下载，只用 sha1 */
    private static function localFileOf(Image $image): string
    {
        $path = (string)$image->path;
        $link = public_path() . 'i' . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $path);
        if (is_file($link)) {
            return $link;
        }
        $cfg = Db::name('storage_configs')->where('id', (int)$image->storage_id)->find();
        if ($cfg && ($cfg['driver'] ?? '') === 'local') {
            $conf   = json_decode((string)$cfg['config'], true) ?: [];
            $rootRel = trim(str_replace('\\', '/', (string)($conf['root'] ?? 'storage/images')), '/');
            $abs = rtrim(public_path(), '/\\') . '/' . $rootRel . '/' . ltrim($path, '/');
            if (is_file($abs)) {
                return $abs;
            }
        }
        return '';
    }
}
