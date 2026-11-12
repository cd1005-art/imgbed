# 踩坑清单（PITFALLS）

> 本项目开发过程中实际踩过的坑，按类别整理。**改代码前先扫一遍对应类别**，避免重复犯错。
> 新增坑：出一次记一次，附"现象 / 根因 / 修法"。

---

## 一、ThinkPHP 模板（think-template）

| # | 现象 | 根因 | 修法 |
|---|---|---|---|
| 1 | 页面 200 但**整页空白** | 输出标签里写了复杂表达式（如 `{$vo.x|default=($vo.a ?: $vo.b)}`），模板编译失败被吞 | 禁止复杂表达式；一律**控制器注入成品字符串**，模板只用 `{$vo.plain_field}` |
| 2 | 下拉选项提交**悬空 ID**（如选了第3项提交 id=3 实际不存在） | `{volist key="k"}` 的 k 是**迭代序号**不是数组键 | 用 `{foreach $arr as $k => $v}` 取真实键 |
| 3 | 列表里 `{$storages[$vo.storage_id]}` 恒为空 | 动态键 + default 过滤器组合不可靠；键类型不一致 | 控制器 `each()` 注入显示字段（`storage_name`、`user_display`、`token_display`…），模板静态键 |
| 4 | 页面某区域 `hidden` 失效、默认展开 | 作者样式 `display:grid/flex` 覆盖了 `[hidden]` 的 UA 样式 | CSS 全局兜底 `[hidden]{display:none!important}` |
| 5 | 表单提交后编辑回填旧值/0 | 编辑回填读的是 `window.__groups` 等**页面加载时快照**；保存后没刷新页面 | 保存成功必须 `location.reload()`；新增字段要同步补回填行（且 patch 必须 assert 命中） |
| 6 | 同名输入框互相覆盖（local/webdav 两段都有 `quota_gb`，隐藏段 0 覆盖可见段 10） | 隐藏≠禁用，隐藏段的 input 照样提交 | 切换显隐时同步 `input.disabled`（见 admin.js `syncCfgFields`） |

## 二、路由（TP8）

| # | 现象 | 根因 | 修法 |
|---|---|---|---|
| 7 | 新子路由 404 或被父页接管（`GET image/export` 返回图片管理页 HTML） | 短规则 `GET image` **先注册**会抢占 `image/export` | **具体路由放前面**，通用规则放后面 |

## 三、模型 / 数据层

| # | 现象 | 根因 | 修法 |
|---|---|---|---|
| 8 | 时间全显示 `01-01 08:33` | TP8/orm4 把 int 时间字段读成 datetime 字符串，模板 `(int)` 强转得到年份 2026 | 全站展示统一走 `ts()` 助手（int/字符串双兼容） |
| 9 | 新加的列 Model 读不到/保存丢字段 | 表结构字段缓存；`fields_cache` 配置差异 | `config/database.php` 关 `fields_cache`；加列后清 runtime 缓存并重启 fpm |
| 10 | `Class "app\common\service\MultiModelAuditDriver" not found`，审核全部转人工 | new 时**漏了子命名空间**（正确为 `service\audit\...`） | 跨子命名空间必须写全限定名或顶部 use |
| 11 | 配额出现负数（-1.3 MB） | 删除回退 `dec()` 无下限，多删即负 | SQL 用 `GREATEST(0, quota_used - size)` |
| 12 | 删除图片后磁盘文件还在 | 早期只做软删未删文件 | 删除时同步 `StorageManager::driver(...)->delete(path)`（后台/前台/API 四处） |

## 四、前端静态资源

| # | 现象 | 根因 | 修法 |
|---|---|---|---|
| 13 | 改了 JS/CSS 用户看不到变化，功能"像没上线" | 文件名不变，浏览器强缓存 | layout 引用带 `?v=N`，**每次改静态资源必须递增 v** |
| 14 | toast 不显示、按钮点不动、批量条不出现 | JS 里调用的函数在另一个 IIFE 内未暴露（如 `toast`） | IIFE 内 `window.toast = toast`；新页面必须 `{block name="script"}pageBoot('xxx'){/block}`，否则一切交互静默失效 |

## 五、Nginx / 部署

| # | 现象 | 根因 | 修法 |
|---|---|---|---|
| 15 | 外链 `/i/xxx.jpg` 404，上传成功但打不开 | 宝塔静态缓存正则 `location ~ .*\.(jpg|png…)$` 优先级高于 `location /`，请求被当静态文件 | `location ^~ /i/ { if (!-e $request_filename) { rewrite ^ /index.php$request_uri last; } }` |
| 16 | 所有子页面都渲染首页 | 宝塔 try_files 直跳 `/index.php` 不带 PATH_INFO，TP8 只认 PATH_INFO | 伪静态改 `if (!-e $request_filename) { rewrite ^(.*)$ /index.php/$1 last; }` |
| 17 | img.oddpp.com 显示 qqbot 内容 | 443 没有本站 server 块，落到 443 默认 server | 补 SSL server 块（证书）+ 确认 include 顺序 |
| 18 | `pkill -f 'php-fpm'` 后全站 502 且拉不起来 | `-f` 匹配命令行，把执行 pkill 的 shell 自己也杀了，后续启动命令没跑 | 用 `killall php-fpm`；重启后务必验证 sock 与首页 200 |
| 19 | 网盘上传成功但外链打不开 | 302 直链指向内网 IP，外网不可达 | 内网网盘一律用「代理中转」模式（/i/ 输出） |
| 20 | 网盘剩余空间读不到 | 标准 WebDAV 无配额接口；Alist 对部分 provider 也不返回 quota | 存储卡片提供「手工配额上限（GB）」按图床已用估算 |

## 六、开发流程纪律（最重要）

| # | 教训 |
|---|---|
| 21 | **所有 python patch 的 `replace` 必须带 `assert old in src`**——静默失败会造成"以为改了其实没改"（多通道 type 行、max_tokens 回填行都因此假上线） |
| 22 | SSH 的 heredoc 会被截断——远程改文件一律走 SFTP 上传完整文件 |
| 23 | 改完必跑 `php -l`；上线后必 curl 验证**渲染结果**（元素/值），不能只看 HTTP 200 |
| 24 | 变量改名要全量 grep 残留（闭包 `use($old)`、视图数组 key 都是重灾区） |
| 25 | 阿里云/云桌面环境下 WorkBuddy 代理会劫持 curl——本机/内网访问必须 `--noproxy '*'` |
| 26 | 后端字段名与前端表单 name 必须逐一核对；两段式表单（local/webdav）注意同名冲突 |
