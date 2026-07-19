<!--
Copyright © 2026 姚金刚. All rights reserved.
Project: geoflow
Created by: 姚金刚
Date: 2026-05-16
X: https://x.com/yaojingang
-->

# Laravel Theme Contract

This contract supersedes the legacy PHP-entrypoint assumptions for the current GEOFlow rewrite.

## Workspace Detection

A current GEOFlow workspace is detected by these files and directories:

- `artisan`
- `routes/web.php`
- `app/Support/Site/SiteThemeViewResolver.php`
- `app/Support/Site/HomepageModuleBuilder.php` when homepage builder support is available
- `resources/views/site`
- `resources/views/site/partials/homepage-modules.blade.php` when homepage builder rendering is available
- `resources/views/theme`
- `resources/views/admin/site-settings/index.blade.php`

Legacy GEOFlow workspaces may still contain root `index.php`, `article.php`, `category.php`, `archive.php`, and `/themes`. Support them only as fallback.

## Runtime Theme Root

Current theme packages live under:

```text
resources/views/theme/{theme_id}/
```

The default built-in frontend lives under:

```text
resources/views/site/
```

Current runtime assets commonly live under:

```text
public/themes/{theme_id}/theme.css
public/themes/{theme_id}/theme.js
```

Older or draft packages may also contain `resources/views/theme/{theme_id}/assets/theme.css`. Inspect the current `layout.blade.php` before deciding which CSS/JS files must be edited.

`SiteThemeViewResolver::first($template, $data)` resolves:

```text
theme.{active_theme}.{template}
site.{template}
```

Theme IDs must match:

```text
^[a-zA-Z0-9_-]+$
```

The active theme is stored in site settings as `active_theme`; when empty, GEOFlow renders the built-in `resources/views/site` views.

The admin base path is configurable. Do not hard-code `/geo_admin` in theme packages, previews, docs, or generated links.

## Theme Package Shape

Minimal package:

```text
resources/views/theme/{theme_id}/
  manifest.json
```

Typical package:

```text
resources/views/theme/{theme_id}/
  manifest.json
  tokens.json
  mapping.json
  home.blade.php
  article.blade.php
  category.blade.php
  archive-index.blade.php
  archive-month.blade.php
  layout.blade.php
  partials/
    header.blade.php
    footer.blade.php
    article-card.blade.php
  assets/
    theme.css

public/themes/{theme_id}/
  theme.css
  theme.js
```

Any missing Blade template automatically falls back to `resources/views/site`.
Admin theme discovery can list a directory when it has a valid `manifest.json` or at least a `home.blade.php`, but a real theme package should include `manifest.json`.

## Homepage Builder Contract

Current GEOFlow can expose a configurable homepage builder in addition to Blade theme packages. The builder stores:

```text
site_settings.homepage_modules
site_settings.homepage_style
```

The site `HomeController` may pass these variables into the home view:

```text
homepageModules
homepageStyle
showHomepageModules
```

The built-in renderer is:

```text
resources/views/site/partials/homepage-modules.blade.php
```

Supported builder module types are:

```text
hero
rich_text
image_band
metric_band
chart_band
feature_grid
article_collection
cta_band
lead_form
custom_html
```

`lead_form` requires a canonical top-level `lead_form_slug` for an existing active form. A theme or homepage design payload may reference that slug; form creation and activation remain authenticated operations.

Supported style tokens are:

```text
accent_color
background_color
surface_color
text_color
muted_color
container_width
section_spacing
radius
```

The admin settings page may expose save, preset, and import routes for these settings. A design run may prepare `homepage-design.json`, but it should not apply it directly unless the operator confirms the exact JSON and `replace` or `append` mode.

## Editable Files

Safe edits:

- `manifest.json`
- `tokens.json`
- `mapping.json`
- root `*.blade.php`
- `partials/*.blade.php`
- `assets/theme.css`
- `public/themes/{theme_id}/theme.css`
- `public/themes/{theme_id}/theme.js` when the current theme already uses it
- homepage design artifacts such as `homepage-design.json` and `homepage-composition-plan.md`
- design notes such as `edit-session.json`, `change-plan.md`, `preview-notes.md`

Do not edit controllers, models, migrations, routes, or database queries for a design-only run.

## Page Templates

Stable template names:

- `home`
- `article`
- `category`
- `archive-index`
- `archive-month`
- `layout`
- `partials.header`
- `partials.footer`
- `partials.article-card`

Stable public routes:

- `/`
- `/article/{slug}`
- `/category/{slug}`
- `/archive`
- `/archive/{year}/{month}`

Stable public data/rendering expectations:

- page title, meta description, keywords, canonical URL, and JSON-LD/schema blocks must remain in the layout flow
- article body content is already rendered HTML; do not re-escape it as plain markdown
- image captions that are only filenames such as `333.png` should not be displayed
- frontend language follows system/site behavior; do not add an independent public language selector unless the system contract changes
- footer copyright should remain public-facing and centered/minimal unless a theme explicitly owns footer layout
- default homepage can use `homepageCarouselSlides`, `featuredArticles`, `hotArticles`, `articles`, and `cardSummaries` to compose richer portal or corporate sections
- when the homepage builder exists, default homepage can also use `homepageModules`, `homepageStyle`, and `showHomepageModules`
- search/category/category-missing homepage states must keep clear result context and should not inherit every default-homepage marketing module by accident

## Preview Reality

Current GEOFlow does not expose an isolated `/preview/{theme}` runtime route by default. Treat preview as one of these:

- static preview files generated in `public_frontend`, or
- a preview theme package that appears in Admin -> Site Settings and is activated only after operator confirmation, or
- the admin theme editor preview when `SiteThemeEditorController` and `SiteThemeEditorService` exist, or
- a future dedicated preview route if the application adds one.

Never claim a live preview route exists until `routes/web.php` confirms it.
