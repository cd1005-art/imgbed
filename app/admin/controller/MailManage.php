<?php
declare (strict_types = 1);

namespace app\admin\controller;

use app\admin\controller\Base;
use app\common\model\Setting;
use app\common\service\MailerService;
use think\facade\Db;

/** 邮件设置：SMTP 配置 + 通知开关 + 阈值 + 测试发送 */
class MailManage extends Base
{
    public function index()
    {
        // 日志筛选：收件人 / 事件模板 / 状态 / 日期范围
        $lto      = trim((string)$this->request->param('lto', ''));
        $ltpl     = trim((string)$this->request->param('ltpl', ''));
        $lstatus  = (string)$this->request->param('lstatus', '');
        $ldfrom   = trim((string)$this->request->param('ldfrom', ''));
        $ldto     = trim((string)$this->request->param('ldto', ''));

        $q = Db::name('mail_logs');
        if ($lto !== '')    { $q->where('to_email', 'like', '%' . $lto . '%'); }
        if ($ltpl !== '')   { $q->where('template', $ltpl); }
        if ($lstatus !== '') { $q->where('status', (int)$lstatus); }
        if ($ldfrom !== '') { $q->where('create_time', '>=', strtotime($ldfrom . ' 00:00:00')); }
        if ($ldto !== '')   { $q->where('create_time', '<=', strtotime($ldto . ' 23:59:59')); }

        $logs  = $q->order('id', 'desc')->limit(100)->select()->toArray();
        $total = (int)Db::name('mail_logs')->count();
        $filtered = count($logs);
        $templates = Db::name('mail_logs')->distinct(true)->column('template');

        $raw = (string)Setting::get('mail_events', '');
        $enabled = $raw === '' ? null : (json_decode($raw, true) ?: []);
        $allEvents = MailerService::eventOptions();
        $flat = [];
        foreach ($allEvents as $g => $items) {
            foreach ($items as $k => $label) {
                $flat[$k] = $label;
            }
        }
        // 空配置视为全开
        if ($enabled === null) {
            $enabled = array_keys($flat);
        }

        return $this->adminView('admin/mail', [
            'page'     => 'mail',
            'settings' => Setting::allSettings(),
            'logs'     => $logs,
            'total'    => $total,
            'events'   => $allEvents,
            'enabled_events' => $enabled,
            'lto' => $lto, 'ltpl' => $ltpl, 'lstatus' => $lstatus,
            'ldfrom' => $ldfrom, 'ldto' => $ldto,
            'templates' => array_values(array_filter($templates)),
            'filtered'  => $filtered,
        ]);
    }

    public function save()
    {
        $keys = [
            'smtp_host', 'smtp_port', 'smtp_secure', 'smtp_user', 'smtp_pass',
            'smtp_from_name', 'smtp_from_addr', 'admin_mail_to', 'verify_mode',
            'alert_unit', 'alert_disk_left_pct', 'alert_quota_left_pct', 'alert_space_left_pct',
        ];
        $all = $this->request->post();
        $saved = 0;
        foreach ($keys as $k) {
            if (array_key_exists($k, $all)) {
                Setting::saveKey($k, (string)$all[$k]);
                $saved++;
            }
        }
        // 验证策略（下拉并入通知卡片）：off / register / lazy
        if (isset($all['verify_policy'])) {
            $vp = (string)$all['verify_policy'];
            if (in_array($vp, ['off', 'register', 'lazy'], true)) {
                Setting::saveKey('verify_mode', $vp === 'off' ? 'none' : $vp);
            }
            $saved++;
        }

        // 通知开关矩阵（mail_events JSON）：events_form 标记确保"全不勾=全关"也能保存
        if (isset($all['events_form'])) {
            $ev = (isset($all['mail_events']) && is_array($all['mail_events']))
                ? array_values(array_filter($all['mail_events'], 'is_string'))
                : [];
            Setting::saveKey('mail_events', json_encode($ev, JSON_UNESCAPED_UNICODE));
            $saved++;
        }
        return json(['status' => true, 'message' => "已保存 {$saved} 项"]);
    }

    /** 删除日志（单条或批量，ids 逗号分隔） */
    public function deleteLogs()
    {
        $ids = array_filter(array_map('intval', explode(',', (string)$this->request->post('ids', ''))));
        if (!$ids) {
            return json(['status' => false, 'message' => '未选择记录']);
        }
        $n = Db::name('mail_logs')->whereIn('id', $ids)->delete();
        return json(['status' => true, 'message' => "已删除 {$n} 条"]);
    }

    /** 清空全部日志 */
    public function clearLogs()
    {
        $n = Db::name('mail_logs')->where('id', '>', 0)->delete();
        return json(['status' => true, 'message' => "已清空 {$n} 条"]);
    }

    /** 测试发送 */
    public function test()
    {
        $to = trim((string)$this->request->post('to', ''));
        if ($to === '') {
            $to = trim((string)Setting::get('admin_mail_to', ''));
        }
        if ($to === '') {
            return json(['status' => false, 'message' => '请填写测试收件地址']);
        }
        $ok = MailerService::send(
            $to,
            '【测试】' . (string)Setting::get('site_name', '逍遥图床') . ' 邮件配置验证',
            '<p>这是一封测试邮件。</p><p>收到本邮件说明 SMTP 配置正确，通知功能可用。</p>',
            'test'
        );
        return $ok
            ? json(['status' => true, 'message' => '发送成功，请查收（含垃圾箱）'])
            : json(['status' => false, 'message' => '发送失败，请到「发送日志」查看具体错误']);
    }
}
