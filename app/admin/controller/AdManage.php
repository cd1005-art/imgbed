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
                // 水印字体列表（storage/fonts/*.ttf|otf）
        $fonts = [];
        foreach (glob(root_path() . 'storage/fonts/*.{ttf,otf,ttc}', GLOB_BRACE) ?: [] as $fp) {
            $fonts[] = basename($fp);
        }
        sort($fonts);
        $storagesForThumb = \think\facade\Db::name('storage_configs')->field('id,name,driver')->select()->toArray();

return $this->adminView('admin/site', [
            'fonts' => $fonts,
            'storages_for_thumb' => $storagesForThumb,
            'page'     => 'site',
            'settings' => Setting::allSettings(),
            'groups'   => StrategyGroup::where('status', 1)->select(),
            'domain'   => rtrim((string)request()->domain(), '/'),
            'credit_plans' => Db::name('credit_plans')->order('sort', 'asc')->order('id', 'asc')->select()->toArray(),
            'recharge_bonus_list' => Db::name('recharge_bonus')->order('amount', 'asc')->order('sort', 'asc')->select()->toArray(),
        ]);
    }

    /** 站点设置保存 */
    /** 充值档位：保存（新增/编辑） */
    public function planSave()
    {
        $id     = (int)$this->request->post('id', 0);
        $amount = (int)$this->request->post('amount', 0);
        $bonus  = max(0, (int)$this->request->post('bonus', 0));
        $sort   = (int)$this->request->post('sort', 0);
        $status = (int)$this->request->post('status', 1) === 1 ? 1 : 0;
        if ($amount <= 0) {
            return json(['status' => false, 'message' => '金额必须大于 0']);
        }
        $data = ['amount' => $amount, 'bonus' => $bonus, 'sort' => $sort, 'status' => $status];
        if ($id > 0) {
            Db::name('credit_plans')->where('id', $id)->update($data);
        } else {
            $data['create_time'] = time();
            Db::name('credit_plans')->insert($data);
        }
        return json(['status' => true, 'message' => '档位已保存']);
    }

    /** 充值档位：删除 */
    public function planDel(int $id)
    {
        Db::name('credit_plans')->where('id', $id)->delete();
        return json(['status' => true, 'message' => '已删除']);
    }

    /** 充值赠送活动：保存 */
    public function bonusSave()
    {
        $id     = (int)$this->request->post('id', 0);
        $amount = (int)$this->request->post('amount', 0);
        $bonus  = (int)$this->request->post('bonus', 0);
        $sort   = (int)$this->request->post('sort', 0);
        $status = (int)$this->request->post('status', 1) === 1 ? 1 : 0;
        if ($amount <= 0 || $bonus <= 0) {
            return json(['status' => false, 'message' => '满额与赠送金额必须大于 0']);
        }
        $data = ['amount' => $amount, 'bonus' => $bonus, 'sort' => $sort, 'status' => $status];
        if ($id > 0) {
            Db::name('recharge_bonus')->where('id', $id)->update($data);
        } else {
            $data['create_time'] = time();
            Db::name('recharge_bonus')->insert($data);
        }
        return json(['status' => true, 'message' => '活动已保存']);
    }

    /** 充值赠送活动：删除 */
    public function bonusDel(int $id)
    {
        Db::name('recharge_bonus')->where('id', $id)->delete();
        return json(['status' => true, 'message' => '已删除']);
    }

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
            // 站币：充值 / 提现 / 分成
            'pager_size', 'explore_per_page',
            'img_compress', 'img_webp', 'img_thumb_size', 'img_watermark', 'img_watermark_pos', 'img_watermark_opacity', 'img_exif_clean',
            'img_watermark_font', 'thumb_storage_id', 'token_scopes', 'audit_max_side',
            'img_local_link', 'img_local_link_min', 'img_local_link_max',
            'credits_per_yuan', 'min_recharge', 'recharge_bonus_pct',
            'withdraw_enable', 'withdraw_rate', 'min_withdraw', 'withdraw_step', 'withdraw_monthly_limit', 'withdraw_fee_pct',
            'revenue_split_mode', 'share_user', 'share_ai',
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
