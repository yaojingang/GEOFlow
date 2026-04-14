# GEOFlow CLI 使用说明

> Languages: [简体中文](GEOFLOW_CLI.md) | [English](GEOFLOW_CLI_en.md) | [日本語](GEOFLOW_CLI_ja.md) | [Español](GEOFLOW_CLI_es.md) | [Русский](GEOFLOW_CLI_ru.md)

`geoflow` 是第一阶段 API 的本地命令行入口。

它只通过正式 `/api/v1` 与系统通信，不直接访问数据库，也不复用后台 session。

## 1. 命令入口

```bash
./bin/geoflow help
```

## 2. 配置优先级

支持三种来源：

1. CLI 参数
2. 环境变量
3. 配置文件

优先级：

`CLI 参数 > 环境变量 > .geoflow.json > ~/.config/geoflow/config.json`

## 3. 推荐首次登录

```bash
./bin/geoflow \
  login \
  --base-url http://127.0.0.1:18080 \
  --username admin
```

如果不传 `--password`，CLI 会在终端里安全提示输入密码。

如果你已经有 token，也可以手动初始化：

```bash
./bin/geoflow \
  config init \
  --base-url http://127.0.0.1:18080 \
  --token gf_xxx
```

查看当前配置：

```bash
./bin/geoflow config show
```

## 4. 常用命令

获取资源字典：

```bash
./bin/geoflow catalog
```

查询任务：

```bash
./bin/geoflow task list --status active
```

创建任务：

```bash
./bin/geoflow task create --json ./task.json
```

启动任务并立即入队：

```bash
./bin/geoflow task start 12
./bin/geoflow task enqueue 12
```

查看任务和 job：

```bash
./bin/geoflow task jobs 12 --limit 20
./bin/geoflow job get 88
```

上传文章草稿：

```bash
./bin/geoflow article create \
  --title "CLI 测试文章" \
  --content-file ./article.md \
  --task-id 12 \
  --author-id 5 \
  --category-id 2
```

审核并发布：

```bash
./bin/geoflow article review 101 --status approved --note "CLI review pass"
./bin/geoflow article publish 101
```

## 5. JSON 输入示例

任务创建：

```json
{
  "name": "CLI Task Integration Test",
  "title_library_id": 1,
  "prompt_id": 6,
  "ai_model_id": 1,
  "knowledge_base_id": 1,
  "author_id": 5,
  "image_library_id": null,
  "image_count": 0,
  "need_review": true,
  "publish_interval": 3600,
  "auto_keywords": true,
  "auto_description": true,
  "draft_limit": 3,
  "is_loop": false,
  "status": "paused",
  "category_mode": "smart",
  "fixed_category_id": null
}
```

文章创建：

```json
{
  "title": "CLI Article Test",
  "content": "# CLI Article Test\n\n这是通过 CLI 创建的文章。",
  "task_id": 12,
  "author_id": 5,
  "category_id": 2,
  "status": "draft",
  "review_status": "pending",
  "keywords": "cli,test",
  "meta_description": "CLI article integration test"
}
```

## 6. 幂等键

所有写操作都支持：

```text
--idempotency-key <key>
```

推荐用于：

- `task create`
- `task update`
- `task start`
- `task stop`
- `task enqueue`
- `article create`
- `article update`
- `article review`
- `article publish`
- `article trash`

## 7. 当前支持范围

当前 CLI 已覆盖：

- `login`
- `catalog`
- `task list/create/get/update/start/stop/enqueue/jobs`
- `job get`
- `article list/create/get/update/review/publish/trash`

当前还没有纳入：

- URL 导入
- 标题异步生成
- 图片上传编排
- 更高阶的批量工作流
