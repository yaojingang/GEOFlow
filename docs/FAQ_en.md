# GEOFlow FAQ

> Languages: [简体中文](FAQ.md) | [English](FAQ_en.md) | [日本語](FAQ_ja.md) | [Español](FAQ_es.md) | [Русский](FAQ_ru.md)

## 1. What is the default admin URL and account?

- Admin URL: `/geo_admin/`
- Default admin username: `admin`
- Default admin password: `admin888`

After first login, it is recommended to immediately change the admin password and `APP_SECRET_KEY`.

## 2. Is Docker required?

No.

You can:

- Use Docker Compose to start `web + postgres + scheduler + worker`
- Or install PHP and PostgreSQL locally and run `php -S` directly

If you just want to get started as quickly as possible, Docker is the preferred option.

## 3. Is PostgreSQL required at runtime?

Yes.

The current public version uses PostgreSQL as the official runtime database. The repository does not include any production database files.

## 4. Why are there no image libraries, knowledge bases, or article data in the repository?

Because these are all runtime or business data:

- Image libraries
- Knowledge base source files
- Generated articles
- Logs and backups

The public repository only provides source code and configuration templates — it does not include these.

## 5. How do I connect an AI model?

After logging into the admin panel, go to "AI Configuration Center → AI Model Management" and add a model by filling in:

- API URL
- Model ID
- Bearer Token

The system is compatible with OpenAI-style APIs.

## 6. What is the article generation pipeline?

The basic flow is:

1. Configure models, prompts, and material libraries
2. Create a task
3. Scheduler enqueues
4. Worker executes AI generation
5. Draft / Review / Publish
6. Front-end displays the article

## 7. Is there a CLI or skill?

Yes.

- CLI documentation: [project/GEOFLOW_CLI_en.md](project/GEOFLOW_CLI_en.md)
- Companion skill repository: [yaojingang/yao-geo-skills](https://github.com/yaojingang/yao-geo-skills)
- Corresponding skill: `skills/geoflow-cli-ops`

## 8. Which documents should I read first for secondary development?

Start with these:

- [System Documentation](System_Documentation_en.md)
- [AI Development Guide](AI_PROJECT_GUIDE_en.md)
- [Project Structure](project/STRUCTURE_en.md)
- [API v1 Reference Draft](project/API_V1_REFERENCE_DRAFT_en.md)
