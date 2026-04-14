# Руководство по развертыванию GEOFlow

> Languages: [简体中文](DEPLOYMENT.md) | [English](DEPLOYMENT_en.md) | [日本語](DEPLOYMENT_ja.md) | [Español](DEPLOYMENT_es.md) | [Русский](DEPLOYMENT_ru.md)

Обновлено: 2026-04-15

## 1. Рекомендуемый путь

Публичную версию рекомендуется разворачивать через Docker Compose.

Почему:

- это самый короткий путь
- он соответствует реальной структуре публичного репозитория
- включает `web + postgres + scheduler + worker`
- не тянет за собой устаревшие инструкции по SQLite / Caddy / PHP-FPM

Если нужен прямой старт, начните с:

- `docs/deployment/DOCKER_DEPLOYMENT.md`

## 2. Минимальные требования

- Docker 24+
- Docker Compose v2
- минимум 2 CPU / 2 GB RAM / 20 GB disk
- Linux-сервер с доступом в интернет

## 3. Базовый сценарий

```bash
git clone https://github.com/yaojingang/GEOFlow.git
cd GEOFlow

cp .env.example .env.prod
vi .env.prod

docker compose -f docker-compose.prod.yml --env-file .env.prod up -d --build
```

После запуска:

- Front-end: `https://your-domain.com/`
- Admin: `https://your-domain.com/geo_admin/`

## 4. Обязательная конфигурация

В `.env.prod` как минимум проверьте:

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

## 5. Сервисы runtime

- `web`: фронтенд и админка
- `postgres`: рабочая база данных
- `scheduler`: сканирование задач, очередь и автопубликация
- `worker`: выполнение модели и генерация контента

## 6. Безопасность

- сразу смените пароль администратора
- замените `APP_SECRET_KEY` для production
- не коммитьте `.env.prod`
- не публикуйте PostgreSQL наружу по умолчанию
- на уровне reverse proxy открывайте только HTTP/HTTPS

## 7. Обновление и откат

Обновление:

```bash
git pull origin main
docker compose -f docker-compose.prod.yml --env-file .env.prod up -d --build
```

Откат:

- вернитесь на нужный commit или tag
- снова выполните `docker compose ... up -d --build`

## 8. Область действия

Этот документ описывает только тот путь развертывания, который соответствует текущему публичному репозиторию.

Старые инструкции по SQLite, локальным скриптам и Caddy / PHP-FPM не являются рекомендуемым путём для публичной версии.
