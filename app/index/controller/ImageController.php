<?php
declare (strict_types = 1);

namespace app\index\controller;

use app\BaseController;
use app\common\model\Image;
use app\common\model\StorageConfig;
use app\common\service\storage\StorageManager;
use think\Response;

/**
 * 图片外链服务 /i/{path}
 * 按存储实例 url_mode：1 代理中转输出 / 2 直链 302
 */
class ImageController extends BaseController
{
    public function serve(string $path): Response
    {
        $image = Image::where('path', $path)
            ->where('status', Image::ST_NORMAL)
            ->find();

        // 拒审 / 冻结 / 不存在 → 404（不泄露细节）
        if (!$image || (int)$image->audit_status === Image::AUDIT_REJECT) {
            return Response::create('Image Not Found')->code(404);
        }

        $storage = StorageConfig::find((int)$image->storage_id);
        $urlMode = (int)($storage['url_mode'] ?? 1);

        // 直链模式：302 跳转，服务器零流量
        if ($urlMode === 2) {
            try {
                $direct = StorageManager::driver((int)$image->storage_id)->getDirectUrl($path);
                if ($direct !== '') {
                    return redirect($direct, 302);
                }
            } catch (\Throwable $e) {
                // 直链不可用降级为代理输出
            }
        }

        // 代理模式：读内容输出
        try {
            $content = StorageManager::driver((int)$image->storage_id)->getContent($path);
        } catch (\Throwable $e) {
            $content = null;
        }
        if ($content === null) {
            return Response::create('Image Not Found')->code(404);
        }

        return Response::create($content)
            ->header([
                'Content-Type'  => (string)$image->mime ?: 'image/jpeg',
                'Cache-Control' => 'public, max-age=31536000, immutable',
                'Expires'       => gmdate('D, d M Y H:i:s', time() + 31536000) . ' GMT',
                'X-Content-Type-Options' => 'nosniff',
            ]);
    }
}
