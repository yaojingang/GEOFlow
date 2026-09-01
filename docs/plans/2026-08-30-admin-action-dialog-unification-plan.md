# 🥷 GEOFlow 后台操作弹窗统一升级方案（最终确认版）

> 状态：已按确认方案实施，自动化与响应式验收通过
>
> 日期：2026-08-30
>
> 审查范围：后台 120 个 Blade 页面、16 个后台 Blade 组件、30 个后台 JavaScript 模块、后台写操作路由、全局反馈渲染、现有自定义弹窗、六种后台语言
>
> 实施结果：已完成共享中央弹窗、业务迁移、功能型弹窗规范、六语言响应式检查与源码不变量测试；未增加数据库字段、公开 API、环境变量或第三方依赖
>
> 最终验收：Laravel 全量测试 2120 项通过（21065 个断言）；后台 JavaScript 136 项通过；生产构建和 Blade 模板缓存通过；六种语言在 1280px、375px、320px 共 18 组布局检查通过

## 一、最终结论

建议建立一套后台全局操作弹窗系统，并按风险分层处理确认、输入、结果和错误反馈。所有后台操作弹窗在桌面端和移动端都固定显示在可视区域正中间，统一文案结构、视觉规范、焦点管理和提交状态。

本次复核确认了四类需要处理的表面：

1. 34 个业务操作仍通过浏览器原生 `confirm()` 确认。
2. 8 处直接 `alert()`、2 类 `prompt()` 场景仍使用浏览器原生提示，其中任务页会因通知对象名称不一致而持续回退到原生提示。
3. 19 个已有自定义弹窗使用多套宽度、圆角、遮罩、焦点和移动端布局规则。
4. 全局服务器反馈仍在内容区顶部展示，后台控制器当前有 154 处成功消息和 242 处错误消息；系统更新页、URL 导入页还会重复渲染顶部反馈。

推荐方案覆盖这些表面，同时保留高风险业务已有的专用安全门槛，例如渠道删除的两步影响确认、托管站点归档的域名输入确认、系统更新的管理员密码和 6 位授权码。

## 二、本轮 review 新发现与完善项

| 级别 | 新发现 | 证据位置 | 纳入方案的处理 |
| --- | --- | --- | --- |
| 高 | 任务页调用 `window.AdminUtils.showToast`，全局实际暴露的是 `window.GeoFlowAdminUi.showToast`，启动、停止、批量启动等结果会进入 `alert()` 回退 | `resources/views/admin/tasks/index.blade.php:623`、`resources/js/admin/ui-v3-shell.js:420` | 任务操作首批迁移到统一结果卡和错误弹窗，移除失效的对象引用 |
| 中 | API Token 复制成功同样引用不存在的 `window.AdminUtils`，成功时没有反馈，失败时进入原生 `prompt()` | `resources/views/admin/api-tokens/index.blade.php:165` | 成功使用统一结果卡，失败使用带只读输入框和复制按钮的中央输入弹窗 |
| 高 | 系统更新和回滚具备授权码与密码门槛，点击动作前仍缺少清晰的版本、恢复点和影响确认 | `resources/views/admin/system-updates/index.blade.php:344`、`:405` | 授权字段进入专用高风险中央弹窗，确认内容展示目标版本、恢复点、预计影响和恢复路径 |
| 中 | 企业知识发布与企业知识修订恢复会改变正式知识内容，当前直接提交 | `resources/views/admin/enterprise-knowledge/show.blade.php:323`、`:370` | 增加发布确认和修订恢复确认，继续保留自动保存与后端校验 |
| 中 | 管理员停用、线索表单停用、渠道暂停、托管站点维护会影响访问或业务接收，当前直接提交 | 对应页面的状态切换表单 | 按风险矩阵补充警告确认；低风险启用动作只显示操作结果 |
| 中 | 系统更新页和 URL 导入页会在全局布局之外再次渲染相同反馈 | `resources/views/admin/system-updates/index.blade.php:130`、`resources/views/admin/url-import/show.blade.php:62` | 删除页面级重复块，统一由全局反馈入口展示 |
| 中 | UI V3 的 `.gf-modal` 在移动端被改为底部对齐，和“所有弹窗居中”的目标冲突 | `resources/css/app.css:940` | 移动端继续居中，使用安全边距、最大高度和弹窗内部滚动 |
| 中 | 欢迎弹窗等固定定位弹窗缺少完整的 `role="dialog"`、焦点循环和关闭后焦点恢复 | `resources/views/admin/partials/welcome-modal.blade.php:96` 等 | 19 个现有弹窗全部通过统一无障碍验收，功能型弹窗保留各自内容结构 |
| 低 | 远端文章副本删除当前文案只有“确认删除”，缺少目标、范围和后果 | `resources/views/admin/distribution/_jobs-table.blade.php:57` | 文案改为“删除远端文章副本”，展示渠道、文章和本地文章是否保留 |
| 说明 | 2 个 `beforeunload` 离开页面确认受浏览器安全规范约束，网页不能替换其原生外观 | `resources/js/admin/ui-v3-shell.js:387`、`resources/js/admin/article-create-assistant.js:605` | 保留浏览器原生离开确认，并在最终静态扫描中列为唯一允许项 |

## 三、审查边界与统计口径

本次使用源码和后台路由进行交叉核对，排除了 `vendor`、`node_modules`、构建产物、预览页、前台主题和第三方编辑器资源。

| 项目 | 数量 | 说明 |
| --- | ---: | --- |
| 后台 Blade 页面 | 120 | `resources/views/admin/**/*.blade.php` |
| 后台 Blade 组件 | 16 | `resources/views/components/admin/**/*.blade.php` |
| 后台 JavaScript 模块 | 30 | `resources/js/admin/**/*.js` |
| 原生调用代码行 | 41 | `confirm` 30 行、`alert` 8 行、`prompt` 3 行 |
| 原生确认对应业务操作 | 34 | 共享脚本的一次调用会服务多个页面或多个动作 |
| 离开页面原生确认 | 2 | 浏览器安全限制，保留 |
| 已有自定义弹窗 | 19 | 包含 `<dialog>`、固定定位弹层和动态创建弹窗 |
| 控制器成功消息 | 154 | `->with('message', ...)` |
| 控制器错误消息 | 242 | `->withErrors(...)` |
| 后台语言 | 6 | 简体中文、英文、日语、西班牙语、俄语、葡萄牙语（巴西） |

