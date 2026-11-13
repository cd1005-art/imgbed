<?php
/**
 * 逍遥图床 Web 安装向导（单文件，不依赖框架）
 * 用法：上传本文件到站点根目录（与 install.sql 同级），浏览器访问 /install.php
 * 安装完成后请删除本文件（锁定后本页会拒绝再次访问）
 */
declare(strict_types=1);

$ROOT      = dirname(__DIR__);   // 站点根（.env / install.sql 所在）
// install.lock 双路径检测（部分环境 open_basedir 只允许 public 目录）
$LOCK_CANDIDATES = [$ROOT . '/install.lock', __DIR__ . '/install.lock'];
$ENV_FILE  = $ROOT . '/.env';
$SQL_FILE  = $ROOT . '/install.sql';

function h(string $s): string { return htmlspecialchars($s, ENT_QUOTES, 'UTF-8'); }

$installed = false;
foreach ($LOCK_CANDIDATES as $lk) {
    if (@file_exists($lk)) { $installed = true; break; }
}
$errors    = [];
$done      = false;

// ---------- 环境检查（硬性项不通过则阻止；软项仅提示） ----------
$checks = [
    'PHP >= 8.1'          => version_compare(PHP_VERSION, '8.1.0', '>='),
    '扩展 pdo_mysql'       => extension_loaded('pdo_mysql'),
    '扩展 gd'              => extension_loaded('gd'),
    '扩展 mbstring'        => extension_loaded('mbstring'),
    '扩展 curl'            => extension_loaded('curl'),
    '目录可写 runtime/'     => is_writable($ROOT) || (is_dir($ROOT . '/runtime') && is_writable($ROOT . '/runtime')),
    'install.sql 存在'     => file_exists($SQL_FILE),
];
$softChecks = [
    '扩展 fileinfo（建议，PHP 可回退检测）' => extension_loaded('fileinfo'),
    '扩展 zip（备份/导入用）'               => class_exists('ZipArchive'),
];
$envOk = !in_array(false, $checks, true);

