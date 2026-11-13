# 开发规范（团队约定，必须遵守）

> 本文件是功能开发的强制流程。违反即为缺陷，不是"待优化"。

## 一、邮件通知覆盖率（★ 标准流程）

**任何新功能上线，必须同时完成通知事件定义**，不允许"先做功能、通知以后补"。

### 新增功能时必做的 4 步

1. **定义事件**：在 `app/common/service/MailerService::EVENTS` 增加 `事件key => 中文名`（按分组：system/account/trade/report/ops）
2. **编写模板**：在 `MailerService::render()` 增加模板（变量 `{{site}} {{name}} {{link}} {{reason}} {{amount}}`）
3. **调用发送**：业务代码用 `MailerService::notify($event, $to, $subject, $template, $vars)`——**禁止直接调 send() 绕过开关**
4. **验证**：后台「邮件设置 → 通知开关」能看到新勾选项；关闭后触发业务不发信、开启后能收到

### 当前已覆盖事件（必须保持同步）

| 分组 | 事件 |
|---|---|
| 系统 | 存储告警、计划任务连续失败/恢复、站点不可用、证书到期 |
| 账号 | 注册验证、找回密码、审核拒绝、违规警告/停用、图片下架 |
| 交易 | VIP 开通、VIP 到期、站币充值、付费图被购买、提现提交、提现结果、分成结算 |
| 举报 | 举报受理、举报处理结果 |
| 运营 | 公告/版本更新（占位，待接入）|

### 未来功能接入点（示例）

- 新增「广告位购买」→ 加事件 `ad_buy`/`ad_expire`
- 新增「Token 用量提醒」→ 加事件 `token_limit`
- 新增「邀请奖励」→ 加事件 `invite_reward`
- 任何"用户花了钱 / 收到钱 / 状态被改变 / 需要运维介入"的动作，**默认必须发通知**

## 二、文档单一来源

- 文档只维护仓库版本（GitHub），服务器不放文档副本
- 改文档 = 改本地 → push

## 三、状态型功能测试纪律

- 心跳、告警去重、计数、审核状态等**测完必须回滚**；或使用临时键/沙箱账号
- 交付时说明"哪些记录是测试产生的"

## 四、服务器环境约束（宝塔）

- `exec`/`shell_exec`/`system` 被 `disable_functions` 禁用 → web 端不得依赖 CLI 调用
- `is_file`/`is_dir` 必须加 `@`（open_basedir 警告会被框架转成异常）
- 任务逻辑下沉 Service 层，CLI 命令与 web 触发共用同一实现

## 五、ThinkPHP 8 编码约定

- 模板禁止复杂表达式（`{$arr[0].key}` 会编译失败）→ 控制器传平铺变量
- `Query` 无 `insertIgnore` → 存在性检查后 insert
- 中文 URL 路径不可靠 → 用 query 参数（`/tag?name=xxx`）

## 一·补充：邮件正文禁止外链（★ 强制）

- **所有**系统邮件正文**不得包含外部链接**（URL、超链接、跳转按钮）——带链接的邮件易被邮箱服务商/网关判定为营销或钓鱼，触发风控拦截。
- 需要用户"回到网站操作"的场景，统一用**站内口令**方式：
  - 邮箱验证 / 密码重置 → 6 位数字验证码（纯文字），用户在站内输入
  - 其他通知 → 只描述结果与站内位置，不给可点击入口
- 代码层：`MailerService::render()` 模板中禁止出现 `<a href>`；模板变量统一为 `{{site}} {{expire_at}} {{reason}} {{amount}} {{name}} {{code}}`。

## 六、UI 一致性（★ 强制）

**任何新增/修改的界面，动手前必须先看已有页面的同类实现，沿用同一套结构，不允许自创样式。**

### 标准列表页结构（以「图片管理」为基准）

```html
<div class="page-toolbar">
  <h1 class="page-title">标题 <span class="count">N 条</span></h1>
  <form class="search-bar" method="get" action="/admin/xxx">
    <input type="text" name="kw" ...>
    <select name="status" class="filter-select">...</select>
    <input type="date" name="dfrom" class="filter-select">
    <button class="btn btn-sm" type="submit">筛选</button>
  </form>
</div>

<div class="batch-bar" id="xxxBatchBar" hidden>
  <span id="xxxBatchCount">已选 0 条</span>
  <button class="btn-ghost-sm btn-danger-sm" id="xxxBatchDel">批量删除</button>
  <button class="btn-ghost-sm" id="xxxBatchClear">取消选择</button>
</div>

<table class="table">...</table>
```

