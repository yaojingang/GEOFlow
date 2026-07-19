<!--
Copyright © 2026 姚金刚. All rights reserved.
Project: geoflow
Created by: 姚金刚
Date: 2026-05-16
X: https://x.com/yaojingang
-->

# GEOFlow Frontend Module And Variable Map

This document is the current GEOFlow frontend contract for `geoflow`. It is based on the Laravel rewrite, not the older root-level PHP frontend.

## 1. Current Workspace Baseline

Current GEOFlow signals:

- Laravel entry: `artisan`
- Public routes: `routes/web.php`
- Site controllers: `app/Http/Controllers/Site/*`
- Theme resolver: `app/Support/Site/SiteThemeViewResolver.php`
- Homepage module builder when present: `app/Support/Site/HomepageModuleBuilder.php`
- Built-in frontend views: `resources/views/site`
- Built-in homepage module partial when present: `resources/views/site/partials/homepage-modules.blade.php`
- Selectable themes: `resources/views/theme/{theme_id}`
- Admin theme selection: `resources/views/admin/site-settings/index.blade.php`
- Admin page theme editor when present: `app/Http/Controllers/Admin/SiteThemeEditorController.php`
- Theme editor service when present: `app/Services/Admin/SiteThemeEditorService.php`
- Public theme assets when present: `public/themes/{theme_id}/theme.css` and optional `theme.js`

Legacy PHP signals such as root `index.php`, `article.php`, `category.php`, `archive.php`, and `/themes` are fallback-only.

## 2. Route Contract

Do not change these public routes during a design-only run:

- `/`
- `/article/{slug}`
- `/category/{slug}`
- `/archive`
- `/archive/{year}/{month}`
- `/forms/{slug}`
- `/forms/{slug}/submissions`

Admin route base may be customized through `config/geoflow.php`; never hard-code `/geo_admin` in theme output.
Themes should not add admin links unless the current application passes such links explicitly.

Current homepage-module admin routes, when present under the configurable admin prefix:

- `admin.site-settings.homepage-modules`: save `homepage_modules` and `homepage_style`
- `admin.site-settings.homepage-modules.preset`: apply a built-in preset in `replace` or `append` mode
- `admin.site-settings.homepage-modules.import`: import reviewed homepage design JSON in `replace` or `append` mode

Do not call these routes automatically. Treat import or preset application as a finalize step that needs operator review of the exact payload and mode.

## 3. Theme Resolution Contract

`SiteThemeViewResolver::first($template, $data)` resolves:

```text
theme.{active_theme}.{template}
site.{template}
```

If a theme does not provide a Blade view, GEOFlow falls back to `resources/views/site`.

Allowed theme ID pattern:

```text
^[a-zA-Z0-9_-]+$
```

## 4. Theme Package Files

Current theme root:

```text
resources/views/theme/{theme_id}/
```

Recommended files:

```text
manifest.json
tokens.json
mapping.json
home.blade.php
article.blade.php
category.blade.php
archive-index.blade.php
archive-month.blade.php
layout.blade.php
partials/header.blade.php
partials/footer.blade.php
partials/article-card.blade.php
assets/theme.css
```

Only `manifest.json` is required for discovery. Missing Blade views fall back to built-in `site.*`.

Current runtime themes may also load CSS/JS from:

```text
public/themes/{theme_id}/theme.css
public/themes/{theme_id}/theme.js
```

When both `resources/views/theme/{theme_id}/assets/theme.css` and `public/themes/{theme_id}/theme.css` exist, inspect the current layout before deciding which file is authoritative for runtime styling.

## 5. Built-In Frontend Views

Stable built-in views:

- `resources/views/site/layout.blade.php`
- `resources/views/site/home.blade.php`
- `resources/views/site/article.blade.php`
- `resources/views/site/category.blade.php`
- `resources/views/site/archive-index.blade.php`
- `resources/views/site/archive-month.blade.php`
- `resources/views/site/partials/header.blade.php`
- `resources/views/site/partials/footer.blade.php`
- `resources/views/site/partials/article-card.blade.php`
- `resources/views/site/partials/lead-form.blade.php`

## 6. Shared Layout Modules

### `layout`

Owns:

