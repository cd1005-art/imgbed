# 部署指南

## 安装方式（三选一）

| 方式 | 适合 | 时间 |
|---|---|---|
| **A. 发行包 + Web 向导**（推荐）| 宝塔 / 虚拟主机用户 | 3 分钟 |
| **B. Docker Compose** | 云服务器 / 运维 | 5 分钟 |
| **C. 源码 + Composer** | 开发者 / 二次开发 | 10 分钟 |

---

### A. 发行包 + Web 向导（最简单）

1. 到 [GitHub Releases](https://github.com/cd1005-art/imgbed/releases) 下载最新 `imgbed-v*.zip`（**已含 vendor，无需 Composer**）
2. 上传到站点根目录并解压（宝塔：文件 → 上传 → 解压）
3. 浏览器打开 `https://你的域名/install.php`
4. 按向导填写：数据库信息（面板中先建好库）→ 管理员账号密码 → 站点名
5. 安装完成后**删除 install.php**（向导会自动尝试重命名）

> 伪静态必须配置（见文末 Nginx 配置），否则除首页外全部 404。

### B. Docker Compose

```bash
git clone https://github.com/cd1005-art/imgbed.git
cd imgbed
# 修改 docker-compose.yml 里的数据库密码（两处）
docker compose up -d --build
# 首次启动会自动建表（install.sql 挂载到 mysql init 目录）
```

- 访问 `http://服务器IP:8080`
- **安装向导仍要跑一次**（写 .env 与管理员密码）：`http://IP:8080/install.php`
  - 数据库主机填 `db`，库名 `imgbed`，用户 `imgbed`，密码为你改的那个
- 数据持久化：`db-data` 卷（MySQL）、`runtime/`、`public/storage/images/`（挂载于宿主机）

### C. 源码 + Composer

```bash
git clone https://github.com/cd1005-art/imgbed.git
cd imgbed
composer install --no-dev
# 方式一：Web 向导（推荐）
#   配好伪静态后浏览器打开 /install.php
# 方式二：手动
mysql < install.sql
cp .env.example .env   # 改数据库连接
```

---

## Nginx 配置（必须）

（以下为原有内容）


> 图床本体 = ThinkPHP 8.1.4 + PHP 8.2 + MySQL 5.7/8 + Nginx
> 本文按「新机器从零到可跑」写，云桌面（Ubuntu 沙箱）与轻量云服务器均适用。

## 0. 两种安装方式

- **一键脚本**（Debian/Ubuntu 沙箱与服务器）：`curl -fsSL https://raw.githubusercontent.com/cd1005-art/imgbed/main/docs/deploy.sh | bash`
- **手动安装包**（宝塔/已有环境，推荐）：上传 `imgbed-full.tgz`（含 vendor，无需 composer），按下文「手动安装（宝塔）」操作

## 0.1 拿代码

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

## 2. 手动安装（宝塔 / 已有环境，用 imgbed-full.tgz）

1. 宝塔建站点（PHP 8.2），站点根目录 = `站点目录/imgbed/public`（**必须指向 public**）
2. 上传 `imgbed-full.tgz` 到站点目录同级，解压得 `imgbed/`
3. 宝塔建数据库 `imgbed`（utf8mb4），导入 `install.sql`
4. 复制 `.env.example` 为 `.env`，填数据库连接（`PASS` 等四项）
5. 权限：`runtime/` 与 `public/storage/` 给 www-data 可写（宝塔默认即可）
6. 伪静态规则（Nginx）：
   ```nginx
   location / { try_files $uri $uri/ /index.php$is_args$args; }
   location /storage/ { expires 30d; add_header Cache-Control "public, immutable"; }
   ```
7. 访问站点首页出 JSON = 装好；后台 `/admin`（admin/admin123，立即改密）

## 3. 数据库（脚本方式）

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

## 4. Composer 依赖

```bash
composer install --no-dev    # 阿里镜像: composer config -g repos.packagist composer https://mirrors.aliyun.com/composer/
```

## 5. Nginx 站点（/etc/nginx/sites-available/imgbed）

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

## 6. 验证

```bash
curl -s http://127.0.0.1:3000/ | head -3        # 返回站点 JSON = 跑通
# 外网（沙箱）: https://${SPACE_KEY}--3000.${REGION}.${HOST}
```

## 7. 支付回调联调（真实逍遥PAY）

1. 后台 `/admin/payment` 填：api_url / pid / key / 网关类型 xpay / 支付通道 type（通道 ID 直接填数字）
2. **回调地址必须是公网可达域名**——沙箱的 `notify_url` 用沙箱公网 URL；正式环境用备案域名
3. 下单跳转 → 真实支付 → 逍遥PAY 异步回调 `/pay/notify` → 返回 success → VIP 自动生效
4. 商户后台确认「通道 ID」绑定了在线收款账号（本机实测签名已通过，报「暂无收款账号在线」属商户侧配置）

## 8. NSFW 推理服务（可选，tools/nsfw-service/）

```bash
cd tools/nsfw-service && pip install -r requirements.txt && python app.py   # 0.0.0.0:8900
```

后台审核驱动选 local_nsfw，并写入设置：`nsfw_endpoint = http://127.0.0.1:8900/`

## 9. 安全清单（上线前）

- [ ] `APP_DEBUG=false`
- [ ] 修改 admin 默认密码（admin/admin123）
- [ ] MySQL 不对公网开放、密码非默认
- [ ] `public/storage/` 目录 nginx 静态服务（不落 PHP）
- [ ] 审核驱动开启（none = 任何内容直接公网外链，合规风险自负）
- [ ] HTTPS + 防盗链（Referer 白名单，二期功能，先用 nginx 实现）

## 计划任务（通知 / 造图机器人）

宝塔「计划任务 → Shell 脚本」添加（路径按实际站点调整）：

| 频率 | 命令 | 用途 |
|---|---|---|
| 每 5 分钟 | `/www/server/php/83/bin/php /www/wwwroot/你的站点/think gen:run` | 造图机器人执行到期任务 |
| 每小时 | `/www/server/php/83/bin/php /www/wwwroot/你的站点/think monitor:storage` | 存储告警（磁盘/配额/网盘阈值）|
| 每天 09:00 | `/www/server/php/83/bin/php /www/wwwroot/你的站点/think monitor:vip` | VIP 到期提醒 |

> 未配置计划任务不影响主站功能，仅以上自动化不触发。

## 水印中文字体

「图片处理 → 水印文字」需要中文字体，程序会按顺序查找：

1. `storage/fonts/*.ttf|ttc|otf`（**推荐**，open_basedir 安全区）
2. 系统字体（Linux 走 `fc-match`，Windows 走 `C:/Windows/Fonts/msyh.ttc`）

**Linux 部署建议**（宝塔默认 open_basedir 不允许访问 `/usr/share/fonts`）：

```bash
mkdir -p storage/fonts
cp /usr/share/fonts/google-noto-cjk/NotoSansCJK-Regular.ttc storage/fonts/
```

找不到字体时水印会降级为位图字体（中文显示为乱码），此时建议关闭水印。

## 外链本地副本（默认开启，零运维）

`/i/` 外链由 nginx 直接输出 `public/i/` 下的文件。程序在上传完成后自动把文件复制一份到该目录。

**为什么需要**：外链若走 PHP 代理读网盘，大图会超时；nginx 静态输出则几乎零开销（实测 0.4s / 14MB 图）。

**配置**：站点设置 → 图片处理

| 配置 | 默认 | 说明 |
|---|---|---|
| 外链本地副本 | 开启 | 关闭后外链依赖 PHP 代理（大图可能超时）|
| 副本体积上限 | 2 MB | 超过上限的图不写副本，避免本地空间被大图吃掉 |

**空间估算**（转 WebP 后）：1920×1080 约 200KB、壁纸约 0.5~1MB、14MB 原图转 WebP 约 0.6~1.5MB。500 张约 300~700MB。

**清理**：副本可随时删除（原图在存储实例里），`rm -rf public/i/*` 后重新访问会自动按需…（注意：删除后历史图外链会 404，除非重新上传）。

## 进阶：网盘挂载零拷贝（可选，需评估性能）

用 rclone 把 WebDAV 挂到本地，再让 `public/i` 指向挂载点，理论上可以省掉副本：

```bash
dnf install -y fuse3
curl -sL https://downloads.rclone.org/rclone-current-linux-amd64.zip | busybox unzip -d /tmp -
cp /tmp/rclone-*/rclone /usr/local/bin/ && chmod +x /usr/local/bin/rclone

rclone config create alist webdav url=http://127.0.0.1:5244/dav/YOUR_STORAGE vendor=other user=admin pass=$(rclone obscure 'your-password')

mkdir -p /mnt/alist
rclone mount alist: /mnt/alist --allow-other --vfs-cache-mode full --buffer-size 32M --daemon

cd public && mv i i.bak && ln -s /mnt/alist i
```

程序检测到 `public/i` 是符号链接时**自动跳过副本写入**（零拷贝）。

**⚠ 实测警告**：如果 WebDAV 后端本身慢（如 Alist 再套一层云盘），首次读取一个文件可能要 30 秒以上，nginx 会挂住连接。**仅在网盘读写延迟 < 100ms 时使用**。否则请使用默认的副本模式。

## 首次登录必做

安装完成后后台地址 `/admin`，**默认管理员账号**：

| 项 | 值 |
|---|---|
| 账号 | `admin` |
| 密码 | `admin123`（若安装向导中已填写自定义密码，则为你填的那个）|

> **⚠️ 请第一件事就是改密码**：侧边栏底部 →「修改密码」→ 输入当前密码和新密码（≥8 位）。
>
> `admin123` 是公开的默认值，任何没改密码的站点都相当于对外开放后台。
>
> 如果你是通过「导入 install.sql」而不是运行 `install.php` 安装的，账号密码一定是 `admin / admin123`。

另外建议：
- 把 `/admin` 路径改掉或加 Nginx Basic Auth（可选）
- 限制 `/admin/login` 的访问频率（防爆破）

## 运行环境要求（自建部署必读）

| 项目 | 最低要求 | 推荐 | 说明 |
|---|---|---|---|
| PHP | 8.0 | 8.3 | 需要扩展：`gd`、`curl`、`mbstring`、`pdo_mysql`、`fileinfo`、`zip`、`openssl` |
| **memory_limit** | **512M** | **1024M** | **处理 4000 万像素以上的大图时必须**。程序会在图片处理前自动 `ini_set('memory_limit','1024M')`；若主机通过 `disable_functions` 禁用了 `ini_set`，则退回 php.ini 的值，此时请手动把 `memory_limit` 设为 1024M |
| post_max_size | ≥ 策略组单图上限 | 32M | 小于单图上限会导致上传直接被 PHP 丢弃 |
| upload_max_filesize | ≥ 策略组单图上限 | 32M | 同上 |
| max_execution_time | 120 | 300 | 大图压缩/水印耗时较长 |
| GD 扩展 | 支持 jpeg/png/webp/gif | 含 `imagick` 更佳 | 缺少 webp 支持时无法输出 WebP |

### 为什么必须 ≥512M 内存

一张 12000×9000 的图（约 1.08 亿像素），GD 解码就要：

```
12000 × 9000 × 4 字节 ≈ 432 MB
```

再做缩放或重新编码会再复制一份，总计 800MB+。

**本程序的处理策略**（无需手动干预）：

1. 处理前自动把 `memory_limit` 提到 1024M
2. 超过策略组 `max_mp`（默认 0=不限；内部兜底 80MP）的图，**按策略组配置处理**：
   - `skip`：原图直传，不压缩（默认，兼容性最好）
   - `downscale`：先缩到指定长边（默认 4096）再常规处理
   - `reject`：拒绝上传并给出中文提示
3. 处理过程中出现任何异常，**回退为原图上传**，不会把用户的上传打回 500

### 快速自检

```bash
php -r "echo ini_get('memory_limit'), PHP_EOL;"
php -r "var_dump(extension_loaded('gd'), extension_loaded('curl'), extension_loaded('fileinfo'));"
```

输出建议：

```
1024M
bool(true)
bool(true)
bool(true)
```

