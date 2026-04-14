# Guía de despliegue de GEOFlow

> Languages: [简体中文](DEPLOYMENT.md) | [English](DEPLOYMENT_en.md) | [日本語](DEPLOYMENT_ja.md) | [Español](DEPLOYMENT_es.md) | [Русский](DEPLOYMENT_ru.md)

Actualizado: 2026-04-15

## 1. Ruta recomendada

La versión pública debe desplegarse con Docker Compose.

Motivos:

- es la ruta más corta
- coincide con la arquitectura real del repositorio público
- incluye `web + postgres + scheduler + worker`
- evita instrucciones antiguas de SQLite / Caddy / PHP-FPM

Si quieres el camino más directo, empieza por:

- `docs/deployment/DOCKER_DEPLOYMENT.md`

## 2. Requisitos mínimos

- Docker 24+
- Docker Compose v2
- al menos 2 CPU / 2 GB RAM / 20 GB disco
- servidor Linux con acceso a Internet

## 3. Flujo básico

```bash
git clone https://github.com/yaojingang/GEOFlow.git
cd GEOFlow

cp .env.example .env.prod
vi .env.prod

docker compose -f docker-compose.prod.yml --env-file .env.prod up -d --build
```

Después del despliegue:

- Front-end: `https://your-domain.com/`
- Admin: `https://your-domain.com/geo_admin/`

## 4. Configuración obligatoria

En `.env.prod` confirma al menos:

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

## 5. Servicios en ejecución

- `web`: front-end y panel admin
- `postgres`: base de datos runtime
- `scheduler`: escaneo de tareas, cola y autopublicación
- `worker`: ejecución del modelo y generación de contenido

## 6. Seguridad

- cambia la contraseña admin tras el primer despliegue
- reemplaza `APP_SECRET_KEY` en producción
- no subas `.env.prod` al repositorio
- no expongas PostgreSQL públicamente por defecto
- publica solo HTTP/HTTPS en la capa de reverse proxy

## 7. Actualización y rollback

Actualizar:

```bash
git pull origin main
docker compose -f docker-compose.prod.yml --env-file .env.prod up -d --build
```

Rollback:

- vuelve al commit o tag objetivo
- ejecuta de nuevo `docker compose ... up -d --build`

## 8. Alcance

Este documento solo cubre la ruta de despliegue que coincide con el repositorio público actual.

SQLite heredado, scripts locales y Caddy / PHP-FPM antiguos no son la ruta recomendada para la versión pública.
