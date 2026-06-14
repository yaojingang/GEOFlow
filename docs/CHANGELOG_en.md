# GEOFlow Changelog

This document tracks user-facing updates in the public repository. For future GitHub pushes, update this file together with the Chinese version in `CHANGELOG.md`.

## 2026-06-14

### CRM Activity Editor Refinement

- Kept “activity records” and “CRM tasks” as separate concepts:
  - Activity records store completed communication outcomes.
  - CRM tasks store future follow-up actions.
- Upgraded the activity input into a lightweight Markdown editor with the article editor visual style:
  - Supports write, preview, and source modes.
  - Quick insert actions cover headings, bold, italic, quotes, lists, links, inline code, and dividers.
  - Reuses admin form borders and button styling without loading full Vditor on CRM detail pages.
  - Preview rendering now restricts link protocols so unsafe links are not rendered as direct clickable targets.
- Verification:
  - `_markdown-editor.blade.php` syntax check passed.
  - `AdminCrmPagesTest` passed: `12 passed / 129 assertions`.

### CRM Inquiry and Opportunity Boundary Optimization

- Refined inquiry statuses: new/edit screens now focus on demand-processing states (`new`, `analyzing`, `qualified`, `converted`, `invalid`, `closed`) while remaining compatible with historical `quoted / won / lost` records.
- Added a clear inquiry-to-opportunity conversion flow:
  - Carries over Collection, customer, source inquiry, opportunity name, owner, and demand-summary context.
  - Marks the source inquiry as converted after successful opportunity creation.
  - Shows a “view opportunity” path when an inquiry already has linked opportunities.
- Enhanced opportunity editing:
  - Shows the source inquiry summary, missing questions, and linked Entity / Knowledge Base / Case counts.
  - Adds a “new document” entry and linked document list.
- Improved document creation:
  - Added an “Associated Opportunity” selector to the document form.
  - Creating a document from an opportunity now carries over opportunity, customer, Collection, and source inquiry.
  - Document list and detail pages show the linked opportunity with a jump link.
- Documentation:
  - Updated `功能说明文档/10-轻量CRM与报价使用说明.md`.
  - Updated `agent-docs/IMPLEMENTATION_STATUS.md`, `FEATURE_DOC_INDEX.md`, and the CRM inquiry/opportunity optimization prompt execution notes.
- Verification:
  - PHP / Blade syntax checks passed.
  - `AdminCrmPagesTest` passed: `12 passed / 129 assertions`.
  - Browser checks confirmed the inquiry list, inquiry detail, opportunity create page, and document create page render without horizontal overflow; the source-inquiry card and opportunity selector render correctly.

## 2026-06-12

### Upstream Integration: Article Editor, Template Factory, and First-Deploy Hint

- Integrated the upstream Markdown article editor:
  - Article create/edit pages now use the Vditor Markdown editor.
  - Quick insert actions cover headings, quotes, lists, dividers, and images.
  - Admins can copy the Markdown body directly.
  - Admins can generate and copy rich-text HTML suitable for WeChat-style editors.
  - Images uploaded from the editor are stored in the dedicated article-editor image library and linked to the article.
- Added article editor backend support:
  - Added `ArticleEditorAssetController`.
  - Added `WeChatArticleHtmlExporter`, which strips unsafe HTML and applies inline styles to common Markdown elements.
  - Added editor image upload and WeChat-ready HTML export endpoints.
- Added the site template replication workflow:
  - Added the Template Factory entry in Site Settings.
  - Admins can submit home, listing, and article reference URLs to create a controlled template cloning task.
  - Reference URL validation blocks localhost, private networks, and reserved addresses.
  - Generated themes are first written into isolated draft directories instead of overwriting active themes.
  - The workflow supports previews, feedback iteration, copy-as-new-template, publish, package download, archive, and draft cleanup.
  - Added `site_theme_replications`, `site_theme_replication_logs`, and `site_theme_replication_versions` tables.
- Improved distribution logs:
  - Recent logs on the distribution index are now paginated.
  - Previous/next links and page jump controls are available.
- Added a first-deployment login hint:
  - Before the default admin signs in successfully, the login page can show the initial account hint.
  - In production environments without a fixed password, the page points admins to the `geoflow-init` initialization log.
  - The hint can be disabled with `GEOFLOW_INITIAL_ADMIN_HINT_ENABLED=false`.
