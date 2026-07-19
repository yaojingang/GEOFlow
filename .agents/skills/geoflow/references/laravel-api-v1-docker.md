<!--
Copyright © 2026 姚金刚. All rights reserved.
Project: geoflow
Created by: 姚金刚
Date: 2026-05-16
X: https://x.com/yaojingang
-->

# Laravel API v1, Docker, And Admin Fallback

Use this reference when the target GEOFlow workspace is a Laravel app and no `bin/geoflow` wrapper is available. `/api/v1` is the normal scriptable content-operations path. Current GEOFlow also has many admin-only workflows under `/admin/*`; use authenticated admin web for those.

## Detection

A Laravel GEOFlow workspace should contain:

- `artisan`
- `routes/api.php`
- `app/Http/Controllers/Api/V1`
- `docker-compose.yml` or `compose.yml` when deployed through Docker

Do not assume old root-level PHP entrypoints exist.

## API Base URL

`GEOFLOW_BASE_URL` must point to the public web root:

```bash
export GEOFLOW_BASE_URL="http://127.0.0.1:18080"
```

Do not set it to:

- `http://127.0.0.1:18080/api/v1`
- `http://127.0.0.1:18080/geo_admin`
- an internal Docker service name unless the agent is running inside the same network
- a proxy endpoint that returns an HTML error page

The skill appends `/api/v1/...` itself.

## Required Token

API fallback requires:

Load the token from a secret manager or a hidden interactive prompt. Avoid placing the value in shell history:

```bash
read -rsp 'GEOFlow API token: ' GEOFLOW_API_TOKEN
printf '\n'
export GEOFLOW_API_TOKEN
```

The token must allow the scopes needed for the requested operation:

- `catalog:read`
- `tasks:read`
- `tasks:write`
- `jobs:read`
- `articles:read`
- `articles:write`
- `articles:publish`
- `materials:read`
- `materials:write`

