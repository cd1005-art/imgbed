<?php
declare (strict_types = 1);

namespace app\common\service;

use app\common\model\Setting;
use think\facade\Db;

/** 站币：加/扣/流水/解锁分成 */
class CreditsService
{
    /** 变动余额（正加负扣），返回新余额；余额不足抛异常 */
    /**
     * 站币变动
     * @param string $account credits=充值余额（仅消费、不可提现） | earnings=收益余额（可提现、可消费）
     */
    public static function change(int $userId, int $amount, string $type, int $refId = 0, string $remark = '', string $account = 'credits'): int
    {
        $account = $account === 'earnings' ? 'earnings' : 'credits';
        $user = Db::name('users')->where('id', $userId)->find();
        if (!$user) {
            throw new \RuntimeException('用户不存在');
        }
        $new = (int)($user[$account] ?? 0) + $amount;
        if ($new < 0) {
            throw new \RuntimeException($account === 'earnings' ? '可提现收益不足' : '站币余额不足');
        }
        Db::name('users')->where('id', $userId)->update([$account => $new]);
        Db::name('credit_logs')->insert([
            'user_id'       => $userId,
            'type'          => $type,
            'amount'        => $amount,
            'balance_after' => $new,
            'account'       => $account,
            'ref_id'        => $refId,
            'remark'        => mb_substr($remark, 0, 240),
            'create_time'   => time(),
        ]);
        return $new;
    }

    /**
     * 支付：先扣充值余额，不足部分扣收益余额
     * @return array ['ok'=>bool,'message'=>string]
     */
    public static function payFrom(int $userId, int $amount, string $type, int $refId = 0, string $remark = ''): array
    {
        $user = Db::name('users')->where('id', $userId)->find();
        if (!$user) {
            return ['ok' => false, 'message' => '用户不存在'];
        }
        $credits  = (int)($user['credits'] ?? 0);
        $earnings = (int)($user['earnings'] ?? 0);
        if ($credits + $earnings < $amount) {
            return ['ok' => false, 'message' => '余额不足（可用 ' . ($credits + $earnings) . ' 币）'];
        }
        $fromCredits = min($credits, $amount);
        $fromEarnings = $amount - $fromCredits;
        if ($fromCredits > 0) {
            self::change($userId, -$fromCredits, $type, $refId, $remark . ($fromEarnings > 0 ? '（充值余额部分）' : ''), 'credits');
        }
        if ($fromEarnings > 0) {
            self::change($userId, -$fromEarnings, $type, $refId, $remark . '（收益部分）', 'earnings');
        }
        return ['ok' => true, 'message' => 'ok'];
    }

    /** 充值入账（订单回调） */
    public static function recharge(int $userId, int $yuan, int $orderId): int
    {
        $rate = max(1, (int)Setting::get('credits_per_yuan', 10));
        $pct  = max(0, (int)Setting::get('recharge_bonus_pct', 0));
        $coins = (int)floor($yuan * $rate * (1 + $pct / 100));

        // 档位额外赠送
        $planBonus = 0;
        $order = Db::name('orders')->where('id', $orderId)->find();
        if ($order && (int)($order['plan_id'] ?? 0) > 0) {
            $planBonus = (int)Db::name('credit_plans')->where('id', (int)$order['plan_id'])->value('bonus');
        }
        $coins += $planBonus;

        $remark = "充值 {$yuan} 元（1:{$rate}" . ($pct > 0 ? " +活动{$pct}%" : '') . ($planBonus > 0 ? " +档位赠{$planBonus}" : '') . '）';
        $balance = self::change($userId, $coins, 'recharge', $orderId, $remark);
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
            $pay = self::payFrom($buyerId, $price, 'unlock', $imageId, '解锁图片 #' . $imageId);
            if (!$pay['ok']) {
                Db::rollback();
                return ['ok' => false, 'message' => $pay['message']];
            }
            if ($authorShare > 0) {
                // 作者分成进「收益余额」：可提现，也可用于消费
                self::change((int)$img['user_id'], $authorShare, 'share', $imageId, '图片 #' . $imageId . ' 被解锁，分成 ' . $share . '%', 'earnings');
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
