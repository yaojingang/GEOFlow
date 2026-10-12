# GEOFlow原生MCP运营后台与ChatGPT接入方案

状态：架构方向已确认；实现方案待开发与验证。本文及PR#148仅修改文档，尚未提供可运行MCP、OAuth或插件。  
修订日期：2026-09-19。源码基线：`9ed2fe80457d5eb280a4bca7cf799895bf2ca3b1`。  
本轮接续的PR版本：`481394fc38b094bcf68c76092d67ee1489b391c3`。  
配套：[复核报告](../reviews/chatgpt-mcp-management-review.md) · [80项验收清单](chatgpt-mcp-management-acceptance.md)。

## 1. 架构决策与本次边界

采用**GEOFlow单仓库内置MCP模块、随站点部署、每个实例独立连接和授权**的主方案。ChatGPT插件与Skill负责使用引导和分发；独立MCP仓库、集中多租户网关留待确有独立发布或托管需求时另行决策。

本修订替代此前RFC中“独立TypeScript sidecar为首版默认形态”的选择。原生调用链不再要求MCP回环调用本站REST API，也不要求额外保存一套本站Sanctum Token。原有安全要求继续保留；变更的是实现边界，业务授权、原子草稿更新、预算、收据和撤销均不能省略。

本PR交付详细方案、实施拆分、风险与验收规格。没有新增运行代码、依赖、迁移、生产凭据、独立仓库或实际插件包；没有部署或合并。本次用户授权范围为补充原PR方案。

| 决策 | 首版要求 | 后续扩展条件 |
| --- | --- | --- |
| 仓库 | 在GEOFlow内模块化实现，使用同一应用版本 | 独立团队、独立发布或多产品复用有明确收益后拆包或拆仓 |
| 部署 | 复用站点运行环境和业务队列，不增加必需的Node服务 | 负载或故障隔离需要时可从同仓库拆进程 |
| 实例连接 | 一个连接绑定一个部署实例和一个已授权管理员 | 多连接分别授权；集中路由网关另做威胁模型 |
| 技术选型 | 优先验证Laravel MCP与Passport的原生组合 | 安装、协议或身份兼容不通过时记录ADR，再选择同栈替代组件 |
| 权限开放 | P0只读，草稿、生成、发布独立开关 | 对应阶段通过验收后才开放 |
| 插件 | 可选工作流与分发入口，连接可先独立使用 | 完整安装、组织分发、公开目录分别验收 |

## 2. 产品目标与明确不做的事

目标体验：部署兼容版本 → 后台启用AI连接 → 复制实例MCP地址 → 在ChatGPT登记并跳转GEOFlow登录授权 → 对话读取、分析和受控操作 → 后台核对结果及随时撤销。

| 用户请求示例 | 所需能力 | 成功应如何表述 |
| --- | --- | --- |
| 看看这篇文章为什么质检失败 | 文章与完整质检证据读取 | 引用文章ID、证据和数据时间；缺证据时说明限制 |
| 汇总最近七天失败的生成任务 | 结构化日志、时间过滤及聚合 | 返回统计口径、分母、截止时间与覆盖范围 |
| 修改这篇草稿，先让我确认差异 | 计划、可信批准、原子草稿提交 | 返回新revision、实际状态和操作收据 |
| 创建任务生成五篇文章，全部待审 | 任务计划、预算、冻结配置与队列 | 分别报告创建、入队、执行、质检状态 |
| 把已审核文章发布到选定渠道 | P3发布计划、批准与渠道门禁 | 按渠道报告实际效果，允许部分成功和结果未知 |

首版排除：任意Shell、SQL、PHP或HTTP代理；直接读取服务器文件；数据库管理；删除；Updater与备份恢复；主题原生代码编辑；用户与密钥管理；人工覆盖质量门禁；任意URL素材导入；浏览器外站自动发布。既有后台存在某个功能，不代表它应自动成为MCP工具。

ChatGPT联网能力与MCP能力各自受客户端控制。MCP不会自动获得ChatGPT浏览器或搜索权限；外部研究资料仅作为输入证据，进入GEOFlow后仍受内容审核和保存规则约束。

## 3. 已核对的源码基础与缺口

以下观察固定到源码基线，沿用原PR已有复核并补查原生身份与实例管理路径；静态观察不构成生产漏洞确认。

