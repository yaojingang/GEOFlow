<!--
Copyright © 2026 姚金刚. All rights reserved.
Project: geoflow
Created by: 姚金刚
Date: 2026-05-16
X: https://x.com/yaojingang
-->

# Template Boundary

The `public_frontend` mode covers GEOFlow frontend template cloning, theme discovery, preview-first theme editing, and controlled design adjustments while preserving GEOFlow's rendering contract.

## Allowed Actions

- inventory existing frontend page modules
- inventory variables and helper functions used by the frontend pages
- discover existing themes and their editable files in the current workspace, preferring `resources/views/theme` for Laravel GEOFlow
- select a target theme and fork it into a preview edit session
- inspect a reference URL for design tokens and layout direction
- design a GEOFlow-compatible theme package
- audit the current template for hierarchy, spacing, typography, density, and responsive issues
- propose incremental token refinement and module-level visual cleanup
- enrich the default homepage with hero/media, chart-lite, metric, text, service/category, case/resource, visual CTA, and portal blocks when they can use current GEOFlow data or explicit static theme copy
- produce reviewed `homepage-design.json` payloads for `homepage_modules` and `homepage_style` when the current GEOFlow workspace exposes `HomepageModuleBuilder` and the admin import route
- generate before/after preview notes for touched modules
- generate a preview-first integration plan
- suggest how the admin should later expose template selection

## Disallowed Actions

- rewriting GEOFlow business logic just to imitate a reference site
- replacing existing PHP data queries with hard-coded mock content
- changing routing rules such as `/article/{slug}`, `/category/{slug}`, or `/archive/...`
- removing SEO or structured-data generation
- direct production activation without preview and confirmation
- editing the live target theme first when a preview fork has not yet been reviewed
- copying an external site's full HTML as the runtime template contract
- editing Laravel controllers, models, migrations, or routes during a design-only request

## Non-Negotiable GEOFlow Contracts

- homepage remains data-driven by published articles, featured articles, categories, search state, and pagination
- richer default-homepage modules remain data-driven by current view inputs such as site copy, carousel slides, featured articles, hot articles, latest articles, card summaries, homepage builder records, homepage style tokens, and route helpers
- search, category, and category-missing states remain focused result pages unless explicitly redesigned
- article detail remains data-driven by article, related articles, tags, SEO blocks, and the article detail ad slot
- category page remains driven by category metadata and paginated article lists
- archive page remains driven by archive overview or month-specific archive data
- frontend continues to use GEOFlow routing, helpers, and data fields

## Safe Replacement Surface

- HTML structure inside module containers
- CSS tokens, spacing, shadows, borders, and colors
- iconography and button styles
- layout composition of existing modules
- design hierarchy, visual density, and responsive behavior of existing modules
- homepage module composition, including large image sections, static text/value panels, CSS-only charts, metric cards, CTA bands, and alternative article-list treatments
- homepage builder JSON, including `style`, `modules`, built-in module types, import mode notes, and existing route-safe links
- consistency cleanup across repeated cards, section headers, and metadata rows
- ad-block visual presentation
- header / footer presentation
- theme manifest metadata, preview routes, and non-runtime notes

## Unsafe Replacement Surface

- removing required placeholders for article title, content, category, author, tags, slug, or ad CTA fields
- inventing modules that require backend data GEOFlow does not provide yet
- inventing corporate facts, customer logos, testimonials, conversion forms, pricing, analytics, or chart data that the current view does not provide
- adding fake forms or interactions that imply backend support
- replacing canonical URLs or schema data contracts
- changing article content rendering away from the markdown-rendered article body unless the system is explicitly updated
- storing preview-session backups under `resources/views/theme` or `/themes` in a way that makes them look like live templates without clear preview labeling
- claiming isolated preview URLs exist in Laravel GEOFlow unless `routes/web.php` provides them

## Homepage Enrichment Boundary

Allowed without backend changes:

- use `HomepageModuleBuilder` and admin import JSON when the current workspace exposes `homepage_modules`, `homepage_style`, `homepage-modules/import`, and `resources/views/site/partials/homepage-modules.blade.php`
- use `homepageCarouselSlides` for hero images, image bands, and slide-driven CTAs
- use `hotArticles` for trending, priority, announcement, or momentum modules
- use `featuredArticles` as case studies, featured resources, or solution cards
- use `articles` and `cardSummaries` for latest resources, reading paths, and compact grids
- use collection counts, category names on loaded articles, article dates, and view counts when present for chart-lite visuals
- use site title/subtitle/description for brand statement, service positioning, and text modules
- use builder module types such as `hero`, `rich_text`, `image_band`, `metric_band`, `chart_band`, `feature_grid`, `article_collection`, `cta_band`, `lead_form` with an existing active `lead_form_slug`, and sanitized `custom_html`

Requires explicit system-change scope:

- editable homepage modules in the admin when the current GEOFlow workspace does not already expose `HomepageModuleBuilder` and homepage module routes
- new database-backed content sections
- customer logo libraries, testimonial records, product catalogs, new lead-form records or activation, pricing tables, or real analytics widgets
- new routes, controllers, migrations, or background jobs

## Homepage Builder Import Boundary

Allowed after review:

- prepare `homepage-design.json` with canonical fields and route-safe links
- recommend `replace` or `append` mode
- ask the operator to import through the admin homepage module UI
- submit the import route only when the user explicitly approves the exact JSON payload and import mode

Not allowed during a design-only pass:

- silently applying a preset or import payload to the live site
- using `custom_html` for scripts, forms, iframes, event handlers, or unsupplied claims
- treating a builder JSON import as isolated preview when the current app renders it live
