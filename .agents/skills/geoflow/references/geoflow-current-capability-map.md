<!--
Copyright © 2026 姚金刚. All rights reserved.
Project: geoflow
Created by: 姚金刚
Date: 2026-07-05
X: https://x.com/yaojingang
-->

# GEOFlow Current Capability Map

This reference maps current GEOFlow 2.1+ operations to the supported automation surface. Always inspect the target workspace before mutating anything:

```bash
php artisan route:list --path=api/v1
php artisan route:list --path=admin
php artisan route:list --except-vendor
```

If a route is missing in the target deployment, report the capability as unavailable there.

## Operation Surfaces

- `CLI`: use `bin/geoflow` only when it exists and `--help` confirms the action.
- `API v1`: use bearer auth, JSON payloads, `Accept: application/json`, and `X-Idempotency-Key` for writes.
- `Admin web`: use an authenticated admin session, CSRF token, cookies, and route-specific form semantics.
- `Super-admin web`: same as admin web, but first verify super-admin access and current-password requirements.

Never replace an admin-web capability with direct SQL or invented API routes.

## API v1 Coverage

API v1 covers scriptable content operations:

- Authentication: `POST /api/v1/auth/login`
- Catalog: `GET /api/v1/catalog`
- Tasks: list, create, show, update, delete, start, stop, enqueue, and task jobs
- Jobs: show one task run via `GET /api/v1/jobs/{id}`
- Materials: summary, typed CRUD, typed item list/create/delete
- Articles: list, create, show, update, review, publish, trash

API v1 does not expose distribution channel CRUD, enterprise knowledge drafting, growth-center lead management, Analytics, URL Import, System Updates, Theme Replication, live Theme Editor, API token admin, admin-user admin, site settings, security settings, or AI model/prompt configuration unless the target workspace adds explicit matching routes.

## Task Contract

Create requires:

- `name`
- `title_library_id`
- `prompt_id`
- `ai_model_id`

Common optional task fields:

- `author_id`, `image_library_id`, `image_count`
- `knowledge_base_id`
- `knowledge_base_ids` up to five IDs; when present, it takes precedence over `knowledge_base_id`
- `fixed_category_id`
- `status`: `active` or `paused`
- `category_mode`: `smart` or `fixed`
- `model_selection_mode`: `fixed` or `smart_failover`
- `publish_scope`: `local_and_distribution`, `distribution_only`, or `local_only`
- `distribution_strategy`: `broadcast`, `round_robin`, or `random_balanced`
- `draft_limit`, `article_limit`, `publish_interval`
- `need_review`, `is_loop`, `auto_keywords`, `auto_description`

Admin task forms also accept `distribution_channel_ids[]`; that channel binding is an admin-web flow unless the target deployment exposes a dedicated API route.

## Admin Web Coverage

Use admin web routes when a capability is absent from API v1.

### Dashboard And Analytics

- Dashboard: `GET /admin/dashboard`
- Analytics: `GET /admin/analytics`
- Growth-center metrics are exposed through the Analytics/Growth Center admin views when the target branch includes lead management.
- Report rendered metrics only; do not invent trend values.

### Enterprise Knowledge

Routes under `/admin/enterprise-knowledge` cover:

- project list and create form
- upload/create project draft
- project workspace
- autosave
- validate
- editor image upload
- publish to knowledge base
- restore revisions
- delete project
- status polling

This is an admin-web workflow. Verify project status and resulting knowledge-base/chunk state after publish. Do not create direct database rows for generated documents, revisions, or chunks.

### Growth Center: Lead Forms And Leads

Admin routes cover:

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

Public lead capture routes are:

- `GET /forms/{slug}`
- `POST /forms/{slug}/submissions`

Operate lead-form creation and lead review/export through admin web in `operations` mode. A homepage `lead_form` module requires an already existing active form slug; switch to `public_frontend` for homepage module payload design.

### Distribution And Frontend Capabilities

Routes under `/admin/distribution` cover:

- channel list/create/show/edit/update
- pause/activate
- health check
- secret reveal/rotation
- target-site package download
- per-channel settings sync and preview
- selected/all channel settings sync and preview
- frontend-capability refresh
- distribution jobs list/edit/update/delete/retry

Channel types:

- `geoflow_agent`
- `wordpress_rest`
- `generic_http_api`

Rules:

- GEOFlow Agent channels can use target packages, static/rewrite frontend mode, secret reveal/rotation, frontend-capability inspection, and settings sync.
- WordPress REST channels use WordPress username and Application Password. Do not reveal the password after save.
- Generic HTTP API channels support configurable auth, request paths, success statuses, response mapping, health checks, and optional settings sync path.
- Treat frontend-capability mismatches as remote package/version issues, not as proof that local homepage modules are invalid.

### URL Import

Routes under `/admin/url-import` cover create, run, status polling, show, history, and commit into knowledge base, keyword library, and title library outputs. Verify an analysis model is configured before running jobs.

### System Updates

Routes under `/admin/system-updates` cover check, plan, manual-command confirmation, backup, apply, run status, retry, mark failed, backup inspection, full rollback, and single-file rollback.

These are high-risk super-admin operations. Before `apply`, `rollback`, `rollback-file`, `retry`, or `mark-failed`, verify update center config, super-admin access, active run state, backup/run UUID, current password requirements, preflight, and plan state.

### Site Settings, Homepage Modules, Theme Replication, And Theme Editor

Routes under `/admin/site-settings` cover:

- global site settings
- theme activation
- homepage module update, preset, and import
- article detail image/text ads
- sensitive words
- theme replication create/show/status/preview/assets/retry/iterate/publish/copy/archive/delete-drafts/package
- live theme editor edit/preview/draft/publish/discard for `{themeId}/{page}`

Theme replication and theme editor publish are high-risk visual/frontend operations. Verify preview pages (`home`, `category`, `article`, or the requested page) before publish. Package download does not imply publication.

### Articles

Admin article routes cover list, create, edit, update, batch status/review/delete/restore/force-delete, per-article restore/force-delete, empty trash, editor image upload, and WeChat HTML export. Prefer API v1 for simple draft/review/publish/trash; use admin web for editor uploads and batch/destructive flows.

### Materials And Knowledge Bases

Admin web extends material work beyond API v1:

- Category CRUD
- Author CRUD and detail
- Keyword library CRUD/import/items
- Title library CRUD/import/items and AI title generation
- Image library CRUD/upload/delete/detail
- Knowledge base CRUD, file upload, Markdown/detail editing, semantic chunk refresh
- Unified materials index

Prefer API v1 for simple typed material CRUD. Use admin web for uploads, imports, AI generation, detail pages, Markdown editing, and chunk refresh.

### AI Configuration

Admin routes cover AI configurator, AI model CRUD, model connection test, default embedding model, chunking config, content prompt CRUD, and special keyword/description prompts. Verify model status after writes and redact provider secrets.

### Admin, Security, And Tokens

Admin web covers login/logout/locale, admin-user management, admin activity logs, API tokens, security password update, and sensitive words. API token creation returns the token once; redact it unless the user explicitly asks to receive it in the current private thread.

## Reporting Standard

For each operation, report:

- surface used: CLI, API v1, admin web, or super-admin web
- route or command
- resource IDs touched
- verification readback
- final state
- redacted secret handling, when relevant

For failed operations, classify the failure as authentication/session, CSRF, permission, route missing, validation, business data, queue/worker, remote target, system update preflight, frontend-capability mismatch, or route-surface mismatch.
