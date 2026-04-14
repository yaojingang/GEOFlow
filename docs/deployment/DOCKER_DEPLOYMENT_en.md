# Docker Deployment Documentation

Updated: 2026-04-14

## 1. Scope

This document applies to the Docker Compose deployment method for the current public repository.

- `docker-compose.yml`
  - Development environment
  - Mounts source code for local debugging
- `docker-compose.prod.yml`
  - Production environment
  - Uses code within the image, does not mount source code
  - Includes `web + postgres + scheduler + worker` by default

## 2. Service Structure

- `web`
  - Provides front-end and admin HTTP access
- `postgres`
  - PostgreSQL runtime database
  - Production orchestration does not expose port to host by default
- `scheduler`
  - Polls and executes `bin/cron.php`
- `worker`
  - Persistent consumer of the task queue, calls AI to generate content

## 3. Environment Variables

Copy a production configuration from the template:

```bash
cp .env.example .env.prod
```

Modify at minimum the following variables:

```env
HOST_PORT=18080
SITE_URL=https://your-domain.com
APP_SECRET_KEY=replace-with-a-long-random-secret

DB_DRIVER=pgsql
DB_HOST=postgres
DB_PORT=5432
DB_NAME=geo_system
DB_USER=geo_user
DB_PASSWORD=change-this-password

CRON_INTERVAL=60
TZ=Asia/Shanghai
REQUIRE_STRONG_APP_SECRET=true
```

Notes:

- `APP_SECRET_KEY` must be securely stored long-term
- `DB_HOST=postgres` means connecting to the PostgreSQL service within Docker Compose
- To connect to the database from the host machine, explicitly expose the PostgreSQL port in a Compose override file — not recommended to expose by default

## 4. Starting Production Environment

```bash
docker compose -f docker-compose.prod.yml --env-file .env.prod up -d --build
```

Check status:

```bash
docker compose -f docker-compose.prod.yml --env-file .env.prod ps
```

View logs:

```bash
docker compose -f docker-compose.prod.yml --env-file .env.prod logs -f web
docker compose -f docker-compose.prod.yml --env-file .env.prod logs -f scheduler
docker compose -f docker-compose.prod.yml --env-file .env.prod logs -f worker
docker compose -f docker-compose.prod.yml --env-file .env.prod logs -f postgres
```

## 5. Data Persistence

Production environment uses the following named volumes:

- `pgdata`
- `geo_data`
- `geo_uploads`
- `geo_logs`

Corresponding critical data:

- `pgdata`
  - PostgreSQL data files
- `geo_uploads`
  - Image library and knowledge base uploaded files
- `geo_logs`
  - Runtime logs
- `geo_data`
  - Runtime auxiliary data

## 6. Updating Deployment

After pulling new code:

```bash
docker compose -f docker-compose.prod.yml --env-file .env.prod up -d --build
```

Restart services only:

```bash
docker compose -f docker-compose.prod.yml --env-file .env.prod restart
```

## 7. Stopping & Uninstalling

Stop containers:

```bash
docker compose -f docker-compose.prod.yml --env-file .env.prod down
```

Stop and delete volumes:

```bash
docker compose -f docker-compose.prod.yml --env-file .env.prod down -v
```

`down -v` will delete PostgreSQL data, uploaded files, and log volumes.

## 8. Current Constraints

- The current public version uses PostgreSQL as the runtime database
- Production orchestration does not expose PostgreSQL to the host network by default
- `scheduler` and `worker` start by default with the production environment
- AI model key encryption depends on `APP_SECRET_KEY`
