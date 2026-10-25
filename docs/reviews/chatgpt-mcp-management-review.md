# GEOFlow 原生 MCP 方案复核报告

修订日期：2026-10-25。源码证据基线：`bd5b1aed45c43e687399f0c114d78b421e177a80`。
关联：[原生 MCP 详细方案](../plans/chatgpt-mcp-management-rfc.md) · [80 项验收清单](../plans/chatgpt-mcp-management-acceptance.md)。

## 复核结论与范围

维护者已批准单仓库内置 MCP、随站点部署、每实例独立授权的文档方案，当前进入实施准备。插件负责工作流与分发；独立仓库和集中网关后置。PR#148 合并仅交付文档，运行能力仍待实施验收。

保留 R01 至 R20，共 20 项设计风险或实施缺口。下文“完善”表示已经写入文档的要求，均不代表 MCP 运行代码已完成。80 项运行验收仍全部 NOT_RUN。

本轮重新读取源码基线的文章控制器、更新及工作流迁移服务、管理操作及收据模型、HTTP 认证上下文、实例管理、Token、权限和 CI 路径。同步检查依赖清单/锁文件及本地开发组件 Registrar。下方固定源码链接均核对路径和相关事实，外部资料于 2026-10-25 搜索并打开核对。

本轮只修改四份文档并做静态一致性检查；仓库 CI 单独报告。未执行本清单的数据库并发、OAuth、Inspector 或 ChatGPT 端到端验收，未读取生产秘密、修改运行代码、创建独立仓库或部署。历史观察随当前源码修订，静态发现不构成生产漏洞确认。

## R01：OAuth 与 Sanctum 边界改为原生双入口

**观察。** [ApiTokenService][C01]提供 Sanctum 能力，不能单凭 Bearer 接口证明具备 ChatGPT OAuth 连接。旧方案要求 MCP 到 Core 再使用专属 Token，该假设与本轮原生同进程调用不匹配。

**完善。** 原生 MCP 使用 OAuth 令牌及其 grant 构建执行上下文；REST 继续使用 Sanctum。两入口进入同一授权应用服务，不回环 HTTP、不额外签发本站 Token。OAuth 身份不得伪装成 Sanctum 上下文，也不使用全局超级管理员凭据。

**验收。** A03、A04、A10、A53、A55。独立适配服务未来另行评审时可重新讨论两套服务间凭据，首版不引入。

## R02：业务 scope 不等于逐客户或逐站点隔离

**观察。** [ArticleController][C02]普通列表与详情没有显式 viewer 参数，任务入口存在相应 viewer 语义。仅凭这些方法不能断言整个应用的全局资源策略，也不能证明已存在可利用的跨租户问题。

**完善。** P0 明确整实例共享运营数据范围，同时保留已有模型/提示词/知识库可见性。只有列表、详情、计数、关联资源和写入都经过范围验证后，连接页才提供更细粒度资源选择。site_id 及站点列表权限不替代授权。

**验收。** A09、A11、A54、A69、A71。

## R03：通用文章更新不能直接作为草稿命令

**观察。** [ArticleGeoFlowService][C03]的 updateArticle 已在锁内比较 workflow_version，按 canUpdatePublished 限制已发布内容，并对相关正文变更执行质量/审核门禁。它仍允许有权调用者修改已发布文章；草稿专用工具需要额外状态约束。

**完善。** Core 共享命令在同一事务内校验身份、资源、草稿状态和预期版本。已发布或受保护状态返回冲突，不隐式下架；草稿工具不透传任意状态、渠道和质检覆盖参数。

**验收。** A21、A23、A25、A29。

## R04：全文并发版本必须覆盖所有相关写入口

**观察。** [文章模型][C18]已有 workflow_version 和 publication_intent。[工作流迁移服务][C16]的 contentChanged 及人工迁移推进版本，[ArticleWorkflow][C17]提供状态规范化；[控制器][C02]和更新服务已有版本比较。[UpdateArticleRequest][C04]中的 config_version 属于特定质检配置。本轮未完整验证全部正文相关写入口的统一 CAS。

**完善。** 工具 revision 优先评估复用 workflow_version，盘点 Web、REST、MCP、Worker 和批量写入的字段、前置条件与版本推进。补齐影响计划的入口后再声明全文 CAS；避免机械新增重复 counter。计划及可信批准绑定经验证的版本语义。

