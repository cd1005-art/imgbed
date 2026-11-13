<?php
declare (strict_types = 1);

namespace app\common\service;

use app\common\model\Setting;

/**
 * 前台导航栏配置（后台可增删改）
 * 存储：settings.nav_links = JSON 数组
 * 字段：name, url, pos(left|right), auth(all|login|guest), blank(0/1), sort, enabled(0/1)
 */
class NavService
{
    /** 默认导航（与内置布局一致；未配置时使用） */
    public static function defaults(): array
    {
        return [
            ['name' => '图片广场', 'url' => '/explore',    'pos' => 'left',  'auth' => 'all',   'blank' => 0, 'sort' => 10, 'enabled' => 1],
            ['name' => '我的图片', 'url' => '/gallery',    'pos' => 'right', 'auth' => 'login', 'blank' => 0, 'sort' => 20, 'enabled' => 1],
            ['name' => 'VIP',      'url' => '/vip',        'pos' => 'right', 'auth' => 'all',   'blank' => 0, 'sort' => 30, 'enabled' => 1],
            ['name' => '个人中心', 'url' => '/console',    'pos' => 'right', 'auth' => 'login', 'blank' => 0, 'sort' => 40, 'enabled' => 1],
            ['name' => '登录',     'url' => '/login',      'pos' => 'right', 'auth' => 'guest', 'blank' => 0, 'sort' => 50, 'enabled' => 1],
            ['name' => '注册',     'url' => '/register',   'pos' => 'right', 'auth' => 'guest', 'blank' => 0, 'sort' => 60, 'enabled' => 1],
            ['name' => '退出',     'url' => '/logout',     'pos' => 'right', 'auth' => 'login', 'blank' => 0, 'sort' => 70, 'enabled' => 1],
        ];
    }

    /** 全部配置（未配置时返回默认） */
    public static function all(): array
    {
        $raw = (string)Setting::get('nav_links', '');
        if (trim($raw) === '') {
            return self::defaults();
        }
        $list = json_decode($raw, true);
        if (!is_array($list) || !$list) {
            return self::defaults();
        }
        $out = [];
        foreach ($list as $it) {
            if (!is_array($it) || empty($it['name']) || empty($it['url'])) {
                continue;
            }
            $out[] = [
                'name'    => (string)$it['name'],
                'url'     => (string)$it['url'],
                'pos'     => ($it['pos'] ?? 'right') === 'left' ? 'left' : 'right',
                'auth'    => in_array(($it['auth'] ?? 'all'), ['all', 'login', 'guest'], true) ? (string)$it['auth'] : 'all',
                'blank'   => (int)($it['blank'] ?? 0) === 1 ? 1 : 0,
                'sort'    => (int)($it['sort'] ?? 50),
                'enabled' => (int)($it['enabled'] ?? 1) === 1 ? 1 : 0,
            ];
        }
        return $out ?: self::defaults();
    }

    /** 当前页面可见链接（按登录态过滤 + 排序 + 分组） */
    public static function forPage(bool $logged): array
    {
        $left = [];
        $right = [];
        foreach (self::all() as $it) {
            if ((int)$it['enabled'] !== 1) {
                continue;
            }
            if ($it['auth'] === 'login' && !$logged) {
                continue;
            }
            if ($it['auth'] === 'guest' && $logged) {
                continue;
            }
            $it['target'] = $it['blank'] === 1 ? ' target="_blank" rel="noopener"' : '';
            if ($it['pos'] === 'left') {
                $left[] = $it;
            } else {
                $right[] = $it;
            }
        }
        usort($left, fn($a, $b) => $a['sort'] <=> $b['sort']);
        usort($right, fn($a, $b) => $a['sort'] <=> $b['sort']);
        return ['left' => $left, 'right' => $right];
    }

    /** 保存 */
    public static function save(array $links): void
    {
        Setting::saveKey('nav_links', json_encode(array_values($links), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    /** 恢复默认 */
    public static function reset(): void
    {
        Setting::saveKey('nav_links', '');
    }
}
