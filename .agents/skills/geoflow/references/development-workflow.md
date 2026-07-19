# GEOFlow Development Workflow

Use this workflow for changes to GEOFlow product code, including the public site, admin, API, CLI, workers, database, distribution, and target packages.

## 1. Establish The Real Contract

1. Read repository instructions and dependency manifests.
2. Run `scripts/discover_geoflow_workspace.py <workspace>`.
3. Inspect current routes with `php artisan route:list --json` when the application boots successfully.
4. Read the closest controller, request validation, model, service, view, test, and migration that already implement a similar capability.
5. Record the affected surface: `backend`, `admin`, `api`, `cli`, `public_frontend`, `channel_frontend`, or `cross_surface`.

Generated capability snapshots provide discovery evidence. Current code, routes, tests, and runtime output remain authoritative.

## 2. Plan The Change By Layer

For each applicable layer, identify the exact contract before editing:

- data: migrations, models, casts, relations, indexes, retention, and rollback
- domain: service boundaries, state transitions, queues, retries, and idempotency
- HTTP: routes, middleware, authorization, validation, responses, CSRF, and rate limits
- admin: configurable admin prefix, permissions, navigation, forms, localization, readback, and audit logs
- public site: controller-provided data, route helpers, SEO/schema, accessibility, responsive behavior, and empty states
- distribution: channel type, signed requests, target-package version, capability negotiation, retries, and static rebuild behavior
- CLI/API: discovered commands and routes, stable exit or response contracts, safe secret handling, and help text

Keep shared business rules in services or support classes when several controllers, commands, jobs, or channels consume them.

## 3. Implement Safely

- Scope changes to the requested feature and its required tests or documentation.
- Preserve existing public routes and payload fields unless the request explicitly changes the contract.
- Use reversible migrations and avoid relying on production-only data during schema changes.
- Keep credentials in configuration or encrypted storage. Redact tokens, provider keys, channel secrets, passwords, and personal lead data from logs and summaries.
- Resolve the admin prefix through the application configuration or route names.
- Reuse the theme resolver, homepage module builder, lead form contract, distribution publisher interfaces, and target-package builders when present.
- Update capability output when a channel renderer or signed endpoint gains a public feature.

## 4. Verification Matrix

Run the smallest meaningful checks first, then the repository's broader gate:

| Changed surface | Minimum evidence |
|---|---|
| PHP domain or service | focused unit test plus related feature test |
| route, controller, middleware | route list plus request/permission feature test |
| migration or model | migration test or fresh test database plus relation/cast coverage |
| admin UI | feature test, localization check, validation path, and rendered-page readback |
| public frontend or theme | page feature test, key route rendering, SEO/schema preservation, responsive preview |
| queue or worker | job/service test, failure classification, retry/idempotency check |
| distribution or target package | publisher/contract tests, capability response, signed request, static/rewrite behavior |
| CLI/API | help or route discovery, success response, failure response, and installed/runtime invocation |

Report the executed commands, test counts when available, observed failures, and unverified runtime layers.

## 5. Operational Handoff

When implementation is complete, switch to `operations`, `public_frontend`, or `channel_frontend` for preview, import, sync, activation, or publication. State the exact target and rollback boundary before the finalize step.