**验收。** A24、A26、A74；缺少写入口覆盖时不开放 P1。

## R05：已有待审保护必须进入共享服务及 Worker 路径

**观察。** [TaskController][C05]的 reviewBoundTaskData 与 assertTaskExecutionScope 已对缺少发布权限的 Token 实施待审约束。本轮未做完整动态 Worker 测试，不能宣称存在已验证竞态漏洞。

**完善。** 提取为 REST 与 MCP 共用的规则。创建、调度、入队和完成分开；计划绑定模型、提示词、知识源、渠道、审核模式及版本，Worker 使用批准快照或拒绝漂移。加入篇数、Token、并发、重试及附加质检预算。

**验收。** A30 至 A33、A72、A76；现有 task stop 不能自动被解释为全部运行工作已取消。

## R06：原生工具注册与管理契约需要明确映射

**观察。** [ManagementOperationRegistry][C06]没有完整覆盖旧文章、目录与素材 API，[覆盖说明][C07]也保留嵌套 schema 缺口。旧注册表不能直接变成全量工具列表。

**完善。** 维护经过审查的工具白名单及共享服务映射，按权限和阶段暴露。原生路径不再依赖回环 auth/session 发现身份；旧 REST 兼容保持，未实现或未知契约明确拒绝，不自动暴露 pending 路由。

**验收。** A13、A14、A20、A59、A60、A80。

## R07：幂等、操作收据与 HTTP 状态各有边界

**观察。** [TaskController][C05]对带收据头的 tasks.enqueue 调用[ManagementOperationService][C19]，以[ManagementOperation][C20]持久存储结果；唯一边界是实例+admin+请求 ID，输入 hash 冲突拒绝，查询/重放复核 scope 及任务可见性。服务仍依赖 HTTP Request 中的 ApiAuthContext。[IdempotencyService][C08]有 stale/uncertain 处理，[CLI 流程][C09]说明恢复后收据缺失不能证明未执行；组合幂等头被拒绝。

**完善。** 提取可信不可变执行上下文，优先扩展现有收据服务和存储，覆盖新增计划与动作，不另建平行 OperationReceiptService。原生路径直接传稳定请求 ID，禁止伪造 Sanctum 请求。REST 保留既有契约；派发恢复及外部效果仍需验收。超时、清理或重新认证后的 unknown 停止自动重发，同一计划不能借新 ID 或 Token 重复执行。

**验收。** A22、A28、A35、A36、A75、A76。

## R08：受理、后台执行与外部效果必须分开汇报

**观察。** 现有任务、Job、文章、质检和渠道分发有不同状态与查询入口；生成文章 ID 不证明全部流程成功。

**完善。** 结果保留 operation_state、work_state、effects_state 和对应 ID。明确部分失败、已保存草稿、已发生费用与逐渠道结果。取消或关闭聊天不等于回滚外部效果；MCP 首版不提供会话结束后的自动监控和通知。

**验收。** A22、A33、A34、A39、A72、A76。

## R09：证据、可见性和输出脱敏需要逐工具定义

**观察。** [ArticleController][C02]轻量质检状态与完整 ai_quality 详情分开；[CatalogController][C10]将审计管理员交给目录服务。不能整包转发这些结果，也不能把状态接口当完整证据。

**完善。** 质检状态和详情分别投影，保留必要来源/版本/截断标识。沿用目录与知识配置可见性；模型输出、日志和异常都不包含秘密、无关个人数据或完整供应商请求响应。内容与证据作为不可信业务数据处理。

**验收。** A11、A12、A17 至 A19、A45、A69。

## R10：运营统计需要真实口径、快照和覆盖边界

**观察。** 已读文章列表没有通用 from/to 过滤，已有路由未提供通用运营日志/聚合入口。部分时间字段不带时区，少量分页不能代表整站统计。

**完善。** 独立 D03/P0A 提供结构化授权查询，明确 from 含/to 不含、时区、数据保留起点、分母、重试和取消口径。可变数据的跨页完整统计需要一致性策略，单固定最大 ID 不足；没有数据时返回 unsupported/null 或 partial，不能补假零。

**验收。** A15、A16、A69 至 A72。日志不能通过任意文件读取或通用 SQL 工具替代。

