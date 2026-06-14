# GEOFlow 实现状态

本文档记录当前定制功能的完成状态。每次开发后都应更新。

## 已完成

| 功能 | 状态 | 说明 |
| --- | --- | --- |
| Collection 顶层容器 | 已完成 | 创建任务页必选，素材按 Collection 归属治理 |
| Entity DB | 已完成核心功能 | 支持基础字段、属性 JSON、标签、AI 分析、素材关联 |
| Case DB | 已完成核心功能 | 支持案例管理、受控 Case 类型并关联核心 Entity |
| 知识库治理字段 | 已完成 | type、role、importance、summary、source URL、status |
| 知识库 Entity 独立关系 | 已完成 | 知识库可关联多个 Entity，并为每个 Entity 单独设置关系 |
| 关系多选组件复用 | 已完成 | Entity 页关联知识库与知识库页关联 Entity 复用 `relation-multi-selector` |
| RAG 检索 | 已完成核心功能 | 支持 Entity、Case、Knowledge Base、Collection 上下文 |
| RAG 检索解释增强 | 已完成 | trace 记录 evidence_score、retrieval_source、match_reasons、score_components 与 evidence_summary |
| 生成追踪 | 已完成核心功能 | 文章可记录生成来源、检索 chunk、上下文 |
| 文章质量评分 | 已完成核心功能 | 列表和编辑页显示评分与审核建议 |
| 文章 Markdown 编辑器 | 已完成 | 文章创建/编辑页接入 Vditor，支持快捷插入和 Markdown 正文编辑 |
| 文章复制导出与图片上传 | 已完成 | 支持复制 Markdown、复制公众号/微信格式 HTML，编辑器上传图片自动入库并关联文章 |
| 标签管理页增强 | 已完成核心功能 | 标签列表、引用统计、引用明细、删除、重命名、分页 |
| 受控标签分组白名单 | 已完成核心功能 | 支持新增、编辑、删除可用分组 |
| 不同素材自动 tag 推荐 | 已移除 | 按用户要求删除自动推荐入口，避免误标签和无效标签扩散 |
| 创建任务页重整 | 已完成核心功能 | Collection 必选，Entity / Case 多选，标签筛选折叠 |
| 创建任务页 Collection 联动 | 已完成 | 选择 Collection 后，Entity / Case 仅允许选择同 Collection 内容；跨 Collection 需显式开启 |
| 图片配置优化 | 已完成核心功能 | 区分不指定图库和不配图，支持 Entity 关联图库思路 |
| URL 采集标题关键词关联 | 已完成 | 标题库关联关键词库，单条标题可编辑关联关键词 |
| URL 采集 Case 类型归一 | 已完成 | URL 采集生成 Case 时归一到受控 Case 类型 |
| URL 采集 AI 模型选择 | 已完成 | 创建采集任务时可指定 AI 分析模型；默认仍自动选择并保留 failover |
| AI 模型正文输出上限 | 已完成 | 聊天模型可配置 `max_tokens`，Worker 正文生成按 provider 传入最大输出 token |
| AI 素材分析公共规则 | 已完成 | Knowledge / Entity / Case 分析统一复用语言一致、事实约束、表格保真规则 |
| AI 分析补充要求 | 已完成 | Knowledge、Entity、Case 表单支持折叠式“补充分析要求”与快捷模板 |
| URL 采集 Entity 语言修复 | 已完成 | 英文等非中文页面不再套中文 Entity 描述模板 |
| 文章生成语言强制 | 已完成 | 生成语言优先由标题和关键词判断，最终提示词强制目标语言 |
| Prompt Skill System v1 | 已完成 | 任务支持可选 `skill_prompt_id`，提示词页可管理 Master Prompt 与 Skill Prompt |
| 轻量 CRM 阶段 1 | 已完成 | 新增客户、内部负责人、跟进记录，客户可关联 Collection |
| 轻量 CRM 阶段 2 | 已完成 | 新增询盘管理，支持 AI 需求识别并关联 Entity、知识库、Case、Tag |
| 轻量 CRM 阶段 3 | 已完成 | 新增报价单、报价明细和打印页，可从询盘生成报价 |
| 轻量 CRM 阶段 4 | 已完成 | 新增订单管理，支持从报价生成订单并维护订单明细 |
| 轻量 CRM 阶段 5 | 已完成 | 新增售后工单，支持 AI 分析、关联订单、Entity、知识库和 Case |
| 轻量 CRM 阶段 6 | 已完成 | 新增 CRM 内容候选与任务 CRM 来源关联，支持从询盘/工单沉淀标题、FAQ、Case |
| 轻量 CRM 阶段 7 | 已完成核心功能 | CRM 列表筛选、Collection 约束、任务页 CRM 来源筛选和主要 UI 入口已补齐 |
| CRM 活动与待办 | 已完成核心功能 | 询盘活动按询盘隔离，客户可汇总活动；未来动作使用独立 CRM 待办；活动记录使用轻量 Markdown 编辑器 |
| CRM 工作台与商机管道 | 已完成核心功能 | 支持今日/逾期待办、商机阶段、金额、概率、成交日期和输单原因 |
| CRM 多联系人 | 已完成核心功能 | 客户可维护多个外部联系人并指定主联系人，兼容旧单据带入字段 |
| CRM 数据归档安全 | 已完成核心功能 | 客户及商业对象使用软删除；归档客户不会级联删除商业记录 |
| CRM 单据独立转换 | 已完成核心功能 | 支持从已有单据创建独立报价 / PI / CI / PL / 合同副本并保留来源 |
| CRM 单据系统阶段 1 | 已完成 | 报价/发票/装箱/合同基础字段结构、正式发票类型、最终合计和明细扩展字段已补齐 |
| CRM 单据系统阶段 2-10 | 已完成核心功能 | 单据表单 UI、项目图片、报价/PI/发票/装箱单/合同分模板打印、卖方信息读取、基础中英文标签、测试与文档已补齐 |
| CRM 卖方常用信息模板 | 已完成核心功能 | 单据卖方信息支持 Seller Company / Bank Account JSON 编辑、格式化、保存常用、导入常用和默认模板 |
| CRM 询盘与商机边界 | 已完成核心功能 | 询盘状态收敛，一键转商机，商机显示来源询盘上下文，单据支持关联商机 |
| 关键词和图片库级标签移除 | 已完成 | 只保留标题库库级标签 |
| 素材库删除后保留筛选位置 | 已完成 | 关键词库、标题库、图片库、知识库删除后保留 query 并回到列表区域 |
| 模板工厂 / 站点模板复刻 | 已完成核心功能 | 可从首页、列表页、文章页 3 个参考 URL 创建模板复刻任务，支持预览、迭代、发布、打包和归档 |
| 分发最近日志分页 | 已完成 | 分发首页最近日志支持分页、页码跳转和上一页/下一页 |
| 首次部署登录提示 | 已完成 | 默认管理员首次成功登录前显示初始账号提示，可通过环境变量关闭 |
| 功能说明文档 | 已完成 | 已创建 `功能说明文档/` |
| Agent 交接文档 | 已完成 | 已创建 `agent-docs/` |

