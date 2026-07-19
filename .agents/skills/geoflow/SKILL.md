---
name: geoflow
description: Develop or operate GEOFlow across Laravel backend/admin/API/CLI, the default site, themes, leads, and GeoFlow Agent channel sites. Use for code changes, running-system operations, frontend or template edits, channel capability sync, legacy PHP migration, or retired yao-geoflow-cli, yao-geoflow-design, and yao-geoflow-template IDs. Discover real routes first. Excludes unrelated work, database shortcuts, invented routes, auth bypass, secret exposure, raw copying, and unapproved live or destructive actions.
---

# GEOFlow

## Route

1. Run `scripts/discover_geoflow_workspace.py <workspace>` for source work or `scripts/geoflow_preflight.sh "<workspace>" [config] [checks]` before runtime mutations. Inspect current CLI help and routes when available.
2. Load one route:

- `development`: [development-workflow.md](references/development-workflow.md) and [system-capability-discovery.md](references/system-capability-discovery.md)
- `operations`: [operation-boundary.md](references/operation-boundary.md), [command-map.md](references/command-map.md), and [geoflow-current-capability-map.md](references/geoflow-current-capability-map.md)
- `public_frontend`: [frontend-resource-index.md](references/frontend-resource-index.md) and [geoflow-frontend-map.md](references/geoflow-frontend-map.md)
- `channel_frontend`: [frontend-resource-index.md](references/frontend-resource-index.md) and [channel-frontend-contract.md](references/channel-frontend-contract.md)
- `legacy_migration`: [legacy-template-migration.md](references/legacy-template-migration.md) and [legacy-skill-id-migration.md](references/legacy-skill-id-migration.md)

3. Mixed work follows: discover, implement, test or preview, approved finalize.

## Mode Boundaries

Each phase has one mode. `development` edits code and tests; `operations` uses supported runtime interfaces; `public_frontend` prepares themes or payloads; `channel_frontend` handles target-package contracts. Switch modes when crossing these boundaries. Keep authenticated form creation, sync, activation, and publication as confirmed operations phases.

## Guardrails

- Follow repository rules and focused tests.
- Run bundled helpers on macOS, Linux, or WSL with Python 3.10+ and Bash where required; runtime preflight also needs `curl`, and live channel reports need PHP CLI plus a working project `artisan`. If a dependency is unavailable, stay in read-only discovery, report the missing verification layer, and do not claim live success.
- Preserve authentication, CSRF, idempotency, permissions, readback, contracts, and secret redaction.
- Separate preview, import, sync, activation, publication, updates, rollback, and destructive actions. Require the exact target and explicit approval for high-risk steps.
- Report the selected mode, evidence, touched files/routes/resources, verification, final state, and remaining risks.