## R11：撤销与数据库恢复不能让旧授权复活

**观察。** [ManagementInstance][C13]将实例 ID 存于业务设置表，并返回现有 RecoveryState 的恢复信息。复制或恢复数据库可能复制旧身份；现有恢复字段不能证明所有部署形态已覆盖。

**完善。** 撤销绑定 grant 的授权码、访问与刷新令牌，影响相关计划和未开始工作，保留其他连接及 CLI Token。验证现有恢复代际的覆盖范围；不足时使用不会随业务库回滚的边界。克隆、迁移域名和新环境重新初始化身份/密钥及授权。

**验收。** A08、A41、A42、A65 至 A67。清理失败如实标记，不显示全部撤销或自动恢复可用。

## R12：方案、运行实现与插件发布各自验收

**观察。** 原 PR 只含文档。OpenAI 官方分别说明 MCP 连接测试和完整插件安装，公开发布也有独立要求。[S03][S04]

**完善。** 方案与验收编号同步，拟新增类、工具、表和路由明确标注未实现。未来插件放在本仓库 integrations/chatgpt，首个读取闭环不依赖公开目录。文档一致性、仓库 CI 与 80 项运行验收分别报告；CLA 声明仍由有权主体确认，本次不代签。

**验收。** A46、A48、A77 至 A80。

## R13：Laravel MCP 默认 OAuth 权限粒度有限

**观察。** [生产依赖清单][C22]未声明 laravel/mcp 或 Passport；[锁文件][C23]在开发依赖中含 Boost 引入的 laravel/mcp v0.7.0。本地该版本 Registrar 与 Laravel 12 文档均采用单一 mcp:use，默认 oauthRoutes 的自定义 OAuth scope 受限。[S01] 开发组件可用不证明生产业务入口已实现。

**完善。** mcp:use 作为连接层许可；业务 actions 及 resource_scope 保存在服务端 grant 并逐次检查。metadata 只广告真实支持的 OAuth scopes；业务权限拒绝与 OAuth 挑战分开，避免循环授权。组件版本与扩展点先通过 D01 验证。

**验收。** A04、A50、A54、A56。

## R14：管理员、普通用户与 Token 模型不能混用

**观察。** [config/auth.php][C11]默认 web 对应 users，admin 对应 admins；[Admin][C12]已使用 Sanctum HasApiTokens 并具有 auth_version。直接套用 OAuth 用户示例可能绑定错误账号，增加同名 trait 也存在集成冲突风险，尚未做安装验证。

**完善。** 优先验证独立 McpPrincipal 及专用 OAuth provider/guard，由有效 admin 登录态建立不可变映射，不复制密码或静态权限，不改默认 web。保留原 Sanctum 认证及 CLI、浏览器运营助手；MCP 与 Passport 扩展点不支持该方式时先修订 ADR，不能假装桥接已完成。

**验收。** A10、A51 至 A53、A55。

## R15：跳过控制器可能遗漏既有管理保护

**观察。** [ApiAuthContext][C21]是 Token 数组与审计 admin_id，认证中间件将其注入 HTTP 请求。[BaseApiController][C14]在 executionAdmin 重查活跃状态与角色；[ManagementSessionController][C15]对 Token 及 ManagementScopePolicy 求交集。收据服务也从 Request 取上下文，直接调用领域服务不会自动执行全部入口检查。

**完善。** 抽出不可变执行上下文、共享 Authorizer 及命令服务，分别从可信 OAuth、Sanctum、admin 会话构造。身份、动作、资源和恢复校验存在于共同执行路径，不能靠模型参数或 MCP 工具名保证。只重构本期依赖的边界，避免复制业务实现。

**验收。** A05、A09、A30、A57、A59、A74。

## R16：授权码与令牌族必须绑定准确的 grant

**设计风险。** 原生系统同一管理员可对同一客户端产生不同范围的授权。若换取令牌或 refresh 时只取该账号最新 grant，可能无意扩大权限；原 RFC 未给出此项独立不变量。

**完善。** principal、client、resource、grant 及其版本贯穿授权码、访问与刷新链，OAuth metadata 声明并实际支持 PKCE S256；issuer 精确一致，令牌检查 audience/resource、类型与当前授权。[S02] scope 增加需要重新同意；subject 从可信上下文解析，不依赖模型参数或邮箱关联。

