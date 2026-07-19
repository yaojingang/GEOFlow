<!--
Copyright © 2026 姚金刚. All rights reserved.
Project: geoflow
Created by: 姚金刚
Date: 2026-06-23
X: https://x.com/yaojingang
-->

# Homepage Composition Guide

Use this reference when a GEOFlow theme request asks for a fuller homepage, a corporate-site feel, a portal-style front page, or modules beyond a basic article list.

## Goal

Turn the GEOFlow homepage from a simple feed into a composed front page while preserving the Laravel theme contract. The homepage may feel like a company website, resource hub, product newsroom, or media portal, but it must still render from existing site settings, article collections, route helpers, and theme assets.

## Current Data Surface

Safe homepage inputs include:

- `siteTitle`, `siteSubtitle`, `siteDescription`, `siteKeywords`
- `homepageCarouselSlides`: up to three enabled slides with `image_url`, `title`, and `link_url`
- `homepageModules`: enabled module records from `site_settings.homepage_modules`
- `homepageStyle`: global homepage style tokens from `site_settings.homepage_style`
- `showHomepageModules`: the controller guard for default first-page homepage rendering
- `featuredArticles`: curated lead or case-study style entries
- `hotArticles`: momentum, popular, or alert-style entries
- `articles`: latest published article paginator
- `cardSummaries`: safe text summaries keyed by article id
- `search`, `category`, `categoryMissing`, `viewTitle`, `pageDescription`, `canonicalUrl`
- article fields already present on loaded items, such as title, slug, excerpt, category, author, published date, cover/image fields when available, and view count when present

Do not query the database from a Blade theme to invent more homepage data. If a module needs data that is not already passed to the view, mark it as a backend/system-change requirement instead of silently hard-coding it.

## Homepage Builder Mode

Current GEOFlow may expose `HomepageModuleBuilder`, `homepage_modules`, `homepage_style`, and the admin import route `homepage-modules/import`. When those signals are present, prefer an importable `homepage-design.json` payload for homepage module and style work. Use Blade/theme edits only when the requested visual treatment cannot be represented by the builder or when the selected theme intentionally overrides the default partial.

Builder style fields:

- colors: `accent_color`, `background_color`, `surface_color`, `text_color`, `muted_color`
- layout tokens: `container_width` (`narrow`, `default`, `wide`), `section_spacing` (`compact`, `normal`, `relaxed`), `radius` (`none`, `soft`, `round`)

Builder module fields:

- identity/order: `id`, `enabled`, `sort_order`
- behavior: `type`, `layout`, `data_source`, `limit`, `alignment`
- content: `title`, `subtitle`, `body`, `image_url`, `link_text`, `link_url`, `lead_form_slug`, `custom_html`
- module style: `accent_color`, `surface_color`, `text_color`, `muted_color`

Builder module types:

- `hero`: headline, body, CTA, optional image
- `rich_text`: plain explanatory content
- `image_band`: large visual section with optional CTA
- `metric_band`: row values written as `Label|Value|Note`
- `chart_band`: CSS bar chart rows written as `Label|Value|Note`
- `feature_grid`: feature rows written as `Title|Description|URL`
- `article_collection`: articles from `featured`, `hot`, or `latest`
- `cta_band`: conversion/navigation block pointing to an existing route
- `lead_form`: an existing active lead form referenced by canonical top-level `lead_form_slug`; creating or activating the form is a separate authenticated operation
- `custom_html`: sanitized HTML for simple structured copy only

Supported layouts are `single`, `split`, `grid`, and `compact`. Supported alignments are `left` and `center`. Article collection sources are `featured`, `hot`, and `latest`.

Preferred import shape:

```json
{
  "style": {
    "accent_color": "#2563eb",
    "background_color": "#ffffff",
    "surface_color": "#ffffff",
    "text_color": "#111827",
    "muted_color": "#6b7280",
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
      "subtitle": "Knowledge workflow",
      "body": "Organize value, proof, resources, and next actions on one homepage.",
      "link_text": "View resources",
      "link_url": "/archive"
    },
    {
      "type": "article_collection",
      "layout": "grid",
      "data_source": "featured",
      "enabled": true,
      "sort_order": 40,
      "title": "Featured resources",
      "limit": 6
    }
  ]
}
```

Use `replace` when the request is to redesign the homepage module stack. Use `append` only when the operator wants to keep existing modules and add new ones. Never submit the import route until the operator has reviewed the exact JSON and mode.

