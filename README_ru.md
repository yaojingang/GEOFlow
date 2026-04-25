# GEOFlow

> Languages: [简体中文](README.md) | [English](README_en.md) | [日本語](README_ja.md) | [Español](README_es.md) | [Русский](README_ru.md)

> Открытая система производства контента для GEO / SEO. **Этот репозиторий — редакция на Laravel 12**: модели и материалы, планирование задач, очереди и мониторинг, ревью и публикация в одном конвейере.

[![PHP](https://img.shields.io/badge/PHP-8.2%2B-blue)](https://www.php.net/)
[![Laravel](https://img.shields.io/badge/Laravel-12-FF2D20)](https://laravel.com/)
[![PostgreSQL](https://img.shields.io/badge/Database-PostgreSQL-336791)](https://www.postgresql.org/)
[![Docker](https://img.shields.io/badge/Docker-Compose-blue)](https://docs.docker.com/compose/)
[![License](https://img.shields.io/badge/License-MIT-blue.svg)](https://opensource.org/licenses/MIT)
[![GitHub stars](https://img.shields.io/github/stars/yaojingang/GEOFlow?style=social)](https://github.com/yaojingang/GEOFlow/stargazers)
[![GitHub forks](https://img.shields.io/github/forks/yaojingang/GEOFlow?style=social)](https://github.com/yaojingang/GEOFlow/network/members)
[![GitHub issues](https://img.shields.io/github/issues/yaojingang/GEOFlow)](https://github.com/yaojingang/GEOFlow/issues)

Каркас фреймворка распространяется под [MIT License](https://opensource.org/licenses/MIT) (см. `composer.json`). Лицензия прикладного кода — по файлам в корне репозитория.

---

## ✨ Возможности

| Возможность | Описание |
|-------------|----------|
| 🤖 Несколько моделей | API в стиле OpenAI, разные провайдеры |
| 📦 Пакетные задачи | Создание, расписание, очередь, повторы; опционально **Horizon** |
| 🗂 Единые материалы | Заголовки, ключевые слова, изображения, базы знаний, промпты |
| 📋 Ревью и публикация | Черновик → ревью → публикация; авто-публикация по настройке |
| 🔍 Поиск и SEO | Мета-теги, Open Graph, структурированные данные |
| 🎨 Фронт и темы | Статьи и настройки сайта в админке |
| ⚡ Реалтайм | **Laravel Reverb** (включается в `.env`) |
| 🐳 Готовый Docker | **Compose**: Postgres (pgvector), Redis, приложение, очередь, scheduler, Reverb |
| 🗄 PostgreSQL | Основная СУБД для стабильной нагрузки |

---

## 🖼 Предпросмотр UI

<p>
  <img src="docs/images/screenshots/dashboard-en.png" alt="Панель GEOFlow" width="48%" />
  <img src="docs/images/screenshots/tasks-en.png" alt="Задачи GEOFlow" width="48%" />
</p>
<p>
  <img src="docs/images/screenshots/materials-en.png" alt="Материалы GEOFlow" width="48%" />
  <img src="docs/images/screenshots/ai-config-en.png" alt="Настройка ИИ GEOFlow" width="48%" />
</p>

Охватывает главную, задачи, статьи и модели. При отсутствии файлов добавьте скриншоты локально.

---

## 🏗 Схема выполнения

```
Админка
  ↓
Планировщик / очередь (опционально Horizon)
  ↓
Worker — вызов ИИ
  ↓
Черновик / ревью / публикация
  ↓
Фронтенд
```

---

## 🧱 Архитектура

| Уровень | Описание |
|---------|----------|
| Web / Admin | **Laravel**: маршруты, контроллеры, **Blade** для админки и статей |
| API | `routes/api.php` и др. (аутентификация по настройкам проекта) |
| Scheduler / Queue / Reverb | **Scheduler**, **`queue:work` / Horizon**, при необходимости **Reverb** |
| Домен и Jobs | `app/Services`, `app/Jobs`, `app/Http/Controllers` и т.д. |
| Хранение | **PostgreSQL** (рекомендуется **pgvector**) + **Redis** |

Основной поток: настройка в админке → задачи в очередь → воркеры генерируют контент → черновик / ревью / публикация → вывод SEO-страниц.

---

## 🎯 Сценарии использования и ожидаемая польза

GEOFlow подходит для таких практических сценариев:

- **Независимый GEO-сайт**  
  Для публикации FAQ, продуктовых материалов, кейсов и брендового знания на отдельном сайте. Цель — повысить видимость в AI-поиске и эффективность контент-операций, а не штамповать низкокачественные страницы.
- **GEO-подканал внутри основного сайта**  
  Для запуска отдельного новостного, экспертного или knowledge-раздела внутри уже существующего сайта. Цель — лучше структурировать контент и упростить его поддержку.
- **Независимый GEO-источник**  
  Для системного накопления гайдов, рейтингов, аналитики и разборов по конкретной теме или отрасли. Цель — строить доверенный контентный актив, а не загрязнять интернет шумом.
- **Внутренняя GEO CMS / операционная система контента**  
  Для использования GEOFlow как внутреннего backend-инструмента для моделей, материалов, знаний, промптов, ревью и публикации. Цель — повысить эффективность команды.
- **Мультисайтовое или мультиканальное GEO-развертывание**  
  Для управления несколькими сайтами, каналами или темами по единому процессу. Цель — стандартизация и повторяемость работы.
- **Автоматизированное управление источниками и дистрибуция**  
  Для инженерного подхода к базам знаний, тематическим обновлениям и контролируемой дистрибуции. Цель — чтобы полезная информация оставалась структурированной, проверяемой и находимой.

Польза от системы возможна только при наличии **реальной, качественной и поддерживаемой базы знаний**.  
GEOFlow не предназначен для создания ложного контента или массового информационного шума. Его задача — повышать эффективность AI-маркетинга и GEO-операций за счет работы с доверенным контентом.

---

## 🧭 Рекомендуемые способы развертывания и использования

- **Как самостоятельный GEO-сайт**  
  Разворачивайте полный frontend и admin и используйте систему как отдельную контентную площадку.
- **Как GEO-подканал существующего сайта**  
  Используйте GEOFlow в поддомене, каталоге или отдельном канале без полной переделки основного сайта.
- **Как GEO-сайт-источник**  
  Сначала стройте базу знаний, затем подключайте задачи и автоматизацию для стабильных обновлений.
- **Как внутреннюю систему управления GEO-контентом**  
  Делайте акцент на админке, моделях, библиотеках материалов, API и редакционных процессах.
- **Как мультисайтовую платформу**  
  Используйте общие процессы и шаблоны для нескольких брендов, тематик или экспериментальных площадок.
- **Как систему автоматизированного управления источниками**  
  Рассматривайте библиотеки заголовков, изображений и промптов, а также базу знаний, как долгосрочную инфраструктуру.

Рекомендуемый порядок работы:

1. Сначала определить реальную бизнес-задачу и аудиторию  
2. Сначала построить базу знаний  
3. Обеспечить точность, проверяемость и поддерживаемость контента  
4. Только затем усиливать систему автоматизацией  

Если база знаний слабая, автоматизация лишь масштабирует шум. В GEOFlow **качество базы знаний должно быть на первом месте**.

---

## 🚀 Быстрый старт

### Вариант 1: Docker (разработка / демо)

```bash
git clone https://github.com/yaojingang/GEOFlow.git
cd GEOFlow
cp .env.example .env
vi .env

docker compose build
docker compose up -d
```

- Сайт: `http://localhost:18080` (порт **`APP_PORT`**, по умолчанию `18080`)  
- Админка: `http://localhost:18080/geo_admin/login` (**`ADMIN_BASE_PATH`**, по умолчанию `geo_admin`)  

При **`docker-compose.yml`** сервис **`init`** после готовности БД выполняет первую миграцию и `db:seed` (учётная запись по умолчанию — см. таблицу ниже).

### Дополнение: Docker (продакшен)

Для продакшена используйте **`docker-compose.prod.yml`** с **Nginx + php-fpm**, а не `php artisan serve`.

```bash
cp .env.prod.example .env.prod
vi .env.prod

docker compose --env-file .env.prod -f docker-compose.prod.yml build
docker compose --env-file .env.prod -f docker-compose.prod.yml up -d postgres redis
docker compose --env-file .env.prod -f docker-compose.prod.yml up -d init
docker compose --env-file .env.prod -f docker-compose.prod.yml up -d app web queue scheduler reverb
```

- Фронт и админка идут через `web` (Nginx), PHP — контейнер `app` (php-fpm).  
- **Учётная запись по умолчанию:** в продакшене **`db:seed` не запускается автоматически**; один раз после миграций выполните команду из **`docs/deployment/DEPLOYMENT.md`** (раздел про первоначального администратора и seed; текст документа на китайском).  
- Подробности — в **`docs/deployment/DEPLOYMENT.md`**.

### Вариант 2: локальный PHP

**Требования:** PHP **8.2+** (`pdo_pgsql`, `redis` и др.), **PostgreSQL**, **Redis**, **Composer 2.x**.

```bash
git clone https://github.com/yaojingang/GEOFlow.git
cd GEOFlow
cp .env.example .env
composer install --no-interaction --prefer-dist
php artisan key:generate

php artisan migrate --force
php artisan db:seed --force
php artisan storage:link

php artisan serve --host=127.0.0.1 --port=8080
```

Дополнительные терминалы:

```bash
php artisan queue:work redis --queue=geoflow,default --sleep=1 --tries=1 --timeout=300
php artisan schedule:work
php artisan reverb:start
```

Админка: `http://127.0.0.1:8080/geo_admin/login`. **Продакшен:** Nginx + PHP-FPM, корень **`public/`**.

---

## Учётные данные по умолчанию (после `db:seed`)

| Поле | Значение |
|------|----------|
| Логин | `admin` |
| Пароль | `password` (**смените в продакшене**) |

### Блокировка после неудачных входов и ручная разблокировка

- Аккаунт администратора автоматически блокируется (`status=locked`) после **5** подряд неудачных попыток входа.
- Заблокированный аккаунт не сможет войти, пока администратор не выполнит ручную разблокировку.
- Команда разблокировки:

```bash
php artisan geoflow:admin-unlock <username>
```

Пример:

```bash
php artisan geoflow:admin-unlock admin
```

---

## Docker (кратко)

**Разработка** (`docker-compose.yml`): `postgres`, `redis`, `init`, `app` (`${APP_PORT:-18080}:8080`), `queue`, `scheduler`, `reverb` (`${REVERB_EXPOSE_PORT:-18081}:8080`). Переменные `docker/entrypoint.sh` — как в [README_en.md](README_en.md).

**Продакшен** (`docker-compose.prod.yml`): запуск через `docker compose --env-file .env.prod -f docker-compose.prod.yml …` (см. дополнение выше и **`docs/deployment/DEPLOYMENT.md`**).

---

## Разработка и тесты

```bash
composer test
./vendor/bin/pint
```

---

## 🌍 Другие языки

- [简体中文](README.md)
- [English](README_en.md)
- [日本語](README_ja.md)
- [Español](README_es.md)

---

## ⭐ Динамика звёзд

[![Star History Chart](https://api.star-history.com/svg?repos=yaojingang/GEOFlow&type=Date)](https://star-history.com/#yaojingang/GEOFlow&Date)
