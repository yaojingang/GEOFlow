# GEOFlow 原生 MCP 运营后台与 ChatGPT 接入方案

状态：维护者已批准文档方案，进入实施准备。PR#148 仅交付文档；业务 MCP HTTP 端点与 ChatGPT 运营接入仍待实现。
修订日期：2026-10-25。源码证据基线：`bd5b1aed45c43e687399f0c114d78b421e177a80`。
配套：[复核报告](../reviews/chatgpt-mcp-management-review.md) · [80 项验收清单](chatgpt-mcp-management-acceptance.md)。

## 1. 架构决策与本次边界

采用**GEOFlow 单仓库内置 MCP 模块、随站点部署、每个实例独立连接和授权**的主方案。ChatGPT 插件与 Skill 负责使用引导和分发；独立 MCP 仓库、集中多租户网关留待确有独立发布或托管需求时另行决策。

本修订替代此前 RFC 中“独立 TypeScript sidecar 为首版默认形态”的选择。原生调用链不再要求 MCP 回环调用本站 REST API，也不要求额外保存一套本站 Sanctum Token。原有安全要求继续保留；变更的是实现边界，业务授权、原子草稿更新、预算、收据和撤销均不能省略。

本 PR 交付详细方案、实施拆分、风险与验收规格。本轮仅调整文档，没有新增运行代码、依赖、迁移、生产凭据、独立仓库或实际插件包，也不执行部署。维护者已授权完成文档审查、CI 与合并；运行能力按后续实施 PR 逐项验收。

| 决策 | 首版要求 | 后续扩展条件 |
| --- | --- | --- |
| 仓库 | 在 GEOFlow 内模块化实现，使用同一应用版本 | 独立团队、独立发布或多产品复用有明确收益后拆包或拆仓 |
| 部署 | 复用站点运行环境和业务队列，不增加必需的 Node 服务 | 负载或故障隔离需要时可从同仓库拆进程 |
| 实例连接 | 一个连接绑定一个部署实例和一个已授权管理员 | 多连接分别授权；集中路由网关另做威胁模型 |
| 技术选型 | 优先验证 Laravel MCP 与 Passport 的原生组合 | 安装、协议或身份兼容不通过时记录 ADR，再选择同栈替代组件 |
| 权限开放 | P0 只读，草稿、生成、发布独立开关 | 对应阶段通过验收后才开放 |
| 插件 | 可选工作流与分发入口，连接可先独立使用 | 完整安装、组织分发、公开目录分别验收 |

## 2. 产品目标与明确不做的事

目标体验：部署兼容版本 → 后台启用 AI 连接 → 复制实例 MCP 地址 → 在 ChatGPT 登记并跳转 GEOFlow 登录授权 → 对话读取、分析和受控操作 → 后台核对结果及随时撤销。

| 用户请求示例 | 所需能力 | 成功应如何表述 |
| --- | --- | --- |
| 看看这篇文章为什么质检失败 | 文章与完整质检证据读取 | 引用文章 ID、证据和数据时间；缺证据时说明限制 |
| 汇总最近七天失败的生成任务 | 结构化日志、时间过滤及聚合 | 返回统计口径、分母、截止时间与覆盖范围 |
| 修改这篇草稿，先让我确认差异 | 计划、可信批准、原子草稿提交 | 返回新 revision、实际状态和操作收据 |
| 创建任务生成五篇文章，全部待审 | 任务计划、预算、冻结配置与队列 | 分别报告创建、入队、执行、质检状态 |
| 把已审核文章发布到选定渠道 | P3 发布计划、批准与渠道门禁 | 按渠道报告实际效果，允许部分成功和结果未知 |

首版排除：任意 Shell、SQL、PHP 或 HTTP 代理；直接读取服务器文件；数据库管理；删除；Updater 与备份恢复；主题原生代码编辑；用户与密钥管理；人工覆盖质量门禁；任意 URL 素材导入；浏览器外站自动发布。既有后台存在某个功能，不代表它应自动成为 MCP 工具。

ChatGPT 联网能力与 MCP 能力各自受客户端控制。MCP 不会自动获得 ChatGPT 浏览器或搜索权限；外部研究资料仅作为输入证据，进入 GEOFlow 后仍受内容审核和保存规则约束。

## 3. 已核对的源码基础与缺口

以下观察于 2026-10-25 重新核对源码基线的依赖、文章工作流、认证及管理操作调用链；固定源码链接见配套复核报告。静态观察不构成生产漏洞确认。

