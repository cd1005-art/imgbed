<?php
declare(strict_types=1);

namespace app\common\service;

use app\common\model\Setting;

/**
 * 图片处理：压缩 / WebP 转换 / 水印 / 缩略图 / EXIF 清理
 *
 * 只处理 GD 支持且无动画的格式（jpg/png/webp/bmp）；GIF 原样返回（保动画）。
 * 处理后返回新的临时文件路径，由调用方负责上传与清理。
 */
class ImageProcessor
{
    /** 可处理扩展名 */
    private const HANDLE = ['jpg', 'jpeg', 'png', 'webp', 'bmp'];

    /**
     * @return array{
     *   path:string|null, ext:string, width:int, height:int,
     *   thumb:string|null, bytes:int
     * }
     */
    public static function process(string $srcPath, string $ext): array
    {
        $ext  = strtolower($ext);
        $info = @getimagesize($srcPath) ?: [0, 0];
        $out  = [
            'path'   => null,
            'ext'    => $ext,
            'width'  => (int)$info[0],
            'height' => (int)$info[1],
            'thumb'  => null,
            'bytes'  => (int)@filesize($srcPath),
        ];

        $compress = (int)Setting::get('img_compress', 82);     // 0=不压缩
        $toWebp   = (int)Setting::get('img_webp', 0) === 1;
        $wmText   = trim((string)Setting::get('img_watermark', ''));
        $wmPos    = (string)Setting::get('img_watermark_pos', 'br');
        $wmOpacity= max(0, min(100, (int)Setting::get('img_watermark_opacity', 60)));
        $thumbMax = (int)Setting::get('img_thumb_size', 480);  // 0=不生成
        $cleanExif= (int)Setting::get('img_exif_clean', 1) === 1;

        // 无需处理：GIF（保动画）、全部开关关闭
        $needProcess = in_array($ext, self::HANDLE, true)
            && ($compress > 0 || $toWebp || $wmText !== '' || $thumbMax > 0 || $cleanExif);
        if (!$needProcess) {
            return $out;
        }

        @ini_set('memory_limit', '512M');

        $img = self::load($srcPath, $ext);
        if (!$img) {
            return $out;                 // 加载失败（内存/格式异常）→ 原图直通
        }

        // 1) 水印（字体不可用等异常不影响后续处理）
        if ($wmText !== '') {
            try {
                self::watermark($img, $wmText, $wmPos, $wmOpacity);
            } catch (\Throwable $e) {
                \think\facade\Log::record('水印添加失败：' . $e->getMessage(), 'error');
            }
        }

        // 2) 缩略图（在转换格式之前基于原始尺寸生成）
        if ($thumbMax > 0) {
            $thumbTmp = tempnam(sys_get_temp_dir(), 'thumb_');
            $tw = imagesx($img);
            $th = imagesy($img);
            if (max($tw, $th) > $thumbMax) {
                $thumb = imagescale($img, $tw >= $th ? $thumbMax : -1, $tw >= $th ? -1 : $thumbMax, IMG_BICUBIC);
            } else {
                $thumb = imagescale($img, $tw, $th);
            }
            if ($thumb) {
                imagewebp($thumb, $thumbTmp, 80);
                imagedestroy($thumb);
                $out['thumb'] = $thumbTmp;
            }
            if (file_exists($thumbTmp) && filesize($thumbTmp) === 0) {
                @unlink($thumbTmp);
                $out['thumb'] = null;
            }
        }

        // 3) 重编码（压缩 + 转格式 + EXIF 清除；GD 重编码天然不保留 EXIF）
        $targetExt  = $toWebp ? 'webp' : ($ext === 'jpeg' ? 'jpg' : $ext);
        $tmpOut     = tempnam(sys_get_temp_dir(), 'img_');
        $quality    = $compress > 0 ? $compress : 85;
        $ok         = false;

        switch ($targetExt) {
            case 'webp':
                $ok = imagewebp($img, $tmpOut, $quality);
                break;
            case 'jpg':
                $ok = imagejpeg($img, $tmpOut, $quality);
                break;
            case 'png':
                // PNG 用压缩级别 0-9（quality 越大压缩越强，体积越小）
                $level = (int)round(min(9, max(0, ($quality / 100) * 9)));
                $ok = imagepng($img, $tmpOut, $level);
                break;
            case 'bmp':
                // GD 无 bmp 写出 → 统一转 png，避免体积暴涨
                $targetExt = 'png';
                $ok = imagepng($img, $tmpOut, 6);
                break;
            default:
                $ok = false;
        }

        imagedestroy($img);

        if (!$ok || !is_file($tmpOut) || filesize($tmpOut) < 128) {
            @unlink($tmpOut);
            return $out;                 // 编码失败 → 原图直通
        }

        // 4) 体积反而变大则回退原图（PNG 转 WebP 偶尔会变大）
        if (filesize($tmpOut) >= $out['bytes'] && !$toWebp) {
            @unlink($tmpOut);
            if ($out['thumb']) {
                // 缩略图仍可用
            }
            return $out;
        }

        $out['path']  = $tmpOut;
        $out['ext']   = $targetExt;
        $out['bytes'] = (int)filesize($tmpOut);
        [$w, $h]      = @getimagesize($tmpOut) ?: [$out['width'], $out['height']];
        $out['width']  = (int)$w;
        $out['height'] = (int)$h;

        return $out;
    }