## 部分完成，建议继续检查

| 功能 | 状态 | 待检查点 |
| --- | --- | --- |
| 阶段 7 性能优化 | 部分完成 | 需要用大量标签、图片、知识库、文章做真实压力验证 |
| 标签远程搜索 | 部分完成或需确认 | 检查所有标签选择器是否都避免一次性渲染大量数据 |
| 标签引用明细懒加载 | 部分完成或需确认 | 检查标签管理页查看明细是否按需请求 |
| 统计缓存 | 部分完成或需确认 | 检查素材统计和标签统计是否频繁重复计算 |
| 知识库向量化异步队列 | 部分完成或需确认 | 大文件导入时是否阻塞页面操作 |
| 旧文章生成来源展示 | 兼容性风险 | 旧文章没有 trace 时可能不显示生成来源 |

## 未完成

| 功能 | 状态 | 原因 |
| --- | --- | --- |
| 任务回收站 | 未实现 | 会改变任务删除语义，建议独立阶段做 |
| AI 知识库纠错助手 | 未实现 | 涉及新表、diff UI、审批、embedding、回滚，建议独立阶段做 |
| Collection 健康度评分 | 未实现 | 可作为后续治理增强 |
| 重复素材合并 | 未实现 | 需要更明确的数据合并策略 |
| 系统更新中心准备度增强 | 跳过 | 本地定制分支没有上游更新中心基底，强行接入会引入不完整入口 |

## 最近验证记录

最近已通过的核心测试包括：

- `AdminMaterialsPagesTest`
- `AdminTasksPageTest`
- `AdminAiPromptsPageTest`
- `WorkerExecutionServicePromptTest`
- `RagRetrievalServiceTest`
- `UrlImportProcessingServiceTest`
- `WorkerGenerationPipelineTraceTest`

最近补充验证：

- `EntityExtractionServiceTest`
- `MaterialAnalysisPromptRulesTest`
- URL 采集指定 AI 模型聚焦测试
- 知识库 AI 分析聚焦测试
- `AdminAiModelsPageTest` max token 配置聚焦测试
- `WorkerExecutionServiceMaxTokensTest`
- `AdminCrmPagesTest`
- `AdminCollectionsPageTest` CRM 后回归验证
- `AdminCrmPagesTest` CRM 阶段 4-7 扩展验证
- `AdminTasksPageTest` CRM 来源关联回归验证
- `AdminCrmPagesTest` CRM 单据系统阶段 2-10 扩展验证，覆盖图片字段、PI、装箱单、合同打印页
- `AdminCrmPagesTest` CRM 询盘转商机与单据关联商机回归验证
- Headless Chrome 新建单据页和多类型打印页截图检查
- 浏览器检查询盘列表、询盘详情、商机创建页和单据创建页，无横向溢出；商机来源卡片和关联商机下拉正常渲染
- `AdminArticlesPageTest`、`AdminLoginPageTest`、`AdminSiteSettingsPageTest`、`AdminSiteThemeReplicationTest`、`AdminDistributionPageTest` 联合回归通过，共 104 tests / 743 assertions

## 2026-06-12 上游功能融入

已完成：

- 文章创建/编辑页接入 Markdown 编辑器，支持快捷插入、复制 Markdown、复制公众号/微信格式 HTML。
- 新增编辑器图片上传控制器，上传图片会入库并在有文章 ID 时关联文章。
- 新增模板工厂 / 站点模板复刻流程，支持从 3 个参考页面分析、生成草稿、预览、迭代、发布、打包和归档。
- 新增站点模板复刻相关数据表：任务、版本、日志。
- 分发首页最近日志增加分页、页码跳转和上一页/下一页。
- 后台登录页增加首次部署默认账号提示，可通过环境变量关闭。
- 更新 README、中文/英文 changelog、agent 交接文档和功能说明文档。

边界：

- 上游 System Update Center 准备度补丁未接入；本地定制分支没有对应更新中心基底。
- 当前浏览器可访问后台入口为 `/admin`，`/geo_admin` 在本地 HTTP 请求中返回 404；CLI 路由列表可能仍显示 `geo_admin`，后续如处理入口路径需先核查配置缓存、环境变量和容器挂载。

验证：

- 已在 Docker 中执行站点模板复刻迁移。
- PHP / Blade / 语言文件语法检查通过。
- `AdminArticlesPageTest`、`AdminLoginPageTest`、`AdminSiteSettingsPageTest`、`AdminSiteThemeReplicationTest`、`AdminDistributionPageTest` 联合回归通过，共 104 tests / 743 assertions。
- 浏览器烟测确认 `/admin/site-settings` 可打开，模板工厂入口存在，链接指向 `/admin/site-settings/theme-replications/create`。

## 2026-06-05 轻量 CRM 阶段 1-3

已完成：

- 新增 CRM 菜单入口。
- 新增 `crm_customers`、`crm_follow_ups`、`crm_inquiries`、`crm_quotes`、`crm_quote_items` 及询盘关联中间表；旧 `crm_contacts/contact_id` 逻辑已删除。
- 客户管理支持列表、创建、编辑、详情、内部负责人和跟进记录。
- 询盘管理支持 Collection、客户、内部负责人、Entity、知识库、Case、Tag 关联。
- 询盘 AI 需求识别支持选择模型；无模型或调用失败时会使用本地关键词匹配降级。
- 报价管理支持从询盘创建报价、维护报价明细、自动计算金额和打开打印页。

边界：

- 该阶段不实现订单、售后工单、PDF 导出、报价审批和邮件发送。
- CRM 只做销售辅助，不承担完整 ERP 的财务、库存、采购或生产管理职责。

验证：

- PHP lint 通过。
- `AdminCrmPagesTest` 通过。
- `AdminCollectionsPageTest` 通过，确认现有 Collection/素材入口未被破坏。

## 2026-06-05 轻量 CRM 阶段 4-7

已完成：