| 现有入口 | 可复用内容 | 原生接入注意事项 |
| --- | --- | --- |
| [composer.json](../../composer.json)、[composer.lock](../../composer.lock) | Laravel 12、PHP ^8.3 与 Sanctum；锁文件的开发依赖含 Boost 引入的 laravel/mcp v0.7.0 | 生产 require 未声明 laravel/mcp 或 Passport；开发安装不构成生产 MCP 入口，实施 PR 须锁定生产组件和扩展点 |
| [routes/api.php](../../routes/api.php) | 文章、任务、Job、素材、站点、会话和能力接口 | 复用业务契约，避免自动暴露全部路由 |
| [config/auth.php](../../config/auth.php)、[Admin](../../app/Models/Admin.php) | admin guard、admins provider 及 Sanctum Token | 默认 web 关联 users；OAuth 不能误用普通用户身份 |
| [ApiAuthContext](../../app/Http/ApiAuthContext.php)、[BaseApiController](../../app/Http/Controllers/Api/V1/BaseApiController.php) | HTTP 请求属性中的 Token/审计管理员、活跃状态与角色复核 | 现有上下文依赖 REST 请求，须提取可信不可变执行上下文，不能为 MCP 伪造请求 |
| [ManagementSessionController](../../app/Http/Controllers/Api/V1/ManagementSessionController.php) | Token 与管理策略求交集、能力过滤 | 提取共享授权规则，不能伪造 Sanctum 上下文 |
| [ManagementInstance](../../app/Services/Api/ManagementInstance.php) | 实例 ID、Core 版本及恢复状态描述 | 现有 protocol_version=1.0 属于管理契约；实例 ID 初始化有数据库写入 |
| [ArticleController](../../app/Http/Controllers/Api/V1/ArticleController.php)、[ArticleGeoFlowService](../../app/Services/GeoFlow/ArticleGeoFlowService.php) | 更新时锁内 workflow_version 比较、已发布内容权限与质量/审核门禁 | 仍需草稿专用状态约束、资源范围和字段投影；不能直接暴露通用更新 |
| [ArticleWorkflowTransitionService](../../app/Services/GeoFlow/ArticleWorkflowTransitionService.php)、[ArticleWorkflow](../../app/Support/GeoFlow/ArticleWorkflow.php)、[Article](../../app/Models/Article.php) | 已有 workflow_version、publication_intent、内容变更及人工状态迁移 | 优先评估复用 workflow_version；全写入口与全文字段覆盖尚未验证，避免重复计数器 |
| [TaskController](../../app/Http/Controllers/Api/V1/TaskController.php) | need_review 与发布权限联动、任务入队 | 控制器中的保护需下沉共享执行层；Worker 快照和预算另验收 |
| [ManagementOperationRegistry](../../app/Support/Api/ManagementOperationRegistry.php) | 操作注册与部分契约 | 旧业务 API 未完整纳入，不能直接当作完整 MCP 工具表 |
| [ManagementOperationService](../../app/Services/Api/ManagementOperationService.php)、[ManagementOperation](../../app/Models/ManagementOperation.php) | tasks.enqueue 持久收据、输入 hash 冲突、实例+admin+请求 ID 去重；回读复核 scope 和任务可见性 | 优先扩展现有收据与授权边界；其 Request/ApiAuthContext 依赖需拆出，不新建平行收据存储 |
| [IdempotencyService](../../app/Services/Api/IdempotencyService.php)、[CLI 流程](../../.agents/skills/geoflow/references/remote-cli-workflow.md) | 旧幂等头、收据头及未知结果的既有约定 | 保留各自契约，原生应用层直接传稳定请求 ID |

现有轻量质检状态不含完整证据；文章列表没有通用起止日期过滤；已查看的 API 路由未提供通用运营日志检索与统计入口。对应能力需补充授权查询服务，不能凭额外参数或读取少量分页声称已实现。

## 4. 原生架构与代码边界

```text
ChatGPT对话 / 可选业务Skill
    ↓ OAuth访问令牌，resource绑定当前实例
实例HTTPS MCP端点
    ↓ 认证、连接绑定、协议校验、限流
MCP工具：严格参数、工具白名单、结果投影
    ↓ 不可变ManagementExecutionContext
共享管理应用服务：动作与资源授权、计划、事务、审计
    ↓
现有领域服务、数据库事务、业务队列与Worker
    ↓
结构化结果：资源、revision、收据、状态、覆盖范围

既有REST入口 → 从Sanctum构建同类执行上下文 → 共享管理应用服务
可信后台页面 → 从admin登录态构建批准上下文 → 计划批准服务
```

MCP 工具不得直接执行 Eloquent 写入或任意查询来绕过共享应用服务。数据库仍由 GEOFlow 现有数据访问层管理。只读查询也必须显式带入授权上下文；认证成功不能替代资源过滤。

以下目录和类名均为拟议结构，未创建：

```text
app/Mcp/Servers/GeoFlowServer.php
app/Mcp/Tools/{Connection,Articles,Tasks,Materials,Analytics}/
app/Services/Management/{ManagementExecutionContext,ManagementAuthorizer}.php
app/Services/Management/{DraftCommandService,GenerationCommandService}.php
app/Services/Management/OperationPlanService.php
app/Services/Management/{OperationalLogQuery,OperationalMetricsQuery}.php
app/Services/Mcp/{McpIdentityResolver,McpGrantService,McpTokenBinding}.php
app/Http/Controllers/Admin/McpConnectionController.php
app/Models/{McpPrincipal,McpGrant}.php
routes/ai.php
config/mcp.php
integrations/chatgpt/{README.md,templates/,skills/,evals/}
tests/Feature/Mcp/
```

共享管理服务可以包装现有领域服务。收据优先扩展现有 app/Services/Api/ManagementOperationService.php 及 ManagementOperation 模型，提取其可信上下文与授权入口，不另造 OperationReceiptService 存储。REST 响应格式和原有 CLI 契约保持兼容；首版只提取本期开放能力实际依赖的边界。

## 5. 统一执行上下文与权限模型

拟议 ManagementExecutionContext 包含：认证来源、可信 principal 标识、实际 admin_id、instance_id、连接与 grant 版本、当前账号 auth_version、资源范围、允许动作、request_id 和恢复代际。对象只能由认证适配器构造；模型参数不能构造或覆盖它。

```text
有效业务权限 = 当前管理员业务权限 ∩ 当前连接允许动作
             ∩ 连接资源范围 ∩ 已实现工具白名单 ∩ 阶段开关
             ∩ 当前授权/恢复状态
```

REST 上下文额外受当前 Sanctum Token 能力限制；原生 MCP 上下文受验证后的 OAuth 授权及其绑定 grant 限制。不得给原生路径虚构一个全权 Core Token，再沿用旧公式。

每次调用在读取或写入前重查账号状态、角色、auth_version、grant_version、撤销状态及资源范围；高风险提交和 Worker 执行前再次验证。tools/list 缓存不授予权限。权限扩大必须重新同意；现有令牌不得随账号升级或后台编辑 grant 而自动扩权。

