<!--
Copyright © 2026 姚金刚. All rights reserved.
Project: geoflow
Created by: 姚金刚
Date: 2026-05-16
X: https://x.com/yaojingang
-->

# Command Map

## Current Capability Rule

Prefer `bin/geoflow` only when it exists and `--help` confirms the requested action. Current Laravel GEOFlow deployments may expose `/api/v1` without a `bin/geoflow` wrapper, so API fallback is expected there.

Do not invent CLI subcommands for actions that only exist in API v1. When the CLI is absent, use the protected header-file setup below and add `X-Idempotency-Key` for writes.

For GEOFlow admin-only capabilities, use authenticated admin web routes. Do not claim they are API v1 endpoints unless `php artisan route:list --path=api/v1` proves it in the target workspace.

## Preflight

```bash
scripts/geoflow_preflight.sh "<workspace>" [config] [checks]
```

`checks` is optional and comma-separated for API fallback. Examples:

```bash
scripts/geoflow_preflight.sh "/path/to/GEOFlow"
scripts/geoflow_preflight.sh "/path/to/GEOFlow" "" catalog,materials
scripts/geoflow_preflight.sh "/path/to/GEOFlow" "" admin
GEOFLOW_PREFLIGHT_CHECKS=catalog,materials scripts/geoflow_preflight.sh "/path/to/GEOFlow"
```

The preflight supports two modes:

- CLI mode when `<workspace>/bin/geoflow` exists.
- API fallback mode when the CLI is absent and `GEOFLOW_BASE_URL` plus `GEOFLOW_API_TOKEN` are available.
- Admin login-page check when `checks` includes `admin`; set `GEOFLOW_ADMIN_PATH` if the admin prefix is not `/admin`.

For the Laravel rewrite without a CLI wrapper, `GEOFLOW_BASE_URL` must point to the public web root, for example `http://127.0.0.1:18080`, not `/geo_admin`, not `/api/v1`, and not a proxy error page.

## First Login

Interactive password prompt:

```bash
"/path/to/workspace/bin/geoflow" login --base-url https://your-geoflow-host --username admin
```

When config exists but the token is invalid or expired, refresh it in place:

```bash
"/path/to/workspace/bin/geoflow" login --base-url https://your-geoflow-host --username admin --force
```

Keep passwords out of command arguments and shell history. Validate the base URL first; authenticated remote hosts require HTTPS, while plain HTTP is limited to loopback development:

```bash
geoflow_curl_proto="$(python3 - "$GEOFLOW_BASE_URL" <<'PY'
import ipaddress
import sys
from urllib.parse import urlsplit

parsed = urlsplit(sys.argv[1])
if parsed.scheme not in {"http", "https"} or not parsed.hostname:
    raise SystemExit("GEOFLOW_BASE_URL must be an http(s) URL with a hostname")
if parsed.username is not None or parsed.password is not None or parsed.query or parsed.fragment:
    raise SystemExit("GEOFLOW_BASE_URL must not contain credentials, query, or fragment")
host = parsed.hostname.rstrip(".").lower()
loopback = host == "localhost" or host.endswith(".localhost")
if not loopback:
    try:
        loopback = ipaddress.ip_address(host).is_loopback
    except ValueError:
        pass
if parsed.scheme == "http" and not loopback:
    raise SystemExit("Authenticated non-loopback GEOFlow hosts require HTTPS")
print("=http,https" if loopback else "=https")
PY
)" || exit 1
```

For an API-only installation, build a mode-`0600` request file with an interactive prompt, capture the response in another protected file, and load the token without printing it:

