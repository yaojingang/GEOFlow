# 管理员 AI 模型隔离发布手册

本手册用于发布管理员个人模型与超级管理员共享模型能力。运行时固定执行个人模型优先、共享模型兜底；Shadow 只记录旧全局首选模型与安全首选模型的差异，不会调用旧全局模型。

## 发布前提

- 已有数据实例执行停机发布，停止 Web、队列、调度与 AI Worker。
- 数据库、`.env`、密钥存储和队列均已备份。
- 明确一位 legacy 超级管理员。存在多位活动超级管理员时必须显式指定 ID。
- 首轮配置保持以下值：

```env
GEOFLOW_ADMIN_AI_OWNERSHIP_WRITE_ENABLED=true
GEOFLOW_ADMIN_AI_SHADOW_ENABLED=true
GEOFLOW_ADMIN_AI_ACCESS_ENFORCE_ENABLED=false
GEOFLOW_ADMIN_AI_REVOCATION_ENFORCE_ENABLED=false
```

安全解析器在所有阶段持续生效。后两个开关控制历史队列身份兼容和撤权强制，不会恢复全局模型池。

## 一、迁移与回填

先记录稳定快照：管理员最大 ID、模型最大 ID、任务最大 ID、运行记录最大 ID，以及带时区的发布截止时间。随后运行预检：

```bash
php artisan geoflow:backfill-admin-ai-access \
  --legacy-owner=<超级管理员ID> \
  --created-before=<ISO-8601时间> \
  --admin-max-id=<管理员最大ID> \
  --model-max-id=<模型最大ID> \
  --task-max-id=<任务最大ID> \
  --task-run-max-id=<运行记录最大ID> \
  --dry-run
```

满足以下条件后，在维护模式执行回填：

- `System/user-content conflicts` 已人工分类。
- `Invalid system bindings` 为 0。
- `Execution identity blocking conflicts` 为 0。
- `Lifecycle identities mapped to legacy owner` 中的活动记录均已确认可以冻结并人工恢复。
- 预检输出不含未知管理员或未知模型。

```bash
php artisan down
php artisan geoflow:backfill-admin-ai-access \
  --legacy-owner=<超级管理员ID> \
  --created-before=<ISO-8601时间> \
  --admin-max-id=<管理员最大ID> \
  --model-max-id=<模型最大ID> \
  --task-max-id=<任务最大ID> \
  --task-run-max-id=<运行记录最大ID> \
  --apply \
  --maintenance-confirmed
```

重复执行同一组参数应显示 0 项新增变更。参数变化会触发冲突保护，需要重新制定并记录迁移批次。

回填同时覆盖任务、URL 导入、企业知识、标题生成、AI Workspace 和 Knowledge Fact。能够从创建者恢复的记录保存原执行管理员；无法可靠恢复的活动或可重试记录映射到 legacy 超级管理员并以 `ai_historical_identity_unresolved` 永久冻结，等待人工确认后重新创建。

## 二、Shadow 观察

恢复新版本服务后，持续观察 2 至 3 个自然日：

```bash
php artisan geoflow:admin-ai-shadow-report --hours=24
php artisan geoflow:admin-ai-shadow-report --hours=72 --json
```

发布门槛：

- `Models without owner` 为 0。
- `Execution identity gaps` 为 0；历史终态数据如需保留空身份，必须进入书面人工清单。
- `Safe model missing` 已处理，独立管理员确实未配置模型的情况需要产品确认。
- 首选模型差异均能归因于个人优先、共享绑定或 `system_only` 隔离。
- 共享成功调用的配置所有者与执行管理员均符合预期。
- 页面、接口、日志、异常和队列载荷中没有 API Key。

Shadow 表只保存管理员 ID、模型 ID、能力类型、差异类型和计数，不保存 API 地址、模型供应商标识、Prompt 或密钥。

每次真实供应商外呼会先写入不可变的用量起始记录。调度器每五分钟执行以下对账，超过 15 分钟仍没有终态的调用会追加 `ai_usage_outcome_missing` 失败事件：

```bash
php artisan geoflow:reconcile-ai-usage-attempts --older-than=900
```

## 三、开启强制边界

先选择测试管理员开启访问与撤权强制，验证 Web、API、CLI、队列、定时任务、重试和恢复。随后扩展到全部普通管理员：

```env
GEOFLOW_ADMIN_AI_ACCESS_ENFORCE_ENABLED=true
GEOFLOW_ADMIN_AI_REVOCATION_ENFORCE_ENABLED=true
```

修改环境变量后清理配置缓存并重启全部常驻进程。验收以下场景：

- 个人模型完整优先于共享模型。
- 普通管理员无法访问其他普通管理员模型。
- 超级管理员内容调用只使用本人 `user_content` 模型。
- 关闭共享、停用管理员、角色变化和模型归档立即阻止尚未外呼的请求。
- 撤权期间已返回的供应商结果以 `revoked` 或 `discarded` 记录，业务数据不落库。
- 缺少兼容 Embedding 模型时使用关键词检索并留下稳定原因。

## 四、数据库约束收口

第一版扩展迁移保留历史字段可空，保证旧数据可以先迁移再回填。正式约束收口安排在 Shadow 达标后的独立发布批次，执行前再次确认：

- `ai_models.owner_admin_id` 全量非空。
- 活动任务与运行记录的执行管理员、角色、访问版本和策略版本完整。
- 模型所有者、共享提供方和执行管理员外键均有效。
- 管理员删除依赖检查与模型所有者 `restrictOnDelete` 已通过生产数据库验证。

收口批次把 `ai_models.owner_admin_id` 设为非空，并按各运行表的生命周期决定历史终态字段是否保留可空。仍需保留的历史终态记录依靠运行时 fail-closed 与审计清单管理，不能为了满足非空约束猜测普通管理员身份。

## 回滚

普通管理员开始写入个人模型后，所有回滚都保留统一访问解析器和所有权字段。出现问题时执行：

1. 进入维护模式并暂停 AI Worker。
2. 保留模型所有权、执行身份、访问版本和用量账本。
3. 暂停受影响的界面入口或后台任务。
4. 部署兼容修复并完成 Shadow 对账。
5. 验证无跨管理员候选后恢复任务。

禁止部署会恢复全局 `AiModel` 查询的旧版本，也禁止重放缺少执行身份的旧队列载荷。

## 访问面门禁

机器清单位于 `tests/Fixtures/admin_ai_model_access_surface.php`。它保留 2026-09-01 的 35 个文件、83 处调用基线，并冻结当前每个直接模型查询的类别、负责人和数量。新增或移动直接查询时，架构测试会失败，变更必须同时完成权限归类和独立审查。