| 现有入口 | 可复用内容 | 原生接入注意事项 |
| --- | --- | --- |
| [composer.json](../../composer.json) | Laravel 12、PHP要求与Sanctum依赖 | Laravel MCP、Passport及锁文件须在实施PR验证，文档不承诺已安装 |
| [routes/api.php](../../routes/api.php) | 文章、任务、Job、素材、站点、会话和能力接口 | 复用业务契约，避免自动暴露全部路由 |
| [config/auth.php](../../config/auth.php)、[Admin](../../app/Models/Admin.php) | admin guard、admins provider及Sanctum Token | 默认web关联users；OAuth不能误用普通用户身份 |
| [BaseApiController](../../app/Http/Controllers/Api/V1/BaseApiController.php) | ApiAuthContext、活跃管理员与角色复核 | 直接调用业务服务不会自动执行这些入口检查 |
| [ManagementSessionController](../../app/Http/Controllers/Api/V1/ManagementSessionController.php) | Token与管理策略求交集、能力过滤 | 提取共享授权规则，不能伪造Sanctum上下文 |
| [ManagementInstance](../../app/Services/Api/ManagementInstance.php) | 实例ID、Core版本及恢复状态描述 | 现有protocol_version=1.0属于管理契约；实例ID初始化有数据库写入 |
| [ArticleController](../../app/Http/Controllers/Api/V1/ArticleController.php)、[ArticleGeoFlowService](../../app/Services/GeoFlow/ArticleGeoFlowService.php) | 文章与质检服务 | 草稿专用状态、内容revision、资源范围和字段投影需补齐 |
| [TaskController](../../app/Http/Controllers/Api/V1/TaskController.php) | need_review与发布权限联动、任务入队 | 控制器中的保护需下沉共享执行层；Worker快照和预算另验收 |
| [ManagementOperationRegistry](../../app/Support/Api/ManagementOperationRegistry.php) | 操作注册与部分契约 | 旧业务API未完整纳入，不能直接当作完整MCP工具表 |
| [IdempotencyService](../../app/Services/Api/IdempotencyService.php)、[CLI流程](../../.agents/skills/geoflow/references/remote-cli-workflow.md) | 幂等、收据、未知结果的既有约定 | 原生应用层保留语义，不依赖伪造HTTP请求头 |

现有轻量质检状态不含完整证据；文章列表没有通用起止日期过滤；已查看的API路由未提供通用运营日志检索与统计入口。对应能力需补充授权查询服务，不能凭额外参数或读取少量分页声称已实现。

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

MCP工具不得直接执行Eloquent写入或任意查询来绕过共享应用服务。数据库仍由GEOFlow现有数据访问层管理。只读查询也必须显式带入授权上下文；认证成功不能替代资源过滤。

以下目录和类名均为拟议结构，未创建：

```text
app/Mcp/Servers/GeoFlowServer.php
app/Mcp/Tools/{Connection,Articles,Tasks,Materials,Analytics}/
app/Services/Management/{ManagementExecutionContext,ManagementAuthorizer}.php
app/Services/Management/{DraftCommandService,GenerationCommandService}.php
app/Services/Management/{OperationPlanService,OperationReceiptService}.php
app/Services/Management/{OperationalLogQuery,OperationalMetricsQuery}.php
app/Services/Mcp/{McpIdentityResolver,McpGrantService,McpTokenBinding}.php
app/Http/Controllers/Admin/McpConnectionController.php
app/Models/{McpPrincipal,McpGrant}.php
routes/ai.php
config/mcp.php
integrations/chatgpt/{README.md,templates/,skills/,evals/}
tests/Feature/Mcp/
```

共享管理服务可以包装现有领域服务，避免第二套文章或任务实现。REST响应格式和原有CLI契约保持兼容；不要求首版重构所有后台模块，只提取本期开放能力实际依赖的授权与事务边界。

## 5. 统一执行上下文与权限模型

拟议ManagementExecutionContext包含：认证来源、可信principal标识、实际admin_id、instance_id、连接与grant版本、当前账号auth_version、资源范围、允许动作、request_id和恢复代际。对象只能由认证适配器构造；模型参数不能构造或覆盖它。

```text
有效业务权限 = 当前管理员业务权限 ∩ 当前连接允许动作
             ∩ 连接资源范围 ∩ 已实现工具白名单 ∩ 阶段开关
             ∩ 当前授权/恢复状态
```

REST上下文额外受当前Sanctum Token能力限制；原生MCP上下文受验证后的OAuth授权及其绑定grant限制。不得给原生路径虚构一个全权Core Token，再沿用旧公式。

每次调用在读取或写入前重查账号状态、角色、auth_version、grant_version、撤销状态及资源范围；高风险提交和Worker执行前再次验证。tools/list缓存不授予权限。权限扩大必须重新同意；现有令牌不得随账号升级或后台编辑grant而自动扩权。

