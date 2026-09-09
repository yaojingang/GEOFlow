# Blue/Green Deployment and Automatic Migration Host Acceptance

[简体中文](2026-09-09-blue-green-host-acceptance.md) | English

Checked September 9, 2026.

> **Technical acceptance passed:** The same signed candidate passed 2 native container suites and 6 complete installed-host rehearsals across amd64 and arm64. Evidence assembly and validation also passed.

## Candidate and scope

- Updater candidate: `0.4.0-rc.4`, commit `917b0acecd85d833ba41a6c04ddf279980c50697`.
- GEOFlow application: source version `3.0.0`, commit `02babc6f514ffbd0116dfeaa7e262cb655695d5b`, signed release sequence `3`.
- The application image was built from the pinned main-branch commit above. The published v3.0.0 assets remain the earlier stable release.
- [Candidate build](https://github.com/yaojingang/geoflow-updater/actions/runs/34310152452). Candidate images and signed repositories are isolated from stable release tags and production update metadata.
- Disposable GitHub-hosted Linux machines run natively on amd64 and arm64. Each architecture covers container contracts, full upgrade and restoration, first-install retry, and online switching.

## Problems found and fixed during rehearsal

| Problem | Fix and verification |
|---|---|
| Fresh installation lacked the external ingress network | Create and verify the instance-owned network; reject a same-name network owned elsewhere |
| Startup recovery lost the recovery-point or target-version identity | Persist operation identity through failed recovery, retry backoff and explicit restoration |
| The legacy scheduler ignored its exit signal and blocked draining | Freeze the scheduler parent and wait for active children; persist freeze intent so interruption recovery can resume the same process |
| HTTP was available while Docker health was still starting, causing installation to fail early | Wait for startup health; real container tests cover delayed health and persistent health failure |
| Interrupted layout conversion left the candidate database running during full restoration | Drain current and recorded deployments, then close infrastructure that differs from the recovery destination before restoring data |
| Upgrading again after restoration reused stopped containers with deleted network IDs | Remove both drained application slots before deleting infrastructure networks; share the order across full restoration, recovery before traffic opens, and maintenance infrastructure replacement |
| Fresh-site realtime configuration was incomplete | Set the internal broadcast host, port, scheme and server path |

See [Updater PR #17](https://github.com/yaojingang/geoflow-updater/pull/17), [PR #18](https://github.com/yaojingang/geoflow-updater/pull/18) and [PR #20](https://github.com/yaojingang/geoflow-updater/pull/20). The [first](https://github.com/yaojingang/geoflow-updater/actions/runs/34298401281), [second](https://github.com/yaojingang/geoflow-updater/actions/runs/34303691010) and [third acceptance runs](https://github.com/yaojingang/geoflow-updater/actions/runs/34308204922) contain the failures used to locate these defects.

Recovery regressions also cover another checkpoint, restart after interrupted restoration, reverse layout restoration, retained destination infrastructure, and rejection of mismatched instance roots, infrastructure paths and parent symlinks before Docker actions. The full Go race suite, static checks and independent reviews passed.

The third run passed container checks and first-install retry on both architectures. After changing the test update repository, the online harness requested a plan before the restarted control API was ready. [Updater PR #19](https://github.com/yaojingang/geoflow-updater/pull/19) adds real API readiness, candidate-version verification and redacted failure tracebacks. Delayed-socket, timeout and wrong-version regressions passed. This harness-only fix reuses the same signed candidate for the repeat run.

The third candidate's [online repeat run](https://github.com/yaojingang/geoflow-updater/actions/runs/34309123244) passed on both architectures. The network cleanup fix then entered the fourth candidate for full acceptance. Its Docker regression reproduced the original failure before passing two consecutive recovery and slot-recreation cycles. Nine cases cover all three entry points and failure while removing either slot.

## Final acceptance results

All 9 jobs in the [final workflow](https://github.com/yaojingang/geoflow-updater/actions/runs/34312377078) succeeded, including evidence assembly.

| Scope | Native amd64 | Native arm64 |
|---|---|---|
| Application, migrations, ingress and real container regressions | [Passed](https://github.com/yaojingang/geoflow-updater/actions/runs/34312377078/job/102341511277) | [Passed](https://github.com/yaojingang/geoflow-updater/actions/runs/34312377078/job/102341511143) |
| First installation and interruption retry | [Passed](https://github.com/yaojingang/geoflow-updater/actions/runs/34312377078/job/102342198405) | [Passed](https://github.com/yaojingang/geoflow-updater/actions/runs/34312377078/job/102342198399) |
| Full upgrade, backup restoration and interruption recovery | [Passed](https://github.com/yaojingang/geoflow-updater/actions/runs/34312377078/job/102342198415) | [Passed](https://github.com/yaojingang/geoflow-updater/actions/runs/34312377078/job/102342198363) |
| Online switching and application switch-back that preserves data | [Passed](https://github.com/yaojingang/geoflow-updater/actions/runs/34312377078/job/102342198546) | [Passed](https://github.com/yaojingang/geoflow-updater/actions/runs/34312377078/job/102342198476) |

Full restoration compared PostgreSQL, Redis, storage files, environment configuration, version, instance and deployment files, and migration history. All 10 upgrade interruption points, failed-restoration backoff and authorized recovery, subsequent upgrades, manual backup and complete restoration passed. The real administrator session remained available after upgrading.

The online rehearsal completed 499 public-health/authenticated-page probe rounds on amd64 and 506 on arm64, with zero errors. Each architecture handed over 20 pending jobs per transition, each executed once on the destination slot. An old realtime connection received messages from the new slot; reconnection passed; application switch-back retained database, Redis and file writes.

The complete candidate identity and individual results are archived unchanged in the [technical evidence JSON](2026-09-09-blue-green-host-acceptance-evidence.json). Candidate binding and required-case validation passed. Release operator, security reviewer and product owner publication approvals remain `pending` for the respective reviewers to complete.

## Scope of use

The online fixture uses identical application code and a separately signed online upgrade plan. It checks traffic switching, authenticated sessions, pending-job handover, cross-slot realtime messages, reconnect and application switch-back that preserves data. Production old-version/new-version compatibility still needs validation of the actual schemas, job payloads, caches and storage.

The main candidate uses a maintenance plan. Technical acceptance, release approval and stable publication have separate records; this report does not announce a stable release. Site administrators should follow the [usage tutorial](../blue-green-deployment-usage_en.md), install matching versions and rehearse with their own data copy to confirm business behavior and backup, migration and restore durations.
