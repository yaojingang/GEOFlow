# GEOFlow Changelog

This document tracks user-facing updates in the public repository. For future GitHub pushes, update this file together with the Chinese version in `CHANGELOG.md`.

## 2026-05-10

### v1.2.x

- Improved third-party AI title generation compatibility:
  - The title generation flow no longer hardcodes the `openai` driver
  - Runtime driver selection now uses the API base URL and model ID
  - Prevents DeepSeek, Zhipu, MiniMax, Volcengine Ark, Alibaba DashScope, and other OpenAI-compatible providers from being routed to `/v1/responses` and returning 404 errors
- Strengthened URL Smart Import security configuration:
  - SSRF protection remains strict by default
  - Added `URL_IMPORT_ALLOW_MIXED_DNS=false` as an example setting only for explicitly controlled transparent proxy, Docker, or VPN mixed-DNS environments
  - Application code reads `config('geoflow.url_import_allow_mixed_dns')`, so it is compatible with Laravel config caching
- Added coverage for model driver resolution and URL normalization.
- Fixed default admin initialization for production Docker first-time deployment:
  - `docker/entrypoint.prod.sh` now supports `AUTO_SEED`
  - `docker-compose.prod.yml` enables seeding only for the one-shot `init` service
  - The default admin account is created after first-time migrations, and repeated runs do not overwrite an existing `admin` user

## 2026-05-08

### v1.2.x

- Added AI model connection testing:
  - Admin AI model lists can now test API connectivity directly
  - Basic checks cover both chat models and embedding models
  - Failed tests return concrete errors to help diagnose API keys, endpoints, model IDs, and provider settings
- Improved frontend and admin asset loading stability:
  - Replaced external Tailwind Play CDN and Lucide CDN usage in frontend templates with locally hosted assets
  - Reduces the risk of broken styles or scripts in regions where external CDNs are unstable
- Added one-click deployment scripts and deployment documentation:
  - Added `deploy-scripts/` for Docker deployment, server preflight checks, and post-deployment health checks
  - Updated the Wiki with deployment guidance, server sizing recommendations, and deployment script usage notes
- Fixed task deletion compatibility:
  - Task deletion no longer depends on the legacy `article_queue` table
  - Prevents `Undefined table: article_queue` errors on the current database schema
- Improved optional material field handling in the task creation API:
  - API task creation can now omit optional author, image library, knowledge base, and fixed category fields
  - Omitted fields are written as explicit `null` values, keeping the API contract aligned with admin task creation
  - Added API contract coverage for omitted optional material fields
- Added a NetEase News-inspired frontend theme:
  - Added the `netease-news-20260429` frontend theme
  - Homepage, category, and article pages now support a cleaner two-column news-style reading layout
  - Preserves GEOFlow article, category, author, SEO, and Schema data contracts
- Added a TDWH English theme fork:
  - Added the `tdwh-english-20260501` English theme sample
  - Provides a clearer internationalized homepage, listing page, and article page structure for English content sites

## 2026-05-06

### v1.2.x

- Fixed the author fallback logic during task-based article generation:
  - If a task has no author configured, GEOFlow now uses an existing author automatically
  - If the configured author no longer exists, GEOFlow falls back to an available author
  - If no author exists in the system, GEOFlow creates a default `GEOFlow` author
  - This prevents PostgreSQL `NOT NULL` failures caused by writing `null` into `articles.author_id`
- Improved AI parsing compatibility for `URL Smart Import`:
  - When one AI model fails, GEOFlow continues with the next available model
  - Keyword and title stages can now parse plain-text AI lists, reducing failures caused by non-standard JSON responses
  - Error messages keep the model name and concrete failure reason for easier API key, response format, and provider debugging
- Upgraded the admin dashboard:
  - Added overview panels for tasks, materials, AI models, URL imports, and popular content
  - Repositioned the quick-start and trend sections to make the dashboard more useful for operations
  - Fixed overly tight spacing between the weekly trend chart and the health panels below it
- Stabilized the local runtime after the fixes:
  - Cleared Laravel optimize cache and restarted the app / queue / scheduler containers
  - Added tests for task author fallback across empty-author, missing-author, and no-author initialization scenarios

## 2026-04-18

### v1.2

- Added first-stage Chinese/English interface support:
  - English is now available across the formal admin pages
  - The login page now has its own language selector
  - The frontend shell follows the admin language selection
- Added `Smart Model Failover` for tasks:
  - Tasks can now use `Fixed Model` or `Smart Failover`
  - When the primary model fails, GEOFlow automatically tries the next available chat model by priority
- Improved provider endpoint handling:
  - Supports versioned chat and embedding endpoints for OpenAI, DeepSeek, MiniMax, Zhipu GLM, and Volcengine Ark
  - Model settings now accept either a base URL or a full endpoint
- Improved task execution behavior:
  - `task-execute.php` now queues execution instead of blocking the page synchronously
  - `published_count` is now updated correctly for tasks that publish directly
- Added frontend theme preview and activation:
  - dynamic `preview/<theme-id>` routes for safe preview-first inspection
  - theme package support under `themes/<theme-id>`
  - admin-side theme preview and activation in Site Settings
  - sample theme `qiaomu-editorial-20260418` is now included in the public repository
  - homepage, category, and archive card summaries now strip Markdown artifacts before rendering
- Added an admin first-login welcome panel:
  - shown automatically after the first admin login
  - redesigned as a single welcome letter instead of a multi-card module layout
  - defaults to Chinese with an in-panel English switch
  - footer now includes a `Project Intro` entry that reopens the panel
  - implementation notes are documented in `project/ADMIN_WELCOME_en.md`
- Added the companion `geoflow-template` skill entry:
  - maps reference URLs into GEOFlow-compatible theme packages
  - outputs `tokens.json`, `mapping.json`, and preview-first theme plans
- Upgraded default GEO prompt templates:
  - Long-form templates now cover article generation, ranking articles, keywords, and descriptions
  - Templates are aligned with GeoFlow's variable rules
- Fixed multiple admin usability issues:
  - PostgreSQL timezone drift
  - Missing leading `/` in generated image paths
  - PostgreSQL boolean write error when saving AI-generated titles
  - Default provider examples now use a neutral DeepSeek sample instead of the old third-party domain