- 筛选控件**与标题同一行**（`.page-toolbar` 内），行内用 `.filter-select` 统一高度
- 批量操作条 `.batch-bar` **默认隐藏**，勾选后显示并显示数量，含「批量操作 / 取消选择」
- 行内操作按钮用 `.btn-ghost-sm`（危险操作用 `.btn-danger-sm`）
- 表格首列统一为勾选框（`<th style="width:28px">`）
- JS 统一用 `bindBatchDelete(cfg)`（admin.js），不重复手写勾选逻辑

### 不确定时先问

样式/交互有疑问、或与现有页面存在多种可能做法时，**先给用户 2-3 个选项并说明差异，由用户确认后再动手**，不自行拍板。

## 七、静态资源必须入仓库

`public/static/` 下有 `.gitignore`（`*` 全忽略）。手写资源必须强制跟踪：

```bash
git add -f public/static/admin.js public/static/imgbed.js public/static/admin.css public/static/imgbed.css
```

否则仓库缺失资源，换会话/换机器部署会不一致。

## 八、静态资源版本号（★ 每次必做）

改动 `public/static/admin.js`、`admin.css`、`imgbed.js`、`imgbed.css` 后，**必须**同步提升 layout 中的版本参数：

```html
<!-- app/admin/view/admin/layout.html -->
<script src="/static/admin.js?v=39"></script>
<link rel="stylesheet" href="/static/admin.css?v=39">

<!-- app/index/view/common/layout.html -->
<script src="/static/imgbed.js?v=25"></script>
```

**不升版本的后果**：浏览器继续使用缓存的旧文件，页面结构已更新但交互逻辑还是旧的，表现为"改了没效果"（2026-11-14 实际踩过：连续多轮改动均未生效，最终定位是版本号停在 v=38）。

**检查方法**：`curl -s https://域名/admin/xxx | grep -oE 'admin\.(js|css)\?v=[0-9]+'`

## 九、列表页交付标准清单（★ 新增页面前必须逐项自检）

**任何后台列表页，交付时必须同时具备下列 9 项，缺一不可，不得等用户复审后再补。**

| # | 项目 | 实现要点 |
|---|---|---|
| 1 | 标题 + 筛选同行 | `.page-toolbar` 内放 `h1.page-title` 与 `form.search-bar` |
| 2 | 筛选维度齐全 | 关键词（模糊）、状态、类型（枚举）、日期范围（起止）；按业务取舍但**至少 3 类** |
| 3 | 表格勾选列 | 表头 `<input type="checkbox" id="xxxCheckAll">` + 行 `.xxx-check` |
| 4 | 批量操作条 | `.batch-bar`（`hidden`）：`已选 N` + 批量操作按钮 + 取消选择 |
| 5 | 行内操作 | 单条删除（`data-row-del`）/ 状态切换 / 编辑，至少含删除 |
| 6 | 分页 | 至少 40 条/页 + 上下页（`$pages > 1` 才显示） |
| 7 | 空状态 | 无数据时给提示文案，不显示空表头 |
| 8 | 危险操作确认 | 删除/状态变更必须 `armConfirm` 二次确认 |
| 9 | 资源版本 | 改 JS/CSS 后升 `?v`（见第八条）|

**自检方法**：交付前 `curl` 页面并 grep：`page-toolbar` / `batch-bar` / `-check` / `data-row-del` 均应有命中；接口用空 `ids` 调用应返回「未选择」。

**JS 复用**：
- 纯删除：`bindBatchDelete({...})`
- 带动作（启用/暂停/删除多动作）：在页面初始化函数里自定义，参照 `adminGen` 的任务列表批量实现。

## 十、新功能交付话术

交付时必须**主动列出本页已具备的清单项**（筛选 / 勾选 / 批量 / 行内操作 / 分页 / 空状态 / 二次确认），不要只说"已完成"。

## 十一、财务域统一入口（★ 强制）

**所有与钱相关的配置与流水，统一收敛到后台「财务管理」一个页面（`/admin/finance`），禁止再单独开菜单入口。**

当前标签：
| 标签 | 内容 |
|---|---|
| 支付网关 | 易支付对接（地址/PID/密钥/通道/开关）|
| VIP 套餐 | 套餐增删改 |
| 最近订单 | 订单列表 + 详情/补单/关闭/删除 |
| 站币流水 | 全站站币变动 + 筛选 + 批量删除 |
| 提现管理 | 提现申请/审核/打款/驳回 |

**后续要做的（已约定，勿遗漏）**：
- **Token 调用次数包、Token 容量包、VIP 套餐赠送调用次数/容量** → **新建 `token` 标签并入本页**，不另开菜单；
- 订单前缀 `C`（容量/次数包）与 `V`（VIP）并行支持，订单类型扩展为 `vip / credits / capacity / token_pack`；
- 订单列表的类型下拉、补单逻辑（`PaymentService::manualComplete`）需同步支持新类型。

**扩展方式**：`finance.html` 加 tab-pane + `FinanceManage::index` 补查询 + `adminFinance()` 补绑定，菜单不动。
