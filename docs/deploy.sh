#!/usr/bin/env bash
# imgbed 一键部署脚本（Ubuntu 沙箱 / 云桌面 / 新服务器通用）
# 用法：curl -fsSL https://raw.githubusercontent.com/cd1005-art/imgbed/main/docs/deploy.sh | bash
set -e

# ---------- 权限处理：非 root 自动走 sudo ----------
if [ "$(id -u)" -eq 0 ]; then
  SUDO=""
elif sudo -v 2>/dev/null; then
  SUDO="sudo"
  echo "==> 使用 sudo 执行系统命令"
else
  echo "!! 需要 root 或 sudo 权限（且 sudo 免密），请以 root 重跑：curl -fsSL ... | sudo bash"
  exit 1
fi

# ---------- 目录选择：优先 /workspace（沙箱），否则 /opt ----------
if [ -d /workspace ] && [ -w /workspace ]; then
  APP_DIR="/workspace/imgbed"
else
  APP_DIR="/opt/imgbed"
  $SUDO mkdir -p /opt
fi

PORT=3000
DB_NAME="imgbed"
DB_USER="imgbed"
DB_PASS=$(head -c 16 /dev/urandom | md5sum | cut -c1-16)
ADMIN_PASS="admin123"

echo "==> [1/7] 安装系统依赖"
export DEBIAN_FRONTEND=noninteractive
$SUDO apt-get update -qq
$SUDO apt-get install -y -qq nginx mariadb-server unzip curl \
  php8.2-fpm php8.2-mysql php8.2-mbstring php8.2-fileinfo php8.2-gd php8.2-curl php8.2-xml php8.2-zip \
  2>/dev/null || {
  $SUDO add-apt-repository -y ppa:ondrej/php && $SUDO apt-get update -qq && \
  $SUDO apt-get install -y -qq nginx mariadb-server unzip curl \
    php8.2-fpm php8.2-mysql php8.2-mbstring php8.2-fileinfo php8.2-gd php8.2-curl php8.2-xml php8.2-zip;
}

echo "==> [2/7] 拉取代码"
if [ -d "$APP_DIR/.git" ]; then
  git -C "$APP_DIR" pull --ff-only
else
  git clone --depth 1 https://github.com/cd1005-art/imgbed.git "$APP_DIR"
fi

echo "==> [3/7] Composer 依赖"
if ! command -v composer >/dev/null; then
  curl -sS https://getcomposer.org/installer | php -- --install-dir=/tmp --filename=composer --quiet
  $SUDO mv /tmp/composer /usr/local/bin/composer
fi
cd "$APP_DIR"
composer install --no-dev --no-interaction --quiet 2>/dev/null || \
  (composer config -g repos.packagist composer https://mirrors.aliyun.com/composer/ && composer install --no-dev --no-interaction --quiet)

echo "==> [4/7] 数据库"
$SUDO service mariadb start 2>/dev/null || $SUDO service mysql start 2>/dev/null || $SUDO mysqld_safe & sleep 4
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

echo "==> [6/7] Nginx 站点（端口 $PORT）"
$SUDO tee /etc/nginx/sites-available/imgbed > /dev/null <<EOF
server {
    listen $PORT;
    server_name _;
    root $APP_DIR/public;
    index index.php;
    client_max_body_size 64m;

    location / { try_files \$uri \$uri/ /index.php\$is_args\$args; }

    location ~ \.php\$ {
        fastcgi_pass unix:/run/php/php8.2-fpm.sock;
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
$SUDO chown -R www-data:www-data "$APP_DIR/runtime" "$APP_DIR/public/storage"
$SUDO nginx -t && $SUDO service nginx reload 2>/dev/null || $SUDO service nginx restart
$SUDO service php8.2-fpm start 2>/dev/null || $SUDO service php8.2-fpm restart

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
