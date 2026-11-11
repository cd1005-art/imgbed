<?php
declare (strict_types = 1);

namespace app\common\model;

use think\facade\Db;

/**
 * 站点设置（纯 Db 封装，不继承 Model——避免与 think\Model::set() 冲突）
 */
class Setting
{
    protected static ?array $cache = null;

    /** 取单项设置（全量缓存） */
    public static function get(string $key, $default = null)
    {
        $all = self::allSettings();
        return array_key_exists($key, $all) ? $all[$key] : $default;
    }

    /** 全量设置（key => value），单请求缓存 */
    public static function allSettings(): array
    {
        if (self::$cache === null) {
            self::$cache = Db::name('settings')->column('svalue', 'skey');
        }
        return self::$cache;
    }

    /** 写入设置 */
    public static function saveKey(string $key, string $value): void
    {
        $exist = Db::name('settings')->where('skey', $key)->find();
        if ($exist) {
            Db::name('settings')->where('skey', $key)->update(['svalue' => $value, 'update_time' => time()]);
        } else {
            Db::name('settings')->insert(['skey' => $key, 'svalue' => $value, 'update_time' => time()]);
        }
        self::$cache = null;
    }
}
