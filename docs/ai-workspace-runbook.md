# GEOFlow 后台帮助助手运行手册

## 定位

AI 工作台提供后台功能问答、操作指引和可信功能入口。请求在 Web 进程内完成本地知识检索，并执行一次对话模型调用。旧版 Run、Plan、Approval、Capability 与 Trace 工作流已停止接收请求，相关数据库表和历史数据继续保留。

## 请求链路

1. 将用户问题保存到现有会话消息表，首次问题同步使用本地规则生成最多 15 个字符的会话标题，不额外调用模型。
2. `AdminHelpKnowledgeCatalog` 识别当前管理员可访问的功能，`AdminHelpQueryContextResolver` 为短追问补充上一条用户问题和上一轮命中章节。
3. `AdminHelpKnowledgeRetriever` 只检索 `ai_workspace_manual` 系统知识库。优先使用当前正文对应的 ready 切片，索引缺失或失败时读取数据库正文或随包 Markdown 的章节索引。
4. 检索最多保留 8 段证据和 10,000 字符，历史最多 10,000 字符，一轮总预算为 24,000 字符。
5. 服务端按命名路由、稳定 GET 入口和管理员权限生成最多 3 个相关功能入口；中文界面还可从同一章节选择 0 至 3 张私有截图。
6. `AdminHelpAssistant` 使用证据、有限历史和固定安全提示词生成详细操作指南，单轮回答上限受模型 `max_tokens` 与工作台 2,400 token 上限共同约束。
7. 接口通过 SSE 返回真实连接状态、正文分片和完成数据。服务端收到模型正文后立即发送，浏览器按动画帧合并 Markdown 重排。
8. 仅在回答完整结束后保存助手消息，消息 `meta` 保存知识来源、知识健康、检索诊断、固定媒体版本、相关功能、推荐问题与生成性能，`usage` 保存模型用量。

模型输出中的链接不会进入可点击区域。页面只渲染服务端返回、同源且位于后台路径下的功能入口。

## SSE 协议

`POST /admin/ai-workspace/conversations/{conversation}/messages` 返回 `200 text/event-stream`。

- `title`：`{title}`，首轮问题使用本地标题时立即返回，兼容现有客户端。
- `status`：`{stage, label, provider?, model?}`。`preparing` 来自本地准备过程；`connected`、`reasoning` 和 `generating` 来自模型流中的真实事件。
- `delta`：`{content}`，收到首个正文分片后隐藏等待状态。
- `done`：`{message_id, related_features, suggestions, knowledge_sources, knowledge_health, related_media, generation}`。
- `error`：`{code, message, related_features, suggestions}`。

所有模型共用一轮总时间预算。流式调用在首个正文分片前遇到可恢复故障时继续尝试下一个可用模型；正文已经开始后若中断，保留已显示内容并明确提示生成中断，避免把两个模型的回答拼接在一起。只有经过真实流式检测且明确失败的模型，才使用普通文本回退；未检测流式能力的旧档案会在下一次请求中先尝试真实流式调用。AI 服务整体不可用时，错误事件仍带有本地检索得到的功能入口和推荐问题。

模型的私有推理内容不会发送到浏览器。页面在等待 3 秒和 8 秒后更新真实等待提示；收到首个正文分片后停止等待计时。用户主动停止时，浏览器保留已接收的部分回答并提供复制按钮，未完成的助手消息不会写入数据库。

## 系统知识库

官方知识文件位于 `resources/knowledge/ai-workspace/geoflow-admin-guide.zh_CN.md`，内容清单位于同目录的 `manifest.php`。正文覆盖后台功能逻辑、流程、设计原理、亮点、权限、常见故障和稳定站内入口。发布门禁要求至少 10,000 个中文字符、15 个必需章节、合法 route 指令和准确内容哈希。

同步命令：

```bash
php artisan geoflow:sync-system-knowledge --key=ai_workspace_manual --media
```