- 新增订单管理：
  - 报价详情页可生成订单。
  - 订单保留客户、询盘、报价、Collection 和 Entity 线索。
  - 订单明细可维护产品、数量、单价和金额。
- 新增售后工单：
  - 工单可关联客户、订单、Collection、核心 Entity、知识库和 Case。
  - 支持工单内容 AI 分析，提取问题摘要、建议回复、缺失问题和关联资料建议。
  - AI 只推荐已有资料，不直接写入知识库或案例库。
- 新增 CRM 内容候选：
  - 询盘详情页可生成标题候选和 FAQ 候选。
  - 工单详情页可生成 FAQ 候选和 Case 候选。
  - 候选进入独立审核列表，管理员确认后才写入标题库、知识库或 Case DB。
- 新增任务 CRM 来源关联：
  - 创建任务页可选择客户、询盘或工单作为 CRM 来源。
  - 选择 Collection 后，CRM 来源列表默认限制为同 Collection；跨 Collection 需显式开启。

边界：

- CRM 仍定位为轻量销售/内容辅助系统，不实现完整 ERP 的库存、采购、生产排程、财务结算。
- 单据审批、自动 PDF 导出、邮件发送和包含状态/单据/订单事件的统一活动流仍建议作为后续独立优化；跟进记录多端时间线已经完成。

验证：

- 本地 Docker 已执行迁移 `2026_06_05_040000_create_crm_order_ticket_and_content_tables`。
- PHP / Blade 语法检查通过。
- `AdminCrmPagesTest` 通过，覆盖订单、售后工单、内容候选审核和 CRM 来源入库。
- `AdminCrmPagesTest` + `AdminTasksPageTest` 联合回归通过。
- 已完成后台订单、工单、工单详情、内容候选、创建任务和新增工单页面截图检查；未发现横向溢出或明显 UI 冲突。

## 2026-06-05 AI 模型正文输出上限配置

已完成：

- 新增 `ai_models.max_tokens` 迁移并在本地 Docker 数据库执行。
- AI 模型配置页新增“正文最大输出 Token”，仅聊天模型显示和保存。
- 模型列表展示模型级输出上限或系统默认 `GEOFLOW_CONTENT_MAX_TOKENS`。
- Worker 正文生成使用 `MarkdownContentWriterAgent(maxTokens: ...)`，按 provider 输出对应字段：
  - OpenAI: `max_output_tokens`
  - Gemini: `maxOutputTokens`
  - DeepSeek / OpenRouter / OpenAI-compatible: `max_tokens`
- 正文疑似截断时写 warning 日志，帮助排查生成中断。

边界：

- 该配置只影响文章正文生成。
- URL 智能采集、知识库语义切片和素材 AI 分析继续使用原分析链路，不自动套用正文 token 上限。

验证：

- PHP lint 通过。
- `AdminAiModelsPageTest` 与 `WorkerExecutionServiceMaxTokensTest` 通过。

## 2026-06-05 Prompt Skill System v1

已完成：

- 新增 `tasks.skill_prompt_id` 迁移并在本地 Docker 数据库执行。
- 原“正文提示词配置”页升级为“文章提示词配置”，同时管理 `content` Master Prompt 和 `skill` Skill Prompt。
- 创建任务页内容配置区新增可选 Skill Prompt 下拉框。
- Worker 生成文章时会组合 Master Prompt 与 Skill Prompt，并继续保留最终语言指令。
- 生成 trace 记录 Skill Prompt 使用情况。
- API catalog 新增 `skill_prompts`。

继续优化建议：

- 后续如要做自动匹配 Skill，应新增明确的 intent 字段或分类器，并保留任务页人工覆盖入口。
- 不建议把 Skill Prompt 当作素材分类或知识库 metadata 使用。

## 2026-06-04 上游更新吸收记录

已采纳：

- OpenAI-compatible embedding 直连 `/embeddings`，避免 Doubao 等接口因 `dimensions` 参数报错。
- AI 模型页补充 Doubao Embedding、MiniMax M3、MiniMax M2.7 预设。
- 文章批量操作、清空回收站、发布弹窗改为相对 URL。
- 现有 RAG 流程局部吸收上游“证据评分 / 召回解释”思路，不整体替换自定义 RAG。

已跳过：

- Generic HTTP 发布器整套合并。
- 上游 RAG 服务整体替换。
- System Update Center。
- Apple Support Clone / 前台主题更新。
- 上游 docs 大量文档更新。

后续 agent 修改数据库、RAG、生成流程或任务页时，应继续运行相关测试。

## 2026-06-05 AI 分析与 URL 采集增强

已完成：

- 新增 `MaterialAnalysisPromptRules`，集中管理语言一致性、事实不可编造、表格/规格参数保真和 JSON 输出规则。
- `MaterialFormAnalysisService` 的 Knowledge / Entity / Case 分析统一使用公共规则。
- Knowledge、Entity、Case 表单新增“补充分析要求”折叠区，支持快捷模板；该内容只作为附加要求，不覆盖系统规则。
- URL 智能采集的知识库提示词复用公共事实与表格规则。
- URL 智能采集生成 Entity 时，非中文页面不再使用中文描述模板。
- URL 智能采集创建任务页新增 AI 分析模型选择；不选时仍按优先级自动选择，指定时优先尝试所选模型并保留 failover。

相关文件：

- `app/Support/GeoFlow/MaterialAnalysisPromptRules.php`
- `app/Services/GeoFlow/MaterialFormAnalysisService.php`
- `app/Services/GeoFlow/UrlImportProcessingService.php`
- `app/Services/GeoFlow/EntityExtractionService.php`
- `resources/views/admin/partials/material-ai-analysis-instructions.blade.php`
- `resources/views/admin/url-import/index.blade.php`

后续注意：

- 自定义提示词不要设计成“完全替换系统提示词”，应继续保持“补充分析要求”模式。
- 如果新增新的素材 AI 分析入口，应复用 `MaterialAnalysisPromptRules`。

## 2026-06-06 CRM 单据与客户优化

已完成（6 项用户需求）：

- **1. "报价" 导航重命名 → "单据制作"**  
  导航 tab、列表页标题、统计卡片、表格表头、控制器 pageTitle、flash message 全部更新。

- **2. 单据类型联动隐藏/显示物流字段**  
  item-row 的件数、净重、毛重、体积 CBM、HS Code 5 个字段增加了 `data-logistics-field` 属性；表单页 JS 监听 `document_type` 下拉框，当选择「报价单」或「形式发票」时自动隐藏物流字段，选择「正式发票」「装箱单」「合同」时恢复显示，明细区有蓝色提示文字说明当前状态。

