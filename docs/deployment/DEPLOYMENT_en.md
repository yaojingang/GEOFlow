# GEOFlow Server Deployment Documentation

> Languages: [简体中文](DEPLOYMENT.md) | [English](DEPLOYMENT_en.md) | [日本語](DEPLOYMENT_ja.md) | [Español](DEPLOYMENT_es.md) | [Русский](DEPLOYMENT_ru.md)

Updated: 2026-04-14

## 1. Recommended Approach

The current public version recommends deployment using Docker Compose.

Reasons:

- Shortest deployment path
- Includes `web + postgres + scheduler + worker` by default
- Consistent with the repository's current runtime structure
- Avoids confusion from legacy SQLite / Caddy / PHP-FPM documentation

If you just want to go live quickly, read this first:

- `docs/deployment/DOCKER_DEPLOYMENT_en.md`

## 2. Minimum Requirements

- Docker 24+
- Docker Compose v2
- At least 2 CPU cores / 2 GB RAM / 20 GB disk
- A Linux server with internet access

## 3. Basic Deployment Process

```bash
git clone https://github.com/yaojingang/GEOFlow.git
cd GEOFlow

cp .env.example .env.prod
vi .env.prod

docker compose -f docker-compose.prod.yml --env-file .env.prod up -d --build
```

After deployment:

- Front-end: `https://your-domain.com/`
- Admin panel: `https://your-domain.com/geo_admin/`

## 4. Required Configuration

In `.env.prod`, confirm at minimum:

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

## 5. Runtime Structure

- `web`
  - Serves front-end and admin pages
- `postgres`
  - Provides the runtime database
- `scheduler`
  - Handles task scanning, enqueuing, and auto-publishing
- `worker`
  - Handles actual model calls to generate content

## 6. Security Recommendations

- Change the admin password immediately after first deployment
- Replace `APP_SECRET_KEY` for production use
- Do not commit `.env.prod` to the repository
- Do not expose the PostgreSQL port externally by default
- The reverse proxy layer should only expose HTTP/HTTPS

## 7. Updates & Rollbacks

Update:

```bash
git pull origin main
docker compose -f docker-compose.prod.yml --env-file .env.prod up -d --build
```

Rollback:

- Check out the target commit/tag
- Re-run the same `docker compose ... up -d --build` command

## 8. Notes

This file only documents the deployment method corresponding to the current public repository.

Historical SQLite, local script-based deployment, and legacy Caddy/PHP-FPM approaches are no longer recommended for the current public version.
