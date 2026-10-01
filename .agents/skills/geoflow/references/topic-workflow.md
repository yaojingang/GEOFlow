# 专题管理与模板设计

专题以已有公开文章为来源，保存文章关联、摘要、阅读分组和来源说明。专题内容与网站主题模板是两个独立对象；模板包保存展示代码，专题任务保存生成规则和内容。

## 先核对当前实例

- 有源码：运行 `discover_geoflow_workspace.py`、`discover_themes.py`、`discover_frontend_surfaces.py`，核对 `topic_contract`、主题 `topic` 声明、专题资源文件和首页模块。
- 无源码：读取 CLI help、身份、capabilities、`themes.contract` 和选定工作区的 contract。权限与操作必须同时得到当前客户端和实例支持。
- 本地发现脚本只读取源码目录。安装包主题、当前启用主题和不可变绑定版本以实例返回值为准。发现了源码能力仍需确认站点已开启专题、存在公开内容。
- 管理路径由实例配置决定。使用后台导航、路由名称或当前路由清单定位入口，保留登录和 CSRF。

## 能力与调用方式

| 目标 | 当前支持路径 | 边界 |
| --- | --- | --- |
| 专题列表、详情、设置、批量创建、AI 建议、历史恢复 | 内容管理 → 专题列表；`routes/admin-topics.php` 中实际后台路由 | 专题逐条管理尚无独立 CLI 命令或 Topic 管理 API。用已登录后台完成。 |
| 自动专题任务 | 任务管理的新建专题任务；已有 Task API/CLI 的创建、修改、启停、入队和查询 | `content_type=topic`；核对标题库、可用模型、站点与专题配置。任务接口支持专题任务，并不等于支持专题内容 CRUD。 |
| 新主题复刻 | 网站设置 → 网站模板 → 一键复刻模板 | 表单接受首页、分类页和文章页三个参考网址。生成包带专题骨架；专题参考页需在后续设计阶段单独阅读并映射。 |
| 专题模板设计和修改 | 源码主题文件；已授权远程主题工作区的文件变更与签名预览 | 旧后台在线模板编辑器已停用。远程主题工作区当前仅支持主站；远程正式发布、配置和回退按实例 availability 判断。 |
| 模板包移植和复用 | 后台检查、导入、导出主题 ZIP | 导出保留专题声明、嵌套 Blade 和 CSS/JS。导入不复制专题内容，也不自动发布专题。 |
| 托管站和远端 Agent | 专题站点选择器中的可用站点；远端 capability 清单 | Laravel 托管站与独立 PHP Agent 目标包分开核对。独立目标包当前没有专题路由和 `topic_collection`，不可宣称已同步。 |

## 专题任务

使用现有 `task create --json FILE`，或当前实例公开的 Task API；创建字段的真实来源为 `StoreTaskRequest` / `UpdateTaskRequest` 和 `TopicTaskService`。读取资料和模型目录后替换以下示例中的 ID：

```json
{
  "name": "专题生成任务",
  "content_type": "topic",
  "target_site_key": "primary",
  "title_library_id": 1,
  "ai_model_id": 1,
  "topic_limit": 10,
  "publish_interval": 60,
  "status": "paused",
  "topic_settings": {
    "template_key": "guide",
    "target_count": 6,
    "after": "draft_only",
    "rules": "依据本站公开文章组织阅读顺序，摘要保留可核验来源。"
  }
}
```

批次转入任务时，同标题的不同范围保留为独立项，标题库使用带范围说明的显示名称；当前任务按保存的逐行配置生成原专题标题。逐行原标题和范围配置属于该任务。另建任务选择同一标题库时，按库中的显示标题和新任务规则生成；需要保留原批次逐行配置时继续使用或编辑原任务。

生成行为按用户授权选择草稿、送审或自动发布，示例先保存为暂停任务。读取任务详情确认类型、站点、规则和数量，再按授权启用或入队；跟踪任务、队列与实际专题结果。保留请求 ID，未知结果先查回执和业务状态，避免重复生成。任务更新保留当前配置版本字段；任务类型无法通过修改转换。

自动发布模式同时需要实例授予的发布权限（当前为 `articles:publish`）。复刻已有专题内容时，先读取并核对选文和来源，再通过新建专题重新组织内容；当前没有专用的复制按钮或 Topic 复制接口。