- **3. CRM 全模块删除功能**  
  客户、询盘、单据、订单、售后工单的列表页和详情页均新增删除按钮，使用 POST form 提交到已有的 delete 路由，带 JavaScript confirm 确认弹窗。客户删除有强提示（会级联删除关联的询盘/报价/订单/工单）。

- **4. 客户邮箱字段**  
  新建 migration `2026_06_06_040000_add_email_to_crm_customers` 给 `crm_customers` 表增加了 `email` 列；控制器 validation / normalization / formData 全部添加 email；客户创建/编辑表单增加邮箱输入框；客户列表和详情页显示邮箱；单据表单中「从客户资料带入」按钮同时填充 buyer_email。

- **5. 卖方信息手动修改入口**  
  已确认现有实现正确：`seller_company_json` 文本域创建时为空、编辑时读取已存值，打印时存储值优先于站点设置自动抓取，手动编辑入口完整。

- **6. 打印单据类型选择优化**  
  `print` 方法支持 `?type=quotation|proforma_invoice|invoice|packing_list|contract` 查询参数；单据详情页「打印」按钮替换为下拉选择器（5 种单据类型）；打印页面顶部增加单据类型切换下拉框，无需进入编辑页即可切换打印模板。

涉及文件：

- `resources/views/admin/crm/partials/nav.blade.php`
- `resources/views/admin/crm/quotes/index.blade.php`
- `resources/views/admin/crm/quotes/form.blade.php`
- `resources/views/admin/crm/quotes/show.blade.php`
- `resources/views/admin/crm/quotes/print.blade.php`
- `resources/views/admin/crm/quotes/partials/item-row.blade.php`
- `resources/views/admin/crm/quotes/partials/print-document.blade.php`
- `resources/views/admin/crm/customers/form.blade.php`
- `resources/views/admin/crm/customers/show.blade.php`
- `resources/views/admin/crm/customers/index.blade.php`
- `resources/views/admin/crm/inquiries/index.blade.php`
- `resources/views/admin/crm/inquiries/show.blade.php`
- `resources/views/admin/crm/orders/index.blade.php`
- `resources/views/admin/crm/orders/show.blade.php`
- `resources/views/admin/crm/tickets/index.blade.php`
- `resources/views/admin/crm/tickets/show.blade.php`
- `app/Http/Controllers/Admin/CrmQuoteController.php`
- `app/Http/Controllers/Admin/CrmCustomerController.php`
- `database/migrations/2026_06_06_040000_add_email_to_crm_customers.php`

验证：

- 全部 25 个修改过的 Blade/PHP 文件通过语法检查
- `AdminCrmPagesTest` 7 个测试全部通过（87 assertions）
- 站点 HTTP 200 正常响应

风险/注意：

- 执行 `php artisan key:generate --force` 前须确认 `.env` 当前 `APP_KEY` 行格式正常，避免双 key 拼接导致 Server Error；Docker 容器测试需带 `APP_KEY` 环境变量运行（见 [KNOWN_ISSUES.md](./KNOWN_ISSUES.md) #12）。

### 同日后续修正与优化

**Bug 修复：**

- **OPcache `validate_timestamps=0` 导致代码修改不生效**  
  容器 `/usr/local/etc/php/conf.d/99-opcache.ini` 中 `opcache.validate_timestamps=0` 导致 PHP 不检查文件修改时间，多次改代码、清缓存、重启容器均无效。修改为 `1` 并重启后 PHP 才会检测源文件变化自动重新编译。此配置适用于本地开发（代码通过 Docker volume 挂载），生产环境应保持 `0` 并通过重建镜像更新代码。

- **`CrmCustomer::$fillable` 缺少 `email`**  
  控制器 validation / normalization 均已正确添加 email，但 Laravel mass assignment 保护会静默丢弃 `$fillable` 中未声明的字段，导致客户编辑/新建时邮箱无法保存。已在 `app/Models/CrmCustomer.php` 的 `$fillable` 数组中添加 `'email'`。

