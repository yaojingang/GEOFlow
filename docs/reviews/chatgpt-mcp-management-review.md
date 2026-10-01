# GEOFlow原生MCP方案复核报告

修订日期：2026-09-19。源码基线：`9ed2fe80457d5eb280a4bca7cf799895bf2ca3b1`。  
本轮接续PR版本：`481394fc38b094bcf68c76092d67ee1489b391c3`。  
关联：[原生MCP详细方案](../plans/chatgpt-mcp-management-rfc.md) · [80项验收清单](../plans/chatgpt-mcp-management-acceptance.md)。

## 复核结论与范围

按维护者确认的方向，将主方案统一为单仓库内置MCP、随站点部署、每实例独立授权。插件负责工作流与分发；独立仓库和集中网关后置。本轮修改方案正文及配套文档，取代此前仅在PR评论中提出原生方向、正文仍默认sidecar的状态。

保留并调整原R01至R12，新增R13至R20，共20项设计风险或实施缺口。下文“完善”表示已经写入文档的要求，均不代表运行代码已修复。80项运行验收仍全部NOT_RUN。

证据包含原PR已核对的API路由、业务服务、Token、幂等和管理契约，本轮补查config/auth.php、Admin、BaseApiController、ManagementSessionController、ManagementInstance及CI，并再次核对OpenAI、Laravel和MCP官方资料。源码链接固定到上述基线；外部资料访问于2026-09-19。

本地尝试访问GitHub仓库因DNS解析失败，文档读取与提交通过已授权GitHub连接完成。未运行PHP、JavaScript、数据库并发、OAuth、Inspector或ChatGPT端到端测试；未读取生产秘密、修改运行代码、创建独立仓库、合并或部署。静态发现不构成对生产漏洞的确认。

## R01：OAuth与Sanctum边界改为原生双入口

**观察。** [ApiTokenService][C01]提供Sanctum能力，不能单凭Bearer接口证明具备ChatGPT OAuth连接。旧方案要求MCP到Core再使用专属Token，该假设与本轮原生同进程调用不匹配。

**完善。** 原生MCP使用OAuth令牌及其grant构建执行上下文；REST继续使用Sanctum。两入口进入同一授权应用服务，不回环HTTP、不额外签发本站Token。OAuth身份不得伪装成Sanctum上下文，也不使用全局超级管理员凭据。

**验收。** A03、A04、A10、A53、A55。独立适配服务未来另行评审时可重新讨论两套服务间凭据，首版不引入。

## R02：业务scope不等于逐客户或逐站点隔离

**观察。** [ArticleController][C02]普通列表与详情没有显式viewer参数，任务入口存在相应viewer语义。仅凭这些方法不能断言整个应用的全局资源策略，也不能证明已存在可利用的跨租户问题。

**完善。** P0明确整实例共享运营数据范围，同时保留已有模型/提示词/知识库可见性。只有列表、详情、计数、关联资源和写入都经过范围验证后，连接页才提供更细粒度资源选择。site_id及站点列表权限不替代授权。

**验收。** A09、A11、A54、A69、A71。

## R03：通用文章更新不能直接作为草稿命令

**观察。** [ArticleGeoFlowService][C03]的updateArticle在风险字段变化后可能归一为draft/pending。通用更新有其现有业务含义，不能直接赋予“绝不影响已发布内容”的更窄保证。

**完善。** Core共享命令在同一事务内校验身份、资源、草稿状态和预期版本。已发布或受保护状态返回冲突，不隐式下架；草稿工具不透传任意状态、渠道和质检覆盖参数。

**验收。** A21、A23、A25、A29。

## R04：全文并发版本必须覆盖所有相关写入口

**观察。** [UpdateArticleRequest][C04]及现有控制器使用config_version处理特定配置检查；不能据此推断普通正文更新拥有统一CAS。现有事务和行锁继续有价值，本发现不否定它们。

**完善。** 明确内容revision及强前置条件，盘点Web、REST、MCP、Worker和批量写入。所有相关修改推进同一版本；MCP自己的计数器或内存锁无法约束其他入口。计划及可信批准绑定revision。

**验收。** A24、A26、A74；缺少写入口覆盖时不开放P1。

## R05：已有待审保护必须进入共享服务及Worker路径

**观察。** [TaskController][C05]的reviewBoundTaskData与assertTaskExecutionScope已对缺少发布权限的Token实施待审约束。本轮未做完整动态Worker测试，不能宣称存在已验证竞态漏洞。

