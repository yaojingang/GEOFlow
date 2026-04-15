# GEOFlow

> Languages: [简体中文](README.md) | [English](README_en.md) | [日本語](README_ja.md) | [Español](README_es.md) | [Русский](README_ru.md)

> An open-source GEO / SEO content production system that connects model configuration, material management, task scheduling, draft review, and front-end publishing into one workflow.

[![PHP](https://img.shields.io/badge/PHP-7.4%2B-blue)](https://www.php.net/)
[![PostgreSQL](https://img.shields.io/badge/Database-PostgreSQL-336791)](https://www.postgresql.org/)
[![Docker](https://img.shields.io/badge/Docker-Compose-blue)](https://docs.docker.com/compose/)
[![License](https://img.shields.io/badge/License-Apache%202.0-blue.svg)](LICENSE)

Released under the Apache License 2.0.

## UI Preview

<p>
  <img src="docs/images/screenshots/home.png" alt="GEOFlow home preview" width="48%" />
  <img src="docs/images/screenshots/task-management.png" alt="GEOFlow task management preview" width="48%" />
</p>
<p>
  <img src="docs/images/screenshots/article-management.png" alt="GEOFlow article management preview" width="48%" />
  <img src="docs/images/screenshots/ai-config.png" alt="GEOFlow AI configurator preview" width="48%" />
</p>

These four screens cover the homepage, task scheduling, article workflow, and model configuration. Additional admin pages are documented in `docs/`.

## What GEOFlow Does

- Run AI-powered GEO / SEO content generation tasks
- Manage title libraries, prompts, images, and knowledge bases
- Process draft → review → publish workflows
- Expose API and CLI entry points for automation
- Render article pages with SEO metadata and structured output

## Quick Start

### Docker

```bash
git clone https://github.com/yaojingang/GEOFlow.git
cd GEOFlow
cp .env.example .env
docker compose --profile scheduler up -d --build
```

- Front-end: `http://localhost:18080`
- Admin: `http://localhost:18080/geo_admin/`

### Local PHP + PostgreSQL

```bash
git clone https://github.com/yaojingang/GEOFlow.git
cd GEOFlow

export DB_DRIVER=pgsql
export DB_HOST=127.0.0.1
export DB_PORT=5432
export DB_NAME=geo_system
export DB_USER=geo_user
export DB_PASSWORD=geo_password

php -S localhost:8080 router.php
```

## Default Admin Login

- Username: `admin`
- Password: `admin888`

Change the admin password and `APP_SECRET_KEY` immediately after the first login.

## Core Runtime

```text
Admin UI
  ↓
Scheduler / Queue
  ↓
Worker executes AI generation
  ↓
Draft / Review / Publish
  ↓
Front-end article output
```

## Main Directories

- `admin/` admin UI and management pages
- `api/v1/` machine-facing API entry
- `bin/` CLI, scheduler, worker, and maintenance scripts
- `docker/` image and container startup files
- `docs/` public documentation
- `includes/` core services and business logic

## Companion Skill

- Skill repository: [yaojingang/yao-geo-skills](https://github.com/yaojingang/yao-geo-skills)
- Skill path: `skills/geoflow-cli-ops`

## Documentation

- [Docs index](docs/README_en.md)
- [FAQ](docs/FAQ_en.md)
- [Deployment](docs/deployment/DEPLOYMENT_en.md)
- [CLI guide](docs/project/GEOFLOW_CLI_en.md)

## Scope of the Public Repository

- Includes source code, configuration templates, and public docs
- Does not include production databases, uploaded files, or real API keys
- Intended for self-hosted deployment and secondary development
