# 部署指南（本地 / 云桌面 / 云服务器通用）

> 图床本体 = ThinkPHP 8.1.4 + PHP 8.2 + MySQL 5.7/8 + Nginx
> 本文按「新机器从零到可跑」写，云桌面（Ubuntu 沙箱）与轻量云服务器均适用。

## 0. 拿代码

```bash
# 方式一：git（推荐，后续更新一条 pull）
git clone https://github.com/cd1005-art/imgbed.git imgbed && cd imgbed

# 方式二：打包上传（本地 dev 环境直接同步）
#   本机: tar -czf imgbed.tgz --exclude runtime --exclude vendor -C D:/wwwroot/img think-8.1.3
#   目标机: tar -xzf imgbed.tgz && mv think-8.1.3 imgbed
```

## 1. 系统依赖（Ubuntu 沙箱 / Debian 系）

```bash
apt update && apt install -y nginx mysql-server php8.2-fpm php8.2-mysql php8.2-mbstring \
  php8.2-fileinfo php8.2-gd php8.2-curl php8.2-xml php8.2-zip unzip curl
# 沙箱若无 php8.2 源: add-apt-repository ppa:ondrej/php && apt update
```

## 2. 数据库

```bash
mysql < install.sql          # 建库 imgbed + 13 张表 + 初始数据（admin/admin123）
mysql -e "ALTER USER 'root'@'localhost' IDENTIFIED BY '你的密码';"
```

`.env` 关键项（项目根）：

```
APP_DEBUG = false            # 上线必须 false，否则响应体会混入调试 HTML
[DB]
NAME = imgbed
USER = root
PASS = 你的密码
```

## 3. Composer 依赖

```bash
composer install --no-dev    # 阿里镜像: composer config -g repos.packagist composer https://mirrors.aliyun.com/composer/
```

## 4. Nginx 站点（/etc/nginx/sites-available/imgbed）

```nginx
server {
    listen 3000;                       # 沙箱只放行特定端口（3000 最稳），云服务器用 80/443
    server_name _;
    root /workspace/imgbed/public;     # 必须指向 public/
    index index.php;

    location / { try_files $uri $uri/ /index.php$is_args$args; }

    location ~ \.php$ {
        fastcgi_pass unix:/run/php/php8.2-fpm.sock;
        fastcgi_param SCRIPT_FILENAME $realpath_root$fastcgi_script_name;
        include fastcgi_params;
    }

    # 本地存储图片直接由 nginx 服务（local 驱动直链模式的性能来源）
    location /storage/ { expires 30d; add_header Cache-Control "public, immutable"; }
}
```

```bash
ln -s /etc/nginx/sites-available/imgbed /etc/nginx/sites-enabled/
nginx -t && systemctl reload nginx
```

## 5. 验证

```bash
curl -s http://127.0.0.1:3000/ | head -3        # 返回站点 JSON = 跑通
# 外网（沙箱）: https://${SPACE_KEY}--3000.${REGION}.${HOST}
```

## 6. 支付回调联调（真实逍遥PAY）

1. 后台 `/admin/payment` 填：api_url / pid / key / 网关类型 xpay / 支付通道 type（通道 ID 直接填数字）
2. **回调地址必须是公网可达域名**——沙箱的 `notify_url` 用沙箱公网 URL；正式环境用备案域名
3. 下单跳转 → 真实支付 → 逍遥PAY 异步回调 `/pay/notify` → 返回 success → VIP 自动生效
4. 商户后台确认「通道 ID」绑定了在线收款账号（本机实测签名已通过，报「暂无收款账号在线」属商户侧配置）

## 7. NSFW 推理服务（可选，tools/nsfw-service/）

```bash
cd tools/nsfw-service && pip install -r requirements.txt && python app.py   # 0.0.0.0:8900
```

后台审核驱动选 local_nsfw，并写入设置：`nsfw_endpoint = http://127.0.0.1:8900/`

## 8. 安全清单（上线前）

- [ ] `APP_DEBUG=false`
- [ ] 修改 admin 默认密码（admin/admin123）
- [ ] MySQL 不对公网开放、密码非默认
- [ ] `public/storage/` 目录 nginx 静态服务（不落 PHP）
- [ ] 审核驱动开启（none = 任何内容直接公网外链，合规风险自负）
- [ ] HTTPS + 防盗链（Referer 白名单，二期功能，先用 nginx 实现）