- **APP_KEY 双 key 拼接导致 Server Error**  
  `php artisan key:generate --force` 将新旧 key 拼接为 `base64:OLD_KEY=base64:NEW_KEY=`（88 字符 / 66 字节），远超 AES-256-CBC 所需 32 字节，Laravel 加密器崩溃。修复方式：`sed -i "s|^APP_KEY=.*|APP_KEY=base64:<32字节有效key>|" .env` 后重启容器。详细排查步骤已录入 [KNOWN_ISSUES.md #12](./KNOWN_ISSUES.md#12-app_key-损坏风险与-docker-环境覆盖)。

- **「从客户资料带入」邮箱同步逻辑增强**  
  通用 `forEach` 循环中 `value !== ''` 守卫导致客户邮箱为空时不更新 `buyer_email` 字段。增加专用 email 同步逻辑（`emailInput.value = profile.email || ''`），绕过空值守卫，确保客户有邮箱时填充、无邮箱时清空。

**功能优化：**

- **单据类型联动字段拆分为两组**  
  原实现将所有 5 个字段作为一组（`data-logistics-field`），quotation/PI 时全部隐藏。优化后拆为两组：
  - **物流字段组 `data-logistics-field`**（件数/净重/毛重/体积 CBM）：quotation、PI、contract → 隐藏；invoice、packing_list → 显示
  - **HS Code 组 `data-hscode-field`**：**仅** invoice → 显示；其余四种单据类型均隐藏
  此规则对齐了 `单据模板/提示词.txt` 中的外贸业务逻辑。提示文字也改为按单据类型展示对应说明。

- **`单据模板/` 目录可行性审核**  
  审核了三份 HTML 模板（quotation-and-proforma-demo / commercial_invoice / packing_list）和提示词，确认「一套基础模板 + document_type 动态显隐」方向正确。当前 `print-document.blade.php` 已具备雏形，下一步可在该文件上统一视觉标准并对齐提示词的分区规则。审核发现的字段缺口（`port_of_loading`、`transport_mode` 等）已记录，属于第二阶段工作。

**技能文档同步：**

- `geoflow-testing/SKILL.md` 新增 OPcache Gotcha、APP_KEY Corruption Risk、Docker ENV APP_KEY Override 三个章节。
- `geoflow-ui-guidelines/SKILL.md` 新增 Post-Edit Verification、Changes Not Visible? Check OPcache 两个章节。

**新增涉及文件：**

- `app/Models/CrmCustomer.php` — `$fillable` 添加 `'email'`
- `resources/views/admin/crm/quotes/partials/item-row.blade.php` — HS Code 改用 `data-hscode-field`
- `resources/views/admin/crm/quotes/form.blade.php` — JS 拆为两组 toggle + 每类型专属提示 + 邮箱同步
- `/usr/local/etc/php/conf.d/99-opcache.ini` — `validate_timestamps=0→1`
- `~/.codex/skills/geoflow-testing/SKILL.md`
- `~/.codex/skills/geoflow-ui-guidelines/SKILL.md`

 `.env` 当前 `APP_KEY` 行格式正常，避免双 key 拼接导致 Server Error；Docker 容器测试需带 `APP_KEY` 环境变量运行（见 [KNOWN_ISSUES.md](./KNOWN_ISSUES.md) #12）

### 2026-06-06：单据打印模板视觉统一 & 物流字段补齐

**子阶段 A — 打印模板视觉统一：**

- 重构 `print-document.blade.php`：CSS 从 `box-shadow` 卡片风格统一为 A4 打印观感（CSS 变量 `--text`/`--muted`/`--border`/`--light`/`--accent`，`font-family: Arial`，`width: 210mm`）
- 拆分 10 个 Blade partial（`print-header`、`print-buyer-commercial`、`print-items`、`print-summary`、`print-terms`、`print-invoice-logistics`、`print-packing-summary`、`print-signature`、`print-contract-terms`），主模板按 document_type 条件 include
- PI 银行信息移至独立第 2 页（`page-break-before: always`），第 1 页添加「Bank details on next page →」提示条
- Invoice 和 Packing List 去掉产品图片（`$showImages` = quotation/PI only）
- Invoice Summary 显示为 `Items Subtotal | Freight/Shipping | Total Invoice Value`；含 Declaration 区
- Packing List 表格无价格列，显示件数/N.W./G.W./CBM；底部黑底 Packing Summary 卡片
- Bank Account 渲染改为 flexible key-value grid（`@foreach $bank as $bankKey => $bankValue`），兼容任意 JSON key
- Terms 改为双栏 grid 布局（Payment/Delivery/Warranty/Installation）
- Signature 改用 `.sig-box` 面板样式

**子阶段 B — 物流字段补齐：**

- Migration `2026_06_06_100000`：`crm_quotes` 新增 `port_of_loading`、`port_of_destination`、`transport_mode`、`shipping_mark`
- `CrmQuote::$fillable` 新增 4 个字段
- `CrmQuoteController` 新增 validation rules、normalization、form defaults
- 表单 `origin_country` 后方新增 4 个输入框
- Invoice 物流汇总面板、Packing List 运输/唛头/港口面板渲染新字段

**新增/修改文件：**

- `resources/views/admin/crm/quotes/partials/print-document.blade.php` — 重构
- `resources/views/admin/crm/quotes/partials/print-header.blade.php` — 新增
- `resources/views/admin/crm/quotes/partials/print-buyer-commercial.blade.php` — 新增
- `resources/views/admin/crm/quotes/partials/print-items.blade.php` — 新增
- `resources/views/admin/crm/quotes/partials/print-summary.blade.php` — 新增
- `resources/views/admin/crm/quotes/partials/print-terms.blade.php` — 新增
- `resources/views/admin/crm/quotes/partials/print-invoice-logistics.blade.php` — 新增
- `resources/views/admin/crm/quotes/partials/print-packing-summary.blade.php` — 新增
- `resources/views/admin/crm/quotes/partials/print-signature.blade.php` — 新增
- `resources/views/admin/crm/quotes/partials/print-contract-terms.blade.php` — 新增
- `database/migrations/2026_06_06_100000_add_logistics_fields_to_crm_quotes.php` — 新增
- `app/Models/CrmQuote.php` — `$fillable` 新增 4 字段
- `app/Http/Controllers/Admin/CrmQuoteController.php` — validation/normalize/form defaults 新增 4 字段
- `resources/views/admin/crm/quotes/form.blade.php` — 新增 4 输入框
- `agent-docs/CRM_DOC_PRINT_OPTIMIZATION.md` — 新增（详细执行指令，防上下文压缩）

**参考模板：** `/tmp/geoflow-doc-review/01-04_*.html`

**验证：** AdminCrmPagesTest 7/7 通过（87 assertions），全部 10 个 Blade partial PHP 语法检查通过。

### 2026-06-07：单据模板细节修正 & 字段补齐

**问题修复：**

1. **Quotation/PI notes 重复渲染** — Summary 左侧已展示 notes 后，fallback 区不再重复输出。修复条件：`!$isInvoice && !$showContract && !$showPrices`。
2. **Packing 条款字段缺失** — 新增 `packing_terms`（string, max:500），DB→Model→Controller→Form→print-terms 全链路。
3. **PI 预付款比例** — 新增 `deposit_percent`（默认 60, 0-100），PI 第 2 页 Payment Summary 动态计算 Deposit Required / Balance Before Shipment。
4. **PI Bank Currency** — 从 `$bank['currency']`（bank_account_json 可能不填）改为 `$quote->currency`（单据币种）。
5. **CI Exporter/Seller 面板** — `print-buyer-commercial` 按 document_type 三路分支：CI 左 Seller 右 Buyer，PL 左 Shipper 右 Consignee，其余 Buyer + Commercial Info。
6. **CI notes 排版** — notes 从 Declaration 区移到 Summary 左侧 `.notes-box`，与 Quotation 一致。Declaration 保留纯文本。
7. **PL Notes 写死** — PL 不再读 `$quote->notes`，固定为 "Package type: Export-grade wooden case. All dimensions and weights are for customs clearance and logistics reference."
8. **Pkg Size 字段** — `crm_quote_items` 新增 `package_length/width/height`（decimal 8,1），item-row 表单新增紧凑 L×W×H 输入（属 `data-logistics-field` 组），PL 打印表格新增 Pkg Size (cm) 列。

**新增/修改文件：**

- `database/migrations/2026_06_07_010000_add_packing_terms_and_deposit_to_crm_quotes.php` — 新增
- `database/migrations/2026_06_07_020000_add_package_dimensions_to_crm_quote_items.php` — 新增
- `app/Models/CrmQuote.php` — fillable + casts 新增 packing_terms / deposit_percent
- `app/Models/CrmQuoteItem.php` — fillable + casts 新增 package_length/width/height
- `app/Http/Controllers/Admin/CrmQuoteController.php` — 所有字段的 validation/normalize/form defaults/labels
- `resources/views/admin/crm/quotes/form.blade.php` — 新增 packing_terms / deposit_percent 输入
- `resources/views/admin/crm/quotes/partials/item-row.blade.php` — 新增 Pkg Size L×W×H 紧凑输入
- `resources/views/admin/crm/quotes/partials/print-document.blade.php` — notes 去重 / PI Payment Summary / Bank Currency / PL Notes 写死 / CI Declaration 简化
- `resources/views/admin/crm/quotes/partials/print-buyer-commercial.blade.php` — CI/PL 三路分支
- `resources/views/admin/crm/quotes/partials/print-items.blade.php` — PL Pkg Size 列
- `resources/views/admin/crm/quotes/partials/print-summary.blade.php` — notes 条件简化
- `resources/views/admin/crm/quotes/partials/print-terms.blade.php` — packing_terms 渲染
- `agent-docs/CRM_DOC_PRINT_OPTIMIZATION.md` — 删除（已完成，内容已融入本文档）

**验证：** AdminCrmPagesTest 7/7 通过（87 assertions），全部 10 个修改文件 PHP 语法检查通过。

### 2026-06-07（第2轮）：签名统一 + Bank 字段标准化 + SKU 删除 + Form 排版优化

**签名模块统一：**
- `print-signature.blade.php` 所有单据签名标题统一为「Seller / 卖方」+「Buyer / 买方」（CI 为「Authorized Signature / 授权签章」）
- Seller Name 从 `$quote->owner`（内部员工）改为 `$seller['name']`（公司名）
- PI 第1页签名删除，仅保留第2页签名（避免产品多时签名溢出）
- CI 底部签名整块移除

**Bank Account 标准化：**
- 固定字段：`beneficiary` / `bank_name` / `account_no` / `swift` / `bank_address`
- Currency 统一使用 `$quote->currency`
- PI page 2 和单页 bank 块统一为标准字段渲染
- 表单 `bank_account_json` placeholder 预填标准模板 `{"beneficiary":"","bank_name":"","account_no":"","swift":"","bank_address":""}`
- `seller_company_json` placeholder 同样预填 `{"name":"","address":"","phone":"","email":"","website":""}`

**其他打印页修复：**
- CI header 去掉 Ref 行（之前引用了 `$quote->notes` 不合理）
- CI Exporter 面板补充 Contact 字段
- PL grid-3（Shipment / Shipping Mark / Port Info）从 `print-packing-summary` 拆分为独立 partial `print-pl-shipment`，移到 Items 表上方
- `.info-grid` 比例从 `1.1fr 0.9fr` 改为 `1fr 1fr`

**SKU 字段全链路删除：**
- Migration `2026_06_07_030000` dropColumn `sku` from `crm_quote_items`
- Model `CrmQuoteItem::$fillable` 移除 `'sku'`
- Controller validation/normalize/formDefaults 清理所有 SKU 相关
- `item-row.blade.php` 移除 SKU 输入，label 从「SKU / 型号」改为「型号」
- `print-items.blade.php` SKU/Model 组合列改为 Model only
- 测试数据中 SKU 断言移除

**Form 排版优化：**
- Section 间距加大（`mt-6`），section 标题区改为 `bg-gray-50` 顶栏（`-mx-5 -mt-5 rounded-t-lg border-b`）
- Pkg Size L×W×H 输入宽度加大（`min-w-[80px]`），间距 `gap-2`

**Entity 关联说明：**
Item 关联 Entity 用于：1) 新建时自动预填 item_name + description；2) 打印时通过 Entity 查询型号规格；3) AI 需求识别时推荐关联 Entity。

