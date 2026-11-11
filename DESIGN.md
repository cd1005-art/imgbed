# DESIGN.md — imgbed 设计世界（自建工具风）

> 视觉世界的唯一权威文件。所有前台页面按本文件执行。
> 模式基调：Operate（工具 UI）——扫描性、一致性、真实使用场景压倒表达。

## 1. 氛围

安静的本地工具感：纸感暖白底、墨色文字、一个深绿功能色。界面退后，图片是唯一的主角。
密度中等偏紧。不做营销页的花哨，不做 dashboard 的卡片阵列。

## 2. 色板（浅色主题）

| 语义 | 值 | 角色 |
|---|---|---|
| `--bg` | `#FAF9F7` | 页面底（纸感微暖，不用纯白） |
| `--surface` | `#FFFFFF` | 卡片/输入框底 |
| `--ink` | `#1C1B18` | 主文字（对比 15.4:1） |
| `--ink-2` | `#5C5850` | 次级文字（暖灰，对比 6.9:1；禁纯灰 #999） |
| `--line` | `#E4E1DB` | 边框/分隔线 |
| `--accent` | `#2E6B4F` | 主功能色（按钮实底、链接、聚焦态；对比白字 7.2:1） |
| `--accent-hover` | `#245740` | 按钮 hover |
| `--accent-soft` | `#E7F0EB` | 功能色浅底（成功提示底） |
| `--danger` | `#B3402A` | 删除/错误（对比白字 6.1:1） |
| `--danger-soft` | `#F7E9E5` | 错误提示底 |
| `--code-bg` | `#F1EFEA` | 等宽/URL 展示底 |

选色理由：深绿不是 AI 生成的紫蓝渐变默认值；暖灰系不是机器灰。

## 3. 字体

```css
--font-sans: 'Noto Sans SC', 'PingFang SC', 'Microsoft YaHei', sans-serif;
--font-mono: 'JetBrains Mono', 'Cascadia Code', Consolas, monospace;
```

- 中文负字距慎用：display 字距 `-0.015em` 封顶
- 层级：页面标题 22px/700 · 区块标题 16px/600 · 正文 14px/400 · 辅助 12px
- 正文行宽 65–75ch（表单/文档）；列表项不限
- **等宽字体只用于真数据**：外链 URL、Token 值、文件名。绝不作"技术感"装饰

## 4. 组件

- **按钮**：实底（accent 底白字）、描边（1px line）、幽灵（无框，次级操作）。高度 36px，触屏 44px。hover 加深不加阴影。禁渐变按钮
- **输入框**：1px line 边框，focus 换 accent 边框 + 2px soft ring（`box-shadow: 0 0 0 3px #E7F0EB`），无发光
- **上传拖拽区**：2px dashed line 边框，拖入态换 accent 实边 + accent-soft 底。区内是图标（SVG）+ 一句主文案 + 一句限制说明，不堆参数徽章
- **图片网格**：缩略图固定高 160px object-fit:cover，信息行（文件名截断 + 尺寸）。操作=复制/删除，图标按钮
- **Toast**：底部居中，深底白字，2s 自灭。不用 alert
- **表格（后台）**：表头 12px ink-2 加 600，行 hover surface 加深一档

## 5. 间距与布局

刻度：4 / 8 / 12 / 16 / 24 / 32 / 48 / 64。
节奏：标题上方留白 > 下方（上 32 下 12）。表单行距 16。区块间距 48。
栅格：内容列 max-width 880px 居中；我的图片网格 auto-fill minmax(200px,1fr)。
断点：640px（网格降 2 列、按钮升 44px）、960px。

## 6. 深度

两级阴影，都有偏移有模糊：
`--shadow-1: 0 1px 2px rgba(28,27,24,.06)`
`--shadow-2: 0 4px 16px rgba(28,27,24,.10)`（toast / 弹层专用）
**禁止**：零偏移彩色光晕、硬偏移块影（4px 4px 0）、嵌套卡片。

## 7. Don'ts（硬禁令，治 AI 味）

1. 禁 kicker/eyebrow（标题上方小标签字）
2. 禁同尺寸 icon+标题+文案卡片阵列当页面结构
3. 禁渐变文字、渐变按钮、玻璃拟态装饰
4. 禁彩色 border-left（>1px）当强调
5. 禁 emoji / Unicode 符号当图标——图标一律手绘 SVG，1.5px stroke 统一
6. 禁系统展示字体（Impact/Arial Black）与装饰性等宽
7. 禁"操作失败，请稍后重试"式废话文案——错误点名问题+恢复方法
8. 禁 hero 大数字+小标签的指标模板
9. 弹窗只用于需要保护注意力的操作（删除确认），能用行内提示就不弹窗

## 8. 浏览器原生件（必须主题化）

```css
::selection { background:#2E6B4F; color:#fff; }
:focus-visible { outline:2px solid #2E6B4F; outline-offset:2px; }
caret-color:#2E6B4F;
scrollbar 细轨 #E4E1DB，滑块 #C9C5BD;
```

## 9. 动效（唯一主时刻）

上传成功：缩略图从上传区位置 scale(.96)+blur(4px) 进入到网格/结果区，exponential ease-out 260ms。
其余交互 transition 120ms（边框色/底色），无入场动画堆砌。

## 10. 响应式

- 640px 以下：上传区高度收紧、网格 2 列、顶栏折叠为图标、触控目标 44px
- 图片预览：查看页图片 max-width:100%，底色 #F1EFEA，不做灯箱特效（一期）
