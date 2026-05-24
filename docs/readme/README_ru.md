# GEOFlow

> Languages: [简体中文](../../README.md) | [English](README_en.md) | [日本語](README_ja.md) | [Español](README_es.md) | [Русский](README_ru.md) | [Português (BR)](README_pt_BR.md)

> GEOFlow — открытая система контент-инжиниринга GEO (Generative Engine Optimization) и мультисайтовой дистрибуции. Она объединяет базы знаний, библиотеки материалов, промпты, AI-задачи, ревью и публикацию, аналитику, пакеты целевых сайтов GEOFlow Agent, каналы WordPress REST и удалённую статическую публикацию в единый управляемый поток, который превращает достоверные материалы в публикуемые, отслеживаемые и распределяемые GEO-активы.

[![PHP](https://img.shields.io/badge/PHP-8.2%2B-blue)](https://www.php.net/)
[![PostgreSQL](https://img.shields.io/badge/Database-PostgreSQL-336791)](https://www.postgresql.org/)
[![Docker](https://img.shields.io/badge/Docker-Compose-blue)](https://docs.docker.com/compose/)
[![License](https://img.shields.io/badge/License-Apache--2.0-blue.svg)](../../LICENSE)
[![GitHub stars](https://img.shields.io/github/stars/yaojingang/GEOFlow?style=social)](https://github.com/yaojingang/GEOFlow/stargazers)
[![GitHub forks](https://img.shields.io/github/forks/yaojingang/GEOFlow?style=social)](https://github.com/yaojingang/GEOFlow/network/members)
[![GitHub issues](https://img.shields.io/github/issues/yaojingang/GEOFlow)](https://github.com/yaojingang/GEOFlow/issues)

GEOFlow распространяется по лицензии [Apache License 2.0](../../LICENSE). Его можно использовать, копировать, изменять и распространять, в том числе в коммерческих целях, при условии сохранения уведомлений об авторских правах и лицензии, а также соблюдения условий Apache-2.0 о патентах, товарных знаках и отказе от гарантий.

---

## ✨ Возможности

| Возможность | Описание |
|-------------|----------|
| 🤖 Несколько моделей | API в стиле OpenAI и нативные endpoints Gemini, chat / embedding, адаптация URL провайдера, smart failover, повторы и статистика вызовов |
| 🧠 RAG по базе знаний | Структурное rule chunking, опциональное LLM semantic planning, стабильный fallback, запись векторов при наличии embedding-модели и поиск контекста при генерации |
| 🗂 Материалы и промпты | Заголовки, ключевые слова, изображения, авторы, базы знаний, промпты тела и специальные промпты |
| 📦 Автоматизация задач | Лимиты генерации, пул черновиков, ревью, частота публикации, очереди, повторы, область публикации и фильтры статей по задаче |
| 📋 Ревью и статьи | Черновики, ревью, публикация, корзина, авторы, категории, SEO и источник задачи в одном процессе |
| 📡 Мультисайтовая дистрибуция | Каналы GEOFlow Agent и WordPress REST, секреты, пакеты целевых сайтов, статический режим, rewrite-правила, удалённое редактирование/удаление, очереди и логи |
| 🧾 Пакеты целевого сайта | PHP Agent для каждого канала: главная, страницы статей, статические assets, sitemap, `llms.txt` / TXT-карты и Schema |
| 📊 Аналитика | Обзор системы, одиночный сайт, мультисайтовая дистрибуция, access logs, топ-контент, AI-краулеры и тренды |
| 🔍 SEO и LLM-friendly вывод | SEO, Open Graph, Schema, GFM Markdown, отдельный CSS, синхронизация изображений, sitemap и TXT-карты |
| 🎨 Фронт и темы | Темы, preview, переключение в админке и синхронизация удалённых заголовков, copyright, тем и категорий |
| 🌍 i18n админки | Китайский, английский, японский, испанский, русский и португальский (Бразилия), включая модули GEOFlow 2.0 |
| 🔔 Уведомления о версии | Проверка GitHub `version.json` и уведомления о новой версии |
| 🐳 Готовый Docker | **Compose**: Postgres (pgvector), Redis, приложение, очередь, scheduler, Reverb и production Nginx/php-fpm |

---

## 🖼 Предпросмотр UI

<table>
  <tr>
    <td width="33%"><img src="../../docs/images/screenshots/dashboard-en.png" alt="GEOFlow admin dashboard preview" /><br /><sub>Admin Dashboard</sub></td>
    <td width="33%"><img src="../../docs/images/screenshots/analytics-en.png" alt="GEOFlow analytics preview" /><br /><sub>Analytics</sub></td>
    <td width="33%"><img src="../../docs/images/screenshots/tasks-en.png" alt="GEOFlow task management preview" /><br /><sub>Task Management</sub></td>
  </tr>
  <tr>
    <td width="33%"><img src="../../docs/images/screenshots/distribution-channel-en.png" alt="GEOFlow distribution channel preview" /><br /><sub>Distribution Channel</sub></td>
    <td width="33%"><img src="../../docs/images/screenshots/materials-en.png" alt="GEOFlow materials preview" /><br /><sub>Materials</sub></td>
    <td width="33%"><img src="../../docs/images/screenshots/ai-config-en.png" alt="GEOFlow AI model configuration preview" /><br /><sub>AI Model Configuration</sub></td>
  </tr>
  <tr>
    <td width="33%"><img src="../../docs/images/screenshots/site-settings-en.png" alt="GEOFlow site settings preview" /><br /><sub>Site Settings</sub></td>
    <td width="33%"></td>
    <td width="33%"></td>
  </tr>
</table>

Охватывает админ-панель, аналитику, задачи, мультисайтовую дистрибуцию, материалы, модели и настройки сайта.

---

## 🆕 Что важно в новой версии

Ключевые изменения GEOFlow 2.0:

- **Админка стала операционным хабом**: сохранён трёхшаговый onboarding, а входы сгруппированы по одиночному сайту, мультисайтовой дистрибуции и companion skills.
- **Gemini и OpenAI-compatible провайдеры**: настройка моделей покрывает OpenAI-style маршруты и нативные Gemini chat / embedding.
- **Семантическое планирование chunking**: доступны структурные правила, автоматический режим и опциональное LLM semantic planning; LLM планирует только границы, а финальные фрагменты восстанавливаются из исходного текста.
- **Отдельная аналитика**: обзор системы, контент-операции, здоровье задач/материалов, статус дистрибуции, access logs и тренды AI-краулеров находятся в `/admin/analytics`.
- **Дистрибуция работает end-to-end**: каналы GEOFlow Agent и WordPress REST, секреты, проверка соединения, пакеты целевых сайтов, статический/rewrite режимы, синхронизация настроек, очереди, логи, удалённое редактирование и удаление.
- **Область публикации явная**: задача может публиковать локально и в каналы, только в каналы или только в локальный GEOFlow; локальный режим отключает выбор каналов.
- **Целевые сайты могут быть статическими**: дистрибуция регенерирует удалённую главную, страницы статей, sitemap, TXT-карты, `llms.txt`, изображения и отдельный CSS.
- **Материалы и RAG стали полнее**: фрагменты знаний, статус векторизации, заголовки, ключевые слова, изображения, авторы и промпты образуют входной слой задач.
- **Улучшены деплой и безопасность**: production Docker использует Nginx + PHP-FPM, seed не перезаписывает существующих админов, Docker/Composer mirrors настраиваются.
- **Локализация покрывает текущие ключи админки**: модули GEOFlow 2.0 больше не показывают raw translation keys или английский fallback.

---

## 🏗 Схема выполнения

```
Админка
  ↓
AI-настройки / материалы / промпты / задачи
  ↓
Планировщик / очередь / worker запускает AI-генерацию
  ↓
Черновик / ревью / публикация
  ↓
Локальные статьи и SEO-страницы
  ↓
Очередь дистрибуции / Agent целевого сайта
  ↓
Удалённая главная, статьи, sitemap, TXT-карты и llms.txt
```

---

## 🧱 Архитектура

| Уровень | Описание |
|---------|----------|
| Web / Admin | **Laravel**: маршруты, контроллеры, сайт статей, **Blade** admin, аналитика, дистрибуция, материалы и задачи |
| API / Agent | Локальные API и PHP Agent целевых сайтов для health check, приёма/обновления/удаления статей, синхронизации настроек и генерации статических файлов |
| Scheduler / Queue / Reverb | **Scheduler**, **`queue:work` / Horizon** для генерации и дистрибуции, при необходимости **Reverb** |
| Домен и Jobs | `app/Services`, `app/Jobs`, `app/Http/Controllers` для AI, RAG, публикации, дистрибуции и анализа логов |
| Хранение | **PostgreSQL** (рекомендуется **pgvector**) + **Redis** + JSON/статические файлы на целевых сайтах |

Основной поток: настройка моделей и промптов → подготовка базы знаний, заголовков, ключевых слов, изображений и авторов → задачи в очередь → генерация воркерами → черновик / ревью / публикация → локальные SEO-страницы → дистрибуция в выбранные каналы → аналитика производства, дистрибуции, доступа и AI-краулеров.

---

## ⚡ Быстрый старт в админке

1. **Настройте API**: добавьте минимум одну chat-модель; для RAG добавьте embedding-модель и выберите стратегию chunking.
2. **Настройте материалы**: подготовьте базу знаний, заголовки, ключевые слова, изображения и авторов на основе реальной проверяемой информации.
3. **Создайте задачу**: выберите материалы, модель, объем генерации, частоту и область публикации; сначала проверьте поток через черновики или ревью.

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
- **Учётная запись по умолчанию:** production-сервис `init` запускает `db:seed` после миграций и создаёт начальную admin-учётную запись; повторный запуск не перезаписывает существующего пользователя `admin`.
- Подробности — в **`../../docs/deployment/DEPLOYMENT.md`**.

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
php artisan queue:work redis --queue=geoflow,distribution,default --sleep=1 --tries=1 --timeout=300
php artisan schedule:work
php artisan reverb:start
```

Админка: `http://127.0.0.1:8080/geo_admin/login`. **Продакшен:** Nginx + PHP-FPM, корень **`public/`**.

---

## Учётные данные по умолчанию (после `db:seed`)

| Поле | Значение |
|------|----------|
| Логин | `GEOFLOW_ADMIN_USERNAME`, по умолчанию `admin` |
| Пароль | В локальной разработке по умолчанию `password`; в продакшене задайте `GEOFLOW_ADMIN_PASSWORD`. Если значение пустое и аккаунт ещё не существует, seeder сгенерирует одноразовый случайный пароль в логах init / `db:seed`. |

Seeder создаёт аккаунт только если целевой логин ещё не существует. Повторные запуски никогда не перезаписывают существующий логин, email или пароль.

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

**Продакшен** (`docker-compose.prod.yml`): запуск через `docker compose --env-file .env.prod -f docker-compose.prod.yml …` (см. дополнение выше и **`../../docs/deployment/DEPLOYMENT.md`**).

---

## Разработка и тесты

```bash
composer test
./vendor/bin/pint
```

---

## 🌍 Другие языки

- [简体中文](../../README.md)
- [English](README_en.md)
- [日本語](README_ja.md)
- [Español](README_es.md)

---

## 📄 Лицензия

GEOFlow распространяется по [Apache License 2.0](../../LICENSE). Лицензия разрешает личное и коммерческое использование, изменение, распространение и приватное развертывание при соблюдении уведомлений о лицензии, авторских правах, изменениях, патентных условиях и отказе от гарантий.

---

## ⭐ Динамика звёзд

[![Star History Chart](https://api.star-history.com/svg?repos=yaojingang/GEOFlow&type=Date)](https://star-history.com/#yaojingang/GEOFlow&Date)
