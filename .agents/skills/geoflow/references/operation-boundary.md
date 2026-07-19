<!--
Copyright © 2026 姚金刚. All rights reserved.
Project: geoflow
Created by: 姚金刚
Date: 2026-07-05
X: https://x.com/yaojingang
-->

# Operation Boundary

The `operations` mode operates a running GEOFlow system. Product code changes require an explicit switch to `development`. Current GEOFlow uses API v1 for scriptable content operations and Blade admin routes for management workflows such as distribution, enterprise knowledge, growth center, system updates, site settings, theme editor, and frontend-capability sync.

## Allowed Actions

- Run `bin/geoflow` commands when the CLI exists and supports the action.
- Use Laravel `/api/v1` fallback for exposed catalog/material/task/job/article operations.
- Use authenticated admin web routes for capabilities not exposed through CLI or API v1.
- Read command output and route lists.
- Build JSON payload files for task, material, article, or admin-form operations.
- Submit admin forms with the current CSRF token and session cookies.
- Download generated packages only when the user explicitly requests the target resource.

## Disallowed Actions

- Direct SQL against the project database.
- Editing backend/frontend code to complete an operations request.
- Replacing a supported CLI action with raw `curl`.
- Claiming admin-only distribution, enterprise knowledge, growth-center lead management, analytics, URL import, system updates, theme replication/editor, frontend-capability sync, API tokens, admin users, site settings, security settings, or async generation flows are available through API v1 unless route inspection proves it.
- Bypassing admin authentication, CSRF validation, super-admin checks, current-password checks, or configured update-center gates.
- Printing distribution secrets, WordPress Application Passwords, generic API secrets, full API tokens, package secrets, or lead personal data in final summaries.

## Required Checks

Before the first mutating action in a workspace:

1. Verify whether `bin/geoflow` exists. If it does not, verify a Laravel GEOFlow app with `artisan` and `routes/api.php`.
2. If CLI configuration is missing, run `geoflow login` first. If using API fallback, obtain a bearer token through `/api/v1/auth/login` or the provided token source.
3. If authenticated reads return `401`, `403`, or token-invalid output, refresh login/token.
4. Verify an authenticated read such as `catalog` succeeds; public homepage checks alone are not sufficient.
5. For material operations, verify `materials:read` and `materials:write` through `GET /api/v1/materials` before writing.
6. For admin web operations, verify the login page, authenticate to an admin session, read the target form/page, then post with CSRF.
7. For super-admin operations, verify super-admin-only routes are accessible before attempting writes.
8. For high-risk actions, ensure the user has explicitly identified the exact action and target resource.

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
- reveal or rotate distribution secrets
- download target-site packages containing generated credentials
- apply, retry, mark failed, or roll back system updates
- publish theme replication output or live theme-editor drafts
- delete generated theme replication drafts
- export leads or expose lead personal data
- batch settings sync to multiple distribution channels

For these operations, report the route, target ID, verification result, and any remaining manual step. Redact secrets and personal data.

## Error Interpretation

Keep these failure classes separate:

- CLI/runtime failure: command missing, config missing, permission problem, malformed args
- API fallback setup failure: missing `GEOFLOW_BASE_URL`, missing bearer token, wrong `/api/v1` base path
- API fallback routing failure: `/api/v1/catalog` returns HTML, proxy errors, login pages, or Laravel web pages instead of JSON
- API failure: `401`, `403`, `404`, `409`, `422`, `500`
- Admin web failure: missing login session, CSRF mismatch, validation redirect, super-admin denial, password-confirmation failure
- Business-data failure: task inactive, missing titles, invalid category, review state conflict, missing active lead form slug
- Remote target failure: WordPress authorization/capability error, generic API mapping failure, GEOFlow Agent health/sync failure
- Frontend-capability mismatch: remote target package does not expose or support the local frontend capability being synced
- System-update failure: preflight failure, missing backup, active run conflict, stale run, manual command not executed, disabled update/rollback config
- Route-surface mismatch: requested capability exists in admin web but not API v1, or is absent from the target deployment

Do not conflate downstream job-data failures, remote target failures, or frontend-capability mismatches with CLI/API transport failures.
