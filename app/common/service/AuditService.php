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
        // 白名单前置：人工复审通过过的图（SHA1/ahash 命中）直接放行，不再调用模型
        $approved = ApprovedFingerprintService::match($localPath);
        if ($approved !== null) {
            $result = [
                'result' => 'pass',
                'score'  => 100,
                'detail' => (string)($approved['note'] ?? '') !== '' ? (string)$approved['note'] : '白名单命中（人工已复审通过）',
            ];
            self::applyResult($image, 'whitelist', $result);
            return $result;
        }

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
    /** 审核描述 → 图片标题：去模型前缀、去首尾标点、限 60 字，太短则保留原名 */
    protected static function cleanTitle(string $detail, string $fallback): string
    {
        $t = preg_replace('/^\s*\[[^\]]{0,40}\]\s*/u', '', $detail);
        // review 类判断语：从"需/建议/待/疑似…复核(确认/判断/人工)"处整段截断
        $parts = preg_split('/[,，。;；]?\s*(需|建议|待|疑似)[^,，。;；]{0,30}(复核|确认|判断|人工|审核|关注)/u', (string)$t);
        if (!empty($parts[0])) {
            $t = $parts[0];
        }
        // 「可能被联想为…」「可能涉及…」等主观推测从句
        $t = preg_replace('/[,，]\s*[^,，。;；]{0,8}(可能|或许|疑似)[^,，。;；]{0,30}(联想|涉及|关联|引起|造成)[^,，。;；]{0,30}/u', '', (string)$t);
        // 去掉审核模型爱加的结论尾巴（措辞不固定，组合规则 + 多轮剥离）
        // 例：，内容正常无害 / ，无任何有害内容 / ，属正常工业机械摄影，无色情暴恐…有害内容
        for ($pass = 0; $pass < 3; $pass++) {
            $before = (string)$t;
            $t = preg_replace(
                '/[,，。;；][^,，。;；]{0,16}(无[^,，。;；]{0,14}(违规|有害|不良|敏感|风险|内容)|内容(正常|合规|健康|无害|安全)|未发现[^,，。;；]{0,6}(违规|问题)|审核通过|符合[^,，。;；]{0,6}(规范|要求|规定))[^。]{0,10}$/u',
                '',
                (string)$t
            );
            // 「，属正常XX」「，正常艺术摄影」「，画面温馨正常」等描述性尾巴
            $t = preg_replace('/[,，。;；][^,，。;；]{0,10}(属)?(正常|常规)[^,，。;；]{0,24}$/u', '', (string)$t);
            if ($t === $before) {
                break;
            }
        }
        $t = preg_replace('/^[\s\p{P}\p{S}]+/u', '', (string)$t);
        $t = preg_replace('/[\s\p{P}\p{S}]+$/u', '', (string)$t);
        $t = trim((string)$t);
        if ($t === '' || mb_strlen($t) < 4) {
            return $fallback;
        }
        return mb_substr($t, 0, 60);
    }

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

        // 标题：优先用模型单独输出的 title（prompt 已要求它只描述画面、不含判定语）
        // 模型没给 title 时，才回退到"从 reason 里正则剥离"的老办法
        if (in_array($result['result'], ['pass', 'review'], true)) {
            $title = trim((string)($result['title'] ?? ''));
            if ($title !== '') {
                // 双保险：万一模型仍在 title 里写了判定语，再过一次清洗
                $title = self::cleanTitle($title, $title);
            } else {
                $title = self::cleanTitle((string)($result['detail'] ?? ''), '');
            }
            if ($title !== '' && $title !== (string)$image->name) {
                $update['name'] = $title;
            }
        }
        if ($result['result'] === 'reject') {
            // 拒绝后的处置方式可配：freeze=冻结外链（默认）/ only_mark=仅标记不冻结
            if ((string)Setting::get('audit_reject_action', 'freeze') !== 'only_mark') {
                $update['status'] = Image::ST_FROZEN;
            }

            // 违规累计：达到上限后按设置处置上传者
            try {
                $limit  = (int)Setting::get('violation_limit', 0);      // 0=不统计
                $action = (string)Setting::get('violation_action', 'ban_user');
                if ($limit > 0 && (int)$image->user_id > 0) {
                    Db::name('users')->where('id', (int)$image->user_id)->inc('violation_count')->update();
                    $u = Db::name('users')->where('id', (int)$image->user_id)->find();
                    if ($u && (int)($u['violation_count'] ?? 0) >= $limit && $action !== 'none') {
                        if ($action === 'ban_user') {
                            Db::name('users')->where('id', (int)$image->user_id)->update(['status' => 0]);
                        } elseif ($action === 'freeze_all') {
                            Db::name('users')->where('id', (int)$image->user_id)->update(['status' => 0]);
                        }
                        Db::name('audit_logs')->insert([
                            'image_id'    => (int)$image->id,
                            'driver'      => 'system',
                            'result'      => 'reject',
                            'score'       => 0,
                            'detail'      => '违规累计 ' . (int)$u['violation_count'] . ' 次，处置：' . $action,
                            'create_time' => time(),
                        ]);
                    }
                }
            } catch (\Throwable $e) {
            }

            // 审核拒绝通知上传者
            try {
                if ((int)$image->user_id > 0) {
                    $u = Db::name('users')->where('id', $image->user_id)->find();
                    if ($u) {
                        MailerService::notify('audit_reject', (string)$u['email'],
                            '图片审核未通过 · ' . (string)Setting::get('site_name', '逍遥图床'),
                            'audit_reject', [
                                'name'   => (string)$u['nickname'],
                                'reason' => mb_substr((string)($result['detail'] ?? '内容不合规'), 0, 140),
                            ]);
                    }
                }
            } catch (\Throwable $e) {
            }
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
