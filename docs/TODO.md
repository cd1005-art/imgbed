# 待办清单

## SEO 增强 — 已完成（2026-11-15）

### 现状盘点
- 路由：`/p/:id`（详情）、`/explore`（广场）、`/tag?name=`（标签）；已有 title/description/OG/canonical
- `img_images` 字段：`name,path,width,height,size,mime,create_time,update_time,audit_status,status,is_public,view_count,origin`
- `public/robots.txt`：全放开，**无 Sitemap 行**
- 无 sitemap 路由、无物理 sitemap 文件

### ~~P1 sitemap.xml~~ ✅ 已完成
- 新增 `app/index/controller/Sitemap.php::index()`
  - 收录：首页 `/`、广场 `/explore`、广场分页 `/explore?page=N`（上限 20 页）、详情 `/p/{id}`、标签 `/tag?name=`（上限 500）
  - 详情筛选：`is_public=1 AND status=1 AND audit_status=1 AND delete_time IS NULL`；`lastmod=update_time`
  - 单文件 ≤ 50000 条；超出拆 `sitemap-1.xml` 并用索引 `sitemap.xml` 指向（当前量小，仅留扩展）
- 路由：`Route::get('sitemap.xml', 'Sitemap/index')`
- 缓存：输出写 `runtime/sitemap/sitemap.xml`，TTL 1800s；响应头 `X-Sitemap-Cache: hit|miss`、`Cache-Control: public,max-age=1800`
- `robots.txt` 增加 `Sitemap: {domain}/sitemap.xml`
- 不做：百度主动推送（需站长 token）、Google sitemap ping（2023 已停用）——仅在文档中说明手工提交

### ~~P2 详情页 JSON-LD~~ ✅ 已完成
- `Photo::show` 组数据（`seo_jsonld`），模板 `head_extra` 输出 `<script type="application/ld+json">`
- `ImageObject`：name/description/contentUrl/thumbnailUrl/url/width/height/encodingFormat/contentSize/datePublished/dateModified/author/isPartOf/interactionStatistic(view_count)
- `BreadcrumbList`：首页 → 图片广场 → 首个标签 → 图片标题
- 用 `json_encode(..., JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)`，模板不拼 JSON

### ~~P3 验收~~ ✅ 已通过（sitemap 654 URL / 私密泄露 0 / JSON-LD 字段齐全 / 缓存 hit）
1. `curl /sitemap.xml` → 200 + `application/xml` + `<urlset`；URL 总数 = 首页1+广场(1+N)+详情(公开通过数)+标签数
2. 断言：私密/待审/拒绝/冻结图**不在** sitemap（取一张私密图 id 反查）
3. 详情页 JSON-LD：`json_decode` 成功、`@type=ImageObject`、`contentUrl` 为绝对地址
4. 连续两次请求第二次 `X-Sitemap-Cache: hit`
5. `robots.txt` 含 Sitemap 行
6. Google Rich Results Test（用户浏览器提交 `/p/{id}`）无错误

### 风险与限制
- 动态 sitemap 首次生成耗时与图片量线性相关（万级约数百 ms，可接受）；已加缓存
- 标签页 URL 含中文需 `urlencode` + XML 转义
- 广场分页只进前 20 页，避免 sitemap 被分页噪声占满


## 造图任务增强 — 已完成（2026-11-15）

### ~~P4 任务详情翻页~~ ✅ 已完成（255 张分 22 页）
- `GenManage::detail(int $id, int $page = 1)`：每页 12 张，返回 `total/pages/page/items`
- 查询：`where('gen_task_id',$id)`，`order('id','desc')`，`page($page,12)`
- 弹窗底部加 `< 上一页 1/3 下一页 >`，点击原地刷新图片区（不关闭弹窗）
- 验收：35 张图的详情可分 3 页看完；翻页后标题计数与实际一致

### ~~P5 近 7 日趋势统计~~ ✅ 已完成
- `GenManage::index` 增加 `trend`：按天聚合 `gen_task_logs`（近 7 天）的 `gen_count/success_count/fail_count`
- 视图：执行日志顶部汇总条下方加 7 列迷你柱（纯 CSS div 高度百分比，不引图表库）
- 每列悬停 title 显示「日期 生成X 成功Y 失败Z」
- 验收：7 个柱子出现，数值与 DB 逐日聚合一致（抽 1 天核对）

### ~~P6 提示词「未使用」列表~~ ✅ 已完成（未命中 756 / 列前 50）
- `GenManage::promptUsage` 额外返回 `unused`（最近 800 条日志中未命中的池子提示词，最多 50 条）
- 视图「使用情况」面板输出两段：`最常用`（现有 top20）+ `从未抽到（前 50）`
- 验收：池子 1000 条、用过若干 → 两段数量相加 ≤ 池子总数；未使用段可直接复制去替换

## 已完成（供参考）


- [x] 详情页 title / description / Open Graph / canonical（2026-11-14）
- [x] 造图任务完整功能（调度/提示词中心/批量/详情预览/统计）
- [x] 财务管理五标签合并
- [x] 图片标题 = 审核描述清洗
