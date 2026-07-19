<!--
Copyright © 2026 姚金刚. All rights reserved.
Project: geoflow
Created by: 姚金刚
Date: 2026-05-16
X: https://x.com/yaojingang
-->

# Theme Edit Workflow

Use this workflow when the request is to adjust a theme that already exists in a GEOFlow workspace.

## System Signals To Detect

The current GEOFlow theme system is considered present when the workspace contains:

- `artisan`
- `routes/web.php`
- `resources/views/site`
- `resources/views/theme/<theme-id>/...`
- `app/Support/Site/SiteThemeViewResolver.php`
- `app/Support/Site/HomepageModuleBuilder.php` when the homepage builder exists
- `resources/views/site/partials/homepage-modules.blade.php` when homepage builder rendering exists
- theme selection in `resources/views/admin/site-settings/index.blade.php`
- homepage module save/preset/import routes in `routes/web.php` when the builder UI exists
- `site_settings.active_theme` storing the active theme ID
- `site_settings.homepage_modules` and `site_settings.homepage_style` storing homepage builder content when present

Legacy PHP workspaces may still use `/themes`, `includes/theme_preview.php`, and `theme-preview.php`; treat those as fallback only.

## Workflow States

### 1. Discover

- inspect `resources/views/theme` first, then legacy `/themes` only when Laravel signals are absent
- read each `manifest.json`
- collect editable files:
  - `home.blade.php`
  - `category.blade.php`
  - `article.blade.php`
  - `archive-index.blade.php`
  - `archive-month.blade.php`
  - `layout.blade.php`
  - `partials/*.blade.php`
  - `assets/theme.css`
  - `public/themes/<theme-id>/theme.css`
  - `public/themes/<theme-id>/theme.js` when already present
  - optional `tokens.json`
  - optional `mapping.json`
- note which themes already look like preview or edit-session forks
- detect whether the current system supports homepage builder JSON import and list supported module types, style tokens, presets, and import modes

Recommended helper:
- `scripts/discover_themes.py`

### 2. Select

- resolve `target_theme_id`
- if the user did not specify a theme, prefer the active theme when known
- if the active theme is unknown, present the discovered themes and ask the model to keep the selection explicit in its plan

### 3. Fork Preview Session

- do not edit the target theme live on the first pass
- create a preview fork under `resources/views/theme/<preview-theme-id>` for Laravel GEOFlow
- copy `public/themes/<base-theme-id>` to `public/themes/<preview-theme-id>` when runtime assets exist
- mark the fork clearly as preview or edit-session in `manifest.json`
- emit public route samples and clearly note whether the current app has isolated preview routes

Recommended helper:
- `scripts/prepare_theme_edit_session.py`

### 4. AI Edit Loop

Safe edit surface:

- Blade files inside the selected theme
- `assets/theme.css`
- `public/themes/<theme-id>/theme.css` and optional `theme.js` when the theme layout loads public runtime assets
- `manifest.json`
- optional tokens and mapping files

Fixed contracts during the edit loop:

- do not hard-code `/geo_admin`
- preserve SEO, canonical, and schema output
- preserve article rendered-HTML behavior and style tables/headings/lists/code blocks through CSS
- do not show filename-only image captions
- keep preview/activation separate
- preserve rich homepage modules only on the default homepage unless search/category states are intentionally redesigned

Typical requests:

- make the layout wider
- make titles bolder
- reduce card noise
- simplify metadata
- add a display module only when it can be built from existing GEOFlow data
- enrich the default homepage with hero/media, charts, metrics, text modules, service/category blocks, case/resource blocks, large visuals, or CTA bands while keeping search/category states clean

Homepage builder path:

- when `HomepageModuleBuilder` is available, create `homepage-design.json` for module/style changes that fit the builder
- include `replace` or `append` import mode guidance
- use builder-supported types before inventing Blade-only modules for the same section
- keep `custom_html` small, sanitized, and free of scripts/forms/iframes
- do not post the import route until the operator accepts the exact payload

### 5. Review Preview

- inspect preview URLs for home, category, article, and archive
- call out what changed, what remained fixed, and where risks still exist
- verify default homepage richness separately from search and category result states
- review `homepage-design.json` separately when the builder path is used, including style tokens, module order, links, article sources, and import mode
- keep iterating on the preview fork until the operator confirms

### 6. Finalize

After preview approval, choose one path:

- `publish_as_new_theme`
  - keep the preview fork as a new theme or rename it to a stable theme id
  - the admin theme picker can then discover it as a new template
- `replace_base_theme`
  - for Laravel, back up under `storage/app/private/geoflow-theme-backups`
  - for legacy PHP, pass `--backup-root` as a persistent parent outside the web workspace; the helper creates a dedicated mode-`0700` child and rejects temporary locations
  - replace the target theme from the confirmed preview fork
  - warn that the live site may change immediately if that target theme is active
- `activate_after_confirmation`
  - activation should happen only after review
  - prefer the admin site-settings flow unless the user explicitly wants direct system mutation
- `import_homepage_design_after_confirmation`
  - applies only when homepage builder import exists
  - import the reviewed `homepage-design.json` through the admin flow or route
  - warn that imported homepage modules can affect the live default homepage immediately

Recommended helper:
- `scripts/finalize_theme_edit_session.py`

## Safety Rules

- preview first, live later
- keep backups outside theme discovery and web-served roots
- finalize only while the base theme, preview theme, and matching public assets are under one exclusive edit session; pause concurrent editors, deployers, and finalizers
- the finalize helper uses an exclusive workspace lock and fails closed when another finalizer may still be running; inspect a stale lock before removing it
- require valid object-shaped `manifest.json` and consistent `edit-session.json` IDs before a live replacement
- reject symbolic links, named pipes, sockets, devices, and other non-regular entries in copied theme trees
- stage the view and public-asset trees before the first live rename; on commit failure, restore every live tree and report any cleanup path that remains
- do not add modules that require backend fields GEOFlow does not already expose
- do not invent corporate claims, logos, forms, testimonials, or chart data that the current view cannot support
- do not treat homepage builder import as a preview route; it is a settings change unless the operator has a separate staging workflow
- do not touch routing, search, SEO generation, or schema generation unless the user explicitly expands scope
