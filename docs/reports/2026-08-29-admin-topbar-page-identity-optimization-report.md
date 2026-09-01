# 后台页面顶栏身份与正文标题区优化报告

日期：2026-08-29

## 完成情况

本轮已完成后台 V3 界面的页面身份统一。100 个标准后台页面、1 个多入口同步预览页、1 个壳内错误页均已接入顶栏短标题和匹配图标，共 102 套页面方案。内容管理页另有回收站状态，因此实际覆盖 103 个标题与图标状态。

页面正文采用两种处理方式：列表、表单和设置页收起原有大标题与说明；详情、AI 工作台和错误页保留对当前对象或当前状态有意义的标题。原有操作按钮、返回入口、状态标签、风险提示、步骤说明和筛选区保持可见。

## 统一规则

| 位置 | 规则 |
|---|---|
| 顶栏左侧 | 显示页面短标题和语义匹配图标，桌面端图标为 18px，标题为 15px |
| 移动端顶栏 | 保留 14px 短标题，隐藏图标，为菜单和用户操作留出空间 |
| 通用正文标题 | 在 V3 界面中视觉收起，保留单一 H1 语义，兼顾屏幕阅读器和页面结构 |
| 动态详情标题 | 保留对象名称、域名、状态或任务进度，作为正文内容继续显示 |
| 说明与警示 | 保留业务说明、风险提示、连接步骤和错误恢复信息 |
| 旧版界面 | 关闭 V3 开关后继续显示原有标题和说明，不受本轮规则影响 |

## 页面覆盖清单

“收起”表示原有通用大标题和紧随其后的说明在 V3 界面中不再占据可见空间。“保留”表示正文标题承载对象名称、状态或任务语境，继续作为内容显示。

### AI 工作台与数据中心

| # | 路由 | 顶栏短标题 | 图标 | 正文标题 |
|---:|---|---|---|---|
| 1 | `admin.ai-workspace` | AI 工作台 | `sparkles` | 保留 |
| 2 | `admin.dashboard` | 运营工作台 | `layout-dashboard` | 收起 |
| 3 | `admin.analytics` | 数据中心 | `chart-no-axes-combined` | 收起 |
| 4 | `admin.analytics.content` | 内容分析 | `file-text` | 收起 |
| 5 | `admin.analytics.traffic` | 流量分析 | `activity` | 收起 |
| 6 | `admin.analytics.ai-visibility` | AI 可见性 | `eye` | 收起 |
| 7 | `admin.analytics.leads` | 线索分析 | `users-round` | 收起 |
| 8 | `admin.analytics.distribution` | 分发分析 | `radio-tower` | 收起 |

### 任务管理

| # | 路由 | 顶栏短标题 | 图标 | 正文标题 |
|---:|---|---|---|---|
| 9 | `admin.tasks.index` | 任务管理 | `workflow` | 收起 |
| 10 | `admin.tasks.workers` | Worker 监控 | `cpu` | 收起 |
| 11 | `admin.tasks.jobs` | 任务记录 | `list-checks` | 收起 |
| 12 | `admin.tasks.create` | 创建任务 | `workflow` | 收起 |
| 13 | `admin.tasks.edit` | 编辑任务 | `square-pen` | 收起 |

### 内容管理与手动发布

| # | 路由 | 顶栏短标题 | 图标 | 正文标题 |
|---:|---|---|---|---|
| 14 | `admin.articles.index` | 内容管理 | `file-text` | 收起 |
| 15 | `admin.articles.create` | 创建文章 | `file-plus-2` | 收起 |
| 16 | `admin.articles.edit` | 编辑文章 | `file-pen-line` | 收起 |
| 17 | `admin.manual-publications.browser-connect.show` | 连接浏览器 | `monitor-smartphone` | 收起 |
| 18 | `admin.manual-publications.index` | 手动发布 | `send` | 收起 |
| 19 | `admin.manual-publications.create` | 创建发布 | `send` | 收起 |
| 20 | `admin.manual-publications.settings.index` | 发布设置 | `settings-2` | 收起 |
| 21 | `admin.manual-publications.show` | 发布详情 | `file-check-2` | 保留 |
| 22 | `admin.manual-publications.edit` | 编辑发布 | `file-pen-line` | 收起 |

