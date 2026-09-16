---
name: geoflow
description: Develop or operate GEOFlow through its remote CLI, Laravel backend/admin/API, default site, themes, leads, and Agent sites. Use for source-free connections, remote theme drafts/previews, planned Updater operations and receipt recovery, code changes, channel sync, legacy migration, or retired yao-geoflow-cli/design/template IDs. Discover capabilities first. Excludes unrelated work, database shortcuts, invented routes, auth bypass, secret exposure, and unapproved live or destructive actions.
---

# GEOFlow

## Route

1. For a running instance, use installed `geoflow`: read help, select the profile, run `whoami`, `capabilities`, and `doctor`. Source is optional. Follow [remote-cli-workflow.md](references/remote-cli-workflow.md). For source edits, run `scripts/discover_geoflow_workspace.py <workspace>`.
2. Load one route:

- `development`: [workflow](references/development-workflow.md), [discovery](references/system-capability-discovery.md).
- `operations`: [remote Updater](references/remote-updater-workflow.md), [boundaries](references/operation-boundary.md), [commands](references/command-map.md), [capabilities](references/geoflow-current-capability-map.md).
- `public_frontend`: [resources](references/frontend-resource-index.md), [site map](references/geoflow-frontend-map.md).
- `channel_frontend`: [resources](references/frontend-resource-index.md), [contract](references/channel-frontend-contract.md).
- `legacy_migration`: [templates](references/legacy-template-migration.md), [skill IDs](references/legacy-skill-id-migration.md).

## Mode Boundaries

Use one mode per phase: `development` edits source/tests; `operations` uses runtime interfaces; frontend modes prepare themes/payloads. Authenticated form creation, sync, activation, and publication are authorized operations. Discover → implement → verify → authorized finalize.

## Guardrails

- Follow repository rules and focused tests; preserve auth, CSRF, scopes, contracts, readback and secret redaction.
- Helpers require macOS/Linux/WSL, Python 3.10+, Bash; preflight needs curl, live channel reports need PHP and project artisan. Missing dependencies permit read-only discovery; report unverified layers.
- Use operations supported by both instance and client. Preview supports draft editing and preview links. Publication, rollback, remote configuration, large-file staging and full administration remain unavailable. Report gaps; never invent routes or substitute template uploads.
- Retain request IDs for task-enqueue receipts; query uncertain results. A missing receipt, including 404 for a prepared journal, never authorizes resending: reconcile business state before an explicitly authorized new request. Draft create/change have no receipt recovery: inspect state after lost responses; never blindly repeat writes.
- Remote Updater actions require actual v2 capabilities, explicit updater scopes, a saved plan and stable request ID. Follow the Updater route for credential input and recovery. Held background work requires attention; retain its receipt.
- Native template editing requires explicit code scope and password reauthentication. Keep secrets in protected input streams.
- Distinguish preview/import/sync/activation/publication/update/rollback. High-risk operations require an exact target and explicit authorization.
- Report mode, redacted identity, changes, verification, final state and remaining limits.
