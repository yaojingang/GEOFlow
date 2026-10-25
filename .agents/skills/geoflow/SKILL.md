---
name: geoflow
description: Operate/develop GEOFlow CLI/Laravel/admin/API, topics/专题 and topic tasks, theme libraries/replication, sites/leads/Agent, channel sync and legacy yao-geoflow-cli/design/template migration. Use for source-free previews, planned Updater operations and receipt recovery. Discover capabilities; exclude unrelated work, SQL shortcuts, invented routes, auth bypass, secrets and unapproved live/destructive actions.
---

# GEOFlow

## Route

Instance: installed `geoflow` help/profile, `whoami`, `capabilities`, `doctor`; [CLI](references/remote-cli-workflow.md). Source optional; edits: `scripts/discover_geoflow_workspace.py <workspace>`.

- `development`: [workflow](references/development-workflow.md), [discovery](references/system-capability-discovery.md).
- `operations`: [Updater](references/remote-updater-workflow.md), [boundaries](references/operation-boundary.md), [commands](references/command-map.md), [capabilities](references/geoflow-current-capability-map.md).
- `public_frontend`: [resources](references/frontend-resource-index.md), [site map](references/geoflow-frontend-map.md).
- `channel_frontend`: [resources](references/frontend-resource-index.md), [contract](references/channel-frontend-contract.md).
- `legacy_migration`: [templates](references/legacy-template-migration.md), [skill IDs](references/legacy-skill-id-migration.md).

[Topics/tasks/themes](references/topic-workflow.md), [libraries](references/theme-edit-workflow.md).

## Boundaries

One mode: source/tests→development; runtime writes→operations; themes/payloads→frontend. Forms/sync/activation/publication need authorization. Discover→implement→verify→authorized finalize.

- Follow repository rules/tests; preserve auth, CSRF, scopes, contracts, readback and secret redaction.
- Helpers: macOS/Linux/WSL, Python 3.10+, Bash; preflight curl; live channel PHP/artisan. Missing tools: read-only discovery, name unverified layers.
- Use the client/instance capability intersection. Preview supports drafts/links; publish, rollback, configuration, large-file staging and full administration are unavailable. Report gaps; never invent routes or substitute uploads.
- Keep enqueue request IDs. No receipt, including prepared-journal 404, authorizes replay: reconcile business state before an explicitly authorized new request. Lost draft create/change responses: inspect state before retry.
- Updater: actual v2 capabilities, explicit updater scopes, saved plan, stable request ID; follow its workflow for protected credential input/recovery. Held work needs attention and retained receipts.
- Native edits: explicit code scope and password reauthentication; protected secret input.
- Separate preview/import/sync/activation/publication/update/rollback. High-risk actions: exact target and explicit authorization.
- Report mode/redacted identity, changes/checks, state/limits.
