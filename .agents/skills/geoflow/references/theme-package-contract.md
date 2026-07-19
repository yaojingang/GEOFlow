<!--
Copyright © 2026 姚金刚. All rights reserved.
Project: geoflow
Created by: 姚金刚
Date: 2026-05-16
X: https://x.com/yaojingang
-->

# Theme Edit and Package Contract

The output of a GEOFlow design run should be a preview-first session or package rather than a direct production overwrite.

## Mode A: Full New Theme Package

```text
resources/views/theme/
  template-YYYYMMDD-XXX/
    manifest.json
    tokens.json
    mapping.json
    assets/
      theme.css
      preview.css
    layout.blade.php
    home.blade.php
    category.blade.php
    article.blade.php
    archive-index.blade.php
    archive-month.blade.php
    partials/
      header.blade.php
      footer.blade.php
      article-card.blade.php

public/themes/template-YYYYMMDD-XXX/
  theme.css
  theme.js
```

## Mode B: Preview Theme Edit Session

```text
resources/views/theme/
  target-theme-edit-YYYYMMDD-XXX/
    manifest.json
    edit-session.json
    design-audit.md
    tokens.delta.json
    mapping.delta.json
    change-plan.md
    preview-notes.md
    assets/
      theme.css
      preview.css
    home.blade.php
    category.blade.php
    article.blade.php
    archive-index.blade.php
    archive-month.blade.php
    partials/
      header.blade.php
      footer.blade.php
      article-card.blade.php

public/themes/target-theme-edit-YYYYMMDD-XXX/
  theme.css
  theme.js
```

## Mode C: Homepage Builder Design JSON

Use this mode when the current GEOFlow workspace exposes `HomepageModuleBuilder`, `homepage_modules`, `homepage_style`, and the admin import route.

```text
homepage-composition-plan.md
homepage-design.json
preview-notes.md
```

Preferred JSON shape:

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
      "data_source": "latest",
      "enabled": true,
      "sort_order": 10,
      "title": "Enterprise GEO Hub",
      "subtitle": "Knowledge workflow",
      "body": "Organize homepage content with custom modules.",
      "image_url": "",
      "link_text": "View resources",
      "link_url": "/archive",
      "lead_form_slug": "",
      "limit": 4,
      "custom_html": "",
      "alignment": "left"
    }
  ]
}
```

For this mode, include:

- import mode: `replace` or `append`
- module type, layout, source, and fallback notes
- route safety for every `link_url` and `image_url`
- an existing active `lead_form_slug` for each `lead_form` module, with form creation or activation kept in an authenticated operations phase
- `custom_html` safety note when used
- warning that import can affect the live default homepage unless applied in staging

## Minimum Manifest Fields

- `id`
- `name`
- `mode`
- `created_at`
- `compatible_pages`
- `compatible_modules`
- `preview_routes`
- `notes`
- Optional:
  - `source_reference_url`
  - `base_template_id`
  - `target_theme_id`
  - `optimization_goals`
  - `change_scope`
  - `session_state`

Recommended Laravel fields:

- `framework`: `laravel`
- `geoflow_contract`: `site-theme-view-resolver`
- `requires_admin_activation`: `true` when no isolated preview route exists
- `blade_templates`: list of provided Blade templates
- `fallback_templates`: list of templates expected to fall back to `resources/views/site`

## Minimum Edit-Session Fields

- `base_theme_id`
- `preview_theme_id`
- `public_assets_dir` when runtime CSS/JS has been forked
- `created_at`
- `change_request`
- `preview_routes`
- `finalize_options`

## Minimum Mapping Output

- `header`
- `footer`
- `home.hero`
- `home.hero_carousel`
- `home.hero_media`
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
- `home.metric_band`
- `home.chart_lite`
- `home.text_value_block`
- `home.service_or_topic_grid`
- `home.case_or_resource_grid`
- `home.hot_articles`
- `home.featured_list`
- `home.cta_band`
- `home.article_card`
- `category.article_card`
- `article.hero`
- `article.prose_shell`
- `article.related_articles`
- `article.sticky_ad`
- `archive.overview_row`
- `archive.article_card`
- When `mode = edit_theme` or `mode = optimize`:
  - `change_scope`
  - `unchanged_contracts`
  - `delta_strategy`
  - `edited_files`

## Finalize Paths

- `publish_as_new_theme`: keep or rename the preview fork so admin theme discovery can list it as a new template
- `replace_base_theme`: back up the original target theme and then replace it from the confirmed preview fork
- `activate_after_confirmation`: activate only after the operator confirms the reviewed preview session
- `import_homepage_design_after_confirmation`: import the reviewed homepage builder payload only after the operator confirms the exact JSON and mode

## Preview Contract

The package should be previewed on at least:

- homepage preview
- category preview
- article detail preview
- archive overview preview

Current Laravel GEOFlow may not provide isolated `/preview/{theme}` URLs. Preview theme edit sessions should clearly say whether review uses static preview artifacts, temporary admin activation, or a real preview route discovered in `routes/web.php`.

Optimization runs should also include a short before/after rationale for each touched module.

Homepage enrichment runs should also include:

- `homepage-composition-plan.md` or an equivalent section in `change-plan.md`
- `homepage-design.json` when the current system supports homepage builder import
- each added homepage module's data source
- default, empty, search, category, and mobile behavior
- whether CSS/JS is edited in `resources/views/theme/{theme_id}/assets` or `public/themes/{theme_id}`
- whether module/style work is delivered through theme files or homepage builder import JSON
- the `replace` or `append` import mode when builder JSON is used
- any desired module that requires backend work instead of theme-only work

Preview must be isolated from the active public template until the operator confirms replacement, publish-as-new, or activation.

## Non-Negotiable Rendering Rules

- keep layout-level title, description, keyword, canonical, and schema slots intact
- do not render raw markdown markers in list excerpts or article bodies
- do not display image captions when the caption is only a filename
- do not hard-code admin URLs
- do not change controllers, database queries, route definitions, or markdown rendering services in a design-only package