    /** 按扩展名加载为 GD 资源 */
    private static function load(string $path, string $ext)
    {
        try {
            $img = match ($ext) {
                'jpg', 'jpeg' => @imagecreatefromjpeg($path),
                'png'         => @imagecreatefrompng($path),
                'webp'        => @imagecreatefromwebp($path),
                'bmp'         => @imagecreatefrombmp($path),
                default       => false,
            };
            if (!$img) {
                return false;
            }
            // 处理带透明通道的图（PNG/WebP）
            if ($ext === 'png' || $ext === 'webp') {
                imagealphablending($img, false);
                imagesavealpha($img, true);
            }
            return $img;
        } catch (\Throwable $e) {
            return false;
        }
    }

    /** 文字水印（右下/左下/右上/左上） */
    private static function watermark($img, string $text, string $pos, int $opacity): void
    {
        $w = imagesx($img);
        $h = imagesy($img);

        // 字号随图片宽度自适应（最小 14，最大 40）
        $fontSize = max(14, min(40, (int)($w / 40)));
        $font     = self::fontFile();
        $pad      = max(10, (int)($w * 0.02));

        if ($font !== '') {
            $box = @imagettfbbox($fontSize, 0, $font, $text);
            $tw  = $box ? abs($box[2] - $box[0]) : 0;
            $th  = $box ? abs($box[7] - $box[1]) : 0;
            if ($tw <= 0 || $th <= 0) {
                $font = '';                // 字体不可用 → 退化到位图字体
            }
        }

        if ($font === '') {
            $fontSize = 5;                 // GD 内置字体尺寸 1-5
            $tw = imagefontwidth($fontSize) * strlen($text) * 2;
            $th = imagefontheight($fontSize) * 2;
        }

        $x = match ($pos) {
            'bl', 'tl' => $pad,
            default    => max($pad, $w - $tw - $pad),
        };
        $y = match ($pos) {
            'tl', 'tr' => $pad + $th,
            default    => max($pad + $th, $h - $pad),
        };

        $alpha = (int)round(127 * (1 - $opacity / 100));
        $color = imagecolorallocatealpha($img, 255, 255, 255, $alpha);

        if ($font !== '') {
            @imagettftext($img, $fontSize, 0, $x, $y, $color, $font, $text);
        } else {
            @imagestring($img, $fontSize, $x, $y - $th, $text, $color);
        }
    }

    /** 找中文字体（Linux 优先用 fc-match 动态定位，Windows 走固定路径） */
    private static function fontFile(): string
    {
        static $cached = null;
        if ($cached !== null) {
            return $cached;
        }

        // 0) 后台指定的水印字体（storage/fonts/ 下）
        $picked = trim((string)\app\common\model\Setting::get('img_watermark_font', ''));
        if ($picked !== '') {
            $root = function_exists('root_path') ? root_path() : dirname(__DIR__, 3) . DIRECTORY_SEPARATOR;
            $f = $root . 'storage/fonts/' . basename($picked);
            if (@is_file($f)) {
                return $cached = $f;
            }
        }

        // 1) Linux：fc-match 自动找可用中文字体（兼容 CentOS/Debian/Ubuntu 各种路径）
        if (function_exists('exec') && DIRECTORY_SEPARATOR === '/') {
            foreach (['Noto Sans CJK SC', 'WenQuanYi Zen Hei', 'Source Han Sans SC', 'DejaVu Sans'] as $name) {
                $out = [];
                @exec('fc-match -f "%{file}" ' . escapeshellarg($name) . ' 2>/dev/null', $out);
                $f = trim((string)($out[0] ?? ''));
                if ($f !== '' && @is_file($f) && preg_match('/\.(ttf|ttc|otf)$/i', $f)) {
                    return $cached = $f;
                }
            }
        }

        // 2) 项目内字体（open_basedir 安全区，运维放 storage/fonts/ 即可）
        $root = function_exists('root_path') ? root_path() : dirname(__DIR__, 3) . DIRECTORY_SEPARATOR;
        foreach (glob($root . 'storage/fonts/*.{ttf,ttc,otf}', GLOB_BRACE) ?: [] as $f) {
            if (@is_file($f)) {
                return $cached = $f;
            }
        }

        // 3) 固定路径兜底
        $candidates = [
            'C:/Windows/Fonts/msyh.ttc',
            'C:/Windows/Fonts/msyhbd.ttc',
            'C:/Windows/Fonts/simhei.ttf',
            '/usr/share/fonts/google-noto-cjk/NotoSansCJK-Medium.ttc',
            '/usr/share/fonts/google-noto-cjk/NotoSansCJK-Bold.ttc',
            '/usr/share/fonts/google-noto-cjk/NotoSansCJK-Regular.ttc',
            '/usr/share/fonts/truetype/wqy/wqy-zenhei.ttc',
            '/usr/share/fonts/opentype/noto/NotoSansCJK-Regular.ttc',
        ];
        foreach ($candidates as $f) {
            if (@is_file($f)) {
                return $cached = $f;
            }
        }
        return $cached = '';
    }
}