内容管理的 `trashed=1` 状态显示“回收站”和 `trash-2` 图标，正文仍按收起规则处理。

### 分发管理

| # | 路由 | 顶栏短标题 | 图标 | 正文标题 |
|---:|---|---|---|---|
| 23 | `admin.distribution.index` | 分发管理 | `radio-tower` | 收起 |
| 24 | `admin.distribution.create` | 新建渠道 | `radio-tower` | 收起 |
| 25 | `admin.distribution.hosted-sites.index` | 托管站点 | `globe-2` | 收起 |
| 26 | `admin.distribution.hosted-sites.create` | 创建站点 | `globe-2` | 收起 |
| 27 | `admin.distribution.hosted-sites.show` | 站点详情 | `globe` | 保留 |
| 28 | `admin.distribution.hosted-sites.edit` | 编辑站点 | `settings-2` | 收起 |
| 29 | `admin.distribution.jobs` | 分发任务 | `list-checks` | 收起 |
| 30 | `admin.distribution.article.edit` | 编辑文章 | `file-pen-line` | 收起 |
| 31 | `admin.distribution.delete` | 删除渠道 | `triangle-alert` | 收起 |
| 32 | `admin.distribution.edit` | 编辑渠道 | `settings-2` | 收起 |
| 33 | `admin.distribution.show` | 渠道详情 | `radio-tower` | 保留 |
| 34 | 三个 `sync-settings` 预览入口 | 同步预览 | `git-compare-arrows` | 收起 |

同步预览页覆盖单渠道、全部渠道和已选渠道三个入口。页面中的风险结论、渠道数量、警告数量和待发送设置继续显示。

### 内容资产

| # | 路由 | 顶栏短标题 | 图标 | 正文标题 |
|---:|---|---|---|---|
| 35 | `admin.categories.index` | 分类管理 | `folder` | 收起 |
| 36 | `admin.categories.create` | 新建分类 | `folder-plus` | 收起 |
| 37 | `admin.categories.edit` | 编辑分类 | `folder-cog` | 收起 |
| 38 | `admin.authors.index` | 作者管理 | `users` | 收起 |
| 39 | `admin.authors.create` | 新建作者 | `user-plus` | 收起 |
| 40 | `admin.authors.edit` | 编辑作者 | `user-round-pen` | 收起 |
| 41 | `admin.authors.detail` | 作者详情 | `user` | 保留 |
| 42 | `admin.keyword-libraries.index` | 关键词库 | `tag` | 收起 |
| 43 | `admin.keyword-libraries.create` | 新建关键词库 | `tag` | 收起 |
| 44 | `admin.keyword-libraries.edit` | 编辑关键词库 | `tag` | 收起 |
| 45 | `admin.keyword-libraries.detail` | 关键词库详情 | `library-big` | 保留 |
| 46 | `admin.keyword-libraries.keywords.create` | 添加关键词 | `tag` | 收起 |
| 47 | `admin.keyword-libraries.import.create` | 导入关键词 | `file-up` | 收起 |
| 48 | `admin.title-libraries.index` | 标题库 | `type` | 收起 |
| 49 | `admin.title-libraries.create` | 新建标题库 | `type` | 收起 |
| 50 | `admin.title-libraries.edit` | 编辑标题库 | `type` | 收起 |
| 51 | `admin.title-libraries.detail` | 标题库详情 | `library-big` | 保留 |
| 52 | `admin.title-libraries.titles.create` | 添加标题 | `type` | 收起 |
| 53 | `admin.title-libraries.import.create` | 导入标题 | `file-up` | 收起 |
| 54 | `admin.title-libraries.ai-generate` | AI 生成标题 | `wand-sparkles` | 收起 |
| 55 | `admin.image-libraries.index` | 图片库 | `images` | 收起 |
| 56 | `admin.image-libraries.create` | 新建图片库 | `image-plus` | 收起 |
| 57 | `admin.image-libraries.edit` | 编辑图片库 | `images` | 收起 |
| 58 | `admin.image-libraries.detail` | 图片库详情 | `library-big` | 保留 |
| 59 | `admin.image-libraries.images.create` | 上传图片 | `upload` | 收起 |
| 60 | `admin.knowledge-bases.index` | 知识库 | `library-big` | 收起 |
| 61 | `admin.knowledge-bases.create` | 新建知识库 | `library-big` | 收起 |
| 62 | `admin.knowledge-bases.edit` | 编辑知识库 | `library-big` | 收起 |
| 63 | `admin.knowledge-bases.detail` | 知识库详情 | `file-search` | 保留 |
| 64 | `admin.enterprise-knowledge.index` | 企业知识 | `database-zap` | 收起 |
| 65 | `admin.enterprise-knowledge.create` | 新建知识项目 | `database-zap` | 收起 |
| 66 | `admin.enterprise-knowledge.show` | 知识项目详情 | `file-search` | 保留 |
| 67 | `admin.materials.index` | 内容资产 | `database` | 收起 |
| 68 | `admin.url-import` | URL 智能导入 | `link` | 收起 |
| 69 | `admin.url-import.history` | 导入历史 | `history` | 收起 |
| 70 | `admin.url-import.show` | 导入详情 | `file-search` | 保留 |