```bash
geoflow_login_request="$(mktemp)"
geoflow_login_response="$(mktemp)"
chmod 600 "$geoflow_login_request" "$geoflow_login_response"
trap 'rm -f "$geoflow_login_request" "$geoflow_login_response" "${geoflow_auth_header:-}"' EXIT
python3 - "$geoflow_login_request" <<'PY'
import getpass
import json
import pathlib
import sys

username = input("GEOFlow admin username: ")
password = getpass.getpass("GEOFlow admin password: ")
pathlib.Path(sys.argv[1]).write_text(
    json.dumps({"username": username, "password": password}),
    encoding="utf-8",
)
PY
curl --disable --proto "$geoflow_curl_proto" -sS --fail-with-body --max-time 20 --max-filesize 5242880 -X POST \
  -H "Accept: application/json" \
  -H "Content-Type: application/json" \
  --data-binary "@$geoflow_login_request" \
  --output "$geoflow_login_response" \
  "$GEOFLOW_BASE_URL/api/v1/auth/login"
GEOFLOW_API_TOKEN="$(python3 - "$geoflow_login_response" <<'PY'
import json
import pathlib
import sys

payload = json.loads(pathlib.Path(sys.argv[1]).read_text(encoding="utf-8"))
token = payload.get("token") or payload.get("data", {}).get("token")
if not token:
    raise SystemExit("Login response did not contain a token")
print(token)
PY
)"
export GEOFLOW_API_TOKEN
```

Before the API examples below, write the token to a protected curl header file. The secret stays out of curl arguments and the file is removed by the existing trap:

```bash
geoflow_auth_header="$(mktemp)"
chmod 600 "$geoflow_auth_header"
printf 'Authorization: Bearer %s\n' "$GEOFLOW_API_TOKEN" > "$geoflow_auth_header"
trap 'rm -f "${geoflow_login_request:-}" "${geoflow_login_response:-}" "${geoflow_auth_header:-}"' EXIT
```

Do not print the full token or protected response file in user-facing output.

## Catalog

```bash
"/path/to/workspace/bin/geoflow" --config /path/to/config catalog
```

Use this as the authoritative authenticated-read check before mutating commands. Only jump to `login --force` when the failure is clearly `401`, `403`, or token-invalid.

API fallback:

```bash
curl --disable --proto "$geoflow_curl_proto" -sS --fail-with-body --max-time 20 --max-filesize 5242880 \
  --header "@$geoflow_auth_header" \
  -H "Accept: application/json" \
  "$GEOFLOW_BASE_URL/api/v1/catalog"
```

Current catalog response includes `models`, `prompts`, `keyword_libraries`, `title_libraries`, `image_libraries`, `knowledge_bases`, `authors`, and `categories`.

If this returns HTML such as `<!doctype html>`, treat it as a base URL/proxy/routing problem, not an AI response-format problem. See [laravel-api-v1-docker.md](laravel-api-v1-docker.md).

## Material Operations

Material API types:

- `categories`
- `authors`
- `keyword-libraries`
- `title-libraries`
- `image-libraries`
- `knowledge-bases`

Aliases accepted by the API include `keywords`, `titles`, `images`, and `knowledge`.

Required scopes:

- read: `materials:read`
- write: `materials:write`

Summary:

```bash
curl --disable --proto "$geoflow_curl_proto" -sS --fail-with-body --max-time 20 --max-filesize 5242880 \
  --header "@$geoflow_auth_header" \
  -H "Accept: application/json" \
  "$GEOFLOW_BASE_URL/api/v1/materials"
```

List one material type:

```bash
curl --disable --proto "$geoflow_curl_proto" -sS --fail-with-body --max-time 20 --max-filesize 5242880 \
  --header "@$geoflow_auth_header" \
  -H "Accept: application/json" \
  "$GEOFLOW_BASE_URL/api/v1/materials/keyword-libraries?search=geo&per_page=20"
```

Create a material library:

```bash
curl --disable --proto "$geoflow_curl_proto" -sS --fail-with-body --max-time 20 --max-filesize 5242880 -X POST \
  --header "@$geoflow_auth_header" \
  -H "Accept: application/json" \
  -H "Content-Type: application/json" \
  -H "X-Idempotency-Key: material-keyword-library-001" \
  --data '{"name":"API Keywords","description":"Created from API"}' \
  "$GEOFLOW_BASE_URL/api/v1/materials/keyword-libraries"
```

Get, update, or delete a material:

```bash
curl --disable --proto "$geoflow_curl_proto" -sS --fail-with-body --max-time 20 --max-filesize 5242880 \
  --header "@$geoflow_auth_header" \
  -H "Accept: application/json" \
  "$GEOFLOW_BASE_URL/api/v1/materials/keyword-libraries/12"

curl --disable --proto "$geoflow_curl_proto" -sS --fail-with-body --max-time 20 --max-filesize 5242880 -X PATCH \
  --header "@$geoflow_auth_header" \
  -H "Accept: application/json" \
  -H "Content-Type: application/json" \
  -H "X-Idempotency-Key: material-keyword-library-update-12" \
  --data '{"description":"Updated from API"}' \
  "$GEOFLOW_BASE_URL/api/v1/materials/keyword-libraries/12"

curl --disable --proto "$geoflow_curl_proto" -sS --fail-with-body --max-time 20 --max-filesize 5242880 -X DELETE \
  --header "@$geoflow_auth_header" \
  -H "Accept: application/json" \
  -H "X-Idempotency-Key: material-keyword-library-delete-12" \
  "$GEOFLOW_BASE_URL/api/v1/materials/keyword-libraries/12"
```

List material items:

```bash
curl --disable --proto "$geoflow_curl_proto" -sS --fail-with-body --max-time 20 --max-filesize 5242880 \
  --header "@$geoflow_auth_header" \
  -H "Accept: application/json" \
  "$GEOFLOW_BASE_URL/api/v1/materials/keyword-libraries/12/items?per_page=50"
```

Create material items:

```bash
curl --disable --proto "$geoflow_curl_proto" -sS --fail-with-body --max-time 20 --max-filesize 5242880 -X POST \
  --header "@$geoflow_auth_header" \
  -H "Accept: application/json" \
  -H "Content-Type: application/json" \
  -H "X-Idempotency-Key: keyword-item-create-001" \
  --data '{"keyword":"geo automation"}' \
  "$GEOFLOW_BASE_URL/api/v1/materials/keyword-libraries/12/items"

curl --disable --proto "$geoflow_curl_proto" -sS --fail-with-body --max-time 20 --max-filesize 5242880 -X POST \
  --header "@$geoflow_auth_header" \
  -H "Accept: application/json" \
  -H "Content-Type: application/json" \
  -H "X-Idempotency-Key: title-item-create-001" \
  --data '{"title":"GEO automation guide","keyword":"geo automation"}' \
  "$GEOFLOW_BASE_URL/api/v1/materials/title-libraries/34/items"
```

Delete material items:

```bash
curl --disable --proto "$geoflow_curl_proto" -sS --fail-with-body --max-time 20 --max-filesize 5242880 -X DELETE \
  --header "@$geoflow_auth_header" \
  -H "Accept: application/json" \
  -H "Content-Type: application/json" \
  -H "X-Idempotency-Key: keyword-items-delete-001" \
  --data '{"ids":[101,102]}' \
  "$GEOFLOW_BASE_URL/api/v1/materials/keyword-libraries/12/items"
```

Knowledge-base items are generated chunks and are read-only through `/items`. To change chunks, update the knowledge-base `content`.

## Task Operations

List tasks:

```bash
"/path/to/workspace/bin/geoflow" --config /path/to/config task list --status active --per-page 20
```

API fallback:

```bash
curl --disable --proto "$geoflow_curl_proto" -sS --fail-with-body --max-time 20 --max-filesize 5242880 \
  --header "@$geoflow_auth_header" \
  -H "Accept: application/json" \
  "$GEOFLOW_BASE_URL/api/v1/tasks?status=active&search=geo&per_page=20"
```

Create task:

```bash
"/path/to/workspace/bin/geoflow" --config /path/to/config task create --json ./task.json --idempotency-key task-create-001
```

Useful task JSON fields:

```json
{
  "name": "API task",
  "title_library_id": 1,
  "prompt_id": 2,
  "ai_model_id": 3,
  "status": "paused",
  "category_mode": "smart",
  "model_selection_mode": "fixed",
  "publish_scope": "local_and_distribution",
  "distribution_strategy": "broadcast",
  "knowledge_base_ids": [4, 5],
  "image_library_id": 6,
  "image_count": 2,
  "author_id": 7,
  "fixed_category_id": 8,
  "need_review": 1,
  "is_loop": 0,
  "auto_keywords": 1,
  "auto_description": 1,
  "draft_limit": 5,
  "article_limit": 10,
  "publish_interval": 3600
}
```