这份清单以“会阻断操作、要求输入、展示操作结果、展示阻断错误”为弹窗范围。普通筛选、常规保存、页面导航和纯信息展示不会额外增加确认步骤。

## 四、现有提示机制位置清单

| 机制 | 当前显示位置 | 当前实现 | 主要问题 | 最终处理 |
| --- | --- | --- | --- | --- |
| 浏览器 `confirm` | 浏览器窗口上方区域，位置由浏览器控制 | 页面内联脚本和 4 个共享模块 | 无法统一样式、标题、图标、排版和引导 | 全部迁移到中央操作弹窗 |
| 浏览器 `alert` | 浏览器窗口上方区域，位置由浏览器控制 | 文章批量校验、图片选择、任务结果等 | 阻断强、信息层级单一、无法提供下一步 | 选择提醒改中央轻提示，阻断错误改中央错误弹窗 |
| 浏览器 `prompt` | 浏览器窗口上方区域，位置由浏览器控制 | AI 工作台重命名、Token 手动复制 | 输入标签、说明、校验和按钮不可控 | 迁移到中央输入弹窗 |
| 全局成功消息 | 页面内容区顶部 | `resources/views/admin/layouts/app.blade.php:83`、`:116` | 操作后容易被滚动位置遮挡，UI V3 和旧布局样式不同 | 改为页面正中的轻量结果卡 |
| 全局错误消息 | 页面内容区顶部 | `resources/views/admin/layouts/app.blade.php:88`、`:121` | 动作错误和表单校验混在一起 | 动作错误进入中央弹窗，字段错误继续紧邻字段，表单顶部保留可访问摘要 |
| UI V3 Toast | 页面底部中央 | `resources/css/app.css:379` | 仅支持一段文字，任务页没有正确调用 | 升级为支持语义类型的中央结果卡 |
| UI V3 `.gf-modal` | 桌面中央、移动端底部 | `resources/css/app.css:326`、`:940` | 移动端位置不一致，和原生 `<dialog>` 的行为不同 | 桌面与移动端统一居中 |
| 页面自定义弹窗 | 大多中央，少量行为不完整 | 19 个分散实现 | 宽度、遮罩、焦点、关闭方式、文案层级不一致 | 操作型弹窗合并，功能型弹窗统一视觉和无障碍规则 |

## 五、34 个原生确认操作最终清单

“优化后内容”采用固定顺序：标题；影响说明；必要引导；主按钮。目标名称、数量、渠道和可恢复性都要进入弹窗正文。

### 5.1 账号、权限与安全

| # | 页面与动作 | 当前位置与文案 | 优化后内容 |
| ---: | --- | --- | --- |
| 1 | 浏览器客户端，断开连接 | `resources/views/admin/account/browser-clients.blade.php:34`，确认断开这个浏览器客户端吗？ | 断开浏览器客户端；该设备将立即失去后台运营连接；需要继续使用时可重新配对；按钮“断开连接” |
| 2 | 管理员，删除账号 | `resources/views/admin/admin-users/index.blade.php:150`，确定要删除管理员“名称”吗？删除后该账号将无法登录。 | 删除管理员“名称”；账号将无法登录，已有审计记录继续保留；确认对象无误后再删除；按钮“删除管理员” |
| 3 | API Token，撤销 | `resources/views/admin/api-tokens/index.blade.php:105`，确认撤销这个 Token 吗？ | 撤销 API Token；依赖该 Token 的接口和自动化会立即停止；撤销后无法恢复；按钮“撤销 Token” |
| 4 | 分发渠道，重置密钥 | `resources/views/admin/distribution/show.blade.php:124`，旧密钥会立即失效，新密钥刷新后隐藏 | 重置“渠道名称”的密钥；旧密钥立即失效，目标站点需要更新配置；新密钥只展示一次；按钮“重置并生成新密钥” |
| 5 | 敏感词，删除 | `resources/views/admin/security-settings/index.blade.php:147`，确定要删除这个敏感词吗？ | 删除敏感词“词语”；后续扫描将不再使用这条规则；历史扫描结果不受影响；按钮“删除敏感词” |

### 5.2 AI 配置与基础素材

| # | 页面与动作 | 当前位置与文案 | 优化后内容 |
| ---: | --- | --- | --- |
| 6 | AI 提示词，删除 | `resources/views/admin/ai-prompts/index.blade.php:111`，确定要删除提示词“名称”吗？此操作不可恢复。 | 删除提示词“名称”；删除后无法恢复；正在引用时由后端继续阻止删除；按钮“删除提示词” |
| 7 | AI 搜索源，删除 | `resources/views/admin/ai-source-providers/index.blade.php:235`、`resources/js/admin/ai-source-providers-index.js:3` | 删除搜索源“名称”；关联配置将停止使用该来源；删除后无法恢复；按钮“删除搜索源” |
| 8 | 作者，删除 | `resources/views/admin/authors/index.blade.php:141`、`resources/js/admin/materials-standalone.js:3` | 删除作者“名称”；有文章引用时无法删除，回收站引用数量单独展示；先迁移引用可降低中断；按钮“删除作者” |
| 9 | 分类，删除 | `resources/views/admin/categories/index.blade.php:91`，确定要删除这个分类吗？ | 删除分类“名称”；仅无文章和任务引用时允许删除；按钮“删除分类” |

### 5.3 文章、审核与回收站