**新增/修改文件：**
- `database/migrations/2026_06_07_030000_drop_sku_from_crm_quote_items.php` — 新增
- `resources/views/admin/crm/quotes/partials/print-signature.blade.php` — 重写
- `resources/views/admin/crm/quotes/partials/print-buyer-commercial.blade.php` — CI Contact + 比例
- `resources/views/admin/crm/quotes/partials/print-header.blade.php` — CI 去 Ref
- `resources/views/admin/crm/quotes/partials/print-document.blade.php` — PI 去签名/统一 bank/grid-3 前置
- `resources/views/admin/crm/quotes/partials/print-pl-shipment.blade.php` — 新增（从 packing-summary 拆出）
- `resources/views/admin/crm/quotes/partials/print-packing-summary.blade.php` — 精简
- `resources/views/admin/crm/quotes/partials/print-items.blade.php` — SKU 移除
- `resources/views/admin/crm/quotes/partials/item-row.blade.php` — SKU 移除 + Pkg Size 加宽
- `resources/views/admin/crm/quotes/form.blade.php` — JSON 模板 + 排版优化
- `app/Models/CrmQuoteItem.php` — SKU 移除
- `app/Http/Controllers/Admin/CrmQuoteController.php` — SKU 全链路移除
- `tests/Feature/AdminCrmPagesTest.php` — SKU 断言移除 + bank 标准字段

**验证：** AdminCrmPagesTest 7/7 通过（87 assertions），全部 13 个修改文件 PHP 语法检查通过。

### 2026-06-07（第3轮）：字段规范化 & Buyer/Contact 拆分

**crm_customers.region → address：**
- Migration rename `region` → `address`
- CrmCustomer Model fillable / Controller / 全部 Blade 标签同步更新
- customerProfiles 返回 `address`，联动 `buyer_address`

**Seller Contact 映射修正：**
- CI/PL Exporter Contact：`$seller['name']`（公司名）→ `$quote->owner`（管理人）
- 签名 Seller Name：同改 `$quote->owner`

**JSON 模板预填：**
- `bank_account_json` 和 `seller_company_json` textarea 首次空时预填模板 JSON 作为 value（非 placeholder），等宽字体

**Buyer/Contact 拆分：**
- `crm_customers` 新增 `contact_person` 字段
- `crm_quotes` 新增 `buyer_contact` 字段
- 全链路：migration→Model fillable→Controller validation/normalize/defaults/customerProfiles/customerBuyerDefaults→Form输入→「从客户资料带入」JS联动→打印页渲染
- Buyer 面板：Company=`$quote->buyer_name`，Contact=`$quote->buyer_contact`（分开渲染，不再共用同一字段）
- 所有 Buyer/Customer 面板标题统一为 "Buyer / 买方"

**Packing 条款默认值：**
- `print-terms` 中 packing_terms 始终渲染；后台未填时 fallback "Standard export wooden case"

