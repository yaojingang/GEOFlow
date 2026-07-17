# GEOFlow Changelog

This document tracks user-facing updates in the public repository. For future GitHub pushes, update this file together with the Chinese version in `CHANGELOG.md`.

## 2026-07-17

### v2.1.1 (release preparation)

- Hardened frontend structured data:
  - Every theme now emits JSON-LD through Laravel `Js::encode`, blocking executable-context payloads such as `</script>` while preserving valid Schema data.
- Tightened managed image and API idempotency boundaries:
  - Image uploads now use content-addressed names and managed-root validation. The new `images.managed_path_hash` identity and `managed_image_paths` registry track state and fencing data to prevent external-path, symlink, and concurrent-deletion escapes.
  - API idempotency records now carry durable state, owner leases, and a fingerprint version. Legacy and expired `in_progress` records enter explicit manual-recovery paths.
  - Physical image deletion stays disabled by default. Upgrades must drain old processes, confirm the migration, complete the `managed_path_hash` backfill, and pass readiness checks before enabling deletion.
- Unified outbound request security and sensitive admin authorization:
  - Distribution, URL import, theme reference fetching, AI, update metadata, and archive downloads now use the safe outbound gateway with URL normalization, complete DNS-candidate validation, IP pinning, redirect controls, response limits, and redacted errors, closing SSRF bypass paths.
  - Sensitive Distribution, URL Import, theme, and replication management routes now require super-admin authorization.
- Isolated generated theme code:
  - Live theme editing routes and UI that wrote Blade or CSS are disabled. Theme replication preview uses a trusted deterministic page, never compiles generated Blade, and applies a sandbox CSP that blocks scripts and external resources.
  - Theme replication publication is package-only and no longer writes generated files into live theme directories.
- Added a read-only security audit:
  - `php artisan geoflow:security-audit` emits a human-readable report, while `--json` emits a stable schema. Any finding or incomplete audit returns exit code `1`.
  - Checks cover security migrations, the managed image registry, deletion gates, API idempotency state, legacy image path input, and private outbound exceptions without HTTP, DNS, or repair operations.
- Updated the dependency security baseline:
  - Upgraded Laravel, Guzzle, PSR-7, and Symfony security patches; the lock file has no known advisories in the dependency audit.
  - The minimum PHP version is now consistently 8.3 to match the current `laravel/ai` requirement; Docker continues to default to PHP 8.4.

## 2026-06-26

### v2.1.0

- Added the Enterprise Knowledge drafting workflow:
  - The admin now includes an Enterprise Knowledge entry for creating projects, uploading or pasting multiple source files, reviewing parsed sources, generating AI drafts, and viewing revision history.
  - Added project, source, and revision tables plus a queued generation job, so long drafting runs can execute in the background instead of blocking the admin page.
  - Source parsing covers common formats including text, Markdown, HTML, CSV, JSON, XML, PDF, Word, and Excel, with each source kept traceable.
  - AI cleanup and structured organization now prioritize source coverage. Long inputs are processed by module, and missing source facts are backfilled into the relevant sections to reduce over-simplification.
  - Draft output keeps modules, audience, content format, manual-review notes, and source summaries for later review and publishing.
- Improved knowledge-base and material management:
  - Knowledge-base detail pages now include a Markdown editor for viewing and adjusting knowledge content directly in the admin.
  - Knowledge-base detail pages can resubmit semantic chunking and safely return to the detail page after the action.
  - The materials page now links into the Enterprise Knowledge builder, making standard knowledge bases, material libraries, and Enterprise Knowledge drafts easier to connect.
- Expanded themes, templates, and frontend output:
  - Added live theme-template editing from the admin, with test coverage for the editing flow. Live theme editing is disabled in v2.1.1; the current security flow uses isolated previews and package-only review archives.
  - Site settings now support homepage module configuration and custom styling, and target-site packages can sync richer homepage structures.
  - Added the APIHot recommendation frontend theme with home, category, archive, article pages, and bundled assets.
  - Unified frontend SEO metadata output so themes share the same SEO head logic and avoid inconsistent titles, Open Graph data, and structured data.
