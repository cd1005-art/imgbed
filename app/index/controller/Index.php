<?php
declare (strict_types = 1);

namespace app\index\controller;

use app\BaseController;

/**
 * 首页（一期占位版：接口说明 + 上传测试）
 * 正式版式按「非 AI 化前端 SKILL」另行美化
 */
class Index extends BaseController
{
    public function index()
    {
        return json([
            'name'    => 'XiaoYao ImageBed',
            'version' => '1.0.0-dev',
            'api'     => [
                'upload' => 'POST /api/v1/upload  (header: X-API-TOKEN)',
                'info'   => 'GET  /api/v1/info/{id}',
                'list'   => 'GET  /api/v1/list?page=1&size=20',
                'delete' => 'POST /api/v1/delete/{id}',
            ],
            'image_url' => '/i/{path}',
        ]);
    }
}