**新增/修改文件：**
- `database/migrations/2026_06_07_040000_rename_region_to_address_in_crm_customers.php` — 新增
- `database/migrations/2026_06_07_050000_add_contact_person_to_crm_customers.php` — 新增
- `database/migrations/2026_06_07_060000_add_buyer_contact_to_crm_quotes.php` — 新增
- `app/Models/CrmCustomer.php` — address + contact_person
- `app/Models/CrmQuote.php` — buyer_contact
- `app/Http/Controllers/Admin/CrmCustomerController.php` — region→address + contact_person
- `app/Http/Controllers/Admin/CrmQuoteController.php` — buyer_contact + customerProfiles/customerBuyerDefaults 扩展
- `resources/views/admin/crm/customers/form.blade.php` — address label + contact_person 输入
- `resources/views/admin/crm/customers/show.blade.php` — address label
- `resources/views/admin/crm/customers/index.blade.php` — address label
- `resources/views/admin/crm/quotes/form.blade.php` — buyer_contact 输入 + JSON 预填 + 联动 JS
- `resources/views/admin/crm/quotes/partials/print-buyer-commercial.blade.php` — Contact 拆分 + 标题统一
- `resources/views/admin/crm/quotes/partials/print-signature.blade.php` — Seller Name→owner
- `resources/views/admin/crm/quotes/partials/print-terms.blade.php` — packing fallback

**验证：** AdminCrmPagesTest 7/7 通过（87 assertions），全部 9 个文件 PHP 语法检查通过。

### 2026-06-07（第4轮）：客户表单 company_name 标签修正 + contact_person 表单输入

**客户表单字段语义修正：**
- `company_name` 标签：`客户名称` → `公司名`，placeholder 同步改为 `客户公司名称`
- 新增 `contact_person` 输入框，标签 `客户名称（联系人）`，placeholder `联系人姓名`

**保存链路修复：**
- `normalizeCustomerPayload()` 补上 `contact_person` 保存（此前该字段在 validation 和 emptyCustomerForm 中存在，但 normalize 中遗漏，导致 save 时被 Laravel mass assignment 静默丢弃）

**修改文件：**
- `resources/views/admin/crm/customers/form.blade.php` — 标签修改 + 新输入框
- `app/Http/Controllers/Admin/CrmCustomerController.php` — normalizeCustomerPayload 补 contact_person

**验证：** AdminCrmPagesTest 7/7 通过。

**说明：** 此轮只改了客户编辑表单，单据制作侧和打印页的 Buyer Name/Contact 字段联动暂未改动，留待下一轮统一处理。

### 2026-06-07（第5轮）：contact_person 提升为核心字段，列表/详情页显示调整

**contact_person 提升为必填：**
- 表单 input 加 `required` + 红色星号
- Controller validation `'nullable'` → `'required'`
- `company_name` 保持 required（公司名和联系人都是必填）

**列表页（index）：**
- 主标题 `$customer->company_name` → `$customer->contact_person ?: $customer->company_name`（联系人优先，无联系人时 fallback 公司名）
- 搜索 placeholder 同步更新

**详情页（show）：**
- 页面标题同列表页逻辑
- "客户名称" 标签改为 "公司名"
- 新增 "客户名称（联系人）" 行显示 contact_person

**测试：** AdminCrmPagesTest 所有客户创建数据补上 contact_person，7/7 通过。

### 2026-06-07（第6轮）：buyer_name → buyer_company 全链路重命名 + 表单标签中文化

**DB 重命名：**
- Migration `2026_06_07_070000` 将 `crm_quotes.buyer_name` 重命名为 `buyer_company`

**全链路替换：**
- Model `CrmQuote::$fillable`：`buyer_name` → `buyer_company`
- Controller：validation/normalize/formDefaults/customerBuyerDefaults/customerProfiles 全部替换
- 所有打印 partials `$quote->buyer_name` → `$quote->buyer_company`
- 详情页 show.blade.php 同步
- 测试文件 test data 同步

**Controller 补充修复：**
- `buyer_contact` 补入 validation rules（之前遗漏）
- `buyer_contact` 补入 `normalizeQuotePayload`（之前遗漏）
- `buyer_contact` 补入 edit formData builder

**表单 Blade 更新：**
- Buyer Name → 联系人（input name: buyer_contact），放在第一位
- Contact → 公司名（input name: buyer_company），放在第二位
- Phone → 电话，Email → 邮箱，Country → 国家，Address → 地址
- JS `data-fill-buyer-from-customer` 同步 key 重命名

**字段语义最终关系：**
```
crm_customers.contact_person → crm_quotes.buyer_contact → 打印页 Contact
crm_customers.company_name   → crm_quotes.buyer_company → 打印页 Company
```

**测试：** 7/7 通过。

### 2026-06-07（第7轮）：Entity-to-Entity 关联功能（基础层）

**数据库：**
- `relation_types` 表：10 种预设关系类型（uses / requires / compatible_with / competes_with / suitable_for / belongs_to / manufactured_by / sold_to / causes / solves），含 forward_label / reverse_label / bidirectional 标记
- `entity_relations` 表：source_entity_id → relation_type_id → target_entity_id + strength(0-100) + source_type(manual/ai)，UNIQUE 约束防重复，DELETE CASCADE

**Model：**
- 新增 `RelationType` Model
- 新增 `EntityRelation` Model（含 sourceEntity / targetEntity / relationType 关系方法）
- `EntityRecord` 追加 `sourceRelations()` / `targetRelations()` 两个 HasMany 方法

**服务层：**
- 新增 `EntityRelationService`：relationTypes() / relatedEntities() / addRelation() / removeRelation() / syncRelations()

**Controller：**
- `EntityController.edit()` 传参加入 `entityRelationService` + `entityOptionsForRelation`
- `EntityController.update()` 保存时调用 `syncRelations()` 处理关系提交
- 新增 `searchJson()` — Entity 远程搜索 API（支持 name/aliases 模糊搜索 + Collection 约束）
- 新增 `relations()` — 查询指定 Entity 的关系列表 API
- 新增 `entityOptionsForRelation()` — 获取可关联的候选 Entity 列表

**前端：**
- 新增 `entities/partials/entity-relations.blade.php` — Entity 编辑页底部"关联 Entity"区域，含目标 Entity 选择器 + 关系类型下拉 + 强度滑块 + 行内删除
- 支持 datalist 搜索、重复检测、已保存关系自动回显

**路由：**
- `GET /admin/entities/search` — Entity 搜索 API
- `GET /admin/entities/{entityId}/relations` — 关系查询 API

**首期不包含（第二期）：**
- AI 自动推荐 Entity Relation
- Entity 状态字段（core/normal/deprecated）
- Entity 列表页实体类型筛选
- AI 找实体（自然语言搜索）

**验证：** AdminCrmPagesTest 7/7 通过（87 assertions），全部 6 个新/改文件 PHP 语法检查通过。

