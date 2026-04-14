# GEOFlow Deployment Guide

> Languages: [简体中文](DEPLOYMENT.md) | [English](DEPLOYMENT_en.md) | [日本語](DEPLOYMENT_ja.md) | [Español](DEPLOYMENT_es.md) | [Русский](DEPLOYMENT_ru.md)

Updated: 2026-04-15

## 1. Recommended Path

The public version should be deployed with Docker Compose.

Why:

- shortest deployment path
- matches the repository runtime shape
- includes `web + postgres + scheduler + worker`
- avoids stale SQLite / Caddy / PHP-FPM instructions

If you want the direct path, start with:

- `docs/deployment/DOCKER_DEPLOYMENT.md`

## 2. Minimum Requirements

- Docker 24+
- Docker Compose v2
- at least 2 CPU / 2 GB RAM / 20 GB disk
- a Linux host with internet access

## 3. Basic Deployment Flow

```bash
git clone https://github.com/yaojingang/GEOFlow.git
cd GEOFlow

cp .env.example .env.prod
vi .env.prod

docker compose -f docker-compose.prod.yml --env-file .env.prod up -d --build
```

After deployment:

- Front-end: `https://your-domain.com/`
- Admin: `https://your-domain.com/geo_admin/`

## 4. Required Configuration

At minimum, confirm these values in `.env.prod`:

```env
SITE_URL=https://your-domain.com
APP_SECRET_KEY=replace-with-a-long-random-secret

DB_DRIVER=pgsql
DB_HOST=postgres
DB_PORT=5432
DB_NAME=geo_system
DB_USER=geo_user
DB_PASSWORD=change-this-password

HOST_PORT=18080
CRON_INTERVAL=60
TZ=Asia/Shanghai
REQUIRE_STRONG_APP_SECRET=true
```

## 5. Runtime Services

- `web`: front-end and admin HTTP service
- `postgres`: runtime database
- `scheduler`: task scanning, enqueueing, and auto-publish
- `worker`: model execution and content generation

## 6. Security Notes

- change the admin password right after first deployment
- replace `APP_SECRET_KEY` in production
- do not commit `.env.prod`
- do not expose PostgreSQL publicly by default
- only expose HTTP/HTTPS at the reverse proxy layer

## 7. Update and Rollback

Update:

```bash
git pull origin main
docker compose -f docker-compose.prod.yml --env-file .env.prod up -d --build
```

Rollback:

- checkout the target commit or tag
- rerun the same `docker compose ... up -d --build`

## 8. Scope

This document only describes the deployment path that matches the current public repository.

Legacy SQLite, local script deployments, and old Caddy / PHP-FPM instructions are not the recommended path for the public version.
