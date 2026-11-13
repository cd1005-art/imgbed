<?php
declare (strict_types = 1);

namespace app\admin\controller;

use app\admin\controller\Base;
use app\common\service\CreditsService;
use think\facade\Db;

/** 提现管理：审核 / 标记打款 / 驳回退币 */
class WithdrawManage extends Base
{
    public function index()
    {
        $page   = max(1, (int)$this->request->param('page', 1));
        $status = trim((string)$this->request->param('status', ''));
        $uw     = trim((string)$this->request->param('uw', ''));
        $dfrom  = trim((string)$this->request->param('dfrom', ''));
        $dto    = trim((string)$this->request->param('dto', ''));

        $q = Db::name('withdrawals')->alias('w')
            ->leftJoin('img_users u', 'u.id = w.user_id')
            ->field('w.*, u.email, u.nickname');
        if ($status !== '') {
            $q->where('w.status', $status);
        }
        if ($uw !== '') {
            if (ctype_digit($uw)) {
                $q->where('w.user_id', (int)$uw);
            } else {
                $uids = Db::name('users')->where(function ($qq) use ($uw) {
                    $qq->where('email', 'like', '%' . $uw . '%')->whereOr('nickname', 'like', '%' . $uw . '%');
                })->column('id');
                $q->whereIn('w.user_id', $uids ?: [0]);
            }
        }
        if ($dfrom !== '') { $q->where('w.create_time', '>=', strtotime($dfrom . ' 00:00:00')); }
        if ($dto !== '')   { $q->where('w.create_time', '<=', strtotime($dto . ' 23:59:59')); }
        $list  = $q->order('w.id', 'desc')->page($page, 30)->select()->toArray();
        $total = (int)Db::name('withdrawals')->when($status !== '', function ($qq) use ($status) { $qq->where('status', $status); })->count();

        return $this->adminView('admin/withdraw', [
            'page'    => 'withdraw',
            'list'    => $list,
            'status'  => $status,
            'uw'      => $uw,
            'dfrom'   => $dfrom,
            'dto'     => $dto,
            'total'   => $total,
            'page_no' => $page,
            'pages'   => (int)ceil($total / 30) ?: 1,
        ]);
    }

    /** 删除提现记录（仅已处理：paid/rejected，财务安全） */
    public function delete()
    {
        $ids = array_filter(array_map('intval', explode(',', (string)$this->request->post('ids', ''))));
        if (!$ids) {
            return json(['status' => false, 'message' => '未选择记录']);
        }
        $deletable = Db::name('withdrawals')->whereIn('id', $ids)->whereIn('status', ['paid', 'rejected'])->column('id');
        $skip = count($ids) - count($deletable);
        $n = $deletable ? Db::name('withdrawals')->whereIn('id', $deletable)->delete() : 0;
        return json([
            'status'  => true,
            'message' => "已删除 {$n} 条" . ($skip > 0 ? "；{$skip} 条未处理记录已跳过（请先审核）" : ''),
        ]);
    }

    public function approve(int $id)
    {
        Db::name('withdrawals')->where('id', $id)->where('status', 'pending')
            ->update(['status' => 'approved', 'handled_by' => (int)session('admin_id'), 'update_time' => time()]);
        return json(['status' => true, 'message' => '已通过，待打款']);
    }

    /** 标记已打款（填凭证号） */
    public function paid(int $id)
    {
        $voucher = trim((string)$this->request->post('voucher', ''));
        $w = Db::name('withdrawals')->where('id', $id)->find();
        if (!$w) {
            return json(['status' => false, 'message' => '记录不存在']);
        }
        Db::name('withdrawals')->where('id', $id)->update([
            'status' => 'paid', 'voucher_no' => mb_substr($voucher, 0, 80),
            'handled_by' => (int)session('admin_id'), 'update_time' => time(),
        ]);
        // 通知用户
        try {
            $u = Db::name('users')->where('id', (int)$w['user_id'])->find();
            if ($u) {
                \app\common\service\MailerService::notify('withdraw_result', (string)$u['email'], '提现已打款 · ' . (string)\app\common\model\Setting::get('site_name', '逍遥图床'),
                    'withdraw_result', ['amount' => (string)$w['real_amount'], 'reason' => '已打款']);
            }
        } catch (\Throwable $e) {
        }
        return json(['status' => true, 'message' => '已标记打款']);
    }

    /** 驳回并退币 */
    public function reject(int $id)
    {
        $w = Db::name('withdrawals')->where('id', $id)->find();
        if (!$w || (string)$w['status'] === 'paid') {
            return json(['status' => false, 'message' => '状态不允许驳回']);
        }
        $rate = max(1, (int)\app\common\model\Setting::get('credits_per_yuan', 10));
        $need = (int)$w['amount'] * $rate;
        try {
            Db::startTrans();
            Db::name('withdrawals')->where('id', $id)->update([
                'status' => 'rejected', 'handled_by' => (int)session('admin_id'),
                'remark' => (string)$this->request->post('remark', ''),
                'update_time' => time(),
            ]);
            CreditsService::change((int)$w['user_id'], $need, 'withdraw_refund', $id, '提现驳回退回');
            Db::commit();
        } catch (\Throwable $e) {
            Db::rollback();
            return json(['status' => false, 'message' => $e->getMessage()]);
        }
        return json(['status' => true, 'message' => '已驳回并退回站币']);
    }
}
