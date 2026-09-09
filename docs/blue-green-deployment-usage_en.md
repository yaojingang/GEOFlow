# GEOFlow Blue/Green Deployment and Automatic Migration Tutorial

[简体中文](blue-green-deployment-usage.md) | English

For site and server administrators. This tutorial covers fresh installation, enrollment, updates through the admin UI or CLI, automatic migrations, backups, and recovery.

It follows the implementation merged in [GEOFlow PR #122](https://github.com/yaojingang/GEOFlow/pull/122) and [updater PR #16](https://github.com/yaojingang/geoflow-updater/pull/16). Subsequent recovery fixes and complete dual-architecture results are recorded in the [host acceptance report](reports/2026-09-09-blue-green-host-acceptance_en.md).

> **Release prerequisite, checked September 9, 2026:** These changes are merged into both repositories' main branches. The published stable versions remain [GEOFlow v3.0.0](https://github.com/yaojingang/GEOFlow/releases/tag/v3.0.0) and [updater v0.3.0](https://github.com/yaojingang/geoflow-updater/releases/tag/v0.3.0), which do not include the complete workflow described here. You need a subsequent official updater release, matching application images, and a signed upgrade plan. Pulling main or installing the existing v0.3.0 package alone is insufficient. Use the version number from the official release when it becomes available.

## 1. Choose your path

[GEOFlow Updater](https://github.com/yaojingang/geoflow-updater) is a separate host-side tool for signed installation, upgrades, backups, and recovery.

| Current installation | Path |
|---|---|
| New server with no GEOFlow installation | [Install GEOFlow Updater](#3-install-or-upgrade-updater) → [Install the site](#4-new-server-install-the-site) → [Configure authorization](#6-configure-authorization-for-the-admin-ui) → [Verify](#9-automatic-migrations-and-verification) |
| Existing standard Docker deployment without updater management | [Install GEOFlow Updater](#3-install-or-upgrade-updater) → [Enroll during maintenance](#5-existing-site-enroll-and-convert-the-layout) → [Configure authorization](#6-configure-authorization-for-the-admin-ui) → [Run a planned update to convert the layout](#8-routine-updates-through-the-server-cli) |
| Existing managed deployment | Preview and update through the [admin UI](#7-routine-updates-through-the-admin-ui) or [server CLI](#8-routine-updates-through-the-server-cli) |
| Problems after an update | [Check the failure state](#11-troubleshooting-and-interrupted-operations), then choose [application switch-back](#102-switch-the-application-back-and-keep-current-data) or [full data recovery](#103-restore-data-from-a-full-recovery-point) |

Examples use instance `primary`, directory `/opt/geoflow`, and URL `https://geo.example.com`. Replace the directory and domain with your actual values. Keep the instance name `primary`, which is the only supported instance name.

Run all `sudo geoflow-updater ...` commands on the **Linux host**. Admin UI operations require a super administrator account.

## 2. When an update can stay online

Blue and green are two application slots. During an update, updater prepares the new release in the other slot, validates it, switches the stable ingress, and completes the handover by draining old requests and background tasks.

Both releases share the database, Redis, and business files. The signed plan and the actual preflight checks determine whether an update can run online:

| Preview result | Required action |
|---|---|
| `strategy: online` | Perform an online blue/green update; traffic switches after candidate validation, and long-lived connections may reconnect |
| `strategy: maintenance` | Schedule maintenance; service and writes pause during the update |
| `layout_change: true` | Convert to blue/green for the first time during a maintenance window |
| Preview fails | Resolve the cause and preview again; this update has not started |

The plan currently in the repository defaults to `maintenance`. Publishers must verify source versions, database, queue, cache, storage, and migration compatibility, then sign an online plan for the corresponding upgrade path. Even with zero pending migrations, business backfills, infrastructure image changes, or layout conversion can require maintenance.

Reserve CPU, memory, and disk capacity for both application slots to run together, and validate capacity against your workload. This is a single-host deployment; a host, database, or Redis failure can still affect the whole site.

## 3. Install or upgrade updater

### 3.1 Prepare the server

The supported environment is Linux with systemd, Docker Engine, and Docker Compose v2, on amd64 or arm64. Managed deployments use the PostgreSQL instance bundled with the site. External databases and multi-host deployments are outside this tutorial's scope.

```bash
uname -m
docker version
docker compose version
systemctl --version
```

`x86_64` maps to amd64; `aarch64` maps to arm64. The updater installer checks for Docker but does not install it.

### 3.2 Download and verify

Choose an official release that explicitly includes this workflow from [updater Releases](https://github.com/yaojingang/geoflow-updater/releases). Download the archive for your architecture and `checksums.txt` into a dedicated directory.

Replace the `X.Y.Z` placeholder below with the actual version, without a leading `v`. These commands require GitHub CLI:

```bash
UPDATER_VERSION='X.Y.Z'
UPDATER_ARCH='amd64'
UPDATER_ARCHIVE="geoflow-updater_${UPDATER_VERSION}_linux_${UPDATER_ARCH}.tar.gz"

gh attestation verify "$UPDATER_ARCHIVE" --repo yaojingang/geoflow-updater
sha256sum --check checksums.txt --ignore-missing
```

Confirm that attestation verification succeeds and that the selected archive reports `OK`. Resolve any verification failure before extracting the archive and reviewing the installer.

```bash
mkdir geoflow-updater-package
tar -xzf "$UPDATER_ARCHIVE" -C geoflow-updater-package
cd geoflow-updater-package
less packaging/scripts/install.sh
sudo ./packaging/scripts/install.sh
sudo geoflow-updater version
sudo systemctl status geoflow-updater --no-pager
```

Use the same process to upgrade an existing updater installation. Confirm that no install, update, backup, or recovery operation is running first. The installer replaces the binary and restarts the service.

## 4. New server: install the site

### 4.1 Configure the public endpoint

Point your domain to the server and configure an external HTTPS reverse proxy to forward to the host ingress, which defaults to port `18080`. A proxy on the same host typically forwards to `http://127.0.0.1:18080`; a proxy in a container needs a host address reachable from that container.

Preserve the Host and HTTPS forwarding information. Support WebSocket upgrades and long-lived connections, and avoid buffering streamed responses. The external proxy manages TLS certificates. Restrict access to port 18080 through your firewall.

Set `--url` to the final public origin: scheme, host, and optional port. Exclude admin paths, query parameters, and credentials.

### 4.2 Run installation

Prerequisites: [updater installation](#3-install-or-upgrade-updater) is complete, the release repository contains a matching signed plan, and the target directory is absent or empty. Use the enrollment procedure in [section 5](#5-existing-site-enroll-and-convert-the-layout) for an existing site.

```bash
sudo geoflow-updater install \
  --instance primary \
  --root /opt/geoflow \
  --url https://geo.example.com
```

Updater generates keys and random credentials, prepares the database and storage, pulls signed images, runs initialization and upgrade steps, starts services, and checks them.

If installation is interrupted, resolve the cause and repeat the command with the **same instance, directory, and URL**. Updater continues from its installation record and preserves existing keys and data. Keep the directory intact for the retry.

### 4.3 Retrieve the administrator account

```bash
sudo cat /opt/geoflow/install-credentials.txt
```

Read this file in your own controlled terminal. Sign in at the URL shown, change the initial password, and update the administrator email address. The file contains the initial password in plain text; handle it as password material. The default admin prefix is `/geo_admin`; use your actual prefix if you have customized it.

Complete authorization in [section 6](#6-configure-authorization-for-the-admin-ui) and verification in [section 9](#9-automatic-migrations-and-verification). After deployment, configure AI provider credentials, model selection, and business settings in the admin UI.

## 5. Existing site: enroll and convert the layout

For an already managed instance, go directly to [section 7](#7-routine-updates-through-the-admin-ui) or [section 8](#8-routine-updates-through-the-server-cli). The Compose commands in this section apply only to a **standard single-stack Docker deployment that is not yet managed**.

### 5.1 Prepare for enrollment

The site directory must contain `.env.prod`, `storage/`, and the current `version.json`. The installed version must match the signed release used for enrollment. If it does not, follow the supported legacy upgrade procedure to reach an enrollable version. Do not edit `version.json` to bypass this check.

Enrollment preserves the configured PostgreSQL and Redis major versions. Supported majors are PostgreSQL 16 or 18 and Redis 7 or 8. Verify that the image major matches the actual data directory. Schedule database major-version migrations separately.

Reserve a maintenance window, stop creating new tasks, drain pending, delayed, and running queues, and save deployment configuration and a recoverable backup. If legacy Redis is not persistent, stopping its container can lose pending tasks.

### 5.2 Register the instance and start managed services

```bash
sudo geoflow-updater enroll \
  --instance-id primary \
  --instance-root /opt/geoflow
```

Fresh installation uses `--instance / --root`; enrollment uses `--instance-id / --instance-root`. Use a dedicated site directory under `/opt`. Temporary directories or home directories isolated by the service sandbox may be rejected.

During the maintenance window, follow the actual paths printed by `enroll`. The standard example below requires both environment files:

```bash
sudo docker compose \
  --env-file /opt/geoflow/.env.prod \
  --env-file /var/lib/geoflow-updater/instances/primary/release.env \
  -f /var/lib/geoflow-updater/instances/primary/docker-compose.managed.yml \
  down --remove-orphans

sudo docker compose \
  --env-file /opt/geoflow/.env.prod \
  --env-file /var/lib/geoflow-updater/instances/primary/release.env \
  -f /var/lib/geoflow-updater/instances/primary/docker-compose.managed.yml \
  up -d --remove-orphans
```

This stops the old services and starts the managed services. Configure authorization in [section 6](#6-configure-authorization-for-the-admin-ui), then perform the checks in [section 9](#9-automatic-migrations-and-verification).

**After enrollment, run a planned update to convert to the blue/green layout.** Preview the plan through [section 7](#7-routine-updates-through-the-admin-ui) or [section 8](#8-routine-updates-through-the-server-cli). If it shows “Migration to blue/green deployment,” confirm a maintenance window before applying it. Enrollment alone does not convert the layout.

## 6. Configure authorization for the admin UI

As a host administrator, run:

```bash
sudo geoflow-updater authorization-uri --instance primary
```

Import the three returned URIs into an authenticator held by a trusted administrator:

| Authenticator entry | Admin UI operations |
|---|---|
| `update` | Check and safely update; Switch application back |
| `backup` | Create full backup |
| `rollback` | Restore data and release |

These URIs contain authorization secrets. Keep them out of tickets, chat logs, and public screenshots. Use a fresh six-digit code from the matching entry for each operation; an accepted code cannot be reused. Repeated invalid codes trigger a lockout, so check the entry and authenticator time before retrying.

The admin UI also requires the current administrator password by default; the site configuration controls this requirement. Plan previews and verification do not need an operation code. The server CLI uses host administrator permissions.

## 7. Routine updates through the admin UI

1. Open “System Update Center” as a super administrator. The default path is `/geo_admin/system-updates`. Confirm that updater is connected, authorization is configured, and no operation is running or awaiting recovery.
2. Click “Preview upgrade plan.” Preview may pull images and start temporary inspection containers. Allow it to finish; it does not apply migrations or switch traffic for this update.
3. Review the target version, strategy, layout change, and pending migrations.
4. If the plan says “Maintenance upgrade,” schedule downtime and select the confirmation that allows service interruption.
5. Click “Check and safely update.” Supply your administrator password when requested and a fresh code from the `update` entry.
6. Follow the stages until the final status is “Completed.” Receiving an operation ID only means the task has started.
7. Click “Run verification,” then perform the business checks in [section 9](#9-automatic-migrations-and-verification).

The page submits the digest from the preview with the update request. If it reports that the plan changed or has not been previewed, obtain a new preview, review it, and submit again.

The admin UI may be temporarily unavailable during maintenance. Reopen it afterward to check the result. Host-side updater executes an operation submitted through the admin UI; closing the browser page does not cancel it.

## 8. Routine updates through the server CLI

### 8.1 Diagnose and preview

```bash
sudo geoflow-updater doctor --instance primary --json
sudo geoflow-updater update --instance primary --dry-run --json
```

A host-side preview can take up to 25 minutes. Review these fields:

| Field | Meaning |
|---|---|
| `target_version` | Release to install |
| `source_sequence` / `target_sequence` | Current and target release sequences |
| `strategy` | Online or maintenance update |
| `layout_change` | Whether the deployment layout will change |
| `pending_migrations` | Database migrations that have not been applied |
| `steps` | Backfills, checks, and cache steps in the release plan |
| `plan_sha256` | Preview digest required to confirm execution |

Use `plan_sha256` in the update command. The separate `upgrade_plan_sha256` field is the digest of the application upgrade-plan file.

### 8.2 Choose one command based on the strategy

Replace the placeholder with the 64-character `plan_sha256` from this preview:

```bash
GEOFLOW_PLAN_SHA256='REPLACE_WITH_PLAN_SHA256_FROM_THIS_PREVIEW'
```

For an `online` plan:

```bash
sudo geoflow-updater update \
  --instance primary \
  --plan-sha256 "$GEOFLOW_PLAN_SHA256" \
  --json
```

For a `maintenance` plan, once the agreed maintenance window has started:

```bash
sudo geoflow-updater update \
  --instance primary \
  --plan-sha256 "$GEOFLOW_PLAN_SHA256" \
  --allow-maintenance \
  --json
```

Choose one of these commands. `--allow-maintenance` permits downtime; it does not turn a maintenance plan into an online plan. There is no force-online switch.

The CLI waits for completion. Use a persistent terminal session for long operations and keep it connected to avoid triggering recovery when the session ends.

### 8.3 Use the repository wrapper

The GEOFlow script `scripts/geoflow-deploy.sh` calls the installed updater and uses the same execution logic. Routine managed operations do not require a source checkout on the server.

From the GEOFlow source root, the equivalent maintenance workflow starts with:

```bash
sudo ./scripts/geoflow-deploy.sh status --instance primary --json
sudo ./scripts/geoflow-deploy.sh update --instance primary --dry-run --json
```

Review the new preview and update `GEOFLOW_PLAN_SHA256`, then run:

```bash
sudo ./scripts/geoflow-deploy.sh update \
  --instance primary \
  --plan-sha256 "$GEOFLOW_PLAN_SHA256" \
  --allow-maintenance
```

Omit `--allow-maintenance` for an online plan. The wrapper uses `status`; its updater equivalent is `doctor`.

## 9. Automatic migrations and verification

Updater invokes `geoflow:upgrade` inside the candidate application to inspect, apply, and verify the signed plan. The current plan includes:

| Step | Purpose |
|---|---|
| Database migrations | Check file digests and run migrations that have not yet been applied |
| Managed images | Run image-readiness processing and verification |
| System knowledge | Update built-in knowledge and media |
| Retrieval backfill | Backfill retrieval data and verify it |
| Security checks | Check the security baseline after the upgrade |
| Cache warmup | Compile the candidate release's configuration, routes, and views |

Updater and the application persist stages and steps. Retries use these records and the actual state to continue. Use updater for routine upgrades; separate `git pull`, `composer install`, frontend builds, or manual `migrate` commands are unnecessary.

After candidate validation, updater switches ingress, hands over background tasks, and drains old requests. Health observation defaults to 120 seconds. Downloads, migrations, and draining add to the total duration. If old requests exceed the drain timeout, updater retains the old slot and reports recovery as required.

After completion, run:

```bash
sudo geoflow-updater doctor --instance primary --json
sudo geoflow-updater verify --instance primary --json
curl --fail --show-error https://geo.example.com/up
```

Confirm that the final operation status is `succeeded`, the instance has the expected version, and health checks have no unresolved failures. A blue/green instance reports `layout: blue-green` and an `active_slot` of blue or green.

Automatic checks cover service processes, ingress release identity, HTTP, the database, Redis, storage, and business upgrade checks. Also test through the public domain:

- Open the home page, existing articles, images, and static assets.
- Confirm that existing sessions still work and that a new admin login succeeds.
- Save a test content item and confirm that it can be read.
- Run a small controlled task and check that it completes without duplicate results.
- Check connections and reconnections on real-time or streaming pages.

## 10. Backups and recovery

### 10.1 Create a full backup

**A full backup pauses service and writes, so schedule a maintenance window.** In the admin UI, select “Create full backup” and use a `backup` code. On the server:

```bash
sudo geoflow-updater backup --instance primary --json
sudo geoflow-updater recovery-points --instance primary
```

A recovery point includes PostgreSQL, the complete site storage tree, Redis data after writes stop, environment configuration, and managed deployment files. Updater retains five points by default and protects the newest pre-update checkpoint among them.

The database snapshot taken during an online update covers PostgreSQL only. It cannot replace a full recovery point containing business files and Redis. Include the downtime for any separate full backup in your maintenance planning.

### 10.2 Switch the application back and keep current data

This is available after a **successfully completed, compatible online blue/green update**, while the retained previous application still matches the current deployment. It does not provide arbitrary historical version selection or a general recovery path for maintenance updates and pending recovery states.

In the admin UI, click “Switch application back” and use an `update` code. On the server:

```bash
sudo geoflow-updater switch-back --instance primary --json
```

The equivalent wrapper command is:

```bash
sudo ./scripts/geoflow-deploy.sh rollback --application --instance primary
```

This reactivates the retained application while continuing to use the current database and business files. Writes made since the update are preserved.

### 10.3 Restore data from a full recovery point

Use this when data, configuration, and the application release need to be restored together. **Data created or changed after the recovery point will be overwritten.** Confirm the recovery time, business impact, and maintenance window first.

```bash
sudo geoflow-updater recovery-points --instance primary
```

Select an ID returned by that command:

```bash
GEOFLOW_RECOVERY_POINT='REPLACE_WITH_VERIFIED_RECOVERY_POINT_ID'
sudo geoflow-updater rollback \
  --instance primary \
  --recovery-point "$GEOFLOW_RECOVERY_POINT" \
  --json
```

The equivalent wrapper command is:

```bash
sudo ./scripts/geoflow-deploy.sh rollback --data \
  --instance primary \
  --recovery-point "$GEOFLOW_RECOVERY_POINT"
```

“Restore data and release” in the admin UI uses a `rollback` code and only permits the newest pre-update checkpoint. A host administrator can select other verified full recovery points through the CLI. Repeat the checks in [section 9](#9-automatic-migrations-and-verification) after restoration.

## 11. Troubleshooting and interrupted operations

| Symptom | Action |
|---|---|
| `install` or `--dry-run` is not recognized | Check updater's version and confirm the official package includes this workflow |
| `signed release has no upgrade plan` | The release repository still provides a legacy release; a matching signed release is needed. Keep the plan intact |
| Enrollment version mismatch | Reach an enrollable version through the supported legacy procedure |
| Admin UI says “Disconnected” | Check the service and the managed containers' socket and instance-token mounts. Do not mount the Docker socket into the website |
| Host path is missing in the admin UI | Set `GEOFLOW_UPDATER_HOST_ROOT` to the actual host directory to generate enrollment commands; service installation and connection are still required |
| Plan digest changed | Preview again, review, then submit |
| Signature, expiry, or image-digest check fails | Check host time, network access, and the release repository; keep verification enabled |
| Another operation is active | Wait for it to finish and avoid duplicate submissions |
| Maintenance has not been allowed | Schedule a window, then use the maintenance checkbox or CLI option |
| `rolled_back` / “Automatically rolled back” | The update failed and recovery ran; confirm the old release is healthy before investigating |
| `recovery_required` / “Recovery required” | Automatic handling is incomplete; preserve state and inspect the failed stage, logs, and recovery points |
| Application switch-back is rejected | Check the conditions in [section 10.2](#102-switch-the-application-back-and-keep-current-data) and assess full data recovery if needed |

Useful diagnostics:

```bash
sudo geoflow-updater doctor --instance primary --json
sudo systemctl status geoflow-updater --no-pager
sudo journalctl -u geoflow-updater -n 200 --no-pager
sudo geoflow-updater recovery-points --instance primary
```

Updater checks persisted records at startup and during background reconciliation to handle interrupted operations. Keep deployment journals, lock files, slots, and data volumes intact. If the service has stopped, investigate first, then start it and observe recovery. Avoid repeatedly restarting an active service.

Maintenance upgrades have a full-checkpoint recovery path before traffic reopens. Online failures prioritize application recovery while preserving new writes. After traffic opens, updater does not automatically rewind the whole database. Run a separate [data-recovery operation](#103-restore-data-from-a-full-recovery-point) if business data must be restored.

Before sharing diagnostics, remove passwords, tokens, authorization URIs, and private business data. Include the operation ID, versions, failed stage, and redacted errors.

## 12. Maintainer reference

Site administrators select and confirm plans through the workflow above. Publishers declare online compatibility and validate it against the corresponding candidate release.

**September 9, 2026 update:** The signed candidate paired with Updater `0.4.0-rc.4` passed installation, full upgrade/restoration, interruption recovery and online-mechanism acceptance on native amd64 and arm64. See the [acceptance report](reports/2026-09-09-blue-green-host-acceptance_en.md) for candidate identity, individual results and fix PRs. Technical acceptance and official publication have separate records; installation still requires the [release prerequisites](#geoflow-bluegreen-deployment-and-automatic-migration-tutorial).

- `deployment/upgrade-plan.json` pins migration-file digests. After adding migrations, update and review the manifest, then run `python3 deployment/generate-upgrade-plan.py --check`.
- A schema 3 release manifest includes the complete plan as the TUF-signed target `releases/<version>/upgrade-plan.json`. Maintenance plans require protocol 3 or later; online plans require protocol 4 or later. Application-plan and preview schemas have their own versions.
- The [candidate acceptance workflow](https://github.com/yaojingang/geoflow-updater/actions/workflows/planned-acceptance.yml) runs application and ingress checks on native amd64 and arm64 hosts, plus separate full upgrade/restore, first-install retry, and online-switch rehearsals. Restoration checks cover the database, Redis, files, configuration, and migration history. Interruption cases include a frozen scheduler and failed-recovery retry state.
- Online rehearsal uses a separately signed fixture with identical application code. It checks login sessions, queued job handover, cross-slot realtime messages, reconnect, and application switch-back that preserves data. Production online compatibility still needs acceptance for the actual old-version/new-version pair. Ordinary publication currently accepts maintenance plans.
- Publishers review complete results for the same candidate on both architectures and obtain release, security, and product approvals. Automated checks require each mandatory case. Runtime fixes require a new candidate build and another acceptance run. The legacy `phase-c-rehearsal.yml` accepts schema 2 candidates only. See [host acceptance instructions](https://github.com/yaojingang/geoflow-updater/blob/main/docs/planned-host-acceptance.md).
- Application and upgrade processes use UID 33. Runtime processes disable repeated permission scans and automatic cache optimization; pre-switch upgrade steps warm each slot's view cache. Existing APP_KEY, business storage, and session identity are preserved, and the protected `.env.prod` is mounted read-only.
- Deployment state is stored under `/var/lib/geoflow-updater`; full recovery points are under `/var/backups/geoflow-updater`. Updater manages these directories. Include the site's `.env.prod` and `storage/` in your operational data management.

Related documentation: [Deployment design (Chinese)](superpowers/plans/2026-09-08-blue-green-deployment-design.md), [Implementation validation (Chinese)](reports/2026-09-08-blue-green-implementation-validation.md), [Legacy 3.0 upgrade guide (Chinese)](deployment/GEOFLOW_V3_UPGRADE.md), [Updater blue/green deployment guide (Chinese)](https://github.com/yaojingang/geoflow-updater/blob/main/docs/blue-green-deployment.md), and [Updater release runbook](https://github.com/yaojingang/geoflow-updater/blob/main/docs/release-runbook.md).

### 12.1 Site administrator responsibilities

Release maintainers perform dual-architecture candidate acceptance. Site administrators install the matching Updater described in the release notes, schedule a maintenance window for initial handover and layout conversion, and verify their own backup and restoration process.

Before rollout, rehearse one upgrade and full restoration with a copy of the site's data in an isolated environment. Check login, critical business functions, queue jobs, files, and realtime messages. Record backup, migration, and restore durations for the site's data volume to plan the maintenance window. Preview each later update and choose the procedure indicated by its `online` or `maintenance` strategy.