- Improved Distribution Management and target-site synchronization:
  - Tasks now include distribution strategies for local, channel-only, and local-plus-channel publishing.
  - Article lists show clearer distribution status, remote-copy links, sync state, and failure information.
  - Distribution Management can sync target-site settings for selected active GEOFlow Agent channels.
  - Target-site packages now follow the same SEO metadata contract as local frontend pages.
- Improved deployment and runtime stability:
  - Added install-state tracking and default-data seed guards so existing deployments are not polluted by repeated demo data after restarts, migrations, or upgrades.
  - Default-admin initialization now supports email configuration, with additional production entrypoint, Redis session, Docker image, network, permissions, and reverse-proxy improvements.
  - Admin paths and Reverb auth paths now support subdirectory deployments, reducing asset and WebSocket issues when `APP_URL` includes a path prefix.
- Expanded test coverage:
  - Added tests for Enterprise Knowledge, theme editing, site settings, distribution strategies, target-site sync, SEO metadata, install guards, and deployment configuration.
  - Full release verification passed with `479 passed` and `3179 assertions`.

## 2026-06-02

### v2.0.4

- Fixed stale admin versions after code updates in deployed environments: the admin version now defaults to local `version.json`, and environment examples no longer write `GEOFLOW_APP_VERSION`.
- Reworked Docker first-install behavior: added `php artisan geoflow:install` and a system installation marker, so default install seeders only run on an empty database; existing deployments are marked as installed without re-seeding default categories, articles, site settings, ads, or prompts.
- Updated the admin version to `2.0.4`, including `version.json` and default admin version display values.

### v2.0.3

- Added the System Update Center:
  - The admin notification area now links to a dedicated System Update Center with current version, latest GitHub version, repository links, changelog links, and last-check time.
  - GitHub `version.json` metadata is used to detect and display newer versions in the admin.
- Added update planning, preflight checks, and manual command previews:
  - Admins can generate update plans from remote release archives and review added, modified, deleted, migration, dependency, and manual-step changes.
  - Preflight checks cover repository trust, archive size, file paths, disk space, worktree state, backup state, and execution switches.
  - The admin shows copyable manual command lists and does not execute host shell commands by default.
- Added backup and rollback flow:
  - Files scheduled for replacement are backed up before update, with manifest, source version, target version, file count, and backup path recorded.
  - Supports single-file restore, full rollback, rollback preflight checks, and a keep-last-10 backup policy.
  - Rollback execution is protected by environment switches and super-admin password checks by default.
- Added queued update execution and recovery:
  - Updates and rollbacks run through the background queue with stages, timeline entries, logs, verification commands, and failure reasons.
  - Includes status polling, stale-run warnings, failed-run retry, and manual failure marking.
  - File verification before apply reduces partial replacement and concurrent update risks.
- Added deployment diagnostics and self-recovery guidance:
  - System Update Center now checks APP_URL, APP_KEY, database connectivity, migrations table, and writable `storage/app` / `bootstrap/cache` paths.
  - Shows runtime configuration, Laravel log summaries, and deployment-mode-specific command guidance.
  - Added Ubuntu 24.04 LTS + Docker production troubleshooting docs for initialization commands, `.env.prod` checks, container logs, and 500 errors.
- Updated the admin version to `2.0.3`, including `version.json` and default admin version display values.

## 2026-05-30

### Distribution Management

- Added Generic HTTP API distribution channels:
  - Supports no auth, Bearer Token, Basic Auth, custom Header Key, and HMAC signatures.
  - Supports per-action HTTP methods and paths for health checks, publish, update, delete, and site-settings sync.
  - Supports `remote_id` / `remote_url` response mapping, success-status configuration, payload wrapping, and request timeout settings.
  - Generic API channels reuse the existing distribution queue, retries, logs, remote article edit/delete actions, and site-settings sync flow.
- Distribution channel detail pages now show Generic API onboarding, response-mapping summaries, and a sample payload for third-party receivers.
- README and localized READMEs now describe the Generic HTTP API channel capability.

