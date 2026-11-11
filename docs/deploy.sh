#!/usr/bin/env bash
# imgbed 一键部署脚本（Ubuntu 沙箱 / 云桌面 / 新服务器通用，自适应 PHP 版本）
# 用法：curl -fsSL https://raw.githubusercontent.com/cd1005-art/imgbed/main/docs/deploy.sh | bash
set -e

# ---------- 权限处理 ----------
if [ "$(id -u)" -eq 0 ]; then
  SUDO=""
elif sudo -v 2>/dev/null; then
  SUDO="sudo"
  echo "==> 使用 sudo 执行系统命令"
else
  echo "!! 需要 root 或 sudo（免密）权限，请以 root 重跑"
  exit 1
fi

# ---------- 目录选择：优先用户可写的 /workspace，其次 /opt（全程 sudo） ----------
if [ -d /workspace ] && [ -w /workspace ]; then
  APP_DIR="/workspace/imgbed"
  APP_SUDO=""
elif [ -d /opt ] && [ -w /opt ]; then
  APP_DIR="/opt/imgbed"
  APP_SUDO=""
else
  APP_DIR="/opt/imgbed"
  APP_SUDO="$SUDO"
  $SUDO mkdir -p /opt
fi
echo "==> 代码目录：$APP_DIR"

# 容器环境：policy-rc.d 会拦所有 service 启动，直接移除
$SUDO rm -f /usr/sbin/policy-rc.d 2>/dev/null || true
# composer 以 root 运行时需要此变量
export COMPOSER_ALLOW_SUPERUSER=1

PORT=3000
DB_NAME="imgbed"
DB_USER="imgbed"
DB_PASS=$(head -c 16 /dev/urandom | md5sum | cut -c1-16)
ADMIN_PASS="admin123"

echo "==> [1/7] 安装系统依赖（自适应系统自带 PHP 版本）"
export DEBIAN_FRONTEND=noninteractive

# 清理坏 PPA（ondrej/php 在 Ubuntu 26.04 无 Release，会导致 update 失败）
$SUDO rm -f /etc/apt/sources.list.d/ondrej*.list* 2>/dev/null || true

# update 失败（个别源抖动）不中断，用已缓存索引继续
$SUDO apt-get update -qq || true
$SUDO apt-get install -y -qq nginx mariadb-server unzip curl git php-fpm php-mysql php-mbstring php-fileinfo php-gd php-curl php-xml php-zip || \
  $SUDO apt-get install -y -qq nginx mariadb-server unzip curl git php8.4-fpm php8.4-mysql php8.4-mbstring php8.4-fileinfo php8.4-gd php8.4-curl php8.4-xml php8.4-zip || \
  $SUDO apt-get install -y -qq nginx mariadb-server unzip curl git php8.3-fpm php8.3-mysql php8.3-mbstring php8.3-fileinfo php8.3-gd php8.3-curl php8.3-xml php8.3-zip || \
  $SUDO apt-get install -y -qq nginx mariadb-server unzip curl git php8.2-fpm php8.2-mysql php8.2-mbstring php8.2-fileinfo php8.2-gd php8.2-curl php8.2-xml php8.2-zip
php -v | head -1

# 探测实际 fpm sock
PHP_SOCK=$(ls /run/php/php*-fpm.sock 2>/dev/null | head -1)
if [ -z "$PHP_SOCK" ]; then
  $SUDO service php*-fpm start 2>/dev/null || $SUDO service php-fpm start || true
  sleep 2
  PHP_SOCK=$(ls /run/php/php*-fpm.sock 2>/dev/null | head -1)
fi
if [ -z "$PHP_SOCK" ]; then
  echo "!! 未找到 php-fpm.sock，改用 TCP 9000"
  PHP_SOCK="127.0.0.1:9000"
fi
echo "==> PHP-FPM: $PHP_SOCK"

echo "==> [2/7] 拉取代码"
if [ -d "$APP_DIR/.git" ]; then
  git -C "$APP_DIR" pull --ff-only
else
  $APP_SUDO git clone --depth 1 https://github.com/cd1005-art/imgbed.git "$APP_DIR"
fi

echo "==> [3/7] Composer 依赖"
cd "$APP_DIR"
[ -w "$APP_DIR" ] || $SUDO chown -R "$(whoami)": "$APP_DIR"
# vendor 随仓库分发（PHP 8.2 环境验证过的完整依赖），存在则跳过 composer
if [ -d "$APP_DIR/vendor" ] && [ -f "$APP_DIR/vendor/autoload.php" ]; then
  echo "==> vendor 已随仓库分发，跳过 composer install"
