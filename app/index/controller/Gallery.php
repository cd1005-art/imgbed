<?php
declare (strict_types = 1);

namespace app\index\controller;

use app\index\controller\Base;
use app\common\model\ApiToken;
use app\common\model\Image;
use think\facade\Db;

/**
 * 我的图片（登录后）
 *
 * @property \app\common\model\User $loginUser 由 CheckLogin 中间件挂载
 */
class Gallery extends Base
{
    /** 列表页 */
    public function index()
    {
        $user  = $this->request->loginUser;
        $page  = max(1, (int)$this->request->param('page', 1));
        $size  = 24;

        $list = Image::where('user_id', $user->id)
            ->where('status', Image::ST_NORMAL)
            ->order('id', 'desc')
            ->page($page, $size)
            ->select();

        $total = Image::where('user_id', $user->id)->where('status', Image::ST_NORMAL)->count();

        $group = \app\common\model\StrategyGroup::find((int)$user->group_id);

        return $this->siteView('gallery/index', [
            'user'  => $user,
            'list'  => $list,
            'page'  => $page,
            'total' => $total,
            'pages' => (int)ceil($total / $size),
            'allow_public'      => $group && (int)$group->allow_public === 1,
            'allow_auto_public' => $group && (int)$group->allow_auto_public === 1,
            'auto_public'       => (int)$user->auto_public === 1,
        ]);
    }

    /** 删除（本人） */
    /** 单张公开/私密/匿名切换：action=public|private|anonymous|realname */
    public function visibility(int $id)
    {
        $user  = $this->request->loginUser;
        $image = Image::where('id', $id)->where('user_id', $user->id)->find();
        if (!$image) {
            return json(['status' => false, 'message' => '图片不存在']);
        }
        $action = (string)$this->request->post('action', '');

        if ($action === 'public') {
            $err = $this->checkCanPublic($user, $image, true);
            if ($err !== '') {
                return json(['status' => false, 'message' => $err]);
            }
            Db::name('images')->where('id', $id)->update(['is_public' => 1, 'update_time' => time()]);
            return json(['status' => true, 'message' => '已公开']);
        }
        if ($action === 'private') {
            Db::name('images')->where('id', $id)->update(['is_public' => 0, 'update_time' => time()]);
            return json(['status' => true, 'message' => '已设为私密']);
        }
        if ($action === 'anonymous') {
            Db::name('images')->where('id', $id)->update(['is_anonymous' => 1]);
            return json(['status' => true, 'message' => '已匿名']);
        }
        if ($action === 'realname') {
            Db::name('images')->where('id', $id)->update(['is_anonymous' => 0]);
            return json(['status' => true, 'message' => '已显示昵称']);
        }
        return json(['status' => false, 'message' => '参数错误']);
    }

    /** 批量：mode=public|private */
    public function batchVisibility()
    {
        $user = $this->request->loginUser;
        $mode = (string)$this->request->post('mode', '');
        $ids  = array_filter(array_map('intval', explode(',', (string)$this->request->post('ids', ''))));
        if (!$ids) {
            return json(['status' => false, 'message' => '未选择图片']);
        }
        $images = Image::whereIn('id', $ids)->where('user_id', $user->id)->select();

        if ($mode === 'public') {
            $ok = 0; $skip = 0;
            foreach ($images as $img) {
                if ($this->checkCanPublic($user, $img, true) === '') {
                    Db::name('images')->where('id', $img->id)->update(['is_public' => 1, 'update_time' => time()]);
                    $ok++;
                } else {
                    $skip++;
                }
            }
            return json(['status' => true, 'message' => "已公开 {$ok} 张" . ($skip ? "，跳过 {$skip} 张（未过审/被下架/无权限）" : '')]);
        }
        if ($mode === 'private') {
            Db::name('images')->whereIn('id', $ids)->where('user_id', $user->id)->update(['is_public' => 0, 'update_time' => time()]);
            return json(['status' => true, 'message' => '已批量设为私密']);
        }
        return json(['status' => false, 'message' => '参数错误']);
    }

    /** 全部公开/私密：mode=public|private */
    public function allVisibility()
    {
        $user = $this->request->loginUser;
        $mode = (string)$this->request->post('mode', '');

        if ($mode === 'private') {
            Db::name('images')->where('user_id', $user->id)->where('status', Image::ST_NORMAL)
                ->update(['is_public' => 0, 'update_time' => time()]);
            return json(['status' => true, 'message' => '全部图片已设为私密']);
        }
        if ($mode === 'public') {
            $group = \app\common\model\StrategyGroup::find((int)$user->group_id);
            if (!$group || (int)$group->allow_public !== 1) {
                return json(['status' => false, 'message' => '当前策略组不允许公开图片']);
            }
            $n = Db::name('images')->where('user_id', $user->id)
                ->where('status', Image::ST_NORMAL)
                ->where('audit_status', Image::AUDIT_PASS)
                ->where('ban_public', 0)
                ->update(['is_public' => 1, 'update_time' => time()]);
            $total = (int)Db::name('images')->where('user_id', $user->id)->where('status', Image::ST_NORMAL)->count();
            return json(['status' => true, 'message' => "已公开 {$n} 张（共 {$total} 张，未过审/被下架的跳过）"]);
        }
        return json(['status' => false, 'message' => '参数错误']);
    }

    /** 自动公开开关 */
    public function autoPublic()
    {
        $user  = $this->request->loginUser;
        $group = \app\common\model\StrategyGroup::find((int)$user->group_id);
        $on    = (int)$this->request->post('on', 0) === 1;

        if ($on && (!$group || (int)$group->allow_auto_public !== 1)) {
            return json(['status' => false, 'message' => '当前策略组未开放自动公开']);
        }
        Db::name('users')->where('id', $user->id)->update(['auto_public' => $on ? 1 : 0]);
        return json(['status' => true, 'message' => $on ? '已开启：新上传且过审的图片自动公开' : '已关闭自动公开']);
    }

    /** 公开前置校验 */
    protected function checkCanPublic($user, $image, bool $needPass = true): string
    {
        $group = \app\common\model\StrategyGroup::find((int)$user->group_id);
        if (!$group || (int)$group->allow_public !== 1) {
            return '当前策略组不允许公开图片';
        }
        if ((int)($image->ban_public ?? 0) === 1) {
            return '该图已被管理员下架，不能再次公开';
        }
        if ($needPass && (int)$image->audit_status !== Image::AUDIT_PASS) {
            return '只有审核通过的图片才能公开';
        }
        return '';
    }

    public function delete(int $id)
    {
        $user = $this->request->loginUser;
        $image = Image::where('id', $id)
            ->where('user_id', $user->id)
            ->where('status', Image::ST_NORMAL)
            ->find();

        if (!$image) {
            return json(['status' => false, 'message' => '图片不存在']);
        }

        $image->save(['status' => Image::ST_DELETED, 'delete_time' => time()]);
        // 配额回退
        Db::name('users')->where('id', $user->id)
            ->update(['quota_used' => Db::raw('GREATEST(0, quota_used - ' . (int)$image->size . ')')]);
        // 同步删除存储文件
        try {
            \app\common\service\storage\StorageManager::driver((int)$image->storage_id)->delete((string)$image->path);
        } catch (\Throwable $e) {}

        return json(['status' => true, 'message' => '已删除']);
    }
}
