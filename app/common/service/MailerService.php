<?php
declare (strict_types = 1);

namespace app\common\service;

use app\common\model\Setting;
use PHPMailer\PHPMailer\PHPMailer;
use think\facade\Db;

/**
 * 邮件服务：统一 SMTP 发送 + 模板 + 日志
 *
 * 模板变量：{{site}} {{link}} {{reason}} {{amount}} {{name}}
 * 使用：MailerService::send('a@b.com', '标题', '正文HTML', 'template_key')
 */
class MailerService
{
    /** 全站通知事件（后台按勾选启用；空配置=全部启用） */
    public const EVENTS = [
        'system' => [
            'storage_alert'  => '存储告急（磁盘/配额/网盘）',
            'cron_fail'      => '计划任务连续失败',
            'site_down'      => '站点不可用',
            'cert_expire'    => 'HTTPS 证书即将到期',
        ],
        'account' => [
            'verify'         => '注册邮箱验证',
            'reset'          => '找回密码',
            'audit_reject'   => '图片审核拒绝',
            'violation'      => '违规警告 / 账号停用',
            'ban_public'     => '图片被下架',
        ],
        'trade' => [
            'vip_open'       => 'VIP 开通成功',
            'vip_expire'     => 'VIP 即将到期',
            'recharge'       => '站币充值成功',
            'sold'           => '付费图片被购买（作者）',
            'withdraw_apply' => '提现申请已提交',
            'withdraw_result'=> '提现审核结果',
            'settle'         => '分成结算',
        ],
        'report' => [
            'report_received'=> '举报已受理（举报人）',
            'report_result'  => '举报处理结果（双方）',
        ],
        'ops' => [
            'announce'       => '公告 / 版本更新',
        ],
    ];

    /** 事件是否启用（mail_events 为空 = 全部启用） */
    public static function enabled(string $event): bool
    {
        $row = \app\common\model\Setting::get('mail_events', null);
        if ($row === null || $row === '') {
            return true;   // 未配置 = 全部启用（兼容旧版）
        }
        $list = json_decode((string)$row, true);
        if (!is_array($list)) {
            return true;
        }
        return in_array($event, $list, true);   // [] = 全部关闭
    }

    /** 按事件发送（未启用直接跳过，返回 false） */
    public static function notify(string $event, string $to, string $subject, string $template, array $vars = []): bool
    {
        if (!self::enabled($event)) {
            return false;
        }
        return self::send($to, $subject, self::render($template, $vars), $event);
    }

    /** 事件扁平列表（后台勾选面板用） */
    public static function eventOptions(): array
    {
        $flat = [];
        foreach (self::EVENTS as $group => $items) {
            $flat[$group] = $items;
        }
        return $flat;
    }

    /** 发送邮件（失败仅记日志，不抛异常） */
    public static function send(string $to, string $subject, string $html, string $template = ''): bool
    {
        $to = trim($to);
        if (!filter_var($to, FILTER_VALIDATE_EMAIL)) {
            self::log($to, $subject, $template, 0, '收件地址无效');
            return false;
        }

        $host = trim((string)Setting::get('smtp_host', ''));
        $user = trim((string)Setting::get('smtp_user', ''));
        $pass = (string)Setting::get('smtp_pass', '');
        if ($host === '' || $user === '' || $pass === '') {
            self::log($to, $subject, $template, 0, 'SMTP 未配置');
            return false;
        }

        try {
            $mail = new PHPMailer(true);
            $mail->isSMTP();
            $mail->Host       = $host;
            $mail->Port       = (int)Setting::get('smtp_port', 465);
            $secure = (string)Setting::get('smtp_secure', 'ssl');
            $mail->SMTPSecure = $secure === 'tls' ? PHPMailer::ENCRYPTION_STARTTLS : ($secure === 'none' ? '' : PHPMailer::ENCRYPTION_SMTPS);
            $mail->SMTPAuth   = true;
            $mail->Username   = $user;
            $mail->Password   = $pass;
            $mail->CharSet    = 'UTF-8';
            $mail->Timeout    = 15;

            $fromAddr = trim((string)Setting::get('smtp_from_addr', '')) ?: $user;
            $mail->setFrom($fromAddr, (string)Setting::get('smtp_from_name', '逍遥图床'));
            $mail->addAddress($to);
            $mail->isHTML(true);
            $mail->Subject = $subject;
            $mail->Body    = $html;
            $mail->send();

            self::log($to, $subject, $template, 1, '');
            return true;
        } catch (\Throwable $e) {
            self::log($to, $subject, $template, 0, mb_substr($e->getMessage(), 0, 240));
            return false;
        }
    }