| # | 页面与动作 | 当前位置与文案 | 优化后内容 |
| ---: | --- | --- | --- |
| 10 | 文章编辑，AI 生成覆盖正文 | `resources/js/admin/article-create-assistant.js:535`，继续生成会替换现有正文 | 替换当前正文；AI 生成结果会覆盖编辑器中的现有正文；建议先保存草稿或复制现有内容；按钮“继续生成并替换” |
| 11 | 文章列表，移入回收站 | `resources/views/admin/articles/index.blade.php:906`，确定要删除这篇文章吗？ | 将“文章标题”移入回收站；文章会停止在当前列表展示，可在回收站恢复；按钮“移入回收站” |
| 12 | 文章列表，快捷审核 | `resources/views/admin/articles/index.blade.php:914`，确定要通过或拒绝这篇文章吗？ | 将“文章标题”标记为“通过或拒绝”；说明状态变化对发布流程的影响；按钮使用“标记为通过”或“标记为拒绝” |
| 13 | 回收站，恢复单篇文章 | `resources/views/admin/articles/index.blade.php:645`，确定要恢复这篇文章吗？ | 恢复“文章标题”；文章回到原有状态和列表；按钮“恢复文章” |
| 14 | 回收站，永久删除单篇文章 | `resources/views/admin/articles/index.blade.php:651`，此操作不可恢复 | 永久删除“文章标题”；正文、关联内容和可删除资源无法恢复；按钮“永久删除” |
| 15 | 回收站，清空全部文章 | `resources/views/admin/articles/index.blade.php:868`，永久删除所有已删除文章 | 清空回收站；明确展示文章数量；全部文章会永久删除；建议先导出需要保留的内容；按钮“永久删除 N 篇文章” |
| 16 | 回收站，批量恢复 | `resources/views/admin/articles/index.blade.php:1027`，确定恢复选中的 N 篇文章吗？ | 恢复 N 篇文章；文章回到原有状态；按钮“恢复 N 篇文章” |
| 17 | 回收站，批量永久删除 | `resources/views/admin/articles/index.blade.php:1031`，此操作不可恢复 | 永久删除 N 篇文章；删除后无法恢复；按钮“永久删除 N 篇文章” |
| 18 | 文章列表，批量移入回收站 | `resources/views/admin/articles/index.blade.php:1048`，确定删除选中的 N 篇文章吗？ | 将 N 篇文章移入回收站；后续可以恢复；按钮“移入回收站” |

### 5.4 分发、企业知识与知识库

| # | 页面与动作 | 当前位置与文案 | 优化后内容 |
| ---: | --- | --- | --- |
| 19 | 分发记录，删除远端文章副本 | `resources/views/admin/distribution/_jobs-table.blade.php:57`，确认删除 | 删除远端文章副本；展示文章标题和渠道名称；本地文章继续保留，远端删除结果会回写；按钮“删除远端副本” |
| 20 | 企业知识项目，删除 | `resources/views/admin/enterprise-knowledge/index.blade.php:85`，确定要删除“名称”吗？ | 删除企业知识项目“名称”；草稿、来源、上传文件和修订将永久删除；已经发布的独立知识库继续保留；按钮“删除项目” |
| 21 | 知识库，删除 | `resources/views/admin/knowledge-bases/index.blade.php:247`，确定要删除知识库“名称”吗？ | 删除知识库“名称”；内容、切片和向量数据将永久删除；正在被任务使用时继续阻止删除；按钮“删除知识库” |
| 22 | 系统知识库，采用官方版本 | `resources/views/admin/knowledge-bases/detail.blade.php:108`，现有内容会保留在修订历史 | 采用当前官方版本；展示当前版本和目标版本；现有内容进入修订历史，可以再次恢复；按钮“采用官方版本” |
| 23 | 知识库修订，恢复 | `resources/views/admin/knowledge-bases/detail.blade.php:230`，恢复操作会生成一条新修订 | 恢复此修订；展示修订时间和摘要；当前内容会保留为新修订；按钮“恢复此版本” |

### 5.5 图片库、关键词库与标题库

| # | 页面与动作 | 当前位置与文案 | 优化后内容 |
| ---: | --- | --- | --- |
| 24 | 图片库，删除 | `resources/views/admin/image-libraries/index.blade.php:158`、`resources/js/admin/materials-standalone.js:3` | 删除图片库“名称”；明确展示图片数量；库内文件会一并永久删除；按钮“删除图片库” |
| 25 | 图片库，批量删除图片 | `resources/views/admin/image-libraries/detail.blade.php:309` | 永久删除 N 张图片；已插入文章的图片地址可能失效；确认引用情况后继续；按钮“删除 N 张图片” |
| 26 | 关键词库，删除 | `resources/views/admin/keyword-libraries/index.blade.php:104`、`resources/js/admin/materials-standalone.js:3` | 删除关键词库“名称”；明确展示关键词数量；库内关键词会一并删除；按钮“删除关键词库” |
| 27 | 关键词，删除单项 | `resources/views/admin/keyword-libraries/detail.blade.php:169`、`resources/js/admin/library-detail-actions.js:86` | 删除关键词“内容”；该词将不再参与后续选取；按钮“删除关键词” |
| 28 | 关键词，批量删除 | `resources/views/admin/keyword-libraries/detail.blade.php:146`、`resources/js/admin/library-detail-actions.js:34` | 删除 N 个关键词；展示所在关键词库；按钮“删除 N 个关键词” |
| 29 | 标题库，删除 | `resources/views/admin/title-libraries/index.blade.php:150`、`resources/js/admin/materials-standalone.js:3` | 删除标题库“名称”；明确展示标题数量；任务正在使用时继续阻止删除；按钮“删除标题库” |
| 30 | 标题生成，停止任务 | `resources/views/admin/title-libraries/detail.blade.php:160`、`resources/js/admin/library-detail-actions.js:19` | 停止标题生成；已完成进度继续保留，当前模型请求仍可能产生一次费用；后续可以重试；按钮“停止生成” |
| 31 | 标题，删除单项 | `resources/views/admin/title-libraries/detail.blade.php:219`、`resources/js/admin/library-detail-actions.js:86` | 删除标题“内容”；删除后无法恢复；按钮“删除标题” |

### 5.6 表单与主题任务

| # | 页面与动作 | 当前位置与文案 | 优化后内容 |
| ---: | --- | --- | --- |
| 32 | 线索表单，删除 | `resources/views/admin/lead-forms/index.blade.php:94`，确定删除这个表单吗？ | 删除表单“名称”；公开地址将停止接收提交；存在提交记录时后端继续阻止删除；按钮“删除表单” |
| 33 | 主题复刻，归档任务 | `resources/views/admin/site-theme-replications/show.blade.php:487` | 归档模板复刻任务“名称”；任务从活动列表移出，已生成记录继续保留；按钮“归档任务” |
| 34 | 主题复刻，删除草稿文件 | `resources/views/admin/site-theme-replications/show.blade.php:496` | 删除复刻草稿；数据库版本记录继续保留，本地草稿文件会删除；按钮“删除草稿文件” |

## 六、原生 alert 与 prompt 最终清单

