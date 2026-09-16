# 恢复后的业务对账边界

当前 Core 提供隔离清单检查、追加式对账决定、空集合证明及全局执行门禁。完整恢复完成后，`http_ready` 保持业务变更、自动重试、worker 和 scheduler 暂停。宿主机解封流程、非空任务的永久重放隔离和新执行适配仍需实现；此文档不声明 B 批完成。

## 固定只读检查

由宿主机受限执行器在已隔离的新版 Core 中调用：

```text
php artisan geoflow:recovery --phase=reconcile-inspect --transaction=原恢复事务ID --after=0 --limit=100 --json
```

新的固定入口提供等效读取：

```text
php artisan geoflow:recovery-reconcile --phase=inspect --transaction=原恢复事务ID --after=0 --limit=100 --json
```

仅接受当前 `validating` 或 `http_ready` 的原事务。`after` 是上一页返回的隔离记录 ID，`limit` 限制为 1 至 200。每页都会重新核对整个来源集合，分页不会降低完整性检查。

输出包含 host、instance、epoch、transaction、准备清单摘要、记录总数和分页记录。每条记录仅包含原表名、ID、完整行摘要、原准备步骤保存的有限业务身份/状态，以及 `replay_adapter_required` 原因。不会输出队列 payload、密码或渠道凭据。

`status=pass` 表示来源仍与准备清单一致。输出始终为 `proof_scope=core_only`、`background_status=held`，包括空清单。它不改变任何原任务，不创建新执行，也不构成开放后台的证明。来源恢复点由宿主机原事务关联，并独立核对不可变清单。

## 追加对账决定

```text
php artisan geoflow:recovery-reconcile --phase=record --transaction=原恢复事务ID --decision-file=/受保护目录/decision.json --json
```

输入必须为当前执行用户拥有的普通文件，使用规范化绝对路径、权限 `0600`、单个硬链接，大小最多 16 KiB。符号链接、未知字段、非字符串身份及无效摘要均拒绝。命令仅返回结构化收据或固定错误码。

JSON 固定字段：`schema_version=1`、`decision_id`（小写 UUID）、`source_table`、`source_id`、`source_sha256`、`disposition`、`evidence_sha256`、`reviewer_sha256`、`source_recovery_point_id`、`source_recovery_point_sha256`。摘要使用 64 位小写十六进制。字段值来自已核验的来源身份和证据，不接收任意说明正文或原始凭据。

| disposition | 记录含义 | 后台结果 |
| --- | --- | --- |
| `hold` | 证据仍待补齐 | 保持 held |
| `verified_no_replay` | 操作人已完成外部状态核对 | 原任务仍 held |
| `reexecute_requested` | 另需 `user_confirmation_sha256`，记录明确的重新执行确认 | 原任务仍 held；不创建新执行 |

决定绑定当前 host、instance、epoch、原恢复事务、来源摘要和恢复点。写入前锁定准备记录并复验完整来源；提交前再读宿主机边界。相同 UUID 与相同规范化内容返回原收据；内容、来源或恢复点冲突时拒绝。决定只追加，不改原任务，收据始终包含 `execution_created=false`。JSON 对象键序不影响摘要和幂等判断。

## 空集合证明与全局门禁

```text
php artisan geoflow:recovery-reconcile --phase=prove-empty --transaction=原恢复事务ID --recovery-point=恢复点ID --recovery-point-sha256=清单SHA256 --json
```

仅当准备清单、隔离记录与全部 22 张来源表均为空时，Core 才在事务中生成不可变、幂等的空集合证明。任何非空记录，包括已完成记录，都会保持暂停。证明绑定 host、instance、epoch、原恢复事务、恢复点及其摘要、固定来源目录和空清单摘要。命令不会修改宿主机状态，结果仍为 `core_only` 和 `held`。

宿主机未来完成独立检查并开放 `ready` 后，HTTP、worker、同步队列与命令入口统一调用门禁：

1. 普通初始化的 `ready`、空 transaction 且当前 epoch 没有准备或其他对账证据，保持正常运行。
2. 恢复代次必须有匹配原事务的准备记录和有效证明。仅改成 `ready`、清空 transaction 或删除准备记录均不能解封。
3. 首次激活锁定准备及证明，重新检查全部来源为空、证明完整性和宿主机边界，最后设置 `activated_at`。
4. 激活后继续验证固定证据，允许正常的新业务记录；不会要求业务表永远为空。
5. 恢复证据或来源表缺失、数据库异常、证明或身份冲突均返回 `recovery_background_held`，错误不携带数据库内容。