## 2026-05-28

### v2.0.2

- Upgraded the admin dashboard into a GEOFlow automation workflow panel:
  - Shows how APIs, material libraries, tasks, articles, distribution, Analytics, and site settings connect in the automated production flow.
  - Keeps the three-step setup guide and companion Skill shortcuts while removing duplicated dashboard metric cards.
- Improved Analytics data accuracy:
  - Total views, viewed content, top content, and log analytics now prefer `view_logs` event data and filter out non-GET requests.
  - Publishing trends use actual `published_at` timestamps, and distribution metrics respect task/category filters through related articles.
  - AI crawler, search bot, other automation, and human traffic classification now share one rule set to reduce misclassification.
- Improved local Docker development behavior:
  - The development image disables CLI OPcache so mounted code updates are reflected without stale admin pages.
- Updated the admin version to `2.0.2`, including `version.json`, environment examples, and default admin version display values.

## 2026-05-24

### AI Models and Knowledge Bases

- Added native Gemini model support:
  - Gemini chat and embedding models can be configured without relying only on OpenAI-compatible routes.
  - Model listings, connection tests, and task generation now recognize Gemini providers consistently.
- Added knowledge-base chunking strategy configuration:
  - Supports structured rule chunking, automatic strategy selection, and optional LLM semantic planning.
  - The LLM only plans semantic boundaries; final chunks are rebuilt from the source text, with rule chunking as the stable fallback.
  - Chunk metadata now includes title, section path, strategy, sequence, and source hash for preview, debugging, and rebuilds.

### Tasks and Distribution

- Improved task create/edit pages:
  - Form width now aligns with the task-management list and reduces unused side whitespace.
  - Content settings, material choices, and distribution-scope sections use the wider layout more effectively.
- Fixed channel selection when the publication scope is local-only:
  - Selecting “publish only to local site” disables and clears distribution channel checkboxes in the UI.
  - The backend ignores stale `distribution_channel_ids` under `local_only`, preventing accidental remote distribution jobs.

### Documentation

- Updated the repository README and localized READMEs with Gemini, semantic chunking, WordPress REST channels, and publication-scope behavior.
- Updated the Chinese and English Wiki outline and added focused pages for Distribution Management, Analytics, and Knowledge Chunking / RAG.

## 2026-05-23

### Distribution Management

- Added WordPress REST API distribution channel support:
  - Supports WordPress Application Password authentication, with encrypted storage and no plaintext reveal.
  - Supports post publish, update, delete, media upload, category/tag sync, and basic site settings sync.
  - Shows different configuration fields and onboarding guidance for GEOFlow Agent and WordPress REST channels.
  - Reuses the unified distribution queue, remote metadata, health checks, remote edit/delete actions, and distribution logs for WordPress channels.

### Documentation

- Systematically refreshed the repository homepage README and localized READMEs:
  - Updated the hero description from future multi-channel distribution to the current GEO content engineering and multi-site distribution system.
  - Added Analytics, Distribution Management, target-site packages, static page distribution, `llms.txt` / TXT maps, remote site-settings sync, and LLM-friendly output to the feature tables.
  - Updated runtime and architecture sections with target-site Agents, distribution queues, remote static pages, and log analytics.

## 2026-05-22

### v2.0.1

- Added a working Distribution Management flow:
  - The admin now includes distribution channel listing, creation, editing, detail pages, queue view, logs, connection tests, pause/enable actions, secret reset, and remote article management.
  - Channel secrets are shown once after creation, and super admins can temporarily reveal them again by verifying the current login password.
  - Tasks and articles can be bound to distribution channels. After local publishing, articles can automatically enter the distribution queue, with distribution status visible on task and article lists.
  - The distribution queue supports remote-copy editing and deletion. Remote edits also update the local GEOFlow article, and remote deletion refreshes the target homepage and map files.