| 场景 | 当前位置 | 当前表现 | 优化方式 |
| --- | --- | --- | --- |
| 文章批量操作未选文章 | `resources/views/admin/articles/index.blade.php:1007` | `alert` | 中央信息提示：“先选择文章”，引导勾选至少一篇文章 |
| 文章批量操作未选动作或路由无效 | `resources/views/admin/articles/index.blade.php:1014`、`:1021` | `alert` | 中央信息提示：“选择要执行的操作”，焦点返回操作下拉框 |
| 文章批量更新未选状态 | `resources/views/admin/articles/index.blade.php:1038` | `alert` | 中央信息提示：“选择目标状态”，焦点返回状态下拉框 |
| 文章批量审核未选结果 | `resources/views/admin/articles/index.blade.php:1044` | `alert` | 中央信息提示：“选择审核结果”，焦点返回审核结果下拉框 |
| 文章批量导出未选文章 | `resources/js/admin/article-batch-export.js:105` | `globalThis.alert` 回退 | 使用统一中央信息提示，导出弹窗继续负责进度和错误详情 |
| 图片批量删除未选图片 | `resources/views/admin/image-libraries/detail.blade.php:304` | `alert` | 中央信息提示：“先选择图片”，关闭后焦点回到图片列表 |
| 企业知识拖拽文件不受支持 | `resources/views/admin/enterprise-knowledge/create.blade.php:203` | `alert` | 中央信息提示，主按钮“选择文件”，直接聚焦文件输入框 |
| 任务启动、停止、批量运行结果 | `resources/views/admin/tasks/index.blade.php:623` | 因失效通知对象回退 `alert` | 成功和普通提醒使用中央结果卡，失败使用可关闭的中央错误弹窗 |
| AI 工作台重命名会话 | `resources/js/admin/ai-workspace.js:977` | `prompt` | 中央输入弹窗，预填当前名称，显示长度约束，主按钮“保存名称” |
| API Token 手动复制 | `resources/views/admin/api-tokens/index.blade.php:169`、`:172` | `prompt` | 中央输入弹窗，Token 使用只读等宽输入框，提供“复制 Token”和“关闭” |

## 七、19 个已有自定义弹窗 review 清单

### 7.1 合并到统一操作弹窗的 9 个表面

| 弹窗 | 当前文件 | 处理 |
| --- | --- | --- |
| 任务启动、停止、批量运行、启停状态 | `resources/views/admin/tasks/index.blade.php` | 使用共享的正向或警告确认类型，保留任务就绪校验 |
| 任务删除 | `resources/views/admin/tasks/index.blade.php`、`resources/js/admin/task-delete-dialog.js` | 使用共享危险确认类型 |
| 任务列表标题库就绪检查 | `resources/views/admin/tasks/index.blade.php`、`resources/js/admin/task-index-readiness.js` | 使用共享阻断或警告类型 |
| 任务创建标题库就绪检查 | `resources/views/admin/tasks/create.blade.php`、`resources/js/admin/task-form.js` | 使用共享阻断或警告类型 |
| AI 模型删除 | `resources/views/admin/ai-models/index.blade.php`、`resources/js/admin/ai-model-delete-dialog.js` | 使用共享危险确认类型 |
| 选中渠道同步设置 | `resources/views/admin/distribution/index.blade.php` | 使用共享影响确认类型，预览逻辑继续保留 |
| 知识库重建切片 | `resources/views/admin/knowledge-bases/index.blade.php` | 使用共享长内容确认类型，继续显示切片、向量和写入影响 |
| 标题生成关键词复用 | `resources/views/admin/title-libraries/ai-generate.blade.php`、`resources/js/admin/title-generation-form.js` | 使用共享警告确认类型 |
| 系统更新错误详情 | `resources/views/admin/system-updates/index.blade.php`、`resources/js/admin/system-updates.js` | 使用共享阻断错误类型，保留复制诊断信息和重试动作 |

### 7.2 保留功能结构并统一视觉与无障碍的 10 个表面

| 弹窗 | 当前文件 | 处理 |
| --- | --- | --- |
| 账号概览 | `resources/views/components/admin/v3/dialogs.blade.php` | 保留内容，统一居中、遮罩、圆角、关闭和焦点规则 |
| 社区二维码 | `resources/views/components/admin/v3/dialogs.blade.php` | 保留宽内容布局，移动端继续居中并内部滚动 |
| 快捷设置 | `resources/views/components/admin/v3/dialogs.blade.php` | 保留快捷入口，统一键盘行为 |
| 欢迎说明 | `resources/views/admin/partials/welcome-modal.blade.php` | 补齐 dialog 语义、焦点循环、Escape、关闭后焦点恢复 |
| 图片库预览 | `resources/views/admin/image-libraries/detail.blade.php` | 保留图片预览能力，统一视觉 token |
| AI 工作台图片预览 | `resources/js/admin/ai-workspace.js` | 保留缩放和快捷键，统一弹窗外观 |
| 文章标题选择器 | `resources/views/admin/articles/form.blade.php` | 保留搜索和选择流程，统一遮罩与焦点 |
| 文章图片选择器 | `resources/views/admin/articles/form.blade.php` | 保留图片插入流程，统一遮罩与焦点 |
| 文章批量导出 | `resources/views/admin/articles/index.blade.php`、`resources/js/admin/article-batch-export.js` | 保留加载、成功和错误状态，统一容器与按钮排版 |
| Embedding 配置引导 | `resources/views/admin/knowledge-bases/index.blade.php` | 保留引导入口，补齐焦点管理和中央布局 |

功能型弹窗会共享视觉与无障碍规则，内容和业务流程继续由各页面维护。操作型弹窗会共享同一个宿主和控制器，减少重复实现。

## 八、review 后新增的高影响操作

这些操作当前没有原生弹窗，风险审查显示需要补充确认或更清晰的结果反馈。

| 操作 | 风险判断 | 最终处理 |
| --- | --- | --- |
| 管理员停用 | 会立即终止账号访问 | 停用前警告确认；重新启用直接执行并显示结果 |
| 线索表单停用 | 公开表单会停止接收提交 | 停用前警告确认；启用直接执行并显示结果 |
| 分发渠道暂停 | 会停止后续分发处理 | 暂停前警告确认；激活前显示目标渠道和健康状态 |
| 托管站点暂停接收 | 会停止接收新文章 | 暂停前警告确认 |
| 托管站点进入维护 | 会禁止索引并改变站点状态 | 进入维护前警告确认 |
| 托管站点激活 | 会重新开放站点工作流 | 显示预检状态和索引状态后确认 |
| 企业知识发布 | 会生成或更新正式知识库 | 展示项目、校验结果和目标知识库后确认 |
| 企业知识修订恢复 | 会改变当前草稿内容 | 展示修订时间、摘要和可恢复性后确认 |
| 系统更新 | 会切换应用版本并触发服务更新 | 在弹窗中展示当前版本、目标版本、备份状态、授权码和管理员密码 |
| 系统回滚 | 会恢复指定恢复点并改变当前运行版本 | 在弹窗中展示恢复点、版本、创建时间、授权码和管理员密码 |