- Documentation:
  - Added `docs/site-template-replication-agent-plan.md`.
  - Added `功能说明文档/11-文章编辑器与模板复刻使用说明.md`.
  - Updated README, agent handoff docs, implementation status, and known issues.
- Verification:
  - Applied migration `2026_06_10_000000_create_site_theme_replication_tables`.
  - PHP / Blade / translation syntax checks passed.
  - Combined regression tests passed for `AdminArticlesPageTest`, `AdminLoginPageTest`, `AdminSiteSettingsPageTest`, `AdminSiteThemeReplicationTest`, and `AdminDistributionPageTest`: `104 passed / 743 assertions`.
  - Browser smoke check confirmed `http://localhost:18080/admin/site-settings` opens and the Template Factory entry is rendered.

### Skipped Upstream Update

- Skipped the system update center readiness patch because this customized branch does not currently include the full update-center foundation. Integrating only the readiness layer would create a partial feature and unnecessary conflicts. Treat the update center as a separate future phase if needed.

## 2026-06-11

### CRM Workflow and Data Safety Upgrade

- Added a CRM workspace for due tasks, pipeline, active orders, after-sales cases, and recent inquiries.
- Added an opportunity pipeline with stages, amount, probability, expected close date, next step, and required lost reason.
- Separated completed interaction history from future CRM tasks; inquiry activity is now scoped to the current inquiry.
- Added multiple external contacts per customer with a primary-contact designation and backward-compatible legacy-field synchronization.
- Added soft-delete archiving for customers, inquiries, documents, orders, tickets, and activities so customer archival no longer removes commercial records.
- Added independent document conversion between quotation, PI, CI, packing list, and contract while retaining the source document link.
- Unified CRM navigation and detail-page UI, with desktop and mobile visual verification.
- Expanded CRM regression coverage to 11 tests and 108 assertions.

## 2026-06-09

### CRM Follow-up Timeline and Stable Print Baseline

- Extended follow-up records across the CRM lifecycle:
  - Customer and inquiry detail pages can create, view, and delete follow-up records.
  - Document, order, and after-sales detail pages show the related customer's follow-ups as a read-only timeline.
  - Added shared Markdown editor and follow-up item partials for consistent rendering.
- Completed the document form section rebuild with consistent collapsible sections for basic, buyer, seller, commercial, items, totals, terms, contract terms, and notes.
- Established HTML print preview as the current stable output path:
  - Experimental PDF/Excel backend methods remain without frontend entry points.
  - Restored the shared A4 styles for all five document types and created the `print-stable-20260609` Git recovery tag.
- Fixed buyer email and address fallback behavior when importing customer data into a document.

## 2026-06-08

### Consistent CRM Customer Selection and Display

- Unified customer labels across document, order, and after-sales pages, prioritizing the contact person while retaining company context.
- Added customer selection to order editing, including controller validation and persistence.
- Standardized customer dropdown metadata to include contact/company and Collection context, reducing ambiguous selections.

## 2026-06-07

### CRM Document Fields and Print Template Refinement

- Normalized customer and buyer field semantics:
  - Customers now separate `contact_person` from `company_name`.
  - Documents separate `buyer_contact` from `buyer_company` across forms, customer import, and print output.
  - Renamed customer `region` to `address`.
- Added packing terms, PI deposit percentage, loading/destination ports, transport mode, shipping mark, package counts, weights, volume, and package dimensions.
- Standardized bank-account JSON fields and removed the unused SKU field.
- Refined all five print templates:
  - PI payment/bank details, CI parties/declaration, and PL shipment/packing information now follow their document-specific responsibilities.
  - Unified signature, contact, buyer/seller naming, and notes rendering rules.
- Added the Entity-to-Entity relationship foundation for future inquiry recommendations, document items, orders, and after-sales context expansion.

## 2026-06-06

### CRM Document System Phases 2-10

- Completed the CRM document form rebuild:
  - Create/edit pages are now organized into Basic Info, Buyer Info, Commercial Info, Line Items, Totals, Terms & Notes, and Custom Contract Terms sections.
  - Line items support line type, Entity, SKU, model, HS code, item image, package count, net weight, gross weight, and volume CBM.
  - Item images can be selected from the image library or uploaded locally, with uploaded image files limited to 200KB.
  - The frontend previews item subtotal and grand total in real time, while backend calculation remains authoritative on save.