- HTML shell
- page title and meta description
- canonical URL when provided
- JSON-LD / schema blocks when provided
- header / content / footer slots

Do not remove SEO, canonical, or schema output.

### `partials.header`

Owns:

- site name / logo
- Home link
- category navigation
- archive link when present
- responsive navigation

Inputs are controller-provided site settings and category collections. Do not query the database directly in a theme.

### `partials.footer`

Owns:

- public copyright line
- footer links and minimal public metadata

Keep it public-facing. Admin copyright/version UI is separate.

## 7. Home Page Modules

View: `home`

Stable modules:

- `home.hero`
- `home.hero_carousel`
- `home.hot_articles`
- `home.featured_articles`
- `home.latest_articles`
- `home.article_card`
- `home.rich_text_block`
- `home.metric_band`
- `home.chart_lite`
- `home.builder.hero`
- `home.builder.rich_text`
- `home.builder.image_band`
- `home.builder.metric_band`
- `home.builder.chart_band`
- `home.builder.feature_grid`
- `home.builder.article_collection`
- `home.builder.cta_band`
- `home.builder.lead_form`
- `home.builder.custom_html`
- `home.visual_band`
- `home.cta_band`
- `home.empty_state`
- `home.pagination`

Typical data:

- site settings
- categories
- homepage carousel slides
- homepage module records
- homepage style tokens
- active lead forms keyed by slug
- featured articles
- hot articles
- paginated latest articles
- article summaries
- pagination metadata

Current upgrade signals:

- `homepageCarouselSlides`: up to three enabled slides from site settings; fields are `image_url`, `title`, and `link_url`.
- `homepageModules`: enabled homepage module records normalized from `site_settings.homepage_modules`.
- `homepageStyle`: normalized global homepage style tokens from `site_settings.homepage_style`.
- `leadFormsBySlug`: active lead forms available to default-site homepage modules when the growth-center tables exist.
- `showHomepageModules`: true only for the default first homepage state: no search, no category, no missing category, and page 1.
- `hotArticles`: published articles marked hot, normally available only on the default first homepage.
- `featuredArticles`: published featured articles, normally available only on the default first homepage.
- `cardSummaries`: safe article card summaries keyed by article id.

Homepage enrichment may compose fuller front pages from these inputs without backend changes. Safe examples include large hero images from carousel slides, hot-article rails, KPI cards based on current collection counts, chart-lite bars based on loaded article categories or view counts, text/value modules from site copy, homepage builder modules, and CTA sections pointing to public routes.

Do not make rich homepage modules appear above search results, category filtering, or category-missing states unless the user explicitly asks for that behavior.

## 7.1 Homepage Module Builder Contract

When `HomepageModuleBuilder` exists, the system can store and render configurable homepage modules through site settings instead of requiring Blade-only customization.

Style fields:

- `accent_color`, `background_color`, `surface_color`, `text_color`, `muted_color`
- `container_width`: `narrow`, `default`, `wide`
- `section_spacing`: `compact`, `normal`, `relaxed`
- `radius`: `none`, `soft`, `round`

Module fields:

- `id`, `type`, `layout`, `data_source`, `enabled`, `sort_order`
- `title`, `subtitle`, `body`, `image_url`, `link_text`, `link_url`, `limit`
- `custom_html`
- `lead_form_slug`
- `accent_color`, `surface_color`, `text_color`, `muted_color`, `alignment`

Supported module types:

- `hero`
- `rich_text`
- `image_band`
- `metric_band`
- `chart_band`
- `feature_grid`
- `article_collection`
- `cta_band`
- `lead_form`
- `custom_html`

Supported layouts are `single`, `split`, `grid`, and `compact`. Supported article sources are `featured`, `hot`, and `latest`. Supported alignments are `left` and `center`. Current validation caps modules at `HomepageModuleBuilder::MAX_MODULES`, normally `30`, and caps article collection limits at `12`.

`lead_form` is a default-site homepage module type. It renders `resources/views/site/partials/lead-form.blade.php` and requires a canonical top-level `lead_form_slug` that points to an existing active lead form. Imported design drafts may use aliases such as `form_slug`, `lead_form`, `form`, or `conversion_form`, but skill-authored payloads should output `lead_form_slug` explicitly.