### AI 配置

| # | 路由 | 顶栏短标题 | 图标 | 正文标题 |
|---:|---|---|---|---|
| 71 | `admin.ai.configurator` | AI 配置器 | `network` | 收起 |
| 72 | `admin.ai-models.index` | 模型管理 | `cpu` | 收起 |
| 73 | `admin.ai-models.create` | 新建模型 | `cpu` | 收起 |
| 74 | `admin.ai-models.edit` | 编辑模型 | `cpu` | 收起 |
| 75 | `admin.ai-source-providers.index` | 信源管理 | `plug-zap` | 收起 |
| 76 | `admin.ai-source-providers.create` | 新建信源 | `plug-zap` | 收起 |
| 77 | `admin.ai-source-providers.edit` | 编辑信源 | `plug-zap` | 收起 |
| 78 | `admin.ai-prompts` | 提示词管理 | `message-square` | 收起 |
| 79 | `admin.ai-prompts.create` | 新建提示词 | `message-square` | 收起 |
| 80 | `admin.ai-prompts.edit` | 编辑提示词 | `message-square` | 收起 |
| 81 | `admin.ai-special-prompts` | 特殊提示词 | `list` | 收起 |

### 系统与网站设置

| # | 路由 | 顶栏短标题 | 图标 | 正文标题 |
|---:|---|---|---|---|
| 82 | `admin.account.show` | 账户与权限 | `user-round-cog` | 收起 |
| 83 | `admin.account.browser-clients.index` | 浏览器客户端 | `monitor-smartphone` | 收起 |
| 84 | `admin.system-updates.index` | 系统更新 | `refresh-cw` | 收起 |
| 85 | `admin.system-updates.runs.show` | 更新详情 | `history` | 收起 |
| 86 | `admin.system-updates.backups.show` | 备份详情 | `archive` | 收起 |
| 87 | `admin.lead-forms.index` | 转化表单 | `clipboard-list` | 收起 |
| 88 | `admin.lead-forms.create` | 新建表单 | `file-plus-2` | 收起 |
| 89 | `admin.lead-forms.edit` | 编辑表单 | `file-pen-line` | 收起 |
| 90 | `admin.leads.index` | 线索管理 | `inbox` | 收起 |
| 91 | `admin.leads.show` | 线索详情 | `user` | 收起 |
| 92 | `admin.site-settings.index` | 网站设置 | `settings` | 收起 |
| 93 | `admin.site-settings.homepage-modules.edit` | 首页模块 | `panels-top-left` | 收起 |
| 94 | `admin.site-settings.theme-replications.create` | 新建主题复刻 | `copy-plus` | 收起 |
| 95 | `admin.site-settings.theme-replications.show` | 主题复刻详情 | `copy-check` | 保留 |
| 96 | `admin.site-settings.sensitive-words` | 敏感词管理 | `shield-alert` | 收起 |
| 97 | `admin.admin-users.index` | 管理员用户 | `users` | 收起 |
| 98 | `admin.admin-users.create` | 新建管理员 | `user-plus` | 收起 |
| 99 | `admin.admin-users.edit` | 编辑管理员 | `user-round-cog` | 收起 |
| 100 | `admin.admin-activity-logs` | 操作日志 | `history` | 收起 |
| 101 | `admin.api-tokens.index` | API Token | `key-round` | 收起 |

