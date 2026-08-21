<!--
Copyright © 2026 姚金刚. All rights reserved.
Project: geoflow
Created by: 姚金刚
Date: 2026-07-05
X: https://x.com/yaojingang
-->

# Operation Boundary

The `operations` mode operates a running GEOFlow system. Product code changes require an explicit switch to `development`. When `bin/geoflow` exists, GEOFlow CLI 0.2.0 is the preferred API v1 client for catalog, task, job, material, and article operations. API v1 is the fallback when the CLI is absent. Blade admin routes handle management workflows such as Analytics, manual publication, distribution, enterprise knowledge, leads, AI source providers, article editor assistance, URL Import, System Updates, site settings, Theme Replication, homepage modules, API tokens, and frontend-capability sync.

## Allowed Actions

- Run `bin/geoflow --version` and `bin/geoflow --help`, then use a listed CLI command when it supports the action.
- Use Laravel `/api/v1` fallback for exposed catalog/material/task/job/article operations.
- Use authenticated admin web routes for capabilities not exposed through CLI or API v1.
- Run local Artisan maintenance commands through `php artisan` or the deployed application container when the command exists in `routes/console.php`.
- Read command output and route lists.
- Build JSON payload files for task, material, article, or admin-form operations.
- Submit admin forms with the current CSRF token and session cookies.
- Download generated packages only when the user explicitly requests the target resource.

## Disallowed Actions

- Direct SQL against the project database.
- Editing backend/frontend code to complete an operations request.
- Replacing a supported CLI action with raw `curl`.
- Claiming admin-only manual publication, distribution, enterprise knowledge, lead management, Analytics, AI source providers, article editor assistance/risk scan, URL Import, System Updates, Theme Replication, homepage editing, frontend-capability sync, API tokens, admin users, site settings, security settings, or async generation flows are available through API v1 unless route inspection proves it.
- Claiming a live Theme Editor exists in the current repository. Current `routes/web.php` has Theme Replication routes and no live Theme Editor route.
- Bypassing admin authentication, CSRF validation, super-admin checks, current-password checks, or configured update-center gates.
- Printing distribution secrets, WordPress Application Passwords, generic API secrets, full API tokens, package secrets, or lead personal data in final summaries.

## Required Checks

Before the first mutating action in a workspace:

1. Verify whether `bin/geoflow` exists. If it does, run `--version` and `--help`. If it does not, verify a Laravel GEOFlow app with `artisan` and `routes/api.php`.
2. If CLI configuration is missing, run `geoflow login` first. If using API fallback, obtain a bearer token through `/api/v1/auth/login` or the provided token source.
3. Interpret authenticated failures before changing credentials: `401` means invalid or expired authentication; `403` means missing permission or scope; `423` means a resource lock; `429` means rate limiting. Refresh login/token only for `401` or explicit token-invalid output.
4. Verify an authenticated read such as `catalog` succeeds; public homepage checks alone are not sufficient.
5. For material operations, use `GET /api/v1/materials` to verify `materials:read`. Confirm that the selected token was issued with `materials:write` before a mutation; the read endpoint cannot prove the write scope, and a missing write scope returns `403`.
6. For admin web operations, verify the login page, authenticate to an admin session, read the target form/page, then post with CSRF.
7. For super-admin operations, verify super-admin-only routes are accessible before attempting writes.
8. For high-risk actions, ensure the user has explicitly identified the exact action and target resource.
9. Resolve the admin prefix from the target configuration or `route:list`; the repository default is `geo_admin` and deployments may override it.

After any mutating action:

1. Re-read the target resource.
2. Report the final persisted state.
3. Inspect background jobs separately when the action queues work.
4. If publishing an article locally, report the persisted `/article/{slug}` route rather than an `article.php?id=...` compatibility link.
5. If the action used admin web, verify by reading the redirected page, JSON status endpoint, listing page, detail page, or artifact metadata.
6. If the action touched a remote channel, separate local GEOFlow success from remote target success.

## High-Risk Admin Web Actions

Proceed only when the user's request explicitly names the target action/resource:

- force-delete articles or empty trash
- delete admin users, revoke API tokens, or change passwords
- export manual-publication records or lead data
- reveal or rotate distribution secrets
- download target-site packages containing generated credentials
- prepare and permanently delete a distribution channel after reviewing its current impact fingerprint
- apply, retry, mark failed, or roll back system updates
- publish theme replication output
- delete generated theme replication drafts
- export leads or expose lead personal data
- batch settings sync to multiple distribution channels

For distribution-channel deletion, use the exact preview, prepare, optional cancel, and final DELETE sequence. The final request needs the channel name, current password, impact fingerprint, impact acknowledgements, and any required stale-operation force acknowledgement. Remote cleanup remains a separate result. For all high-risk operations, report the route, target ID, verification result, and any remaining manual step. Redact secrets and personal data.

## Current Admin And Local-Command Boundary

Use admin web when CLI/API v1 has no matching capability. Current admin-only surfaces include the six Analytics pages, manual-publication workbench, AI source providers, article editor assistant and risk scan, staged distribution-channel deletion, and the GET homepage-module editor. Admin web login and API login are rate limited; API tokens can be revoked from the super-admin token page. Password or admin-status changes also revoke affected sessions and API tokens.

Current local-only maintenance commands are:

```bash
php artisan geoflow:recover-knowledge-syncs --stale=600 --limit=50
php artisan geoflow:prune-expired-cache --limit=5000
```

Do not translate these commands into API or GEOFlow CLI calls. In Docker, run them inside the application service.

## Error Interpretation

Keep these failure classes separate:

- CLI/runtime failure: command missing, config missing, permission problem, malformed args
- API fallback setup failure: missing `GEOFLOW_BASE_URL`, missing bearer token, wrong `/api/v1` base path
- API fallback routing failure: `/api/v1/catalog` returns HTML, proxy errors, login pages, or Laravel web pages instead of JSON
- API failure: `401`, `403`, `404`, `409`, `422`, `423`, `429`, `500`
- Admin web failure: missing login session, CSRF mismatch, validation redirect, super-admin denial, password-confirmation failure
- Business-data failure: task inactive, missing titles, invalid category, review state conflict, missing active lead form slug
- Remote target failure: WordPress authorization/capability error, generic API mapping failure, GEOFlow Agent health/sync failure
- Frontend-capability mismatch: remote target package does not expose or support the local frontend capability being synced
- System-update failure: preflight failure, missing backup, active run conflict, stale run, manual command not executed, disabled update/rollback config
- Route-surface mismatch: requested capability exists in admin web but not API v1, or is absent from the target deployment
- Transport-policy failure: authenticated remote HTTP, unsafe endpoint/credential source mixing, redirect, TLS, timeout, or response-size rejection

Do not conflate downstream job-data failures, remote target failures, or frontend-capability mismatches with CLI/API transport failures.