业务动作可以复用 articles:read、tasks:read 等现有语义；drafts:write、operations:read、analytics:read 等新增名称需显式登记和契约测试。它们是应用层动作，不能在默认 OAuth metadata 里虚报为已支持的 OAuth scopes。

P0 只承诺明确授权的整实例共享运营资料，并继续保留已有模型、提示词和知识库可见性限制。只有完成所有查询、详情、关联资源、计数和写入的范围校验后，授权页才能提供 site_ids 等更细粒度选择。site_id 参数和拥有站点列表权限均不能替代数据隔离。

## 6. OAuth 实现路线与身份共存

### 6.1 组件选择和上线前验证

Laravel 官方 MCP 提供 Web 服务与 OAuth 集成，可作为原生候选。当前开发安装 v0.7.0 的 Registrar::oauthRoutes 及 Laravel 12 文档均采用单一 mcp:use，官方文档明确该默认流程的自定义 OAuth scope 限制。[S01] 第一份实施 PR 须锁定生产 MCP/Passport 版本并实测扩展点、PHP 最低版本、Admin 身份适配、resource 绑定与真实客户端互通。验证失败时保留关闭状态，先记录具体兼容缺口。

不要把两套 HasApiTokens trait 直接混入现有 Admin，也不要直接修改默认 web guard。优先验证独立 McpPrincipal 及专用 OAuth provider/guard：principal 保存不可变 subject 及对 Admin 的引用，不复制密码、角色或权限；同意页面仍要求有效 admin 登录态。OAuth 服务只通过可信登录流程建立该引用。该桥接方式是拟议设计，需验证 Passport 和 Laravel MCP 的实际扩展点；组件默认行为不能当作已经完成桥接。

同时验证授权码、访问令牌和刷新令牌都绑定到同一个 grant。仅按 admin_id 或 client_id 查找“最新授权”会把多个连接混在一起，明确禁止。不得要求 OIDC ID Token 作为必需品；本地 OAuth 主体可以通过已验证令牌及服务端记录解析。业务调用使用访问令牌，不能把 ID Token 当成访问令牌。

### 6.2 实例授权流程

1. 管理员在本站启用 MCP，完成实例身份、规范地址、OAuth 密钥和允许客户端配置。
2. ChatGPT 连接实例 MCP URL；未认证请求获得符合选定协议的 401 挑战与受保护资源 metadata。
3. 客户端发现本站授权服务；按实测能力使用预注册、CIMD 或 DCR。不能宣称组件默认支持全部方式。
4. GEOFlow 显示 admin 登录及同意页面，列出客户端、实例、数据范围、业务动作、有效期与外发说明。
5. 服务端从当前登录态解析管理员，创建独立 grant，将授权码绑定 principal、client、resource、grant 版本和 PKCE。
6. 客户端交换授权码，获得仅可访问当前 MCP 资源的访问令牌；每次工具调用验证其绑定与当前权限。
7. 后台可以缩减或撤销 grant；扩权需新一轮同意。刷新只延续原 grant，不得跳转到其他连接或扩大权限。

OAuth metadata 须声明并实际支持 PKCE S256。issuer、受保护资源 metadata 中的授权服务地址须精确一致，resource 贯穿授权及令牌交换，令牌在资源端验证签名或可信内省、issuer、audience、期限与权限。[S02][S06] 单有 Passport 默认 Token 签名校验不足以证明 resource 绑定已满足要求；跨实例重放测试是硬门槛。

仅当成功及错误授权响应均返回与 metadata 精确匹配的 iss 时，才声明 authorization_response_iss_parameter_supported=true。静态预注册、CIMD 或 DCR 按已配置且通过验证的方式启用；使用 ChatGPT MCP 管理页提供的确切 CIMD 地址和 redirect URI，不硬编码通用 callback。[S02] state、回调精确匹配、授权码单次使用及错误响应均纳入负向测试。

mcp:use 只作为连接层许可。细粒度动作与资源记录在服务端 grant，并在每次调用中执行。认证失败、OAuth scope 不足、业务动作拒绝分别返回恰当错误；业务权限不足时不无限触发同一个 mcp:use 授权循环。

### 6.3 凭据与密钥

每个实例持有自己的 OAuth 签名或验证配置；不在镜像、Git、插件包、日志或模型输出中提供秘密。原生 MCP 不额外签发本站 Sanctum Token。旧 REST Token 不得在 MCP 入口被当成 OAuth 令牌，MCP 令牌也不能被旧 REST 路径静默接受。原有 CLI、浏览器运营助手和后台会话需要回归。

是否发行 refresh token、期限与轮换策略在实施 PR 中明确。只有支持并验证时才广告对应能力；不支持时明确需要重新登录。撤销必须覆盖该 grant 的授权码、访问与刷新令牌，不能误撤销其他连接或 CLI 凭据。

## 7. 拟议数据模型与生命周期

以下为逻辑实体，可复用现有可靠存储；不要求机械新增全部表。本 PR 不执行迁移。

| 逻辑实体 | 必需信息 | 不变量 |
| --- | --- | --- |
| MCP principal | subject、admin 引用、instance_id | 服务端创建、映射不可被请求参数修改，无重复密码体系 |
| MCP grant | grant_id、principal、client、resource、actions、resource_scope、版本、期限、撤销时间、auth_version 与恢复代际 | 一次授权有独立记录；权限扩张不影响已签发授权 |
| OAuth 绑定 | code/token/refresh 标识、grant_id、授权版本与令牌族 | 全流程绑定原 grant；不存不必要的令牌明文 |
| 操作计划 | plan_id、动作、规范化输入摘要、资源版本、成本上限、grant、期限、状态 | 计划生成后内容不可变；变更产生新计划 |
| 批准记录 | plan_id、可信批准人、批准时间、摘要与版本 | 只能由可信页面或经过专项验证的客户端确认机制产生 |
| 操作收据及派发记录 | operation_id、稳定请求 ID、计划、执行状态、资源 ID、outbox/派发标识 | 与业务变化一致；重复消费不能新增同一效果 |
| 审计事件 | 工具、身份、资源、时间、耗时、错误分类与安全摘要 | 不记录密码、完整 Token、聊天全文或默认保存正文 |