以下操作继续使用已有安全结构：

- 分发渠道最终删除继续使用两步影响审查、影响指纹和名称输入页面。
- 托管站点归档继续要求输入完整域名。
- 托管站点开放索引继续要求勾选内容、版权、合规和预检确认。
- 分发设置同步继续使用预览页和服务器端 `frontend_sync_confirmed` 门槛。
- 系统更新授权码和管理员密码继续由后端强制校验。
- 人工发布状态流转保留现有表单、修订号和审计逻辑，操作完成后接入统一结果反馈。

## 九、统一交互架构

### 9.1 组件与数据流

```text
页面按钮或表单
      |
      v
共享 Action Dialog 控制器
      |----------------------|
      v                      v
中央 <dialog> 宿主       中央 Result Card
确认 / 输入 / 阻断错误    成功 / 普通提醒
      |
      v
原有表单提交或原有 fetch
      |
      v
服务器 session 结果或 JSON 结果
      |
      +----------> 共享控制器统一展示
```

### 9.2 新增共享接口

计划新增一个 Blade 宿主和一个 JavaScript 控制器：

- `resources/views/components/admin/action-dialog.blade.php`
- `resources/js/admin/action-dialog.js`
- `resources/css/admin-action-dialog.css`

控制器提供四个稳定能力：

- `confirm(options)`：返回确认或取消结果。
- `alert(options)`：展示需要用户关闭的阻断信息。
- `prompt(options)`：返回输入值或取消结果。
- `notice(options)`：展示无需焦点锁定的中央结果卡。

静态表单通过 `data-admin-confirm-form` 和文案属性声明确认内容。动态操作通过 Promise API 调用。所有文本使用 Blade 转义或 `textContent` 写入，禁止把业务字符串拼进 `innerHTML`。

### 9.3 表单提交策略

1. 控制器在捕获阶段识别需要确认的表单，保存实际触发按钮。
2. 首次提交被阻止并打开中央弹窗。
3. 用户确认后，通过一次性放行标记和 `requestSubmit(原触发按钮)` 继续原有表单校验与提交。
4. 提交期间设置 `aria-busy`，主按钮显示进行中状态并阻止重复提交。
5. 页面通过浏览器前进后退缓存恢复时，自动清理进行中状态。
6. 危险按钮在共享控制器初始化前保持禁用，初始化完成后启用；JavaScript 失效时不会绕过确认直接删除。

### 9.4 服务器反馈策略

| 反馈 | 展示方式 | 焦点 | 关闭策略 |
| --- | --- | --- | --- |
| 成功 | 页面正中的轻量结果卡 | 不抢焦点，`role="status"` | 默认 4 秒，悬停或聚焦时暂停，可手动关闭 |
| 普通提醒 | 页面正中的轻量结果卡 | 不抢焦点，`role="status"` | 默认 5 秒，可手动关闭 |
| 操作失败 | 页面正中的错误弹窗 | 聚焦标题或关闭按钮，`role="alertdialog"` | 用户关闭，正文提供原因和下一步 |
| 表单校验 | 字段旁错误加表单摘要 | 聚焦第一个无效字段 | 不使用成功结果卡，避免遮挡修正流程 |
| 长任务已提交 | 中央结果卡加“查看进度”入口 | 不抢焦点 | 用户关闭或自动消失 |

现有 `session('message')` 先由全局布局直接映射为中央成功结果卡。动作控制器逐批增加结构化 `admin_action_notice`，字段包括 `tone`、`title`、`message`、`guidance`、`action_label` 和安全站内 `action_url`。表单校验继续使用 Laravel `$errors` 和字段错误。

## 十、UI 与排版统一规范

### 10.1 设计方向

- 视觉主题：延续 GEOFlow Admin UI V3 的克制企业工具风格，以白色实体表面、清晰文字层级和语义色表达风险。
- CSS 策略：共享操作弹窗使用由 `resources/js/app.js` 引入的 `resources/css/admin-action-dialog.css` 语义类，确保 UI V3 和旧布局都加载；现有功能型弹窗 token 继续放在 `resources/css/app.css`；页面按钮继续使用现有 Tailwind 工具类；不引入 CSS Modules、CSS-in-JS 或新依赖。
- 交互特征：按钮按下使用现有 `scale(0.96)`；鼠标触发的弹窗使用短促淡入；键盘触发和减少动态效果模式不播放进入动画。

### 10.2 尺寸与位置

| Token | 规范 |
| --- | --- |
| 位置 | `position: fixed` 或 `<dialog>` top layer，水平和垂直正中 |
| 基础确认宽度 | `min(480px, calc(100vw - 32px))` |
| 长内容宽度 | `min(560px, calc(100vw - 32px))` |
| 功能型宽弹窗 | 最大 `720px`，标题和图片选择器可按原业务保留更宽规格 |
| 最大高度 | `min(720px, calc(100dvh - 32px))`，正文区域内部滚动 |
| 圆角 | 弹窗 16px，内部卡片 10px，按钮 8px |
| 遮罩 | `rgba(15, 23, 42, 0.48)`，不使用默认毛玻璃效果 |
| 阴影 | `0 24px 72px rgba(15, 23, 42, 0.28)` |
| 安全边距 | 桌面 24px，移动端 16px，并考虑 `env(safe-area-inset-*)` |
| 触控目标 | 最小 40 x 40px |

320px、375px 和 1280px 宽度都保持视觉中心。移动端按钮在空间不足时纵向排列，危险主按钮仍放在视觉终点，正文先于按钮滚动。

### 10.3 内容层级

| 区域 | 规范 |
| --- | --- |
| 图标 | 40px 语义图标容器，危险红、警告琥珀、成功绿、信息蓝灰 |
| 标题 | 18px、600 字重、26px 行高，直接描述动作和对象 |
| 对象摘要 | 14px、600 字重，可完整换行，不使用省略号隐藏关键对象 |
| 影响说明 | 14px、22px 行高，最多优先展示三条影响 |
| 引导说明 | 13px、20px 行高，说明恢复、备份、前置步骤或下一步 |
| 按钮 | 最小高度 40px，按钮文字使用明确动词和对象 |

### 10.4 语义色和按钮顺序

