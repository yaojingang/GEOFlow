# Руководство по GEOFlow CLI

> Languages: [简体中文](GEOFLOW_CLI.md) | [English](GEOFLOW_CLI_en.md) | [日本語](GEOFLOW_CLI_ja.md) | [Español](GEOFLOW_CLI_es.md) | [Русский](GEOFLOW_CLI_ru.md)

`geoflow` — локальная CLI-точка входа для API первой очереди.

Он работает с GEOFlow только через `/api/v1`. Напрямую в БД не ходит и не использует веб-сессию админки.

## 1. Точка входа

```bash
./bin/geoflow help
```

## 2. Приоритет конфигурации

Источники:

1. флаги CLI
2. переменные окружения
3. конфигурационные файлы

Приоритет:

`CLI flags > environment variables > .geoflow.json > ~/.config/geoflow/config.json`

## 3. Рекомендуемый первый вход

```bash
./bin/geoflow \
  login \
  --base-url http://127.0.0.1:18080 \
  --username admin
```

Если `--password` не передан, CLI запросит его безопасно.

Если токен уже есть, можно инициализировать вручную:

```bash
./bin/geoflow \
  config init \
  --base-url http://127.0.0.1:18080 \
  --token gf_xxx
```

Показать текущую конфигурацию:

```bash
./bin/geoflow config show
```

## 4. Частые команды

Получить catalog:

```bash
./bin/geoflow catalog
```

Список задач:

```bash
./bin/geoflow task list --status active
```

Создать задачу:

```bash
./bin/geoflow task create --json ./task.json
```

Запустить и поставить в очередь:

```bash
./bin/geoflow task start 12
./bin/geoflow task enqueue 12
```

Проверить jobs:

```bash
./bin/geoflow task jobs 12 --limit 20
./bin/geoflow job get 88
```

Создать статью:

```bash
./bin/geoflow article create \
  --title "CLI test article" \
  --content-file ./article.md \
  --task-id 12 \
  --author-id 5 \
  --category-id 2
```

Ревью и публикация:

```bash
./bin/geoflow article review 101 --status approved --note "CLI review pass"
./bin/geoflow article publish 101
```

## 5. Примеры JSON

Создание задачи:

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

Создание статьи:

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

## 6. Идемпотентный ключ

Все write-команды поддерживают:

```text
--idempotency-key <key>
```

## 7. Текущий охват

Поддерживается:

- `login`
- `catalog`
- `task list/create/get/update/start/stop/enqueue/jobs`
- `job get`
- `article list/create/get/update/review/publish/trash`

Пока не покрыто:

- URL import
- асинхронная генерация заголовков
- orchestration загрузки изображений
- высокоуровневые batch workflow
