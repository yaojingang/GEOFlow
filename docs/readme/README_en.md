# GEOFlow

> Languages: [简体中文](../../README.md) | [English](README_en.md) | [日本語](README_ja.md) | [Español](README_es.md) | [Русский](README_ru.md) | [Português (BR)](README_pt_BR.md)

> GEOFlow is an open-source GEO (Generative Engine Optimization) content engineering and multi-site distribution system. It connects system knowledge, materials and prompts, AI generation, article quality inspection, review and publishing, browser-assisted operations, hosted channel sites, and analytics into one maintainable workflow for turning trustworthy source material into trackable, publishable, multi-channel GEO content assets.

[![PHP](https://img.shields.io/badge/PHP-8.3%2B-blue)](https://www.php.net/)
[![CI](https://github.com/yaojingang/GEOFlow/actions/workflows/ci.yml/badge.svg)](https://github.com/yaojingang/GEOFlow/actions/workflows/ci.yml)
[![PostgreSQL](https://img.shields.io/badge/Database-PostgreSQL-336791)](https://www.postgresql.org/)
[![Docker](https://img.shields.io/badge/Docker-Compose-blue)](https://docs.docker.com/compose/)
[![License](https://img.shields.io/badge/License-AGPL--3.0-blue.svg)](../../LICENSE)
[![GitHub stars](https://img.shields.io/github/stars/yaojingang/GEOFlow?style=social)](https://github.com/yaojingang/GEOFlow/stargazers)
[![GitHub forks](https://img.shields.io/github/forks/yaojingang/GEOFlow?style=social)](https://github.com/yaojingang/GEOFlow/network/members)
[![GitHub issues](https://img.shields.io/github/issues/yaojingang/GEOFlow)](https://github.com/yaojingang/GEOFlow/issues)

GEOFlow is released under the [GNU Affero General Public License v3.0](../../LICENSE). Individuals and organizations may use, modify, deploy, and distribute it in compliance with AGPL-3.0. A modified version offered to users over a network must offer those users its corresponding source as required by the license. A separate commercial license is available from the copyright holder for proprietary modifications, white-label or OEM distribution, embedding in proprietary products, or other deployments that cannot comply with AGPL-3.0.

### Anonymous usage telemetry

Anonymous usage telemetry is disabled by default. Operators can enable it and configure an HTTPS collector endpoint to measure deployment totals, daily admin activity, and version distribution. The browser event contains a random installation ID, an irreversible administrator digest, the GEOFlow version, and the event type. An empty endpoint sends no request.

Domains, page paths, administrator accounts, email addresses, article content, cookies, `APP_KEY`, and business secrets are excluded from the payload. The default configuration is:

```dotenv
GEOFLOW_TELEMETRY_ENABLED=false
GEOFLOW_TELEMETRY_ENDPOINT=
```

---

## ✨ What You Can Do With It

| Feature | Description |
|---------|-------------|
| 🤖 Multi-model generation | OpenAI-style APIs and native Gemini endpoints, chat / embedding models, provider URL adaptation, smart failover, retries, and usage statistics |
| 🧠 Knowledge-base RAG | Upload documents, use structured rule chunking, optional LLM semantic planning, and stable fallback; write vectors when an embedding model is configured, then retrieve relevant context during generation |
| 🧭 Illustrated admin help | Built-in system knowledge, permission-filtered feature links, streaming answers, and 24 sanitized admin screenshots for product guidance and troubleshooting |
| 🛡️ Article AI quality inspection | Checks articles against knowledge evidence, advertising rules, and publishing context, with scored dimensions, issue locations, legal references, recommendations, and audited overrides |
| 🗂 Materials and prompts | Title libraries, keyword libraries, image libraries, authors, knowledge bases, body prompts, and special prompts |
| 📦 Task automation | Generation limits, draft pools, review toggles, publishing cadence, queues, retries, publication-scope control, and task-scoped article filtering |
| 📋 Review and article management | Drafts, review, publishing, trash, authors, categories, SEO fields, and task source tracking |
| 🌐 Chrome operations assistant and PWA | Device-paired Manual Publication work, reviewable draft filling, execution evidence, and an installable standalone admin workspace |
| 📡 Multi-site distribution | GEOFlow Agent, WordPress REST, and Generic HTTP API channels, secrets, target-site packages, static mode, rewrite rules, remote article editing/deletion, queues, and logs |
| 🧾 Target-site packages | Per-channel PHP Agent packages with homepage, article pages, static assets, sitemap, `llms.txt` / TXT maps, and Schema output |
| 📊 Analytics | System overview, single-site operations, multi-site distribution, access logs, top content, AI crawler recognition, and trend charts |
| 🔍 SEO and LLM-friendly output | SEO metadata, Open Graph, Schema, GFM Markdown, standalone CSS, image sync, sitemap, and TXT maps |
| 🎨 Front-end and themes | Default themes, theme packages, preview routes, admin switching, and GEOFlow Agent remote title/copyright/theme/category sync |
| 🌍 Admin i18n | Chinese, English, Japanese, Spanish, Russian, and Portuguese (Brazil), including Admin UI V3 modules |
| 🔔 Version updates | Admin can check GitHub `version.json` and notify admins when a newer version is available |
| ♻️ Independent updates and recovery | GEOFlow Updater uses signed installers and a local Unix socket for website updates, full backups, environment verification, and recovery-point rollback |
| 🐳 Ready to deploy | **Docker Compose** for PostgreSQL (pgvector), Redis, app, queue, scheduler, Reverb, and production Nginx/php-fpm |

---

## GEOFlow CLI 0.2.0

The repository includes `bin/geoflow` for API v1 catalog, task, job, material, and article operations. It supports secure profiles, login, JSON file or stdin input, deletion confirmation, and structured error hints on macOS, Linux, and WSL. Native Windows ACLs require manual verification.

[Full CLI guide](../GEOFLOW_CLI_en.md) | [中文文档](../GEOFLOW_CLI.md)

---

## 🖼 UI Preview

<table>
  <tr>
    <td width="34%" rowspan="3"><img src="../../docs/images/screenshots/analytics-en.png" alt="GEOFlow analytics preview" /><br /><sub>Analytics</sub></td>
    <td width="33%" rowspan="2"><img src="../../docs/images/screenshots/site-settings-en.png" alt="GEOFlow site settings preview" /><br /><sub>Site Settings</sub></td>
    <td width="33%"><img src="../../docs/images/screenshots/dashboard-en.png" alt="GEOFlow admin dashboard preview" /><br /><sub>Admin Dashboard</sub></td>
  </tr>
  <tr>
    <td width="33%"><img src="../../docs/images/screenshots/tasks-en.png" alt="GEOFlow task management preview" /><br /><sub>Task Management</sub></td>
  </tr>
  <tr>
    <td width="33%"><img src="../../docs/images/screenshots/ai-config-en.png" alt="GEOFlow AI model configuration preview" /><br /><sub>AI Model Configuration</sub></td>
    <td width="33%"><img src="../../docs/images/screenshots/materials-en.png" alt="GEOFlow materials preview" /><br /><sub>Materials</sub></td>
  </tr>
</table>

These screens cover the admin dashboard, analytics, task scheduling, materials, model configuration, and site settings. More admin documentation lives under `../../docs/`.

---

## 🆕 New Version Highlights

GEOFlow 3.0 is a major upgrade across the admin, AI, distribution, and operations workflows:

- **Admin UI V3 is unified across the product**: admin pages share the new sidebar, top bar, navigation, and interaction patterns, with recent activity, adjustable sidebar width, responsive layouts, accessible states, and local assets.
- **AI Workspace is now an illustrated admin help assistant**: built-in system knowledge covers 15 admin topics with 24 sanitized screenshots and a fixed 72-question evaluation set. It streams answers over SSE and shows only features the current administrator can access. The legacy Run, Plan, Approval, Capability, and Trace workflow no longer accepts new requests, while historical data remains available.
- **Article AI quality inspection is a publishing gate**: checks use task knowledge, versioned advertising rules, and publishing context to return scores, evidence, source locations, legal references, and recommendations. Reviewable, blocked, failed, and stale results remain drafts.
- **Hosted channel sites have a complete lifecycle**: subdomain allocation, lifecycle management, article assignment, publishing quotas, cooldowns, technical preflight, cache invalidation, and reconciliation now share primary-host and trusted-proxy safeguards.
- **Manual Publication connects to the Chrome operations assistant**: the extension uses device pairing and least-privilege tokens to claim work, send heartbeats, verify accounts, fill plain-text Zhihu answer drafts, and return execution evidence. Users still confirm the final publication.
- **The admin can be installed as a PWA**: local icons, the web app manifest, service worker, and update flow provide a standalone workspace experience.
- **Long-running content operations are covered**: title libraries support queued generation of up to 100,000 titles with recovery and cancellation, task trash keeps a 90-day audit record, and article lists support batch Markdown export.
- **Task and model configuration is safer**: title library capacity and conflicts are checked before task activation, while model probes record real streaming readiness, plain-text fallback, failover, and more complete readiness states.
- **API and CLI cover routine operations**: API v1 and `bin/geoflow` cover catalogs, tasks, runs, materials, articles, and browser operations with structured input, output, and safety checks.
- **The independent updater owns high-risk operations**: the website requests updates, full backups, environment verification, and recovery-point rollback over a fixed local Unix-socket API. Sensitive actions require the administrator password and a six-digit authenticator code, while legacy update history remains available for read-only audit.
- **Installation and deployment boundaries are clearer**: fresh installations start with clean required data and do not import demo articles automatically. Upgrades run migrations, rebuild frontend assets, restart runtime processes, and then install and verify [GEOFlow Updater](https://github.com/yaojingang/geoflow-updater). Hosted sites stay disabled until wildcard DNS, wildcard TLS, trusted proxy, and Nginx settings are ready.

---

## 🏗 Runtime Structure

```
Admin dashboard
    ↓
AI config / materials / prompts / task settings
    ↓
Scheduler / queue / worker runs AI generation
    ↓
Draft / review / publish
    ↓
Local front-end articles & SEO pages
    ↓
Distribution queue / target-site Agent
    ↓
Remote static homepage, article pages, sitemap, TXT maps, and llms.txt
```

---

## 🧱 System Architecture

| Layer | Description |
|-------|-------------|
| Web / Admin | **Laravel** routes and controllers; article site, **Blade** admin, analytics, distribution, materials, and tasks |
| API / Agent | Local APIs and target-site PHP Agents for health checks, article receive/update/delete, remote settings sync, and static-file generation |
| Scheduler / Queue / Reverb | **Laravel Scheduler**; **`queue:work` / Horizon** consumers for generation and distribution; **Reverb** when enabled |
| Domain & Jobs | `app/Services`, `app/Jobs`, `app/Http/Controllers`, etc. for AI generation, RAG, publishing, distribution, and log analytics |
| Persistence | **PostgreSQL** (recommended: **pgvector** aligned with Compose) + **Redis** for queues/cache + target-site JSON/static files |

Core pipeline:

1. Configure models, prompts, and libraries in admin
2. Prepare knowledge, title, keyword, image, and author assets, and choose a knowledge chunking strategy when needed
3. Create tasks and hand off to scheduler/queue
4. Workers call models to generate body text and metadata
5. Articles move through draft, review, and publish
6. The local front-end renders articles and SEO pages
7. Selected channels enqueue distribution and sync content to GEOFlow Agent or WordPress target sites
8. Analytics tracks content production, distribution status, access logs, and AI crawler trends

---

## ⚡ Admin Quick Start

After signing in, use the dashboard quick-start path for the first validation cycle:

1. **Configure API**: add at least one working chat model; add an embedding model and choose a chunking strategy if you need knowledge-base RAG retrieval.
2. **Configure materials**: prepare knowledge bases, title libraries, keyword libraries, image libraries, and authors. Start from real, verifiable business information.
3. **Create a task**: choose libraries, materials, model, generation count, publishing cadence, and publication scope. Start with draft or review flow before enabling full auto-publish and multi-site distribution.

---

## 🎯 Use Cases and Expected Outcomes

GEOFlow fits these practical scenarios:

- **Independent GEO website**
  Organize product content, FAQs, cases, and brand knowledge into a maintainable system—aim for AI-search visibility and operational efficiency, not thin pages at scale.
- **GEO sub-channel inside an official site**
  Add a dedicated news, knowledge, or solutions channel under an existing site—structure content for search and citations, with easier team updates.
- **Independent GEO source site**
  Publish high-quality explainers, lists, guides, and references for an industry or topic—build credible assets, not web noise.
- **Internal GEO content management**
  Use as a production backend for models, assets, knowledge, review, and publishing—raise team efficiency and reduce tool sprawl.
- **Multi-site / multi-section GEO**
  Operate multiple outlets or templates with one operational pattern—standardize production and maintenance.
- **Automated source management and distribution**
  Engineer knowledge bases, topical updates, and distribution—help valuable information stay structured and retrievable.

Value should rest on a **real, high-quality, maintained knowledge base**.
GEOFlow is not for fabricating noise, mass pollution, or false claims—it helps teams produce and distribute **trustworthy** content and improve GEO operating efficiency.

---

## 🧭 Suggested Deployment and Usage Patterns

- **As a standalone GEO website**
  Deploy full front-end and admin; run product, FAQ, case, and topic content as a first-class property.
- **As a GEO sub-channel**
  Deploy as a subdirectory, subdomain, or side channel without rebuilding the main site.
- **As a GEO source site**
  Prioritize the knowledge base, then use tasks for steady, controlled updates.
- **As an internal GEO backend**
  De-emphasize the public site; focus on admin, models, assets, scheduling, review, and APIs.
- **As a multi-site or multi-channel system**
  Reuse workflows across brands, themes, or experiments.
- **As an automated source-management layer**
  Invest in title/image/prompt libraries and knowledge as long-term infrastructure.

Suggested order of work:

1. Clarify real goals and audience
2. Build the knowledge base before heavy automation
3. Keep content accurate, verifiable, and maintainable
4. Then scale with models, tasks, and templates

Weak knowledge bases plus strong automation only scale noise. In GEOFlow, **knowledge-base quality comes first**.

---

## 🚀 Quick Start

### Option 1: Docker (development / demo)

```bash
git clone https://github.com/yaojingang/GEOFlow.git
cd GEOFlow
cp .env.example .env
vi .env   # DB, Redis, APP_URL, ADMIN_BASE_PATH, REVERB_*, etc.

docker compose build
docker compose up -d
```

- Site (default): `http://localhost:18080` (host port from **`APP_PORT`**, default `18080`)
- Admin login: `http://localhost:18080/geo_admin/login` (path prefix from **`ADMIN_BASE_PATH`**, default `geo_admin`)

Under **`docker-compose.yml`**, the **`init`** service runs first-time migration and seeding after the database is ready (default admin—see below).

### Option 1 add-on: Docker (production)

For production, use **`docker-compose.prod.yml`** with **Nginx + php-fpm** instead of `php artisan serve`.

For a first install on a fresh empty database, you can use the reference deployment script to run host checks, prepare `.env.prod`, deploy containers, and run post-deployment health checks:

```bash
curl -fsSL https://raw.githubusercontent.com/yaojingang/GEOFlow/main/deploy-scripts/geoflow-docker-deploy.sh -o geoflow-docker-deploy.sh
bash geoflow-docker-deploy.sh
```

See [`../../deploy-scripts/README.md`](../../deploy-scripts/README.md).

```bash
cp .env.prod.example .env.prod
vi .env.prod

docker compose --env-file .env.prod -f docker-compose.prod.yml build
docker compose --env-file .env.prod -f docker-compose.prod.yml up -d postgres redis
docker compose --env-file .env.prod -f docker-compose.prod.yml up -d init
docker compose --env-file .env.prod -f docker-compose.prod.yml up -d app web queue ai-quality-queue ai-quality-backfill-queue ai-optimization-queue knowledge-queue scheduler reverb
```

- Frontend and admin both enter through `web` (Nginx)
- PHP is executed by `app` (php-fpm)
- **First install:** the production `init` service runs migrations and then `php artisan geoflow:install`. This sequence is limited to a fresh empty database. Deployments with data or migration history must follow the stopped-and-drained upgrade protocol in section 3.1 of `../../docs/deployment/DEPLOYMENT.md`.
- See `../../docs/deployment/DEPLOYMENT.md` for details

### Option 2: Local PHP stack

**Prerequisites:** PHP **8.3+** with `pdo_pgsql`, `redis`, and other typical Laravel extensions; local **PostgreSQL** and **Redis**; **Composer 2.x**.

```bash
git clone https://github.com/yaojingang/GEOFlow.git
cd GEOFlow
cp .env.example .env
# Edit .env: DB_* → Postgres, REDIS_* → Redis, QUEUE_CONNECTION=redis, etc.

composer install --no-interaction --prefer-dist
php artisan key:generate

GEOFLOW_SECURITY_FRESH_INSTALL_CONFIRMED=true php artisan migrate --force
php artisan geoflow:install                                            # first install on an empty database
php artisan storage:link

php artisan serve --host=127.0.0.1 --port=8080
```

In separate terminals:

```bash
php artisan queue:work redis --queue=geoflow,distribution,default --sleep=1 --tries=1 --timeout=300
php artisan schedule:work
php artisan reverb:start
```

- Admin: `http://127.0.0.1:8080/geo_admin/login` (adjust if `ADMIN_BASE_PATH` changes)
- In production you may use `php artisan horizon` instead of `queue:work`, supervised by systemd or Supervisor

---

## Environment checklist

| Component | Notes |
|-----------|--------|
| PHP | **8.3+** (Docker image may use 8.4) |
| Extensions | Standard Laravel set; `pdo_pgsql` for Postgres; `redis` for queues |
| Composer | 2.x |
| Database | **PostgreSQL** (recommended: **pgvector**, same as Compose image) |
| Redis | Queues/cache (you can set `QUEUE_CONNECTION=sync` for minimal local-only tests—not for production) |

---

## Source deployment notes

```bash
chmod -R ug+rwx storage bootstrap/cache
```

**Default admin** (after first-empty-db `php artisan geoflow:install`, see `Database\Seeders\AdminUserSeeder`):

| Field | Value |
|-------|--------|
| Username | `GEOFLOW_ADMIN_USERNAME`, default `admin` |
| Password | Local/dev default `password`; in production set `GEOFLOW_ADMIN_PASSWORD`. If it is empty and the account does not exist yet, the installer generates a one-time random password in the init / `geoflow:install` logs. |

`geoflow:install` only runs install seeders on a fresh empty database. If it detects existing user/business data but no installation marker, it writes the marker and skips seeding. `AdminUserSeeder` itself remains idempotent and never overwrites an existing username, email, or password.

### Admin login lockout and manual unlock

- Admin accounts are automatically locked (`status=locked`) after **5** consecutive failed login attempts.
- Locked accounts cannot sign in until manually unlocked by an administrator.
- Unlock command:

```bash
php artisan geoflow:admin-unlock USERNAME
```

Example:

```bash
php artisan geoflow:admin-unlock admin
```

**Production HTTP:** Nginx/Apache + **PHP-FPM**, document root **`public/`**—do not expose the project root as the web root.

---

## Docker deployment notes

### Development Compose services

| Service | Role |
|---------|------|
| `postgres` | PostgreSQL 16 + pgvector |
| `redis` | Redis 7 |
| `assets` | One-off frontend dependency install and Vite asset build |
| `init` | One-off bootstrap (`restart: "no"`) |
| `app` | Internal `php artisan serve` process with no host port |
| `web` | Unified Nginx gateway on **`127.0.0.1:${APP_PORT:-18080}:80`** |
| `queue` | AI workspace and application queue worker |
| `scheduler` | `schedule:work` |
| `reverb` | Internal WebSocket service proxied by Nginx at same-origin **`/reverb`** |

Optional localhost-only DB/Redis host ports: see `DB_EXPOSE_PORT` and `REDIS_EXPOSE_PORT` in `docker-compose.yml`.

### `docker/entrypoint.sh` variables

| Variable | Default | Meaning |
|----------|---------|---------|
| `COMPOSER_ON_START` | `true` | Run `composer install` on container start |
| `AUTO_MIGRATE` | `true` | Run `php artisan migrate --force` on start; existing deployments still require the stopped-and-drained protocol for security migrations |
| `AUTO_INIT_ONCE` | `true` on `init` only | Run `migrate` + `geoflow:install`; the installer decides whether the DB is empty |
| `AUTO_INSTALL_ONCE` | `false` | Run `geoflow:install` after migrations; do not enable on long-running services |

The entrypoint automatically runs `key:generate --force` when `.env` does not contain a valid `APP_KEY`; no extra toggle is required.

`./storage` and `./.env` are mounted; application code lives in the image. For production, use the new **`docker-compose.prod.yml`** stack (`Nginx + php-fpm`) and see `../../docs/deployment/DEPLOYMENT.md`.

**Existing deployment upgrades:** do not run `git pull` → `build` → `up -d` directly. Follow the stopped-and-drained migration and readiness protocol in [deployment section 3.1](../deployment/DEPLOYMENT.md#31-受管图片删除升级门禁).

---

## Development & tests

```bash
composer test
./vendor/bin/pint
```

---

## 🌍 README in other languages

- [简体中文](../../README.md)
- [日本語](README_ja.md)
- [Español](README_es.md)
- [Русский](README_ru.md)
- [Português (BR)](README_pt_BR.md)

---

## 📄 License

GEOFlow is licensed under the [GNU Affero General Public License v3.0](../../LICENSE). Individuals and organizations may use, modify, deploy, and distribute GEOFlow in compliance with AGPL-3.0; modified network services must offer corresponding source to their users as required by the license. Contact the copyright holder for separate commercial terms covering proprietary modifications, white-label or OEM distribution, proprietary product integration, or other uses that cannot comply with AGPL-3.0. Versions previously released under Apache-2.0 remain under their original license.

Before submitting code, documentation, designs, or other original material, read the [contribution guide](../../CONTRIBUTING.md). Copyrightable contributions require acceptance of the [GEOFlow Contributor License Agreement v1.0](../../CLA.md) before merge.

---

## ⭐ Star history

[![Star History Chart](https://star-history.dera.page/svg?repos=yaojingang/GEOFlow&type=Date)](https://star-history.dera.page/#yaojingang/GEOFlow&Date)