`author_id`, `image_library_id`, `knowledge_base_id`, `knowledge_base_ids`, and `fixed_category_id` are optional. `knowledge_base_ids` may contain up to five IDs and takes precedence over legacy `knowledge_base_id`. `publish_scope` is one of `local_and_distribution`, `distribution_only`, or `local_only`. `distribution_strategy` is one of `broadcast`, `round_robin`, or `random_balanced`. API v1 accepts `publish_interval` in seconds.

API v1 does not bind `distribution_channel_ids`. Use admin web task create/update routes when the task must select concrete distribution channels.

API fallback:

```bash
curl --disable --proto "$geoflow_curl_proto" -sS --fail-with-body --max-time 20 --max-filesize 5242880 -X POST \
  --header "@$geoflow_auth_header" \
  -H "Accept: application/json" \
  -H "Content-Type: application/json" \
  -H "X-Idempotency-Key: task-create-001" \
  --data @./task.json \
  "$GEOFLOW_BASE_URL/api/v1/tasks"
```

Get task:

```bash
"/path/to/workspace/bin/geoflow" --config /path/to/config task get 12
```

Update task:

```bash
"/path/to/workspace/bin/geoflow" --config /path/to/config task update 12 --json ./task-patch.json --idempotency-key task-update-12
```

Delete task:

```bash
curl --disable --proto "$geoflow_curl_proto" -sS --fail-with-body --max-time 20 --max-filesize 5242880 -X DELETE \
  --header "@$geoflow_auth_header" \
  -H "Accept: application/json" \
  -H "X-Idempotency-Key: task-delete-12" \
  "$GEOFLOW_BASE_URL/api/v1/tasks/12"
```

Task deletion moves visible task articles to trash, unlinks trashed task articles, and deletes schedule/material queue records when those tables exist.

Start task:

```bash
"/path/to/workspace/bin/geoflow" --config /path/to/config task start 12 --idempotency-key task-start-12
```

API fallback:

```bash
curl --disable --proto "$geoflow_curl_proto" -sS --fail-with-body --max-time 20 --max-filesize 5242880 -X POST \
  --header "@$geoflow_auth_header" \
  -H "Accept: application/json" \
  -H "Content-Type: application/json" \
  -H "X-Idempotency-Key: task-start-12" \
  --data '{"enqueue_now":true}' \
  "$GEOFLOW_BASE_URL/api/v1/tasks/12/start"
```

Start and enqueue immediately:

```bash
"/path/to/workspace/bin/geoflow" --config /path/to/config task start 12 --enqueue-now --idempotency-key task-start-12
```

Stop task:

```bash
"/path/to/workspace/bin/geoflow" --config /path/to/config task stop 12 --idempotency-key task-stop-12
```

Manual enqueue:

```bash
"/path/to/workspace/bin/geoflow" --config /path/to/config task enqueue 12 --idempotency-key task-enqueue-12
```

List task jobs:

```bash
"/path/to/workspace/bin/geoflow" --config /path/to/config task jobs 12 --limit 20
```

Get job:

```bash
"/path/to/workspace/bin/geoflow" --config /path/to/config job get 88
```

## Article Operations

List articles:

```bash
"/path/to/workspace/bin/geoflow" --config /path/to/config article list --task-id 12 --per-page 20
```

API fallback:

```bash
curl --disable --proto "$geoflow_curl_proto" -sS --fail-with-body --max-time 20 --max-filesize 5242880 \
  --header "@$geoflow_auth_header" \
  -H "Accept: application/json" \
  "$GEOFLOW_BASE_URL/api/v1/articles?task_id=12&status=draft&review_status=pending&per_page=20"
```

Create article from markdown:

```bash
"/path/to/workspace/bin/geoflow" --config /path/to/config article create \
  --title "标题" \
  --content-file ./article.md \
  --task-id 12 \
  --author-id 5 \
  --category-id 2 \
  --idempotency-key article-create-001
```

Create article from JSON:

```bash
"/path/to/workspace/bin/geoflow" --config /path/to/config article create --json ./article.json --idempotency-key article-create-001
```

API fallback create:

```bash
curl --disable --proto "$geoflow_curl_proto" -sS --fail-with-body --max-time 20 --max-filesize 5242880 -X POST \
  --header "@$geoflow_auth_header" \
  -H "Accept: application/json" \
  -H "Content-Type: application/json" \
  -H "X-Idempotency-Key: article-create-001" \
  --data @./article.json \
  "$GEOFLOW_BASE_URL/api/v1/articles"
```

Update article:

```bash
"/path/to/workspace/bin/geoflow" --config /path/to/config article update 101 --json ./article-patch.json --idempotency-key article-update-101
```

Review article:

```bash
"/path/to/workspace/bin/geoflow" --config /path/to/config article review 101 --status approved --note "pass" --idempotency-key article-review-101
```

API fallback review body uses `review_status` and `review_note`:

```bash
curl --disable --proto "$geoflow_curl_proto" -sS --fail-with-body --max-time 20 --max-filesize 5242880 -X POST \
  --header "@$geoflow_auth_header" \
  -H "Accept: application/json" \
  -H "Content-Type: application/json" \
  -H "X-Idempotency-Key: article-review-101" \
  --data '{"review_status":"approved","review_note":"API review pass"}' \
  "$GEOFLOW_BASE_URL/api/v1/articles/101/review"
```

Publish article:

```bash
"/path/to/workspace/bin/geoflow" --config /path/to/config article publish 101 --idempotency-key article-publish-101
```

Then verify persisted state:

```bash
"/path/to/workspace/bin/geoflow" --config /path/to/config article get 101
```

Then verify the final local frontend URL using `/article/{slug}` from the persisted article slug or the page's canonical URL when `status=published`. Generated article slugs should be 8-character short ASCII tokens, but user-supplied slugs may differ. Do not return `article.php?id=...` as the published URL.

Trash article:

```bash
"/path/to/workspace/bin/geoflow" --config /path/to/config article trash 101 --idempotency-key article-trash-101
```

## Distribution Boundary

Current GEOFlow includes Distribution Management, target-site packages, static target sites, WordPress REST channels, generic HTTP API channels, frontend-capability sync, settings-sync preview, and distribution queues. These admin operations are not exposed through the current `/api/v1` surface.

API task fields can set `publish_scope`, which affects worker-driven task publishing:

- `local_and_distribution`: publish locally and enqueue distribution when task channels exist.
- `distribution_only`: worker publishing may mark local articles `private` while still eligible for distribution.
- `local_only`: skip distribution.

Do not claim the API can create distribution channels, rotate secrets, download target-site packages, inspect Analytics, run URL imports, manage system updates, operate enterprise knowledge or growth-center leads, edit themes, refresh frontend capabilities, replicate themes, or manage API tokens unless the target workspace exposes separate API routes for those actions.

## Admin Web Route Map

Use this map when a capability is absent from `/api/v1`. The default admin prefix is `/admin` in the local Docker instance and may be configured by `geoflow.admin_base_path`.

### Login And Session

- `GET /admin/login`
- `POST /admin/login`
- `POST /admin/logout`
- `GET /admin/locale/{locale}`

Admin writes require the current CSRF token from the form page and the admin session cookies.

### Dashboard And Analytics

- `GET /admin/dashboard`
- `GET /admin/analytics`

Use query parameters for filters. Read visible metric labels and tables from the rendered page; do not infer numbers from memory.

### Tasks

- `GET /admin/tasks`
- `GET /admin/tasks/create`
- `POST /admin/tasks/create`
- `GET /admin/tasks/{taskId}/edit`
- `PUT /admin/tasks/{taskId}`
- `POST /admin/tasks/{taskId}/toggle-status`
- `POST /admin/tasks/{taskId}/delete`
- `GET /admin/tasks/health-check`
- `POST /admin/tasks/batch/start`

Use admin web for channel selection through `distribution_channel_ids[]`, task monitoring health-check, and batch start/stop.

### Distribution