- Split document print rendering by document type:
  - Quotation, Proforma Invoice, Invoice, Packing List, and Contract now route to type-specific print wrappers using a shared base layout.
  - Proforma Invoice displays bank account details; Packing List hides price / amount columns; Contract displays custom customer terms, governing law, dispute resolution, and signature blocks.
  - Print pages read seller basics from site settings and allow document-level `seller_company_json` overrides.
  - Print templates support basic English and Simplified Chinese labels, with legacy documents falling back to the default language.
- Updated quote detail rendering:
  - Detail pages now show buyer information, commercial terms, image thumbnails, packaging / weight fields, and grand total.
  - Creating orders from quotes remains compatible with legacy quote amount fields.
- Documentation:
  - Updated the lightweight CRM guide with the new document form, image fields, type-specific print templates, and current boundaries.
  - Updated `agent-docs/IMPLEMENTATION_STATUS.md` for future agent handoff.
- Verification:
  - `AdminCrmPagesTest` passed, covering quote creation, image upload fields, Proforma Invoice, Packing List, Contract print pages, and quote-to-order flow.
  - Completed headless Chrome screenshot checks for the new document form and multiple print pages.

### CRM Document System Phase 1

- Expanded the CRM quote / invoice / packing list / contract data model:
  - Added buyer fields, document language, trade term, origin country, warranty / installation terms, shipping fee, discount, tax, grand total, bank account, seller company, signature notes, custom contract terms, governing law, and dispute resolution to `crm_quotes`.
  - Added line type, SKU, model, HS code, image reference / uploaded image path, package count, net weight, gross weight, and volume CBM to `crm_quote_items`.
  - Added `invoice` as a supported `document_type`.
- Updated backend save behavior:
  - Item subtotal remains backend-calculated as `quantity × unit price`.
  - Grand total is now calculated as `items subtotal + shipping fee + tax - discount`.
  - When old quotes have `grand_total=0`, order creation still falls back to the legacy `total_amount`.
- Documentation:
  - Documented the CRM document implementation plan, including line item images and custom contract terms; completion details are now maintained in the agent implementation status and usage guide.
  - Updated the lightweight CRM usage guide to remove old external-contact wording and keep the internal-owner model consistent.
- Verification:
  - Applied migration `2026_06_06_030000_enhance_crm_quote_documents`.
  - Related PHP / Blade syntax checks passed.
  - `AdminCrmPagesTest` passed, covering invoice creation, grand total calculation, and print title rendering.
  - Completed a headless Chrome render screenshot check for the quote creation page.

## 2026-06-05

### Lightweight CRM Phases 4-7

- Phase 4: added order management:
  - Quote detail pages can create sales orders directly.
  - Orders preserve customer, inquiry, quote, Collection, and Entity context.
  - Order items support product text, quantity, unit price, amount, and status maintenance.
- Phase 5: added after-sales tickets:
  - Tickets can link to customers, orders, Collections, a core Entity, Knowledge Bases, and Cases.
  - Ticket AI analysis can extract issue summaries, suggested replies, missing questions, and related material suggestions.
  - AI only recommends existing materials and does not write directly to Knowledge Bases or Case DB.
- Phase 6: added CRM content proposals and task source linkage:
  - Inquiry detail pages can create title and FAQ proposals.
  - Ticket detail pages can create FAQ and Case proposals.
  - Content proposals must be approved by an administrator before writing into title libraries, Knowledge Bases, or Case DB.
  - Task creation supports customer, inquiry, or ticket as a CRM source, with Collection-aware filtering.
- Phase 7: improved CRM filters and admin entry points:
  - CRM list pages now include Collection, status, and type filters where relevant.
  - CRM navigation covers customers, inquiries, quotes, orders, after-sales tickets, and content proposals.
  - Task CRM source selection is limited to the current Collection by default; cross-Collection use requires explicit opt-in.
- Verification:
  - Applied migration `2026_06_05_040000_create_crm_order_ticket_and_content_tables`.
  - PHP / Blade syntax checks passed.
  - `AdminCrmPagesTest` and `AdminTasksPageTest` passed.
  - Visual screenshot checks were completed for orders, tickets, ticket detail, proposals, task creation, and ticket creation pages.

### Lightweight CRM Phases 1-3

- Added a new admin `CRM` menu entry, positioned as lightweight customer / inquiry / quotation support rather than a full ERP module.
- Phase 1: added basic customer management:
  - Customers can be assigned to Collections.
  - Customer detail pages support internal owners and follow-up records.
  - Customer lists support search, status filtering, and Collection filtering.