| 类型 | 主色 | 初始焦点 | 按钮顺序 |
| --- | --- | --- | --- |
| 永久删除 | 红色 | “取消” | 取消、永久删除 |
| 可恢复删除 | 红色 | “取消” | 取消、移入回收站 |
| 暂停或停用 | 琥珀色 | “取消” | 取消、暂停或停用 |
| 启动或恢复 | 绿色 | 主动作 | 取消、启动或恢复 |
| 普通确认 | 蓝色 | 主动作 | 取消、继续 |
| 输入 | 蓝色 | 输入框 | 取消、保存 |
| 阻断错误 | 红色图标、深色关闭按钮 | 标题或关闭按钮 | 关闭、可选下一步 |

颜色不作为唯一提示，标题、图标、正文和按钮文字同时表达语义。

### 10.5 动效

- 鼠标触发进入：180ms，`opacity`、`translateY(8px)`、`scale(0.98)` 到最终状态。
- 退出：140ms，只使用 `opacity` 和小幅 `translateY(-8px)`。
- 缓动：`cubic-bezier(0.16, 1, 0.3, 1)`。
- `prefers-reduced-motion: reduce`：关闭位移和缩放，只保留即时显隐。
- 键盘触发：不播放进入动画，立即显示并聚焦。
- 禁止使用 `transition: all`，只声明 `opacity` 和 `transform`。

## 十一、文案规范与示例

### 11.1 固定文案结构

1. 标题使用“动作 + 对象”，例如“永久删除 12 篇文章”。
2. 第一段说明直接结果，例如“这些文章及其可删除关联数据将无法恢复”。
3. 第二段说明影响范围，例如“本地文章保留，远端副本会从渠道中移除”。
4. 引导说明可执行的前置步骤，例如“需要保留时先导出 Markdown”。
5. 主按钮使用完整动作，例如“撤销 Token”“停止生成”“恢复此版本”。

### 11.2 禁用表达

- 不使用只有“确定吗”“确认删除”“操作成功”“操作失败”的笼统文案。
- 成功文案不使用感叹号。
- 错误文案不使用“出错了”“Oops”等缺少原因的开头。
- 不把不可恢复、费用、外部失效或发布影响藏在按钮下方的小字中。
- 不把数据库主键当作唯一对象描述，优先显示名称并补充 ID。

### 11.3 五种完整示例

**永久删除**

- 标题：永久删除 12 篇文章
- 正文：这些文章将从回收站中移除，正文和可删除关联数据无法恢复。
- 引导：需要保留时，先返回列表导出 Markdown。
- 按钮：取消 / 永久删除 12 篇文章

**启动任务**

- 标题：启动任务“品牌问答周更”
- 正文：系统会按当前模型、素材和发布策略开始生成文章。
- 引导：标题库当前可用 286 条，预计满足本轮 20 篇生成需求。
- 按钮：取消 / 启动任务

**暂停渠道**

- 标题：暂停渠道“官网资讯站”
- 正文：新的分发任务会停止进入该渠道，已完成的远端内容继续保留。
- 引导：重新激活后可以继续处理后续任务。
- 按钮：取消 / 暂停渠道

**阻断错误**

- 标题：未能删除图片库
- 正文：该图片库仍被 3 篇文章引用。
- 引导：先迁移或移除引用，再返回重试。
- 按钮：查看引用 / 关闭

**重命名输入**

- 标题：重命名会话
- 字段：会话名称
- 说明：最多 80 个字符，名称只用于后台识别。
- 按钮：取消 / 保存名称

## 十二、无障碍与安全规则

### 12.1 键盘与焦点

- 弹窗打开后把焦点放到安全且符合语义的位置。
- Tab 和 Shift + Tab 只能在弹窗内循环。
- Escape 等同取消；提交已经开始时禁止关闭并明确显示进行中状态。
- 点击遮罩取消未提交操作；阻断错误和输入弹窗不会因误点遮罩丢失内容。
- 关闭后把焦点恢复到原按钮；原按钮已被移除时回到最近的可操作容器。
- 页面任意时刻只允许一个操作弹窗占用焦点。

### 12.2 语义与读屏

- 确认和阻断错误使用 `role="alertdialog"`。
- 普通输入和功能型弹窗使用 `role="dialog"`。
- 所有弹窗绑定 `aria-labelledby`，有补充说明时绑定 `aria-describedby`。
- 中央结果卡使用 `role="status"` 和 `aria-live="polite"`。
- 错误结果使用明确文本，不依赖红色表达。

### 12.3 提交与数据安全

- 危险表单在客户端未初始化时保持禁用。
- 服务器端权限、CSRF、状态、引用、影响指纹、授权码和密码校验全部继续生效。
- 共享控制器只负责交互，不复制后端安全规则。
- 所有动态业务文案通过转义文本写入，禁止注入 HTML。
- Token 只在当前已有安全展示区域和手动复制弹窗中出现，不进入通用结果消息、日志或持久存储。
- 异步操作携带原有 CSRF Token，失败后恢复按钮状态并保留重试入口。

## 十三、实施范围与文件清单

预计会触及 40 个以上文件。范围较大，因此按五个可独立合并的阶段实施。每个阶段完成后系统都可正常使用，后续阶段停止也不会破坏已完成部分。

### 13.1 共享基础文件

| 文件 | 动作 | 用途 |
| --- | --- | --- |
| `resources/views/components/admin/action-dialog.blade.php` | 新增 | 全局中央操作弹窗和结果卡宿主 |
| `resources/js/admin/action-dialog.js` | 新增 | confirm、alert、prompt、notice 和表单绑定 |
| `resources/css/admin-action-dialog.css` | 新增 | UI V3 与旧布局共用的操作弹窗、结果卡样式 |
| `resources/js/app.js` | 修改 | 在所有后台布局加载共享控制器 |
| `resources/css/app.css` | 修改 | 统一已有功能型弹窗 token、中央位置、动效和移动端规则 |
| `resources/views/admin/layouts/app.blade.php` | 修改 | UI V3 与旧布局各渲染一次共享宿主，接入 session 反馈 |
| `resources/views/components/admin/v3/dialogs.blade.php` | 修改 | 功能型弹窗使用统一视觉和无障碍规则 |
| `resources/views/admin/partials/welcome-modal.blade.php` | 修改 | 补齐 dialog 语义、焦点和关闭行为 |
| 六个 `lang/*/admin.php` | 修改 | 新增统一弹窗公共文案和各业务优化文案 |

### 13.2 业务页面和脚本

