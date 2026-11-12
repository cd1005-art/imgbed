<?php
declare (strict_types = 1);

namespace app\api\controller;

use app\BaseController;
use app\common\exception\UploadException;
use app\common\model\ApiToken;
use app\common\model\Image;
use app\common\model\StrategyGroup;
use app\common\model\User;
use app\common\service\UploadService;
use think\facade\Db;
use think\Request;

/**
 * API v1 控制器
 *
 * 鉴权：X-API-TOKEN 头 / Authorization: Bearer <token> / token 参数（兼容多种客户端）
 * 返回格式：兼容 Lsky（status/code/message/data）+ 原生扩展字段
 */
class V1 extends BaseController
{
    /** @var ApiToken|null 当前 Token */
    protected ?ApiToken $token = null;

    /** @var User|null Token 所属用户 */
    protected ?User $user = null;

    /**
     * 解析并校验 Token（upload 等写操作用）
     */
    protected function auth(): void
    {
        $tokenStr = (string)$this->request->header('x-api-token', '');
        if ($tokenStr === '' && str_starts_with((string)$this->request->header('authorization', ''), 'Bearer ')) {
            $tokenStr = trim(substr((string)$this->request->header('authorization'), 7));
        }
        if ($tokenStr === '') {
            $tokenStr = (string)$this->request->param('token', '');
        }

        if ($tokenStr === '') {
            $this->fail(401, '缺少 API Token');
        }

        $token = ApiToken::where('token', $tokenStr)->where('status', 1)->find();
        if (!$token) {
            $this->fail(401, 'API Token 无效或已停用');
        }

        $user = User::findUser((int)$token->user_id);
        if (!$user) {
            $this->fail(401, 'Token 所属用户不可用');
        }

        // Token 日调用上限（0=不限）
        if ($token->daily_limit > 0) {
            $count = Db::name('upload_logs')
                ->where('user_id', $user->id)
                ->where('create_time', '>=', strtotime(date('Y-m-d')))
                ->count();
            if ($count >= (int)$token->daily_limit) {
                $this->fail(429, 'Token 达到今日调用上限');
            }
        }

        $this->token = $token;
        $this->user  = $user;

        // 调用统计：鉴权通过的每次 API 请求计 1 次（upload/info/list/delete 统一口径）
        Db::name('api_tokens')->where('id', $token->id)
            ->inc('request_count')
            ->update(['last_used_time' => time()]);
    }

    /**
     * 上传接口（核心）
     * POST /api/v1/upload
     * 文件字段：file（兼容 image / file）
     */
    public function upload(): \think\response\Json
    {
        $this->auth();

        // 兼容 file / image 字段名
        $file = $this->request->file('file') ?: $this->request->file('image');
        if (!$file) {
            $this->fail(400, '未收到上传文件（字段名 file）');
        }

        $group = StrategyGroup::where('id', $this->user->group_id)->where('status', 1)->find();
        if (!$group) {
            $this->fail(500, '用户策略组异常');
        }

        $service = new UploadService();
        try {
            $result = $service->upload(
                $file,
                (int)$this->user->id,
                $group,
                $this->clientIp(),
                (string)$this->request->header('user-agent', '')
            );
        } catch (UploadException $e) {
            $this->fail(400, $e->getMessage());
        }

        $image = $result['image'];
        $url   = $result['url'];

        // 站点外链补全为绝对地址（API 调用方在站外）
        if (str_starts_with($url, '/')) {
            $url = $this->request->domain() . $url;
        }

        $this->logCall('upload', true);

        // 统一返回：Lsky 兼容字段 + 原生扩展
        return json([
            'status'  => true,
            'code'    => 200,
            'message' => $result['reused'] ? 'success（SHA1 秒传复用）' : 'success',
            'data'    => [
                'id'         => (int)$image->id,
                'name'       => (string)$image->name,
                'url'        => $url,
                'direct_url' => $result['direct_url'] !== '' ? $result['direct_url'] : null,
                'md5'        => (string)$image->md5,
                'sha1'       => (string)$image->sha1,
                'size'       => (int)$image->size,
                'width'      => (int)$image->width,
                'height'     => (int)$image->height,
                'mime'       => (string)$image->mime,
                'ext'        => (string)$image->ext,
                'reused'     => $result['reused'],
                // 常用格式快捷链接（Markdown / BBCode / HTML）
                'markdown'   => '![](' . $url . ')',
                'html'       => '<img src="' . $url . '" />',
                'bbcode'     => '[img]' . $url . '[/img]',
                'delete'     => $this->request->domain() . '/api/v1/delete/' . $image->id,
            ],
        ]);
    }

