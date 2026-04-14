# GEOFlow

> Languages: [简体中文](README.md) | [English](README_en.md) | [日本語](README_ja.md) | [Español](README_es.md) | [Русский](README_ru.md)

> Открытая система генерации контента для GEO / SEO. Она объединяет настройку моделей, управление материалами, выполнение задач, ревью и публикацию в единый рабочий поток.

[![PHP](https://img.shields.io/badge/PHP-7.4%2B-blue)](https://www.php.net/)
[![PostgreSQL](https://img.shields.io/badge/Database-PostgreSQL-336791)](https://www.postgresql.org/)
[![Docker](https://img.shields.io/badge/Docker-Compose-blue)](https://docs.docker.com/compose/)
[![License](https://img.shields.io/badge/License-Apache%202.0-blue.svg)](LICENSE)

Released under the Apache License 2.0.

## Что умеет GEOFlow

- Запускать AI-задачи по генерации GEO / SEO контента
- Управлять библиотеками заголовков, промптов, изображений и знаний
- Поддерживать цепочку черновик → ревью → публикация
- Предоставлять API и CLI для автоматизации
- Выводить статьи с SEO-метаданными

## Быстрый старт

### Docker

```bash
git clone https://github.com/yaojingang/GEOFlow.git
cd GEOFlow
cp .env.example .env
docker compose --profile scheduler up -d --build
```

- Front-end: `http://localhost:18080`
- Admin: `http://localhost:18080/geo_admin/`

### Локальный PHP + PostgreSQL

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

## Начальные учетные данные администратора

- Логин: `admin`
- Пароль: `admin888`

После первого входа сразу смените пароль администратора и `APP_SECRET_KEY`.

## Исполняемая схема

```text
Панель администратора
  ↓
Планировщик / очередь
  ↓
Worker выполняет AI-генерацию
  ↓
Черновик / ревью / публикация
  ↓
Вывод на фронтенде
```

## Основные каталоги

- `admin/` административный интерфейс
- `api/v1/` входная точка API
- `bin/` CLI, scheduler, worker и служебные скрипты
- `docker/` контейнерная конфигурация
- `docs/` публичная документация
- `includes/` ядро сервисов и бизнес-логики

## Сопутствующий Skill

- Репозиторий: [yaojingang/yao-geo-skills](https://github.com/yaojingang/yao-geo-skills)
- Путь: `skills/geoflow-cli-ops`

## Документация

- [Docs index](docs/README_ru.md)
- [FAQ](docs/FAQ_ru.md)
- [Deployment](docs/deployment/DEPLOYMENT_ru.md)
- [CLI guide](docs/project/GEOFLOW_CLI_ru.md)

## Что входит в публичный репозиторий

- Исходный код, шаблоны конфигурации и публичная документация
- Без production-базы, загруженных файлов и реальных API-ключей
- Подходит для self-hosted развертывания и доработки под свои задачи