**完善。** 提取为REST与MCP共用的规则。创建、调度、入队和完成分开；计划绑定模型、提示词、知识源、渠道、审核模式及版本，Worker使用批准快照或拒绝漂移。加入篇数、Token、并发、重试及附加质检预算。

**验收。** A30至A33、A72、A76；现有task stop不能自动被解释为全部运行工作已取消。

## R06：原生工具注册与管理契约需要明确映射

**观察。** [ManagementOperationRegistry][C06]没有完整覆盖旧文章、目录与素材API，[覆盖说明][C07]也保留嵌套schema缺口。旧注册表不能直接变成全量工具列表。

**完善。** 维护经过审查的工具白名单及共享服务映射，按权限和阶段暴露。原生路径不再依赖回环auth/session发现身份；旧REST兼容保持，未实现或未知契约明确拒绝，不自动暴露pending路由。

**验收。** A13、A14、A20、A59、A60、A80。

## R07：幂等、操作收据与HTTP状态各有边界

**观察。** [IdempotencyService][C08]存在stale/uncertain等处理；[远程CLI流程][C09]说明恢复后收据缺失不能证明未执行。旧幂等头与新收据头不同，任务入队不能同时接受两者。

**完善。** 原生服务直接使用稳定业务请求ID和收据，不伪造HTTP头。REST保留既有契约。对超时、响应丢失、去重记录清理和重新认证逐项定义恢复策略，unknown停止自动重发；相同计划不能通过新ID或新Token重复执行。

**验收。** A22、A28、A35、A36、A75、A76。

## R08：受理、后台执行与外部效果必须分开汇报

**观察。** 现有任务、Job、文章、质检和渠道分发有不同状态与查询入口；生成文章ID不证明全部流程成功。

**完善。** 结果保留operation_state、work_state、effects_state和对应ID。明确部分失败、已保存草稿、已发生费用与逐渠道结果。取消或关闭聊天不等于回滚外部效果；MCP首版不提供会话结束后的自动监控和通知。

**验收。** A22、A33、A34、A39、A72、A76。

## R09：证据、可见性和输出脱敏需要逐工具定义

**观察。** [ArticleController][C02]轻量质检状态与完整ai_quality详情分开；[CatalogController][C10]将审计管理员交给目录服务。不能整包转发这些结果，也不能把状态接口当完整证据。

**完善。** 质检状态和详情分别投影，保留必要来源/版本/截断标识。沿用目录与知识配置可见性；模型输出、日志和异常都不包含秘密、无关个人数据或完整供应商请求响应。内容与证据作为不可信业务数据处理。

**验收。** A11、A12、A17至A19、A45、A69。

## R10：运营统计需要真实口径、快照和覆盖边界

**观察。** 已读文章列表没有通用from/to过滤，已有路由未提供通用运营日志/聚合入口。部分时间字段不带时区，少量分页不能代表整站统计。

**完善。** 独立D03/P0A提供结构化授权查询，明确from含/to不含、时区、数据保留起点、分母、重试和取消口径。可变数据的跨页完整统计需要一致性策略，单固定最大ID不足；没有数据时返回unsupported/null或partial，不能补假零。

**验收。** A15、A16、A69至A72。日志不能通过任意文件读取或通用SQL工具替代。

## R11：撤销与数据库恢复不能让旧授权复活

**观察。** [ManagementInstance][C13]将实例ID存于业务设置表，并返回现有RecoveryState的恢复信息。复制或恢复数据库可能复制旧身份；现有恢复字段不能证明所有部署形态已覆盖。

**完善。** 撤销绑定grant的授权码、访问与刷新令牌，影响相关计划和未开始工作，保留其他连接及CLI Token。验证现有恢复代际的覆盖范围；不足时使用不会随业务库回滚的边界。克隆、迁移域名和新环境重新初始化身份/密钥及授权。

**验收。** A08、A41、A42、A65至A67。清理失败如实标记，不显示全部撤销或自动恢复可用。

## R12：方案、运行实现与插件发布各自验收

**观察。** 原PR只含文档。OpenAI官方分别说明MCP连接测试和完整插件安装，公开发布也有独立要求。[S03][S04]

**完善。** 方案与验收编号同步，所有拟新增类、工具、表和路由明确标注未实现。实际插件放在本仓库integrations/chatgpt，首个读取闭环不依赖公开目录。80项测试不得被文档检查或原有CI替代；CLA声明仍由有权主体确认，本次不代签。

**验收。** A46、A48、A77至A80。