建议 grant 状态为 active、revoked、expired；计划状态为 prepared、approved、executing、succeeded、failed、unknown、expired 或 cancelled。收据、工作执行与外部效果使用独立字段，不能用一个 status 覆盖全部语义。

现有收据唯一键为实例、管理员和稳定请求 ID，动作及输入进入摘要比较。扩展到计划时保留该去重边界并绑定计划与载荷摘要；相同 ID 不同内容冲突。刷新或重新认证后的收据查询仅在操作者和原授权关系可证明时允许，不能靠相同邮箱续接；撤销后可在可信后台查询审计，不因此恢复 MCP 访问。

## 8. 后台连接管理与部署地址

新增“AI 连接”页面，提供启用状态、规范 MCP 地址、授权记录、当前能力、到期时间、最近访问、撤销和诊断。授权与批准页使用 admin 登录、CSRF 及必要的重新认证；GET 只展示，不修改业务。用户不需要把密码、验证码或访问 Token 粘贴到聊天。

根路径示例：`https://geo.example.com/mcp`。子目录示例：`https://geo.example.com/geoflow/mcp`。这些只是拟议外部地址，实际路径经部署验证。HTML 后台地址不能充当 MCP 端点；自定义后台前缀只影响管理页面，不应隐式改变已登记的 MCP 资源。

规范 resource 来自可信配置，不能从未经校验的 Host 或转发头构造。包含路径的资源 metadata 发现应按规范映射，并在 401 中明确 resource_metadata 地址；授权服务 issuer、其 metadata 与回调也要保持一致。[S06] 子目录不能只做字符串拼接，需要专项验收。

诊断检查 TLS、路由、metadata 一致性、组件/数据库准备状态和队列可用性，仅返回脱敏结果。诊断只针对本站已配置端点，不提供任意地址探测。不得让 WAF 验证码、HTML 登录重定向或错误的代理缓存阻断 MCP 机器请求；OAuth 登录页面继续保留浏览器安全保护。

## 9. MCP 协议、版本与工具契约

使用经组件和客户端验证的 Streamable HTTP；初始化、协议协商、工具发现、调用和错误响应遵循锁定版本。[S07] 不把资料引用的 2025-11-25 声明为当前唯一或最新版本；实施 PR 记录实际协议版本及兼容测试。

区分 mcp_protocol_version、management_contract_version、core_version、tool_schema_version 与 contract_hash。现有管理接口的 protocol_version=1.0 不能复制到 MCP initialize 响应。原生工具通过已审查的注册表映射共享服务；不需要通过回环 HTTP 读取 auth/session 才建立身份。

所有业务工具需要认证；公开 metadata 只提供接入必需信息。Session ID 仅作传输标识，不能代替身份。跨请求共享进程、容器单例和工具缓存不得保留上一用户的上下文。启用会话模式时验证重连、重启、过期、用户隔离和负载均衡行为。

每个工具定义严格 inputSchema、outputSchema、稳定名称、触发条件、权限、副作用、错误及 annotations。[S05] 未知参数默认拒绝；模型不能提供 base_url、admin_id、grant_id、认证头、SQL 或通用路由。合法业务 ID 来自工具返回，并在每次调用重新授权。

| 工具组 | 拟议工具 | 数据/应用服务来源 | 阶段 |
| --- | --- | --- | --- |
| 实例与目录 | get_connection_status、get_catalog、list_sites | 共享身份/能力描述、目录与站点查询；保留现有可见性 | P0 |
| 文章 | list_articles、get_article | 现有文章查询加授权、分页与字段投影 | P0 |
| 质检 | get_article_quality_status、get_article_quality_detail | 轻量状态与完整证据分开返回 | P0 |
| 任务 | list_tasks、get_task、list_task_jobs、get_job | 任务与执行记录查询，保留 viewer 语义 | P0 |
| 素材 | get_material_summary | 素材摘要，默认不整库导出 | P0 |
| 运营分析 | query_operational_logs、get_operational_metrics | 新增授权查询、时间口径和聚合服务 | P0A，可在只读接通后独立交付 |
| 草稿 | prepare_draft_change、commit_draft_change | 新增计划与草稿命令服务 | P1 |
| 操作核对 | get_operation | 计划/收据关联及当前权限检查 | P1 起 |
| 任务配置 | prepare_generation_task、create_generation_task | 固定待审、禁用调度和分发的任务计划与提交 | P2 |
| 任务执行 | prepare_generation_run、enqueue_generation、stop_generation | 预算、执行快照、队列与明确的取消语义 | P2 |
| 发布 | prepare_publication、commit_publication | 可信批准、质量与渠道约束 | P3 |

这些业务工具均待实现。现有 REST 路径仅用于契约对照，不能证明业务 MCP HTTP 入口已实现。注册表中的 pending 项不暴露；业务能力、权限或契约无法确认时返回 unsupported 或明确拒绝，不回退到更宽泛 API。

只读工具不改变业务状态、不启动生成或质检；必要审计与限流记账明确登记。prepare 类工具会持久化计划，应设置 readOnlyHint=false；commit 类按实际副作用标注。业务范围受限的私有查询与公开互联网访问分别判断 openWorldHint，生成调用外部供应商时不得隐瞒外部副作用。annotations 不承担权限或批准判断。

### 9.1 草稿工具示例契约

