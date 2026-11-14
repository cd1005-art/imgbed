<?php
declare (strict_types = 1);

namespace app\index\controller;

use app\common\model\Image;
use app\common\model\Setting;
use app\index\controller\Base;
use think\facade\Db;
use think\facade\Log;

/**
 * sitemap.xml：动态生成 + 文件缓存（默认 30 分钟）
 * 仅收录公开、正常、审核通过的图片，避免暴露私密/待审内容
 */
class Sitemap extends Base
{
    /** 缓存文件路径（多应用下 runtime 目录为 runtime/{app}/，固定指向 index 应用） */
    public static function cacheFile(): string
    {
        return root_path() . 'runtime' . DIRECTORY_SEPARATOR . 'index' . DIRECTORY_SEPARATOR
            . 'sitemap' . DIRECTORY_SEPARATOR . 'sitemap.xml';
    }

    /** 单文件 URL 上限（协议限制 50000；留余量） */
    private const MAX_URLS = 49000;

    public function index()
    {
        // 后台可关闭 sitemap（输出合法空 urlset，避免 robots 指向 404）
        if ((int)Setting::get('sitemap_enable', 1) !== 1) {
            return $this->xmlResponse($this->emptyXml(), 'disabled');
        }

        $cacheFile = self::cacheFile();
        $ttl       = max(1, (int)Setting::get('sitemap_ttl', 30)) * 60;
        $force     = $this->request->param('refresh') === '1' && (int)session('admin_id') > 0;

        if (!$force && is_file($cacheFile) && (time() - (int)filemtime($cacheFile)) < $ttl) {
            return $this->xmlResponse((string)file_get_contents($cacheFile), 'hit');
        }

        try {
            $xml = $this->build();
        } catch (\Throwable $e) {
            Log::record('sitemap 生成失败：' . $e->getMessage(), 'error');
            if (is_file($cacheFile)) {
                return $this->xmlResponse((string)file_get_contents($cacheFile), 'stale');
            }
            return $this->xmlResponse($this->emptyXml(), 'empty');
        }

        @mkdir(dirname($cacheFile), 0755, true);
        @file_put_contents($cacheFile, $xml, LOCK_EX);
        return $this->xmlResponse($xml, 'miss');
    }

    protected function build(): string
    {
        $domain = rtrim((string)request()->domain(), '/');
        $today  = date('Y-m-d');
        $items  = [];

        // 首页与广场
        $items[] = ['loc' => $domain . '/', 'changefreq' => 'daily', 'priority' => '1.0', 'lastmod' => $today];
        $items[] = ['loc' => $domain . '/explore', 'changefreq' => 'daily', 'priority' => '0.9', 'lastmod' => $today];

        // 广场分页（后台配置数量，0=不含）
        $publicWhere = function () {
            return Image::where('is_public', 1)
                ->where('audit_status', Image::AUDIT_PASS)
                ->where('status', Image::ST_NORMAL);
        };
        $total  = $publicWhere()->count();
        $pagesLimit = max(0, (int)Setting::get('sitemap_pages', 20));
        $pages  = min($pagesLimit, (int)ceil($total / 30));
        for ($i = 2; $i <= $pages; $i++) {
            $items[] = ['loc' => $domain . '/explore?page=' . $i, 'changefreq' => 'daily', 'priority' => '0.6', 'lastmod' => $today];
        }

        // 图片详情
        $images = $publicWhere()->field('id,update_time')->order('id', 'desc')->limit(self::MAX_URLS)->select();
        foreach ($images as $img) {
            $items[] = [
                'loc'        => $domain . '/p/' . (int)$img->id,
                'changefreq' => 'weekly',
                'priority'   => '0.8',
                'lastmod'    => date('Y-m-d', (int)($img->update_time ?: time())),
            ];
        }
        if ($total > self::MAX_URLS) {
            Log::record('sitemap 达到上限 ' . self::MAX_URLS . ' 条，当前公开图 ' . $total . ' 张，超出部分未收录（需实现索引拆分）', 'warning');
        }

        // 标签页（后台配置：是否收录 / 最小使用次数 / 上限）
        $includeTags = (int)Setting::get('sitemap_include_tags', 1) === 1;
        $tagMin      = max(1, (int)Setting::get('sitemap_tag_min_use', 2));
        $tagLimit    = max(0, (int)Setting::get('sitemap_tag_limit', 200));
        $tags = $includeTags ? Db::name('tags')->where('status', 1)->where('use_count', '>=', $tagMin)
            ->order('use_count', 'desc')->limit($tagLimit)->field('name,use_count')->select()->toArray() : [];
        $left = max(0, self::MAX_URLS - count($items));
        foreach (array_slice($tags, 0, min($tagLimit, $left)) as $t) {
            $items[] = [
                'loc'        => $domain . '/tag?name=' . rawurlencode((string)$t['name']),
                'changefreq' => 'weekly',
                'priority'   => '0.5',
                'lastmod'    => $today,
            ];
        }

        return $this->render($items);
    }

    protected function render(array $items): string
    {
        $out = '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
        $out .= '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">';
        foreach ($items as $it) {
            $out .= "\n  <url>\n    <loc>" . $this->e($it['loc']) . "</loc>";
            if (!empty($it['lastmod'])) {
                $out .= "\n    <lastmod>" . $this->e($it['lastmod']) . "</lastmod>";
            }
            if (!empty($it['changefreq'])) {
                $out .= "\n    <changefreq>" . $this->e($it['changefreq']) . "</changefreq>";
            }
            if (!empty($it['priority'])) {
                $out .= "\n    <priority>" . $this->e($it['priority']) . "</priority>";
            }
            $out .= "\n  </url>";
        }
        $out .= "\n</urlset>\n";
        return $out;
    }

    protected function emptyXml(): string
    {
        return $this->render([]);
    }

    protected function e(string $s): string
    {
        return htmlspecialchars($s, ENT_QUOTES | ENT_XML1, 'UTF-8');
    }

    protected function xmlResponse(string $xml, string $cache)
    {
        return response($xml, 200, [
            'Content-Type'  => 'application/xml; charset=utf-8',
            'Cache-Control' => 'public, max-age=1800',
            'X-Sitemap-Cache' => $cache,
        ]);
    }
}
