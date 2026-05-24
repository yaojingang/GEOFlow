# GEOFlow

> Languages: [简体中文](../../README.md) | [English](README_en.md) | [日本語](README_ja.md) | [Español](README_es.md) | [Русский](README_ru.md) | [Português (BR)](README_pt_BR.md)

> GEOFlow is an open-source GEO (Generative Engine Optimization) content engineering and multi-site distribution system. It connects knowledge bases, material libraries, prompts, AI generation tasks, review and publishing, analytics, GEOFlow Agent target-site packages, WordPress REST channels, and remote static-page distribution into one maintainable workflow for turning trustworthy source material into trackable, publishable, multi-channel GEO content assets.

[![PHP](https://img.shields.io/badge/PHP-8.2%2B-blue)](https://www.php.net/)
[![PostgreSQL](https://img.shields.io/badge/Database-PostgreSQL-336791)](https://www.postgresql.org/)
[![Docker](https://img.shields.io/badge/Docker-Compose-blue)](https://docs.docker.com/compose/)
[![License](https://img.shields.io/badge/License-Apache--2.0-blue.svg)](../../LICENSE)
[![GitHub stars](https://img.shields.io/github/stars/yaojingang/GEOFlow?style=social)](https://github.com/yaojingang/GEOFlow/stargazers)
[![GitHub forks](https://img.shields.io/github/forks/yaojingang/GEOFlow?style=social)](https://github.com/yaojingang/GEOFlow/network/members)
[![GitHub issues](https://img.shields.io/github/issues/yaojingang/GEOFlow)](https://github.com/yaojingang/GEOFlow/issues)

GEOFlow is released under the [Apache License 2.0](../../LICENSE). You may use, copy, modify, and distribute it, including for commercial purposes, provided that you retain copyright and license notices and comply with the patent, trademark, and warranty-disclaimer terms of Apache-2.0.

---

## ✨ What You Can Do With It

| Feature | Description |
|---------|-------------|
| 🤖 Multi-model generation | OpenAI-style APIs and native Gemini endpoints, chat / embedding models, provider URL adaptation, smart failover, retries, and usage statistics |
| 🧠 Knowledge-base RAG | Upload documents, use structured rule chunking, optional LLM semantic planning, and stable fallback; write vectors when an embedding model is configured, then retrieve relevant context during generation |
| 🗂 Materials and prompts | Title libraries, keyword libraries, image libraries, authors, knowledge bases, body prompts, and special prompts |
| 📦 Task automation | Generation limits, draft pools, review toggles, publishing cadence, queues, retries, publication-scope control, and task-scoped article filtering |
| 📋 Review and article management | Drafts, review, publishing, trash, authors, categories, SEO fields, and task source tracking |
| 📡 Multi-site distribution | GEOFlow Agent and WordPress REST channels, secrets, target-site packages, static mode, rewrite rules, remote article editing/deletion, queues, and logs |
| 🧾 Target-site packages | Per-channel PHP Agent packages with homepage, article pages, static assets, sitemap, `llms.txt` / TXT maps, and Schema output |
| 📊 Analytics | System overview, single-site operations, multi-site distribution, access logs, top content, AI crawler recognition, and trend charts |
| 🔍 SEO and LLM-friendly output | SEO metadata, Open Graph, Schema, GFM Markdown, standalone CSS, image sync, sitemap, and TXT maps |
| 🎨 Front-end and themes | Default themes, theme packages, preview routes, admin switching, and GEOFlow Agent remote title/copyright/theme/category sync |
| 🌍 Admin i18n | Chinese, English, Japanese, Spanish, Russian, and Portuguese (Brazil), including GEOFlow 2.0 modules |
| 🔔 Version updates | Admin can check GitHub `version.json` and notify admins when a newer version is available |
| 🐳 Ready to deploy | **Docker Compose** for PostgreSQL (pgvector), Redis, app, queue, scheduler, Reverb, and production Nginx/php-fpm |

---

## 🖼 UI Preview

<table>
  <tr>
    <td width="33%"><img src="../../docs/images/screenshots/dashboard-en.png" alt="GEOFlow admin dashboard preview" /><br /><sub>Admin Dashboard</sub></td>
    <td width="33%"><img src="../../docs/images/screenshots/analytics-en.png" alt="GEOFlow analytics preview" /><br /><sub>Analytics</sub></td>
    <td width="33%"><img src="../../docs/images/screenshots/tasks-en.png" alt="GEOFlow task management preview" /><br /><sub>Task Management</sub></td>
  </tr>
  <tr>
    <td width="33%"><img src="../../docs/images/screenshots/distribution-channel-en.png" alt="GEOFlow distribution channel preview" /><br /><sub>Distribution Channel</sub></td>
    <td width="33%"><img src="../../docs/images/screenshots/materials-en.png" alt="GEOFlow materials preview" /><br /><sub>Materials</sub></td>
    <td width="33%"><img src="../../docs/images/screenshots/ai-config-en.png" alt="GEOFlow AI model configuration preview" /><br /><sub>AI Model Configuration</sub></td>
  </tr>
  <tr>
    <td width="33%"><img src="../../docs/images/screenshots/site-settings-en.png" alt="GEOFlow site settings preview" /><br /><sub>Site Settings</sub></td>
    <td width="33%"></td>
    <td width="33%"></td>
  </tr>
</table>

These screens cover the admin dashboard, analytics, task scheduling, multi-site distribution, materials, model configuration, and site settings. More admin documentation lives under `../../docs/`.

---

## 🆕 New Version Highlights

GEOFlow 2.0 highlights include:

- **Dashboard as an operations hub**: keeps the three-step setup guide and groups entries by single-site operations, multi-site distribution, and companion skills.
- **Gemini and OpenAI-compatible providers are both first-class**: model setup covers OpenAI-style providers and native Gemini chat / embedding routes.
- **Knowledge bases support semantic chunk planning**: choose structured rule chunking, automatic strategy, or optional LLM semantic planning; the LLM plans boundaries while final chunks are rebuilt from the source text.
- **Standalone Analytics page**: system overview, content operations, task health, material health, distribution status, access logs, and AI crawler trends live under `/admin/analytics`.
- **Distribution Management is usable end to end**: GEOFlow Agent and WordPress REST channels, secrets, connection tests, target-site package downloads, static/rewrite modes, remote settings sync, queues, logs, remote editing, and remote deletion.
- **Publication scope is explicit**: tasks can publish locally and to channels, publish only to selected channels, or publish only to the local GEOFlow site. Local-only mode disables channel selection and never enters the remote distribution queue.
- **Target sites can run as static sites**: distribution can regenerate remote homepages, article pages, sitemap, TXT maps, `llms.txt`, images, and standalone CSS.
- **Materials and RAG are more complete**: knowledge chunks, vectorization status, title libraries, keyword libraries, image libraries, authors, and prompts form the task input layer.
- **Deployment and security are stronger**: production Docker uses Nginx + PHP-FPM, seeded admins are not overwritten, and Docker/Composer mirrors are configurable.
- **Localization coverage is complete for current admin keys**: GEOFlow 2.0 modules no longer fall back to raw translation keys or English copy.

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

For common cloud servers, you can use the reference deployment script to run host checks, prepare `.env.prod`, deploy containers, and run post-deployment health checks:

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
docker compose --env-file .env.prod -f docker-compose.prod.yml up -d app web queue scheduler reverb
```

- Frontend and admin both enter through `web` (Nginx)
- PHP is executed by `app` (php-fpm)
- **Default admin:** the production `init` service runs `db:seed` after migrations to create the default admin account; repeated runs do not overwrite an existing `admin` user.
- See `../../docs/deployment/DEPLOYMENT.md` for details

### Option 2: Local PHP stack

**Prerequisites:** PHP **8.2+** with `pdo_pgsql`, `redis`, and other typical Laravel extensions; local **PostgreSQL** and **Redis**; **Composer 2.x**.

```bash
git clone https://github.com/yaojingang/GEOFlow.git
cd GEOFlow
cp .env.example .env
# Edit .env: DB_* → Postgres, REDIS_* → Redis, QUEUE_CONNECTION=redis, etc.

composer install --no-interaction --prefer-dist
php artisan key:generate

php artisan migrate --force
php artisan db:seed --force    # optional: default admin, etc.
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
| PHP | **8.2+** (Docker image may use 8.4) |
| Extensions | Standard Laravel set; `pdo_pgsql` for Postgres; `redis` for queues |
| Composer | 2.x |
| Database | **PostgreSQL** (recommended: **pgvector**, same as Compose image) |
| Redis | Queues/cache (you can set `QUEUE_CONNECTION=sync` for minimal local-only tests—not for production) |

---

## Source deployment notes

```bash
chmod -R ug+rwx storage bootstrap/cache
```

**Default admin** (after `php artisan db:seed`, see `Database\Seeders\AdminUserSeeder`):

| Field | Value |
|-------|--------|
| Username | `GEOFLOW_ADMIN_USERNAME`, default `admin` |
| Password | Local/dev default `password`; in production set `GEOFLOW_ADMIN_PASSWORD`. If it is empty and the account does not exist yet, the seeder generates a one-time random password in the init / `db:seed` logs. |

The seeder only creates the account when the target username does not exist. Repeated runs never overwrite an existing username, email, or password.

### Admin login lockout and manual unlock

- Admin accounts are automatically locked (`status=locked`) after **5** consecutive failed login attempts.
- Locked accounts cannot sign in until manually unlocked by an administrator.
- Unlock command:

```bash
php artisan geoflow:admin-unlock <username>
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
| `init` | One-off bootstrap (`restart: "no"`) |
| `app` | `php artisan serve`, maps **`${APP_PORT:-18080}:8080`** |
| `queue` | `queue:work redis` |
| `scheduler` | `schedule:work` |
| `reverb` | WebSocket, maps **`${REVERB_EXPOSE_PORT:-18081}:8080`** |

Optional localhost-only DB/Redis host ports: see `DB_EXPOSE_PORT` and `REDIS_EXPOSE_PORT` in `docker-compose.yml`.

### `docker/entrypoint.sh` variables

| Variable | Default | Meaning |
|----------|---------|---------|
| `COMPOSER_ON_START` | `true` | Run `composer install` on container start |
| `AUTO_MIGRATE` | `true` | Run `php artisan migrate --force` on each start |
| `AUTO_INIT_ONCE` | `true` on `init` only | First-time `migrate` + `db:seed` on empty DB |
| `AUTO_GENERATE_APP_KEY` | enabled in `init` | Generate `APP_KEY` when missing |
| `AUTO_SEED` | `false` | If `true`, runs **`db:seed` every start** (use with care) |

`./storage` and `./.env` are mounted; application code lives in the image. For production, use the new **`docker-compose.prod.yml`** stack (`Nginx + php-fpm`) and see `../../docs/deployment/DEPLOYMENT.md`.

**Upgrade:** `git pull` → `docker compose build` → `docker compose up -d`.

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

GEOFlow is licensed under the [Apache License 2.0](../../LICENSE). It allows personal and commercial use, modification, redistribution, and private deployment, as long as the license, copyright notices, modification notices, patent terms, and warranty disclaimers are respected.

---

## ⭐ Star history

[![Star History Chart](https://api.star-history.com/svg?repos=yaojingang/GEOFlow&type=Date)](https://star-history.com/#yaojingang/GEOFlow&Date)