以下为待实现的参数示例：

```json
{
  "mode": "update",
  "article_id": 123,
  "expected_revision": 7,
  "changes": {
    "title": "待审标题",
    "content": "待审正文"
  }
}
```

prepare 返回 plan_id、规范化差异、输入摘要、预估副作用、有效期和可信确认页面位置。create 模式不接收 article_id 或 expected_revision；update 模式二者必填。changes 逐字段白名单，嵌套 additionalProperties=false。提交工具仅接收 plan_id，由服务端重新校验并消费批准，不能接受新的正文或 confirmed=true 覆盖既有计划。

### 9.2 结果与错误

```json
{
  "schema_version": "proposed-v1",
  "instance_id": "example-instance",
  "operation_id": "example-operation",
  "operation_state": "accepted",
  "work_state": "queued",
  "effects_state": "not_started",
  "resource_ids": [],
  "request_id": "example-request",
  "as_of": "2026-10-25T00:00:00Z",
  "warnings": [],
  "next_action": "get_operation"
}
```

业务结果通过 MCP 结构化结果及必要的可读文本返回；工具业务失败采用 SDK 支持的工具错误格式，并保留机器可读 error_code。认证错误在 HTTP 层返回恰当挑战。计划冲突、资源版本冲突、授权撤销、预算不足、功能关闭、结果未知与网络故障分别编码；不向模型暴露异常堆栈、数据库信息或密钥。

## 10. 安全草稿与可信批准

当前通用更新已有锁内版本比较、已发布内容权限和质量/审核门禁，内容变更会进入工作流服务。草稿工具仍须提供明确的窄契约：P1 在共享应用服务及 Core 事务中同时验证管理员、连接、资源、当前状态和 expected_revision，锁定真实文章行后修改。已发布、删除或受保护状态一律冲突，不隐式下架。

工具契约中的 revision 优先评估映射到已有 workflow_version。现有 contentChanged 和人工迁移会推进版本，config_version 另用于特定质检配置。实施时盘点 Web、REST、MCP、Worker 及批量更新的字段与版本推进，补齐所有影响计划的写入口，并验证强前置条件；本轮未证明统一全文 CAS 已完成。确需其他版本语义时先说明差异，避免机械新增重复计数器。

允许字段逐项确认，例如 title、content、excerpt、keywords、meta_description。status、review_status、task_id、slug、发布渠道和质量配置不混入普通草稿修改。正文按现有安全渲染与存储规则处理，防止通过草稿输入引入存储型脚本。

计划绑定动作、内容摘要、资源 revision、实例、管理员、grant 版本、授权代际、预算和期限。确认页面展示差异及可能的质检费用；批准后任何绑定条件变化均使计划失效。首版可信批准在 GEOFlow 页面完成，POST 受 CSRF 和当前账号保护；模型不能自签批准。ChatGPT 自己的确认提示属于额外保护，不能在没有可验证信号时充当服务端批准证据。

草稿保存若自动启动质检或其他付费过程，应纳入本次授权预算和收据；否则采用经验证的不自动启动模式。不能在工具描述中承诺零费用，同时让后台静默调用模型。

## 11. 生成任务、预算与停止语义

创建任务、开启调度、入队执行和完成生成是不同动作。P2 首次创建固定 need_review=true，关闭自动调度与对外分发；禁止用户输入通过嵌套参数改写它们。现有 TaskController 中的 reviewBoundTaskData 及 assertTaskExecutionScope 保护应提取到共享服务，REST 与 MCP 共同回归。

prepare_generation_task 生成配置计划；create_generation_task 只提交已批准的禁用态配置。prepare_generation_run 绑定任务 revision、模型、提示词、知识源、数量、渠道集合与预算；enqueue_generation 原子验证、预留预算并保存执行快照。Worker 按快照执行或遇到漂移停止，不能在入队之后重新读取一套更高权限的当前配置。

预算至少覆盖生成篇数、最大并发、输入输出 Token 上限、供应商重试、自动质检与优化附加消耗。金额上限只有在价格和计量可验证且实际可执行时才能承诺；否则明确报告估算，并使用篇数、Token 等硬限制。预留原子化，未知或仍运行的工作不能提前释放全部预算。

stop_generation 需要区分停止新调度、取消未开始 Job、协作中止已开始 Job。已经发给供应商的调用可能无法立即取消；已发生费用与生成结果保留。不能把现有 task stop 自动解释成已取消所有运行中工作。

ChatGPT 在对话中撰写正文并保存，与 GEOFlow 后台调用模型生成是两条路径。ChatGPT 订阅不被视为 GEOFlow 供应商 API 额度；连接本身不要求用户向后台提供 ChatGPT 会话 Cookie。后台生成继续使用已授权的 GEOFlow 模型配置，实际用量来源与未知费用明确标注。

## 12. 幂等、收据与派发一致性

现有 TaskController 在执行 scope 检查后，将带 X-Client-Request-Id 的 tasks.enqueue 交给 ManagementOperationService：收据绑定实例、管理员及请求 ID，比较操作/任务/job_type 摘要，持久关联 TaskRun；查询与重放复核所需 scope 和任务可见性。该能力当前覆盖任务入队，尚未覆盖草稿计划或全部外部效果。

优先从现有服务提取可信不可变执行上下文和共享授权边界，再接收原生计划的稳定请求 ID 并扩展同一收据存储。现有 ApiAuthContext 由 Sanctum 中间件注入 HTTP 请求，MCP 不能伪造 Request、Token 数组或请求头来调用它。旧 REST 的 X-Idempotency-Key 与收据 X-Client-Request-Id 保留各自契约，组合头继续拒绝。