**验收。** A03、A04、A26、A41、A55、A56、A74。

## R17：每实例直连需要完整地址与代理契约

**设计风险。** GEOFlow 可使用子目录和自定义后台路径。OAuth metadata、issuer 与 MCP resource 的路径处理不同；信任任意 Host、转发头或重定向还会污染发现流程。

**完善。** 规范地址来自可信部署配置，401 声明正确 metadata 位置，根目录与子目录分别测试。仅在成功和错误授权响应均返回精确匹配 iss 时声明 authorization_response_iss_parameter_supported=true；CIMD 及 redirect URI 从 ChatGPT MCP 管理页复制，静态预注册/CIMD/DCR 按实际配置验证。[S02] OAuth 页面保留 CSRF，机器路由验证令牌，发现请求限制目的地；普通后台 HTML URL 不能充当 MCP 端点。

**验收。** A02、A19、A44、A61 至 A63。

## R18：管理协议版本与 MCP 协议版本须分离

**观察。** [ManagementInstance][C13]返回 protocol_version=1.0，该字段属于现有远程管理契约。它不能直接成为 MCP initialize 的协议版本。

**完善。** 分开 MCP 协议、管理契约、Core、工具 schema 和 contract_hash。组件及实际支持版本在实施 PR 锁定；规范引用版本不被描述为当前唯一最新版本。客户端缓存旧工具时仍重查权限，兼容失败明确关闭能力。

**验收。** A01、A13、A48、A59、A60、A80。

## R19：原生模块共享资源，且只读入口可能隐式初始化

**观察。** [ManagementInstance][C13]的 id 使用 firstOrCreate；原生模块与主应用共享进程/资源。模块目录隔离无法自动保证只读无业务变更或后台不被慢请求耗尽。

**完善。** 部署/启用阶段完成实例初始化，未就绪时拒绝业务调用。只读不启动质检或生成，审计与限流记账单独声明；prepare 会写计划，按有状态工具标注。设置查询/并发/响应上限，耗时任务入队，按实例演练禁用与负载隔离。

**验收。** A17、A44、A47、A49、A58、A64、A65、A73。

## R20：目标账号、工作区与插件连接须实测

**观察。** 当前官方入口为 Plugins 添加自定义 MCP、配置认证、Create as a plugin、安装及@调用，账号和工作区安全策略仍适用。[S03][S09] 插件固定 URL 和平台注册映射不能证明动态任意实例绑定已完成。[S04]

**完善。** 按账号、客户端、模型、对话模式、工作区策略分别验证。先完成每实例直连，插件使用真实本地/组织映射；不创建假 ID，不打包客户凭据。不支持写入时保持只读，不能通过伪造 annotations 或 GET 副作用绕过限制。集中网关另立项目评审。

**验收。** A46、A77 至 A80；目标账号真实接入仍为 NOT_RUN。

## 实施与证据闭环

D01、D02 交付原生身份及只读；D03 独立补运营查询；D04 安全草稿；D05 受控生成；D06 受控发布；D07 插件交付。授权、预算、收据、恢复及客户端实测随对应阶段推进，未通过能力默认关闭。详细依赖见 RFC 第 18 节。

D01+D02 先交接隔离 fixture 的两管理员/两实例读取闭环：A 在实例 A 授权最小只读 grant，读取 instance/articles.list/articles.get 白名单；拒绝无文章读取 grant 的 B 使用自身令牌及跨实例同 ID 访问；撤销后旧 access/refresh 均拒绝。三个简称仅映射拟议读取工具，详细开发边界、A 编号和证据字段见 RFC 第 18.1 节。全程不启动付费采集、质检、生成或发布，当前 NOT_RUN。

本轮将方案对齐当前源码和官方资料，并补充可执行交接。后续实现 PR 应逐项引用 R 编号、A 编号和真实证据。文档状态、CI 状态、运行验收、合并和部署分别报告；文档合并不证明运行风险已消除。

## 固定源码与官方资料