- Added target-site packages and static-site delivery:
  - Channel detail pages can download target-site packages preconfigured with the current channel secret, site settings, and deployment path.
  - Packages include a PHP Agent, homepage, article detail pages, static assets, sitemap, TXT map, Apache `.htaccess`, and Nginx rewrite-rule examples.
  - Static mode is enabled by default. Publishing or deleting articles regenerates the static homepage, detail pages, sitemap, and LM-friendly TXT map files.
  - Article pages now include Markdown rendering, tables, code blocks, quotes, image rendering, Schema structured data, and external CSS asset references.
- Added remote site-settings synchronization:
  - Distribution channel edit pages can manage target-site title, subtitle, description, copyright, ICP/filing text, theme template, and categories.
  - Added an Update Target Site action to resync homepage, article pages, map files, and remote configuration after uploading a fresh package or changing settings.
  - Added static-mode and rewrite-mode guidance, plus copyable Apache/Nginx rules in the admin.
- Added the Analytics page:
  - The admin top navigation now includes Analytics, centralizing system overview, single-site operations, multi-site distribution, and self-service log data.
  - Analytics supports date range, quick time ranges, distribution channel, task, category, article, traffic type, and log source filters. Quick time selection updates the form first; data refreshes after clicking Apply Filters.
  - Content analytics includes publishing trends, task trends, content funnel, category distribution, and task/material/AI health panels.
  - Log analytics includes visit trends, top articles, top channel sites, AI crawler recognition, status codes, source types, and sample access-log visualization.
- Reworked the admin dashboard into a navigation hub:
  - Removed dashboard statistics cards and moved statistics into Analytics.
  - Kept the three-step setup guide and grouped common entries into Single-Site Operations, Multi-Site Distribution, and companion Skill resources.
  - Added prompt configuration and user management entries under single-site operations, plus target packages, distribution queue/logs, and related skills under multi-site distribution.
- Improved the first-deployment guide:
  - `GEOFlow 2.0 First Deployment Guide` now uses a compact white Kami-style document layout with smaller title and body typography.
  - Copy now covers dashboard navigation, Analytics, single-site operations, multi-site distribution, and backup checks before production.
- Completed Portuguese admin localization:
  - Incorporated and completed the `pt_BR` admin translations from PR #27, covering navigation, notifications, authors, frontend copy, materials, AI configuration, Analytics, Distribution Management, and all current admin language keys.
  - Added Portuguese locale coverage tests to prevent new admin modules from falling back to English copy.
- Incorporated low-risk Docker deployment PR improvements:
  - Development and production compose files can now configure PHP, Composer, Nginx, pgvector, Redis, and Composer Packagist mirror images through environment variables.
  - `.dockerignore` now excludes local Docker data, logs, caches, sessions, view caches, and upload directories so runtime data is not copied into built images.
  - Added default-admin seeder coverage for creating the initial admin and preserving existing credentials.
- Expanded test coverage:
  - Added tests for Distribution Management, Analytics, access logs, admin activity sanitization, the welcome guide, migration structure, and retry policy.
  - Full release verification passed with `188 passed` and `1231 assertions`.

## 2026-05-21

### v2.0

- Updated the admin version to `2.0`, including `version.json`, environment examples, and default admin version display values.
- Reworked the first-login admin welcome panel into a first-deployment guide:
  - Reminds administrators to check passwords, admin path, site URL, language, and baseline security settings first
  - Guides verification of PostgreSQL, Redis, queue workers, scheduler, and writable storage paths
  - Clarifies the first-run flow: configure models and prompts, prepare materials, generate a small sample, review/publish, then scale to larger tasks
- Added first-use guidance for Distribution Management 2.0:
  - Explains target channels, Agent URL, secrets, static mode, and target-site packages
  - Guides package download, connection tests, remote settings sync, and distribution log review
  - Emphasizes backing up the database, `.env`, uploads, `storage`, and target-site packages before upgrades or migrations

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
  - The one-shot `init` service runs `geoflow:install` after migrations
  - The default admin account is created only for an empty first install; existing deployments are marked as initialized
  - Long-running services do not receive initialization environment variables, so restarts do not repeat install seeders

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
