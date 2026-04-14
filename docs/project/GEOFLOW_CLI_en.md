# GEOFlow CLI Guide

> Languages: [简体中文](GEOFLOW_CLI.md) | [English](GEOFLOW_CLI_en.md) | [日本語](GEOFLOW_CLI_ja.md) | [Español](GEOFLOW_CLI_es.md) | [Русский](GEOFLOW_CLI_ru.md)

`geoflow` is the local CLI entry for the first-stage API.

It talks to GEOFlow only through the formal `/api/v1` endpoints. It does not access the database directly and does not reuse the admin web session.

## 1. Entry

```bash
./bin/geoflow help
```

## 2. Configuration Priority

Supported sources:

1. CLI flags
2. environment variables
3. config files

Priority:

`CLI flags > environment variables > .geoflow.json > ~/.config/geoflow/config.json`

## 3. Recommended First Login

```bash
./bin/geoflow \
  login \
  --base-url http://127.0.0.1:18080 \
  --username admin
```

If `--password` is omitted, the CLI prompts for it securely.

If you already have a token, you can also initialize manually:

```bash
./bin/geoflow \
  config init \
  --base-url http://127.0.0.1:18080 \
  --token gf_xxx
```

Check current config:

```bash
./bin/geoflow config show
```

## 4. Common Commands

Fetch catalog:

```bash
./bin/geoflow catalog
```

List tasks:

```bash
./bin/geoflow task list --status active
```

Create a task:

```bash
./bin/geoflow task create --json ./task.json
```

Start and enqueue:

```bash
./bin/geoflow task start 12
./bin/geoflow task enqueue 12
```

Query jobs:

```bash
./bin/geoflow task jobs 12 --limit 20
./bin/geoflow job get 88
```

Create an article:

```bash
./bin/geoflow article create \
  --title "CLI test article" \
  --content-file ./article.md \
  --task-id 12 \
  --author-id 5 \
  --category-id 2
```

Review and publish:

```bash
./bin/geoflow article review 101 --status approved --note "CLI review pass"
./bin/geoflow article publish 101
```

## 5. JSON Input Examples

Task creation:

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

Article creation:

```json
{
  "title": "CLI Article Test",
  "content": "# CLI Article Test\n\nThis article was created through the CLI.",
  "task_id": 12,
  "author_id": 5,
  "category_id": 2,
  "status": "draft",
  "review_status": "pending",
  "keywords": "cli,test",
  "meta_description": "CLI article integration test"
}
```

## 6. Idempotency Key

All write commands support:

```text
--idempotency-key <key>
```

Recommended for:

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

## 7. Current Coverage

Current CLI coverage:

- `login`
- `catalog`
- `task list/create/get/update/start/stop/enqueue/jobs`
- `job get`
- `article list/create/get/update/review/publish/trash`

Not covered yet:

- URL import
- async title generation
- image upload orchestration
- higher-level batch workflows