[C01]: https://github.com/yaojingang/GEOFlow/blob/bd5b1aed45c43e687399f0c114d78b421e177a80/app/Services/Api/ApiTokenService.php
[C02]: https://github.com/yaojingang/GEOFlow/blob/bd5b1aed45c43e687399f0c114d78b421e177a80/app/Http/Controllers/Api/V1/ArticleController.php
[C03]: https://github.com/yaojingang/GEOFlow/blob/bd5b1aed45c43e687399f0c114d78b421e177a80/app/Services/GeoFlow/ArticleGeoFlowService.php
[C04]: https://github.com/yaojingang/GEOFlow/blob/bd5b1aed45c43e687399f0c114d78b421e177a80/app/Http/Requests/Api/UpdateArticleRequest.php
[C05]: https://github.com/yaojingang/GEOFlow/blob/bd5b1aed45c43e687399f0c114d78b421e177a80/app/Http/Controllers/Api/V1/TaskController.php
[C06]: https://github.com/yaojingang/GEOFlow/blob/bd5b1aed45c43e687399f0c114d78b421e177a80/app/Support/Api/ManagementOperationRegistry.php
[C07]: https://github.com/yaojingang/GEOFlow/blob/bd5b1aed45c43e687399f0c114d78b421e177a80/docs/api/remote-management-preview.md
[C08]: https://github.com/yaojingang/GEOFlow/blob/bd5b1aed45c43e687399f0c114d78b421e177a80/app/Services/Api/IdempotencyService.php
[C09]: https://github.com/yaojingang/GEOFlow/blob/bd5b1aed45c43e687399f0c114d78b421e177a80/.agents/skills/geoflow/references/remote-cli-workflow.md
[C10]: https://github.com/yaojingang/GEOFlow/blob/bd5b1aed45c43e687399f0c114d78b421e177a80/app/Http/Controllers/Api/V1/CatalogController.php
[C11]: https://github.com/yaojingang/GEOFlow/blob/bd5b1aed45c43e687399f0c114d78b421e177a80/config/auth.php
[C12]: https://github.com/yaojingang/GEOFlow/blob/bd5b1aed45c43e687399f0c114d78b421e177a80/app/Models/Admin.php
[C13]: https://github.com/yaojingang/GEOFlow/blob/bd5b1aed45c43e687399f0c114d78b421e177a80/app/Services/Api/ManagementInstance.php
[C14]: https://github.com/yaojingang/GEOFlow/blob/bd5b1aed45c43e687399f0c114d78b421e177a80/app/Http/Controllers/Api/V1/BaseApiController.php
[C15]: https://github.com/yaojingang/GEOFlow/blob/bd5b1aed45c43e687399f0c114d78b421e177a80/app/Http/Controllers/Api/V1/ManagementSessionController.php
[C16]: https://github.com/yaojingang/GEOFlow/blob/bd5b1aed45c43e687399f0c114d78b421e177a80/app/Services/GeoFlow/ArticleWorkflowTransitionService.php
[C17]: https://github.com/yaojingang/GEOFlow/blob/bd5b1aed45c43e687399f0c114d78b421e177a80/app/Support/GeoFlow/ArticleWorkflow.php
[C18]: https://github.com/yaojingang/GEOFlow/blob/bd5b1aed45c43e687399f0c114d78b421e177a80/app/Models/Article.php
[C19]: https://github.com/yaojingang/GEOFlow/blob/bd5b1aed45c43e687399f0c114d78b421e177a80/app/Services/Api/ManagementOperationService.php
[C20]: https://github.com/yaojingang/GEOFlow/blob/bd5b1aed45c43e687399f0c114d78b421e177a80/app/Models/ManagementOperation.php
[C21]: https://github.com/yaojingang/GEOFlow/blob/bd5b1aed45c43e687399f0c114d78b421e177a80/app/Http/ApiAuthContext.php
[C22]: https://github.com/yaojingang/GEOFlow/blob/bd5b1aed45c43e687399f0c114d78b421e177a80/composer.json
[C23]: https://github.com/yaojingang/GEOFlow/blob/bd5b1aed45c43e687399f0c114d78b421e177a80/composer.lock
[S01]: https://laravel.com/framework/docs/12.x/mcp
[S03]: https://developers.openai.com/plugins/deploy/connect-chatgpt
[S04]: https://developers.openai.com/plugins/build/plugins
[S09]: https://developers.openai.com/api/docs/guides/custom-mcp-server
[S02]: https://developers.openai.com/plugins/build/auth