业务动作可以复用articles:read、tasks:read等现有语义；drafts:write、operations:read、analytics:read等新增名称需显式登记和契约测试。它们是应用层动作，不能在默认OAuth metadata里虚报为已支持的OAuth scopes。

P0只承诺明确授权的整实例共享运营资料，并继续保留已有模型、提示词和知识库可见性限制。只有完成所有查询、详情、关联资源、计数和写入的范围校验后，授权页才能提供site_ids等更细粒度选择。site_id参数和拥有站点列表权限均不能替代数据隔离。

## 6. OAuth实现路线与身份共存

### 6.1 组件选择和上线前验证

Laravel官方MCP提供Web服务与OAuth集成，可作为原生候选；其默认Mcp::oauthRoutes流程使用mcp:use，不能直接表达全部业务权限。[S01] 第一份实施PR必须验证PHP最低支持版本、现有Laravel版本、MCP组件与Passport依赖锁定、Admin身份适配、resource绑定以及真实客户端互通。验证失败时保留关闭状态，先记录具体兼容缺口。

不要把两套HasApiTokens trait直接混入现有Admin，也不要直接修改默认web guard。优先验证独立McpPrincipal及专用OAuth provider/guard：principal保存不可变subject及对Admin的引用，不复制密码、角色或权限；同意页面仍要求有效admin登录态。OAuth服务只通过可信登录流程建立该引用。该桥接方式是拟议设计，需验证Passport和Laravel MCP的实际扩展点；组件默认行为不能当作已经完成桥接。

同时验证授权码、访问令牌和刷新令牌都绑定到同一个grant。仅按admin_id或client_id查找“最新授权”会把多个连接混在一起，明确禁止。不得要求OIDC ID Token作为必需品；本地OAuth主体可以通过已验证令牌及服务端记录解析。业务调用使用访问令牌，不能把ID Token当成访问令牌。

### 6.2 实例授权流程

1. 管理员在本站启用MCP，完成实例身份、规范地址、OAuth密钥和允许客户端配置。
2. ChatGPT连接实例MCP URL；未认证请求获得符合选定协议的401挑战与受保护资源metadata。
3. 客户端发现本站授权服务；按实测能力使用预注册、CIMD或DCR。不能宣称组件默认支持全部方式。
4. GEOFlow显示admin登录及同意页面，列出客户端、实例、数据范围、业务动作、有效期与外发说明。
5. 服务端从当前登录态解析管理员，创建独立grant，将授权码绑定principal、client、resource、grant版本和PKCE。
6. 客户端交换授权码，获得仅可访问当前MCP资源的访问令牌；每次工具调用验证其绑定与当前权限。
7. 后台可以缩减或撤销grant；扩权需新一轮同意。刷新只延续原grant，不得跳转到其他连接或扩大权限。

OAuth授权码与PKCE S256、回调精确匹配、state处理、resource与受众校验、签名或可信令牌内省、期限和允许算法按选定实现验证。[S02][S06] 单有Passport默认Token签名校验不足以证明resource绑定已经满足要求；跨实例重放测试是硬门槛。

mcp:use只作为连接层许可。细粒度动作与资源记录在服务端grant，并在每次调用中执行。认证失败、OAuth scope不足、业务动作拒绝分别返回恰当错误；业务权限不足时不无限触发同一个mcp:use授权循环。

### 6.3 凭据与密钥

每个实例持有自己的OAuth签名或验证配置；不在镜像、Git、插件包、日志或模型输出中提供秘密。原生MCP不额外签发本站Sanctum Token。旧REST Token不得在MCP入口被当成OAuth令牌，MCP令牌也不能被旧REST路径静默接受。原有CLI、浏览器运营助手和后台会话需要回归。

是否发行refresh token、期限与轮换策略在实施PR中明确。只有支持并验证时才广告对应能力；不支持时明确需要重新登录。撤销必须覆盖该grant的授权码、访问与刷新令牌，不能误撤销其他连接或CLI凭据。

## 7. 拟议数据模型与生命周期

以下为逻辑实体，可复用现有可靠存储；不要求机械新增全部表。本PR不执行迁移。