业务记录、计划消费、收据和待派发工作采用同事务或等价可恢复机制。建议评估事务 outbox 及幂等 Worker：数据库已提交但派发失败时可恢复，派发成功但响应丢失时可核对。不得仅凭应用日志宣称全链路 exactly-once，尤其不对外部供应商和渠道作此保证。

同一计划并发提交最多产生一次本地业务效果；不同载荷复用 ID 报冲突。超时、502、崩溃、stale 记录、旧 Token 续接或恢复后收据 404 均可能对应 unknown。保留原 ID，查收据、Job 和实际资源，停止自动换 ID 重发。去重保留期与收据清理需要覆盖可重试窗口；清理不能让已消费计划再次执行。

operation_state、work_state、effects_state 分别表达受理、后台工作和外部效果。HTTP 成功、收据完成或获得 article_id 均不证明全部渠道发布成功。失败返回也可能已经留下草稿，需要报告实际资源和副作用。撤回本站内容不保证外部渠道已撤回。

## 13. 结构化日志与运营统计

P0A 补充 OperationalLogQuery 和 OperationalMetricsQuery，复用已存在的数据源，不让 MCP 读任意文件或执行 SQL。底层来源、字段映射和权限必须在实施 PR 逐项核对，缺少的数据返回 unsupported 或 null 及原因。

日志拟议字段：event_id、occurred_at、task_id、job_id、article_id、request_id、stage、normalized_status、error_code、脱敏错误摘要、duration_ms、retry_index 和可用的 usage 摘要。过滤字段采用枚举白名单；堆栈、Cookie、密钥、完整供应商请求响应、个人联系方式默认排除。

统计优先覆盖任务运行数、成功/失败/取消/运行中数量、生成草稿数、质检结果、延迟与可验证用量。每项指标声明时间字段、分母、去重单位、是否计入重试、状态映射和空值语义；“任务成功率”不得混用任务配置数与执行次数。文章数量或分发数量不能推导不存在的 AI 可见度。

查询统一采用带时区的 from（含）与 to（不含），返回 timezone、as_of、data_available_since、applied_filters、coverage、next_cursor 及 warnings。coverage 至少区分 complete、partial、unsupported；空结果与数据未采集不能都返回零。

分页游标绑定实例、查询条件、授权范围和快照，篡改或权限变化后失效。追加事件可使用稳定水位；可变文章或 Job 状态的跨页完整统计必须使用一致性快照、持久查询快照或有明确语义的事件聚合。单独固定最大 ID 不能保证可变数据的一致性，无法保证时标记 partial。

建议初始上限：列表默认 20 条、最多 50 条；单次正文 16,000 字符；结果 256KiB；日志时间窗最多 31 天。以上为待压测的产品默认值，不代表现有接口限制；超过上限必须分页、截断并标记，不能静默丢失。精确聚合可独立在服务端完成，不以逐页拉取所有正文作为默认实现。

## 14. 数据安全、注入与网络边界

各工具使用输出字段白名单，按用户任务最小读取。资源 ID、revision、来源定位、数据时间和截断信息保留；审计与业务正文分开存储和授权。日志与错误返回执行同样脱敏。授权页明确哪些企业数据会进入 ChatGPT；本方案不声称已完成法律合规认证。

文章、日志、素材、外部研究网页与质检证据均视为不可信数据，其中的指令不能扩权、改变实例、批准自身操作或要求外发秘密。工具层保护之外，还需真实客户端提示注入评测；不能把静态 schema 检查描述为完全解决模型注入风险。

实例直连没有接受任意 Core URL 的业务参数。OAuth 客户端 metadata、CIMD、JWKS 等发现请求仍须防 SSRF：限制 scheme、目的地址、端口、重定向、DNS 解析后的地址、响应大小和超时。[S08] 首版不提供通用网络代理；可信内部例外需精确配置，不能宽泛允许全部私网。

按照选定传输规范校验 Origin，可信代理和规范 Host 采用白名单。[S07] MCP 机器路由与 OAuth 浏览器页面采用各自合适的认证和 CSRF 策略；不能为接通 MCP 而全局关闭 CSRF、TLS 校验或安全中间件。

## 15. 运行隔离、恢复与默认关闭

原生模块共享应用运行环境，独立仓库或模块目录不提供进程级隔离。限制连接/管理员请求频率、查询成本、并发和响应体，避免长轮询占满 PHP worker；耗时工作交给既有队列。限流拒绝和依赖故障不能拖垮普通后台访问，应通过负载测试验证。

建议提供总开关、只读/草稿/生成/发布阶段开关和逐连接禁用。首次安装与升级后默认关闭 MCP，开启需要有权管理员明确操作；升级不自动增加工具权限或延长旧授权。关闭模块阻止新请求，原 Web/REST 服务继续运行；已开始工作按约定停止或返回真实状态。

安装阶段完成实例 ID 和 OAuth 密钥初始化。ManagementInstance::id 当前使用 firstOrCreate，不能让未准备的首次只读调用承担隐式实例初始化；初始化失败时拒绝提供业务工具。审计和限流记账可作为明确的基础设施副作用，业务读取不得新建文章、任务或触发模型。

恢复门禁优先评估现有 RecoveryState 的 epoch/host_id/phase，但只有证明其覆盖普通部署和所有恢复路径后才能依赖。无法保证时，另设不会随业务数据库回滚的授权代际，并在恢复程序强制旧 grant/批准失效；不得仅将撤销版本保存在同一可回滚数据库中。

复制数据库、迁移域名或新建测试环境可能复制 management_instance_id。新环境须重新初始化实例身份及密钥，改变 resource/issuer 时重新授权。旧授权不得因克隆、恢复或回滚复活。恢复和密钥轮换失败时保持 MCP 关闭，不能回退到共享超级管理员 Token。

## 16. 安装、升级与插件分发

