# 前台模板预览与启用

GEOFlow 现在支持基于主题包的前台模板预览与启用。

这套能力的边界是：

- 保留现有首页、分类页、文章页、归档页的数据契约
- 保留现有 SEO、Open Graph、结构化数据输出规则
- 保留现有路由和文章 / 分类 / 归档查询逻辑
- 只替换前台展示层的模板、样式和模块外观

## 当前能力

### 1. 主题包目录

主题统一放在：

```text
themes/<theme-id>/
```

以当前样板主题为例：

```text
themes/qiaomu-editorial-20260418/
├── manifest.json
├── tokens.json
├── mapping.json
├── assets/
│   └── theme.css
└── templates/
    ├── header.php
    ├── footer.php
    ├── home.php
    ├── category.php
    ├── article.php
    └── archive.php
```

### 2. 动态预览路由

系统支持独立预览，不会直接覆盖线上前台。

预览地址格式：

```text
/preview/<theme-id>/
/preview/<theme-id>/category/<slug>
/preview/<theme-id>/article/<slug>
/preview/<theme-id>/archive
```

预览使用真实数据库内容，而不是静态假数据。

### 3. 后台模板启用

后台 `网站设置` 页面新增了 `网站模板` 区块，支持：

- 查看当前启用模板
- 预览首页 / 分类页 / 文章页 / 归档页
- 切回默认前台
- 启用某个主题包

## SEO 与多语言说明

模板切换不会改动现有 SEO 规则。

以下内容继续沿用系统原始逻辑：

- `title`
- `description`
- `keywords`
- canonical URL
- Open Graph
- JSON-LD / 结构化数据

模板预览与正式启用都会复用当前页面已有的 SEO 计算结果。

多语言方面：

- 前台界面仍然跟随当前 `lang` 参数或会话语言
- 模板只控制展示层，不单独重写语言逻辑
- 同一个主题包可以在 `zh-CN / en` 等语言状态下复用

## 首页描述规则

首页、分类页、归档页的列表卡片摘要会做展示层清洗：

- 自动去掉 Markdown 标题符号，如 `#`、`##`
- 自动去掉粗体符号，如 `**`
- 自动去掉列表与链接残留符号

这样可以保证模板卡片里的摘要更接近可阅读正文，而不是原始 Markdown 片段。

## 配套 Skill

如果你要把一个参考网址映射成 GEOFlow 可预览主题包，建议配套使用：

- Skill 仓库：[yaojingang/yao-geo-skills](https://github.com/yaojingang/yao-geo-skills)
- Skill 路径：`skills/geoflow-template`

它的职责是：

- 梳理 GEOFlow 前台模块与变量契约
- 分析参考站点的视觉 token 和模块结构
- 输出 `tokens.json / mapping.json / manifest.json`
- 先生成 preview-first 的模板包，而不是直接替换正式前台
