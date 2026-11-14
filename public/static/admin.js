/* admin.js — 后台交互（依赖 imgbed.js 的 toast/copyText） */
(function () {
  'use strict';

  var boot = window.pageBoot;
  window.pageBoot = function (page) {
    if (boot) boot(page);
    var map = {
      adminImage: function () { adminImage(); adminImageExtras(); }, adminUser: adminUser, adminGroup: adminGroup,
      adminStorage: adminStorage, adminPayment: adminPayment,
      adminAd: adminAd, adminToken: adminToken, adminSite: adminSite, adminAudit: adminAudit,
      adminModel: adminModel, adminLog: adminLog, adminMail: adminMail, adminTag: adminTag, adminReport: adminReport, adminWithdraw: adminWithdraw, adminGen: adminGen, adminCron: adminCron, adminNav: adminNav, adminCredit: adminCredit, adminFinance: adminFinance, adminNone: function () {}
    };
    if (map[page]) map[page]();
  };

  /** 通用 Tabs：容器内 .tab-btn[data-tab] 切换 .tab-pane[data-pane]，支持 #hash 保持 */
  function initTabs(rootSel) {
    var root = document.querySelector(rootSel);
    if (!root) return;
    var btns = root.querySelectorAll(':scope > .tabs-nav .tab-btn');
    var panes = root.querySelectorAll(':scope > .tab-pane');
    function show(name, push) {
      var found = false;
      btns.forEach(function (b) {
        var on = b.getAttribute('data-tab') === name;
        b.classList.toggle('active', on);
        if (on) found = true;
      });
      panes.forEach(function (p) {
        p.classList.toggle('active', p.getAttribute('data-pane') === name);
      });
      if (!found && btns[0]) show(btns[0].getAttribute('data-tab'), false);
      if (push) { try { history.replaceState(null, '', '#' + name); } catch (e) {} }
    }
    btns.forEach(function (b) {
      b.addEventListener('click', function () { show(b.getAttribute('data-tab'), true); });
    });
    // 定位优先级：URL hash → ?tab= → 第一个标签（GET 筛选提交后能停留在原标签）
    var hash = (location.hash || '').replace('#', '');
    var qs = new URLSearchParams(location.search || '');
    var initial = hash || qs.get('tab') || (btns[0] ? btns[0].getAttribute('data-tab') : '');
    show(initial, false);
  }

  function post(url) {
    return fetch(url, { method: 'POST' }).then(function (r) { return r.json(); });
  }
  /** 带 body 的 POST */
  function postBody(url, body) {
    return fetch(url, {
      method: 'POST', headers: { 'Content-Type': 'application/x-www-form-urlencoded' }, body: body
    }).then(function (r) { return r.json(); });
  }

  /* 造图任务：立即执行（单任务，忽略调度时间） */
  document.addEventListener('click', function (e) {
    var b = e.target && e.target.closest ? e.target.closest('[data-gen-run]') : null;
    if (!b) { return; }
    if (b.dataset.busy === '1') { return; }
    b.dataset.busy = '1';
    var old = b.textContent;
    b.textContent = '生成中…';
    b.disabled = true;
    toast('已提交通道，正在生成（约 10~60 秒）…');
    fetch('/admin/gen/run/' + b.dataset.genRun, { method: 'POST' })
      .then(function (r) { return r.json(); })
      .then(function (d) {
        toast(d.message);
        setTimeout(function () { location.reload(); }, 900);
      })
      .catch(function () {
        toast('执行失败：网络或超时（可稍后看执行日志）');
        b.textContent = old; b.disabled = false; b.dataset.busy = '0';
      });
  });

  /* 订单操作：详情 / 补单 / 关闭（全局委托） */
  document.addEventListener('click', function (e) {
    var t = e.target;
    if (!t || !t.closest) { return; }

    var d = t.closest('[data-ord-detail]');
    if (d) {
      fetch('/admin/order/detail/' + d.dataset.ordDetail)
        .then(function (r) { return r.json(); })
        .then(function (res) { alert(res.detail || res.message); })
        .catch(function () { toast('读取失败'); });
      return;
    }

    var pbtn = t.closest('[data-ord-pay]');
    if (pbtn) {
      if (!confirm('⚠ 人工补单：将直接把该订单标记为已支付，\n并立即发放权益（VIP 延长 / 站币到账）。\n\n确认已收到用户付款？')) { return; }
      toast('补单处理中…');
      fetch('/admin/order/pay/' + pbtn.dataset.ordPay, { method: 'POST' })
        .then(function (r) { return r.json(); })
        .then(function (res) { toast(res.message); if (res.status) setTimeout(function () { location.reload(); }, 900); })
        .catch(function () { toast('补单失败：网络错误'); });
      return;
    }

    var cbtn = t.closest('[data-ord-close]');
    if (cbtn) {
      if (!confirm('关闭该未支付订单？（用户再打开支付页将提示订单已关闭）')) { return; }
      fetch('/admin/order/close/' + cbtn.dataset.ordClose, { method: 'POST' })
        .then(function (r) { return r.json(); })
        .then(function (res) { toast(res.message); if (res.status) setTimeout(function () { location.reload(); }, 600); })
        .catch(function () { toast('操作失败：网络错误'); });
    }
  });

  /* 用户站币调整：全局委托 */
  document.addEventListener('click', function (e) {
    var btn = e.target && e.target.closest ? e.target.closest('[data-user-credits]') : null;
    if (!btn) { return; }
    var id = btn.dataset.userCredits;
    var bal = btn.dataset.balance || '0';
    var amount = prompt('调整站币（当前余额 ' + bal + '）\n\n正数增加，负数扣减，例如 100 或 -50：');
    if (amount === null) { return; }
    amount = parseInt(amount, 10);
    if (isNaN(amount) || amount === 0) { toast('请输入非零整数'); return; }
    var remark = prompt('备注（可选，如：活动补偿 / 违规扣减）：') || '';
    toast('处理中…');
    fetch('/admin/user/credits/' + id, {
      method: 'POST', headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
      body: 'amount=' + amount + '&remark=' + encodeURIComponent(remark)
    }).then(function (r) { return r.json(); }).then(function (d) {
      toast(d.message); if (d.status) setTimeout(function () { location.reload(); }, 700);
    }).catch(function () { toast('操作失败：网络错误'); });
  });

  /* 造图-单日上限保存：全局委托（不依赖页面初始化，避免绑定被异常中断） */
  document.addEventListener('click', function (e) {
    var t = e.target;
    if (!t || t.id !== 'genDailySave') { return; }
    var input = document.getElementById('genDailyLimit');
    var v = parseInt(input ? input.value : '', 10);
    if (isNaN(v) || v < 0) { toast('请输入 0 或正整数'); return; }
    toast('保存中…');
    fetch('/admin/gen/settings', {
      method: 'POST',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
      body: 'gen_daily_limit=' + v
    }).then(function (r) { return r.json(); }).then(function (d) {
      toast(d.message);
    }).catch(function () {
      toast('保存失败：网络或接口错误');
    });
  });

  /** 通用：全选/单条删除/批量删除（cfg: checkAll, checkbox, batchBtn, api, label） */
  function bindBatchDelete(cfg) {
    var bar = cfg.batchBar ? document.querySelector(cfg.batchBar) : null;
    var barTxt = cfg.barCount ? document.querySelector(cfg.barCount) : null;
    function refreshBar() {
      var n = document.querySelectorAll(cfg.checkbox + ':checked').length;
      if (bar) bar.hidden = n === 0;
      if (barTxt) barTxt.textContent = '已选 ' + n + ' ' + (cfg.unit || '条');
    }
    var all = document.querySelector(cfg.checkAll);
    if (all) all.addEventListener('change', function () {
      document.querySelectorAll(cfg.checkbox).forEach(function (c) { c.checked = all.checked; });
      refreshBar();
    });
    document.querySelectorAll(cfg.checkbox).forEach(function (c) { c.addEventListener('change', refreshBar); });
    if (cfg.clearBtn) {
      var cb = document.querySelector(cfg.clearBtn);
      if (cb) cb.addEventListener('click', function () {
        document.querySelectorAll(cfg.checkbox).forEach(function (x) { x.checked = false; });
        if (all) all.checked = false;
        refreshBar();
      });
    }
    document.querySelectorAll('[data-row-del]').forEach(function (b) {
      b.addEventListener('click', function () {
        armConfirm(b, '删除该' + (cfg.label || '记录') + '？', function () {
          postBody(cfg.api, 'ids=' + b.dataset.rowDel).then(function (d) {
            toast(d.message); if (d.status) setTimeout(function () { location.reload(); }, 500);
          });
        });
      });
    });
    var bb = document.querySelector(cfg.batchBtn);
    if (bb) bb.addEventListener('click', function () {
      var ids = Array.prototype.map.call(document.querySelectorAll(cfg.checkbox + ':checked'),
        function (c) { return c.value; });
      if (!ids.length) { toast('先勾选' + (cfg.label || '记录')); return; }
      armConfirm(bb, '删除选中的 ' + ids.length + ' 个' + (cfg.label || '记录') + '？', function () {
        postBody(cfg.api, 'ids=' + ids.join(',')).then(function (d) {
          toast(d.message); if (d.status) setTimeout(function () { location.reload(); }, 500);
        });
      });
    });
  }

  function postForm(url, form) {
    var btn = form.querySelector('button[type="submit"]');
    if (btn) { btn.dataset.orig = btn.textContent; btn.disabled = true; btn.textContent = '保存中…'; }
    var fd = new FormData(form);
    var qs = new URLSearchParams(fd).toString();
    return fetch(url, { method: 'POST', headers: { 'Content-Type': 'application/x-www-form-urlencoded' }, body: qs })
      .then(function (r) { return r.json(); })
      .catch(function (e) { return { status: false, message: '网络错误：' + e }; })
      .then(function (d) {
        if (btn) { btn.disabled = false; btn.textContent = btn.dataset.orig; }
        return d;
      });
  }
  /* 两段式确认按钮 */
  function armConfirm(btn, label, fn) {
    if (!btn.classList.contains('confirming')) {
      btn.classList.add('confirming');
      btn.dataset.orig = btn.textContent;
      btn.textContent = label;
      setTimeout(function () { btn.classList.remove('confirming'); btn.textContent = btn.dataset.orig; }, 3000);
      return;
    }
    fn();
  }

  /* ---------- 图片管理 ---------- */
  function adminImage() {
    initBatch();
    var orphanBtn = document.getElementById('orphanBtn');
    if (orphanBtn) {
      orphanBtn.addEventListener('click', function () {
        orphanBtn.disabled = true;
        orphanBtn.textContent = '扫描中…';
        fetch('/admin/image/orphan/scan', { method: 'POST' })
          .then(function (r) { return r.json(); })
          .then(function (d) {
            orphanBtn.disabled = false;
            orphanBtn.textContent = '清理孤立文件';
            if (!d.status) { toast('扫描失败'); return; }
            var lines = [];
            (d.data.details || []).forEach(function (x) {
              lines.push(x.storage_name + '：' + (x.error ? ('无法读取（' + x.error + '）') : x.count + ' 个孤立文件'));
            });
            var tip = '扫描结果：\n' + lines.join('\n') + '\n\n确认删除全部孤立文件吗？（1 小时内创建的文件会被保护跳过）';
            if (!confirm(tip)) return;
            fetch('/admin/image/orphan/purge', { method: 'POST' })
              .then(function (r) { return r.json(); })
              .then(function (pd) { toast(pd.message || '完成'); })
              .catch(function () { toast('网络错误'); });
          })
          .catch(function () { orphanBtn.disabled = false; orphanBtn.textContent = '清理孤立文件'; toast('网络错误'); });
      });
    }

    var exp = document.getElementById('exportBtn');
    if (exp) {
      exp.addEventListener('click', function (e) {
        e.preventDefault();
        var form = document.querySelector('.search-bar');
        var qs = form ? new URLSearchParams(new FormData(form)).toString() : '';
        location.href = '/admin/image/export' + (qs ? '?' + qs : '');
      });
    }
    document.querySelectorAll('[data-toggle-status]').forEach(function (b) {
      b.addEventListener('click', function () {
        post('/admin/image/toggle/' + b.dataset.toggleStatus).then(function (d) {
          if (d.status) location.reload(); else toast(d.message);
        });
      });
    });
    document.querySelectorAll('[data-del]').forEach(function (b) {
      b.addEventListener('click', function () {
        armConfirm(b, '确认删除?', function () {
          post('/admin/image/delete/' + b.dataset.del).then(function (d) {
            if (d.status) { var tr = b.closest('tr'); if (tr) tr.remove(); toast('已删除'); }
            else toast(d.message);
          });
        });
      });
    });
  }

  /* 图片管理批量操作 */
  function initBatch() {
    var all = document.getElementById('checkAll');
    var bar = document.getElementById('batchBar');
    var cnt = document.getElementById('batchCount');
    if (!all || !bar) return;

    function refresh() {
      var checked = document.querySelectorAll('.row-check:checked');
      bar.hidden = checked.length === 0;
      if (cnt) cnt.textContent = '已选 ' + checked.length + ' 张';
    }
    all.addEventListener('change', function () {
      document.querySelectorAll('.row-check').forEach(function (c) { c.checked = all.checked; });
      refresh();
    });
    document.querySelectorAll('.row-check').forEach(function (c) { c.addEventListener('change', refresh); });
    var clear = document.getElementById('batchClear');
    if (clear) clear.addEventListener('click', function () {
      document.querySelectorAll('.row-check').forEach(function (c) { c.checked = false; });
      if (all) all.checked = false;
      refresh();
    });
    document.querySelectorAll('[data-batch]').forEach(function (btn) {
      btn.addEventListener('click', function () {
        var ids = Array.prototype.map.call(document.querySelectorAll('.row-check:checked'), function (c) { return c.value; });
        if (!ids.length) return;
        var act = btn.dataset.batch;
        if (!confirm(act === 'delete' ? ('确认删除 ' + ids.length + ' 张？') : ('确认操作 ' + ids.length + ' 张？'))) return;
        fetch('/admin/image/batch', {
          method: 'POST',
          headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
          body: 'action=' + act + '&ids=' + ids.join(',')
        }).then(function (r) { return r.json(); }).then(function (d) {
          toast(d.message);
          if (d.status) setTimeout(function () { location.reload(); }, 600);
        }).catch(function () { toast('网络错误'); });
      });
    });
    refresh();
  }

  /* ---------- 用户管理 ---------- */
  function adminUser() {
    document.querySelectorAll('[data-user-toggle]').forEach(function (b) {
      b.addEventListener('click', function () {
        post('/admin/user/toggle/' + b.dataset.userToggle).then(function (d) { d.status ? location.reload() : toast(d.message); });
      });
    });
    document.querySelectorAll('[data-user-reset]').forEach(function (b) {
      b.addEventListener('click', function () {
        armConfirm(b, '确认重置?', function () {
          post('/admin/user/resetpwd/' + b.dataset.userReset).then(function (d) {
            if (d.status) toast('新密码：' + d.data.password + '（只显示一次）');
            else toast(d.message);
          });
        });
      });
    });
    /* 行内编辑：点击编辑在该行下方插入编辑表单 */
    document.querySelectorAll('[data-user-edit]').forEach(function (btn) {
      btn.addEventListener('click', function () {
        var uid = btn.dataset.userEdit;
        var tr = btn.closest('tr');
        if (document.getElementById('edit-row-' + uid)) { return; }
        var cells = tr ? tr.children : [];
        var email = cells[2] ? cells[2].textContent.trim() : '';
        var nickname = cells[3] ? cells[3].textContent.trim() : '';
        var groupsHtml = '';
        (window.__groups || []).forEach(function (g) {
          groupsHtml += '<option value="' + g.id + '">' + g.name + '</option>';
        });
        var er = document.createElement('tr');
        er.id = 'edit-row-' + uid;
        er.innerHTML = '<td colspan="10"><div class="user-edit-bar">' +
          '<label>昵称 <input type="text" class="ue-nickname" value="' + nickname + '"></label>' +
          '<label>邮箱 <input type="text" class="ue-email" value="' + email + '"></label>' +
          '<label>策略组 <select class="ue-group">' + groupsHtml + '</select></label>' +
          '<label>状态 <select class="ue-status"><option value="1">正常</option><option value="2">禁用</option></select></label>' +
          '<label>新密码 <input type="text" class="ue-password" placeholder="留空不改"></label>' +
          '<button class="btn btn-sm" class="ue-save">保存</button>' +
          '<button class="btn-ghost-sm ue-cancel">取消</button>' +
          '</div></td>';
        tr.parentNode.insertBefore(er, tr.nextSibling);
        // 回填当前策略组（防止默认第一项导致误改）
        var rowGroupSel = tr ? tr.querySelector('.group-select') : null;
        if (rowGroupSel) {
          er.querySelector('.ue-group').value = rowGroupSel.value;
        }
        var rowStatus = tr && tr.children[6] ? tr.children[6].textContent.trim() : '';
        if (rowStatus.indexOf('禁用') !== -1) {
          er.querySelector('.ue-status').value = '2';
        }
        er.querySelector('.ue-cancel').onclick = function () { er.remove(); };
        er.querySelector('.btn.btn-sm').onclick = function () {
          var body = 'nickname=' + encodeURIComponent(er.querySelector('.ue-nickname').value)
            + '&email=' + encodeURIComponent(er.querySelector('.ue-email').value)
            + '&group_id=' + er.querySelector('.ue-group').value
            + '&status=' + er.querySelector('.ue-status').value
            + '&password=' + encodeURIComponent(er.querySelector('.ue-password').value);
          fetch('/admin/user/save/' + uid, {
            method: 'POST', headers: { 'Content-Type': 'application/x-www-form-urlencoded' }, body: body
          }).then(function (r) { return r.json(); }).then(function (d) {
            toast(d.message);
            if (d.status) setTimeout(function () { location.reload(); }, 700);
          }).catch(function () { toast('网络错误'); });
        };
      });
    });

    /* 批量操作 */
    var uAll = document.getElementById('userCheckAll');
    document.querySelectorAll('[data-user-del]').forEach(function (b) {
      b.addEventListener('click', function () {
        var id = b.dataset.userDel;
        var name = b.dataset.name || ('#' + id);
        var imgs = b.dataset.imgs || '0';
        if (!confirm('⚠ 彻底删除用户「' + name + '」？\n\n该用户名下图片：' + imgs + ' 张\n将清除：用户、图片（含文件）、订单、站币流水、解锁记录、举报、Token\n\n删除后不可恢复！')) return;
        fetch('/admin/user/delete/' + id, {
          method: 'POST', headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
          body: ''
        }).then(function (r) { return r.json(); }).then(function (d) {
          toast(d.message); if (d.status) setTimeout(function () { location.reload(); }, 600);
        }).catch(function () { toast('网络错误'); });
      });
    });

    document.querySelectorAll('[data-user-verify]').forEach(function (b) {
      b.addEventListener('click', function () {
        if (!confirm('手动标记该用户邮箱为已验证？\n（用于用户收不到验证码时的兜底）')) return;
        fetch('/admin/user/verify-email/' + b.dataset.userVerify, { method: 'POST' })
          .then(function (r) { return r.json(); }).then(function (d) {
            toast(d.message); if (d.status) setTimeout(function () { location.reload(); }, 500);
          }).catch(function () { toast('网络错误'); });
      });
    });

    var uBar = document.getElementById('userBatchBar');
    var uCnt = document.getElementById('userBatchCount');
    function uRefresh() {
      var checked = document.querySelectorAll('.user-check:checked');
      if (uBar) uBar.hidden = checked.length === 0;
      if (uCnt) uCnt.textContent = '已选 ' + checked.length + ' 人';
    }
    if (uAll) uAll.addEventListener('change', function () {
      document.querySelectorAll('.user-check').forEach(function (c) { c.checked = uAll.checked; });
      uRefresh();
    });
    document.querySelectorAll('.user-check').forEach(function (c) { c.addEventListener('change', uRefresh); });
    var uClear = document.getElementById('userBatchClear');
    if (uClear) uClear.addEventListener('click', function () {
      document.querySelectorAll('.user-check').forEach(function (c) { c.checked = false; });
      if (uAll) uAll.checked = false;
      uRefresh();
    });
    document.querySelectorAll('[data-user-batch]').forEach(function (btn) {
      btn.addEventListener('click', function () {
        var ids = Array.prototype.map.call(document.querySelectorAll('.user-check:checked'), function (c) { return c.value; });
        if (!ids.length) return;
        var act = btn.dataset.userBatch;
        var body = 'action=' + act + '&ids=' + ids.join(',');
        if (act === 'group') {
          var gid = prompt('输入目标策略组 ID（可先看策略组页）');
          if (!gid) return;
          body += '&group_id=' + gid;
        }
        var msg = (act === 'delete')
          ? ('⚠ 彻底删除所选 ' + ids.length + ' 个用户？\n\n将清除其全部数据（图片/订单/流水/举报），不可恢复！')
          : ('确认对 ' + ids.length + ' 个用户执行该操作？');
        if (!confirm(msg)) return;
        fetch('/admin/user/batch', {
          method: 'POST', headers: { 'Content-Type': 'application/x-www-form-urlencoded' }, body: body
        }).then(function (r) { return r.json(); }).then(function (d) {
          toast(d.message);
          if (d.status) setTimeout(function () { location.reload(); }, 700);
        }).catch(function () { toast('网络错误'); });
      });
    });

    document.querySelectorAll('.group-select').forEach(function (sel) {
      sel.addEventListener('change', function () {
        fetch('/admin/user/group/' + sel.dataset.user, {
          method: 'POST', headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
          body: 'group_id=' + sel.value
        }).then(function (r) { return r.json(); }).then(function (d) { toast(d.message); });
      });
    });
  }

  /* ---------- 策略组 ---------- */
  function adminGroup() {
    var form = document.getElementById('groupForm');
    if (!form) return;
    form.addEventListener('submit', function (e) {
      e.preventDefault();
      postForm('/admin/group/save', form).then(function (d) { toast(d.message); if (d.status) location.reload(); });
    });
    document.querySelectorAll('[data-toggle-group]').forEach(function (b) {
      b.addEventListener('click', function () {
        post('/admin/group/toggle/' + b.dataset.toggleGroup).then(function (d) {
          toast(d.message); if (d.status) setTimeout(function () { location.reload(); }, 400);
        });
      });
    });
    document.querySelectorAll('[data-del-group]').forEach(function (b) {
      b.addEventListener('click', function () {
        armConfirm(b, '确认删除?', function () {
          post('/admin/group/delete/' + b.dataset.delGroup).then(function (d) {
            toast(d.message); if (d.status) setTimeout(function () { location.reload(); }, 400);
          });
        });
      });
    });
    document.querySelectorAll('[data-edit-group]').forEach(function (b) {
      b.addEventListener('click', function () {
        var g = (window.__groups || []).filter(function (x) { return String(x.id) === b.dataset.editGroup; })[0];
        if (!g) return;
        document.getElementById('g_id').value = g.id;
        document.getElementById('g_name').value = g.name;
        document.getElementById('g_remark').value = g.remark || '';
        document.getElementById('g_max').value = Math.round(g.max_size / 1048576);
        document.getElementById('g_daily').value = g.daily_count;
        document.getElementById('g_quota').value = Math.round(g.quota_total / 1073741824);
        document.getElementById('g_keep').value = g.keep_days;
        document.getElementById('g_ext').value = g.allow_ext;
        document.getElementById('g_max_tokens').value = g.max_tokens || 0;
        document.getElementById('g_storage').value = g.storage_id;
        document.getElementById('g_ad').value = g.ad_exempt;
        document.getElementById('g_status').value = g.status;
        if (document.getElementById('g_allow_public')) document.getElementById('g_allow_public').value = g.allow_public;
        if (document.getElementById('g_allow_auto_public')) document.getElementById('g_allow_auto_public').value = g.allow_auto_public;
        if (document.getElementById('g_can_report')) document.getElementById('g_can_report').value = g.can_report;
        if (document.getElementById('g_allow_paid')) document.getElementById('g_allow_paid').value = g.allow_paid;
        form.scrollIntoView({ behavior: 'smooth' });
      });
    });
  }

  /* ---------- 存储管理 ---------- */
  function adminStorage() {
    // 驱动切换：显隐对应配置字段
    // 按驱动显示/隐藏配置段，并禁用隐藏段输入（防同名字段互相覆盖导致保存丢失）
    function syncCfgFields() {
      document.querySelectorAll('.driver-select').forEach(function (sel) {
        var panel = document.getElementById('storage-form-' + sel.dataset.panel);
        if (!panel) return;
        panel.querySelectorAll('.cfg-fields').forEach(function (f) {
          var on = f.dataset.cfg === sel.value;
          f.hidden = !on;
          f.querySelectorAll('input, select, textarea').forEach(function (inp) {
            inp.disabled = !on;
          });
        });
      });
    }
    syncCfgFields();
    document.querySelectorAll('.driver-select').forEach(function (sel) {
      sel.addEventListener('change', syncCfgFields);
    });
    // 展开编辑
    document.querySelectorAll('[data-edit-storage]').forEach(function (b) {
      b.addEventListener('click', function () {
        var f = document.getElementById('storage-form-' + b.dataset.editStorage);
        if (f) { f.hidden = !f.hidden; }
      });
    });

    // 保存
    document.querySelectorAll('.storage-form').forEach(function (form) {
      form.addEventListener('submit', function (e) {
        e.preventDefault();
        postForm('/admin/storage/save', form).then(function (d) { toast(d.message); if (d.status) location.reload(); });
      });
    });
    // 连通测试
    document.querySelectorAll('[data-probe]').forEach(function (b) {
      b.addEventListener('click', function () {
        var out = document.getElementById('probe-' + b.dataset.probe);
        out.textContent = '测试中…'; out.className = 'probe-result';
        post('/admin/storage/probe/' + b.dataset.probe).then(function (d) {
          out.textContent = (d.data.ok ? '✓ ' : '✗ ') + d.data.msg;
          out.className = 'probe-result ' + (d.data.ok ? 'ok' : 'err');
        });
      });
    });
    // 删除
    document.querySelectorAll('[data-storage-del]').forEach(function (b) {
      b.addEventListener('click', function () {
        armConfirm(b, '确认删除?', function () {
          post('/admin/storage/delete/' + b.dataset.storageDel).then(function (d) {
            d.status ? location.reload() : toast(d.message);
          });
        });
      });
    });
  }

  /* ---------- 支付管理 ---------- */
  function adminPayment() {
    initTabs('#payTabs');
    bindBatchDelete({checkAll:'#ordCheckAll', checkbox:'.ord-check', batchBtn:'#ordDel',
      api:'/admin/payment/delete-orders', label:'订单', batchBar:'#ordBar', barCount:'#ordCount',
      clearBtn:'#ordClearSel', unit:'条'});
    var gw = document.getElementById('gatewayForm');
    if (gw) gw.addEventListener('submit', function (e) {
      e.preventDefault();
      postForm('/admin/payment/gateway', gw).then(function (d) { toast(d.message); });
    });
    var pf = document.getElementById('planForm');
    if (pf) pf.addEventListener('submit', function (e) {
      e.preventDefault();
      postForm('/admin/payment/plan', pf).then(function (d) { toast(d.message); if (d.status) location.reload(); });
    });
    document.querySelectorAll('[data-plan-del]').forEach(function (b) {
      b.addEventListener('click', function () {
        armConfirm(b, '确认删除?', function () {
          post('/admin/payment/plan/delete/' + b.dataset.planDel).then(function (d) { d.status ? location.reload() : toast(d.message); });
        });
      });
    });
    document.querySelectorAll('[data-plan-fill]').forEach(function (b) {
      b.addEventListener('click', function () {
        var p = (window.__plans || []).filter(function (x) { return String(x.id) === b.dataset.planFill; })[0];
        if (!p) return;
        document.getElementById('p_id').value = p.id;
        document.getElementById('p_name').value = p.name;
        document.getElementById('p_days').value = p.days;
        document.getElementById('p_price').value = p.price / 100;
        document.getElementById('p_group').value = p.group_id;
        document.getElementById('p_desc').value = p.description || '';
        document.getElementById('p_status').value = p.status;
        pf.scrollIntoView({ behavior: 'smooth' });
      });
    });
  }

  /* ---------- 站点设置 / 审核设置 ---------- */
  function adminSite() {
    var f = document.getElementById('siteForm');
    if (f) f.addEventListener('submit', function (e) {
      e.preventDefault();
      postForm('/admin/site/save', f).then(function (d) { toast(d.message); });
    });
  }
  function adminAudit() {
    var f = document.getElementById('auditForm');
    if (f) f.addEventListener('submit', function (e) {
      e.preventDefault();
      postForm('/admin/audit/save', f).then(function (d) { toast(d.message); });
    });
  }

  /* ---------- 导航管理 ---------- */
  function adminNav() {
    var body = document.getElementById('navBody');
    function collect() {
      var out = [];
      document.querySelectorAll('.nav-row').forEach(function (tr) {
        var name = tr.querySelector('.nav-name').value.trim();
        var url = tr.querySelector('.nav-url').value.trim();
        if (!name || !url) return;
        out.push({
          name: name, url: url,
          pos: tr.querySelector('.nav-pos').value,
          auth: tr.querySelector('.nav-auth').value,
          blank: tr.querySelector('.nav-blank').checked ? 1 : 0,
          sort: parseInt(tr.querySelector('.nav-sort').value, 10) || 50,
          enabled: tr.querySelector('.nav-enabled').checked ? 1 : 0
        });
      });
      return out;
    }
    var add = document.getElementById('navAdd');
    if (add) add.addEventListener('click', function () {
      var tr = document.createElement('tr');
      tr.className = 'nav-row';
      tr.innerHTML = '<td><input type="text" class="nav-name" placeholder="名称" style="width:100%"></td>' +
        '<td><input type="text" class="nav-url" placeholder="/path 或 https://..." style="width:100%"></td>' +
        '<td><select class="nav-pos filter-select"><option value="left">站名右侧</option><option value="right" selected>右侧导航</option></select></td>' +
        '<td><select class="nav-auth filter-select"><option value="all">所有人</option><option value="login">仅登录</option><option value="guest">仅未登录</option></select></td>' +
        '<td style="text-align:center"><input type="checkbox" class="nav-blank"></td>' +
        '<td><input type="number" class="nav-sort" value="80" style="width:64px"></td>' +
        '<td style="text-align:center"><input type="checkbox" class="nav-enabled" checked></td>' +
        '<td><button class="btn-ghost-sm btn-danger-sm" type="button" onclick="this.closest(\'tr\').remove()">删除</button></td>';
      body.appendChild(tr);
    });
    var save = document.getElementById('navSave');
    if (save) save.addEventListener('click', function () {
      var links = collect();
      if (!links.length) { toast('至少保留一个导航项'); return; }
      fetch('/admin/nav/save', {
        method: 'POST', headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: 'links_json=' + encodeURIComponent(JSON.stringify(links))
      }).then(function (r) { return r.json(); }).then(function (d) {
        toast(d.message); if (d.status) setTimeout(function () { location.reload(); }, 700);
      }).catch(function () { toast('网络错误'); });
    });
    var rs = document.getElementById('navReset');
    if (rs) rs.addEventListener('click', function () {
      armConfirm(rs, '恢复默认导航？当前自定义配置将被覆盖', function () {
        post('/admin/nav/reset').then(function (d) {
          toast(d.message); if (d.status) setTimeout(function () { location.reload(); }, 600);
        });
      });
    });
  }

  /* ---------- 计划任务 ---------- */
  function adminCron() {
    document.querySelectorAll('[data-cron-run]').forEach(function (b) {
      b.addEventListener('click', function () {
        b.disabled = true; var t0 = b.textContent; b.textContent = '执行中…';
        post('/admin/cron/run/' + b.dataset.cronRun).then(function (d) {
          b.disabled = false; b.textContent = t0;
          toast(d.message);
          if (d.status) setTimeout(function () { location.reload(); }, 900);
        }).catch(function () { b.disabled = false; b.textContent = t0; toast('网络错误'); });
      });
    });
    document.querySelectorAll('[data-copy-text]').forEach(function (b) {
      b.addEventListener('click', function () {
        var t = b.dataset.copyText;
        (navigator.clipboard ? navigator.clipboard.writeText(t) : Promise.reject())
          .then(function () { toast('命令已复制'); })
          .catch(function () { prompt('手动复制：', t); });
      });
    });
    var lm = document.getElementById('cronLogModal');
    document.querySelectorAll('[data-cron-log]').forEach(function (b) {
      b.addEventListener('click', function () {
        var task = b.dataset.cronLog;
        fetch('/admin/cron/log/' + task).then(function (r) { return r.json(); }).then(function (d) {
          if (!d.status) { toast(d.message || '读取失败'); return; }
          document.getElementById('cronLogTitle').textContent = '执行日志 · ' + task;
          var runs = (d.runs || []).map(function (x) {
            return x.time + '  ' + (x.ok == 1 ? '✓成功' : '✗失败') + '  ' + x.duration_ms + 'ms  ' + x.source + '  ' + (x.result || '');
          }).join('\n');
          document.getElementById('cronLogRuns').textContent = runs ? ('最近执行：\n' + runs) : '';
          document.getElementById('cronLogTail').textContent = d.tail || '';
          lm.hidden = false;
        }).catch(function () { toast('网络错误'); });
      });
    });
    if (lm) document.getElementById('cronLogClose').onclick = function () { lm.hidden = true; };

    var kc = document.getElementById('cronKeyCopy');
    if (kc) kc.addEventListener('click', function () {
      var el = document.getElementById('cronKeyInput');
      var val = el ? String(el.value || '').trim() : '';
      if (!val) { toast('未取到密钥'); return; }
      copyText(val, function (ok) { toast(ok ? '密钥已复制' : '复制失败，请手动选择'); });
      try { el.focus(); el.select(); } catch (e) {}
    });

    var rk = document.getElementById('cronRotate');
    if (rk) rk.addEventListener('click', function () {
      if (!confirm('重置密钥？\n\n· 旧密钥立即失效\n· 宝塔里用「方式 B（访问 URL）」的任务需全部替换新地址\n· 方式 A（CLI）不受影响')) return;
      post('/admin/cron/rotate').then(function (d) {
        toast(d.message); setTimeout(function () { location.reload(); }, 600);
      });
    });
  }

  /* ---------- 造图任务 ---------- */
  function adminGen() {
    initTabs('#genTabs');
    // 新建 / 编辑任务
    var nf = document.getElementById('genNewForm');
    function resetGenForm() {
      if (!nf) { return; }
      nf.reset();
      nf.querySelector('[name=id]').value = '0';
      var flag = document.getElementById('genEditFlag');
      if (flag) { flag.hidden = true; flag.textContent = ''; }
      var sb = document.getElementById('genSubmitBtn');
      if (sb) sb.textContent = '创建任务';
    }
    var newTab = document.querySelector('[data-tab="new"]');
    var genSwitchByCode = false;   // 程序化切换时不触发"重置"
    if (newTab) newTab.addEventListener('click', function () {
      if (genSwitchByCode) { return; }
      var fid = nf ? nf.querySelector('[name=id]').value : '0';
      if (fid !== '0') { resetGenForm(); }
    });
    if (nf) nf.addEventListener('submit', function (e) {
      e.preventDefault();
      postForm('/admin/gen/save', nf).then(function (d) {
        toast(d.message); if (d.status) setTimeout(function () { location.reload(); }, 800);
      });
    });
    document.querySelectorAll('[data-gen-edit]').forEach(function (b) {
      b.addEventListener('click', function () {
        var t = (window.__genTasks || []).filter(function (x) { return String(x.id) === b.dataset.genEdit; })[0];
        if (!t || !nf) { return; }
        nf.querySelector('[name=id]').value = t.id;
        nf.querySelector('[name=name]').value = t.name;
        nf.querySelector('[name=model_id]').value = t.model_id;
        nf.querySelector('[name=target_user_input]').value = t.target_name || t.target_user_id;
        nf.querySelector('[name=count_per_run]').value = t.count_per_run;
        nf.querySelector('[name=interval_minutes]').value = t.interval_minutes;
        nf.querySelector('[name=auto_public]').value = t.auto_public;
        if (nf.querySelector('[name=retry_on_fail]')) nf.querySelector('[name=retry_on_fail]').value = t.retry_on_fail;
        if (nf.querySelector('[name=category]')) nf.querySelector('[name=category]').value = t.category || '';
        if (nf.querySelector('[name=auto_tag]')) nf.querySelector('[name=auto_tag]').value = t.auto_tag;
        var ps = [];
        try { ps = JSON.parse(t.prompts) || []; } catch (e2) {}
        nf.querySelector('[name=prompts]').value = ps.join('\n');
        // 先把 id 归零并程序化切到新建标签（避免触发重置），再回填
        nf.querySelector('[name=id]').value = '0';
        var tabBtn = document.querySelector('[data-tab="new"]');
        if (tabBtn) { genSwitchByCode = true; tabBtn.click(); genSwitchByCode = false; }

        nf.querySelector('[name=id]').value = t.id;
        nf.querySelector('[name=name]').value = t.name;
        nf.querySelector('[name=model_id]').value = t.model_id;
        nf.querySelector('[name=target_user_input]').value = t.target_user_id;
        nf.querySelector('[name=count_per_run]').value = t.count_per_run;
        nf.querySelector('[name=interval_minutes]').value = t.interval_minutes;
        nf.querySelector('[name=auto_public]').value = t.auto_public;
        var ps2 = [];
        try { ps2 = JSON.parse(t.prompts) || []; } catch (e3) {}
        nf.querySelector('[name=prompts]').value = ps2.join('\n');

        var flag = document.getElementById('genEditFlag');
        if (flag) { flag.hidden = false; flag.textContent = '编辑中 #' + t.id; }
        var sb = document.getElementById('genSubmitBtn');
        if (sb) sb.textContent = '保存修改';
        nf.scrollIntoView({ behavior: 'smooth' });
        toast('已载入任务 #' + t.id + '，修改后点「保存修改」');
      });
    });

    // 任务详情弹窗
    var dm = document.getElementById('genDetailModal');
    var dCurId = 0;
    function renderDetail(d, id) {
      document.getElementById('genDetailTitle').textContent = '任务 #' + id + ' · ' + d.name;
      document.getElementById('genDetailInfo').textContent = d.info;
      var g = document.getElementById('genDetailGrid');
      var e = document.getElementById('genDetailEmpty');
      g.innerHTML = '';
      (d.images || []).forEach(function (im) {
        var cell = document.createElement('a');
        cell.href = im.url; cell.target = '_blank';
        cell.style.cssText = 'display:block;border:1px solid #E4E1DB;border-radius:8px;overflow:hidden;text-decoration:none;color:inherit';
        cell.innerHTML = '<img src="' + im.url + '" style="width:100%;height:110px;object-fit:cover;display:block">'
          + '<div style="padding:6px 8px;font-size:12px;line-height:1.5">#' + im.id + ' · ' + im.time
          + '<br><span style="color:' + (im.audit_text === '通过' ? '#2E6B4F' : (im.audit_text === '拒绝' ? '#B3402A' : '#8A867E')) + '">'
          + im.audit_text + (im.is_public == 1 ? ' · 已公开' : '') + '</span></div>';
        g.appendChild(cell);
      });
      e.hidden = !!(d.images && d.images.length);
      e.textContent = d.message || '';

      var pager = document.getElementById('genDetailPager');
      if (pager) {
        pager.innerHTML = '';
        if ((d.pages || 1) > 1) {
          var prev = document.createElement('button');
          prev.className = 'btn-ghost-sm'; prev.type = 'button'; prev.textContent = '上一页';
          prev.disabled = d.page <= 1;
          prev.onclick = function () { loadDetail(dCurId, d.page - 1); };
          var info = document.createElement('span');
          info.style.margin = '0 8px'; info.textContent = d.page + ' / ' + d.pages;
          var next = document.createElement('button');
          next.className = 'btn-ghost-sm'; next.type = 'button'; next.textContent = '下一页';
          next.disabled = d.page >= d.pages;
          next.onclick = function () { loadDetail(dCurId, d.page + 1); };
          pager.appendChild(prev); pager.appendChild(info); pager.appendChild(next);
        }
      }
    }
    function loadDetail(id, page) {
      dCurId = id;
      fetch('/admin/gen/detail/' + id + '?page=' + (page || 1)).then(function (r) { return r.json(); }).then(function (d) {
        if (!d.status) { toast(d.message); return; }
        renderDetail(d, id);
      }).catch(function () { toast('读取失败'); });
    }
    document.querySelectorAll('[data-gen-detail]').forEach(function (b) {
      b.addEventListener('click', function () {
        dm.hidden = false;
        loadDetail(b.dataset.genDetail, 1);
      });
    });
    if (dm) document.getElementById('genDetailClose').onclick = function () { dm.hidden = true; };

    // 复制任务
    document.querySelectorAll('[data-gen-copy]').forEach(function (b) {
      b.addEventListener('click', function () {
        armConfirm(b, '复制该任务？（副本默认暂停，便于修改后启用）', function () {
          post('/admin/gen/copy/' + b.dataset.genCopy).then(function (d) {
            toast(d.message); if (d.status) setTimeout(function () { location.reload(); }, 700);
          });
        });
      });
    });

    // ===== 提示词中心 =====
    initTabs('#promptTabs');
    var pv = document.getElementById('promptPreview');
    function fillPreview(list) {
      if (!pv) { return; }
      pv.value = (list || []).join('\n');
      pv.focus();
    }
    function promptPost(url, body) {
      return fetch(url, { method: 'POST', headers: { 'Content-Type': 'application/x-www-form-urlencoded' }, body: body })
        .then(function (r) { return r.json(); });
    }
    var pgr = document.getElementById('pgRun');
    if (pgr) pgr.addEventListener('click', function () {
      var topic = (document.getElementById('pgTopic') || {}).value || '';
      if (!topic.trim()) { toast('请填写主题'); return; }
      pgr.disabled = true; pgr.textContent = '生成中…';
      promptPost('/admin/gen/prompt/gen',
        'topic=' + encodeURIComponent(topic)
        + '&style=' + encodeURIComponent((document.getElementById('pgStyle') || {}).value || '')
        + '&count=' + ((document.getElementById('pgCount') || {}).value || 30)
        + '&model_id=' + ((document.getElementById('pgModel') || {}).value || 0)
      ).then(function (d) {
        toast(d.message); if (d.list) { fillPreview(d.list); }
      }).catch(function () { toast('生成失败：网络错误'); })
        .finally(function () { pgr.disabled = false; pgr.textContent = '生成'; });
    });
    var tplr = document.getElementById('tplRun');
    if (tplr) tplr.addEventListener('click', function () {
      promptPost('/admin/gen/prompt/template', 'count=' + ((document.getElementById('tplCount') || {}).value || 60))
        .then(function (d) {
          toast(d.status ? ('已生成 ' + d.list.length + ' 条') : (d.message || '失败'));
          if (d.list) { fillPreview(d.list); }
        }).catch(function () { toast('生成失败：网络错误'); });
    });
    var rvr = document.getElementById('revRun');
    if (rvr) rvr.addEventListener('click', function () {
      var u = (document.getElementById('revUrl') || {}).value || '';
      if (!u.trim()) { toast('请填写图片地址'); return; }
      rvr.disabled = true; rvr.textContent = '识别中…';
      promptPost('/admin/gen/prompt/reverse',
        'image_url=' + encodeURIComponent(u) + '&model_id=' + ((document.getElementById('revModel') || {}).value || 0)
      ).then(function (d) {
        toast(d.message); if (d.list) { fillPreview(d.list); }
      }).catch(function () { toast('反推失败：网络错误'); })
        .finally(function () { rvr.disabled = false; rvr.textContent = '反推提示词'; });
    });
    var iof = document.getElementById('ioFile');
    if (iof) iof.addEventListener('change', function () {
      var f = iof.files && iof.files[0];
      if (!f) { return; }
      var fr = new FileReader();
      fr.onload = function () { if (pv) { pv.value = String(fr.result || ''); } toast('文件已读入下方编辑框'); };
      fr.readAsText(f, 'utf-8');
    });
    var ioe = document.getElementById('ioExport');
    if (ioe) ioe.addEventListener('click', function (e) {
      e.preventDefault();
      var sel = document.getElementById('ioTaskExport');
      if (!sel || !sel.value) { toast('请选择任务'); return; }
      window.location.href = '/admin/gen/prompt/export/' + sel.value;
    });
    var ur = document.getElementById('usageRun');
    if (ur) ur.addEventListener('click', function () {
      var sel = document.getElementById('promptTargetTask');
      if (!sel || !sel.value) { toast('请选择任务'); return; }
      ur.disabled = true;
      promptPost('/admin/gen/prompt/usage', 'task_id=' + sel.value).then(function (d) {
        var out = document.getElementById('usageOut');
        if (!d.status) { toast(d.message); if (out) { out.textContent = d.message || ''; } return; }
        var lines = [d.summary, '', '— 最常用 —'];
        (d.list || []).forEach(function (x, i) {
          lines.push((i + 1) + '. [' + x.count + ' 次] ' + x.prompt);
        });
        if (d.unused && d.unused.length) {
          lines.push('', '— 从未抽到（共 ' + d.unused_total + ' 条，列前 ' + d.unused.length + ' 条） —');
          d.unused.forEach(function (p, i) { lines.push((i + 1) + '. ' + p); });
        }
        if (out) { out.textContent = lines.join('\n'); }
      }).catch(function () { toast('统计失败：网络错误'); })
        .finally(function () { ur.disabled = false; });
    });

    var pab = document.getElementById('promptAppendBtn');
    if (pab) pab.addEventListener('click', function () {
      var sel = document.getElementById('promptTargetTask');
      if (!sel || !sel.value) { toast('请选择目标任务'); return; }
      var txt = pv ? pv.value : '';
      if (!txt.trim()) { toast('下方编辑框为空'); return; }
      promptPost('/admin/gen/prompt/append',
        'task_id=' + sel.value + '&prompts=' + encodeURIComponent(txt)
      ).then(function (d) {
        toast(d.message);
        if (d.status) { setTimeout(function () { location.href = '/admin/gen?tab=prompts'; }, 800); }
      }).catch(function () { toast('追加失败：网络错误'); });
    });

    // 任务列表批量（启用/暂停/删除）
    var tsAll = document.getElementById('genTaskCheckAll');
    var tsBar = document.getElementById('genTaskBar');
    var tsCnt = document.getElementById('genTaskCount');
    function tsRefresh() {
      var n = document.querySelectorAll('.gentask-check:checked').length;
      if (tsBar) tsBar.hidden = n === 0;
      if (tsCnt) tsCnt.textContent = '已选 ' + n + ' 个';
    }
    if (tsAll) tsAll.addEventListener('change', function () {
      document.querySelectorAll('.gentask-check').forEach(function (c) { c.checked = tsAll.checked; });
      tsRefresh();
    });
    document.querySelectorAll('.gentask-check').forEach(function (c) { c.addEventListener('change', tsRefresh); });
    var tsClear = document.getElementById('genTaskClear');
    if (tsClear) tsClear.addEventListener('click', function () {
      document.querySelectorAll('.gentask-check').forEach(function (c) { c.checked = false; });
      if (tsAll) tsAll.checked = false;
      tsRefresh();
    });
    function tsBatch(action, label) {
      var ids = Array.prototype.map.call(document.querySelectorAll('.gentask-check:checked'), function (c) { return c.value; });
      if (!ids.length) { toast('先勾选任务'); return; }
      var btn = document.getElementById(action === 'delete' ? 'genTaskDel' : (action === 'enable' ? 'genTaskEnable' : 'genTaskDisable'));
      armConfirm(btn, '批量' + label + '选中的 ' + ids.length + ' 个任务？', function () {
        postBody('/admin/gen/batch', 'action=' + action + '&ids=' + ids.join(',')).then(function (d) {
          toast(d.message); if (d.status) setTimeout(function () { location.reload(); }, 600);
        });
      });
    }
    ['genTaskEnable|enable|启用', 'genTaskDisable|disable|暂停', 'genTaskDel|delete|删除'].forEach(function (spec) {
      var p2 = spec.split('|');
      var b = document.getElementById(p2[0]);
      if (b) b.addEventListener('click', function () { tsBatch(p2[1], p2[2]); });
    });

    bindBatchDelete({checkAll:'#genLogCheckAll', checkbox:'.genlog-check', batchBtn:'#genLogDel',
      api:'/admin/gen/delete-logs', label:'日志', batchBar:'#genLogBar', barCount:'#genLogCount',
      clearBtn:'#genLogClearSel', unit:'条'});
    var lc = document.getElementById('genLogClear');
    if (lc) lc.addEventListener('click', function () {
      armConfirm(lc, '清空全部执行日志? 不可恢复', function () {
        postBody('/admin/gen/clear-logs', '').then(function (d) {
          toast(d.message); if (d.status) setTimeout(function () { location.reload(); }, 500);
        });
      });
    });
  }

  /* ---------- 财务管理（网关/套餐/订单/站币/提现） ---------- */
  function adminFinance() {
    initTabs('#finTabs');

    // 订单批量
    bindBatchDelete({checkAll:'#ordCheckAll', checkbox:'.ord-check', batchBtn:'#ordDel',
      api:'/admin/payment/delete-orders', label:'订单', batchBar:'#ordBar',
      barCount:'#ordCount', clearBtn:'#ordClearSel', unit:'条'});
    // 站币流水批量
    bindBatchDelete({checkAll:'#creditCheckAll', checkbox:'.credit-check', batchBtn:'#creditBatchDel',
      api:'/admin/credit/delete', label:'流水记录', batchBar:'#creditBar',
      barCount:'#creditCount', clearBtn:'#creditBatchClear', unit:'条'});
    // 提现批量
    bindBatchDelete({checkAll:'#wdCheckAll', checkbox:'.wd-check', batchBtn:'#wdBatchDel',
      api:'/admin/withdraw/delete', label:'提现记录', batchBar:'#wdBatchBar',
      barCount:'#wdBatchCount', clearBtn:'#wdBatchClear', unit:'条'});

    // 网关表单
    var gw = document.getElementById('gatewayForm');
    if (gw) gw.addEventListener('submit', function (e) {
      e.preventDefault();
      postForm('/admin/payment/gateway', gw).then(function (d) { toast(d.message); });
    });
    // 套餐表单
    var pf = document.getElementById('planForm');
    if (pf) pf.addEventListener('submit', function (e) {
      e.preventDefault();
      postForm('/admin/payment/plan', pf).then(function (d) { toast(d.message); if (d.status) location.reload(); });
    });
    document.querySelectorAll('[data-plan-del]').forEach(function (b) {
      b.addEventListener('click', function () {
        armConfirm(b, '确认删除该套餐?', function () {
          post('/admin/payment/plan/delete/' + b.dataset.planDel).then(function (d) { d.status ? location.reload() : toast(d.message); });
        });
      });
    });
    document.querySelectorAll('[data-plan-fill]').forEach(function (b) {
      b.addEventListener('click', function () {
        var pl = (window.__plans || []).filter(function (x) { return String(x.id) === b.dataset.planFill; })[0];
        if (!pl) return;
        document.getElementById('p_id').value = pl.id;
        document.getElementById('p_name').value = pl.name;
        document.getElementById('p_days').value = pl.days;
        document.getElementById('p_price').value = pl.price / 100;
        document.getElementById('p_group').value = pl.group_id;
        document.getElementById('p_desc').value = pl.description || '';
        document.getElementById('p_status').value = pl.status;
        if (pf) pf.scrollIntoView({ behavior: 'smooth' });
      });
    });

    // 提现操作（通过/打款/驳回）
    document.querySelectorAll('[data-wd-approve]').forEach(function (b) {
      b.addEventListener('click', function () {
        armConfirm(b, '通过该提现申请？', function () {
          post('/admin/withdraw/approve/' + b.dataset.wdApprove).then(function (d) {
            toast(d.message); if (d.status) setTimeout(function () { location.reload(); }, 600);
          });
        });
      });
    });
    document.querySelectorAll('[data-wd-paid]').forEach(function (b) {
      b.addEventListener('click', function () {
        var v = prompt('填写打款凭证/备注（可留空）：') || '';
        armConfirm(b, '确认已打款？', function () {
          postBody('/admin/withdraw/paid/' + b.dataset.wdPaid, 'voucher=' + encodeURIComponent(v)).then(function (d) {
            toast(d.message); if (d.status) setTimeout(function () { location.reload(); }, 600);
          });
        });
      });
    });
    document.querySelectorAll('[data-wd-reject]').forEach(function (b) {
      b.addEventListener('click', function () {
        var r = prompt('驳回原因（必填）：') || '';
        if (!r.trim()) { toast('请填写驳回原因'); return; }
        postBody('/admin/withdraw/reject/' + b.dataset.wdReject, 'reason=' + encodeURIComponent(r)).then(function (d) {
          toast(d.message); if (d.status) setTimeout(function () { location.reload(); }, 600);
        });
      });
    });
  }

  /* ---------- 图片管理：复制描述 / 编辑标题 / 人工复审 ---------- */
  function adminImageExtras() {
    // 点击审核列复制描述
    document.querySelectorAll('[data-copy-desc]').forEach(function (td) {
      td.addEventListener('click', function () {
        var v = td.getAttribute('data-copy-desc') || '';
        if (!v) { toast('无描述可复制'); return; }
        copyText(v, function (ok) { toast(ok ? '审核描述已复制' : '复制失败'); });
      });
    });

    // 编辑标题弹窗
    var m = document.getElementById('imgTitleModal');
    var inp = document.getElementById('imgTitleInput');
    var desc = document.getElementById('imgTitleDesc');
    var curId = 0;
    document.querySelectorAll('[data-img-title]').forEach(function (b) {
      b.addEventListener('click', function () {
        curId = b.dataset.imgTitle;
        inp.value = b.dataset.name || '';
        desc.value = b.dataset.desc || '';
        var tg = document.getElementById('imgTagsInput');
        if (tg) { tg.value = b.dataset.tags || ''; }
        var au = document.getElementById('imgAuditSelect');
        if (au) { au.value = b.dataset.audit || '1'; }
        m.hidden = false;
        inp.focus();
      });
    });
    if (m) {
      document.getElementById('imgTitleCancel').onclick = function () { m.hidden = true; };
      document.getElementById('imgTitleCopy').onclick = function () {
        copyText(desc.value || '', function (ok) { toast(ok ? '描述已复制' : '复制失败'); });
      };
      document.getElementById('imgTitleSave').onclick = function () {
        var t = (inp.value || '').trim();
        if (!t) { toast('标题不能为空'); return; }
        var tg = (document.getElementById('imgTagsInput') || {}).value || '';
        var au = (document.getElementById('imgAuditSelect') || {}).value || '1';
        fetch('/admin/image/update/' + curId, {
          method: 'POST', headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
          body: 'title=' + encodeURIComponent(t) + '&tags=' + encodeURIComponent(tg) + '&audit_status=' + encodeURIComponent(au)
        }).then(function (r) { return r.json(); }).then(function (d) {
          toast(d.message); if (d.status) setTimeout(function () { location.reload(); }, 600);
        }).catch(function () { toast('保存失败：网络错误'); });
      };
    }

  }

  /* ---------- 站币流水 ---------- */
  function adminCredit() {
    bindBatchDelete({checkAll:'#creditCheckAll', checkbox:'.credit-check', batchBtn:'#creditBatchDel',
      api:'/admin/credit/delete', label:'流水记录', batchBar:'#creditBar',
      barCount:'#creditCount', clearBtn:'#creditBatchClear', unit:'条'});
  }

  /* ---------- 举报管理 ---------- */
  function adminReport() {
    bindBatchDelete({checkAll:'#reportCheckAll', checkbox:'.report-check', batchBtn:'#reportBatchDel',
      api:'/admin/report/delete', label:'举报记录', batchBar:'#reportBatchBar',
      barCount:'#reportBatchCount', clearBtn:'#reportBatchClear', unit:'条'});

    document.querySelectorAll('[data-rep-confirm]').forEach(function (b) {
      b.addEventListener('click', function () {
        armConfirm(b, '确认举报成立并下架该图片？', function () {
          post('/admin/report/confirm/' + b.dataset.repConfirm).then(function (d) {
            toast(d.message); if (d.status) setTimeout(function () { location.reload(); }, 600);
          });
        });
      });
    });
    // 批量下架 / 批量驳回
    var rbc = document.getElementById('reportBatchConfirm');
    if (rbc) rbc.addEventListener('click', function () {
      var ids = Array.prototype.map.call(document.querySelectorAll('.report-check:checked'), function (c) { return c.value; });
      if (!ids.length) { toast('先勾选举报记录'); return; }
      armConfirm(rbc, '批量下架选中的 ' + ids.length + ' 条举报对应图片？（不可恢复）', function () {
        postBody('/admin/report/batch-confirm', 'ids=' + ids.join(',')).then(function (d) {
          toast(d.message); if (d.status) setTimeout(function () { location.reload(); }, 700);
        });
      });
    });
    var rbr = document.getElementById('reportBatchReject');
    if (rbr) rbr.addEventListener('click', function () {
      var ids = Array.prototype.map.call(document.querySelectorAll('.report-check:checked'), function (c) { return c.value; });
      if (!ids.length) { toast('先勾选举报记录'); return; }
      if (!confirm('批量驳回选中的 ' + ids.length + ' 条举报？')) return;
      var done = 0;
      Promise.all(ids.map(function (id) {
        return fetch('/admin/report/reject/' + id, { method: 'POST' }).then(function (r) { return r.json(); });
      })).then(function (rs) {
        done = rs.filter(function (x) { return x.status; }).length;
        toast('已驳回 ' + done + ' 条');
        setTimeout(function () { location.reload(); }, 700);
      });
    });

    document.querySelectorAll('[data-rep-unban]').forEach(function (b) {
      b.addEventListener('click', function () {
        armConfirm(b, '解除该图片的禁止公开限制？', function () {
          post('/admin/report/unban/' + b.dataset.repUnban).then(function (d) {
            toast(d.message); if (d.status) setTimeout(function () { location.reload(); }, 600);
          });
        });
      });
    });
    document.querySelectorAll('[data-rep-reject]').forEach(function (b) {
      b.addEventListener('click', function () {
        armConfirm(b, '驳回该举报？', function () {
          post('/admin/report/reject/' + b.dataset.repReject).then(function (d) {
            toast(d.message); if (d.status) setTimeout(function () { location.reload(); }, 600);
          });
        });
      });
    });
  }

  /* ---------- 提现管理 ---------- */
  function adminWithdraw() {
    bindBatchDelete({checkAll:'#wdCheckAll', checkbox:'.wd-check', batchBtn:'#wdBatchDel',
      api:'/admin/withdraw/delete', label:'提现记录', batchBar:'#wdBatchBar',
      barCount:'#wdBatchCount', clearBtn:'#wdBatchClear', unit:'条'});

    document.querySelectorAll('[data-wd-approve]').forEach(function (b) {
      b.addEventListener('click', function () {
        armConfirm(b, '通过该提现申请？', function () {
          post('/admin/withdraw/approve/' + b.dataset.wdApprove).then(function (d) {
            toast(d.message); if (d.status) setTimeout(function () { location.reload(); }, 600);
          });
        });
      });
    });
    document.querySelectorAll('[data-wd-paid]').forEach(function (b) {
      b.addEventListener('click', function () {
        var v = prompt('填写打款凭证/备注（可留空）：') || '';
        armConfirm(b, '确认已打款？', function () {
          postBody('/admin/withdraw/paid/' + b.dataset.wdPaid, 'voucher=' + encodeURIComponent(v)).then(function (d) {
            toast(d.message); if (d.status) setTimeout(function () { location.reload(); }, 600);
          });
        });
      });
    });
    document.querySelectorAll('[data-wd-reject]').forEach(function (b) {
      b.addEventListener('click', function () {
        var r = prompt('驳回原因（必填）：') || '';
        if (!r.trim()) { toast('请填写驳回原因'); return; }
        postBody('/admin/withdraw/reject/' + b.dataset.wdReject, 'reason=' + encodeURIComponent(r)).then(function (d) {
          toast(d.message); if (d.status) setTimeout(function () { location.reload(); }, 600);
        });
      });
    });
  }

  /* ---------- 标签管理 ---------- */
  function adminTag() {
    bindBatchDelete({checkAll:'#tagCheckAll', checkbox:'.tag-check', batchBtn:'#tagBatchDel', api:'/admin/tag/delete', label:'标签', batchBar:'#tagBatchBar', barCount:'#tagBatchCount', clearBtn:'#tagBatchClear', unit:'个'});
    function batchStatus(status) {
      var ids = Array.prototype.map.call(document.querySelectorAll('.tag-check:checked'), function (c) { return c.value; });
      if (!ids.length) { toast('先勾选标签'); return; }
      var label = status ? '启用' : '停用';
      var btn = document.getElementById(status ? 'tagBatchEnable' : 'tagBatchDisable');
      armConfirm(btn, '批量' + label + '选中的 ' + ids.length + ' 个标签？', function () {
        postBody('/admin/tag/batch-status', 'ids=' + ids.join(',') + '&status=' + status).then(function (d) {
          toast(d.message); if (d.status) setTimeout(function () { location.reload(); }, 500);
        });
      });
    }
    var be = document.getElementById('tagBatchEnable');
    if (be) be.addEventListener('click', function () { batchStatus(1); });
    var bd = document.getElementById('tagBatchDisable');
    if (bd) bd.addEventListener('click', function () { batchStatus(0); });

    document.querySelectorAll('[data-tag-toggle]').forEach(function (b) {
      b.addEventListener('click', function () {
        post('/admin/tag/toggle/' + b.dataset.tagToggle).then(function (d) {
          toast(d.message); if (d.status) setTimeout(function () { location.reload(); }, 400);
        });
      });
    });
    var mb = document.getElementById('mergeBtn');
    if (mb) mb.addEventListener('click', function () {
      var f = document.getElementById('mergeForm');
      var from = f.querySelector('input[name="from"]').value.trim();
      var toId = f.querySelector('input[name="to_id"]').value.trim();
      if (!from || !toId) { toast('填来源名与目标 ID'); return; }
      if (!confirm('确认合并？来源标签将被删除并转为别名')) return;
      fetch('/admin/tag/merge', {
        method: 'POST', headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: 'from=' + encodeURIComponent(from) + '&to_id=' + encodeURIComponent(toId)
      }).then(function (r) { return r.json(); }).then(function (d) {
        toast(d.message); if (d.status) setTimeout(function () { location.reload(); }, 700);
      }).catch(function () { toast('网络错误'); });
    });
  }

  /* ---------- 邮件设置 ---------- */
  function adminMail() {
    initTabs('#mailTabs');
    var f = document.getElementById('mailForm');
    if (f) f.addEventListener('submit', function (e) {
      e.preventDefault();
      postForm('/admin/mail/save', f).then(function (d) { toast(d.message); });
    });
    var pf = document.getElementById('mailPolicyForm');
    if (pf) pf.addEventListener('submit', function (e) {
      e.preventDefault();
      postForm('/admin/mail/save', pf).then(function (d) { toast(d.message); });
    });
    var au = document.getElementById('alertUnit');
    if (au) au.addEventListener('change', function () {
      var txt = au.value === 'gb' ? 'GB' : '%';
      document.querySelectorAll('.unit-label').forEach(function (el) { el.textContent = txt; });
      toast('判断口径已改为 ' + txt + '，请确认三个阈值数值后保存');
    });

    var ef = document.getElementById('eventsForm');
    if (ef) ef.addEventListener('submit', function (e) {
      e.preventDefault();
      postForm('/admin/mail/save', ef).then(function (d) { toast(d.message); });
    });
    var ea = document.getElementById('evAll');
    if (ea) ea.addEventListener('click', function () {
      document.querySelectorAll('input[name="mail_events[]"]').forEach(function (c) { c.checked = true; });
    });
    var en = document.getElementById('evNone');
    if (en) en.addEventListener('click', function () {
      document.querySelectorAll('input[name="mail_events[]"]').forEach(function (c) { c.checked = false; });
    });

    bindBatchDelete({checkAll:'#mailLogCheckAll', checkbox:'.ml-check', batchBtn:'#mailLogDel',
      api:'/admin/mail/delete-logs', label:'日志', batchBar:'#mailLogBar', barCount:'#mailLogCount',
      clearBtn:'#mailLogClearSel', unit:'条'});
    var lc = document.getElementById('mailLogClear');
    if (lc) lc.addEventListener('click', function () {
      armConfirm(lc, '清空全部发送日志? 不可恢复', function () {
        post('/admin/mail/clear-logs').then(function (d) {
          toast(d.message); if (d.status) setTimeout(function () { location.reload(); }, 500);
        });
      });
    });

    var t = document.getElementById('mailTestBtn');
    if (t) t.addEventListener('click', function () {
      var to = (document.getElementById('testTo') || {}).value || '';
      t.disabled = true; t.textContent = '发送中…';
      fetch('/admin/mail/test', {
        method: 'POST', headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: 'to=' + encodeURIComponent(to)
      }).then(function (r) { return r.json(); }).then(function (d) {
        t.disabled = false; t.textContent = '发送测试邮件';
        if (d.detail) {
          alert((d.status ? 'SMTP 已接受\n\n' : 'SMTP 报错\n\n') + d.detail);
        } else {
          toast(d.message);
        }
        if (d.status) setTimeout(function () { location.reload(); }, 1500);
      }).catch(function () { t.disabled = false; t.textContent = '发送测试邮件'; toast('网络错误'); });
    });
  }

  /* ---------- 模型设置：多卡片 ---------- */
  function adminModel() {
    document.querySelectorAll('.model-form').forEach(function (form) {
      form.addEventListener('submit', function (e) {
        e.preventDefault();
        postForm('/admin/model/save', form).then(function (d) {
          toast(d.message);
          if (d.status) setTimeout(function () { location.reload(); }, 600);
        });
      });
    });
    var df = document.getElementById('driverForm');
    if (df) df.addEventListener('submit', function (e) {
      e.preventDefault();
      postForm('/admin/model/driver', df).then(function (d) { toast(d.message); });
    });
    document.querySelectorAll('[data-model-test]').forEach(function (b) {
      b.addEventListener('click', function () {
        b.disabled = true; var orig = b.textContent; b.textContent = '测试中…';
        post('/admin/model/test/' + b.dataset.modelTest).then(function (d) {
          b.disabled = false; b.textContent = orig;
          toast(d.message || '测试完成');
        }).catch(function () {
          b.disabled = false; b.textContent = orig; toast('网络错误');
        });
      });
    });
    document.querySelectorAll('[data-model-move]').forEach(function (b) {
      b.addEventListener('click', function () {
        post('/admin/model/move/' + b.dataset.modelMove + '/' + b.dataset.dir).then(function (d) {
          toast(d.message); if (d.status) setTimeout(function () { location.reload(); }, 400);
        });
      });
    });
    document.querySelectorAll('[data-model-toggle]').forEach(function (b) {
      b.addEventListener('click', function () {
        post('/admin/model/toggle/' + b.dataset.modelToggle).then(function (d) {
          toast(d.message); if (d.status) setTimeout(function () { location.reload(); }, 400);
        });
      });
    });
    document.querySelectorAll('[data-model-del]').forEach(function (b) {
      b.addEventListener('click', function () {
        armConfirm(b, '确认删除?', function () {
          post('/admin/model/delete/' + b.dataset.modelDel).then(function (d) {
            toast(d.message); if (d.status) setTimeout(function () { location.reload(); }, 400);
          });
        });
      });
    });
  }

  /* ---------- 上传日志 ---------- */
  function adminLog() {
    document.querySelectorAll('[data-log-del]').forEach(function (b) {
      b.addEventListener('click', function () {
        armConfirm(b, '确认删除?', function () {
          post('/admin/log/delete/' + b.dataset.logDel).then(function (d) {
            toast(d.message); if (d.status) setTimeout(function () { location.reload(); }, 400);
          });
        });
      });
    });
    var all = document.getElementById('logCheckAll');
    var bar = document.getElementById('logBatchBar');
    var cnt = document.getElementById('logBatchCount');
    function refresh() {
      var checked = document.querySelectorAll('.log-check:checked');
      if (bar) bar.hidden = checked.length === 0;
      if (cnt) cnt.textContent = '已选 ' + checked.length + ' 条';
    }
    if (all) all.addEventListener('change', function () {
      document.querySelectorAll('.log-check').forEach(function (c) { c.checked = all.checked; });
      refresh();
    });
    document.querySelectorAll('.log-check').forEach(function (c) { c.addEventListener('change', refresh); });
    var clr = document.getElementById('logBatchClear');
    if (clr) clr.addEventListener('click', function () {
      document.querySelectorAll('.log-check').forEach(function (c) { c.checked = false; });
      if (all) all.checked = false;
      refresh();
    });
    document.querySelectorAll('[data-log-batch]').forEach(function (btn) {
      btn.addEventListener('click', function () {
        var ids = Array.prototype.map.call(document.querySelectorAll('.log-check:checked'), function (c) { return c.value; });
        if (!ids.length) return;
        if (!confirm('确认删除 ' + ids.length + ' 条日志？')) return;
        fetch('/admin/log/batch', {
          method: 'POST', headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
          body: 'action=delete&ids=' + ids.join(',')
        }).then(function (r) { return r.json(); }).then(function (d) {
          toast(d.message); if (d.status) setTimeout(function () { location.reload(); }, 500);
        }).catch(function () { toast('网络错误'); });
      });
    });
    var clean = document.getElementById('logCleanBtn');
    if (clean) clean.addEventListener('click', function () {
      armConfirm(clean, '确认清理?', function () {
        fetch('/admin/log/batch', {
          method: 'POST', headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
          body: 'action=clean&days=30'
        }).then(function (r) { return r.json(); }).then(function (d) {
          toast(d.message); if (d.status) setTimeout(function () { location.reload(); }, 500);
        }).catch(function () { toast('网络错误'); });
      });
    });
  }

  /* ---------- 广告管理 ---------- */
  function adminAd() {
    document.querySelectorAll('[data-slot-form]').forEach(function (form) {
      form.addEventListener('submit', function (e) {
        e.preventDefault();
        postForm('/admin/ad/slot', form).then(function (d) { toast(d.message); });
      });
    });
  }

  /* ---------- Token ---------- */
  function adminToken() {
    document.querySelectorAll('[data-token-toggle]').forEach(function (b) {
      b.addEventListener('click', function () {
        post('/admin/token/toggle/' + b.dataset.tokenToggle).then(function (d) { d.status ? location.reload() : toast(d.message); });
      });
    });

    /* 行内编辑：名称/日上限/容量上限 */
    document.querySelectorAll('[data-token-edit]').forEach(function (btn) {
      btn.addEventListener('click', function () {
        var tid = btn.dataset.tokenEdit;
        if (document.getElementById('token-edit-' + tid)) return;
        var tr = btn.closest('tr');
        var t = (window.__tokens || []).filter(function (x) { return String(x.id) === String(tid); })[0] || {};
        var quotaMb = t.quota_limit ? Math.round(t.quota_limit / 1048576) : 0;
        var er = document.createElement('tr');
        er.id = 'token-edit-' + tid;
        er.innerHTML = '<td colspan="11"><div class="user-edit-bar">' +
          '<label>名称 <input type="text" class="te-name" value="' + (t.name || '') + '"></label>' +
          '<label>日调用上限 <input type="number" class="te-daily" value="' + (t.daily_limit || 0) + '" style="width:90px"></label>' +
          '<label>容量上限(MB) <input type="number" class="te-quota" value="' + quotaMb + '" style="width:90px"></label>' +
          '<button class="btn btn-sm te-save">保存</button><button class="btn-ghost-sm te-cancel">取消</button>' +
          '</div></td>';
        tr.parentNode.insertBefore(er, tr.nextSibling);
        er.querySelector('.te-cancel').onclick = function () { er.remove(); };
        er.querySelector('.te-save').onclick = function () {
          var body = 'name=' + encodeURIComponent(er.querySelector('.te-name').value)
            + '&daily_limit=' + er.querySelector('.te-daily').value
            + '&quota_limit_mb=' + er.querySelector('.te-quota').value;
          fetch('/admin/token/save/' + tid, {
            method: 'POST', headers: { 'Content-Type': 'application/x-www-form-urlencoded' }, body: body
          }).then(function (r) { return r.json(); }).then(function (d) {
            toast(d.message); if (d.status) setTimeout(function () { location.reload(); }, 700);
          }).catch(function () { toast('网络错误'); });
        };
      });
    });

    /* 批量 */
    var all = document.getElementById('tokenCheckAll');
    var bar = document.getElementById('tokenBatchBar');
    var cnt = document.getElementById('tokenBatchCount');
    function refresh() {
      var checked = document.querySelectorAll('.token-check:checked');
      if (bar) bar.hidden = checked.length === 0;
      if (cnt) cnt.textContent = '已选 ' + checked.length + ' 个';
    }
    if (all) all.addEventListener('change', function () {
      document.querySelectorAll('.token-check').forEach(function (c) { c.checked = all.checked; });
      refresh();
    });
    document.querySelectorAll('.token-check').forEach(function (c) { c.addEventListener('change', refresh); });
    var clr = document.getElementById('tokenBatchClear');
    if (clr) clr.addEventListener('click', function () {
      document.querySelectorAll('.token-check').forEach(function (c) { c.checked = false; });
      if (all) all.checked = false;
      refresh();
    });
    document.querySelectorAll('[data-token-batch]').forEach(function (btn) {
      btn.addEventListener('click', function () {
        var ids = Array.prototype.map.call(document.querySelectorAll('.token-check:checked'), function (c) { return c.value; });
        if (!ids.length) return;
        var act = btn.dataset.tokenBatch;
        if (!confirm('确认对 ' + ids.length + ' 个 Token 执行该操作？')) return;
        fetch('/admin/token/batch', {
          method: 'POST', headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
          body: 'action=' + act + '&ids=' + ids.join(',')
        }).then(function (r) { return r.json(); }).then(function (d) {
          toast(d.message); if (d.status) setTimeout(function () { location.reload(); }, 700);
        }).catch(function () { toast('网络错误'); });
      });
    });
    refresh();
  }
})();