实施版应交付原生模块与锁文件、环境变量说明、路由/metadata 配置、迁移与备份说明、连接页面、工具契约、测试报告和撤销手册。仅 Markdown 方案不能被称为可安装版本。

部署顺序：锁定兼容版本 → 在测试站备份并验证迁移 → 初始化每实例身份和密钥 → 配置 HTTPS 及代理 → 启用只读试点 → 完成 MCP Inspector 和真实 ChatGPT 验收 → 再开放已通过阶段。升级采用兼容性评估和必要的数据回填；回滚先关闭 MCP，确认旧应用能处理已迁移结构，不盲目删除授权审计表。

截至 2026-10-25，官方接入路径为 ChatGPT Plugins → plus → Add custom MCP server → 配置连接和认证 → Create as a plugin → 安装 → 在对话中通过@选择。[S03][S09] 目标账号、工作区权限、安全策略及工具调用仍需实测。公共部署验证公开 HTTPS 端点；本地开发可单独验证 Secure MCP Tunnel，目录发布另行验收。

此处验收自定义 MCP 连接在 ChatGPT 中的安装；D07 另验收仓库插件包、Skill 与分发。

插件代码置于 integrations/chatgpt，包含工作流、连接说明和经过验证的清单模板。portable 格式与兼容格式使用各自 schema；不能只改文件名。[S04] 公共模板不包含秘密或真实客户连接 ID。需要平台注册映射时在本地/组织安装步骤绑定真实 ID，不编造可用标识。

一个固定远程 URL 的公开插件不天然支持任意客户实例。首版采用每实例手动直连与可选本地/组织分发；统一插件填写任意地址的体验留作独立兼容验证。确需中心网关时另做凭据托管、租户路由、SSRF、域名绑定和跨实例授权设计。

## 17. ChatGPT 客户端兼容与失败降级

官方页面描述自定义 MCP 插件的读写接入，并说明账号、工作区权限及安全策略会约束使用。[S03][S09] 目标账号能否添加、安装、授权和调用具体工具，以实际组合验证；官方流程本身不证明 GEOFlow 已接通。

验收矩阵至少记录：账号套餐、个人/组织工作区、Web/桌面端、选定模型、普通对话/其他模式、OAuth 客户端方式、工具读取/写入/刷新、完整插件安装及同意页面。每个组合分别标记 PASS、FAIL、BLOCKED 或 NOT_RUN；不能用 API Playground 成功替代目标 ChatGPT 成功。

客户端不支持写入时保持只读并明确限制，允许用户在 GEOFlow 后台完成操作；禁止把写工具伪装成只读工具或放到 GET 链接中绕过平台限制。首个受控写入必须使用测试草稿，不对生产内容做能力探测。

已经入队的 GEOFlow 工作可由服务器继续执行；聊天关闭后，本方案不提供自动持续监控或主动通知。Webhook、定时运营与通知另行实现和授权，不凭 MCP 连接自动成立。

## 18. 实施 PR 拆分与开放门槛

以下编号是后续工作包，尚未创建相应实现 PR。维护者已批准本方案进入实施准备；本 PR 合并后仍可作为方案入口。各包先读仓库规则，保持小范围改动、明确依赖和实测证据。

| 工作包 | 交付与依赖 | 必须验证 |
| --- | --- | --- |
| D01：原生基础与身份 | 生产组件锁定、默认关闭、OAuth/Principal/Grant、可信执行上下文、连接与撤销页面 | P0 身份/协议、A49 至 A68 适用项；先与 D02 完成下述最小闭环 |
| D02：只读工具 | 依赖 D01；先实例与文章读取，再补目录、站点、任务、质检和素材投影 | A01 至 A20、相关运维及权限一致性、A77；最小闭环不代表完整 P0 已通过 |
| D03：运营查询 P0A | 依赖 D02；结构化日志、时间过滤、指标和快照 | A15 至 A19、A69 至 A72；不依赖先开放写入 |
| D04：安全草稿 P1 | 依赖 D02；复用并补齐 workflow_version 覆盖、计划、可信批准、现有收据扩展 | A21 至 A29、A73 至 A76 及真实写入 A78；包含保存触发质检的预算 |
| D05：受控生成 P2 | 依赖 D04；任务配置/执行计划、预算、快照、Worker 停止与恢复 | A30 至 A36、A72 及 A74 至 A78；回归已有审核保护 |
| D06：受控发布 P3 | 依赖 D05；质量、文章及渠道快照、逐渠道效果、可信批准 | A37 至 A40 及适用安全/恢复用例；保留渠道操作租约和删除保护 |
| D07：插件交付 P4 | 依赖已验收工具；Skill、清单、安装说明和评测 | A46、A77 至 A80；可独立于尚未开放的 P2/P3 功能交付 |

A41 至 A48 为全阶段运维与回归基础。验收清单共 80 项，全部 NOT_RUN；开放某阶段需前置阶段及适用新增项通过，不能只看区间内几项。实际 P0 单实例演示仍需第二账号和第二实例执行拒绝测试。

### 18.1 D01+D02 首个可交接读取闭环

开发包限定为生产组件及路由配置、管理员身份适配与 grant 撤销、可信上下文/共享授权提取，以及三个只读工具：get_connection_status、list_articles、get_article。交接用例简称 instance、articles.list、articles.get，仅用于对应这三个拟议工具，不表示现有管理注册表已有同名操作。目录、质检等后续只读投影仍归 D02；本包不增加草稿、生成、发布或插件包。

首包输出白名单：instance 返回 instance_id、core_version、management_contract_version、mcp_protocol_version 及允许的只读动作；articles.list 返回 id、title、status、review_status、workflow_version、updated_at 及分页信息；articles.get 在列表字段上增加 excerpt、content 与必要的截断标识。正文遵守第 13 节上限；不输出凭据、模型配置、原始供应商响应或无关个人信息。以上字段为待实施契约，需用 outputSchema 固定。