| 逻辑实体 | 必需信息 | 不变量 |
| --- | --- | --- |
| MCP principal | subject、admin引用、instance_id | 服务端创建、映射不可被请求参数修改，无重复密码体系 |
| MCP grant | grant_id、principal、client、resource、actions、resource_scope、版本、期限、撤销时间、auth_version与恢复代际 | 一次授权有独立记录；权限扩张不影响已签发授权 |
| OAuth绑定 | code/token/refresh标识、grant_id、授权版本与令牌族 | 全流程绑定原grant；不存不必要的令牌明文 |
| 操作计划 | plan_id、动作、规范化输入摘要、资源版本、成本上限、grant、期限、状态 | 计划生成后内容不可变；变更产生新计划 |
| 批准记录 | plan_id、可信批准人、批准时间、摘要与版本 | 只能由可信页面或经过专项验证的客户端确认机制产生 |
| 操作收据及派发记录 | operation_id、稳定请求ID、计划、执行状态、资源ID、outbox/派发标识 | 与业务变化一致；重复消费不能新增同一效果 |
| 审计事件 | 工具、身份、资源、时间、耗时、错误分类与安全摘要 | 不记录密码、完整Token、聊天全文或默认保存正文 |

建议grant状态为active、revoked、expired；计划状态为prepared、approved、executing、succeeded、failed、unknown、expired或cancelled。收据、工作执行与外部效果使用独立字段，不能用一个status覆盖全部语义。

去重唯一约束应包含实例、可信操作者、动作和稳定请求ID，并将计划与载荷摘要关联。相同ID不同内容冲突。刷新或重新认证后的收据查询仅在操作者和原授权关系可证明时允许，不能靠相同邮箱续接；撤销后可在可信后台查询审计，不因此恢复MCP访问。

## 8. 后台连接管理与部署地址

新增“AI连接”页面，提供启用状态、规范MCP地址、授权记录、当前能力、到期时间、最近访问、撤销和诊断。授权与批准页使用admin登录、CSRF及必要的重新认证；GET只展示，不修改业务。用户不需要把密码、验证码或访问Token粘贴到聊天。

根路径示例：`https://geo.example.com/mcp`。子目录示例：`https://geo.example.com/geoflow/mcp`。这些只是拟议外部地址，实际路径经部署验证。HTML后台地址不能充当MCP端点；自定义后台前缀只影响管理页面，不应隐式改变已登记的MCP资源。

规范resource来自可信配置，不能从未经校验的Host或转发头构造。包含路径的资源metadata发现应按规范映射，并在401中明确resource_metadata地址；授权服务issuer、其metadata与回调也要保持一致。[S06] 子目录不能只做字符串拼接，需要专项验收。

诊断检查TLS、路由、metadata一致性、组件/数据库准备状态和队列可用性，仅返回脱敏结果。诊断只针对本站已配置端点，不提供任意地址探测。不得让WAF验证码、HTML登录重定向或错误的代理缓存阻断MCP机器请求；OAuth登录页面继续保留浏览器安全保护。

## 9. MCP协议、版本与工具契约

使用经组件和客户端验证的Streamable HTTP；初始化、协议协商、工具发现、调用和错误响应遵循锁定版本。[S07] 不把资料引用的2025-11-25声明为当前唯一或最新版本；实施PR记录实际协议版本及兼容测试。

区分mcp_protocol_version、management_contract_version、core_version、tool_schema_version与contract_hash。现有管理接口的protocol_version=1.0不能复制到MCP initialize响应。原生工具通过已审查的注册表映射共享服务；不需要通过回环HTTP读取auth/session才建立身份。

所有业务工具需要认证；公开metadata只提供接入必需信息。Session ID仅作传输标识，不能代替身份。跨请求共享进程、容器单例和工具缓存不得保留上一用户的上下文。启用会话模式时验证重连、重启、过期、用户隔离和负载均衡行为。

每个工具定义严格inputSchema、outputSchema、稳定名称、触发条件、权限、副作用、错误及annotations。[S05] 未知参数默认拒绝；模型不能提供base_url、admin_id、grant_id、认证头、SQL或通用路由。合法业务ID来自工具返回，并在每次调用重新授权。

| 工具组 | 拟议工具 | 数据/应用服务来源 | 阶段 |
| --- | --- | --- | --- |
| 实例与目录 | get_connection_status、get_catalog、list_sites | 共享身份/能力描述、目录与站点查询；保留现有可见性 | P0 |
| 文章 | list_articles、get_article | 现有文章查询加授权、分页与字段投影 | P0 |
| 质检 | get_article_quality_status、get_article_quality_detail | 轻量状态与完整证据分开返回 | P0 |
| 任务 | list_tasks、get_task、list_task_jobs、get_job | 任务与执行记录查询，保留viewer语义 | P0 |
| 素材 | get_material_summary | 素材摘要，默认不整库导出 | P0 |
| 运营分析 | query_operational_logs、get_operational_metrics | 新增授权查询、时间口径和聚合服务 | P0A，可在只读接通后独立交付 |
| 草稿 | prepare_draft_change、commit_draft_change | 新增计划与草稿命令服务 | P1 |
| 操作核对 | get_operation | 计划/收据关联及当前权限检查 | P1起 |
| 任务配置 | prepare_generation_task、create_generation_task | 固定待审、禁用调度和分发的任务计划与提交 | P2 |
| 任务执行 | prepare_generation_run、enqueue_generation、stop_generation | 预算、执行快照、队列与明确的取消语义 | P2 |
| 发布 | prepare_publication、commit_publication | 可信批准、质量与渠道约束 | P3 |

