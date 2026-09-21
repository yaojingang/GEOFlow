# RDS MySQL 8.0 隔离验收手册

本文面向阿里云 RDS MySQL 8.0（当前已知版本为 8.0.36、已开启向量存储）的 GEOFlow 首次部署。所有迁移和 DML 验证都应针对 GEOFlow 专用数据库或隔离测试库执行。

## 1. 先确认数据库边界

不要在包含其他业务数据的现有 schema 上执行 `migrate:fresh`、`DROP DATABASE` 或清表操作。建议在同一个 RDS 实例中创建一个独立的 GEOFlow 数据库，由有权限的 DBA 创建；应用运行账号只授予该数据库所需权限。

应用运行账号至少需要能够在该数据库中完成 Laravel 迁移和业务读写：

- `SELECT`、`INSERT`、`UPDATE`、`DELETE`；
- `CREATE`、`ALTER`、`DROP`、`INDEX`、`REFERENCES`；
- MySQL usage ledger 需要 `TRIGGER`；
- 能读取 `INFORMATION_SCHEMA`，用于向量能力和约束/触发器检查。

不要把 RDS 控制台的实例账号、密码或连接串提交到仓库。SAE 中的 `DB_PASSWORD` 使用 Secret 注入。

## 2. 在目标库执行只读能力检查

先连接到目标 GEOFlow 数据库，再执行以下只读 SQL：

```sql
SELECT VERSION() AS mysql_version, @@version_comment AS version_comment;

SELECT
    VECTOR_DIM(VEC_FROMTEXT('[1,0]')) AS vector_dimensions,
    VEC_DISTANCE_COSINE(
        VEC_FROMTEXT('[1,0]'),
        VEC_FROMTEXT('[1,0]')
    ) AS cosine_distance;

SHOW GRANTS;
```

预期版本为 MySQL 8.0.x；向量检查应返回 `2` 和 `0`。这只证明 RDS 的向量函数可用，不代表 GEOFlow 表结构已经完成。

## 3. 在隔离库执行 GEOFlow release

SAE 常驻 Web、Worker、Scheduler 和 Reverb 不执行迁移。发布顺序是：

1. GitHub Actions 构建并推送应用镜像和 Web 镜像。
2. 在 SAE 创建/更新一个一次性 `release` 任务，使用应用镜像。
3. 为 release 注入与 `.env.sae.example` 相同的 DB、Redis、APP_KEY 和网络配置。
4. 将 release 角色设置为：

   ```env
   GEOFLOW_SAE_RUNTIME=true
   GEOFLOW_ALLOW_MISSING_ENV_FILE=true
   SAE_ROLE=release
   SAE_RELEASE_CONFIRM=true
   AUTO_WAIT_FOR_DB=true
   AUTO_MIGRATE=true
   AUTO_INSTALL_ONCE=false
   AUTO_OPTIMIZE=true
   ```

5. release 成功后，再滚动更新 Web、Worker、Knowledge、Scheduler 和 Reverb 应用。

只有明确确认目标是全新的 GEOFlow 空库时，才把 `AUTO_INSTALL_ONCE` 改成 `true`。已有数据环境禁止用首次安装流程代替升级迁移。

## 4. 迁移后检查核心结构

在 release 成功后，用只读账号或 DBA 账号检查：

```sql
SHOW CREATE TABLE knowledge_chunks;

SELECT
    TABLE_NAME,
    COLUMN_NAME,
    DATA_TYPE,
    COLUMN_TYPE,
    IS_NULLABLE
FROM INFORMATION_SCHEMA.COLUMNS
WHERE TABLE_SCHEMA = DATABASE()
  AND (
      (TABLE_NAME = 'knowledge_chunks' AND COLUMN_NAME = 'embedding_vector')
      OR TABLE_NAME IN ('ai_model_usage_events', 'ai_model_usage_attempt_starts')
  )
ORDER BY TABLE_NAME, ORDINAL_POSITION;

SELECT
    CONSTRAINT_NAME,
    TABLE_NAME,
    CONSTRAINT_TYPE
FROM INFORMATION_SCHEMA.TABLE_CONSTRAINTS
WHERE CONSTRAINT_SCHEMA = DATABASE()
  AND TABLE_NAME IN ('ai_model_usage_events', 'ai_model_usage_attempt_starts')
ORDER BY TABLE_NAME, CONSTRAINT_NAME;

SELECT
    TRIGGER_NAME,
    EVENT_OBJECT_TABLE,
    EVENT_MANIPULATION,
    ACTION_TIMING
FROM INFORMATION_SCHEMA.TRIGGERS
WHERE TRIGGER_SCHEMA = DATABASE()
  AND EVENT_OBJECT_TABLE IN ('ai_model_usage_events', 'ai_model_usage_attempt_starts')
ORDER BY EVENT_OBJECT_TABLE, TRIGGER_NAME;
```

预期结果：

- `knowledge_chunks.embedding_vector` 是 `VECTOR(3072)`；
- usage event 表有非负、归属和 digest 约束；
- usage event 与 attempt start 表都有 update/delete append-only 触发器；
- 不应出现 PostgreSQL 的 `jsonb` 类型或 `CREATE EXTENSION` 失败记录。

## 5. 验证向量写入和检索

不要直接修改线上知识库数据来做探针。先在隔离库中通过应用创建一条测试知识库并完成一次向量化，然后检查：

```sql
SELECT
    id,
    embedding_dimensions,
    embedding_provider,
    VECTOR_DIM(embedding_vector) AS stored_dimensions
FROM knowledge_chunks
WHERE embedding_vector IS NOT NULL
ORDER BY id DESC
LIMIT 5;
```

应用层会使用 `VEC_FROMTEXT(...)` 写入 MySQL `VECTOR` 列，并使用 `VEC_DISTANCE_COSINE(...)` 做候选排序。阿里云 RDS MySQL 向量存储语法和索引能力以[官方文档](https://help.aliyun.com/zh/rds/apsaradb-rds-for-mysql/vector-storage-1)为准；当前代码先使用精确扫描，待隔离库基准测试后再单独评估 HNSW 索引，不在首次迁移中盲目创建索引。

## 6. 验证 usage ledger 约束

以下操作应在隔离库/测试数据上验证，不能对正式账本随意执行：

- 负数 token 或 cost 被拒绝；
- 大写或长度不是 64 的 digest 被拒绝；
- 不合法的 execution scope / model source / admin 组合被拒绝；
- 更新或删除 usage event、attempt start 被 `SIGNAL SQLSTATE '45000'` 拒绝；
- 合法的 `system`、`interactive_admin`、`persisted_admin` 记录仍能插入。

MySQL 触发器是按行执行的；这部分与 PostgreSQL 的 statement-level 触发器语义不同，代码没有把 PostgreSQL transition table 伪装成 MySQL 触发器。

## 7. 上线门槛

以下条件全部满足后，才把 Web 从测试域名切到正式域名：

- release 迁移成功且没有 pending migration；
- RDS MySQL 向量函数、`VECTOR(3072)` 列、知识库写入和检索通过；
- Redis PING、队列消费、Scheduler 单副本和 Reverb 握手通过；
- NAS 挂载后的上传、图片、导出和重启后读取通过；
- SAE Web `/up`、Nginx/PHP-FPM 进程和日志正常；
- 已记录上一版本 commit tag，并确认可以回滚应用版本；
- 数据库变更已有备份和 forward-fix 方案。

数据库 migration 不是应用镜像回滚的一部分。若 migration 已执行，回滚应用镜像前必须确认旧版本能够读取新 schema；不能把 SAE 版本回滚当成数据库回滚。
