<?php
declare (strict_types = 1);

namespace app\index\controller;

use app\index\controller\Base;
use app\common\exception\UploadException;
use app\common\model\Setting;
use app\common\model\StrategyGroup;
use app\common\model\User;
use app\common\service\UploadService;
use think\response\Json;

/**
 * 前台网页上传（首页上传区 AJAX 提交）
 *
 * 登录用户 → 按用户策略组；游客 → 游客组（运营模式 invite 时拒绝游客上传）。
 */
class UploadController extends Base
{
    public function index()
    {
        $userId = (int)session('user_id');
        $user   = $userId > 0 ? User::findUser($userId) : null;
        $guestGroup = StrategyGroup::find((int)Setting::get('guest_group_id', 1));

        $quotaTotal = 0;
        $quotaUsed  = 0;
        if ($user) {
            $quotaTotal = (int)$user->quota_total > 0 ? (int)$user->quota_total : (int)($user->group->quota_total ?? 1073741824);
            $quotaUsed  = (int)$user->quota_used;
        }

        // 生效策略组：登录用户 → 所属组；游客 → 游客组
        $group = $user
            ? StrategyGroup::where('id', $user->group_id)->where('status', 1)->find()
            : $guestGroup;
        $allowExt = $group ? $group->allowedExt() : ['jpg', 'jpeg', 'png', 'gif', 'webp'];

        return $this->siteView('upload/index', [
            'user'             => $user,
            'site_name'        => Setting::get('site_name', '逍遥图床'),
            'guest_group'      => $guestGroup,
            'group'            => $group,
            'max_size_str'     => $group ? UploadService::formatSize((int)$group->max_size) : '5 MB',
            'allow_ext_str'    => implode(' / ', array_map('strtoupper', $allowExt)),
            'keep_days_text'   => ($group && (int)$group->keep_days > 0)
                ? '图片保留 ' . (int)$group->keep_days . ' 天'
                : '图片永久保留',
            'daily_count_text' => ($group && (int)$group->daily_count > 0)
                ? '每日可传 ' . (int)$group->daily_count . ' 次'
                : '每日上传不限',
            'quota_used_str'   => UploadService::formatSize($quotaUsed),
            'quota_total_str'  => UploadService::formatSize($group ? (int)$group->quota_total : 1073741824),
        ]);
    }

    /** 网页 AJAX 上传 */
    public function save(): Json
    {
        $userId = (int)session('user_id');
        $user   = $userId > 0 ? User::findUser($userId) : null;

        // 邀请制模式禁止游客上传
        if (!$user && Setting::get('operation_mode', 'free_pay') === 'invite') {
            return $this->jsonFail(401, '当前仅限受邀用户上传，先登录');
        }

        $file = $this->request->file('file') ?: $this->request->file('image');
        if (!$file) {
            return $this->jsonFail(400, '没收到文件，重新选一下');
        }

        $group = $user
            ? StrategyGroup::where('id', $user->group_id)->where('status', 1)->find()
            : StrategyGroup::where('id', (int)Setting::get('guest_group_id', 1))->where('status', 1)->find();

        if (!$group) {
            return $this->jsonFail(500, '上传策略没配置好，联系站长');
        }

        try {
            $result = (new UploadService())->upload(
                $file,
                $user ? (int)$user->id : 0,
                $group,
                $this->clientIp(),
                (string)$this->request->header('user-agent', '')
            );
        } catch (UploadException $e) {
            return $this->jsonFail(400, $e->getMessage());
        }

        $image = $result['image'];
        $url   = $result['url'];
        if (str_starts_with($url, '/')) {
            $url = $this->request->domain() . $url;
        }

        return json([
            'status'  => true,
            'code'    => 200,
            'message' => 'ok',
            'data'    => [
                'id'       => (int)$image->id,
                'name'     => (string)$image->name,
                'url'      => $url,
                'size'     => (int)$image->size,
                'width'    => (int)$image->width,
                'height'   => (int)$image->height,
                'reused'   => $result['reused'],
                'markdown' => '![](' . $url . ')',
                'html'     => '<img src="' . $url . '" />',
                'bbcode'   => '[img]' . $url . '[/img]',
            ],
        ]);
    }

    protected function jsonFail(int $code, string $msg): Json
    {
        return json(['status' => false, 'code' => $code, 'message' => $msg, 'data' => null]);
    }

    protected function clientIp(): string
    {
        return (string)($this->request->header('x-real-ip', '')
            ?: $this->request->header('x-forwarded-for', '')
            ?: $this->request->ip());
    }
}