- `GET /admin/distribution`
- `GET /admin/distribution/create`
- `POST /admin/distribution/create`
- `GET /admin/distribution/{channelId}`
- `GET /admin/distribution/{channelId}/edit`
- `PUT /admin/distribution/{channelId}`
- `POST /admin/distribution/{channelId}/pause`
- `POST /admin/distribution/{channelId}/activate`
- `POST /admin/distribution/{channelId}/health`
- `POST /admin/distribution/{channelId}/rotate-secret`
- `POST /admin/distribution/{channelId}/reveal-secret`
- `POST /admin/distribution/{channelId}/download-package`
- `POST /admin/distribution/{channelId}/sync-settings`
- `GET /admin/distribution/{channelId}/sync-settings/preview`
- `POST /admin/distribution/{channelId}/frontend-capabilities/refresh`
- `GET /admin/distribution/sync-settings-all/preview`
- `POST /admin/distribution/sync-settings-all`
- `POST /admin/distribution/sync-settings-selected/preview`
- `POST /admin/distribution/sync-settings-selected`
- `GET /admin/distribution/jobs`
- `GET /admin/distribution/jobs/{distributionId}/edit`
- `PUT /admin/distribution/jobs/{distributionId}`
- `POST /admin/distribution/jobs/{distributionId}/delete`
- `POST /admin/distribution/jobs/{distributionId}/retry`

Channel types are `geoflow_agent`, `wordpress_rest`, and `generic_http_api`. Secret reveal and package download may require super-admin password confirmation.

Refresh frontend capabilities before syncing homepage/theme settings to a GEOFlow Agent channel. If the target package does not expose frontend capabilities, treat it as an outdated or unsupported target package and report the mismatch.

### Enterprise Knowledge

- `GET /admin/enterprise-knowledge`
- `GET /admin/enterprise-knowledge/create`
- `POST /admin/enterprise-knowledge/create`
- `GET /admin/enterprise-knowledge/{projectId}`
- `POST /admin/enterprise-knowledge/{projectId}/autosave`
- `GET /admin/enterprise-knowledge/{projectId}/status`
- `POST /admin/enterprise-knowledge/{projectId}/validate`
- `POST /admin/enterprise-knowledge/{projectId}/editor/images/upload`
- `POST /admin/enterprise-knowledge/{projectId}/publish`
- `POST /admin/enterprise-knowledge/{projectId}/revisions/{revisionId}/restore`
- `POST /admin/enterprise-knowledge/{projectId}/delete`

Use these routes for enterprise knowledge drafting, editor upload, validation, publish, and revision restore. Verify project status and resulting knowledge-base state after publish.

### Growth Center

- `GET /admin/lead-forms`
- `GET /admin/lead-forms/create`
- `POST /admin/lead-forms`
- `GET /admin/lead-forms/{formId}/edit`
- `PUT /admin/lead-forms/{formId}`
- `POST /admin/lead-forms/{formId}/toggle-status`
- `POST /admin/lead-forms/{formId}/delete`
- `GET /admin/leads`
- `GET /admin/leads/export`
- `GET /admin/leads/{submissionId}`
- `PUT /admin/leads/{submissionId}`

Public lead capture routes are `GET /forms/{slug}` and `POST /forms/{slug}/submissions`. Admin exports and lead details may contain personal data; redact summaries and require an explicit export request.

### URL Import

- `GET /admin/url-import`
- `POST /admin/url-import`
- `GET /admin/url-import/history`
- `GET /admin/url-import/{jobId}`
- `POST /admin/url-import/{jobId}/run`
- `GET /admin/url-import/{jobId}/status`
- `POST /admin/url-import/{jobId}/commit`

Verify an analysis model is ready before running jobs. Poll `status` until terminal state before commit.

### System Updates

- `GET /admin/system-updates`
- `POST /admin/system-updates/check`
- `POST /admin/system-updates/plan`
- `POST /admin/system-updates/backup`
- `POST /admin/system-updates/apply`
- `GET /admin/system-updates/runs/status`
- `GET /admin/system-updates/runs/{runUuid}`
- `POST /admin/system-updates/runs/{runUuid}/retry`
- `POST /admin/system-updates/runs/{runUuid}/mark-failed`
- `POST /admin/system-updates/plans/{runUuid}/commands/{commandIndex}/executed`
- `GET /admin/system-updates/backups/{backupUuid}`
- `POST /admin/system-updates/backups/{backupUuid}/rollback`
- `POST /admin/system-updates/backups/{backupUuid}/files/rollback`

