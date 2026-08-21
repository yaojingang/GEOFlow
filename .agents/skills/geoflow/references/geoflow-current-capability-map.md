<!--
Copyright © 2026 姚金刚. All rights reserved.
Project: geoflow
Created by: 姚金刚
Date: 2026-07-05
X: https://x.com/yaojingang
-->

# GEOFlow Current Capability Map

This reference maps the current repository to its supported operation surfaces. Inspect the target workspace before a mutation:

```bash
bin/geoflow --version
bin/geoflow --help
php artisan route:list --path=api/v1
php artisan route:list --except-vendor
```

If a command or route is absent in the target deployment, report that capability as unavailable there.

## Operation Surfaces

- `CLI 0.2.0`: preferred client for current API v1 catalog, task, job, material, and article operations.
- `API v1`: fallback when the CLI is absent. Use bearer auth and JSON. Add `X-Idempotency-Key` only to operations that support it; current DELETE routes do not use it.
- `Admin web`: capabilities outside API v1. Use an authenticated admin session, CSRF token, and the actual configurable admin prefix.
- `Super-admin web`: protected workflows such as distribution, manual-publication settings, system updates, theme replication, admin users, and API tokens.
- `Local Artisan`: maintenance commands that operate inside the deployed Laravel application.

Do not replace an admin or Artisan capability with direct SQL or an invented API path.

## CLI And API v1 Coverage

CLI 0.2.0 and API v1 cover the same scriptable content surface:

- Authentication: `POST /api/v1/auth/login`
- Catalog: `GET /api/v1/catalog`
- Tasks: list, create, show, update, delete, start, stop, enqueue, and task jobs
- Jobs: show one task run
- Materials: summary, typed CRUD, typed item list/create/delete, and image upload
- Articles: list, create, show, update, review, publish, and trash

The CLI adds local profile handling, endpoint and credential binding, HTTPS policy, secret prompts/stdin, JSON input, response limits, redaction, and deletion confirmation. Use [command-map.md](command-map.md) for the complete command set.

API v1 does not expose distribution-channel management, manual publication, enterprise knowledge, lead administration, Analytics, AI source-provider management, article editor assistance, URL Import, System Updates, Theme Replication, homepage module editing, API-token administration, admin-user administration, site settings, or security settings.

### Task Contract

Create requires `name`, `title_library_id`, `prompt_id`, and `ai_model_id`. Common optional fields include:

- `author_id`, `image_library_id`, `image_count`
- `knowledge_base_id`, or `knowledge_base_ids` with up to five IDs
- `fixed_category_id`
- `status`: `active` or `paused`
- `category_mode`: `smart` or `fixed`
- `model_selection_mode`: `fixed` or `smart_failover`
- `publish_scope`: `local_and_distribution`, `distribution_only`, or `local_only`
- `distribution_strategy`: `broadcast`, `round_robin`, or `random_balanced`
- `draft_limit`, `article_limit`, `publish_interval`
- `need_review`, `is_loop`, `auto_keywords`, `auto_description`

`knowledge_base_ids` takes precedence over legacy `knowledge_base_id`. Concrete `distribution_channel_ids[]` binding remains an admin task-form flow.

## Admin Prefix

The route prefix comes from `geoflow.admin_base_path` and `ADMIN_BASE_PATH`; the repository default is `geo_admin`. The examples below use `/{admin}` as a placeholder. Resolve the real path from configuration or `route:list` before sending a request.

Admin writes need current cookies and CSRF. Routes protected by `admin.super` also require a super-admin account. Some sensitive actions add rate limits, current-password checks, or impact confirmations.

## Analytics Pages

Current pages are:

- Overview: `GET /{admin}/analytics`
- Content: `GET /{admin}/analytics/content`
- Traffic: `GET /{admin}/analytics/traffic`
- AI visibility: `GET /{admin}/analytics/ai-visibility`
- Leads: `GET /{admin}/analytics/leads`
- Distribution: `GET /{admin}/analytics/distribution`, protected by `admin.super`

The overview may redirect legacy content, traffic, or channel query parameters to the matching page. Read rendered filters, KPI cards, tables, and chart data from the requested page. Do not reconstruct metrics from memory.