同步具有幂等性。首次同步创建唯一系统知识库、官方修订和索引任务；未修改的官方正文可以随版本安全升级；管理员已经二次编辑的正文会被保留，并显示官方更新可采用状态。系统知识库受到应用删除断言和数据库限制外键双重保护，Web、API、CLI 和模型直接删除均会失败。

超级管理员可在知识库详情页编辑正文、恢复历史修订、采用当前官方版本和管理图片。普通管理员拥有只读访问。保存正文时会检查最小长度、必需章节、route 白名单、动态路由、外部 Markdown 链接、Markdown 图片和疑似敏感信息。每次编辑、恢复和采用官方版本都会生成新修订并触发可回退索引。

健康状态说明：

- `healthy`：正文哈希与 ready 索引一致。
- `indexing`：新索引正在生成，检索继续使用上一版 ready 切片或正文回退。
- `customized`：本地正文已编辑，当前内容继续参与问答。
- `fallback`：切片缺失或失败，系统使用数据库正文或随包官方 Markdown 回答。

## 知识图片

官方图片清单位于 `resources/knowledge/ai-workspace/media/manifest.json`，当前包含 24 张经过脱敏检查的 1440×900 WebP 截图。每张图具有稳定素材键、章节、命名路由、标题、alt、说明、关键词、应用版本和 SHA-256。同步后文件进入 `local` 私有磁盘，通过需要后台登录和功能权限的读取接口提供。

图片替换会创建不可变新版本，历史消息固定保存媒体 ID、版本和哈希。停用只影响新回答，已有历史仍能读取当时版本。清理命令只删除超过会话留存期加 7 天、已经停用且没有未过期消息引用的旧媒体；官方清单当前版本始终保留。

执行清理前可以预览命中数量：`php artisan geoflow:prune-ai-workspace --days=90 --dry-run`。确认范围后再去掉 `--dry-run`；定时清理沿用配置中的留存天数。

模型正文中的图片语法不会创建图片节点。浏览器只渲染 `related_media` 结构化字段，限制为同源后台地址，采用懒加载、明确 alt、预览对话框和加载失败降级。首版截图语言为 `zh_CN`，其他后台语言完整使用文字回答。

## 帮助目录维护

目录位于 `app/Services/AiWorkspace/AdminHelpKnowledgeCatalog.php`。每个条目需要维护：

- 稳定 ID、名称、说明和检索关键词；
- 3 个或以上可执行操作步骤；
- 已注册的后台命名路由；
- Lucide 图标名和权限级别；
- 2 个或以上预设追问；
- 是否作为无命中问题的常用功能。

帮助事实以中文内容为权威。模型按用户语言组织答案。新增或修改条目后需要运行目录协议测试，确认路由有效、权限过滤正确、上下文不含 URL。

## 模型就绪与故障转移

工作台复用 AI 配置器中的已启用对话模型、日额度、优先级和 Provider 故障转移。

工作台按照模型故障转移优先级选择已启用且通过文本检测的对话模型。新建、配置已变更、检测已过期或最近检测失败的模型不会参与真实问答；超级管理员重新执行模型连接检测并通过后才会恢复。连接检测优先执行真实流式请求，成功时记录 `streaming.ready`；流式失败后再验证普通文本，成功时记录 `streaming.degraded` 和已观测的降级原因。流式成功要求至少一个正文分片和非错误的终止事件，缺失终止事件、错误事件或未知终止原因都会按中断处理。流式与普通文本探测共用总超时预算。成功回答会刷新 7 天就绪记录。结构化输出和工具调用不参与工作台就绪判断。

相关环境变量：

