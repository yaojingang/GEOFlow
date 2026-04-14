# GEOFlow FAQ

> Languages: [简体中文](FAQ.md) | [English](FAQ_en.md) | [日本語](FAQ_ja.md) | [Español](FAQ_es.md) | [Русский](FAQ_ru.md)

## 1. What is the default admin URL and account?

- Admin URL: `/geo_admin/`
- Default username: `admin`
- Default password: `admin888`

Change the admin password and `APP_SECRET_KEY` after the first login.

## 2. Do I have to use Docker?

No.

You can either:

- run `web + postgres + scheduler + worker` with Docker Compose
- or run PHP locally with PostgreSQL and `php -S`

If you want the shortest path, use Docker.

## 3. Is PostgreSQL required?

Yes. The public runtime target is PostgreSQL.

## 4. Why are there no image, knowledge-base, or article data files in the repo?

Those are runtime or business data:

- image libraries
- knowledge base source files
- generated articles
- logs and backups

The public repo ships source code and configuration templates only.

## 5. How do I connect an AI model?

In the admin panel, go to `AI Configuration Center → AI Model Management` and fill in:

- API URL
- model ID
- Bearer token

GEOFlow expects an OpenAI-compatible API shape.

## 6. What is the article generation flow?

1. Configure models, prompts, and materials
2. Create a task
3. Scheduler enqueues jobs
4. Worker calls AI to generate content
5. Draft / review / publish
6. Front-end renders the article

## 7. Is there a CLI or skill?

Yes.

- CLI guide: [project/GEOFLOW_CLI_en.md](project/GEOFLOW_CLI_en.md)
- Skill repository: [yaojingang/yao-geo-skills](https://github.com/yaojingang/yao-geo-skills)
- Skill name: `geoflow-cli-ops`

## 8. Which docs should I read first for secondary development?

- [System documentation](系统说明文档.md)
- [AI project guide](AI_PROJECT_GUIDE.md)
- [Project structure](project/STRUCTURE.md)
- [API v1 reference draft](project/API_V1_REFERENCE_DRAFT.md)