## 专题模板契约

公开地址：`/topics`、`/topics/page/{page}`、`/topics/{slug}`。使用 `SiteUrlGenerator::topics()` / `topic()` 生成地址并保留签名预览查询参数。

| 展示对象 | 主题覆盖路径 | 回退或数据契约 |
| --- | --- | --- |
| 专题列表 | `topics/index.blade.php` | `site.topics.index`；`topics` 分页器、`search`、`tag`、`topicTags` |
| 专题详情外壳 | `topics/show.blade.php` | `site.topics.show`；`topic`、`topicArticles`、`topicSummary`、`topicScore` |
| 详情内部布局 | `topics/templates/{id}.blade.php` | 内置 `default` / `guide` / `roundup`；由 `TopicTemplateCatalog` 选择 |
| 首页展示 | `site.partials.topic-home` 或首页编排 `topic_collection` | 使用 `homeTopics`，仅渲染实际公开专题，检查空状态和默认首页条件 |
| 导航 / 文章回链 | `site.partials.topic-navigation` / `site.partials.related-topics` | 服从专题设置、站点范围与公开状态 |
| 主题资源 | `public/themes/{theme_id}/topics.css`、`topics.js` | 查看 `topic-assets` 的资源选择；不可只修改源码 CSS 而漏掉运行资源或绑定版本 |

主题 `manifest.json` 的专题声明示例：

```json
{
  "topic": {
    "contract": 1,
    "layouts": [
      {"id": "default", "name": "标准聚合", "view": "site.topics.templates.default"},
      {"id": "guide", "name": "阅读指南", "view": "site.topics.templates.guide"},
      {"id": "roundup", "name": "资讯盘点", "view": "site.topics.templates.roundup"},
      {"id": "editorial", "name": "编辑精选", "view": "topics/templates/editorial.blade.php"}
    ],
    "homepage_module": {"view": "site.partials.topic-home", "enabled": true}
  }
}
```

`default` 必须存在；布局 ID 唯一。自定义路径属于本主题 `topics/templates/` 下的 Blade 文件，文件须存在。无 `topic` 声明的旧模板使用内置布局；缺少专题覆盖文件可正常回退。包声明与源码发现结果仍需通过系统检查，不能仅凭 JSON 内容宣称可用。

## 复刻、设计和修改步骤

1. 明确目标站点、当前主题及绑定版本，读取主题契约和已提供的专题布局。
2. 阅读用户提供的专题参考页，提取信息层级、列表、摘要、时间、标签、评分、来源、阅读导航与移动端布局。使用 GEOFlow 实际字段；评分不存在时隐藏，不填充示例分数、日期或来源。
3. 远程 Blade 修改需要 `themes:code` 和密码再次认证，秘密通过受保护输入提供。在草稿工作区或隔离源码预览副本修改列表、详情、内部布局和专题资源。准备新主题时补齐 `topic` 声明、页面映射、首页模块和导航。源码 fork helper 会复制嵌套模板和公开专题资源。
4. 远程预览使用 `theme-workspaces.preview` 返回的 `topics-index`、`topics-show`、`topics-empty` 签名链接。没有公开专题时详情返回 `available=false`，先报告真实内容不足。源码 helper 的路径样例需要真实 slug，并不证明独立预览已启用。
5. 验证首页、列表筛选与分页、三种布局或自定义布局、空状态、来源跳转、文章回链、键盘导航和手机布局。保留 canonical、结构化信息、真实发布时间与来源证据。网页可见内容与结构化输出保持一致。
6. 模板检查、导入和导出后核对专题声明、文件清单与内容哈希。旧主题兼容升级保留自定义文件和版本；启用不可变绑定的站点须走系统正式流程，单独覆盖源码不会更新绑定版本。
7. 已获授权的操作直接完成并读取最终状态。超出当前授权或实例能力的操作说明具体限制；不要通过数据库、隐藏路由或伪造 CLI 命令绕过。

相关实现证据：`TopicTemplateCatalog`、`TopicThemeCompatibility`、`ThemeScaffoldWriter`、`SiteThemePackageGuard`、`ThemeWorkspaceService`、`ThemeWorkspacePreview`。测试优先覆盖专题目录、主题包、工作区预览和生成任务。