```dotenv
GEOFLOW_AI_WORKSPACE_RUNTIME_ENABLED=false
GEOFLOW_AI_WORKSPACE_RETENTION_DAYS=90
GEOFLOW_AI_WORKSPACE_GLOBAL_CONCURRENCY=10
GEOFLOW_AI_WORKSPACE_CONCURRENCY_CACHE_STORE=redis
GEOFLOW_AI_WORKSPACE_ADMIN_DAILY_MODEL_CALLS=200
GEOFLOW_AI_WORKSPACE_MODEL_TOTAL_TIMEOUT=90
GEOFLOW_AI_WORKSPACE_MODEL_ATTEMPT_TIMEOUT=30
GEOFLOW_AI_WORKSPACE_HISTORY_CHAR_BUDGET=10000
GEOFLOW_AI_WORKSPACE_GENERATION_LEASE_SECONDS=180
GEOFLOW_AI_WORKSPACE_REQUIRE_VERIFIED_MODEL=true
```

工作台不需要专用队列 Worker 或 Horizon Supervisor。

## 常见排查

### 页面显示 AI 服务不可用

1. 确认 `GEOFLOW_AI_WORKSPACE_RUNTIME_ENABLED=true`。
2. 在 AI 配置器中确认至少一个对话模型为启用状态。
3. 新建模型、模型配置变更、检测失败或检测过期时，由超级管理员重新执行模型连接检测；检测通过后模型才会进入工作台候选列表。
4. 检查模型日额度和管理员日调用额度。

### 一直等待且没有正文

1. 检查反向代理是否关闭 SSE 缓冲，响应应包含 `X-Accel-Buffering: no`。
2. 检查浏览器网络面板中的 `status` 与 `delta` 事件。
3. 检查 Provider 超时、连接错误和全局并发限制。
4. 查看助手消息 `meta.generation` 中的 `provider_first_event_ms`、`ttft_ms`、`total_ms`、`attempts` 和故障转移计数。
5. 流式能力被明确标记为 `degraded` 时，确认普通文本回退可以成功完成。

### 相关功能入口缺失

1. 检查目录条目的命名路由是否存在。
2. 检查当前管理员是否拥有受保护功能权限。
3. 确认入口是无需动态对象 ID 的稳定页面。

### 回答没有引用系统知识

1. 执行 `php artisan geoflow:sync-system-knowledge --key=ai_workspace_manual --media`。
2. 在知识库列表检查系统徽标、官方版本和健康状态。
3. 查看助手消息 `meta.retrieval` 的 `mode`、`fallback_reason`、`latency_ms` 和 `evidence_count`。
4. 索引失败时确认系统仍可通过 `fallback` 返回正文；随后检查 knowledge 队列与 Embedding 模型。

### 相关截图没有显示

1. 确认当前后台语言为简体中文。
2. 确认媒体记录为启用状态且没有“待复核”标记。
3. 检查媒体绑定的章节、route 和当前管理员权限。
4. 验证私有文件哈希、MIME 和尺寸；图片接口返回 404 时正文问答仍应正常完成。

## 验证

```bash
php artisan test --compact tests/Feature/AdminAiWorkspaceTest.php
php artisan test --compact tests/Feature/AiWorkspaceRuntimeProtocolV2Test.php
php artisan test --compact tests/Feature/AiWorkspaceWorkflowTest.php
php artisan test --compact tests/Feature/SystemKnowledgeBaseTest.php tests/Feature/AiWorkspaceKnowledgeMediaTest.php
php artisan test --compact tests/Unit/AiWorkspace
node --test tests/JavaScript/ai-workspace.test.js
vendor/bin/pint --dirty --format agent
npm run build
php artisan route:list --path=admin/ai-workspace --except-vendor
php artisan geoflow:sync-system-knowledge --key=ai_workspace_manual --media
```

发布前在 320px、390px、768px、1280px 和 1440px 宽度下检查初始、等待、流式、停止、完成、错误和历史回放状态，并确认浏览器控制台没有错误。使用至少 10,000 字符的 Markdown 覆盖表格、引用、嵌套列表、代码块和外部链接净化。

## 回滚

代码回滚需要恢复旧控制器、前端资源、流式服务和模型运行时。接口路由、数据库结构与环境变量没有变化，现有工作流表、迁移与历史数据无需恢复。
