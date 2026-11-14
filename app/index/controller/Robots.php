<?php
declare (strict_types = 1);

namespace app\index\controller;

use app\common\model\Setting;
use app\index\controller\Base;

/** robots.txt：按当前域名动态生成，避免开源分发时写死域名 */
class Robots extends Base
{
    public function index()
    {
        $domain = rtrim((string)request()->domain(), '/');
        $nl = chr(10);   // 避免不同编辑器/传输层把 \n 转义成字面量
        $txt = 'User-agent: *' . $nl . 'Disallow:' . $nl;
        if ((int)Setting::get('sitemap_enable', 1) === 1) {
            $txt .= $nl . 'Sitemap: ' . $domain . '/sitemap.xml' . $nl;
        }
        return response($txt, 200, [
            'Content-Type'  => 'text/plain; charset=utf-8',
            'Cache-Control' => 'public, max-age=3600',
        ]);
    }
}