## Manual Publication Workbench

Routes under `/{admin}/manual-publications` provide:

- `GET /` list, filters, assignment-aware statistics, and status views
- `GET /create` and `POST /` creation
- `GET /export` CSV export
- `GET /{manualPublicationId}` detail
- `GET /{manualPublicationId}/edit` and `PUT /{manualPublicationId}` update
- `POST /{manualPublicationId}/transition` workflow transition and completion readback
- super-admin settings under `/settings` for personas and platform account references

Super admins create and configure work items. Ordinary active admins see and transition assigned items according to `ManualPublicationPolicy`. Creation from an article accepts only articles with `approved` or `auto_approved` review status. The workbench stores copy, public account references, assignee, schedule, risk state, duplicate warnings, completion URL, and result notes. It does not store platform passwords, cookies, access tokens, or OAuth credentials.

CSV export can contain content and external account references. Require an explicit export request and protect the resulting file.

## Article Editor Assistance And Risk Scan

Admin article routes add capabilities outside CLI/API v1:

- `GET /{admin}/articles/editor/titles` loads assistant title choices.
- `POST /{admin}/articles/editor/generate` runs the AI editor assistant and is limited by `throttle:10,1`.
- `POST /{admin}/articles/{articleId}/risk-scan` reruns the article risk scan.
- `POST /{admin}/articles/{articleId}/editor/images/upload` uploads an editor image.
- `POST /{admin}/articles/editor/wechat-html` exports WeChat HTML.
- Batch review/status/delete/restore/force-delete, single restore/force-delete, and trash emptying remain admin flows.

Read the article edit page before an assistant or risk action, preserve its CSRF/session state, and read the resulting article or redirect page afterward. Risk override requires an explicit reason where the workflow asks for one.

## AI Source Providers

Routes under `/{admin}/ai-source-providers` provide:

- `GET /` provider, binding, quota, and health overview
- `POST /` create a supported source provider
- `PUT /{providerId}` update provider settings
- `POST /{providerId}/test` test a provider, limited by `admin-sensitive`
- `POST /{providerId}/delete` delete an unused provider
- `POST /model-bindings` update Ark and DeepSeek model bindings
- `POST /model-bindings/upsert-api` create or update a bound model API
- `POST /model-bindings/test` test structured output, limited by `admin-sensitive`

Provider endpoints pass the current endpoint policy. Changing endpoint origin requires a fresh API key. Stored keys are encrypted, and failed forms exclude `api_key` from repopulated input. Redact provider and model secrets from reports.

## Distribution Channels And Safe Deletion

Distribution routes are protected by `admin.super`. They cover channel list/create/show/edit/update, pause/activate, health, secret rotation/reveal, target package download, settings-sync preview/apply, frontend-capability refresh, and job edit/delete/retry.

Channel types are `geoflow_agent`, `wordpress_rest`, and `generic_http_api`. WordPress Application Passwords and generic API secrets remain secret after save. GEOFlow Agent settings sync needs a fresh capability inspection and an exact preview.

Channel deletion is a staged admin-web workflow:

1. `GET /{admin}/distribution/{channelId}/delete` renders the current impact and fingerprint.
2. `POST /{admin}/distribution/{channelId}/delete/prepare` moves the channel to `deleting` and marks queued distributions failed so workers cannot start new sends.
3. `POST /{admin}/distribution/{channelId}/delete/cancel` returns a prepared channel to `paused` if the operator stops.
4. `DELETE /{admin}/distribution/{channelId}` performs the final deletion under `admin-sensitive` rate limiting.

The final request requires the exact channel name, current password, current impact fingerprint, history acknowledgement, and acknowledgements for remote content, task changes, or credentials when those impacts exist. Fresh sending jobs and fresh channel operations block deletion. Stale sending or operation state requires its own force acknowledgement.

Final deletion detaches tasks, switches lone `local_and_distribution` tasks to `local_only`, pauses lone `distribution_only` tasks, removes local distributions and secrets, and writes a redacted audit record with a remote cleanup manifest. It does not prove that remote content was deleted. Report remote cleanup as a separate follow-up.