    /**
     * 图片信息（仅本人）
     * GET /api/v1/info/{id}
     */
    public function info(int $id): \think\response\Json
    {
        $this->auth();
        $image = Image::where('id', $id)->where('user_id', $this->user->id)->where('status', '>', 0)->find();
        if (!$image) {
            $this->fail(404, '图片不存在');
        }
        $this->logCall('info');
        return json(['status' => true, 'code' => 200, 'message' => 'success', 'data' => $image->toArray()]);
    }

    /**
     * 图片列表（仅本人）
     * GET /api/v1/list?page=1&size=20
     */
    public function list(): \think\response\Json
    {
        $this->auth();
        $page = max(1, (int)$this->request->param('page', 1));
        $size = min(100, max(1, (int)$this->request->param('size', 20)));

        $list = Image::where('user_id', $this->user->id)
            ->where('status', Image::ST_NORMAL)
            ->order('id', 'desc')
            ->page($page, $size)
            ->select()
            ->each(function ($item) {
                $item['url'] = $this->request->domain() . '/i/' . $item['path'];
                return $item;
            });

        $this->logCall('list');
        return json([
            'status'  => true,
            'code'    => 200,
            'message' => 'success',
            'data'    => ['page' => $page, 'size' => $size, 'list' => $list],
        ]);
    }

    /**
     * 删除（仅本人）
     * POST /api/v1/delete/{id}
     */
    public function delete(int $id): \think\response\Json
    {
        $this->auth();
        $image = Image::where('id', $id)->where('user_id', $this->user->id)->where('status', Image::ST_NORMAL)->find();
        if (!$image) {
            $this->fail(404, '图片不存在');
        }

        $image->save(['status' => Image::ST_DELETED, 'delete_time' => time()]);
        $this->logCall('delete');
        return json(['status' => true, 'code' => 200, 'message' => 'success', 'data' => null]);
    }

    /** 分类计数 + 调用日志（每请求一条） */
    protected function logCall(string $action, bool $success = true): void
    {
        try {
            // 分类列
            $fieldMap = ['upload' => 'upload_count', 'list' => 'list_count', 'info' => 'info_count', 'delete' => 'delete_count'];
            if (isset($fieldMap[$action])) {
                Db::name('api_tokens')->where('id', $this->token->id)->inc($fieldMap[$action])->update();
            }
            // 调用日志（统计卡片与周期分析用）
            Db::name('api_call_logs')->insert([
                'token_id'    => (int)$this->token->id,
                'user_id'     => (int)$this->user->id,
                'action'      => $action,
                'success'     => $success ? 1 : 0,
                'ip'          => $this->clientIp(),
                'create_time' => time(),
            ]);
        } catch (\Throwable $e) {
            // 统计失败不影响业务
        }
    }

    /** 统一失败输出并终止 */
    protected function fail(int $code, string $msg): void
    {
        $httpStatus = in_array($code, [401, 404, 429], true) ? $code : 200;
        $response = json([
            'status'  => false,
            'code'    => $code,
            'message' => $msg,
            'data'    => null,
        ]);
        if ($httpStatus !== 200) {
            $response->code($httpStatus);
        }
        $response->send();
        exit;
    }

    /** 客户端 IP（代理穿透基础处理） */
    protected function clientIp(): string
    {
        return (string)($this->request->header('x-real-ip', '')
            ?: $this->request->header('x-forwarded-for', '')
            ?: $this->request->ip());
    }
}
