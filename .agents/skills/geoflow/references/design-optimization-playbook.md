<!--
Copyright © 2026 姚金刚. All rights reserved.
Project: geoflow
Created by: 姚金刚
Date: 2026-05-16
X: https://x.com/yaojingang
-->

# Design Optimization Playbook

Use this reference when the request is to improve an existing GEOFlow theme rather than replace it wholesale.

## Optimization Modes

- `polish`: tighten spacing, typography, chips, borders, shadows, and visual rhythm
- `structural_cleanup`: simplify noisy sections, unify repeated cards, and reduce layout inconsistency
- `page_specific_tuning`: improve one or two pages such as homepage or article detail without redesigning the full system
- `homepage_enrichment`: turn the default homepage into a richer front page with hero/media, metrics/chart-lite, text/value blocks, visual CTAs, and article-derived resource/case sections
- `homepage_builder_design`: produce importable `homepage-design.json` for `homepage_modules` and `homepage_style` when the current GEOFlow system exposes the builder/import contract
- `hybrid_restyle`: use a reference site as direction while staying anchored to the current template
- `target_theme_edit`: apply the changes inside a selected theme fork that can be previewed in the live GEOFlow system

## Default Audit Checklist

- header density and navigation clarity
- hero hierarchy and section rhythm
- card consistency across home/category/archive
- article readability, metadata treatment, and related-content treatment
- ad block tone and how aggressive it feels
- mobile spacing, stacking, and overflow risk
- repeated token drift across colors, radii, borders, and shadows
- whether the requested new display modules can be built from existing GEOFlow data fields
- whether default homepage modules stay out of search/category result states
- whether charts and metrics are derived from current collections or clearly marked as static theme illustrations
- whether homepage module/style requests fit the current `HomepageModuleBuilder` contract before editing Blade

## Preferred Outputs

- `design-audit.md`: current-template issues, constraints, and opportunities
- `tokens.delta.json`: only the token changes needed for the optimization pass
- `mapping.delta.json`: touched modules and what changes inside each one
- `homepage-composition-plan.md`: module order, data source, fallback behavior, and desktop/mobile preview notes when homepage enrichment is in scope
- `homepage-design.json`: importable builder payload when the current system supports homepage modules and custom homepage styles
- `change-plan.md`: priority order, rollout notes, and preview focus
- preview pages or preview notes for every touched route
- a preview theme id and preview URLs when the edit runs against an existing target theme

## Guardrails

- preserve GEOFlow routes, helpers, and data queries
- optimize modules that already exist before inventing new ones
- when inventing homepage modules, prefer safe composition from current variables over backend expansion
- when the homepage builder exists, prefer builder module JSON for supported module/style changes before adding Blade-only sections
- use CSS-only chart and metric treatments unless the current theme already has a safe script path
- keep `custom_html` sanitized and reviewable; do not use it for scripts, forms, iframes, or unsupplied claims
- prefer shared card and metadata patterns over one-off page styling
- when the current template is acceptable structurally, bias toward token cleanup and hierarchy fixes instead of large rewrites
- default to preview-fork editing instead of live-theme editing on the first pass
