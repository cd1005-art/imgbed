/* imgbed.js — 上传/复制/删除/Token 交互（无依赖） */
(function () {
  'use strict';

  /* ---------- Toast ---------- */
  var toastEl = document.getElementById('toast');
  var toastTimer = null;
  function toast(msg) {
    if (!toastEl) { toastEl = document.createElement('div'); toastEl.id = 'toast'; toastEl.className = 'toast'; document.body.appendChild(toastEl); }
    toastEl.textContent = msg;
    toastEl.hidden = false;
    clearTimeout(toastTimer);
    toastTimer = setTimeout(function () { toastEl.hidden = true; }, 2000);
  }
  window.toast = toast;

  /* ---------- 复制 ---------- */
  function copyText(text, done) {
    if (navigator.clipboard && window.isSecureContext) {
      navigator.clipboard.writeText(text).then(function () { done(true); }, function () { fallback(); });
    } else { fallback(); }
    function fallback() {
      var ta = document.createElement('textarea');
      ta.value = text; ta.style.position = 'fixed'; ta.style.opacity = '0';
      document.body.appendChild(ta); ta.select();
      try { document.execCommand('copy'); done(true); } catch (e) { done(false); }
      document.body.removeChild(ta);
    }
  }

  function bindCopy(root) {
    root.querySelectorAll('[data-copy]').forEach(function (btn) {
      btn.addEventListener('click', function () {
        var src = document.querySelector(btn.getAttribute('data-copy'));
        if (src) copyText(src.textContent, function (ok) { toast(ok ? '已复制' : '复制失败，手动选中复制'); });
      });
    });
  }

  /* ---------- 页面引导 ---------- */
  window.pageBoot = function (page) {
    // 单个页面初始化异常不应中断后续内联脚本（如详情页的举报/解锁绑定）
    try { if (page === 'upload') bootUpload(); } catch (e) { console.error('bootUpload', e); }
    try { if (page === 'gallery') bootGallery(); } catch (e) { console.error('bootGallery', e); }
    try { if (page === 'console') bootConsole(); } catch (e) { console.error('bootConsole', e); }
  };

  /* ---------- 上传页 ---------- */
  function bootUpload() {
    var zone = document.getElementById('dropZone');
    var input = document.getElementById('fileInput');
    var box = document.getElementById('resultBox');
    var progress = document.getElementById('progress');
    var bar = document.getElementById('progressBar');
    if (!zone || !input) return;

    zone.addEventListener('click', function () { input.click(); });
    zone.addEventListener('keydown', function (e) { if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); input.click(); } });
    zone.addEventListener('dragover', function (e) { e.preventDefault(); zone.classList.add('dragover'); });
    zone.addEventListener('dragleave', function () { zone.classList.remove('dragover'); });
    zone.addEventListener('drop', function (e) {
      e.preventDefault(); zone.classList.remove('dragover');
      if (e.dataTransfer.files.length) enqueue(Array.prototype.slice.call(e.dataTransfer.files));
    });
    input.addEventListener('change', function () {
      if (input.files.length) enqueue(Array.prototype.slice.call(input.files));
      input.value = '';
    });
    // 粘贴上传
    document.addEventListener('paste', function (e) {
      var items = e.clipboardData && e.clipboardData.files;
      if (items && items.length) enqueue(Array.prototype.slice.call(items));
    });

    document.getElementById('continueBtn').addEventListener('click', function () {
      box.hidden = true; zone.hidden = false;
    });

    var floatBox = document.getElementById('uploadFloat');
    var floatPct = document.getElementById('ufPct');
    var floatFill = document.getElementById('ufFill');

    /* ---------- 上传队列：多项排队 + 失败重试 + 拖拽排序 ---------- */
    var queue = [];
    var seq = 0;
    var busy = false;
    var qbox = document.getElementById('uploadQueue');
    var dragId = 0;
    var lastOk = null;

    var MAX_BATCH = parseInt(window.UPLOAD_MAX_BATCH || 100, 10);   // 单次入队上限（策略组配置，0=不限）
    var AUTO_RETRY = 1;    // 服务器繁忙时自动重试次数

    function enqueue(files) {
      if (MAX_BATCH > 0 && files.length > MAX_BATCH) {
        toast('一次最多 ' + MAX_BATCH + ' 张，已截取前 ' + MAX_BATCH + ' 张，其余请分批再传');
        files = files.slice(0, MAX_BATCH);
      }
      // 前端预检：超过策略单图上限的直接拦下，不浪费流量
      var maxBytes = parseInt(window.UPLOAD_MAX_BYTES || 0, 10);
      if (maxBytes > 0) {
        var passed = [];
        files.forEach(function (f) {
          if (f.size > maxBytes) {
            toast(f.name + ' 超过单图上限 ' + (maxBytes / 1048576).toFixed(1) + 'MB');
          } else {
            passed.push(f);
          }
        });
        files = passed;
        if (!files.length) { return; }
      }
      files.forEach(function (f) {
        seq += 1;
        queue.push({ id: seq, file: f, status: 'wait', pct: 0, result: null, err: '' });
      });
      if (qbox) qbox.hidden = false;
      render();
      pump();
    }

    function pump() {
      if (busy) { return; }
      var job = null;
      for (var i = 0; i < queue.length; i += 1) {
        if (queue[i].status === 'wait') { job = queue[i]; break; }
      }
      if (!job) { return; }
      busy = true;
      job.status = 'up';
      render();
      send(job);
    }

    function send(job) {
      var fd = new FormData();
      fd.append('file', job.file);
      var xhr = new XMLHttpRequest();
      xhr.open('POST', '/upload/save');
      xhr.upload.onprogress = function (e) {
        if (!e.lengthComputable) { return; }
        job.pct = Math.round(e.loaded / e.total * 100);
        var el = qbox && qbox.querySelector('[data-bar="' + job.id + '"]');
        if (el) { el.style.width = job.pct + '%'; }
        var st = qbox && qbox.querySelector('[data-status="' + job.id + '"]');
        if (st && job.pct >= 100) { st.textContent = '处理中…'; }
        // 顶部进度条同步当前项
        if (progress) { progress.hidden = false; }
        if (bar) { bar.style.width = job.pct + '%'; }
        if (floatBox) {
          floatBox.hidden = false;
          floatFill.style.width = job.pct + '%';
          floatPct.textContent = job.pct >= 100 ? '处理中…' : job.pct + '%';
        }
      };
      xhr.onload = function () {
        busy = false;
        var data = null;
        try { data = JSON.parse(xhr.responseText); } catch (e) { data = null; }
        if (progress) { progress.hidden = true; }
        if (floatBox) { floatBox.hidden = true; }
        if (bar) { bar.style.width = '0%'; }

        if (data && data.status) {
          job.status = 'ok';
          job.pct = 100;
          job.result = data.data;
          lastOk = { data: data.data, file: job.file };
        } else {
          var failMsg = !data
            ? (xhr.status === 0 ? '网络中断或超时'
              : ('服务端错误 ' + xhr.status + (xhr.status >= 500 ? '（服务器繁忙，稍后自动重试）' : '')))
            : (data.message || '上传失败');

          // 服务器繁忙 / 网络异常：自动重试一次（业务错误如超限、格式不允许则不重试）
          var retriable = !data && (xhr.status === 0 || xhr.status >= 500);
          if (retriable && (job.retried || 0) < AUTO_RETRY) {
            job.retried = (job.retried || 0) + 1;
            job.status = 'wait';
            job.pct = 0;
            job.err = '';
            render();
            setTimeout(function () { pump(); }, 1500);
            return;
          }

          job.status = 'fail';
          job.err = failMsg;
          toast(failMsg);
          if (qbox) { qbox.scrollIntoView({ block: 'nearest', behavior: 'smooth' }); }
        }
        render();
        var pending = queue.some(function (q) { return q.status === 'wait' || q.status === 'up'; });
        if (!pending && lastOk) {
          // 队列跑完：显示上传结果页（大图 + 四格式链接）
          showResult(lastOk.data, lastOk.file);
          lastOk = null;
        }
        pump();
      };
      xhr.onerror = function () {
        busy = false;
        job.status = 'fail';
        job.err = '网络错误，请重试';
        toast(job.err);
        render();
        pump();
      };
      xhr.send(fd);
    }

    function render() {
      if (!qbox) { return; }
      qbox.innerHTML = queue.map(function (q) {
        var cls = q.status === 'up' ? 'up' : (q.status === 'ok' ? 'ok' : (q.status === 'fail' ? 'fail' : 'wait'));
        var statusText = q.status === 'wait' ? '排队中'
          : (q.status === 'up' ? (q.pct >= 100 ? '处理中…' : q.pct + '%')
          : (q.status === 'ok' ? '已完成' : (q.err || '失败')));
        var actions = '';
        if (q.status === 'ok') {
          actions = '<button class="btn-ghost-sm" type="button" data-copy="' + q.id + '">复制链接</button>'
                  + '<button class="btn-ghost-sm" type="button" data-open="' + q.id + '">打开</button>';
        } else if (q.status === 'fail') {
          actions = '<button class="btn-ghost-sm btn-danger-sm" type="button" data-retry="' + q.id + '">重试</button>';
        } else if (q.status === 'wait') {
          actions = '<button class="btn-ghost-sm" type="button" data-remove="' + q.id + '">移除</button>';
        }
        return '<div class="uq-item" draggable="' + (q.status === 'wait' ? 'true' : 'false') + '" data-qid="' + q.id + '">'
          + '<span class="uq-handle" title="拖动调整顺序">⋮⋮</span>'
          + '<div class="uq-main">'
          +   '<div class="uq-name" title="' + (q.file.name || '') + '">' + (q.file.name || '图片') + '</div>'
          +   '<div class="uq-meta"><span class="uq-status ' + cls + '" data-status="' + q.id + '">' + statusText + '</span>'
          +     ' · ' + (q.file.size / 1024).toFixed(1) + ' KB</div>'
          +   '<div class="uq-bar"><i data-bar="' + q.id + '" style="width:' + (q.status === 'ok' ? 100 : q.pct) + '%"></i></div>'
          + '</div>'
          + '<div class="uq-act">' + actions + '</div>'
          + '</div>';
      }).join('');
      bindQueueEvents();
    }

    function bindQueueEvents() {
      if (!qbox) { return; }
      qbox.querySelectorAll('[data-retry]').forEach(function (b) {
        b.onclick = function () {
          var id = parseInt(b.getAttribute('data-retry'), 10);
          var q = queue.filter(function (x) { return x.id === id; })[0];
          if (q) { q.status = 'wait'; q.pct = 0; q.err = ''; render(); pump(); }
        };
      });
      qbox.querySelectorAll('[data-remove]').forEach(function (b) {
        b.onclick = function () {
          var id = parseInt(b.getAttribute('data-remove'), 10);
          queue = queue.filter(function (x) { return x.id !== id; });
          render(); pump();
        };
      });
      qbox.querySelectorAll('[data-copy]').forEach(function (b) {
        b.onclick = function () {
          var id = parseInt(b.getAttribute('data-copy'), 10);
          var q = queue.filter(function (x) { return x.id === id; })[0];
          if (q && q.result) { copyText(q.result.url, function (ok) { toast(ok ? '已复制' : '复制失败'); }); }
        };
      });
      qbox.querySelectorAll('[data-open]').forEach(function (b) {
        b.onclick = function () {
          var id = parseInt(b.getAttribute('data-open'), 10);
          var q = queue.filter(function (x) { return x.id === id; })[0];
          if (q && q.result) { window.open(q.result.url, '_blank'); }
        };
      });
      // 拖拽排序（仅排队中的项可拖）
      qbox.querySelectorAll('.uq-item').forEach(function (el) {
        el.addEventListener('dragstart', function () {
          if (el.getAttribute('draggable') !== 'true') { return; }
          dragId = parseInt(el.getAttribute('data-qid'), 10);
          el.classList.add('is-drag');
        });
        el.addEventListener('dragend', function () { el.classList.remove('is-drag'); });
        el.addEventListener('dragover', function (e) {
          if (!dragId) { return; }
          e.preventDefault();
          el.classList.add('is-over');
        });
        el.addEventListener('dragleave', function () { el.classList.remove('is-over'); });
        el.addEventListener('drop', function (e) {
          e.preventDefault();
          el.classList.remove('is-over');
          var toId = parseInt(el.getAttribute('data-qid'), 10);
          if (!dragId || dragId === toId) { dragId = 0; return; }
          var from = queue.findIndex(function (x) { return x.id === dragId; });
          var to = queue.findIndex(function (x) { return x.id === toId; });
          if (from >= 0 && to >= 0) {
            var moved = queue.splice(from, 1)[0];
            queue.splice(to, 0, moved);
          }
          dragId = 0;
          render();
        });
      });
    }

    function showResult(d, file) {
      zone.hidden = true;
      box.hidden = false;
      var thumb = document.getElementById('resultThumb');
      thumb.src = d.url;
      document.getElementById('resultName').textContent = d.name;
      document.getElementById('resultSub').textContent =
        d.width + '×' + d.height + ' · ' + (d.size / 1024).toFixed(1) + ' KB' + (d.reused ? ' · 秒传复用' : '');
      var urlEl = document.getElementById('resultUrl');
      urlEl.textContent = d.url;
      box.querySelectorAll('[data-fmt]').forEach(function (b) {
        b.onclick = function () {
          copyText(d[b.getAttribute('data-fmt')] || d.url, function (ok) { toast(ok ? '已复制 ' + b.textContent : '复制失败'); });
        };
      });
      var cp = box.querySelector('[data-copy="#resultUrl"]');
      if (cp) cp.onclick = function () { copyText(d.url, function (ok) { toast(ok ? '已复制' : '复制失败'); }); };
    }
  }

  /* ---------- 图片库 ---------- */
  function bootGallery() {
    document.querySelectorAll('[data-copy-url]').forEach(function (btn) {
      btn.addEventListener('click', function () {
        var url = location.origin + btn.getAttribute('data-copy-url');
        copyText(url, function (ok) { toast(ok ? '已复制外链' : '复制失败'); });
      });
    });

    // 两段式删除（行内确认，不用弹窗）
    // ---- 解锁定价 ----
    document.querySelectorAll('[data-price]').forEach(function (b) {
      b.addEventListener('click', function () {
        var cur = b.getAttribute('data-cur') || '0';
        var v = prompt('设置该图解锁价格（站币，0=免费）', cur);
        if (v === null) return;
        postJson('/gallery/price/' + b.getAttribute('data-price'), 'price=' + encodeURIComponent(v));
      });
    });

    // ---- 公开/私密 ----
    function reload() { setTimeout(function () { location.reload(); }, 500); }
    function postJson(url, body, done) {
      fetch(url, {
        method: 'POST', headers: { 'Content-Type': 'application/x-www-form-urlencoded' }, body: body || ''
      }).then(function (r) { return r.json(); })
        .then(function (d) { toast(d.message); if (d.status) { done ? done() : reload(); } })
        .catch(function () { toast('网络错误'); });
    }
    document.querySelectorAll('[data-vis]').forEach(function (b) {
      b.addEventListener('click', function () {
        postJson('/gallery/visibility/' + b.getAttribute('data-vis'), 'action=' + b.getAttribute('data-to'));
      });
    });
    var all = document.getElementById('selectAll');
    if (all) all.addEventListener('click', function () {
      var boxes = document.querySelectorAll('.g-check');
      var on = all.textContent === '全选';
      boxes.forEach(function (c) { c.checked = on; });
      all.textContent = on ? '取消全选' : '全选';
    });
    function checkedIds() {
      return Array.prototype.map.call(document.querySelectorAll('.g-check:checked'), function (c) { return c.value; });
    }
    var bd = document.getElementById('bulkDelete');
    if (bd) bd.addEventListener('click', function () {
      var ids = Array.prototype.map.call(document.querySelectorAll('.g-check:checked'), function (c) { return c.value; });
      if (!ids.length) { toast('先勾选图片'); return; }
      if (!confirm('删除选中的 ' + ids.length + ' 张图片？\n\n图片与文件将被永久删除，配额会释放，不可恢复！')) return;
      fetch('/gallery/batch-delete', {
        method: 'POST', headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: 'ids=' + ids.join(',')
      }).then(function (r) { return r.json(); }).then(function (d) {
        toast(d.message); if (d.status) setTimeout(function () { location.reload(); }, 800);
      }).catch(function () { toast('删除失败：网络错误'); });
    });

    document.querySelectorAll('[data-bulk]').forEach(function (b) {
      b.addEventListener('click', function () {
        var ids = checkedIds();
        if (!ids.length) { toast('先勾选图片'); return; }
        postJson('/gallery/batch-visibility', 'mode=' + b.getAttribute('data-bulk') + '&ids=' + ids.join(','));
      });
    });
    document.querySelectorAll('[data-all]').forEach(function (b) {
      b.addEventListener('click', function () {
        var mode = b.getAttribute('data-all');
        if (!confirm(mode === 'public' ? '将把所有审核通过的图片公开？' : '将把所有图片设为私密？')) return;
        postJson('/gallery/all-visibility', 'mode=' + mode);
      });
    });
    var aps = document.getElementById('autoPublicSwitch');
    if (aps) aps.addEventListener('change', function () {
      postJson('/gallery/auto-public', 'on=' + (aps.checked ? 1 : 0), function () {});
    });

    document.querySelectorAll('[data-del]').forEach(function (btn) {
      btn.addEventListener('click', function () {
        if (!btn.classList.contains('confirming')) {
          btn.classList.add('confirming');
          btn.textContent = '确认删除';
          setTimeout(function () { btn.classList.remove('confirming'); btn.innerHTML = '<svg class="ic"><use href="#i-trash"/></svg>'; }, 3000);
          return;
        }
        var id = btn.getAttribute('data-del');
        fetch('/gallery/delete/' + id, { method: 'POST' })
          .then(function (r) { return r.json(); })
          .then(function (data) {
            if (data.status) {
              var cell = btn.closest('.cell');
              if (cell) cell.remove();
              toast('已删除');
            } else { toast(data.message || '删除失败'); }
          })
          .catch(function () { toast('网络错误'); });
      });
    });
  }

  /* ---------- 个人中心 ---------- */
  /** 通用 Tabs（前台复用后台同款行为） */
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
    btns.forEach(function (b) { b.addEventListener('click', function () { show(b.getAttribute('data-tab'), true); }); });
    var qs = new URLSearchParams(location.search || '');
    show((location.hash || '').replace('#', '') || qs.get('tab') || (btns[0] ? btns[0].getAttribute('data-tab') : ''), false);
  }

  function bootConsole() {
    initTabs('#consoleTabs');
    initTabs('#creditSubTabs');
    initTabs('#withdrawSubTabs');
    var createBtn = document.getElementById('tokenCreateBtn');
    if (createBtn) {
      createBtn.addEventListener('click', function () {
        var name = document.getElementById('tokenName').value;
        var scopes = [];
        document.querySelectorAll('.scope-cb:checked').forEach(function (cb) { scopes.push(cb.value); });
        fetch('/console/token/create', {
          method: 'POST',
          headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
          body: 'name=' + encodeURIComponent(name) + scopes.map(function (s) { return '&scopes[]=' + s; }).join('')
        }).then(function (r) { return r.json(); }).then(function (data) {
          if (!data.status) { toast(data.message); return; }
          var box = document.getElementById('tokenNew');
          document.getElementById('tokenNewVal').textContent = data.data.token;
          box.hidden = false;
          toast('Token 已生成');
          var cp = document.getElementById('tokenNewCopy');
          if (cp) cp.onclick = function () {
            copyText(data.data.token, function (ok) { toast(ok ? '已复制 Token' : '复制失败，手动选中复制'); });
          };
        }).catch(function () { toast('网络错误'); });
      });
    }

    document.querySelectorAll('[data-recharge]').forEach(function (b) {
      b.addEventListener('click', function () {
        var form = document.createElement('form');
        form.method = 'POST'; form.action = '/console/recharge';
        var inp = document.createElement('input');
        inp.type = 'hidden'; inp.name = 'yuan'; inp.value = b.getAttribute('data-recharge');
        if (b.getAttribute('data-plan')) {
          var pi = document.createElement('input');
          pi.type = 'hidden'; pi.name = 'plan_id'; pi.value = b.getAttribute('data-plan');
          form.appendChild(pi);
        }
        form.appendChild(inp); document.body.appendChild(form); form.submit();
      });
    });
    // 到账预估：金额 × 比例 + 满额赠送
    var prev = document.getElementById('rechargePreview');
    var rcInput = document.getElementById('customRecharge');
    if (prev && rcInput) {
      prev.dataset.baseText = prev.textContent;
      var calcPreview = function () {
        var v = parseInt(rcInput.value || '0', 10);
        if (!v) { prev.textContent = prev.dataset.baseText; return; }
        var rate = parseInt(prev.getAttribute('data-rate') || '10', 10);
        var bonuses = [];
        try { bonuses = JSON.parse(prev.getAttribute('data-bonus') || '[]') || []; } catch (e) { bonuses = []; }
        var best = 0;
        bonuses.forEach(function (b) {
          if (v >= parseInt(b.amount || 0, 10)) { best = Math.max(best, parseInt(b.bonus || 0, 10)); }
        });
        var coins = v * rate + best;
        prev.innerHTML = '到账预估：<b>' + coins + '</b> 币' + (best > 0 ? '（含满额赠送 ' + best + ' 币）' : '') + '，支付成功后自动到账。';
      };
      rcInput.addEventListener('input', calcPreview);
    }

    var crb = document.getElementById('customRechargeBtn');
    if (crb) crb.addEventListener('click', function () {
      var el = document.getElementById('customRecharge');
      var v = parseInt(el.value || '0', 10);
      var min = parseInt(el.getAttribute('min') || '1', 10);
      if (!v || v < min) { toast('最低充值 ' + min + ' 元'); return; }
      var form = document.createElement('form');
      form.method = 'POST';
      form.action = '/console/recharge';
      [['yuan', v]].forEach(function (kv) {
        var i = document.createElement('input');
        i.type = 'hidden'; i.name = kv[0]; i.value = kv[1];
        form.appendChild(i);
      });
      document.body.appendChild(form);
      form.submit();
    });

    var af = document.getElementById('alipayForm');
    if (af) af.addEventListener('submit', function (e) {
      e.preventDefault();
      fetch('/console/alipay', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: new URLSearchParams(new FormData(af)).toString()
      }).then(function (r) { return r.json(); }).then(function (d) {
        toast(d.message);
        if (d.status) setTimeout(function () { location.reload(); }, 700);
      }).catch(function () { toast('保存失败：网络错误'); });
    });

    var wf = document.getElementById('withdrawForm');
    if (wf) wf.addEventListener('submit', function (e) {
      e.preventDefault();
      var body = new URLSearchParams(new FormData(wf)).toString();
      fetch('/console/withdraw', {
        method: 'POST', headers: { 'Content-Type': 'application/x-www-form-urlencoded' }, body: body
      }).then(function (r) { return r.json(); }).then(function (d) {
        toast(d.message); if (d.status) setTimeout(function () { location.reload(); }, 800);
      }).catch(function () { toast('网络错误'); });
    });

    var vs = document.getElementById('verifySubmitBtn');
    if (vs) vs.addEventListener('click', function () {
      var code = (document.getElementById('verifyCodeInput') || {}).value || '';
      if (!code) { toast('请输入验证码'); return; }
      fetch('/verify-code', {
        method: 'POST', headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: 'code=' + encodeURIComponent(code)
      }).then(function (r) { return r.json(); }).then(function (d) {
        toast(d.message); if (d.status) setTimeout(function () { location.reload(); }, 800);
      }).catch(function () { toast('网络错误'); });
    });

    var rv = document.getElementById('resendVerifyBtn');
    if (rv) rv.addEventListener('click', function () {
      rv.disabled = true; rv.textContent = '发送中…';
      fetch('/console/resend-verify', { method: 'POST' })
        .then(function (r) { return r.json(); })
        .then(function (d) {
          rv.disabled = false; rv.textContent = '发送验证码';
          toast(d.message);
        })
        .catch(function () { rv.disabled = false; rv.textContent = '发送验证码'; toast('网络错误'); });
    });

    document.querySelectorAll('[data-token-del]').forEach(function (btn) {
      btn.addEventListener('click', function () {
        if (!btn.classList.contains('confirming')) {
          btn.classList.add('confirming');
          btn.textContent = '确认';
          setTimeout(function () { btn.classList.remove('confirming'); btn.innerHTML = '<svg class="ic"><use href="#i-trash"/></svg>'; }, 3000);
          return;
        }
        fetch('/console/token/delete/' + btn.getAttribute('data-token-del'), { method: 'POST' })
          .then(function (r) { return r.json(); })
          .then(function (data) {
            if (data.status) { btn.closest('.token-item').remove(); toast('已删除'); }
            else { toast(data.message || '删除失败'); }
          }).catch(function () { toast('网络错误'); });
      });
    });

    var pf = document.getElementById('profileForm');
    if (pf) {
      pf.addEventListener('submit', function (e) {
        e.preventDefault();
        var fd = new FormData(pf);
        var qs = new URLSearchParams(fd).toString();
        fetch('/console/profile', { method: 'POST', headers: { 'Content-Type': 'application/x-www-form-urlencoded' }, body: qs })
          .then(function (r) { return r.json(); })
          .then(function (data) { toast(data.message); if (data.status && fd.get('new_password')) pf.reset(); })
          .catch(function () { toast('网络错误'); });
      });
    }
  }
})();