- Phase 2: added inquiry management and AI need recognition:
  - Inquiries can link to customers, internal owners, Collections, Entities, Knowledge Bases, Cases, and tags.
  - AI analysis can fill language, need summary, product interest, reply points, missing questions, and urgency.
  - AI only recommends existing Entities, Knowledge Bases, and Cases; it does not create new materials.
  - When no model is configured or the AI call fails, a local keyword-matching fallback provides recommendations.
  - Inquiry-linked materials are Collection-limited to avoid cross-business-context mistakes.
- Phase 3: added quotation management:
  - Quotes can be created from customers or inquiries.
  - Creating a quote from an inquiry can prefill the customer, Collection, and linked Entities.
  - Quote items support Entity links, quantity, unit, unit price, and backend amount calculation.
  - Added quote detail and printable quote pages.
- Documentation:
  - Added `功能说明文档/10-轻量CRM与报价使用说明.md`.
  - Updated `agent-docs` handoff, implementation status, known issues, and feature-doc index.
- Verification:
  - `AdminCrmPagesTest` passed, covering customers, inquiries, AI fallback recommendations, quotes, and printable pages.
  - `AdminCollectionsPageTest` passed, confirming existing Collection/material entry points remain intact.

### Article Generation Max Output Token Setting

- Added an "Article Max Output Tokens" field to the AI model configuration page:
  - Only chat models show and save this field; embedding models ignore it.
  - Empty values fall back to `GEOFLOW_CONTENT_MAX_TOKENS`, defaulting to `8192`.
  - The model list shows whether each chat model uses a model-level value or the system default.
- The article-generation Worker now passes the max output token setting into model requests:
  - OpenAI uses `max_output_tokens`.
  - Gemini uses `maxOutputTokens`.
  - OpenAI-compatible / DeepSeek / OpenRouter-style providers use `max_tokens`.
- Added truncation-risk logging:
  - Warnings are written when the provider returns `finish_reason=length`, Markdown code fences are unclosed, or long content appears unfinished.
- Verification:
  - Applied migration `2026_06_05_020000_add_max_tokens_to_ai_models`.
  - Focused `AdminAiModelsPageTest` and `WorkerExecutionServiceMaxTokensTest` tests passed.

### Article Skill Prompt v1

- Added a lightweight Master Prompt + Skill Prompt generation layer:
  - Existing `prompt_id` remains the required Master Prompt for tasks, preserving old task-generation behavior.
  - Added optional `tasks.skill_prompt_id` for task create/edit pages.
  - During article generation, the Worker composes Master Prompt and Skill Prompt before rendering title, keyword, and RAG knowledge variables.
  - Generation traces now record `skill_prompt_id`, `skill_prompt`, and `has_skill_prompt` for article review and debugging.
- Expanded the Article Prompt Configuration entry:
  - The former content prompt page now manages both `content` Master Prompts and `skill` Skill Prompts.
  - Added starter Skill Prompt templates for Comparison, Buying Guide, and Application article types.
  - Prompts already referenced by tasks cannot change type directly, preventing semantic drift for existing tasks.
- Added `skill_prompts` to the API catalog response for future external task creation flows.
- Verification:
  - Applied migration `2026_06_05_010000_add_skill_prompt_id_to_tasks`.
  - `WorkerExecutionServicePromptTest`, `AdminAiPromptsPageTest`, `AdminTasksPageTest`, and focused API catalog / task-create tests passed.

### AI Material Analysis Prompts and URL Import Language Fixes

- Added AI analysis model selection when creating URL Smart Import jobs:
  - Admins can choose a specific chat model or keep the default auto-select behavior.
  - The selected model is tried first for page cleaning, knowledge organization, keyword generation, and title generation.
  - Existing failover remains in place, so other available models are still tried if the selected model fails.
- Added shared AI material analysis rules:
  - Centralized language consistency, fact-grounding, table/spec preservation, and JSON-only output constraints.
  - Manual Knowledge Base, Entity, and Case AI analysis now reuse these rules.
  - Knowledge analysis now clearly separates summary, admin-facing description, and storage-ready Markdown content instead of encouraging raw paragraph copying across fields.
- Improved preset analysis rules for Knowledge Base, Entity, and Case forms:
  - Knowledge Base analysis prioritizes product parameters, FAQ, steps, constraints, and table content.
  - Entity analysis treats Entities as lightweight index nodes rather than full knowledge documents.
  - Case analysis only extracts cases when real scenarios, problems, solutions, results, or metrics are present, and metrics must not be invented.
