<?php
declare (strict_types = 1);

namespace app\admin\controller;

use app\admin\controller\Base;
use think\facade\Db;

/** 站币流水管理 */
class CreditManage extends Base
{
    /** 变动类型中文 */
    public const TYPES = [
        'recharge'        => '充值',
        'unlock'          => '解锁付费图（支出）',
        'author_income'   => '作者收益',
        'withdraw'        => '提现扣减',
        'withdraw_refund' => '提现驳回退回',
        'vip_settle'      => 'VIP 分成结算',
        'admin_adjust'    => '管理员调整',
        'gen_reward'      => '造图奖励',
    ];

    /** 删除流水记录（单条/批量；财务记录，二次确认后执行） */
    public function delete()
    {
        $ids = array_filter(array_map('intval', explode(',', (string)$this->request->post('ids', ''))));
        if (!$ids) {
            return json(['status' => false, 'message' => '未选择记录']);
        }
        $n = Db::name('credit_logs')->whereIn('id', $ids)->delete();
        return json(['status' => true, 'message' => "已删除 {$n} 条流水（不影响用户当前余额）"]);
    }

    public function index()
    {
        $page = max(1, (int)$this->request->param('page', 1));
        $uw   = trim((string)$this->request->param('uw', ''));
        $type = trim((string)$this->request->param('type', ''));
        $dfrom = trim((string)$this->request->param('dfrom', ''));
        $dto   = trim((string)$this->request->param('dto', ''));

        $build = function () use ($uw, $type, $dfrom, $dto) {
            $q = Db::name('credit_logs');
            if ($uw !== '') {
                if (ctype_digit($uw)) {
                    $q->where('user_id', (int)$uw);
                } else {
                    $uids = Db::name('users')->where(function ($qq) use ($uw) {
                        $qq->where('email', 'like', '%' . $uw . '%')->whereOr('nickname', 'like', '%' . $uw . '%');
                    })->column('id');
                    $q->whereIn('user_id', $uids ?: [0]);
                }
            }
            if ($type !== '') { $q->where('type', $type); }
            if ($dfrom !== '') { $q->where('create_time', '>=', strtotime($dfrom . ' 00:00:00')); }
            if ($dto !== '')   { $q->where('create_time', '<=', strtotime($dto . ' 23:59:59')); }
            return $q;
        };

        $list  = $build()->order('id', 'desc')->page($page, 40)->select()->toArray();
        $total = $build()->count();

        // 用户昵称
        $uids = [];
        foreach ($list as $r) { $uids[(int)$r['user_id']] = (int)$r['user_id']; }
        $uMap = [];
        if ($uids) {
            foreach (Db::name('users')->whereIn('id', array_values($uids))->field('id,nickname,email,status')->select()->toArray() as $u) {
                $label = $u['nickname'] !== '' ? $u['nickname'] : $u['email'];
                if ((int)$u['status'] === 3 || strpos((string)$u['email'], '#del') !== false) { $label .= '（已删除）'; }
                $uMap[(int)$u['id']] = $label;
            }
        }
        $sumIn  = (int)$build()->where('amount', '>', 0)->sum('amount');
        $sumOut = (int)$build()->where('amount', '<', 0)->sum('amount');

        return $this->adminView('admin/credit', [
            'page'    => 'credit',
            'list'    => $list,
            'total'   => $total,
            'uw'      => $uw,
            'type'    => $type,
            'dfrom'   => $dfrom,
            'dto'     => $dto,
            'page_no' => $page,
            'pages'   => (int)ceil($total / 40) ?: 1,
            'types'   => self::TYPES,
            'user_map' => $uMap,
            'sum_in'  => $sumIn,
            'sum_out' => $sumOut,
        ]);
    }
}