新表为 `recovery_reconciliations` 与 `recovery_reconciliation_decisions`；由独立增量迁移创建，旧准备摘要算法保持兼容。恢复期间先迁移再准备。删除这两张证据表的迁移回退会丢失审计记录，运行维护应优先向前修复。

## 全部来源均保留

清单使用 `RecoveryPreparation::INTENTS` 的同一固定目录，保存所有状态的记录。完成记录也可能关联尚未执行的后继工作。

| 表 | 主要续接/重执行入口及身份 |
| --- | --- |
| jobs、failed_jobs、job_batches | 数据库队列 pop、失败 retry、批次后继；原 job/batch ID，payload 仅保留原表 |
| tasks、task_runs | `GeoFlowScheduleTasksCommand`、`JobQueueService` 的调度、claim、补投与重试；task/run ID、schedule_enabled、next_run_at、next_publish_at |
| article_distributions | 分发 worker、渠道重试、HostedSiteReconciler；article/channel、idempotency_key、remote_id；queued/sending/outcome_unknown 等状态不能自动重放 |
| manual_publications | 浏览器 claim/receipt、人工恢复 ready；原 publication、任务/文章、账户与认领记录 |
| site_theme_replications | 主题抓取、生成、迭代、发布工作；原 replication 及目标主题身份 |
| ai_workspace_runs、ai_workspace_steps、ai_workspace_external_operations | 工作区恢复器与外部动作；原 run/step/operation、工具幂等身份及外部结果证据 |
| url_import_jobs | `UrlImportRecoveryService` 的 queued/running 恢复；原 job 及已导入业务对象 |
| article_ai_quality_checks | 检查 worker 与 `ArticleAiQualityReconciliationService`；queued/running 和完成后的发布门禁后继 |
| article_ai_optimization_runs | 优化恢复器；awaiting_quality/queued/planning/rewriting/validating/evaluating/candidate_ready/applying 及自动应用后继 |
| title_generation_runs | `TitleGenerationCoordinator`；queued/running、部分失败的显式重新执行 |
| knowledge_fact_generation_runs | `KnowledgeFactGenerationRecoveryService`；queued/running、具备可恢复批次的终态 |
| ai_visibility_runs | 可见度查询任务；queued/running 及原平台/问题身份 |
| enterprise_knowledge_projects | `EnterpriseKnowledgeDraftRecoveryService`；queued/processing 草稿生成 |
| knowledge_bases | 知识索引恢复；chunk_sync_status pending/processing、chunk_sync_token、来源 hash |
| url_change_requests | `RecoverUrlChanges`；checking/ready/applied/refreshing 及仍需刷新后的路径变更 |
| hosted_site_allocation_requests | `HostedSiteReconciler`；pending、next_attempt_at、分配请求身份 |
| hosted_site_article_assignments | 同一协调器的 reserved 超时与分发后继；文章/站点/分配身份 |

未知队列类型和不支持的领域动作继续暂停。对账不得清空 Redis、删除原行或把所有旧状态改为完成。

## HTTP 读取清单

`http_ready` 仅开放明确列出的站点页面/资产、认证入口、系统更新状态及续接控制，以及已核验的管理 API 读取动作。GET 和 HEAD 本身不代表没有业务写入；未列出的路由保持暂停。

例如知识库事实页 GET 会 `firstOrCreate` 事实库，因此恢复待对账时必须拒绝。新增读取接口需要核验控制器和调用链，再加入清单。站点文章读取继续关闭浏览统计写入。

## 后续逐项恢复的必要条件

每条待恢复工作必须绑定 `(epoch, source_table, source_id, source_sha256)`、来源恢复点、审核者和证据摘要。只有外部查询/幂等记录证明安全，或用户明确确认重执行，才能创建单独的新执行记录。

原记录永久保持重放隔离，所有 scheduler、retry、claim、手动入队、队列 worker 和完成后继均需检查。新执行链接原隔离身份与决定，重新验证当前权限、渠道和业务状态，并使用新的执行 ID。未知结果不能通过换 ID 自动重发。

尚未实现上述全部适配前，不得把 host phase 改为 ready 当作非空任务对账完成。现有空集合证明只覆盖 Core；宿主机仍须核验恢复点、Redis 隔离 namespace、新生产队列和旧容器退出，并通过受保护的固定流程开放。当前 Updater 保持恢复后的 `http_ready`，尚未提供该解封流程。测试中的状态转换仅用于验证 Core 门禁。

旧签名 Core 3.1.0 不提供本命令或新协议。受限恢复 adapter 保持认证失效、后台隔离和健康核验边界；没有安全解封证明时继续限制运行。