## Site Settings, Homepage Modules, And Theme Replication

Current site-setting routes include:

- `GET|POST /{admin}/site-settings`
- `POST /{admin}/site-settings/theme`
- `GET /{admin}/site-settings/homepage-modules`
- `POST /{admin}/site-settings/homepage-modules`
- `POST /{admin}/site-settings/homepage-modules/preset`
- `POST /{admin}/site-settings/homepage-modules/import`
- article image/text ad settings and sensitive-word management

The GET homepage route is the editor page and supplies persisted modules/style, supported module/layout values, active lead forms, and preset metadata. Switch to `public_frontend` mode to design or validate a homepage payload, then return to `operations` for an approved POST and readback.

Super-admin Theme Replication routes cover create, show, status, `home|category|article` preview, retry, iterate, publish, copy, archive, delete drafts, and package download. Preview and package state do not prove publication.

The current `routes/web.php` does not expose live Theme Editor edit, preview, draft, publish, or discard routes. Treat that operation as unavailable unless the inspected target deployment has explicit routes.

## Enterprise Knowledge, Leads, URL Import, And System Updates

Enterprise Knowledge routes cover list/create, workspace, status, autosave, validation, editor image upload, revision restore, publish to a knowledge base, and project deletion. Read both project status and resulting knowledge-base/chunk state after publication.

Lead administration covers lead-form CRUD/status, lead list/detail/update, and CSV export. Public capture routes are `GET /forms/{slug}` and throttled `POST /forms/{slug}/submissions`. Lead detail and export contain personal data.

URL Import is a super-admin flow covering create, history, show, run, status, and commit. Verify the analysis model before a run and wait for a terminal state before commit.

System Updates cover check, plan, manual-command confirmation, backup, apply, run status, retry, mark failed, full rollback, and single-file rollback. Apply, retry, mark failed, and rollback require the exact run/backup target, the current gate state, and explicit user approval.

## Login Limits And Credential Revocation

Both admin web login and API login use the `admin-login` limiter, currently 30 attempts per minute per IP. Web login also tracks failed attempts by normalized username plus IP. The defaults are five failures and a 900-second temporary lockout, configured by `geoflow.max_login_attempts` and `geoflow.login_lockout_seconds`. This temporary limiter does not change `admins.status`.

An admin with `status=locked` remains manually locked until the supported unlock command runs:

```bash
php artisan geoflow:admin-unlock USERNAME
```

Super admins manage API tokens under `/{admin}/api-tokens`. Token creation displays plaintext once. `POST /{admin}/api-tokens/{tokenId}/revoke` physically deletes that Sanctum token, so later API calls return `401`.

Password changes, admin status changes, and admin deletion call `revokeAuthenticationCredentials()`. That increments `auth_version`, rotates the remember token, and deletes all API tokens for the affected admin. Existing admin sessions fail the `auth_version` check on their next request.

## Local Artisan Maintenance

These commands have no API v1 or GEOFlow CLI 0.2.0 equivalent:

```bash
php artisan geoflow:recover-knowledge-syncs --stale=600 --limit=50
php artisan geoflow:prune-expired-cache --limit=5000
```

`geoflow:recover-knowledge-syncs` requeues knowledge chunk-sync pipelines that stopped making progress. It clamps `--stale` to at least 60 seconds and `--limit` to 1 through 200. The scheduler runs it every five minutes with overlap protection.

`geoflow:prune-expired-cache` deletes expired rows only when the configured limiter cache uses the database driver. Other cache drivers return a no-op message. It clamps `--limit` to 1 through 20,000, runs hourly, and uses overlap protection.

Run local Artisan commands in the correct application container when the deployment is containerized:

```bash
docker compose exec app php artisan geoflow:recover-knowledge-syncs --stale=600 --limit=50
```

## Reporting Standard

For each operation, report the surface, exact route or command, resource IDs, verification readback, final state, and redacted secret handling. Classify failures as authentication/session, CSRF, permission, route missing, validation, business data, queue/worker, locked resource, rate limit, remote target, update preflight, frontend-capability mismatch, or route-surface mismatch.