    /** 用内置模板发送 */
    public static function sendTemplate(string $to, string $subject, string $templateKey, array $vars = []): bool
    {
        return self::send($to, $subject, self::render($templateKey, $vars), $templateKey);
    }

    /** 内置模板渲染 */
    public static function render(string $key, array $vars = []): string
    {
        $site = (string)Setting::get('site_name', '逍遥图床');
        $vars = array_merge(['site' => $site, 'link' => '', 'reason' => '', 'amount' => '', 'name' => ''], $vars);

        $templates = [
            'verify' => '<p>{{name}} 你好，</p><p>欢迎注册 {{site}}。请点击下方链接完成邮箱验证（{{expire}} 小时内有效）：</p>'
                . '<p><a href="{{link}}">{{link}}</a></p><p>如果不是你本人操作，请忽略本邮件。</p>',
            'reset' => '<p>{{name}} 你好，</p><p>我们收到了你的密码重置请求，请点击下方链接设置新密码（{{expire}} 小时内有效）：</p>'
                . '<p><a href="{{link}}">{{link}}</a></p><p>如果不是你本人操作，请忽略本邮件。</p>',
            'vip_open' => '<p>你的 {{site}} 会员已开通成功。</p><p>套餐：{{name}}<br>金额：{{amount}} 元<br>有效期至：{{link}}</p>',
            'vip_expire' => '<p>你的 {{site}} 会员将于 {{link}} 到期，到期后将自动降回免费策略组（已上传图片保留）。</p>',
            'audit_reject' => '<p>你上传的图片未通过内容审核，已被拒绝。</p><p>原因：{{reason}}</p><p>如有疑问可联系管理员。</p>',
            'storage_alert' => '<p>{{site}} 存储告警：</p><p>{{reason}}</p><p>请及时处理，避免上传中断。</p>',
            'withdraw_result' => '<p>你的提现申请已处理。</p><p>金额：{{amount}} 元<br>状态：{{reason}}</p>',
            'violation' => '<p>你的账号因多次上传违规内容已被限制。</p><p>详情：{{reason}}</p>',
            'sold' => '<p>你公开的图片被购买：{{name}}</p><p>你获得收益 {{amount}} 站币，已入账。</p>',
            'recharge' => '<p>充值成功：{{amount}} 元，当前余额 {{name}} 站币。</p>',
            'cron_fail' => '<p>计划任务「{{name}}」连续失败。</p><p>{{reason}}</p><p>请检查宝塔计划任务与 runtime 日志。</p>',
            'cron_recover' => '<p>计划任务「{{name}}」已恢复正常执行。</p>',
            'report_received' => '<p>你的举报已受理，我们将在复核后通过邮件告知结果。</p><p>举报理由：{{reason}}</p>',
            'report_result' => '<p>举报处理结果：{{name}}</p><p>{{reason}}</p>',
            'ban_public' => '<p>你的图片「{{name}}」因{{reason}}已被下架，不能再设为公开。</p>',
        ];
        $html = $templates[$key] ?? ('<p>{{reason}}</p><p>—— {{site}}</p>');

        $expire = (string)Setting::get('verify_expire', '24');
        $vars['expire'] = $expire;
        foreach ($vars as $k => $v) {
            $html = str_replace('{{' . $k . '}}', (string)$v, $html);
        }
        // 统一外壳
        return '<div style="max-width:560px;margin:0 auto;padding:28px 24px;font:14px/1.8 -apple-system,\'Segoe UI\',Microsoft YaHei,sans-serif;color:#333;background:#fff">'
            . '<div style="font-size:17px;font-weight:700;margin-bottom:14px;color:#1a7f37">' . htmlspecialchars($site, ENT_QUOTES, 'UTF-8') . '</div>'
            . $html
            . '<hr style="border:none;border-top:1px solid #eee;margin:22px 0"><p style="color:#999;font-size:12px">本邮件由系统自动发送，请勿直接回复。</p></div>';
    }

    protected static function log(string $to, string $subject, string $template, int $status, string $error): void
    {
        try {
            Db::name('mail_logs')->insert([
                'to_email'    => mb_substr($to, 0, 120),
                'subject'     => mb_substr($subject, 0, 200),
                'template'    => $template,
                'status'      => $status,
                'error'       => $error,
                'create_time' => time(),
            ]);
        } catch (\Throwable $e) {
        }
    }
}