## R13：Laravel MCP默认OAuth权限粒度有限

**观察。** Laravel MCP文档说明默认oauthRoutes使用单一mcp:use，不能直接承担全部自定义业务scope需求。[S01] 存在官方组件不证明其在GEOFlow已经可用。

**完善。** mcp:use作为连接层许可；业务actions及resource_scope保存在服务端grant并逐次检查。metadata只广告真实支持的OAuth scopes；业务权限拒绝与OAuth挑战分开，避免循环授权。组件版本与扩展点先通过D01验证。

**验收。** A04、A50、A54、A56。

## R14：管理员、普通用户与Token模型不能混用

**观察。** [config/auth.php][C11]默认web对应users，admin对应admins；[Admin][C12]已使用Sanctum HasApiTokens并具有auth_version。直接套用OAuth用户示例可能绑定错误账号，增加同名trait也存在集成冲突风险，尚未做安装验证。

**完善。** 优先验证独立McpPrincipal及专用OAuth provider/guard，由有效admin登录态建立不可变映射，不复制密码或静态权限，不改默认web。保留原Sanctum认证及CLI、浏览器运营助手；MCP与Passport扩展点不支持该方式时先修订ADR，不能假装桥接已完成。

**验收。** A10、A51至A53、A55。

## R15：跳过控制器可能遗漏既有管理保护

**观察。** [BaseApiController][C14]依赖ApiAuthContext，并在executionAdmin重新检查活跃状态与角色；[ManagementSessionController][C15]对Token及ManagementScopePolicy求交集。直接调用业务服务不会自动执行这些入口逻辑。

**完善。** 抽出不可变执行上下文、共享Authorizer及命令服务，分别从可信OAuth、Sanctum、admin会话构造。身份、动作、资源和恢复校验存在于共同执行路径，不能靠模型参数或MCP工具名保证。只重构本期依赖的边界，避免复制业务实现。

**验收。** A05、A09、A30、A57、A59、A74。

## R16：授权码与令牌族必须绑定准确的grant

**设计风险。** 原生系统同一管理员可对同一客户端产生不同范围的授权。若换取令牌或refresh时只取该账号最新grant，可能无意扩大权限；原RFC未给出此项独立不变量。

**完善。** principal、client、resource、grant及其版本贯穿授权码、访问与刷新链。scope增加需要重新同意；OAuth subject从验证后上下文解析，不要求模型提供或依赖邮箱关联。签名验证之外还要验证resource、类型和当前授权。

**验收。** A03、A04、A26、A41、A55、A56、A74。

## R17：每实例直连需要完整地址与代理契约

**设计风险。** GEOFlow可使用子目录和自定义后台路径。OAuth metadata、issuer与MCP resource的路径处理不同；信任任意Host、转发头或重定向还会污染发现流程。

**完善。** 规范地址来自可信部署配置，401声明正确metadata位置，根目录与子目录分别测试。OAuth浏览器页面保留CSRF，机器路由使用正确的令牌校验；不全局关安全机制。发现请求继续限制网络目的地。普通后台HTML URL不能直接当MCP端点。

**验收。** A02、A19、A44、A61至A63。

## R18：管理协议版本与MCP协议版本须分离

**观察。** [ManagementInstance][C13]返回protocol_version=1.0，该字段属于现有远程管理契约。它不能直接成为MCP initialize的协议版本。

**完善。** 分开MCP协议、管理契约、Core、工具schema和contract_hash。组件及实际支持版本在实施PR锁定；规范引用版本不被描述为当前唯一最新版本。客户端缓存旧工具时仍重查权限，兼容失败明确关闭能力。

**验收。** A01、A13、A48、A59、A60、A80。

## R19：原生模块共享资源，且只读入口可能隐式初始化

**观察。** [ManagementInstance][C13]的id使用firstOrCreate；原生模块与主应用共享进程/资源。模块目录隔离无法自动保证只读无业务变更或后台不被慢请求耗尽。

**完善。** 部署/启用阶段完成实例初始化，未就绪时拒绝业务调用。只读不启动质检或生成，审计与限流记账单独声明；prepare会写计划，按有状态工具标注。设置查询/并发/响应上限，耗时任务入队，按实例演练禁用与负载隔离。

**验收。** A17、A44、A47、A49、A58、A64、A65、A73。

## R20：Pro与统一插件任意实例连接不能凭文档推定

