#!/usr/bin/env bash
# imgbed Docker 一键部署（绕开宿主 PHP 版本兼容问题，锁定 PHP 8.2 + MySQL 5.7）
# 用法：curl -fsSL https://raw.githubusercontent.com/cd1005-art/imgbed/main/docs/deploy-docker.sh | bash
set -e

if ! command -v docker >/dev/null; then
  echo "!! 未检测到 docker，先安装：curl -fsSL https://get.docker.com | bash && usermod -aG docker \$USER"
  exit 1
fi

if [ "$(id -u)" -eq 0 ]; then SUDO=""; elif sudo -v 2>/dev/null; then SUDO="sudo"; else SUDO=""; fi

if [ -d /workspace ] && [ -w /workspace ]; then APP_DIR="/workspace/imgbed"; else APP_DIR="/opt/imgbed"; $SUDO mkdir -p /opt; fi
echo "==> 代码目录：$APP_DIR"

PORT=3000
DB_NAME="imgbed"
DB_USER="imgbed"
DB_PASS=$(head -c 16 /dev/urandom | md5sum | cut -c1-16)
MYSQL_ROOT_PASS=$(head -c 16 /dev/urandom | md5sum | cut -c1-16)
ADMIN_PASS="admin123"

echo "==> [1/5] 拉取代码"
if [ -d "$APP_DIR/.git" ]; then
  git -C "$APP_DIR" pull --ff-only
else
  $SUDO git clone --depth 1 https://github.com/cd1005-art/imgbed.git "$APP_DIR" 2>/dev/null || git clone --depth 1 https://github.com/cd1005-art/imgbed.git "$APP_DIR"
fi
cd "$APP_DIR"
$SUDO chmod -R a+w "$APP_DIR" 2>/dev/null || true

echo "==> [2/5] 生成 docker-compose 与配置"
mkdir -p docker
cat > docker/nginx.conf <<'EOF'
server {
    listen 80;
    server_name _;
    root /app/public;
    index index.php;
    client_max_body_size 64m;

    location / { try_files $uri $uri/ /index.php$is_args$args; }

    location ~ \.php$ {
        fastcgi_pass php:9000;
        fastcgi_param SCRIPT_FILENAME /app/public$fastcgi_script_name;
        include fastcgi_params;
    }

    location /storage/ { expires 30d; add_header Cache-Control "public, immutable"; }

    location ~ /\. { deny all; }
}
EOF

cat > docker-compose.yml <<EOF
services:
  mysql:
    image: mysql:5.7
    restart: unless-stopped
    environment:
      MYSQL_ROOT_PASSWORD: $MYSQL_ROOT_PASS
      MYSQL_DATABASE: $DB_NAME
      MYSQL_USER: $DB_USER
      MYSQL_PASSWORD: $DB_PASS
    volumes:
      - dbdata:/var/lib/mysql
    command: --character-set-server=utf8mb4 --collation-server=utf8mb4_general_ci

  php:
    image: php:8.2-fpm
    restart: unless-stopped
    volumes:
      - ..:/app          # 挂 imgbed 上级目录，nginx 同样挂 /app
      - ../runtime:/app/runtime
    environment:
      DB_HOST: mysql
      DB_NAME: $DB_NAME
      DB_USER: $DB_USER
      DB_PASS: $DB_PASS

  nginx:
    image: nginx:alpine
    restart: unless-stopped
    ports:
      - "$PORT:80"
    volumes:
      - ..:/app:ro
      - ./nginx.conf:/etc/nginx/conf.d/default.conf:ro
    depends_on:
      - php

volumes:
  dbdata:
EOF

echo "==> [3/5] 生成 .env（PHP 容器内 DB_HOST=mysql）"
cat > .env <<EOF
APP_DEBUG = false
DEFAULT_APP = index

[DB]
TYPE = mysql
HOST = mysql
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
chmod 600 .env

echo "==> [4/5] 启动容器 + Composer 安装 + 导库"
$SUDO docker compose up -d mysql nginx php 2>/dev/null || $SUDO docker-compose up -d mysql nginx php
sleep 8

echo "    - Composer 安装依赖（容器内 PHP 8.3，产物落到代码卷）"
$SUDO docker run --rm -v "$(cd .. && pwd)":/app -w /app/think-8.1.3 composer:2 composer install --no-dev --no-interaction --quiet 2>/dev/null \
  || $SUDO docker run --rm -v "$(dirname "$APP_DIR")":/app -w /app/think-8.1.3 composer:2 composer install --no-dev --no-interaction --quiet

echo "    - 导入 install.sql"
$SUDO docker compose exec -T mysql mysql -uroot -p"$MYSQL_ROOT_PASS" "$DB_NAME" < install.sql

echo "    - 目录权限（fpm 容器 www-data=33）"
$SUDO chmod -R 777 runtime public/storage 2>/dev/null || true

echo "==> [5/5] 验证"
sleep 2
HTTP_CODE=$(curl -s -o /dev/null -w "%{http_code}" "http://127.0.0.1:$PORT/" || echo 000)

cat <<EOF

============================================================
 部署完成（HTTP $HTTP_CODE）

 站点入口      : http://127.0.0.1:$PORT/
 公网访问      : 沙箱网关 https://<SPACE_ID>--$PORT.<REGION>.<HOST>（带 Basic 凭据）
 后台          : /admin   账号 admin / $ADMIN_PASS
 支付回调      : <公网URL>/pay/notify（沙箱 Basic 墙会拦截无凭据回调）
 数据库        : mysql 容器 / 库 $DB_NAME / 用户 $DB_USER / 密码 $DB_PASS
                root 密码：$MYSQL_ROOT_PASS

 常用运维：
   docker compose ps | logs -f php | down | up -d
============================================================
EOF