### 壳内错误页

| # | 页面 | 顶栏短标题 | 图标 | 正文标题 |
|---:|---|---|---|---|
| 102 | 后台模型未找到 | 页面不存在 | `circle-alert` | 保留 |

错误页顶栏使用“页面不存在”，正文继续显示“信息未找到”、原因说明和恢复操作。

## 多语言覆盖

短标题已覆盖简体中文、英语、日语、西班牙语、俄语和巴西葡萄牙语。每种语言包含 102 个页面标题键，页面身份在请求时按当前语言解析，切换语言后无需缓存刷新。

## 实现位置

| 文件 | 作用 |
|---|---|
| `app/Support/AdminUiRegistry.php` | 维护路由、短标题键、图标和正文标题模式 |
| `app/Providers/AppServiceProvider.php` | 向后台公共布局注入当前页面身份 |
| `resources/views/admin/layouts/app.blade.php` | 将页面身份传给顶栏，并声明正文标题模式 |
| `resources/views/components/admin/v3/topbar.blade.php` | 渲染短标题和图标 |
| `resources/css/app.css` | 控制顶栏尺寸、移动端适配和正文标题的无障碍收起 |
| `lang/*/admin_pages.php` | 保存六种语言的短标题 |

页面仍可通过 `topbar-title`、`topbar-icon` 和 `body-heading` 三个 Blade 区段覆盖共享规则。内容管理回收站和壳内错误页使用了这套覆盖机制。

## 验收结果

| 检查项 | 结果 |
|---|---|
| 标准后台页面覆盖 | 100 个 GET 页面均有页面身份 |
| 共享预览入口 | 2 个 GET 和 1 个 POST 入口均显示“同步预览” |
| 页面状态覆盖 | 103 个路由状态均能解析短标题和图标 |
| 多语言 | 6 种语言无缺失键或原始翻译键外露 |
| 图标 | 57 个实际使用的 Lucide 图标均存在于当前运行时 |
| 页面语义 | 每个标准后台页面在正文中保留且仅保留 1 个 H1 |
| 桌面端 | 内容管理、数据中心、创建文章、连接浏览器、AI 工作台和错误页已截图检查 |
| 移动端 | 390px 宽度下短标题可见，无横向溢出 |
| 前端构建 | `npm run build` 通过 |
| PHP 测试 | 2014 项测试通过，共 19682 条断言 |

## 未纳入范围

登录页、独立主题预览、独立 403/404/500 页面、跳转路由、文件下载、JSON 状态接口和二进制响应继续使用各自的界面或响应结构。

## 后续维护规则

新增后台壳页面时，需要在 `AdminUiRegistry` 中登记短标题键、图标和正文标题模式，并在六个 `admin_pages.php` 文件中补齐翻译。全页冒烟测试会在页面缺少身份、出现重复 H1 或翻译键缺失时失败。