| 模块 | 主要文件 |
| --- | --- |
| 任务与模型 | `resources/views/admin/tasks/index.blade.php`、`tasks/create.blade.php`、`ai-models/index.blade.php`、`resources/js/admin/task-*.js`、`ai-model-delete-dialog.js` |
| 文章与编辑器 | `resources/views/admin/articles/index.blade.php`、`articles/form.blade.php`、`resources/js/admin/article-create-assistant.js`、`article-batch-export.js` |
| 素材库 | 作者、图片库、关键词库、标题库页面，以及 `materials-standalone.js`、`library-detail-actions.js`、`title-generation-form.js` |
| 知识库 | `resources/views/admin/knowledge-bases/index.blade.php`、`knowledge-bases/detail.blade.php`、企业知识三个页面 |
| 权限与安全 | 浏览器客户端、管理员、API Token、AI 提示词、AI 搜索源、分类、线索表单、敏感词页面 |
| 分发与主题 | 渠道详情、远端任务表、渠道列表、托管站点详情、主题复刻详情 |
| 系统更新 | `resources/views/admin/system-updates/index.blade.php`、`resources/js/admin/system-updates.js` |
| AI 工作台 | `resources/js/admin/ai-workspace.js` |
| 重复顶部反馈 | `resources/views/admin/system-updates/index.blade.php`、`resources/views/admin/url-import/show.blade.php` |

### 13.3 测试文件

| 文件 | 动作 |
| --- | --- |
| `tests/JavaScript/admin-action-dialog.test.js` | 新增共享控制器单元测试 |
| `tests/Feature/AdminActionDialogTest.php` | 新增宿主、语言和源码不变量测试 |
| `tests/JavaScript/materials-standalone.test.js` | 更新异步确认契约 |
| `tests/JavaScript/library-detail-actions.test.js` | 更新统一确认调用 |
| `tests/JavaScript/task-delete-dialog.test.js` 等现有弹窗测试 | 迁移或合并到共享控制器测试 |
| `tests/Feature/AdminAuthorImageLibraryStandalonePagesTest.php` | 把 `window.confirm` 正向断言改为统一弹窗断言 |
| 任务、模型、文章、系统更新、知识库、分发相关 Feature 测试 | 增加对应页面的文案和绑定断言 |

## 十四、分阶段实施计划

### 阶段 1：共享基础与任务、AI 模型试点

目标：先覆盖用户最关心的启动、停止、删除和结果提示。

- 新增共享 Blade 宿主、JavaScript 控制器和统一样式。
- UI V3 与旧布局都接入中央弹窗和结果卡。
- 修复 `AdminUtils` 与 `GeoFlowAdminUi` 名称不一致。
- 迁移任务启动、停止、批量运行、状态切换、删除、就绪检查。
- 迁移 AI 模型删除。
- 增加共享控制器单元测试和布局 Feature 测试。

阶段验收：任务页不再出现浏览器 `alert`；任务和模型弹窗在 320px、375px、1280px 都居中；取消、确认、错误、重复点击和焦点恢复通过测试。

### 阶段 2：文章、回收站与编辑器

- 迁移文章单篇和批量删除、恢复、永久删除、清空回收站、快捷审核。
- 迁移 AI 生成覆盖正文确认。
- 迁移文章选择和批量参数缺失提醒。
- 文章批量导出继续保留进度状态，容器和错误反馈统一。
- 文章标题与图片选择器统一视觉 token 和焦点行为。

阶段验收：文章模块没有 `confirm` 或 `alert`；可恢复和不可恢复动作使用不同文案与按钮；动态创建表单保持原 CSRF 和目标路由。

### 阶段 3：素材库、知识库与企业知识

- 迁移作者、图片库、关键词库、标题库、知识库的删除和批量删除。
- 迁移标题生成停止、知识库采用官方版本和修订恢复。
- 迁移企业知识删除、发布、修订恢复和拖拽文件提醒。
- 合并知识库切片重建、关键词复用和 Embedding 引导弹窗。

阶段验收：共享模块不再以同步 `window.confirm` 作为依赖；批量数量、引用影响、可恢复性和费用提示正确；六种语言无按钮溢出。

### 阶段 4：账号、安全、分发、主题与系统更新

- 迁移浏览器客户端、管理员、API Token、提示词、搜索源、分类、表单和敏感词操作。
- 迁移渠道暂停或激活、密钥重置、远端副本删除、选中渠道同步。
- 补充托管站点生命周期确认，保留域名输入归档和索引复选确认。
- 迁移主题任务归档与草稿删除。
- 系统更新和回滚使用带授权字段的高风险弹窗，错误详情统一。
- 移除系统更新页和 URL 导入页的重复顶部反馈。

阶段验收：渠道两步删除安全流程完全保留；系统更新授权门槛完全保留；Token 不进入通用反馈；分发和系统更新失败提供明确恢复路径。

### 阶段 5：功能型弹窗统一与全后台扫尾

- 统一账号、二维码、快捷设置、欢迎、图片预览、AI 图片预览、文章选择器等功能型弹窗。
- 统一移动端中央位置、遮罩、圆角、标题排版、按钮区域和内部滚动。
- 对六种语言执行响应式截图验收。
- 执行源码不变量扫描，清除遗漏的原生调用。
- 更新内置后台帮助截图或文档中已经过时的弹窗画面。

阶段验收：19 个现有自定义弹窗全部满足中央位置和无障碍基线；后台业务源码只保留两个 `beforeunload` 原生确认。

## 十五、测试与验收清单

### 15.1 自动测试

- `confirm` 的确认、取消、Escape、遮罩和关闭后焦点恢复。
- `prompt` 的预填、必填、长度校验、取消和返回值。
- `notice` 的自动关闭、暂停计时、手动关闭和队列覆盖。
- 同一时间只显示一个操作弹窗。
- 表单确认后保留原提交按钮、字段、CSRF、method 和 action。
- 动态操作的 Promise 结果正确驱动原有 fetch 或表单。
- 重复点击只产生一次提交。
- `pageshow` 后提交按钮恢复。
- 所有动态文案以文本方式渲染，恶意 HTML 不会执行。
- 六种语言公共键和业务键保持一致。
- UI V3 和旧布局都只渲染一个共享宿主。
- 后台业务源码没有 `window.confirm`、`window.alert`、`window.prompt` 和无前缀的对应调用；两个 `beforeunload` 在白名单中。

### 15.2 建议验证命令

