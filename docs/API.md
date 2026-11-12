# API 使用说明

图床提供 **Lsky Pro 兼容**的 API，可直接对接 PicGo / Typora / ShareX / 自写脚本。

## 一、获取 Token

1. 登录站点 → 个人中心（`/console`）→ API Token → 生成
2. **完整 Token 只显示一次**，立即复制保存（列表只保留中间打码版本）
3. Token 的生成权限由所属策略组控制（`-1`=未开放 / `0`=不限 / `N`=最多 N 个）

## 二、鉴权

任选一种（请求头优先）：

```
X-API-TOKEN: <你的Token>
Authorization: Bearer <你的Token>
```

## 三、接口

基础地址：`https://img.oddpp.com/api/v1`

| 方法 | 路径 | 说明 |
|---|---|---|
| POST | `/upload` | 上传图片（multipart，字段名 `file`）|
| GET | `/info/{id}` | 查询图片信息 |
| GET | `/list?page=1&size=20` | 我的图片列表 |
| POST | `/delete/{id}` | 删除图片（软删+删存储文件）|

### 上传示例（curl）

```bash
curl -X POST "https://img.oddpp.com/api/v1/upload" \
  -H "X-API-TOKEN: 你的Token" \
  -F "file=@/path/to/photo.jpg"
```

返回（Lsky 兼容）：

```json
{
  "status": true,
  "code": 200,
  "message": "success",
  "data": {
    "id": 42,
    "name": "photo.jpg",
    "url": "https://img.oddpp.com/i/2026/11/13/xxx.jpg",
    "markdown": "![photo](https://img.oddpp.com/i/2026/11/13/xxx.jpg)",
    "html": "<img src=\"https://img.oddpp.com/i/2026/11/13/xxx.jpg\" alt=\"photo\">",
    "size": 123456, "width": 1920, "height": 1080, "reused": false
  }
}
```

说明：相同图片会触发「秒传复用」（`reused: true`），不重复占用存储。

## 四、客户端配置

### PicGo（推荐）

1. PicGo → 插件设置 → 搜索安装 **lskypro** 或 **picgo-plugin-lankong**（Lsky Pro 兼容插件）
2. 图床配置：
   - **API 地址**：`https://img.oddpp.com`（或 `https://img.oddpp.com/api/v1`，看插件要求）
   - **Token**：生成的那个
   - 上传字段：`file`
3. 设为默认图床，拖图即传

### Typora

设置 → 图像 → 上传服务选 **PicGo(app)** → 其余同 PicGo 配置。

### ShareX / 自写脚本

按上方接口自行对接；鉴权头 `X-API-TOKEN`。

## 五、调用口径与限制（后台可配）

- **单图上限**、**日上传次数**、**允许格式**、**存储容量** 由所属策略组决定（个人中心「我的权益」可查）
- Token **调用次数**统计（免费功能，仅用量统计非收费）：每次鉴权通过的请求计 1 次；上传成功/失败分列统计（后台 API Token 页）
- 存储空间/配额达到上限时上传会被拒绝（提示具体原因）

## 六、常见问题

| 问题 | 原因 |
|---|---|
| 401 invalid token | Token 复制不完整 / 已被删除 / 已停用 |
| 400 文件不是有效图片 | 上传的不是图片或后缀与内容不符 |
| 400 上传太频繁（日上限）| 策略组日次数用尽，等次日或升级 |
| 图片 404 | 检查链接是否完整（应为 /i/年/月/日/文件名）|