else
  command -v composer >/dev/null || { curl -sS https://getcomposer.org/installer | php -- --install-dir=/usr/local/bin --filename=composer --quiet; }
  composer install --no-dev --no-interaction --no-plugins 2>&1 | tail -25
  [ -f "$APP_DIR/vendor/autoload.php" ] || { echo "!! composer install 失败（上方为完整报错）"; exit 1; }
fi

echo "==> [4/7] 数据库"
$SUDO service mariadb start 2>/dev/null || $SUDO service mysql start 2>/dev/null || ($SUDO mysqld_safe &) ; sleep 4
$SUDO mysql -e "CREATE DATABASE IF NOT EXISTS $DB_NAME DEFAULT CHARACTER SET utf8mb4;"
$SUDO mysql -e "CREATE USER IF NOT EXISTS '$DB_USER'@'localhost' IDENTIFIED BY '$DB_PASS';"
$SUDO mysql -e "GRANT ALL ON $DB_NAME.* TO '$DB_USER'@'localhost'; FLUSH PRIVILEGES;"
$SUDO mysql "$DB_NAME" < "$APP_DIR/install.sql"

echo "==> [5/7] 生成 .env"
cat > "$APP_DIR/.env" <<EOF
APP_DEBUG = false
DEFAULT_APP = index

[DB]
TYPE = mysql
HOST = 127.0.0.1
NAME = $DB_NAME
USER = $DB_USER
PASS = $DB_PASS
PORT = 3306
CHARSET = utf8mb4
PREFIX = img_

[LANG]
DEFAULT_LANG = zh-cn

[CACHE]
DRIVER = file

[SESSION]
DRIVER = file
EOF
chmod 600 "$APP_DIR/.env"

echo "==> [6/7] Nginx 站点（端口 $PORT，PHP: $PHP_SOCK）"
# PHP-FPM 启动（多层兜底，最终保证 9000 有进程）
PHP_SOCK=""
$SUDO service php8.2-fpm start 2>/dev/null || $SUDO service php8.3-fpm start 2>/dev/null || \
$SUDO service php8.4-fpm start 2>/dev/null || $SUDO service php8.5-fpm start 2>/dev/null || \
$SUDO service php-fpm start 2>/dev/null || true
sleep 1
PHP_SOCK=$(find /run/php -name "*fpm*.sock" 2>/dev/null | head -1)

if [ -z "$PHP_SOCK" ]; then
  $SUDO php-fpm8.2 --daemonize 2>/dev/null || $SUDO php-fpm8.3 --daemonize 2>/dev/null || \
  $SUDO php-fpm8.4 --daemonize 2>/dev/null || $SUDO php-fpm8.5 --daemonize 2>/dev/null || true
  sleep 1
  PHP_SOCK=$(find /run/php -name "*fpm*.sock" 2>/dev/null | head -1)
fi

# 终极兜底：用系统 fpm 二进制 + 最小 TCP 配置强制起 9000（保证 nginx 有后端）
if [ -z "$PHP_SOCK" ] && ! (ss -tln 2>/dev/null | grep -q ':9000'); then
  FPM_BIN=$(ls /usr/sbin/php-fpm* 2>/dev/null | head -1)
  if [ -n "$FPM_BIN" ]; then
    echo "==> FPM 兜底：以 TCP 9000 强制拉起 $FPM_BIN"
    $SUDO tee /tmp/fpm-tcp.conf > /dev/null <<FPMEOF
[global]
pid = /tmp/fpm-tcp.pid
error_log = /tmp/fpm-tcp.log
[www]
listen = 127.0.0.1:9000
pm = dynamic
pm.max_children = 10
pm.start_servers = 2
pm.min_spare_servers = 2
pm.max_spare_servers = 4
FPMEOF
    $SUDO "$FPM_BIN" -y /tmp/fpm-tcp.conf --daemonize 2>/dev/null || $SUDO "$FPM_BIN" --fpm-config /tmp/fpm-tcp.conf --daemonize
    sleep 1
  fi
fi

PHP_SOCK=$(find /run/php -name "*fpm*.sock" 2>/dev/null | head -1)
[ -z "$PHP_SOCK" ] && PHP_SOCK="127.0.0.1:9000"
echo "==> PHP-FPM: $PHP_SOCK"

$SUDO tee /etc/nginx/sites-available/imgbed > /dev/null <<EOF
server {
    listen $PORT;
    server_name _;
    root $APP_DIR/public;
    index index.php;
    client_max_body_size 64m;

    location / { try_files \$uri \$uri/ /index.php\$is_args\$args; }

    location ~ \.php\$ {
        fastcgi_pass $PHP_SOCK;
        fastcgi_param SCRIPT_FILENAME \$realpath_root\$fastcgi_script_name;
        include fastcgi_params;
    }

    location /storage/ { expires 30d; add_header Cache-Control "public, immutable"; }

    location ~ /\. { deny all; }
}
EOF
$SUDO ln -sf /etc/nginx/sites-available/imgbed /etc/nginx/sites-enabled/imgbed
$SUDO rm -f /etc/nginx/sites-enabled/default
$SUDO mkdir -p "$APP_DIR/public/storage/images" "$APP_DIR/runtime"
$SUDO chown -R www-data:www-data "$APP_DIR/runtime" "$APP_DIR/public/storage" 2>/dev/null || true
if ! $SUDO nginx -t 2>&1 | tail -5; then echo "!! nginx 配置校验失败，见上方错误"; fi
($SUDO service nginx reload 2>/dev/null || $SUDO service nginx restart || ($SUDO nginx || true))


echo "==> [7/7] 验证"
sleep 1
HTTP_CODE=$(curl -s -o /dev/null -w "%{http_code}" "http://127.0.0.1:$PORT/" || echo 000)

cat <<EOF

============================================================
 部署完成（HTTP $HTTP_CODE）

 站点入口      : http://127.0.0.1:$PORT/
 公网访问      : 沙箱网关 URL（https://<SPACE_ID>--$PORT.<REGION>.<HOST>）
 后台          : /admin   账号 admin / $ADMIN_PASS （登录后立即改密！）
 支付回调地址  : <公网URL>/pay/notify
 数据库        : $DB_NAME / $DB_USER / $DB_PASS

 下一步：
 1. 后台「支付管理」填逍遥PAY 网关与通道
 2. notify_url 用公网 URL（沙箱 Basic 认证会拦截无凭据回调，
    正式回调联调建议内网穿透到有公网入口的机器）
============================================================
EOF