**观察。** OpenAI开发者模式页面列出Pro等账号和读写支持，帮助中心仍保留Pro仅read/fetch等限制，实际入口与模式说明存在差异。[S09][S10] 插件固定URL和平台注册映射也不能证明动态任意实例绑定已完成。[S04]

**完善。** 按账号、客户端、模型、对话模式、工作区策略分别验证。先完成每实例直连，插件使用真实本地/组织映射；不创建假ID，不打包客户凭据。不支持写入时保持只读，不能通过伪造annotations或GET副作用绕过限制。集中网关另立项目评审。

**验收。** A46、A77至A80；目标账号真实接入仍为NOT_RUN。

## 实施与证据闭环

D01、D02交付原生身份及只读；D03独立补运营查询；D04安全草稿；D05受控生成；D06受控发布；D07插件交付。授权、预算、收据、恢复及客户端实测随对应阶段推进，未通过能力默认关闭。详细依赖见RFC第18节。

本轮解决的是方案表述与方向不一致，并补充实施要求；没有证明任何运行风险已经消除。后续实现PR应逐项引用R编号、A编号和真实证据。文档状态、CI状态、运行验收、合并和部署分别报告。

## 固定源码与官方资料

[C01]: https://github.com/yaojingang/GEOFlow/blob/9ed2fe80457d5eb280a4bca7cf799895bf2ca3b1/app/Services/Api/ApiTokenService.php
[C02]: https://github.com/yaojingang/GEOFlow/blob/9ed2fe80457d5eb280a4bca7cf799895bf2ca3b1/app/Http/Controllers/Api/V1/ArticleController.php
[C03]: https://github.com/yaojingang/GEOFlow/blob/9ed2fe80457d5eb280a4bca7cf799895bf2ca3b1/app/Services/GeoFlow/ArticleGeoFlowService.php
[C04]: https://github.com/yaojingang/GEOFlow/blob/9ed2fe80457d5eb280a4bca7cf799895bf2ca3b1/app/Http/Requests/Api/UpdateArticleRequest.php
[C05]: https://github.com/yaojingang/GEOFlow/blob/9ed2fe80457d5eb280a4bca7cf799895bf2ca3b1/app/Http/Controllers/Api/V1/TaskController.php
[C06]: https://github.com/yaojingang/GEOFlow/blob/9ed2fe80457d5eb280a4bca7cf799895bf2ca3b1/app/Support/Api/ManagementOperationRegistry.php
[C07]: https://github.com/yaojingang/GEOFlow/blob/9ed2fe80457d5eb280a4bca7cf799895bf2ca3b1/docs/api/remote-management-preview.md
[C08]: https://github.com/yaojingang/GEOFlow/blob/9ed2fe80457d5eb280a4bca7cf799895bf2ca3b1/app/Services/Api/IdempotencyService.php
[C09]: https://github.com/yaojingang/GEOFlow/blob/9ed2fe80457d5eb280a4bca7cf799895bf2ca3b1/.agents/skills/geoflow/references/remote-cli-workflow.md
[C10]: https://github.com/yaojingang/GEOFlow/blob/9ed2fe80457d5eb280a4bca7cf799895bf2ca3b1/app/Http/Controllers/Api/V1/CatalogController.php
[C11]: https://github.com/yaojingang/GEOFlow/blob/9ed2fe80457d5eb280a4bca7cf799895bf2ca3b1/config/auth.php
[C12]: https://github.com/yaojingang/GEOFlow/blob/9ed2fe80457d5eb280a4bca7cf799895bf2ca3b1/app/Models/Admin.php
[C13]: https://github.com/yaojingang/GEOFlow/blob/9ed2fe80457d5eb280a4bca7cf799895bf2ca3b1/app/Services/Api/ManagementInstance.php
[C14]: https://github.com/yaojingang/GEOFlow/blob/9ed2fe80457d5eb280a4bca7cf799895bf2ca3b1/app/Http/Controllers/Api/V1/BaseApiController.php
[C15]: https://github.com/yaojingang/GEOFlow/blob/9ed2fe80457d5eb280a4bca7cf799895bf2ca3b1/app/Http/Controllers/Api/V1/ManagementSessionController.php
[S01]: https://laravel.com/docs/12.x/mcp
[S03]: https://developers.openai.com/plugins/deploy/connect-chatgpt
[S04]: https://developers.openai.com/plugins/build/plugins
[S09]: https://developers.openai.com/api/docs/guides/developer-mode
[S10]: https://help.openai.com/en/articles/12584461-developer-mode-and-mcp-apps-in-chatgpt-beta
