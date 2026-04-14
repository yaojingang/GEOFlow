# GEOFlow FAQ

> Languages: [简体中文](FAQ.md) | [English](FAQ_en.md) | [日本語](FAQ_ja.md) | [Español](FAQ_es.md) | [Русский](FAQ_ru.md)

## 1. Какой URL и логин администратора по умолчанию?

- Админка: `/geo_admin/`
- Логин: `admin`
- Пароль: `admin888`

После первого входа смените пароль администратора и `APP_SECRET_KEY`.

## 2. Обязательно ли использовать Docker?

Нет.

- Можно поднять `web + postgres + scheduler + worker` через Docker Compose
- или запускать PHP локально с PostgreSQL и `php -S`

Если нужен самый короткий путь, используйте Docker.

## 3. Runtime-база — PostgreSQL?

Да. Публичная версия рассчитана на PostgreSQL.

## 4. Почему в репозитории нет изображений, базы знаний и статей?

Потому что это runtime- или бизнес-данные:

- библиотеки изображений
- исходные файлы базы знаний
- сгенерированные статьи
- логи и бэкапы

Публичный репозиторий содержит только исходный код и шаблоны конфигурации.

## 5. Как подключить AI-модель?

В админке откройте `AI Configuration Center → AI Model Management` и укажите:

- URL API
- ID модели
- Bearer token

GEOFlow ожидает OpenAI-совместимый формат API.

## 6. Как выглядит процесс генерации статьи?

1. Настройка моделей, промптов и материалов
2. Создание задачи
3. Scheduler ставит job в очередь
4. Worker вызывает AI
5. Черновик / ревью / публикация
6. Статья выводится на фронтенде

## 7. Есть ли CLI или skill?

Да.

- CLI guide: [project/GEOFLOW_CLI_ru.md](project/GEOFLOW_CLI_ru.md)
- Репозиторий skill: [yaojingang/yao-geo-skills](https://github.com/yaojingang/yao-geo-skills)
- Skill: `geoflow-cli-ops`

## 8. Что читать в первую очередь для доработки системы?

- [系统说明文档](系统说明文档.md)
- [AI_PROJECT_GUIDE.md](AI_PROJECT_GUIDE.md)
- [project/STRUCTURE.md](project/STRUCTURE.md)
- [project/API_V1_REFERENCE_DRAFT.md](project/API_V1_REFERENCE_DRAFT.md)