### 2026-06-08（第7轮）：CRM客户下拉选项统一 + 详情页客户显示修复

**问题：** 单据制作、订单、售后的详情页和编辑页中客户显示/下拉格式不统一。

**修复内容：**

1. **详情页客户显示**：`orders/show` 和 `tickets/show` 从 `company_name` 改为 `contact_person ?: company_name`，与 `quotes/show` 保持一致

2. **编辑表单下拉格式**：`tickets/form` 客户下拉从裸 `$customer->company_name` 改为 `label + meta` 格式（联系人名 · 业务容器），与询盘/报价编辑页一致

3. **Controller customerOptions 统一**：
   - `CrmSalesOrderController` 和 `CrmAfterSalesTicketController` 的 `customerOptions` 从 `CrmCustomer::get(['id','company_name'])` 改为标准 `{id, label, meta, collection_id}` 格式（label = contact_person ?: company_name）
   - 支持按 Collection 过滤、上限 300 条

4. **订单编辑页新增客户选择**：`orders/form` 补上客户下拉，Controller validation 和 update payload 同步增加 `customer_id`

**测试：** 7/7 通过。

### 2026-06-09（第8轮）：打印版本回滚与Git锚点

**背景：** 此前尝试过 dompdf/spatie-laravel-pdf 自动生成 PDF 方案和 PhpSpreadsheet Excel 导出方案，均因样式/排版问题不可用。

**最终方案确定：** 保持纯 HTML 打印预览 + 浏览器手动 Ctrl+P 打印 PDF。

**回滚操作：**
- `print-document.blade.php` 回滚到 git HEAD（commit `8a7666b`），恢复稳定 A4 打印样式
- Controller 保留 `downloadExcel` 和 `downloadPdf` 方法（`bak-20260609-excel` 版本），路由保留 `excel` 和 `pdf`
- 编辑页 UI 优化（`form.blade.php`、`item-row.blade.php`）保持不变

**Git 锚点：**
- Commit: `d609891` — "snapshot: stable HTML print version after Excel/PDF rollback"
- Tag: `print-stable-20260609`
- 用途：如需恢复打印样式，可通过此 tag 快速还原

**结论：** 该版本打印预览正常、单据类型切换联动正常、编辑页 UI 优化保留。

### 2026-06-09（第9轮）：跟进记录多端展示 + Markdown编辑器 + 布局优化

**背景：** 跟进记录入口只在客户详情页，应围绕"询盘→报价→订单→售后"线索链多端展示。

**改动内容：**

1. **跟进记录多端展示：**
   - 询盘详情页：新增跟进记录表单 + 列表，`inquiry_id` 自动填入
   - 客户详情页：表单增加询盘下拉；列表增加来源标签
   - 报价详情页：通过关联询盘→客户展示跟进 timeline（只读）
   - 订单详情页：通过关联询盘→客户展示跟进 timeline（只读）
   - 售后详情页：通过关联订单→询盘→客户展示跟进 timeline（只读）
   - 共用组件 `_follow-up-item.blade.php` 统一渲染

2. **Markdown 编辑器：**
   - 新增 `_markdown-editor.blade.php` 组件，三态切换（编辑/预览/源码）
   - 工具栏支持加粗、斜体、标题、链接、行内代码、列表
   - 跟进表单 textarea 全部替换为 Markdown 编辑器
   - 列表渲染使用 `Str::markdown()` 解析

3. **跟进记录删除功能：**
   - 路由 `POST crm/follow-ups/{followUpId}/delete`
   - `CrmInquiryController::destroyFollowUp()` 方法
   - `_follow-up-item` 组件 hover 显示删除按钮

4. **布局调整：**
   - 跟进记录模块从右侧 aside 移到左侧主内容区（5个页面）
   - 独立占宽主列，适合较长的跟进内容阅读

5. **列表页修复：**
   - 订单列表页和售后列表页客户信息从 `company_name` 改为 `contact_person ?: company_name`

**Controller 改动：**
- `CrmInquiryController`：新增 `storeFollowUp()`、`destroyFollowUp()`，show 方法加载 `customer.followUps.inquiry`
- `CrmQuoteController`：show 方法加载 `inquiry.customer.followUps.inquiry`
- `CrmSalesOrderController`：show 方法加载 `inquiry.customer.followUps.inquiry`
- `CrmAfterSalesTicketController`：show 方法加载 `order.inquiry.customer.followUps.inquiry`

**新增文件：**
- `resources/views/admin/crm/partials/_markdown-editor.blade.php`
- `resources/views/admin/crm/partials/_follow-up-item.blade.php`
- `resources/views/admin/crm/partials/_follow-up-section.blade.php`（未使用，可清理）

**注意事项：**
- `admin.crm.follow-ups.delete` 路由在 CRM 组公用层级，不在 any 子组内
- `_markdown-editor` 组件用内联 `style="display:none"` 而非 `x-cloak`（项目未定义对应 CSS）
- 跟进记录通过 `customer.followUps` 加载，跨询盘共享同一客户的所有跟进
## 2026-06-11：CRM 工作流升级

- 已新增 CRM 工作台、CRM 待办与商机管道。
- 客户已支持多个外部联系人和主联系人；旧联系人字段保留并自动同步。
- 询盘活动已按 `inquiry_id` 隔离，客户层活动仍可汇总查看。
- 客户、询盘、单据、订单、售后、活动记录已启用软删除归档。
- 单据可转换为独立的报价 / PI / CI / 装箱单 / 合同记录，并通过 `source_quote_id` 追踪来源。
- Docker 回归测试：`AdminCrmPagesTest` 11 项、108 个断言通过；桌面与 390px 移动端已完成截图检查。

## 2026-06-14：CRM 活动记录编辑器优化

- 保留活动记录与 CRM 待办两个概念：
  - 活动记录：已发生的沟通结果。
  - CRM 待办：未来需要执行的动作。
- `resources/views/admin/crm/partials/_markdown-editor.blade.php` 升级为文章编辑器风格的轻量 Markdown 编辑器：
  - 支持编辑、预览、源码三种视图。
  - 支持标题、加粗、斜体、引用、列表、链接、行内代码和分隔线快捷插入。
  - 不加载完整 Vditor，避免 CRM 详情页过重。
  - 预览渲染限制链接协议，避免不安全链接直接可点击。
- 回归验证：
  - `php -l resources/views/admin/crm/partials/_markdown-editor.blade.php` 通过。
  - `AdminCrmPagesTest` 通过，12 项、129 个断言。
