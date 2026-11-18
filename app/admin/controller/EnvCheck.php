<?php
declare (strict_types = 1);

namespace app\admin\controller;

use think\facade\Db;

/**
 * 环境自检：只读诊断，不修改任何配置
 */
class EnvCheck extends Base
{
    /** 把 128M / 1G 这类写法折算成字节 */
    private function toBytes(string $v): int
    {
        $v = trim($v);
        if ($v === '' || $v === '-1') {
            return -1;
        }
        $unit = strtolower(substr($v, -1));
        $num  = (int)$v;
        switch ($unit) {
            case 'g': return $num * 1073741824;
            case 'm': return $num * 1048576;
            case 'k': return $num * 1024;
            default:  return (int)$v;
        }
    }

    private function fmtBytes(int $b): string
    {
        if ($b < 0) {
            return '不限';
        }
        if ($b >= 1073741824) {
            return round($b / 1073741824, 2) . ' GB';
        }
        if ($b >= 1048576) {
            return round($b / 1048576, 1) . ' MB';
        }
        if ($b >= 1024) {
            return round($b / 1024, 1) . ' KB';
        }
        return $b . ' B';
    }

    public function index()
    {
        // ---------- 基础信息 ----------
        $base = [
            ['PHP 版本', PHP_VERSION, version_compare(PHP_VERSION, '8.1.0', '>=') ? 'ok' : 'fail', '需 ≥ 8.1'],
            ['运行方式', PHP_SAPI, PHP_SAPI === 'fpm-fcgi' || PHP_SAPI === 'apache2handler' ? 'ok' : 'warn', 'FPM 或 Apache 模块'],
            ['服务器时间', date('Y-m-d H:i:s'), 'ok', ''],
            ['时区', (string)ini_get('date.timezone'), (string)ini_get('date.timezone') !== '' ? 'ok' : 'warn', '建议 Asia/Shanghai 或 PRC'],
            ['框架版本', 'ThinkPHP ' . \think\facade\App::version(), 'ok', ''],
        ];

        // ---------- 扩展 ----------
        $extMap = [
            'gd'        => ['图片处理', '必须', true],
            'mbstring'  => ['中文处理', '必须', true],
            'curl'      => ['网盘/审核 API', '必须', true],
            'pdo_mysql' => ['数据库', '必须', true],
            'fileinfo'  => ['MIME 检测', '必须', true],
            'openssl'   => ['HTTPS/SMTP', '必须', true],
            'json'      => ['JSON', '必须', true],
            'session'   => ['登录态', '必须', true],
            'iconv'     => ['编码转换', '建议', false],
            'zlib'      => ['压缩传输', '建议', false],
            'ctype'     => ['字符串判断', '建议', false],
            'filter'    => ['输入过滤', '建议', false],
            'hash'      => ['密码/签名', '必须', true],
            'Zend OPcache' => ['脚本缓存', '建议', false],
        ];
        $exts = [];
        foreach ($extMap as $name => $meta) {
            $loaded = extension_loaded($name);
            $exts[] = [
                'name'   => $name,
                'use'    => $meta[0],
                'level'  => $meta[1],
                'state'  => $loaded ? 'ok' : ($meta[2] ? 'fail' : 'warn'),
                'text'   => $loaded ? '已加载' : '未加载',
            ];
        }

        // ---------- GD 能力 ----------
        $gdFns = [
            'imagecreatefromjpeg' => ['JPG 解码', '--with-jpeg', true],
            'imagecreatefrompng'  => ['PNG 解码', '--with-png', true],
            'imagecreatefromwebp' => ['WebP 解码', '--with-webp', false],
            'imagecreatefromgif'  => ['GIF 解码', '--with-gif', false],
            'imagecreatefrombmp'  => ['BMP 解码', '--with-bmp', false],
            'imagewebp'           => ['WebP 输出', '--with-webp', true],
            'imagettftext'        => ['中文水印', '--with-freetype', true],
            'imagescale'          => ['等比缩放', 'GD 2.1+', true],
            'imagecopyresampled'  => ['高质量重采样', 'GD 2.0+', true],
        ];
        $gd = [];
        foreach ($gdFns as $fn => $meta) {
            $ok = function_exists($fn);
            $gd[] = [
                'fn'     => $fn,
                'label'  => $meta[0],
                'switch' => $meta[1],
                'state'  => $ok ? 'ok' : ($meta[2] ? 'fail' : 'warn'),
                'text'   => $ok ? '支持' : '不支持',
            ];
        }
        $gdInfo = function_exists('gd_info') ? gd_info() : [];

        // ---------- 运行参数 ----------
        $mem    = $this->toBytes((string)ini_get('memory_limit'));
        $post   = $this->toBytes((string)ini_get('post_max_size'));
        $upload = $this->toBytes((string)ini_get('upload_max_filesize'));
        $maxExec = (int)ini_get('max_execution_time');
        $gcLife  = (int)ini_get('session.gc_maxlifetime');
        $ckLife  = (int)ini_get('session.cookie_lifetime');

        $params = [
            [
                'name' => 'memory_limit',
                'val'  => (string)ini_get('memory_limit'),
                'state' => $mem === -1 || $mem >= 1073741824 ? 'ok' : ($mem >= 536870912 ? 'warn' : 'fail'),
                'desc' => '建议 1024M；低于 512M 时超大图处理会失败',
            ],
            [
                'name' => 'post_max_size',
                'val'  => (string)ini_get('post_max_size'),
                'state' => $post >= 33554432 ? 'ok' : ($post >= 8388608 ? 'warn' : 'fail'),
                'desc' => '需 ≥ 策略组单图上限，建议 32M',
            ],
            [
                'name' => 'upload_max_filesize',
                'val'  => (string)ini_get('upload_max_filesize'),
                'state' => $upload >= 33554432 ? 'ok' : ($upload >= 8388608 ? 'warn' : 'fail'),
                'desc' => '同上，建议 32M',
            ],
            [
                'name' => 'max_execution_time',
                'val'  => $maxExec === 0 ? '不限制' : $maxExec . ' 秒',
                'state' => $maxExec === 0 || $maxExec >= 120 ? 'ok' : 'warn',
                'desc' => '大图压缩与网盘上传耗时较长，建议 300',
            ],
            [
                'name' => 'session.gc_maxlifetime',
                'val'  => $gcLife . ' 秒（' . round($gcLife / 3600, 1) . ' 小时）',
                'state' => $gcLife >= 604800 ? 'ok' : ($gcLife >= 86400 ? 'warn' : 'fail'),
                'desc' => '与 config/session.php 的 expire 对齐，建议 604800（7 天）',
            ],
            [
                'name' => 'session.cookie_lifetime',
                'val'  => $ckLife === 0 ? '0（关浏览器即失效）' : $ckLife . ' 秒',
                'state' => $ckLife === 0 ? 'warn' : 'ok',
                'desc' => '0 表示关浏览器需要重登；要 7 天免登则设 604800',
            ],
            [
                'name' => 'config/session.php expire',
                'val'  => (string)config('session.expire'),
                'state' => (int)config('session.expire') >= 86400 ? 'ok' : 'warn',
                'desc' => '框架层会话有效期（秒）',
            ],
        ];

        // ---------- 目录与文件 ----------
        $paths = [
            ['runtime/', root_path() . 'runtime'],
            ['public/i/', public_path() . 'i'],
            ['public/i/thumbs/', public_path() . 'i' . DIRECTORY_SEPARATOR . 'thumbs'],
            ['storage/fonts/', root_path() . 'storage' . DIRECTORY_SEPARATOR . 'fonts'],
        ];
        $dirs = [];
        foreach ($paths as $p) {
            $exists = is_dir($p[1]);
            $writable = $exists && is_writable($p[1]);
            $dirs[] = [
                'name'  => $p[0],
                'state' => $writable ? 'ok' : ($exists ? 'fail' : 'warn'),
                'text'  => $writable ? '可写' : ($exists ? '不可写' : '目录不存在'),
            ];
        }
        // 字体
        $fonts = [];
        foreach (['米芾体.ttf', '谭体.ttf'] as $f) {
            $fp = root_path() . 'storage' . DIRECTORY_SEPARATOR . 'fonts' . DIRECTORY_SEPARATOR . $f;
            $fonts[] = ['name' => $f, 'state' => is_readable($fp) ? 'ok' : 'warn', 'text' => is_readable($fp) ? '可读' : '缺失'];
        }

        // ---------- 数据库 ----------
        $db = ['state' => 'fail', 'text' => '未连接', 'version' => '-', 'tables' => 0];
        try {
            $row = Db::query('SELECT VERSION() AS v');
            $db['version'] = (string)($row[0]['v'] ?? '-');
            $tables = Db::query('SELECT COUNT(*) AS c FROM information_schema.tables WHERE table_schema = DATABASE()');
            $db['tables'] = (int)($tables[0]['c'] ?? 0);
            $db['state'] = 'ok';
            $db['text']  = '连接正常';
        } catch (\Throwable $e) {
            $db['text'] = '连接失败：' . mb_substr($e->getMessage(), 0, 80);
        }

        // ---------- 磁盘 ----------
        $free  = @disk_free_space(public_path());
        $total = @disk_total_space(public_path());
        $disk = [
            'free'  => $free === false ? '-' : $this->fmtBytes((int)$free),
            'total' => $total === false ? '-' : $this->fmtBytes((int)$total),
            'pct'   => ($free !== false && $total) ? (int)round((1 - $free / $total) * 100) : 0,
            'state' => ($free !== false && $total && $free / $total < 0.05) ? 'warn' : 'ok',
        ];

        // ---------- 汇总 ----------
        $counts = ['ok' => 0, 'warn' => 0, 'fail' => 0];
        foreach ([$base, $exts, $gd, $params, $dirs, $fonts] as $group) {
            foreach ($group as $item) {
                $s = $item['state'] ?? 'ok';
                if (isset($counts[$s])) {
                    $counts[$s]++;
                }
            }
        }
        if (isset($counts[$db['state']])) {
            $counts[$db['state']]++;
        }
        $verdict = $counts['fail'] > 0 ? 'fail' : ($counts['warn'] > 0 ? 'warn' : 'ok');

        return $this->adminView('admin/env_check', [
            'page'    => 'env_check',
            'base'    => $base,
            'exts'    => $exts,
            'gd'      => $gd,
            'gd_info' => $gdInfo,
            'params'  => $params,
            'dirs'    => $dirs,
            'fonts'   => $fonts,
            'db'      => $db,
            'disk'    => $disk,
            'counts'  => $counts,
            'verdict' => $verdict,
        ]);
    }
}
