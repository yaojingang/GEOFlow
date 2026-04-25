# GEOFlow

> Languages: [简体中文](../../README.md) | [English](README_en.md) | [日本語](README_ja.md) | [Español](README_es.md) | [Русский](README_ru.md)

> GEOFlow is an open-source intelligent content engineering system designed specifically for GEO (Generative Engine Optimization). It is one of the world's earliest data, content, and distribution infrastructures systematically designed around GEO workflows, connecting data assets, knowledge bases, material management, AI generation, review and publishing, front-end presentation, and future multi-channel distribution into one evolving pipeline.

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
| 🤖 Multi-model generation | OpenAI-style APIs, chat / embedding model types, provider URL adaptation, smart failover, and retry handling |
| 📦 Batch task execution | Task creation, generation limits, publishing cadence, queue execution, failure records, and task-scoped article filtering |
| 🗂 Unified asset management | Title libraries, keyword libraries, image libraries, author library, knowledge bases, and prompts |
| 🧠 Knowledge-base RAG | Upload documents, generate chunks, write vectors when an embedding model is configured, and retrieve relevant context during generation |
| 📋 Review & publishing workflow | Draft, review, and publish states, optional auto-publish, plus article filters by status, author, and task |
| 🔍 Search-oriented output | SEO metadata, Open Graph, structured data, and GFM Markdown rendering for headings, tables, lists, and images |
| 🎨 Front-end & themes | Default theme, theme packages, preview routes, admin theme switching, and a fixed GEOFlow admin brand |
| 🌍 Admin i18n | Admin UI supports Chinese, English, Japanese, Spanish, and Russian |
| 🔔 Version updates | Admin can check GitHub `version.json` and notify admins when a newer version is available |
| 🐳 Ready to deploy | **Docker Compose**: PostgreSQL (pgvector), Redis, app, queue, scheduler, Reverb |
| 🗄 PostgreSQL runtime | PostgreSQL by default; suitable for steady load and concurrent writes |

---

## 🖼 UI Preview

<p>
  <img src="../../docs/images/screenshots/dashboard-en.png" alt="GEOFlow dashboard preview" width="48%" />
  <img src="../../docs/images/screenshots/tasks-en.png" alt="GEOFlow task management preview" width="48%" />
</p>
<p>
  <img src="../../docs/images/screenshots/materials-en.png" alt="GEOFlow materials preview" width="48%" />
  <img src="../../docs/images/screenshots/ai-config-en.png" alt="GEOFlow AI configuration preview" width="48%" />
</p>

These screens cover the home page, task scheduling, article workflow, and model configuration. More admin documentation lives under `../../docs/` (add or replace screenshots locally if paths are missing).

---

## 🆕 New Version Highlights

New version highlights include:

- **Admin experience**: fixed GEOFlow admin brand, multi-language switching, admin account editing/deletion, first-login welcome letter, GitHub version update reminders, and a dashboard quick-start block.
- **Task pipeline**: fixed model and smart failover modes; generation and publishing are separated; task article links open task-scoped article lists.
- **Asset system**: knowledge bases, title libraries, keyword libraries, image libraries, and authors are all first-class admin entries.
- **RAG readiness**: knowledge bases are chunked after upload; embedding models enable vector writes and retrieval; missing embedding setup has explicit guidance.
- **Model setup**: clearer provider URL rules for OpenAI-style APIs, Zhipu, Volcengine Ark, and other non-`/v1` providers.
- **Frontend output**: article Markdown uses GFM rendering, including headings, tables, lists, and images; legacy `/uploads` image paths are normalized to `/storage/uploads`.
- **Deployment and security**: custom admin path via `ADMIN_BASE_PATH`; production should use Nginx + PHP-FPM; change the seeded admin password before going live.

---

## 🏗 Runtime Structure

```
Admin dashboard
    ↓
Task scheduler / queue (Horizon optional)
    ↓
Worker runs AI generation
    ↓
Draft / review / publish
    ↓
Front-end articles & SEO output
```

---

## 🧱 System Architecture

| Layer | Description |
|-------|-------------|
| Web / Admin | **Laravel** routes and controllers; article site and **Blade** admin; browsing, assets, tasks, settings |
| API | `routes/api.php` and related HTTP APIs (auth per project configuration) |
| Scheduler / Queue / Reverb | **Laravel Scheduler**; **`queue:work` / Horizon** consumers; **Reverb** for WebSockets when enabled |
| Domain & Jobs | `app/Services`, `app/Jobs`, `app/Http/Controllers`, etc.—business rules and GEO pipelines |
| Persistence | **PostgreSQL** (recommended: **pgvector** aligned with Compose) + **Redis** for queues/cache |

Core pipeline:

1. Configure models, prompts, and libraries in admin
2. Prepare knowledge, title, keyword, image, and author assets
3. Create tasks and hand off to scheduler/queue
4. Workers call models to generate body text and metadata
5. Articles move through draft, review, and publish
6. Front-end renders articles and SEO pages

---

## ⚡ Admin Quick Start

After signing in, use the dashboard quick-start path for the first validation cycle:

1. **Configure API**: add at least one working chat model; add an embedding model if you need knowledge-base RAG retrieval.
2. **Configure materials**: prepare knowledge bases, title libraries, keyword libraries, image libraries, and authors. Start from real, verifiable business information.
3. **Create a task**: choose libraries, materials, model, generation count, and publishing cadence. Start with draft or review flow before enabling full auto-publish.

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
- **Default admin:** production does **not** auto-run `db:seed`; run it once after migrations (command and credentials in `../../docs/deployment/DEPLOYMENT.md`, section *Default admin (first-time seeding)*).
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
php artisan queue:work redis --queue=geoflow,default --sleep=1 --tries=1 --timeout=300
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
| Username | `admin` |
| Password | `password` (**change immediately in production**) |

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

---

## 📄 License

GEOFlow is licensed under the [Apache License 2.0](../../LICENSE). It allows personal and commercial use, modification, redistribution, and private deployment, as long as the license, copyright notices, modification notices, patent terms, and warranty disclaimers are respected.

---

## ⭐ Star history

[![Star History Chart](https://api.star-history.com/svg?repos=yaojingang/GEOFlow&type=Date)](https://star-history.com/#yaojingang/GEOFlow&Date)
