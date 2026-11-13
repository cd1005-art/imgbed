<?php
declare (strict_types = 1);

namespace app\common\service;

use think\facade\Db;

/**
 * 标签服务：归一化 / 自生长登记 / 关联 / 统计
 *
 * 归一化：小写、去空白符号、长度限制；同义词映射（内置常见映射 + tag_aliases 表）
 * 自生长：未见过的词自动登记（settings.audit_tag_auto_register=1）
 */
class TagService
{
    /** 常见同义词 → 标准词 */
    public const SYNONYMS = [
        '小猫' => '猫', '猫咪' => '猫', 'cat' => '猫',
        '小狗' => '狗', '狗狗' => '狗', 'dog' => '狗',
        '人像' => '人物', '女孩' => '人物', '男孩' => '人物', '男生' => '人物', '女生' => '人物',
        '风景照' => '风景', '景色' => '风景',
        '动漫' => '二次元', '动画' => '二次元', 'anime' => '二次元',
        '插画图' => '插画', '绘画' => '插画',
        '街拍' => '摄影', '照片' => '摄影',
        '桌布' => '壁纸', 'wallpaper' => '壁纸',
        '头像图' => '头像',
        '表情' => '表情包', 'meme' => '表情包',
    ];

    /** 禁用词（身份/隐私类，直接丢弃） */
    public const BLOCKED = ['人脸', '姓名', '身份证', '地址', '手机号', '裸', '色情'];

    /**
     * 归一化单个标签
     * @return string 返回标准词；空字符串表示丢弃
     */
    public static function normalize(string $raw): string
    {
        $t = trim($raw);
        $t = preg_replace('/[\s\p{P}\p{S}]+/u', '', $t) ?? '';
        if ($t === '' || mb_strlen($t) > 10) {
            return '';
        }
        foreach (self::BLOCKED as $bad) {
            if (str_contains($t, $bad)) {
                return '';
            }
        }
        $lower = mb_strtolower($t);
        if (isset(self::SYNONYMS[$lower])) {
            return self::SYNONYMS[$lower];
        }
        return $t;
    }

    /** 解析模型返回的 tags（数组或字符串），归一化 + 去重 + 限数量 */
    public static function parse($raw, int $max = 5): array
    {
        if (is_string($raw)) {
            $raw = preg_split('/[,，、|\/]+/u', $raw) ?: [];
        }
        if (!is_array($raw)) {
            return [];
        }
        $out = [];
        foreach ($raw as $t) {
            if (!is_string($t)) {
                continue;
            }
            $n = self::normalize($t);
            // 去掉 [模型名] 之类前缀，并丢弃过长（>8 字）的"描述型标签"
            $n = trim((string)preg_replace('/^\[[^\]]*\]\s*/u', '', $n));
            if ($n !== '' && mb_strlen($n) <= 8 && !in_array($n, $out, true)) {
                $out[] = $n;
            }
            if (count($out) >= $max) {
                break;
            }
        }
        return $out;
    }

    /**
     * 把标签挂到图片：登记字典 + 关联 + 计数 + 回写 JSON
     */
    public static function attach(int $imageId, array $tags, string $source = 'auto'): void
    {
        if (!$tags) {
            return;
        }
        $autoRegister = (string)\app\common\model\Setting::get('audit_tag_auto_register', '1') === '1';
        $tagIds = [];

        foreach ($tags as $name) {
            $tag = Db::name('tags')->where('name', $name)->find();
            if (!$tag) {
                // 别名映射
                $alias = Db::name('tag_aliases')->where('alias', $name)->find();
                if ($alias) {
                    $tag = Db::name('tags')->where('id', $alias['tag_id'])->find();
                }
            }
            if (!$tag && $autoRegister) {
                $tagId = (int)Db::name('tags')->insertGetId([
                    'name'        => $name,
                    'category'    => '未分类',
                    'source'      => $source === 'user' ? 'user' : 'auto',
                    'status'      => 1,
                    'use_count'   => 0,
                    'create_time' => time(),
                ]);
                $tag = ['id' => $tagId, 'name' => $name];
            }
            if (!$tag) {
                continue;
            }
            $tagIds[] = (int)$tag['id'];
            Db::name('tags')->where('id', $tag['id'])->inc('use_count')->update();
            $exists = Db::name('image_tags')->where('image_id', $imageId)->where('tag_id', (int)$tag['id'])->find();
            if (!$exists) {
                Db::name('image_tags')->insert([
                    'image_id'    => $imageId,
                    'tag_id'      => (int)$tag['id'],
                    'source'      => $source,
                    'create_time' => time(),
                ]);
            }
        }

        Db::name('images')->where('id', $imageId)->update([
            'tags' => json_encode($tags, JSON_UNESCAPED_UNICODE),
        ]);
    }

    /** 图片标签列表（JSON → 数组） */
    public static function ofImage(array $image): array
    {
        $t = json_decode((string)($image['tags'] ?? '[]'), true);
        return is_array($t) ? $t : [];
    }
}
