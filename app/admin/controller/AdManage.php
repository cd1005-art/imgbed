<?php
declare (strict_types = 1);

namespace app\admin\controller;

use app\admin\controller\Base;
use app\common\model\AdSlot;
use app\common\model\Setting;
use app\common\model\StrategyGroup;
use think\facade\Db;

/**
 * 广告管理 + 站点设置（运营模式等）
 */
class AdManage extends Base
{
    /** 广告位管理 */
    public function index()
    {
        return $this->adminView('admin/ad', [
            'page'  => 'ad',
            'slots' => AdSlot::order('sort', 'asc')->select(),
        ]);
    }

    /** 站点设置 */
    public function site()
    {
        return $this->adminView('admin/site', [
            'page'     => 'site',
            'settings' => Setting::allSettings(),
            'groups'   => StrategyGroup::where('status', 1)->select(),
            'domain'   => rtrim((string)request()->domain(), '/'),
        ]);
    }

    /** 站点设置保存 */
    /** 手动触发百度主动推送（增量：update_time 之后更新的公开图，单次上限 1000，受百度配额约束） */
    public function seoPushBaidu()
    {
        if (!\app\common\service\BaiduPushService::enabled()) {
            return json(['status' => false, 'message' => '请先开启百度推送并填写 token']);
        }
        $since  = (int)Setting::get('baidu_push_since', 0);
        $domain = rtrim((string)Setting::get('site_domain', ''), '/');
        if ($domain === '') {
            $domain = rtrim((string)request()->domain(), '/');
        }

        $rows = \app\common\model\Image::where('is_public', 1)
            ->where('audit_status', \app\common\model\Image::AUDIT_PASS)
            ->where('status', \app\common\model\Image::ST_NORMAL)
            ->where('update_time', '>=', $since)
            ->order('update_time', 'asc')->limit(\app\common\service\BaiduPushService::BATCH)->field('id')->select();

        $urls = [];
        foreach ($rows as $r) {
            $urls[] = $domain . '/p/' . (int)$r->id;
        }
        if (!$urls) {
            $urls = [$domain . '/', $domain . '/explore'];
        }

        $res = \app\common\service\BaiduPushService::push($urls);
        if ($res['ok']) {
            Setting::saveKey('baidu_push_since', (string)time());
        }
        return json([
            'status'  => $res['ok'],
            'message' => $res['message'] . '（本次提交 ' . count($urls) . ' 条）',
        ]);
    }

    /** 清除 sitemap 缓存并立即重建 */
    public function seoRefresh()
    {
        $cache = root_path() . 'runtime' . DIRECTORY_SEPARATOR . 'index' . DIRECTORY_SEPARATOR
            . 'sitemap' . DIRECTORY_SEPARATOR . 'sitemap.xml';
        @unlink($cache);
        $domain = rtrim((string)request()->domain(), '/');
        $ch = @curl_init($domain . '/sitemap.xml?refresh=1');
        if ($ch) {
            @curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 120,
                CURLOPT_SSL_VERIFYPEER => false, CURLOPT_SSL_VERIFYHOST => false,
            ]);
            $xml = (string)@curl_exec($ch);
            @curl_close($ch);
            $n = substr_count($xml, '<loc>');
            if ($n > 0) {
                return json(['status' => true, 'message' => "已重建 sitemap，共 {$n} 个 URL"]);
            }
        }
        return json(['status' => true, 'message' => '已清除缓存；由于当前会话非管理员抓取，sitemap 将在下次访问时重建']);
    }

    public function siteSave()
    {
        $allowed = [
            'site_name', 'operation_mode', 'allow_register', 'guest_group_id', 'register_group_id',
            // SEO
            'sitemap_enable', 'sitemap_ttl', 'sitemap_pages', 'sitemap_include_tags',
            'sitemap_tag_min_use', 'sitemap_tag_limit', 'seo_jsonld_enable',
            // 百度主动推送
            'baidu_push_enable', 'baidu_push_token', 'baidu_push_site', 'baidu_push_auto',
            'baidu_push_auto_count', 'baidu_push_auto_hours', 'baidu_push_daily_max',
        ];
        $all = $this->request->post();
        $saved = 0;
        foreach ($allowed as $key) {
            if (array_key_exists($key, $all)) {
                Setting::saveKey($key, (string)$all[$key]);
                $saved++;
            }
        }
        // 改了 SEO 相关配置：顺手清掉 sitemap 缓存，下次访问按新配置重建
        foreach ($allowed as $k) {
            if (str_starts_with($k, 'sitemap_') && array_key_exists($k, $all)) {
                @unlink(root_path() . 'runtime' . DIRECTORY_SEPARATOR . 'index' . DIRECTORY_SEPARATOR
                    . 'sitemap' . DIRECTORY_SEPARATOR . 'sitemap.xml');
                break;
            }
        }
        return json(['status' => true, 'message' => "已保存 {$saved} 项设置"]);
    }

    /** 审核设置 */
    public function audit()
    {
        return $this->adminView('admin/audit', [
            'page'     => 'audit',
            'settings' => Setting::allSettings(),
        ]);
    }

    /** 审核设置保存 */
    public function auditSave()
    {
        $allowed = ['default_audit_driver', 'llm_audit_endpoint', 'llm_audit_key', 'llm_audit_model'];
        $all = $this->request->post();
        $saved = 0;
        foreach ($allowed as $key) {
            if (array_key_exists($key, $all)) {
                Setting::saveKey($key, (string)$all[$key]);
                $saved++;
            }
        }
        return json(['status' => true, 'message' => "已保存 {$saved} 项审核设置"]);
    }

    /** 广告位保存 */
    public function saveSlot()
    {
        $id   = (int)$this->request->post('id', 0);
        $data = [
            'name'     => trim((string)$this->request->post('name', '')),
            'type'     => in_array($this->request->post('type'), ['html', 'image', 'js'], true)
                ? (string)$this->request->post('type') : 'html',
            'content'  => (string)$this->request->post('content', ''),
            'is_open'  => (int)$this->request->post('is_open', 0),
            'update_time' => time(),
        ];
        if ($data['name'] === '') {
            return json(['status' => false, 'message' => '广告位名必填']);
        }
        if ($id > 0) {
            Db::name('ad_slots')->where('id', $id)->update($data);
        } else {
            $data['slot_key'] = 'custom_' . bin2hex(random_bytes(3));
            $data['sort'] = 9;
            Db::name('ad_slots')->insert($data);
        }
        return json(['status' => true, 'message' => '已保存']);
    }

}
