<?php
declare (strict_types = 1);

namespace app\common\service;

use app\common\model\Setting;
use think\facade\Db;

/** 站币：加/扣/流水/解锁分成 */
class CreditsService
{
    /** 变动余额（正加负扣），返回新余额；余额不足抛异常 */
    public static function change(int $userId, int $amount, string $type, int $refId = 0, string $remark = ''): int
    {
        $user = Db::name('users')->where('id', $userId)->find();
        if (!$user) {
            throw new \RuntimeException('用户不存在');
        }
        $new = (int)$user['credits'] + $amount;
        if ($new < 0) {
            throw new \RuntimeException('站币余额不足');
        }
        Db::name('users')->where('id', $userId)->update(['credits' => $new]);
        Db::name('credit_logs')->insert([
            'user_id'       => $userId,
            'type'          => $type,
            'amount'        => $amount,
            'balance_after' => $new,
            'ref_id'        => $refId,
            'remark'        => mb_substr($remark, 0, 240),
            'create_time'   => time(),
        ]);
        return $new;
    }

    /** 充值入账（订单回调） */
    public static function recharge(int $userId, int $yuan, int $orderId): int
    {
        $rate = max(1, (int)Setting::get('credits_per_yuan', 10));
        $balance = self::change($userId, $yuan * $rate, 'recharge', $orderId, "充值 {$yuan} 元（1:{$rate}）");
        try {
            $u = Db::name('users')->where('id', $userId)->find();
            if ($u) {
                MailerService::notify('recharge', (string)$u['email'],
                    '充值成功 · ' . (string)Setting::get('site_name', '逍遥图床'),
                    'recharge', ['amount' => (string)$yuan, 'name' => (string)$balance]);
            }
        } catch (\Throwable $e) {
        }
        return $balance;
    }

    /**
     * 解锁付费图：扣买家 + 给作者分成
     * @return array ['ok'=>bool,'message'=>string]
     */
    public static function unlock(int $imageId, int $buyerId): array
    {
        $img = Db::name('images')->where('id', $imageId)->find();
        if (!$img) {
            return ['ok' => false, 'message' => '图片不存在'];
        }
        if ((int)$img['is_public'] !== 1) {
            return ['ok' => false, 'message' => '图片未公开'];
        }
        if ((int)$img['user_id'] === $buyerId) {
            return ['ok' => false, 'message' => '这是你自己的图片'];
        }
        $exists = Db::name('unlocks')->where('image_id', $imageId)->where('user_id', $buyerId)->find();
        if ($exists) {
            return ['ok' => false, 'message' => '已解锁过'];
        }
        $price = (int)$img['price_credits'];
        if ($price <= 0) {
            return ['ok' => false, 'message' => '该图片无解锁价格（可直接查看）'];
        }

        // 分成比例：统一 or 按来源（AI 生成 vs 用户自制）
        $splitMode = (string)Setting::get('revenue_split_mode', '0');
        $share = (int)Setting::get('share_user', 70);
        if ($splitMode === '1' && in_array((string)$img['origin'], ['user_gen', 'gen_bot'], true)) {
            $share = (int)Setting::get('share_ai', 50);
        }
        $share = max(0, min(100, $share));
        $authorShare = (int)floor($price * $share / 100);

        try {
            Db::startTrans();
            self::change($buyerId, -$price, 'unlock', $imageId, '解锁图片 #' . $imageId);
            if ($authorShare > 0) {
                self::change((int)$img['user_id'], $authorShare, 'share', $imageId, '图片 #' . $imageId . ' 被解锁，分成 ' . $share . '%');
                Db::name('users')->where('id', (int)$img['user_id'])->inc('credits_total', $authorShare)->update();
            }
            Db::name('unlocks')->insert([
                'image_id' => $imageId, 'user_id' => $buyerId, 'price' => $price,
                'author_share' => $authorShare, 'create_time' => time(),
            ]);
            Db::commit();
        } catch (\Throwable $e) {
            Db::rollback();
            return ['ok' => false, 'message' => $e->getMessage()];
        }

        // 通知作者（站内交易）
        try {
            $author = Db::name('users')->where('id', (int)$img['user_id'])->find();
            if ($author) {
                MailerService::notify('sold', (string)$author['email'],
                    '你的图片被解锁 · ' . (string)Setting::get('site_name', '逍遥图床'),
                    'sold', ['name' => (string)$img['name'], 'amount' => (string)$authorShare]);
            }
        } catch (\Throwable $e) {
        }

        return ['ok' => true, 'message' => '解锁成功，已获得原图权限'];
    }

    /** 是否已解锁 */
    public static function unlocked(int $imageId, int $userId): bool
    {
        if ($userId <= 0) {
            return false;
        }
        return (bool)Db::name('unlocks')->where('image_id', $imageId)->where('user_id', $userId)->find();
    }
}
