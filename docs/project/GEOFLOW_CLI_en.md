# GEOFlow CLI User Guide

> Languages: [简体中文](GEOFLOW_CLI.md) | [English](GEOFLOW_CLI_en.md) | [日本語](GEOFLOW_CLI_ja.md) | [Español](GEOFLOW_CLI_es.md) | [Русский](GEOFLOW_CLI_ru.md)

`geoflow` is the local command-line entry point for the Phase 1 API.

It communicates with the system exclusively through the official `/api/v1` — it does not directly access the database, nor does it reuse admin sessions.

---

## 1. Command Entry

Project command entry:

```bash
php bin/geoflow help
```

You can also grant the script execute permission and run it directly:

```bash
./bin/geoflow help
```

---

## 2. Configuration

Three methods are supported for providing connection information:

1. CLI parameters
2. Environment variables
3. Configuration file

Priority:

`CLI parameters > Environment variables > .geoflow.json > ~/.config/geoflow/config.json`

### 2.1 Recommended Login

It is recommended to first log in using the admin username and password. The CLI will automatically exchange them for a token and save the local configuration:

```bash
php bin/geoflow \
  login \
  --base-url http://127.0.0.1:18080 \
  --username admin
```

If `--password` is not provided, the CLI will securely prompt for the password in the terminal.

### 2.2 Manual Initialization

If you already have a usable token, you can initialize manually:

```bash
php bin/geoflow \
  config init \
  --base-url http://127.0.0.1:18080 \
  --token gf_xxx
```

By default, this writes to:

```text
~/.config/geoflow/config.json
```

Configuration file format:

```json
{
  "base_url": "http://127.0.0.1:18080",
  "token": "gf_xxx",
  "timeout": 30
}
```

### 2.3 View Current Configuration

```bash
php bin/geoflow config show
```

### 2.4 Temporary Override

```bash
php bin/geoflow \
  --config /tmp/geoflow.json \
  catalog
```

---

## 3. Common Commands

### 3.1 Get Resource Catalog

```bash
php bin/geoflow catalog
```

### 3.2 First Login Example

```bash
php bin/geoflow \
  login \
  --base-url http://127.0.0.1:18080 \
  --username admin
```

Or explicitly pass the password:

```bash
php bin/geoflow \
  login \
  --base-url http://127.0.0.1:18080 \
  --username admin \
  --password your-password
```

### 3.3 Task Management

List tasks:

```bash
php bin/geoflow task list --status active
```

Create a task:

```bash
php bin/geoflow task create --json ./task.json
```

Start a task:

```bash
php bin/geoflow task start 12
```

Manually enqueue:

```bash
php bin/geoflow task enqueue 12
```

View task jobs:

```bash
php bin/geoflow task jobs 12 --limit 20
php bin/geoflow job get 88
```

### 3.4 Article Management

Upload an article draft directly:

```bash
php bin/geoflow article create \
  --title "CLI Test Article" \
  --content-file ./article.md \
  --task-id 12 \
  --author-id 5 \
  --category-id 2
```

Create via JSON:

```bash
php bin/geoflow article create --json ./article.json
```

Approve review:

```bash
php bin/geoflow article review 101 \
  --status approved \
  --note "CLI review pass"
```

Publish article:

```bash
php bin/geoflow article publish 101
```

---

## 4. JSON Input

### 4.1 Task Creation Example

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

### 4.2 Article Creation Example

```json
{
  "title": "CLI Article Test",
  "content": "# CLI Article Test\n\nThis is an article created via CLI.",
  "task_id": 12,
  "author_id": 5,
  "category_id": 2,
  "status": "draft",
  "review_status": "pending",
  "keywords": "cli,test",
  "meta_description": "CLI article integration test"
}
```

---

## 5. Idempotency Key

All write operations support:

```text
--idempotency-key <key>
```

Recommended for these commands:

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

This way, the CLI or subsequent skills won't create duplicate resources during retries.

---

## 6. Current Scope

The current CLI covers the Phase 1 API main pipeline:

- `login`
- `catalog`
- `task list/create/get/update/start/stop/enqueue/jobs`
- `job get`
- `article list/create/get/update/review/publish/trash`

Not yet included:

- URL import
- Async title generation
- Image upload orchestration
- Batch workflow encapsulation

These are better suited for higher-level encapsulation through skills in the next phase.