```bash
node --test tests/JavaScript/admin-action-dialog.test.js
node --test tests/JavaScript/*.test.js
php artisan test --compact tests/Feature/AdminActionDialogTest.php
php artisan test --compact tests/Feature/AdminTasksPageTest.php
php artisan test --compact tests/Feature/AdminArticlesPageTest.php
php artisan test --compact tests/Feature/AdminAuthorImageLibraryStandalonePagesTest.php
php artisan test --compact tests/Feature/AdminKeywordTitleStandalonePagesTest.php
php artisan test --compact tests/Feature/AdminSystemUpdaterBridgeTest.php
npm run build
```

修改 PHP 文件时只格式化本次拥有的目标文件。当前工作区包含用户未提交的 PHP 变更，实施时禁止使用会批量改写其他脏文件的格式化命令。

### 15.3 视觉验收矩阵

| 维度 | 验收值 |
| --- | --- |
| 语言 | `zh_CN`、`en`、`ja`、`es`、`ru`、`pt_BR` |
| 桌面宽度 | 1280px |
| 移动宽度 | 375px |
| 极窄压力测试 | 320px，重点检查俄语、葡萄牙语和长数量按钮 |
| 动效 | 默认、`prefers-reduced-motion: reduce`、键盘触发 |
| 输入方式 | 鼠标、触控、键盘 |
| 状态 | 默认、加载、成功、警告、错误、长文案、长对象名、大批量数量 |

固定宽度视觉检查使用隔离测试浏览器，不改变用户当前浏览器窗口尺寸。

## 十六、验收标准

实施完成后必须同时满足以下条件：

- 34 个现有原生确认业务操作全部使用共享中央弹窗。
- 8 处直接 `alert` 和 2 类 `prompt` 场景全部迁移。
- 任务启动、停止和批量执行结果不会进入浏览器原生提示。
- review 新增的 10 类高影响操作按风险矩阵完成确认或结果反馈。
- 19 个已有自定义弹窗在桌面和移动端都位于可视区域正中间。
- 操作型弹窗共享同一个宿主与控制器，功能型弹窗共享视觉和无障碍规范。
- 服务器成功消息使用中央结果卡，操作错误使用中央错误弹窗，字段校验继续紧邻字段。
- 系统更新页和 URL 导入页不再重复显示顶部反馈。
- 危险操作都明确展示对象、影响、可恢复性和主按钮动作。
- 六种语言文案键完整，长文案无截断、按钮无溢出。
- 后台业务源码扫描只允许两个 `beforeunload` 原生确认。
- 渠道删除、托管站点归档、开放索引、系统更新授权等后端安全门槛保持原样。
- 不新增第三方依赖、数据库迁移、公开 API、环境变量或后台设置项。

## 十七、风险、回滚与工作区保护

| 风险 | 控制措施 |
| --- | --- |
| 异步确认改变原同步流程 | 使用 Promise API 和一次性表单放行标记，逐模块迁移并保留原后端接口 |
| 动态表单重复提交 | 保存原 submitter，使用 `aria-busy` 和单次提交锁 |
| 焦点被多个弹窗争用 | 全局只允许一个活动操作弹窗，功能型弹窗打开前关闭已有弹窗 |
| 长文案在移动端溢出 | 弹窗正文内部滚动，按钮允许纵向排列，六语言视觉矩阵验收 |
| 服务器错误被统一后丢失细节 | 错误弹窗展示后端安全消息和下一步，开发诊断信息继续留在日志 |
| JavaScript 未加载时危险操作绕过确认 | 危险按钮初始化前禁用，后端安全校验继续保留 |
| 大范围修改和当前工作区变更冲突 | 每阶段开始前重新读取工作区状态，只修改阶段拥有的文件，发现同一代码块变化时先停下复核 |

每个阶段都可以单独回滚对应提交。方案不包含数据库迁移和接口契约变化，回滚只涉及前端组件、页面绑定、文案和结果展示。渠道删除、系统更新和托管站点的服务器安全状态不会因前端回滚而改变。

当前工作区已有未提交变更，且以下目标文件存在重叠：`resources/css/app.css`、`resources/views/admin/articles/form.blade.php`、`resources/views/admin/system-updates/index.blade.php`、`resources/views/admin/tasks/index.blade.php`、`lang/en/admin.php`、`lang/pt_BR/admin.php`、`lang/zh_CN/admin.php` 及若干相关测试。当前分支还落后上游 42 个提交。实施开始前必须重新 review 工作区和分支状态，保留用户已有改动；不会自行拉取、切换分支或清理文件。

## 十八、备选方案与取舍

### 推荐：共享宿主、统一控制器、分批迁移

优点：交互一致、文案可控、无障碍规则集中、可以测试、可以逐阶段落地。现有表单、路由和后端校验继续复用。

### 未采用：覆盖 `window.confirm`、`window.alert` 和 `window.prompt`

这些浏览器 API 是同步接口，共享弹窗需要异步等待用户操作。全局猴子补丁会破坏返回值和调用顺序，也难以保留表单触发按钮、焦点和加载状态。

### 未采用：每个页面单独新增弹窗

当前 19 个自定义弹窗已经证明分散实现会产生宽度、遮罩、焦点和移动端行为差异。继续逐页复制会增加测试和维护成本。

## 十九、前提、非目标与确认项

### 计划前提

本方案假设 Admin UI V3 是当前主要运行界面，同时旧布局仍受配置默认值支持。实施会让共享弹窗同时覆盖两套布局。以后正式移除旧布局时，可以删除旧布局的渲染分支和对应测试，业务弹窗 API 无需改变。

### 本次非目标

- 不替换浏览器关闭或刷新页面时的 `beforeunload` 原生确认。
- 不改变登录页、网站前台、浏览器扩展、API 和 CLI 的交互。
- 不重做文章选择器、图片预览、社区二维码等功能型弹窗的业务流程。
- 不改变渠道删除、托管站点归档、开放索引和系统更新的后端安全规则。
- 不新增数据库字段、环境变量、后台设置、第三方包或外部服务。
- 不在用户确认方案前实施任何业务代码修改。

### 待用户确认

请确认以下方向：

1. 采用共享中央弹窗和中央结果卡。
2. 五个阶段按顺序实施，每个阶段完成后单独 review 和验收。
3. 将 review 新发现的高影响操作一起纳入。
4. 保留两类浏览器原生离开页面确认和现有专用安全流程。

确认后从“阶段 1：共享基础与任务、AI 模型试点”开始实施。
