<?php
declare (strict_types = 1);

namespace app\admin\controller;

/**
 * admin 应用控制器基类：注入后台公共变量
 */
class Base extends \app\BaseController
{
    protected function adminView(string $template, array $vars = [])
    {
        $vars['admin']      = $vars['admin'] ?? $this->request->loginAdmin ?? null;
        $vars['site_name']  = $vars['site_name'] ?? \app\common\model\Setting::get('site_name', '逍遥图床');
        $vars['page']       = $vars['page'] ?? '';
        $vars['pager_size'] = $vars['pager_size'] ?? max(3, min(20, (int)\app\common\model\Setting::get('pager_size', 5)));
        return view($template, $vars);
    }
}