// ---------- 安装执行 ----------
if (!$installed && ($_SERVER['REQUEST_METHOD'] === 'POST')) {
    $dbHost  = trim((string)($_POST['db_host'] ?? '127.0.0.1'));
    $dbPort  = (int)($_POST['db_port'] ?? 3306);
    $dbName  = trim((string)($_POST['db_name'] ?? 'imgbed'));
    $dbUser  = trim((string)($_POST['db_user'] ?? ''));
    $dbPass  = (string)($_POST['db_pass'] ?? '');
    $admUser = trim((string)($_POST['admin_user'] ?? 'admin'));
    $admPass = (string)($_POST['admin_pass'] ?? '');
    $admMail = trim((string)($_POST['admin_email'] ?? ''));
    $site    = trim((string)($_POST['site_name'] ?? '逍遥图床'));
    $prefix  = trim((string)($_POST['db_prefix'] ?? 'img_'));
    if (!preg_match('/^[A-Za-z0-9_]*$/', $prefix))       { $errors[] = '表前缀只能包含字母数字下划线'; }

    if (!$envOk)                                          { $errors[] = '环境检查未通过，请先解决上方红叉项'; }
    if ($dbUser === '')                                   { $errors[] = '数据库用户名必填'; }
    if (!preg_match('/^[A-Za-z0-9_]+$/', $dbName))         { $errors[] = '数据库名只能包含字母数字下划线'; }
    if (strlen($admPass) < 8)                             { $errors[] = '管理员密码至少 8 位'; }

    if (!$errors) {
        try {
            // 1) 建库
            $dsn = "mysql:host={$dbHost};port={$dbPort};charset=utf8mb4";
            $pdo = new PDO($dsn, $dbUser, $dbPass, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_TIMEOUT => 8,
            ]);
            // 建库：无建库权限时（虚拟主机常见）尝试直接使用已存在的库
            try {
                $pdo->exec("CREATE DATABASE IF NOT EXISTS `{$dbName}` DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci");
            } catch (Throwable $e0) {
                try {
                    $pdo->exec("USE `{$dbName}`");
                } catch (Throwable $e1) {
                    throw new RuntimeException('数据库不存在且当前账号无建库权限，请先在面板中创建数据库 ' . $dbName);
                }
            }
            $pdo->exec("USE `{$dbName}`");

            // 2) 导入 install.sql（跳过 CREATE DATABASE / USE 语句）
            $sql = (string)file_get_contents($SQL_FILE);
            $sql = preg_replace('/^\s*--.*$/m', '', $sql);       // 去注释行
            if ($prefix !== 'img_') {
                $sql = str_replace('img_', $prefix, $sql);        // install.sql 中 img_ 仅用于表名
            }
            $stmts = array_filter(array_map('trim', explode(';', $sql)));
            $count = 0;
            foreach ($stmts as $stmt) {
                if ($stmt === '') continue;
                if (preg_match('/^(CREATE\s+DATABASE|USE)\b/i', $stmt)) continue;
                $pdo->exec($stmt);
                $count++;
            }

            // 3) 管理员账号
            $hash = password_hash($admPass, PASSWORD_BCRYPT);
            $up = $pdo->prepare("UPDATE `{$prefix}admins` SET `username` = ?, `password` = ? WHERE `id` = 1");
            $up->execute([$admUser, $hash]);
            if ($up->rowCount() === 0) {
                $ins = $pdo->prepare("INSERT INTO `{$prefix}admins` (`username`,`password`,`status`,`create_time`,`update_time`) VALUES (?,?,1,UNIX_TIMESTAMP(),UNIX_TIMESTAMP())");
                $ins->execute([$admUser, $hash]);
            }

            // 4) 站点名
            $pdo->prepare("UPDATE `{$prefix}settings` SET `svalue` = ? WHERE `skey` = 'site_name'")->execute([$site]);

            // 5) 写 .env
            $env = "APP_DEBUG = false\n\n[APP]\nDEFAULT_TIMEZONE = Asia/Shanghai\n\n[DATABASE]\nTYPE = mysql\nHOSTNAME = {$dbHost}\nDATABASE = {$dbName}\nUSERNAME = {$dbUser}\nPASSWORD = {$dbPass}\nHOSTPORT = {$dbPort}\nCHARSET = utf8mb4\nPREFIX = {$prefix}\n\n[LANG]\ndefault_lang = zh-cn\n";
            if (file_put_contents($ENV_FILE, $env) === false) {
                throw new RuntimeException('.env 写入失败，请检查站点根目录写权限');
            }

            // 6) 锁定（根 + public 双写，兼容 open_basedir 限制）
            foreach ($LOCK_CANDIDATES as $lk) {
                @file_put_contents($lk, date('Y-m-d H:i:s') . " installed\n");
            }
            // 尝试隐藏自身（失败不影响）
            @rename(__FILE__, __FILE__ . '.installed');

            $done = true;
        } catch (Throwable $e) {
            $errors[] = '安装失败：' . $e->getMessage();
        }
    }
}
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>安装向导 · 逍遥图床</title>
<style>
:root{--bg:#0d1117;--panel:#161b22;--line:#30363d;--text:#e6edf3;--sub:#9198a1;--accent:#2ea043;--red:#f85149}
*{box-sizing:border-box}
body{margin:0;background:var(--bg);color:var(--text);font:14px/1.7 -apple-system,"Segoe UI",Microsoft YaHei,sans-serif}
.wrap{max-width:640px;margin:48px auto;padding:0 20px 60px}
h1{font-size:22px;margin:0 0 6px}
.sub{color:var(--sub);margin:0 0 24px}
.card{background:var(--panel);border:1px solid var(--line);border-radius:10px;padding:20px;margin-bottom:16px}
.card h2{font-size:15px;margin:0 0 14px;color:var(--accent)}
table{width:100%;border-collapse:collapse}
td{padding:5px 0;font-size:13.5px}
td:last-child{text-align:right}
.ok{color:var(--accent)} .bad{color:var(--red)}
label{display:block;margin:10px 0 4px;font-size:13px;color:var(--sub)}
input{width:100%;height:36px;padding:0 10px;background:#0d1117;border:1px solid var(--line);border-radius:6px;color:var(--text);font-size:14px}
input:focus{outline:none;border-color:var(--accent)}
.row{display:flex;gap:12px}.row>div{flex:1}
button{width:100%;height:42px;margin-top:18px;background:var(--accent);border:none;border-radius:6px;color:#fff;font-size:15px;font-weight:600;cursor:pointer}
button:hover{filter:brightness(1.1)}
.err{background:#3d1418;border:1px solid #6e2226;color:#ffb3b8;padding:10px 14px;border-radius:8px;margin-bottom:14px;font-size:13px}
.warn{background:#3d2f14;border:1px solid #6e5522;color:#f0d9a8;padding:10px 14px;border-radius:8px;margin-bottom:14px}
.done{text-align:center;padding:40px 20px}
.done h2{color:var(--accent);font-size:20px}
</style>
</head>
<body>
<div class="wrap">
  <h1>逍遥图床 · 安装向导</h1>
  <p class="sub">开源自托管图床 · MIT License</p>

<?php if ($installed): ?>
  <div class="card done">
    <h2>✅ 系统已安装</h2>
    <p class="sub">检测到 install.lock，安装向导已锁定。</p>
    <p class="sub">如需重装：删除站点根目录的 <code>install.lock</code> 与 <code>.env</code> 后刷新本页（会清空现有数据，慎用）。</p>
    <p><a href="/admin/login" style="color:var(--accent)">前往后台登录 →</a></p>
  </div>
<?php elseif ($done): ?>
  <div class="card done">
    <h2>🎉 安装完成</h2>
    <p class="sub">数据库已初始化，管理员账号已创建，.env 已生成。</p>
    <div class="warn" style="text-align:left;margin:18px 0">安全提示：请立即删除站点根目录下的 <b>install.php</b>（若已自动重命名可忽略）。</div>
    <p><a href="/admin/login" style="color:var(--accent);font-size:16px">前往后台登录 →</a></p>
  </div>
<?php else: ?>
  <div class="card">
    <h2>环境检查</h2>
    <table>
    <?php foreach ($checks as $name => $ok): ?>
      <tr><td><?= h($name) ?></td><td class="<?= $ok ? 'ok' : 'bad' ?>"><?= $ok ? '✓ 通过' : '✗ 不通过' ?></td></tr>
    <?php endforeach; ?>
    <?php foreach ($softChecks as $name => $ok): ?>
      <tr><td><?= h($name) ?></td><td class="<?= $ok ? 'ok' : 'bad' ?>"><?= $ok ? '✓ 通过' : '⚠ 未启用（不阻断）' ?></td></tr>
    <?php endforeach; ?>
    </table>
  </div>

  <?php foreach ($errors as $e): ?>
    <div class="err"><?= h($e) ?></div>
  <?php endforeach; ?>

  <form method="post">
    <div class="card">
      <h2>数据库配置</h2>
      <div class="row">
        <div><label>主机</label><input name="db_host" value="<?= h($_POST['db_host'] ?? '127.0.0.1') ?>"></div>
        <div style="max-width:110px"><label>端口</label><input name="db_port" value="<?= h($_POST['db_port'] ?? '3306') ?>"></div>
      </div>
      <label>数据库名（不存在会自动创建）</label><input name="db_name" value="<?= h($_POST['db_name'] ?? 'imgbed') ?>">
      <div class="row">
        <div><label>用户名</label><input name="db_user" value="<?= h($_POST['db_user'] ?? '') ?>"></div>
        <div><label>密码</label><input type="password" name="db_pass" value="<?= h($_POST['db_pass'] ?? '') ?>"></div>
      </div>
      <label>表前缀（多站点共库时区分，如 img2_）</label><input name="db_prefix" value="<?= h($_POST['db_prefix'] ?? 'img_') ?>">
    </div>

    <div class="card">
      <h2>管理员与站点</h2>
      <div class="row">
        <div><label>管理员账号</label><input name="admin_user" value="<?= h($_POST['admin_user'] ?? 'admin') ?>"></div>
        <div><label>管理员密码（≥8 位）</label><input type="password" name="admin_pass" value=""></div>
      </div>
      <label>站点名称</label><input name="site_name" value="<?= h($_POST['site_name'] ?? '逍遥图床') ?>">
    </div>

    <button type="submit">开始安装</button>
  </form>
<?php endif; ?>
</div>
</body>
</html>
