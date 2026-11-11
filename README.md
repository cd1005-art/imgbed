<div align="center">

# XiaoYao ImageBed · 逍遥图床

**自托管图床：上传即得稳定外链，带开放 API、多存储驱动、AI 审核、易支付变现**

PHP 8.2 · ThinkPHP 8.1 · MIT License

</div>

---

## 特性

- **上传即用**：拖拽 / 点选 / 粘贴上传，秒出 URL / Markdown / HTML / BBCode 四格式链接
- **开放 API**：`/api/v1/upload` Token 鉴权，**兼容 Lsky Pro 格式**——PicGo / ShareX / Typora 生态插件零配置接入
- **SHA1 秒传**：同文件跨渠道（网页 / API）自动复用，不重复占用存储
- **多存储驱动**：本地磁盘 / WebDAV（坚果云、Alist 聚合网盘）/ S3 协议（OSS、COS、R2），策略组按用户组分配
- **外链双模式**：代理中转（防盗链有效）/ 302 直链（零服务器流量），按存储实例配置
- **AI 审核**：多模态大模型（OpenAI 兼容端点）或本地 NSFW 模型，拒绝自动冻结，误判转人工复审
- **运营变现**：易支付协议支付（逍遥PAY / 标准易支付）、VIP 套餐、免费/付费/邀请制三种运营模式、广告位系统
- **完整后台**：仪表盘、图片、用户、策略组、存储、支付、广告、Token、日志九大模块

## 环境要求

| 项 | 要求 |
|---|---|
| PHP | >= 8.1（推荐 8.2），扩展：fileinfo / gd / mbstring / curl |
| MySQL | 5.7+ / 8.0 |
| Web | Nginx（Apache 亦可） |

## 快速开始

```bash
git clone https://github.com/cd1005-art/imgbed.git
cd imgbed
composer install --no-dev
mysql < install.sql        # 建库 imgbed + 全部表 + 初始数据
cp .env.example .env       # 改数据库连接
```

Nginx 站点配置、云桌面/服务器完整部署、支付回调联调、NSFW 审核服务部署：见 **[docs/DEPLOY.md](docs/DEPLOY.md)**

默认后台：`/admin`（admin / admin123，装完立即改密）

## API 摘要

```
POST /api/v1/upload       # 上传，Header: X-API-TOKEN（兼容 Authorization: Bearer）
GET  /api/v1/info/{id}    # 图片信息
GET  /api/v1/list         # 分页列表
POST /api/v1/delete/{id}  # 删除
```

返回含 `url / markdown / html / bbcode / sha1 / reused` 全字段，详见 `docs/`（持续补充）。

## 目录结构

```
app/
├── index/      # 前台：上传、图库、个人中心、VIP、支付回调
├── api/        # 开放 API v1
├── admin/      # 后台八模块
├── i/          # /i/{path} 外链服务（代理输出 / 302 直链）
└── common/
    ├── service/
    │   ├── storage/    # 存储驱动（local / webdav / s3 / imagehost 多链冗余）
    │   ├── audit/      # 审核驱动（llm_vlm / local_nsfw / 云审核）
    │   └── PaymentService.php   # 易支付协议（逍遥PAY / YPAY 双网关）
    └── model/
tools/nsfw-service/       # 本地 NSFW 审核推理服务（Python）
docs/DEPLOY.md            # 部署文档
```

## Roadmap

- [x] 核心：上传 / 外链 / 用户 / API / 后台 / 支付 / 审核 / 广告
- [ ] S3 驱动（OSS / COS / R2）
- [ ] 第三方图床多链冗余（主备切换 + 探活）
- [ ] WebP / AVIF 自动转换、防盗链、审核异步队列
- [ ] 皮肤系统（CSS 变量已就绪）、GitHub Pages 文档站

## 致谢

- [ThinkPHP](https://github.com/top-think/framework)（Apache-2.0）
- 设计参考：[Lsky Pro](https://github.com/lsky-org/lsky-pro) 的存储驱动与策略组架构思路（未使用其代码）

## License

MIT © [cd1005-art](https://github.com/cd1005-art)
