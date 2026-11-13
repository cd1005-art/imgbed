<?php
declare (strict_types = 1);

namespace app\index\controller;

use app\common\model\AdSlot;
use app\common\model\Setting;
use app\common\model\User;

/**
 * index 应用控制器基类：统一注入站点名、登录用户、广告位
 */
class Base extends \app\BaseController
{
    /** 渲染视图并注入公共变量 */
    protected function siteView(string $template, array $vars = [])
    {
        $uid = (int)session('user_id');
        $vars['site_name'] = $vars['site_name'] ?? Setting::get('site_name', '逍遥图床');
        $vars['user']      = $vars['user'] ?? ($uid > 0 ? User::findUser($uid) : null);

        // 广告位（后台开关控制；免费运营模式的变现入口）
        $vars['ads_home_header'] = $vars['ads_home_header'] ?? self::adContent('home_header');
        $vars['ads_home_footer'] = $vars['ads_home_footer'] ?? self::adContent('home_footer');

        // 导航栏（后台「导航管理」可配置）
        $logged = $uid > 0;
        $vars['nav_left']  = $vars['nav_left']  ?? \app\common\service\NavService::forPage($logged)['left'];
        $vars['nav_right'] = $vars['nav_right'] ?? \app\common\service\NavService::forPage($logged)['right'];

        return view($template, $vars);
    }

    /** 取广告位内容（VIP 组用户免广告由调用方策略决定，一期前台统一展示） */
    protected static function adContent(string $slotKey): string
    {
        try {
            $slot = AdSlot::where('slot_key', $slotKey)->where('is_open', 1)->find();
            return $slot ? (string)$slot->content : '';
        } catch (\Throwable $e) {
            return '';
        }
    }
}