## Module Families

### Hero And Media

- `home.hero_statement`: site title, subtitle, description, primary route CTA
- `home.hero_article`: lead featured/latest article with summary and read-more CTA
- `home.hero_carousel`: `homepageCarouselSlides` as image-led carousel or stacked feature panels
- `home.hero_split`: text/value proposition plus lead article or slide visual

### Text And Value Blocks

- `home.value_statement`: short site-description driven positioning block
- `home.feature_grid`: 3-6 text cards derived from categories, featured article themes, or static theme copy
- `home.process_steps`: static theme copy for methodology/process when the user asks for enterprise or service positioning
- `home.faq_teaser`: static theme copy plus links to relevant articles only when the current theme owns the copy

### Metrics And Chart-Lite Blocks

Use lightweight HTML/CSS charts instead of adding a chart dependency by default.

- `home.metric_band`: counts from current collections, such as featured count, hot count, latest count
- `home.topic_distribution`: bars grouped by category names from the loaded articles
- `home.momentum_chart`: timeline or bars from article published dates
- `home.view_heat`: bars from article view counts when the field is present
- `home.comparison_matrix`: static theme copy for service/plan/comparison-style enterprise pages

### Article-Derived Portal Blocks

- `home.featured_cases`: featured articles presented as case studies or solutions
- `home.hot_alerts`: hot articles as breaking/trending/priority resources
- `home.latest_resources`: latest articles with compact cards or grouped rows
- `home.topic_rail`: category or topic chips linking to public category routes when category data exists
- `home.resource_tabs`: visual grouping of current article collections; avoid JS-heavy tabs unless the current theme already has safe script support

### Visual And CTA Blocks

- `home.image_band`: carousel slide images or article images as large visual panels
- `home.logo_strip`: text-only trust strip unless real logo data is passed by the system
- `home.cta_band`: route-safe CTA to search, latest articles, archive, or a selected article
- `home.newsletter_like`: visual subscription-style block only if it links to an existing route or remains non-functional copy; do not create fake forms that imply backend support

## Composition Patterns

### Corporate Portal

Recommended order:

1. Hero statement or carousel
2. Metric band or chart-lite proof block
3. Feature/service grid
4. Featured cases from `featuredArticles`
5. Latest resources from `articles`
6. CTA band and footer reinforcement

### Content Hub

Recommended order:

1. Lead article hero
2. Hot article rail
3. Topic/category entrances
4. Featured article grid
5. Latest article feed
6. Archive/search CTA

### Knowledge Hub

Recommended order:

1. Text-led hero
2. Reading-path cards
3. Topic distribution chart
4. Featured resources
5. Latest resources
6. Search/archive CTA

### Homepage Builder Corporate Portal

Recommended builder order:

1. `hero` with `split` layout
2. `metric_band` or `chart_band`
3. `feature_grid`
4. `article_collection` from `featured`
5. `article_collection` from `latest`
6. `cta_band`

## Design Rules

- Homepage-first modules should have clear section rhythm, not one long list.
- Use visual variety: one hero, one data/proof section, one text/value section, one article-derived section, one CTA or navigation section.
- Preserve list/search/category states. Rich homepage modules should usually render only for the default homepage: `search === ''`, no category, no missing category, first page.
- Preserve `showHomepageModules` behavior when the system provides it.
- Search and category views should remain task-focused and should not show corporate filler modules above their result context.
- Avoid fake dashboards. A chart must be explainable from current collection data or clearly be a static theme illustration.
- Avoid fake forms, fake testimonials, fake logos, and fake customer claims unless the user supplies that content.
- Keep module copy editable in the theme and avoid hard-coded company claims that look like factual business data.
- Prefer CSS bars, grids, timelines, badges, and simple SVG decoration over new JS dependencies.
- Keep `custom_html` small and reviewable. Use only sanitized structural tags (`p`, lists, headings, blockquote, links, simple containers); do not rely on scripts, inline event handlers, forms, iframes, or images inside `custom_html`.

## Output Requirements

For homepage enrichment, include:

- `homepage-composition-plan.md` or a section in `change-plan.md`
- `homepage-design.json` when the homepage module builder is available
- `mapping.json` keys for every added homepage module
- explicit data source per module
- fallback behavior when collections are empty
- import mode recommendation: `replace` or `append`
- preview notes for desktop and mobile homepage states
- a statement of modules that would require backend data if the user wants them to become real dynamic content
