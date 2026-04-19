# GEOFlow

> Languages: [简体中文](README.md) | [English](README_en.md) | [日本語](README_ja.md) | [Español](README_es.md) | [Русский](README_ru.md)

> An open-source content production system designed for GEO / SEO content operations. It chains together model configuration, material management, task scheduling, draft review, and front-end publishing into a complete pipeline — ideal for building automated content sites or internal content operations dashboards.

[![PHP](https://img.shields.io/badge/PHP-7.4%2B-blue)](https://www.php.net/)
[![PostgreSQL](https://img.shields.io/badge/Database-PostgreSQL-336791)](https://www.postgresql.org/)
[![Docker](https://img.shields.io/badge/Docker-Compose-blue)](https://docs.docker.com/compose/)
[![License](https://img.shields.io/badge/License-Apache%202.0-blue.svg)](LICENSE)

Released under the Apache License 2.0.

---

## ✨ What You Can Do With It

| Feature | Description |
|---------|-------------|
| 🤖 Multi-Model Content Generation | Compatible with OpenAI-style APIs, supports multiple AI providers |
| 📦 Batch Task Execution | Task creation, scheduled dispatching, queue execution, and failure retry |
| 🗂 Unified Material Management | Centralized management of title libraries, keyword libraries, image libraries, knowledge bases, and prompt templates |
| 📋 Review & Publishing Workflow | Three-stage workflow: Draft → Review → Publish, with optional auto-publishing |
| 🔍 Search-Oriented Display Optimization | Article SEO metadata, Open Graph, and structured data |
| 🎨 Frontend Theme Preview | Preview-first theme packages, theme preview routes, and admin activation |
| 🐳 Ready to Deploy | Supports Docker Compose — works on both local machines and servers |
| 🗄 PostgreSQL Runtime | Built on PostgreSQL by default, suitable for stable operation and concurrent writes |

---

## 🖼 UI Preview

<p>
  <img src="docs/images/screenshots/home.png" alt="GEOFlow Dashboard Preview" width="48%" />
  <img src="docs/images/screenshots/task-management.png" alt="GEOFlow Task Management Preview" width="48%" />
</p>
<p>
  <img src="docs/images/screenshots/article-management.png" alt="GEOFlow Materials Preview" width="48%" />
  <img src="docs/images/screenshots/ai-config.png" alt="GEOFlow AI Configurator Preview" width="48%" />
</p>

These four pages cover the main workflows: site homepage, task scheduling, article pipeline, and model configuration. Additional admin pages are documented in `docs/`.

---

## 🏗 Runtime Structure

```
Admin Dashboard
    ↓
Task Scheduler / Queue
    ↓
Worker executes AI generation
    ↓
Draft / Review / Publish
    ↓
Front-end article & SEO page output
```

---

## 🧱 System Architecture

| Layer | Description |
|-------|-------------|
| Web / Admin | Front-end article site and admin dashboard — handles content browsing, material management, task management, and configuration |
| API / CLI | `/api/v1` provides machine-readable interfaces; `bin/geoflow` provides local CLI capabilities for batch tasks and automation |
| Scheduler / Worker | Scheduler scans tasks and enqueues them; Worker handles actual model calls to generate content |
| Domain Services | Task, article, queue, AI, and retrieval services in `includes/` implement core business rules |
| Persistence | PostgreSQL serves as the runtime database, storing tasks, articles, materials, review states, and system configuration |

Core Pipeline:

1. Configure models, prompts, and material libraries in the admin panel
2. Create a task and enter scheduling
3. Scheduler writes to the job queue
4. Worker calls AI to generate content
5. Articles enter the Draft → Review → Publish pipeline
6. Front-end renders articles and SEO pages

---

## 🚀 Quick Start

### Option 1: Docker (Recommended)

```bash
# 1. Clone the repository
git clone https://github.com/yaojingang/GEOFlow.git
cd GEOFlow

# 2. Copy the environment variable file
cp .env.example .env

# 3. Edit .env and set required parameters (see configuration below)
vi .env

# 4. Start Web, PostgreSQL, Scheduler, and Worker
docker compose --profile scheduler up -d --build

# Access the front-end
open http://localhost:18080

# Access the admin panel
open http://localhost:18080/geo_admin/
```

### Option 2: Local PHP Server

**Prerequisites:** PHP 7.4+, with `pdo_pgsql` and `curl` extensions enabled, and a local PostgreSQL instance

```bash
# 1. Clone the repository
git clone https://github.com/yaojingang/GEOFlow.git
cd GEOFlow

# 2. Configure database environment variables
export DB_DRIVER=pgsql
export DB_HOST=127.0.0.1
export DB_PORT=5432
export DB_NAME=geo_system
export DB_USER=geo_user
export DB_PASSWORD=geo_password

# 3. Start the development server
php -S localhost:8080 router.php

# Access the admin panel
open http://localhost:8080/geo_admin/
```

## 🤝 Companion Skill

This project comes with two public companion skills:

- Skill repository: [yaojingang/yao-geo-skills](https://github.com/yaojingang/yao-geo-skills)
- CLI operations: `skills/geoflow-cli-ops`
- Frontend template cloning: `skills/geoflow-template`

Use cases:

- Create and manage tasks via local CLI
- Upload article drafts
- Review and publish articles
- Check task and job status
- Generate GEOFlow-compatible theme packages from reference URLs
- Output `tokens.json / mapping.json` for preview-first frontend iterations

Related docs:

- [Frontend Theme Preview and Activation](docs/project/THEME_PREVIEW_en.md)

---

## ⚙️ Environment Variable Configuration

Copy `.env.example` to `.env` and modify as needed:

```dotenv
# Web service exposed port (default: 18080)
HOST_PORT=18080

# Site access URL (must match HOST_PORT)
SITE_URL=http://localhost:18080

# Application secret key (use a random string of 32+ characters)
APP_SECRET_KEY=replace-with-a-long-random-secret

# Cron scheduling interval (seconds, default: 60)
CRON_INTERVAL=60

# Timezone
TZ=Asia/Shanghai
```

---

## 📖 Getting Started Workflow

1. Log into the admin panel  
Visit `/geo_admin/` and sign in with the admin account. Default credentials: `admin / admin888`. You can change these after logging in.

2. Configure AI Models  
Go to "AI Configuration Center → AI Model Management" to add models — fill in the API URL, model ID, and API key. Use the **Quick Provider Fill** buttons to pre-fill settings for popular providers:

   | Provider | API Base URL | Model ID |
   |----------|-------------|---------|
   | **MiniMax** | `https://api.minimax.io` | `MiniMax-M2.7` / `MiniMax-M2.7-highspeed` |
   | OpenAI | `https://api.openai.com` | `gpt-4o` |
   | DeepSeek | `https://api.deepseek.com` | `deepseek-chat` |
   | Zhipu GLM | `https://open.bigmodel.cn/api/paas/v4` | `glm-4.6` / `glm-5` |
   | Volcengine Ark | `https://ark.cn-beijing.volces.com/api/v3` | inference endpoint ID such as `ep-xxxx` |

   You can enter either a provider base URL or a full endpoint URL. Chat models default to `/v1/chat/completions`, while embedding models default to `/v1/embeddings`. GEOFlow also auto-detects Zhipu `/api/paas/v4` and Volcengine Ark `/api/v3` style bases and expands them to the correct capability-specific path. Dedicated rerank model wiring is not available yet.

3. Prepare Materials  
Create title libraries, image libraries, knowledge bases, and prompt templates.

4. Create a Task  
In "Task Management", select a title library, model, prompt, image library, and publishing rules.

5. Start Generation  
The task enters the scheduling and worker execution pipeline. Articles are generated to draft or published directly based on configuration.

> After initial deployment, it is strongly recommended to change the admin password and `APP_SECRET_KEY` immediately.

---

## 🔄 Content Generation Flow

```
Configure models / materials / prompts
        ↓
Create task
        ↓
Scheduler enqueues
        ↓
Worker calls AI to generate content
        ↓
Optional image insertion / SEO metadata
        ↓
Draft / Review / Publish
        ↓
Front-end display
```

---

## 📁 Directory Structure

```text
GEOFlow/
├── index.php                     Front-end homepage — article list and site aggregation display
├── article.php                   Article detail page — full text, SEO, and related articles
├── category.php                  Category page — articles aggregated by category
├── archive.php                   Archive page — browse content by date
├── router.php                    Local dev router for `php -S`
├── docker-compose.yml            Dev environment orchestration — web / postgres / scheduler / worker
├── docker-compose.prod.yml       Production environment orchestration template
├── start.sh                      Local quick start script
├── .env.example                  Environment variable template
│
├── admin/                        Admin management system
│   ├── dashboard.php             Admin dashboard and statistics overview
│   ├── tasks.php                 Task management — view task status, retry, execution
│   ├── task-create.php           Create task — configure title library, model, prompt, publishing rules
│   ├── articles.php              Article list — view drafts, published articles, workflow status
│   ├── articles-review.php       Review center — process pending articles
│   ├── materials.php             Material management entry — title, image, knowledge libraries
│   ├── ai-models.php             AI model configuration — API URL, ID, and key
│   ├── ai-prompts.php            Prompt template management
│   ├── site-settings.php         Site settings — site name, SEO, front-end configuration
│   └── includes/                 Admin shared templates, navigation, and page scaffolding
│
├── api/v1/                       Machine-facing API layer
│   └── index.php                 API single entry point — routing, auth, and response output
│
├── assets/                       Front-end static resources
│   ├── css/                      Front/admin stylesheets
│   ├── js/                       Front/admin interaction scripts
│   └── images/                   Default images and static icons
│
├── bin/                          CLI and background scripts
│   ├── geoflow                   Local CLI — for skill and automation script invocation
│   ├── cron.php                  Scheduler — scans tasks and writes to queue
│   ├── worker.php                Persistent Worker — calls AI to generate content
│   ├── db_maintenance.php        Database maintenance tool
│   ├── migrate_sqlite_to_pg.php  Historical migration script
│   ├── api/                      API helper scripts (e.g., token creation)
│   └── git/                      Release sync and open-source check scripts
│
├── docker/                       Container images and startup helpers
│   ├── Dockerfile                Multi-stage image for Web / Scheduler / Worker
│   ├── entrypoint.sh             Web container startup entry
│   ├── scheduler.sh              Scheduler container startup entry
│   └── php.ini                   Container PHP configuration
│
├── docs/                         External documentation center
│   ├── deployment/               Installation and deployment docs
│   ├── project/                  API, CLI, structure, and dev docs
│   ├── 系统说明文档.md           System feature documentation
│   ├── AI_PROJECT_GUIDE.md       AI core module documentation
│   └── FAQ.md                    Frequently asked questions
│
├── includes/                     Core business logic and service layer
│   ├── config.php                Global config, constants, and runtime parameters
│   ├── db_support.php            Database driver and connection helpers
│   ├── database.php              Front-end and base data access wrapper
│   ├── database_admin.php        Admin schema init and default data bootstrap
│   ├── functions.php             Common functions, Markdown rendering, admin login helpers
│   ├── ai_engine.php             Task execution engine — chains title, content, images, and storage
│   ├── ai_service.php            Generic AI request wrapper
│   ├── job_queue_service.php     Queue claim / complete / fail / retry logic
│   ├── task_service.php          Task base service
│   ├── task_lifecycle_service.php Task start, stop, enqueue, and lifecycle actions
│   ├── article_service.php       Article create, update, review, publish service
│   ├── api_auth.php              API Bearer authentication
│   ├── api_token_service.php     API token generation and verification
│   └── catalog_service.php       CLI/API base resource dictionary output
│
└── data/                         Runtime data directory placeholder; no real database or business data in public repo
```

Directory Conventions:

- Front-end entry files stay in the root for easy deployment and route mapping
- `admin/` holds admin pages and admin action entry points
- `api/v1/` holds the official external API
- `bin/` holds CLI, scheduling, and maintenance scripts
- `includes/` holds core business logic and the service layer
- `docs/` only retains externally useful documentation

---

## 🐳 Docker Components

| Service | Description | Default Start |
|---------|-------------|---------------|
| `web` | Provides front-end and admin HTTP access | ✅ |
| `postgres` | PostgreSQL database | ✅ |
| `scheduler` | Task scheduler | `--profile scheduler` |
| `worker` | Persistent generation worker | `--profile scheduler` |

```bash
# Start Web only (without scheduling)
docker compose up -d

# Start full services (with Scheduler and Worker)
docker compose --profile scheduler up -d

# View full service logs
docker compose logs -f
```

---

## 🛡 Security Notes

- All database operations use **PDO prepared statements** to prevent SQL injection
- All form submissions verify **CSRF Tokens**
- Output content is escaped with **HTMLSpecialChars** to prevent XSS
- Admin passwords are stored using **bcrypt** encryption
- Configurable security headers (X-Frame-Options, X-Content-Type-Options, etc.)

> ⚠️ Before production deployment, make sure to change the `APP_SECRET_KEY` in `.env` and update the default admin password.
>
> To report security issues, see [SECURITY.md](SECURITY.md).

---

## 📚 Documentation & Extensions

Detailed documentation is available in the [`docs/`](docs/) directory:

- [System Documentation](docs/System_Documentation_en.md) - Complete feature documentation
- [AI Development Guide](docs/AI_PROJECT_GUIDE_en.md) - Core classes and architecture
- [Local Environment Setup](docs/Local_Environment_Setup_Guide_en.md) - Development environment setup
- [Deployment Docs](docs/deployment/DEPLOYMENT_en.md) - Server deployment steps
- [Companion Skill Repository](https://github.com/yaojingang/yao-geo-skills) - `geoflow-cli-ops`

---

## 📌 Current Open-Source Repository Scope

- Provides a runnable public source code version
- Does not include production databases, uploaded files, or real API keys
- Suitable as a base for secondary development or for building your own GEO content site
