# GEOFlow 3.1 upgrade instructions

[简体中文](GEOFLOW_V3_1_UPGRADE.md) | English

[GEOFlow v3.1.0](https://github.com/yaojingang/GEOFlow/releases/tag/v3.1.0) and [GEOFlow Updater v0.4.0](https://github.com/yaojingang/geoflow-updater/releases/tag/v0.4.0) were officially published on September 9, 2026. Matching dual-architecture images and the signed update source are public. This release uses sequence `3` and the `maintenance` strategy.

## Choose an upgrade path

| Current state | Procedure |
|---|---|
| New server | [Install Updater 0.4.0](../blue-green-deployment-usage_en.md#3-install-or-upgrade-updater), then follow fresh site installation |
| Enrolled 3.0.0 site | Upgrade the host updater, then confirm the maintenance upgrade through the CLI below |
| Unenrolled standard Docker 3.0.0 site | Upgrade Core to 3.1.0 during maintenance, then enroll and convert the layout |
| Custom deployment or earlier version | Review configuration, database and historical upgrade requirements, and test the adapted procedure in isolation |

This release uses a `maintenance` plan. Initial layout conversion and the 3.0.0 to 3.1.0 upgrade require a maintenance window. The online fixture tests switching mechanics; compatibility between actual old and new versions requires its own signed plan.

## Enrolled 3.0.0: use the host CLI for the first upgrade

Pause new business tasks, rehearse backup and full restoration with a copy of site data, and record the expected duration. Verify and install Updater `0.4.0` through the [installation tutorial](../blue-green-deployment-usage_en.md#3-install-or-upgrade-updater), with no installation, update, backup or recovery operation running.

The older admin UI cannot send the maintenance confirmation required by the new plan. Run the first upgrade on the Linux host:

```bash
sudo geoflow-updater version
sudo geoflow-updater doctor --instance primary --json
sudo geoflow-updater update --instance primary --dry-run --json
```

Confirm that the preview targets `3.1.0` with strategy `maintenance`, and save its `plan_sha256`. Replace the placeholder below with that digest. If the update source points to another version, read that release's instructions first.

```bash
sudo geoflow-updater update --instance primary \
  --plan-sha256 PLAN_SHA256 --allow-maintenance
sudo geoflow-updater verify --instance primary
sudo geoflow-updater doctor --instance primary --json
```

Perform the [post-upgrade checks](../blue-green-deployment-usage_en.md#9-automatic-migrations-and-verification). Later updates can use the plan preview and confirmation in the 3.1 admin UI.

## Unenrolled standard Docker 3.0.0: upgrade Core first

`enroll` requires the installed version to match the current signed release. Once the signed source points to 3.1.0, unenrolled 3.0.0 sites first need a maintenance upgrade. These steps apply to an unmodified official Git checkout using the standard `docker-compose.prod.yml`, preserving its project name, data directories and PostgreSQL/Redis major versions.

1. Follow [steps 1 through 3 of the older tutorial's Path B (Chinese)](GEOFLOW_V3_UPGRADE.md#路径-b普通生产-compose-从-2x-升级) to inspect the deployment, stop new work, drain requests and jobs, and back up the database, configuration, storage and old images. Stop the original Redis container and take a cold backup of its data directory, also preserving external storage according to the actual mounts, then restart only the original Redis service. Verify restoration before proceeding. Keep every old application process stopped while retaining the original database and Redis services.
2. In the original site's clean Git worktree, pin the new tag and confirm its commit against the official release. Preserve `.env.prod`, `APP_KEY`, business storage and database mounts.

```bash
git fetch origin tag v3.1.0
git rev-parse 'v3.1.0^{commit}'
git switch --detach v3.1.0
dc() { docker compose --env-file .env.prod -f docker-compose.prod.yml "$@"; }
dc config --quiet
dc build app web
```

3. Run migrations and backfills with the new image. Continue only after each command succeeds, keep the external maintenance restriction active, and set the temporary drain confirmation only after old processes have stopped.

```bash
dc run --rm --no-deps \
  -e GEOFLOW_SECURITY_FRESH_INSTALL_CONFIRMED=false \
  -e GEOFLOW_SECURITY_UPGRADE_DRAIN_CONFIRMED=true \
  -e AUTO_MIGRATE=false -e AUTO_INSTALL_ONCE=false -e AUTO_OPTIMIZE=false \
  init php artisan migrate --force
dc run --rm --no-deps -e AUTO_OPTIMIZE=false app php artisan geoflow:sync-system-knowledge --media
dc run --rm --no-deps -e AUTO_OPTIMIZE=false app php artisan geoflow:backfill-ai-quality-retrieval
dc run --rm --no-deps -e AUTO_OPTIMIZE=false app php artisan geoflow:backfill-ai-quality-retrieval --verify --json
dc run --rm --no-deps -e AUTO_OPTIMIZE=false app php artisan geoflow:managed-images:readiness --json
dc run --rm --no-deps -e AUTO_OPTIMIZE=false app php artisan geoflow:security-audit --json
dc run --rm --no-deps -e AUTO_OPTIMIZE=false app php artisan geoflow:upgrade --phase=verify --json
```

4. Keep maintenance restrictions in place while following [startup and business verification (Chinese)](GEOFLOW_V3_UPGRADE.md#启动新服务与业务验收). Use `3.1.0` as the expected version when applying the older tutorial. Check caches, login, critical functions, queues, files and realtime messages, then follow the [enrollment tutorial](../blue-green-deployment-usage_en.md#5-existing-site-enroll-and-convert-the-layout). Confirm empty queues and drained old services again before handover.
5. Preview and confirm the maintenance layout conversion after enrollment. Conversion of the same complete signed release is allowed only from the old single-deployment layout; converted instances cannot repeat a same-sequence upgrade.

Deployments starting from 2.x or with incomplete 3.0 data governance must first complete [historical administrator/model identity backfills (Chinese)](GEOFLOW_V3_UPGRADE.md#历史-ai-模型与执行身份). The Core ZIP contains source; the Docker build above installs dependencies and builds frontend assets.

## Acceptance, themes and recovery

- Check ordinary and super-administrator login, article generation, task drafts, queues, retrieval, files and realtime messages, and rehearse full restoration in isolation.
- Installed theme packages remain available. Exported packages default to the Core version used during export. Before importing a package restricted to 3.0.0, its author should validate 3.1.0 compatibility, update the declaration and rebuild the package.
- Managed upgrades use complete recovery points for data restoration. Online application switch-back preserves live data; see the [recovery tutorial](../blue-green-deployment-usage_en.md#10-backups-and-recovery) for scope.
- If a manual maintenance upgrade fails, retain maintenance mode and restore the recorded source, images, configuration, complete database and storage backups, then verify business functions.
