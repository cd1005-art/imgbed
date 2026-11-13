<?php
declare (strict_types = 1);

namespace app\common\service;

use think\facade\Db;

/**
 * 违规图片指纹：sha1 精确 + aHash 感知（8x8 灰度均值哈希）
 * 用途：上传前置拦截（防马甲重传）、下架图入库、近似重复检测
 */
class FingerprintService
{
    /** 计算 sha1 + ahash，返回 ['sha1'=>..,'ahash'=>..] */
    public static function compute(string $file): array
    {
        $sha1 = @sha1_file($file) ?: '';
        $ahash = self::ahash($file);
        return ['sha1' => $sha1, 'ahash' => $ahash];
    }

    /** 8x8 均值哈希（16 位十六进制） */
    public static function ahash(string $file): string
    {
        $info = @getimagesize($file);
        if (!$info) {
            return '';
        }
        $src = @imagecreatefromstring((string)@file_get_contents($file));
        if (!$src) {
            return '';
        }
        $w = imagesx($src);
        $h = imagesy($src);
        $small = imagecreatetruecolor(8, 8);
        imagecopyresampled($small, $src, 0, 0, 0, 0, 8, 8, $w, $h);

        $vals = [];
        $sum = 0;
        for ($y = 0; $y < 8; $y++) {
            for ($x = 0; $x < 8; $x++) {
                $rgb = imagecolorat($small, $x, $y);
                $v = (($rgb >> 16) & 0xFF) * 0.299 + (($rgb >> 8) & 0xFF) * 0.587 + ($rgb & 0xFF) * 0.114;
                $vals[] = $v;
                $sum += $v;
            }
        }
        $avg = $sum / 64;
        $bits = '';
        foreach ($vals as $v) {
            $bits .= $v >= $avg ? '1' : '0';
        }
        imagedestroy($small);
        imagedestroy($src);

        // 64bit → 16 hex
        $hex = '';
        for ($i = 0; $i < 64; $i += 4) {
            $hex .= dechex(bindec(substr($bits, $i, 4)));
        }
        return $hex;
    }

    /** 汉明距离 */
    public static function distance(string $a, string $b): int
    {
        if (strlen($a) !== 16 || strlen($b) !== 16) {
            return 64;
        }
        $d = 0;
        for ($i = 0; $i < 16; $i++) {
            $x = hexdec($a[$i]) ^ hexdec($b[$i]);
            $d += substr_count(decbin($x), '1');
        }
        return $d;
    }

    /**
     * 匹配违规库
     * @return array|null 命中的指纹记录
     */
    public static function match(string $file): ?array
    {
        if ((string)\app\common\model\Setting::get('banned_match_enforce', '1') !== '1') {
            return null;
        }
        $fp = self::compute($file);
        if ($fp['sha1'] !== '') {
            $hit = Db::name('banned_fingerprints')->where('sha1', $fp['sha1'])->find();
            if ($hit) {
                Db::name('banned_fingerprints')->where('id', $hit['id'])->inc('hit_count')->update();
                return $hit;
            }
        }
        if ($fp['ahash'] !== '') {
            $cands = Db::name('banned_fingerprints')->where('phash', '<>', '')->field('id,phash,reason,hit_count')->select()->toArray();
            foreach ($cands as $c) {
                if (self::distance($fp['ahash'], (string)$c['phash']) <= 8) {
                    Db::name('banned_fingerprints')->where('id', $c['id'])->inc('hit_count')->update();
                    return $c;
                }
            }
        }
        return null;
    }

    /** 入库（下架/删除违规图时调用，幂等） */
    public static function ban(int $imageId, string $reason, string $source = 'admin'): void
    {
        $img = Db::name('images')->where('id', $imageId)->find();
        if (!$img) {
            return;
        }
        $sha1 = (string)($img['sha1'] ?? '');
        $phash = '';
        // 本地文件可算 ahash
        try {
            $row = Db::name('storage_configs')->where('id', (int)$img['storage_id'])->find();
            $cfg = $row ? json_decode((string)$row['config'], true) : [];
            if ($row && $row['driver'] === 'local') {
                $rootRel = trim(str_replace('\\', '/', (string)($cfg['root'] ?? 'storage/images')), '/');
                $local = rtrim(public_path(), '/\\') . '/' . $rootRel . '/' . ltrim((string)$img['path'], '/');
                if (is_file($local)) {
                    $fp = self::compute($local);
                    $sha1 = $fp['sha1'] ?: $sha1;
                    $phash = $fp['ahash'];
                }
            }
        } catch (\Throwable $e) {
        }

        $exists = Db::name('banned_fingerprints')->where('image_id', $imageId)->find();
        if ($exists) {
            return;
        }
        Db::name('banned_fingerprints')->insert([
            'image_id'    => $imageId,
            'sha1'        => $sha1,
            'phash'       => $phash,
            'reason'      => mb_substr($reason, 0, 200),
            'source'      => $source,
            'hit_count'   => 0,
            'create_time' => time(),
        ]);
    }
}