The `public_frontend` mode does not create lead forms. If no active form slug is available, use a `cta_band` that links to the intended public form URL or switch to `operations` for authenticated lead-form creation.

Default-site supported modules and remote target-package supported modules are separate contracts. The current default site can support `lead_form`; the current managed GeoFlow Agent target package may not expose `lead_form` in `/geoflow-agent/v1/frontend-capabilities`. When remote support is missing, downgrade the channel payload to CTA/link or require target package upgrade before sync.

Built-in presets, when present:

- `enterprise_brand`
- `content_portal`
- `service_solution`
- `report_hub`
- `product_launch`

Agent output can be imported as JSON through `homepage-modules/import`. Preferred top-level shape:

```json
{
  "style": {
    "accent_color": "#2563eb",
    "container_width": "wide",
    "section_spacing": "relaxed",
    "radius": "soft"
  },
  "modules": [
    {
      "type": "hero",
      "layout": "split",
      "enabled": true,
      "sort_order": 10,
      "title": "Enterprise GEO Hub",
      "body": "Use homepage modules to present value, proof, resources, and next actions.",
      "link_text": "View resources",
      "link_url": "/archive"
    },
    {
      "type": "lead_form",
      "layout": "single",
      "enabled": true,
      "sort_order": 60,
      "title": "Request a GEO consultation",
      "body": "Use an existing active lead form; this payload only references the slug.",
      "lead_form_slug": "geo-consultation"
    }
  ]
}
```

The importer also accepts common aliases such as `sections`, `blocks`, `style_tokens`, `kind`, `headline`, `copy`, `cta_label`, and `cta_url`. Still prefer the canonical field names above so reviews are explicit.

## 8. Category Page Modules

View: `category`

Stable modules:

- `category.header`
- `category.description`
- `category.article_list`
- `category.article_card`
- `category.pagination`
- `category.empty_state`

Typical data:

- category
- articles
- pagination metadata

## 9. Article Page Modules

View: `article`

Stable modules:

- `article.header`
- `article.cover_image`
- `article.meta`
- `article.prose`
- `article.tags`
- `article.related_articles`
- `article.ad_slot`

Typical data:

- article
- author
- category
- tags
- related articles
- rendered article HTML
- article images
- article detail ad
- canonical URL and structured data

Important rendering rule: do not show image captions that are only filenames such as `333.png`; keep article images visual unless the system provides meaningful captions.
Important markdown rule: article content is passed to the theme as rendered HTML. The theme should style standard HTML nodes such as `h2`, `h3`, `p`, `ul`, `ol`, `blockquote`, `table`, `pre`, and `code`; it should not expose raw markdown markers in list cards or article detail views.

## 10. Archive Modules

Views: `archive-index`, `archive-month`

Stable modules:

- `archive.overview`
- `archive.month_group`
- `archive.article_list`
- `archive.article_card`
- `archive.pagination`

Typical data:

- archive months
- selected year/month
- articles
- pagination metadata

## 11. Safe Editing Surface

Safe in theme packages:

- Blade markup inside existing page/module boundaries
- CSS tokens, spacing, shadows, colors, borders, typography, responsive behavior
- card layouts and metadata presentation
- homepage composition modules that derive from current view variables or clearly static theme copy
- header/footer presentation
- ad slot presentation
- `manifest.json`, `tokens.json`, `mapping.json`
- public theme CSS/JS files when the current layout loads `asset('themes/{theme_id}/...')`

Unsafe without explicit system-change approval:

- controllers
- models
- migrations
- routing
- markdown renderer
- SEO/schema generation
- database queries
- admin theme activation
- admin base path behavior
- multilingual persistence behavior
- dynamic corporate data such as customers, logos, testimonials, products, plans, or analytics unless GEOFlow already passes it to the view or the user explicitly expands scope

## 12. Preview Notes

Current Laravel GEOFlow does not guarantee an isolated `/preview/{theme}` route. A design run should either:

- generate static preview artifacts for review, or
- create a preview theme under `resources/views/theme` and ask the operator to activate it only after review, or
- use the admin theme editor preview when the current app exposes `SiteThemeEditorController`, or
- use a dedicated preview route only when the current app actually provides one.

Never invent preview URLs from the legacy PHP system.