使用隔离 fixture 建立实例 A/B 和管理员 A/B，两个实例各有相同数字 ID、内容可辨认的文章。管理员 A 在实例 A 通过真实登录同意最小只读 grant，绑定实例 resource、articles:read 及必要连接动作，禁用全部业务写能力。

依次执行授权 → 读取 instance → articles.list → articles.get → 使用尚无文章读取 grant 的管理员 B 自身令牌，以及实例 B 令牌，尝试同 ID 访问 → 通过参数伪造 admin_id/grant_id → 撤销 A 的 grant → 使用旧 access token 调用及旧 refresh token 换取令牌。正常读取只能返回白名单；无授权、跨实例、伪造身份和撤销后调用均拒绝。管理员 B 另有合法授权时，仅返回其自身已批准范围；整实例共享文章遵守已同意的共享范围。

最小证据对应 A01 至 A10、A13、A14、A17、A20、A41、A45、A47、A49 至 A63、A66、A68、A77、A80 中相关断言；在验收报告逐项列出实际执行的子场景。A02/A03/A55/A56 覆盖 metadata、PKCE、精确 issuer/resource、grant 绑定；A08/A09/A51/A57 覆盖身份和实例隔离；A41/A68 覆盖撤销与旧令牌拒绝。若未发行 refresh token，记录该子场景 BLOCKED 及能力关闭，不能声称完整撤销链已通过。

保留提交与组件版本、协议/契约版本、脱敏 instance/admin/grant 标识、metadata/工具 schema 摘要、客户端/工作区配置、请求关联 ID、字段投影、预期与实际错误、撤销时间、access/refresh 拒绝证据及数据库/队列前后快照。全程不启动付费采集、质检、生成或发布；数据库/队列断言纳入 A58，基础设施审计单列。以上步骤均待实现和执行，当前仍为 NOT_RUN；完整 P0 开放另需其余适用验收。

## 19. 待实施 PR 回答的阻塞项

| 问题 | 默认处理 | 解除条件 |
| --- | --- | --- |
| MCP/Passport 版本和扩展点兼容 | 业务 MCP HTTP 入口保持关闭 | 依赖安装、身份/受众绑定和真实客户端证据 |
| 当前账号或模型写入支持不明 | 只读连接可独立交付 | 目标组合的 A78 通过 |
| 细粒度资源隔离未完整验证 | 明确整实例范围，保留已有可见性规则 | 全入口授权矩阵通过后才显示细粒度选项 |
| 全文 revision 与异步副作用不完整 | 不开放草稿提交 | 写入口盘点、并发和预算验收通过 |
| 日志/指标缺少底层数据 | unsupported 或 partial，不填充假零 | 真实字段映射、保留期和聚合对照 |
| 普通部署缺少可靠恢复代际 | MCP 关闭，先补恢复门禁 | 恢复、克隆和撤销演练通过 |
| 公开插件任意实例绑定未验证 | 单实例直连及已验证分发方式 | 客户端配置路径或另行评审的网关方案 |

## 20. 参考资料与证据说明

外部资料于 2026-10-25 搜索并打开核对，实施时仍需复查。编号仅用于本文与复核报告的定位；架构选择、数据模型、默认上限及阶段划分属于本项目设计。

- [S01 Laravel MCP](https://laravel.com/framework/docs/12.x/mcp)：原生 Web 入口、默认 OAuth scope 与组件边界。
- [S02 OpenAI 认证](https://developers.openai.com/plugins/build/auth)：MCP OAuth 与客户端识别。
- [S03 OpenAI 连接与测试](https://developers.openai.com/plugins/deploy/connect-chatgpt)：实例接入和分层验收。
- [S04 OpenAI 插件打包](https://developers.openai.com/plugins/build/plugins)：清单、工作流与平台注册映射。
- [S05 OpenAI 工具定义](https://developers.openai.com/plugins/plan/tools)：工具契约和副作用声明。
- [S06 MCP 授权规范参考版本](https://modelcontextprotocol.io/specification/2025-11-25/basic/authorization)：resource、metadata、PKCE 和令牌边界。
- [S07 MCP 传输规范参考版本](https://modelcontextprotocol.io/specification/2025-11-25/basic/transports)：HTTP 传输与安全要求。
- [S08 MCP 安全实践](https://modelcontextprotocol.io/docs/2025-11-25/tutorials/security/security_best_practices)：令牌、代理与发现安全。
- [S09 OpenAI 自定义 MCP 接入](https://developers.openai.com/api/docs/guides/custom-mcp-server)：认证配置、安装、使用及工作区限制。
- [S11 Laravel Passport](https://laravel.com/framework/docs/12.x/passport)：OAuth 组件适配时的基础资料。

已有源码观察详见第 3 节和复核报告中的固定提交链接。本文未运行应用、OAuth 或真实客户端测试；没有将文档一致性检查、CI 或组件官方示例视为业务接入验收。

[S01]: https://laravel.com/framework/docs/12.x/mcp
[S02]: https://developers.openai.com/plugins/build/auth
[S03]: https://developers.openai.com/plugins/deploy/connect-chatgpt
[S04]: https://developers.openai.com/plugins/build/plugins
[S05]: https://developers.openai.com/plugins/plan/tools
[S06]: https://modelcontextprotocol.io/specification/2025-11-25/basic/authorization
[S07]: https://modelcontextprotocol.io/specification/2025-11-25/basic/transports
[S08]: https://modelcontextprotocol.io/docs/2025-11-25/tutorials/security/security_best_practices
[S09]: https://developers.openai.com/api/docs/guides/custom-mcp-server
[S11]: https://laravel.com/framework/docs/12.x/passport