Treat apply, retry, mark-failed, rollback, and rollback-file as high-risk super-admin operations. Verify active run state, backup/plan UUID, password requirement, and preflight messages.

### Articles

- `GET /admin/articles`
- `GET /admin/articles/create`
- `POST /admin/articles/create`
- `GET /admin/articles/{articleId}/edit`
- `PUT /admin/articles/{articleId}`
- `POST /admin/articles/{articleId}/editor/images/upload`
- `POST /admin/articles/{articleId}/restore`
- `POST /admin/articles/{articleId}/force-delete`
- `POST /admin/articles/batch/update-status`
- `POST /admin/articles/batch/update-review`
- `POST /admin/articles/batch/delete`
- `POST /admin/articles/batch/restore`
- `POST /admin/articles/batch/force-delete`
- `POST /admin/articles/trash/empty`
- `POST /admin/articles/editor/wechat-html`

Prefer API v1 for simple article CRUD/review/publish/trash. Use admin web for batch operations, restore, force-delete, editor uploads, trash emptying, and WeChat export.

### Materials

- `GET /admin/materials`
- Categories: `/admin/categories`
- Authors: `/admin/authors`
- Keyword libraries: `/admin/keyword-libraries`
- Title libraries: `/admin/title-libraries`
- Image libraries: `/admin/image-libraries`
- Knowledge bases: `/admin/knowledge-bases`

Use admin web for imports, uploads, AI title generation, knowledge-base file upload, and chunk refresh.

### AI Configuration

- `GET /admin/ai-configurator`
- `GET /admin/ai-models`
- `POST /admin/ai-models/create`
- `PUT /admin/ai-models/{modelId}`
- `POST /admin/ai-models/{modelId}/test`
- `POST /admin/ai-models/{modelId}/delete`
- `POST /admin/ai-models/default-embedding`
- `POST /admin/ai-models/chunking-config`
- `GET /admin/ai-prompts`
- `POST /admin/ai-prompts/create`
- `PUT /admin/ai-prompts/{promptId}`
- `POST /admin/ai-prompts/{promptId}/delete`
- `GET /admin/ai-special-prompts`
- `POST /admin/ai-special-prompts/keyword`
- `POST /admin/ai-special-prompts/description`

Redact provider API keys and encrypted secret fields.

### Site Settings And Theme Replication

- `GET /admin/site-settings`
- `POST /admin/site-settings`
- `POST /admin/site-settings/theme`
- `POST /admin/site-settings/article-detail-ads`
- `POST /admin/site-settings/article-detail-text-ads`
- `POST /admin/site-settings/homepage-modules`
- `POST /admin/site-settings/homepage-modules/preset`
- `POST /admin/site-settings/homepage-modules/import`
- `GET|POST /admin/site-settings/sensitive-words`
- `POST /admin/site-settings/sensitive-words/{wordId}/delete`
- `GET /admin/site-settings/theme-editor/{themeId}/{page}`
- `GET /admin/site-settings/theme-editor/{themeId}/{page}/preview`
- `POST /admin/site-settings/theme-editor/{themeId}/{page}/draft`
- `POST /admin/site-settings/theme-editor/{themeId}/{page}/publish`
- `POST /admin/site-settings/theme-editor/{themeId}/{page}/discard`
- Theme replication routes under `/admin/site-settings/theme-replications`

Switch to `public_frontend` for homepage module payload design. For theme replication or live theme editor publish in `operations`, verify status and previews before publish. Package download does not imply publish.

### Super-Admin Management

- Admin users: `/admin/admin-users`
- Activity logs: `GET /admin/admin-activity-logs`
- API tokens: `/admin/api-tokens`
- Security password and sensitive-word aliases: `/admin/security-settings`

API token creation returns the token once. Do not print it unless explicitly requested.