这些工具均待实现。现有REST路径仅用于契约对照，不能当成MCP已经存在的证明。注册表中的pending项不暴露；业务能力、权限或契约无法确认时返回unsupported或明确拒绝，不回退到更宽泛API。

只读工具不改变业务状态、不启动生成或质检；必要审计与限流记账明确登记。prepare类工具会持久化计划，应设置readOnlyHint=false；commit类按实际副作用标注。业务范围受限的私有查询与公开互联网访问分别判断openWorldHint，生成调用外部供应商时不得隐瞒外部副作用。annotations不承担权限或批准判断。

### 9.1 草稿工具示例契约

以下是拟议参数格式，不是可直接调用的现有接口：

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

prepare返回plan_id、规范化差异、输入摘要、预估副作用、有效期和可信确认页面位置。create模式不接收article_id或expected_revision；update模式二者必填。changes逐字段白名单，嵌套additionalProperties=false。提交工具仅接收plan_id，由服务端重新校验并消费批准，不能接受新的正文或confirmed=true覆盖既有计划。

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
  "as_of": "2026-09-19T00:00:00Z",
  "warnings": [],
  "next_action": "get_operation"
}
```

业务结果通过MCP结构化结果及必要的可读文本返回；工具业务失败采用SDK支持的工具错误格式，并保留机器可读error_code。认证错误在HTTP层返回恰当挑战。计划冲突、资源版本冲突、授权撤销、预算不足、功能关闭、结果未知与网络故障分别编码；不向模型暴露异常堆栈、数据库信息或密钥。

## 10. 安全草稿与可信批准

当前普通文章更新可能把风险字段变化后的文章归一为draft/pending；它不能直接满足只修改草稿的窄契约。P1在共享应用服务及Core事务中同时验证管理员、连接、资源、当前状态和expected_revision，锁定真实文章行后修改。已发布、删除或受保护状态一律冲突，不隐式下架。

统一内容revision或等价强前置条件覆盖Web、REST、MCP、Worker及批量更新路径。现有config_version用于特定质检配置，不能替代全文版本。需要完成写入口盘点；未覆盖的写入口仍可能造成丢失更新，不能仅新增一个MCP计数器就开放P1。

允许字段逐项确认，例如title、content、excerpt、keywords、meta_description。status、review_status、task_id、slug、发布渠道和质量配置不混入普通草稿修改。正文按现有安全渲染与存储规则处理，防止通过草稿输入引入存储型脚本。

计划绑定动作、内容摘要、资源revision、实例、管理员、grant版本、授权代际、预算和期限。确认页面展示差异及可能的质检费用；批准后任何绑定条件变化均使计划失效。首版可信批准在GEOFlow页面完成，POST受CSRF和当前账号保护；模型不能自签批准。ChatGPT自己的确认提示属于额外保护，不能在没有可验证信号时充当服务端批准证据。

草稿保存若自动启动质检或其他付费过程，应纳入本次授权预算和收据；否则采用经验证的不自动启动模式。不能在工具描述中承诺零费用，同时让后台静默调用模型。

## 11. 生成任务、预算与停止语义

创建任务、开启调度、入队执行和完成生成是不同动作。P2首次创建固定need_review=true，关闭自动调度与对外分发；禁止用户输入通过嵌套参数改写它们。现有TaskController中的reviewBoundTaskData及assertTaskExecutionScope保护应提取到共享服务，REST与MCP共同回归。

prepare_generation_task生成配置计划；create_generation_task只提交已批准的禁用态配置。prepare_generation_run绑定任务revision、模型、提示词、知识源、数量、渠道集合与预算；enqueue_generation原子验证、预留预算并保存执行快照。Worker按快照执行或遇到漂移停止，不能在入队之后重新读取一套更高权限的当前配置。

预算至少覆盖生成篇数、最大并发、输入输出Token上限、供应商重试、自动质检与优化附加消耗。金额上限只有在价格和计量可验证且实际可执行时才能承诺；否则明确报告估算，并使用篇数、Token等硬限制。预留原子化，未知或仍运行的工作不能提前释放全部预算。

stop_generation需要区分停止新调度、取消未开始Job、协作中止已开始Job。已经发给供应商的调用可能无法立即取消；已发生费用与生成结果保留。不能把现有task stop自动解释成已取消所有运行中工作。

ChatGPT在对话中撰写正文并保存，与GEOFlow后台调用模型生成是两条路径。ChatGPT订阅不被视为GEOFlow供应商API额度；连接本身不要求用户向后台提供ChatGPT会话Cookie。后台生成继续使用已授权的GEOFlow模型配置，实际用量来源与未知费用明确标注。

## 12. 幂等、收据与派发一致性

原生应用层接收由持久计划产生的稳定请求ID，传给共享收据服务。旧REST的X-Idempotency-Key与新收据X-Client-Request-Id继续各自遵守契约，不能同时使用或静默删除保护；原生路径不需要伪造这两个HTTP头。

业务记录、计划消费、收据和待派发工作采用同事务或等价可恢复机制。建议评估事务outbox及幂等Worker：数据库已提交但派发失败时可恢复，派发成功但响应丢失时可核对。不得仅凭应用日志宣称全链路exactly-once，尤其不对外部供应商和渠道作此保证。

同一计划并发提交最多产生一次本地业务效果；不同载荷复用ID报冲突。超时、502、崩溃、stale记录、旧Token续接或恢复后收据404均可能对应unknown。保留原ID，查收据、Job和实际资源，停止自动换ID重发。去重保留期与收据清理需要覆盖可重试窗口；清理不能让已消费计划再次执行。

operation_state、work_state、effects_state分别表达受理、后台工作和外部效果。HTTP成功、收据完成或获得article_id均不证明全部渠道发布成功。失败返回也可能已经留下草稿，需要报告实际资源和副作用。撤回本站内容不保证外部渠道已撤回。

## 13. 结构化日志与运营统计

P0A补充OperationalLogQuery和OperationalMetricsQuery，复用已存在的数据源，不让MCP读任意文件或执行SQL。底层来源、字段映射和权限必须在实施PR逐项核对，缺少的数据返回unsupported或null及原因。

日志拟议字段：event_id、occurred_at、task_id、job_id、article_id、request_id、stage、normalized_status、error_code、脱敏错误摘要、duration_ms、retry_index和可用的usage摘要。过滤字段采用枚举白名单；堆栈、Cookie、密钥、完整供应商请求响应、个人联系方式默认排除。

统计优先覆盖任务运行数、成功/失败/取消/运行中数量、生成草稿数、质检结果、延迟与可验证用量。每项指标声明时间字段、分母、去重单位、是否计入重试、状态映射和空值语义；“任务成功率”不得混用任务配置数与执行次数。文章数量或分发数量不能推导不存在的AI可见度。

查询统一采用带时区的from（含）与to（不含），返回timezone、as_of、data_available_since、applied_filters、coverage、next_cursor及warnings。coverage至少区分complete、partial、unsupported；空结果与数据未采集不能都返回零。

分页游标绑定实例、查询条件、授权范围和快照，篡改或权限变化后失效。追加事件可使用稳定水位；可变文章或Job状态的跨页完整统计必须使用一致性快照、持久查询快照或有明确语义的事件聚合。单独固定最大ID不能保证可变数据的一致性，无法保证时标记partial。

建议初始上限：列表默认20条、最多50条；单次正文16,000字符；结果256KiB；日志时间窗最多31天。以上为待压测的产品默认值，不代表现有接口限制；超过上限必须分页、截断并标记，不能静默丢失。精确聚合可独立在服务端完成，不以逐页拉取所有正文作为默认实现。

## 14. 数据安全、注入与网络边界

各工具使用输出字段白名单，按用户任务最小读取。资源ID、revision、来源定位、数据时间和截断信息保留；审计与业务正文分开存储和授权。日志与错误返回执行同样脱敏。授权页明确哪些企业数据会进入ChatGPT；本方案不声称已完成法律合规认证。

文章、日志、素材、外部研究网页与质检证据均视为不可信数据，其中的指令不能扩权、改变实例、批准自身操作或要求外发秘密。工具层保护之外，还需真实客户端提示注入评测；不能把静态schema检查描述为完全解决模型注入风险。

实例直连没有接受任意Core URL的业务参数。OAuth客户端metadata、CIMD、JWKS等发现请求仍须防SSRF：限制scheme、目的地址、端口、重定向、DNS解析后的地址、响应大小和超时。[S08] 首版不提供通用网络代理；可信内部例外需精确配置，不能宽泛允许全部私网。

按照选定传输规范校验Origin，可信代理和规范Host采用白名单。[S07] MCP机器路由与OAuth浏览器页面采用各自合适的认证和CSRF策略；不能为接通MCP而全局关闭CSRF、TLS校验或安全中间件。

## 15. 运行隔离、恢复与默认关闭

原生模块共享应用运行环境，独立仓库或模块目录不提供进程级隔离。限制连接/管理员请求频率、查询成本、并发和响应体，避免长轮询占满PHP worker；耗时工作交给既有队列。限流拒绝和依赖故障不能拖垮普通后台访问，应通过负载测试验证。

建议提供总开关、只读/草稿/生成/发布阶段开关和逐连接禁用。首次安装与升级后默认关闭MCP，开启需要有权管理员明确操作；升级不自动增加工具权限或延长旧授权。关闭模块阻止新请求，原Web/REST服务继续运行；已开始工作按约定停止或返回真实状态。

安装阶段完成实例ID和OAuth密钥初始化。ManagementInstance::id当前使用firstOrCreate，不能让未准备的首次只读调用承担隐式实例初始化；初始化失败时拒绝提供业务工具。审计和限流记账可作为明确的基础设施副作用，业务读取不得新建文章、任务或触发模型。

恢复门禁优先评估现有RecoveryState的epoch/host_id/phase，但只有证明其覆盖普通部署和所有恢复路径后才能依赖。无法保证时，另设不会随业务数据库回滚的授权代际，并在恢复程序强制旧grant/批准失效；不得仅将撤销版本保存在同一可回滚数据库中。

复制数据库、迁移域名或新建测试环境可能复制management_instance_id。新环境须重新初始化实例身份及密钥，改变resource/issuer时重新授权。旧授权不得因克隆、恢复或回滚复活。恢复和密钥轮换失败时保持MCP关闭，不能回退到共享超级管理员Token。

## 16. 安装、升级与插件分发

实施版应交付原生模块与锁文件、环境变量说明、路由/metadata配置、迁移与备份说明、连接页面、工具契约、测试报告和撤销手册。仅Markdown方案不能被称为可安装版本。

部署顺序：锁定兼容版本 → 在测试站备份并验证迁移 → 初始化每实例身份和密钥 → 配置HTTPS及代理 → 启用只读试点 → 完成MCP Inspector和真实ChatGPT验收 → 再开放已通过阶段。升级采用兼容性评估和必要的数据回填；回滚先关闭MCP，确认旧应用能处理已迁移结构，不盲目删除授权审计表。

ChatGPT连接按当前官方页面操作，在开发者模式登记包括/mcp路径的端点并授权。[S03] 页面位置、套餐、工作区策略和模型支持记录到验收报告。公开HTTPS与受支持的开发隧道分别测试；开发隧道可用不能当作公开目录发布已通过。

插件代码置于integrations/chatgpt，包含工作流、连接说明和经过验证的清单模板。portable格式与兼容格式使用各自schema；不能只改文件名。[S04] 公共模板不包含秘密或真实客户连接ID。需要平台注册映射时在本地/组织安装步骤绑定真实ID，不编造可用标识。

一个固定远程URL的公开插件不天然支持任意客户实例。首版采用每实例手动直连与可选本地/组织分发；统一插件填写任意地址的体验留作独立兼容验证。确需中心网关时另做凭据托管、租户路由、SSRF、域名绑定和跨实例授权设计。

## 17. ChatGPT客户端兼容与失败降级

当前开发者文档列出Web开发者模式的Pro等账号及读写工具；帮助中心仍保留Pro仅read/fetch和部分模式限制的说明。[S09][S10] 文档口径存在差异，本方案不推断某个具体账号、Pro模型或对话模式已经获得全部能力。

验收矩阵至少记录：账号套餐、个人/组织工作区、Web/桌面端、选定模型、普通对话/其他模式、OAuth客户端方式、工具读取/写入/刷新、完整插件安装及同意页面。每个组合分别标记PASS、FAIL、BLOCKED或NOT_RUN；不能用API Playground成功替代目标ChatGPT成功。

客户端不支持写入时保持只读并明确限制，允许用户在GEOFlow后台完成操作；禁止把写工具伪装成只读工具或放到GET链接中绕过平台限制。首个受控写入必须使用测试草稿，不对生产内容做能力探测。

已经入队的GEOFlow工作可由服务器继续执行；聊天关闭后，本方案不提供自动持续监控或主动通知。Webhook、定时运营与通知另行实现和授权，不凭MCP连接自动成立。

## 18. 实施PR拆分与开放门槛

以下编号是工作包，不代表已创建新的GitHub PR。各包先读仓库规则，保持小范围改动、明确依赖和实测证据；原PR#148作为方案入口保持开放。

| 工作包 | 交付与依赖 | 必须验证 |
| --- | --- | --- |
| D01：原生基础与身份 | 组件锁定、默认关闭、OAuth/Principal/Grant、共享授权、连接页面 | P0身份/协议、A49至A68适用项、真实读取A77 |
| D02：只读工具 | 依赖D01；文章、目录、站点、任务、质检和素材投影 | A01至A20、相关运维及权限一致性；至少完整单实例读取闭环 |
| D03：运营查询P0A | 依赖D02；结构化日志、时间过滤、指标和快照 | A15至A19、A69至A72；不依赖先开放写入 |
| D04：安全草稿P1 | 依赖D02；全写入口revision、计划、可信批准、收据 | A21至A29、A73至A76及真实写入A78；包含保存触发质检的预算 |
| D05：受控生成P2 | 依赖D04；任务配置/执行计划、预算、快照、Worker停止与恢复 | A30至A36、A72及A74至A78；回归已有审核保护 |
| D06：受控发布P3 | 依赖D05；质量、文章及渠道快照、逐渠道效果、可信批准 | A37至A40及适用安全/恢复用例；保留渠道操作租约和删除保护 |
| D07：插件交付P4 | 依赖已验收工具；Skill、清单、安装说明和评测 | A46、A77至A80；可独立于尚未开放的P2/P3功能交付 |

A41至A48为全阶段运维与回归基础。验收清单共80项，全部NOT_RUN；开放某阶段需前置阶段及适用新增项通过，不能只看区间内几项。实际P0单实例演示仍需第二账号和第二实例执行拒绝测试。

## 19. 待实施PR回答的阻塞项

| 问题 | 默认处理 | 解除条件 |
| --- | --- | --- |
| MCP/Passport版本和扩展点兼容 | 组件与运行入口保持关闭 | 依赖安装、身份/受众绑定和真实客户端证据 |
| 当前账号或模型写入支持不明 | 只读连接可独立交付 | 目标组合的A78通过 |
| 细粒度资源隔离未完整验证 | 明确整实例范围，保留已有可见性规则 | 全入口授权矩阵通过后才显示细粒度选项 |
| 全文revision与异步副作用不完整 | 不开放草稿提交 | 写入口盘点、并发和预算验收通过 |
| 日志/指标缺少底层数据 | unsupported或partial，不填充假零 | 真实字段映射、保留期和聚合对照 |
| 普通部署缺少可靠恢复代际 | MCP关闭，先补恢复门禁 | 恢复、克隆和撤销演练通过 |
| 公开插件任意实例绑定未验证 | 单实例直连及已验证分发方式 | 客户端配置路径或另行评审的网关方案 |

## 20. 参考资料与证据说明

外部资料访问于2026-09-19，实施时重新核对。编号仅用于本文与复核报告的定位；架构选择、数据模型、默认上限及阶段划分属于本项目设计。

- [S01 Laravel MCP](https://laravel.com/docs/12.x/mcp)：原生Web入口与认证组件边界。
- [S02 OpenAI认证](https://developers.openai.com/plugins/build/auth)：MCP OAuth与客户端识别。
- [S03 OpenAI连接与测试](https://developers.openai.com/plugins/deploy/connect-chatgpt)：实例接入和分层验收。
- [S04 OpenAI插件打包](https://developers.openai.com/plugins/build/plugins)：清单、工作流与平台注册映射。
- [S05 OpenAI工具定义](https://developers.openai.com/plugins/plan/tools)：工具契约和副作用声明。
- [S06 MCP授权规范参考版本](https://modelcontextprotocol.io/specification/2025-11-25/basic/authorization)：resource、metadata、PKCE和令牌边界。
- [S07 MCP传输规范参考版本](https://modelcontextprotocol.io/specification/2025-11-25/basic/transports)：HTTP传输与安全要求。
- [S08 MCP安全实践](https://modelcontextprotocol.io/specification/2025-11-25/basic/security_best_practices)：令牌、代理与发现安全。
- [S09 OpenAI开发者模式](https://developers.openai.com/api/docs/guides/developer-mode)。
- [S10 OpenAI帮助中心MCP说明](https://help.openai.com/en/articles/12584461-developer-mode-and-mcp-apps-in-chatgpt-beta)。
- [S11 Laravel Passport](https://laravel.com/docs/12.x/passport)：OAuth组件适配时的基础资料。

已有源码观察详见第3节和复核报告中的固定提交链接。本文未运行应用、OAuth或真实客户端测试；没有将文档一致性检查、CI或组件官方示例视为业务接入验收。