/* ---------- 分页增强：动态页码块（每块 5 页）+ 跳转指定页 ---------- */
(function () {
  function goToPage(param, page, total) {
    var v = Math.max(1, Math.min(total, parseInt(page, 10) || 1));
    var u = new URL(location.href);
    u.searchParams.set(param, v);
    location.href = u.toString();
  }

  function initPagerJump() {
    document.querySelectorAll('.pager').forEach(function (box) {
      if (box.querySelector('[data-pager-pages]')) { return; }

      var param = null;
      box.querySelectorAll('a[href]').forEach(function (x) {
        if (param) { return; }
        var m = (x.getAttribute('href') || '').match(/[?&](page|upage|pno)=(\d+)/);
        if (m) { param = m[1]; }
      });
      if (!param) { return; }

      var txt = (box.textContent || '').replace(/\s+/g, ' ');
      var tm = txt.match(/(\d+)\s*\/\s*(\d+)/);
      if (!tm) { return; }
      var cur = parseInt(tm[1], 10) || 1;
      var total = parseInt(tm[2], 10) || 0;
      if (total < 2) { return; }

      // 页码块：滑动窗口，起点=当前页，尾部不足时回退凑满
      var PER = Math.max(3, Math.min(20, parseInt(window.PAGER_SIZE, 10) || 5));
      var start = cur;
      var end = Math.min(start + PER - 1, total);
      if (end - start + 1 < PER) {
        start = Math.max(1, end - PER + 1);
      }

      var pbox = document.createElement('span');
      pbox.setAttribute('data-pager-pages', '1');
      pbox.style.cssText = 'display:inline-flex;gap:4px;align-items:center;margin-left:10px';

      var html = '';
      if (start > 1) {
        html += '<button type="button" class="btn-ghost-sm" data-pg="' + Math.max(1, cur - PER) + '" title="上一组">«</button>';
      }
      for (var i = start; i <= end; i++) {
        html += '<button type="button" class="' + (i === cur ? 'btn btn-sm' : 'btn-ghost-sm') + '" data-pg="' + i + '">' + i + '</button>';
      }
      if (end < total) {
        html += '<button type="button" class="btn-ghost-sm" data-pg="' + Math.min(total, cur + PER) + '" title="下一组">»</button>';
      }
      pbox.innerHTML = html;
      pbox.addEventListener('click', function (e) {
        var t = e.target.closest ? e.target.closest('[data-pg]') : null;
        if (t) { goToPage(param, t.getAttribute('data-pg'), total); }
      });
      box.appendChild(pbox);

      // 跳转输入框
      var wrap = document.createElement('span');
      wrap.setAttribute('data-pager-jump', '1');
      wrap.style.cssText = 'margin-left:10px;display:inline-flex;align-items:center;gap:6px';
      wrap.innerHTML = '<span class="count">跳至</span>'
        + '<input type="number" min="1" max="' + total + '" value="' + cur + '" style="width:58px">'
        + '<span class="count">/ ' + total + ' 页</span>'
        + '<button type="button" class="btn btn-sm">跳转</button>';
      var inp = wrap.querySelector('input');
      var btn = wrap.querySelector('button');
      btn.addEventListener('click', function () { goToPage(param, inp.value, total); });
      inp.addEventListener('keydown', function (e) { if (e.key === 'Enter') { goToPage(param, inp.value, total); } });
      box.appendChild(wrap);
    });
  }

  window.initPagerJump = initPagerJump;
  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', initPagerJump);
  } else {
    initPagerJump();
  }
})();