If no token exists, prefer the interactive local CLI login. For an API-only installation, use the protected request/response-file flow in [command-map.md](command-map.md#first-login); it keeps the password and returned token out of process arguments and terminal output.

The current login endpoint returns a token with all available API v1 scopes for the authenticated admin. Do not print the full token in user-facing output.

## Docker Checks

From the GEOFlow workspace:

```bash
docker compose ps
docker compose exec app php artisan route:list --path=api/v1
docker compose exec app php artisan about
```

If the PHP service name is not `app`, inspect `docker compose ps` and use the actual service name.

For production compose, the app may run behind an Nginx `web` service and PHP-FPM `app` service. Browser/API access should use the exposed web port, not the internal PHP-FPM port.

For database host issues:

- inside Docker Compose, `DB_HOST=postgres` is usually correct when the service is named `postgres`
- outside Docker Compose, `postgres` will not resolve unless DNS/hosts provides it
- browser access should use the exposed host port, for example `127.0.0.1:18080`

For queue behavior:

- generation jobs use the `geoflow` queue
- distribution jobs use the `distribution` queue
- current API v1 does not manage distribution channels, target packages, Analytics, URL Import, System Updates, Theme Replication, API Tokens, admin users, AI configuration, site settings, or security settings

## Preflight

From a GEOFlow repository checkout, run the project-local Skill:

```bash
.agents/skills/geoflow/scripts/geoflow_preflight.sh "/path/to/GEOFlow"
```

For material work:

```bash
.agents/skills/geoflow/scripts/geoflow_preflight.sh "/path/to/GEOFlow" "" catalog,materials
```

For admin-web work:

```bash
.agents/skills/geoflow/scripts/geoflow_preflight.sh "/path/to/GEOFlow" "" admin
```

From a global Codex installation, replace the script path with `~/.codex/skills/geoflow/scripts/geoflow_preflight.sh`. When working from inside the Skill directory, `scripts/geoflow_preflight.sh` is the package-root-relative form. The helper requires Bash, Python 3.10+, and `curl`; a non-executable legacy `bin/geoflow` also requires PHP CLI.

Preflight is successful only when authenticated API reads return JSON. A public homepage check is not sufficient.

## Non-JSON Response Diagnosis

If the response body starts with HTML, for example:

```text
<!doctype html>
```

Do not diagnose this as an AI response-format error. It usually means:

- `GEOFLOW_BASE_URL` points to the wrong path
- the app is behind a proxy error page
- the request reached the frontend/admin web route instead of `/api/v1`
- the Docker service is not exposing the Laravel app on the expected port
- the server returned a framework error page instead of JSON

Correct the URL, proxy, container, or Laravel route setup before retrying business operations.

## Current API Surface

The Laravel API v1 paths are:

- `POST /api/v1/auth/login`
- `GET /api/v1/catalog`
- `GET /api/v1/tasks`
- `POST /api/v1/tasks`
- `GET /api/v1/tasks/{id}`
- `PATCH /api/v1/tasks/{id}`
- `DELETE /api/v1/tasks/{id}`
- `POST /api/v1/tasks/{id}/start`
- `POST /api/v1/tasks/{id}/stop`
- `POST /api/v1/tasks/{id}/enqueue`
- `GET /api/v1/tasks/{id}/jobs`
- `GET /api/v1/jobs/{id}`
- `GET /api/v1/materials`
- `GET /api/v1/materials/{type}`
- `POST /api/v1/materials/{type}`
- `GET /api/v1/materials/{type}/{id}`
- `PATCH /api/v1/materials/{type}/{id}`
- `DELETE /api/v1/materials/{type}/{id}`
- `GET /api/v1/materials/{type}/{id}/items`
- `POST /api/v1/materials/{type}/{id}/items`
- `DELETE /api/v1/materials/{type}/{id}/items`
- `GET /api/v1/articles`
- `POST /api/v1/articles`
- `GET /api/v1/articles/{id}`
- `PATCH /api/v1/articles/{id}`
- `POST /api/v1/articles/{id}/review`
- `POST /api/v1/articles/{id}/publish`
- `POST /api/v1/articles/{id}/trash`

Mutating endpoints should use `X-Idempotency-Key`.

## Material Types and Payload Notes

Material types:

- `categories`: `name`, optional `slug`, `description`, `sort_order`
- `authors`: `name`, optional `email`, `bio`, `avatar`, `website`, `social_links`
- `keyword-libraries`: `name`, optional `description`
- `title-libraries`: `name`, optional `description`
- `image-libraries`: `name`, optional `description`
- `knowledge-bases`: `name`, `content`, optional `description`, `file_type`, `file_path`

Writable item endpoints:

- `keyword-libraries/{id}/items`: create with `keyword`
- `title-libraries/{id}/items`: create with `title`, optional `keyword`
- `image-libraries/{id}/items`: create with `file_path`, optional file metadata

Knowledge-base item listings expose chunks; chunk writes happen by updating the parent knowledge-base content.

## Task Payload Notes

Required on create:

- `name`
- `title_library_id`
- `prompt_id`
- `ai_model_id`

Common optional fields:

- `author_id`
- `image_library_id`
- `image_count`
- `knowledge_base_id`
- `knowledge_base_ids`: up to five IDs; takes precedence over `knowledge_base_id`
- `fixed_category_id`
- `category_mode`: `smart` or `fixed`
- `model_selection_mode`: `fixed` or `smart_failover`
- `status`: `active` or `paused`
- `publish_scope`: `local_and_distribution`, `distribution_only`, or `local_only`
- `distribution_strategy`: `broadcast`, `round_robin`, or `random_balanced`
- `draft_limit`, `article_limit`, `publish_interval`
- `need_review`, `is_loop`, `auto_keywords`, `auto_description`

Optional material fields may be omitted and will be persisted as `null` on create.

API v1 task responses may include `task_progress`, `queue_overview`, `schedule_enabled`, `knowledge_base_ids`, `knowledge_bases`, distribution counters, and latest job status fields. Read back `GET /api/v1/tasks/{id}` after writes.

## Admin Fallback Boundary

Current GEOFlow has admin UI capabilities including Distribution Management, target-site packages, WordPress REST channels, generic HTTP API channels, frontend-capability refresh, target settings-sync preview, logs, queue retry, remote article management, enterprise knowledge drafting/publish, growth-center lead forms/leads/export, Analytics, URL Import, System Updates, Theme Replication, Theme Editor, site settings, AI configuration, API tokens, and admin users. These are not part of the current `/api/v1` operations surface unless route inspection proves otherwise.

Use API v1 only for the exposed task/article/material operations. When an operation requires an admin-only capability, switch to authenticated admin web routes if the user has provided or already has a valid admin session. Otherwise report the missing admin session/prerequisite.
