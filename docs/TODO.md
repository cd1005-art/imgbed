# 待办清单（约定后统一实施）

> 记录用户明确要求「稍后一起做」的功能，实施前不擅自动手。

## SEO 增强（2026-11-14 约定）

- [ ] **1. sitemap.xml 生成与提交**
  - 生成 `/sitemap.xml`：首页、图片广场分页、图片详情页（`/p/{id}`）、标签页；单文件上限 5 万条，超出拆 sitemap 索引
  - 仅收录 `is_public=1 AND status=1 AND audit_status=1` 的图
  - 缓存（如每小时重建），避免每次动态查询全表
  - **需用户操作**：注册/登录百度搜索资源平台、Google Search Console，验证站点所有权后提交 sitemap 地址
  - 可选：自动 ping（百度/Google 的 sitemap ping 接口）

- [ ] **2. 图片详情页结构化数据（schema.org ImageObject）**
  - 在 `/p/{id}` 输出 JSON-LD：`@type: ImageObject`、`contentUrl`、`thumbnailUrl`、`name`（图片标题）、`description`（标签拼接）、`author`、`datePublished`、`width`/`height`、`isPartOf`（站点）
  - 附加 `BreadcrumbList`（首页 → 图片广场 → 标签 → 图片）
  - **验证**：Google Rich Results Test / 百度结构化数据工具

## 已完成（供参考）

- [x] 详情页 title / description / Open Graph / canonical（2026-11-14）
- [x] 造图任务完整功能（调度/提示词中心/批量/详情预览/统计）
- [x] 财务管理五标签合并
- [x] 图片标题 = 审核描述清洗
