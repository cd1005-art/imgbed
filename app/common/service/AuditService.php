<?php
declare (strict_types = 1);

namespace app\common\service;

use app\common\model\Image;
use app\common\model\Setting;
use app\common\model\StrategyGroup;
use app\common\service\audit\AuditDriverInterface;
use app\common\service\audit\LlmVlmDriver;
use app\common\service\audit\LocalNsfwDriver;
use think\facade\Db;
use think\facade\Log;

/**
 * 审核服务：按策略组/全局驱动机审，结果写 audit_logs，拒绝自动冻结
 *
 * 一期为同步调用（上传响应内完成）；驱动 none 直接放行零开销。
 * 二期换 think-queue 异步化（images.audit_status=0 待审队列已就绪）。
 */
class AuditService
{
    /** 审核一张图：返回 ['result' => ..., 'score' => ..., 'detail' => ...] */
    public static function audit(Image $image, string $localPath, string $mime): array
    {
        $driverKey = self::driverKey($image);

        if ($driverKey === '' || $driverKey === 'none') {
            $result = ['result' => 'pass', 'score' => 0, 'detail' => '未启用审核'];
        } else {
            try {
                $driver = self::makeDriver($driverKey);
                $result = $driver->audit($localPath, $mime);
            } catch (\Throwable $e) {
                Log::record('审核驱动异常：' . $e->getMessage(), 'error');
                $result = ['result' => 'review', 'score' => 0, 'detail' => '驱动异常：' . $e->getMessage()];
            }
        }

        self::applyResult($image, $driverKey, $result);
        return $result;
    }

    /** 策略组驱动优先，其次全局设置 */
    protected static function driverKey(Image $image): string
    {
        $group = StrategyGroup::find((int)$image->group_id);
        $groupDriver = $group ? (string)$group->audit_driver : '';
        return $groupDriver !== '' ? $groupDriver : (string)Setting::get('default_audit_driver', 'none');
    }

    /** 驱动工厂（后台配置注入） */
    protected static function makeDriver(string $key): AuditDriverInterface
    {
        return match ($key) {
            'local_nsfw' => new LocalNsfwDriver(['endpoint' => (string)Setting::get('nsfw_endpoint', '')]),
            'llm_vlm'    => new \app\common\service\audit\MultiModelAuditDriver(),   // 多模型链：按排序优先，失败自动切换，全部失败转人工
            // 'aliyun' => new AliyunDriver(...),     // 二期
            // 'tencent' => new TencentDriver(...),   // 二期
            default => throw new \RuntimeException('未知审核驱动: ' . $key),
        };
    }

    /** 落库：audit_logs + images 审核态，拒绝自动冻结 */
    protected static function applyResult(Image $image, string $driver, array $result): void
    {
        $statusMap = ['pass' => Image::AUDIT_PASS, 'reject' => Image::AUDIT_REJECT, 'review' => Image::AUDIT_REVIEW];
        $auditStatus = $statusMap[$result['result']] ?? Image::AUDIT_REVIEW;

        try {
            Db::name('audit_logs')->insert([
                'image_id'    => $image->id,
                'driver'      => $driver,
                'result'      => $result['result'],
                'score'       => (int)$result['score'],
                'detail'      => (string)$result['detail'],
                'create_time' => time(),
            ]);
        } catch (\Throwable $e) {
            // 审核日志失败不阻断
        }

        $update = [
            'audit_status' => $auditStatus,
            'audit_score'  => (int)$result['score'],
            'audit_time'   => time(),
            'update_time'  => time(),
        ];
        if ($result['result'] === 'reject') {
            $update['status'] = Image::ST_FROZEN;   // 拒绝即冻结，外链断
        }

        // 标签（仅通过时入库，复审/拒绝不公示）
        if ($result['result'] === 'pass' && !empty($result['tags'])) {
            try {
                \app\common\service\TagService::attach((int)$image->id, (array)$result['tags'], 'auto');
            } catch (\Throwable $e) {
            }
        }

        // 自动公开：用户开启 + 策略组允许 + 审核通过
        if ($result['result'] === 'pass' && (int)$image->user_id > 0) {
            try {
                $user  = Db::name('users')->where('id', $image->user_id)->find();
                $group = StrategyGroup::find((int)$image->group_id);
                if ($user && (int)($user['auto_public'] ?? 0) === 1
                    && $group && (int)($group->allow_public ?? 1) === 1
                    && (int)($group->allow_auto_public ?? 0) === 1) {
                    $update['is_public'] = 1;
                }
            } catch (\Throwable $e) {
            }
        }

        Db::name('images')->where('id', $image->id)->update($update);
    }
}
