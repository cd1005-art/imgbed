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
      if (e.dataTransfer.files.length) upload(e.dataTransfer.files[0]);
    });
    input.addEventListener('change', function () {
      if (input.files.length) upload(input.files[0]);
      input.value = '';
    });
    // 粘贴上传
    document.addEventListener('paste', function (e) {
      var items = e.clipboardData && e.clipboardData.files;
      if (items && items.length) upload(items[0]);
    });

    document.getElementById('continueBtn').addEventListener('click', function () {
      box.hidden = true; zone.hidden = false;
    });

    var floatBox = document.getElementById('uploadFloat');
    var floatPct = document.getElementById('ufPct');
    var floatFill = document.getElementById('ufFill');

    function upload(file) {
      zone.classList.add('uploading');
      progress.hidden = false; bar.style.width = '0%';
      if (floatBox) { floatBox.hidden = false; floatFill.style.width = '0%'; floatPct.textContent = '0%'; }

      var fd = new FormData();
      fd.append('file', file);
      var xhr = new XMLHttpRequest();
      xhr.open('POST', '/upload/save');
      xhr.upload.onprogress = function (e) {
        if (!e.lengthComputable) return;
        var pct = Math.round(e.loaded / e.total * 100);
        bar.style.width = pct + '%';
        if (floatBox) {
          floatFill.style.width = pct + '%';
          floatPct.textContent = pct + '%';
          if (pct >= 100) { floatPct.textContent = '处理中…'; }
        }
      };
      xhr.onload = function () {
        zone.classList.remove('uploading');
        progress.hidden = true;
        if (floatBox) floatBox.hidden = true;
        var data;
        try { data = JSON.parse(xhr.responseText); } catch (e) { toast('服务返回异常，看下服务器日志'); return; }
        if (!data.status) { toast(data.message || '上传失败'); return; }
        showResult(data.data, file);
      };
      xhr.onerror = function () { zone.classList.remove('uploading'); progress.hidden = true; if (floatBox) floatBox.hidden = true; toast('网络错误，重试一次'); };
      xhr.send(fd);
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
      // 格式按钮的动态数据
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
    var btns = root.querySelectorAll('.tab-btn');
    var panes = root.querySelectorAll('.tab-pane');
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
    show((location.hash || '').replace('#', '') || (btns[0] ? btns[0].getAttribute('data-tab') : ''), false);
  }

  function bootConsole() {
    initTabs('#consoleTabs');
    var createBtn = document.getElementById('tokenCreateBtn');
    if (createBtn) {
      createBtn.addEventListener('click', function () {
        var name = document.getElementById('tokenName').value;
        fetch('/console/token/create', {
          method: 'POST',
          headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
          body: 'name=' + encodeURIComponent(name)
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
        form.appendChild(inp); document.body.appendChild(form); form.submit();
      });
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
