# GEOFlow 3.2.1 upgrade guide

[简体中文](GEOFLOW_V3_2_1_UPGRADE.md) | English

The paired release uses Core `3.2.1`, Updater `0.5.0` and signed release sequence `4`. An upgrade from 3.1.0 applies 28 new migrations in a maintenance window. Confirm that both the [Core release](https://github.com/yaojingang/GEOFlow/releases/tag/v3.2.1) and the [Updater release](https://github.com/yaojingang/geoflow-updater/releases/tag/v0.5.0) are available before upgrading.

## Prepare the site

1. Record the current source revision, image digests, Compose project name, override files, ports and volume mounts. Keep the existing PostgreSQL and Redis major versions.
2. Restrict public traffic, stop accepting new work, drain requests and queues, and stop workers and the scheduler. Retain the maintenance boundary until verification succeeds.
3. Back up PostgreSQL, the complete Redis `/data` directory, `storage/`, custom themes, environment and deployment files. Retain the existing `APP_KEY` and the previous source and images. On managed hosts, retain instance configuration, recovery points and operation receipts.
4. Restore a copy into a separate environment and verify login, articles, uploads, themes and encrypted fields. When introducing a Redis bind mount, follow the [Redis migration procedure](DEPLOYMENT.md#32-首次启用-redis-持久化目录) to preserve the old container's complete RDB and AOF files before replacing it.

## Managed 3.1.0 installations

Verify and install the paired Updater package. Use the actual instance name if it differs from `primary`.

```bash
sudo geoflow-updater version
sudo geoflow-updater doctor --instance primary --json
sudo geoflow-updater update --instance primary --dry-run --json
```

Verify target `3.2.1`, sequence `4` and the maintenance plan. Use the exact `plan_sha256` returned by the dry run after workers are drained:

```bash
sudo geoflow-updater update --instance primary \
  --plan-sha256 PLAN_SHA256 --allow-maintenance
sudo geoflow-updater verify --instance primary
```

After a full recovery, check `http_ready` and the background `held` state. Sign in again to read restored data, and reconcile the original operation receipt, recovery epoch and each business domain. This release has no supported operation to lift the hold, so writes and background work stay paused. Preserve the recovery state and contact the maintainer before resuming work.

## Standard Docker installations

Verify the source ZIP and its checksum, then extract and build the app and web images. The [production deployment guide](DEPLOYMENT.md) covers installation and the Redis mount transition. On an existing site, keep the original Compose project, volume sources, environment and `APP_KEY`. Resolve volume paths before starting any new service. Retain the old source and images for recovery.

Use a one-off app container with `AUTO_MIGRATE`, `AUTO_INSTALL_ONCE` and `AUTO_OPTIMIZE` disabled. Run `php artisan geoflow:upgrade --phase=inspect --source-sequence=3 --json` against the official 3.1.0 baseline. Confirm the migration count and plan digest. After draining the old runtime, execute the maintenance `apply` phase with the inspected digest, a unique operation ID and `GEOFLOW_SECURITY_UPGRADE_DRAIN_CONFIRMED=true`. Run the `verify` phase with the same operation and digest. The [Chinese guide](GEOFLOW_V3_2_1_UPGRADE.md#标准-docker-人工维护升级) includes the complete Compose commands.

## Verify the result

Under the external maintenance boundary, start the new web and app services and clear the Laravel maintenance marker if present. Verify health, login, articles, themes, uploads, knowledge, Redis and real-time messaging. Resume workers and traffic after verification. Recheck older quality reports and retain the existing review state of held articles.

Existing environments with an explicit branch metadata URL should set `GEOFLOW_UPDATE_METADATA_URL` to `https://github.com/yaojingang/GEOFlow/releases/latest/download/version.json` and refresh configuration caches. New installations inherit that setting from the examples.

For a failed upgrade, preserve the logs and maintenance boundary. Restore the original version with a consistent database, storage, Redis and configuration snapshot into a separate target and verify it before switching traffic. If the new version has accepted writes, preserve those changes and plan reconciliation before restoring an older snapshot.