/* ---------- 深色/浅色主题切换（全站，localStorage 持久化） ---------- */
(function () {
  // 前后台主题互不影响：按 body 是否 admin-body 动态取 key
  function themeKey() {
    if (document.body && document.body.classList.contains('admin-body')) {
      return 'imgbed-theme-admin';
    }
    // 前台：按登录用户区分（不同账号各自记住自己的选择）
    var uid = parseInt(window.CURRENT_UID || 0, 10);
    return uid > 0 ? 'imgbed-theme-u' + uid : 'imgbed-theme-guest';
  }
  function apply(t) {
    document.documentElement.setAttribute('data-theme', t);
    try { localStorage.setItem(themeKey(), t); } catch (e) {}
    var fab = document.getElementById('themeFab');
    if (fab) { fab.textContent = t === 'dark' ? '☀' : '◐'; fab.title = t === 'dark' ? '切换到浅色' : '切换到深色'; }
  }
  function init() {
    var saved = '';
    try { saved = localStorage.getItem(themeKey()) || ''; } catch (e) {}
    if (saved === 'dark' || saved === 'light') { apply(saved); }
    var fab = document.createElement('button');
    fab.id = 'themeFab';
    fab.className = 'theme-fab';
    fab.type = 'button';
    fab.textContent = document.documentElement.getAttribute('data-theme') === 'dark' ? '☀' : '◐';
    fab.title = '切换深色/浅色';
    fab.addEventListener('click', function () {
      var cur = document.documentElement.getAttribute('data-theme') === 'dark' ? 'dark' : 'light';
      apply(cur === 'dark' ? 'light' : 'dark');
    });
    document.body.appendChild(fab);
  }
  if (document.readyState === 'loading') { document.addEventListener('DOMContentLoaded', init); } else { init(); }
})();