- Added a collapsible supplemental analysis instructions field:
  - Available on Knowledge Base create/detail, Entity, and Case forms.
  - Includes quick templates for table/spec preservation, English output, and conservative Case extraction.
  - Supplemental instructions act only as additional guidance and cannot override schema, language, or fact-grounding rules.
- Fixed mixed-language URL Smart Import Entity descriptions:
  - Non-Chinese pages no longer use a hard-coded Chinese description template.
  - Entity descriptions now preserve evidence/context according to the resolved import language.
- Verification:
  - Added tests for English URL Entity descriptions and shared prompt rules.
  - Relevant PHP syntax checks and focused material page tests passed.

### Knowledge Base Entity Relations and Case Type Governance

- Improved the Knowledge Base to Entity linking workflow:
  - Knowledge base create, edit, and detail pages now reuse the same multi-select plus per-item relation UI used by Entity material links.
  - A single knowledge base can link to multiple Entities and assign each Entity its own relation, such as primary subject, supporting reference, application reference, competitor reference, or troubleshooting reference.
  - The global default relation field remains as a compatibility fallback for existing data, bulk actions, and older form submissions.
  - Entity edit pages also reuse the same shared relation selector component when linking knowledge bases, reducing duplicated JavaScript and UI rules.
- Added a shared relation multi-selector component:
  - Added `resources/views/admin/partials/relation-multi-selector.blade.php`.
  - Centralizes the interaction where selected items each receive an independent relation setting.
- Improved Case type governance:
  - Added controlled Case types for customer success, application scenario, troubleshooting, comparison validation, implementation delivery, ROI/metrics, and general cases.
  - URL Smart Import and AI form analysis now normalize generated Cases to controlled types instead of creating free-form types such as `URL采集案例`.
  - RAG and Worker generation context now includes Case-type reference rules so article generation can use case evidence more accurately.
- Improved material list deletion behavior:
  - Keyword library, title library, image library, and knowledge base deletes preserve the current query filters and return to the material list section.
  - Knowledge base saved views now include a Clear option that clears view filters while keeping the current Collection.
- Documentation:
  - Updated the feature docs for overview, Entity/Case, Knowledge Base RAG, material management, and URL Smart Import.
  - Updated agent implementation status so future agents can quickly understand the workflow changes.
- Verification:
  - Relevant PHP, Blade, and translation syntax checks passed.
  - `AdminMaterialsPagesTest` passed, covering per-Entity knowledge base relations, shared Entity-page relation UI, and core material workflows.

## 2026-06-04

### Entity Internal Link Suggestions and UI Guidelines

- Added a controlled Entity type system:
  - Entity types are now constrained to product model, product line, industry, application scenario, material/component, technology/process, brand/company, competitor, customer segment, and general business entity.
  - Historical free-form types remain editable for backward compatibility.
  - Internal-link fields are shown only for linkable Entity types such as product model, product line, application scenario, technology/process, and brand/company.
- Added article draft internal link suggestions:
  - Article edit pages now show an internal-link suggestion card after article content and before generation sources.
  - Suggestions are based on linked Entities, Collection scope, and matched article text.
  - Admins must explicitly select and apply suggestions before Markdown links are written to the article body.
  - Added the `article_internal_links` table to record applied links for review and traceability.
- Improved Entity usage in RAG and generation traces:
  - Entity traces now include writing role information and whether the Entity can participate in draft link suggestions.
  - Final generation prompts explicitly tell the AI not to insert internal links directly; draft review handles them separately.
- Improved AI form analysis fallback:
  - Entity AI analysis now normalizes unknown free-form types to the general business Entity type.
- Added a local Codex UI guideline skill:
  - Created `/Users/leo/.codex/skills/geoflow-ui-guidelines/SKILL.md`.
  - Future GEOFlow admin UI, Blade, Tailwind, form, dropdown, selector, CSS, or JavaScript work should load this skill automatically.
  - The skill prioritizes existing partial/component reuse, consistent input border/focus styles, and avoiding duplicated CSS/JS.
- Verification:
  - Applied migration `2026_06_03_090000_add_entity_link_fields_and_article_internal_links`.
  - Relevant PHP syntax checks passed.
  - Focused feature tests passed for Entity link fields, article internal-link application, and RAG Entity traces.

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
