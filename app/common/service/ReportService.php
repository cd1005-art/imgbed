<?php
declare (strict_types = 1);

namespace app\common\service;

use app\common\model\Image;
use app\common\model\Setting;
use think\facade\Db;

/**
 * 举报处理：AI 初审 → 成立自动下架（仅下架 / 下架+冻结，可配）
 */
class ReportService
{
    /**
     * 提交举报并做 AI 初审
     * @return array ['status'=>bool,'message'=>string,'verdict'=>string]
     */
    public static function submit(int $imageId, int $reporterId, string $reason): array
    {
        $img = Image::find($imageId);
        if (!$img || (int)$img->status !== Image::ST_NORMAL) {
            return ['status' => false, 'message' => '图片不存在', 'verdict' => ''];
        }
        if ((int)$img->user_id === $reporterId) {
            return ['status' => false, 'message' => '不能举报自己的图片', 'verdict' => ''];
        }
        // 去重：同人同图 24 小时内一次
        $dup = Db::name('reports')->where('image_id', $imageId)->where('reporter_id', $reporterId)
            ->where('create_time', '>', time() - 86400)->find();
        if ($dup) {
            return ['status' => false, 'message' => '已举报过，处理中', 'verdict' => ''];
        }

        $reportId = (int)Db::name('reports')->insertGetId([
            'image_id'    => $imageId,
            'reporter_id' => $reporterId,
            'reason'      => mb_substr($reason, 0, 500),
            'status'      => 'pending',
            'create_time' => time(),
            'update_time' => time(),
        ]);

        // 通知举报人：已受理
        try {
            $reporter = Db::name('users')->where('id', $reporterId)->find();
            if ($reporter) {
                MailerService::notify('report_received', (string)$reporter['email'],
                    '举报已受理 · ' . (string)Setting::get('site_name', '逍遥图床'),
                    'report_received', ['reason' => mb_substr($reason, 0, 140)]);
            }
        } catch (\Throwable $e) {
        }

        // AI 初审
        $verdict = 'review';
        $detail = 'AI 初审未给出结论';
        try {
            $row = Db::name('storage_configs')->where('id', (int)$img->storage_id)->find();
            $cfg = $row ? json_decode((string)$row['config'], true) : [];
            $local = null;
            if ($row && $row['driver'] === 'local') {
                $rootRel = trim(str_replace('\\', '/', (string)($cfg['root'] ?? 'storage/images')), '/');
                $p = rtrim(public_path(), '/\\') . '/' . $rootRel . '/' . ltrim((string)$img->path, '/');
                if (@is_file($p)) {
                    $local = $p;
                }
            }
            if ($local) {
                $res = AuditService::audit($img, $local, 'image/jpeg');
                $verdict = (string)$res['result'];
                $detail = (string)$res['detail'];
            } else {
                $detail = '非本地存储，AI 无法直接读取（转人工）';
            }
        } catch (\Throwable $e) {
            $detail = 'AI 初审异常：' . mb_substr($e->getMessage(), 0, 150);
        }

        // 判定：模型 reject → 举报成立（自动下架）；pass → 驳回；review → 人工
        if ($verdict === 'reject') {
            self::ban($img, '举报成立（AI）：' . $detail, 'report');
            Db::name('reports')->where('id', $reportId)->update([
                'status' => 'ai_confirmed', 'ai_verdict' => 'reject', 'ai_detail' => mb_substr($detail, 0, 400), 'update_time' => time(),
            ]);
            return ['status' => true, 'message' => '举报已受理，经 AI 复核确认违规，已下架处理', 'verdict' => 'reject'];
        }
        if ($verdict === 'pass') {
            Db::name('reports')->where('id', $reportId)->update([
                'status' => 'ai_rejected', 'ai_verdict' => 'pass', 'ai_detail' => mb_substr($detail, 0, 400), 'update_time' => time(),
            ]);
            return ['status' => true, 'message' => '经 AI 复核未发现违规，已驳回', 'verdict' => 'pass'];
        }

        Db::name('reports')->where('id', $reportId)->update([
            'status' => 'pending', 'ai_verdict' => 'review', 'ai_detail' => mb_substr($detail, 0, 400), 'update_time' => time(),
        ]);
        return ['status' => true, 'message' => '举报已提交，等待人工复核', 'verdict' => 'review'];
    }

    /** 下架处理（按 report_action 配置：仅下架 / 下架+冻结） */
    public static function ban(Image $img, string $reason, string $source = 'report'): void
    {
        $action = (string)Setting::get('report_action', 'public_only');
        $update = [
            'is_public'   => 0,
            'ban_public'  => 1,       // 用户不可再次公开（管理员可解除）
            'update_time' => time(),
        ];
        if ($action === 'freeze') {
            $update['status'] = Image::ST_FROZEN;   // 等同于删除（外链断）
        }
        Db::name('images')->where('id', $img->id)->update($update);

        FingerprintService::ban((int)$img->id, $reason, $source);

        // 违规计数 + 自动停用
        $uid = (int)$img->user_id;
        if ($uid > 0) {
            Db::name('users')->where('id', $uid)->inc('violation_count')->update();
            $u = Db::name('users')->where('id', $uid)->find();
            $limit = max(1, (int)Setting::get('violation_autoban', 3));
            if ($u && (int)$u['violation_count'] >= $limit && (int)$u['status'] === 1) {
                Db::name('users')->where('id', $uid)->update(['status' => 2, 'update_time' => time()]);
                MailerService::notify('violation', (string)$u['email'], '账号已被限制 · ' . (string)Setting::get('site_name', '逍遥图床'),
                    'violation', ['name' => (string)$u['nickname'], 'reason' => '累计 ' . (int)$u['violation_count'] . ' 次违规上传']);
            }
        }
    }
}
