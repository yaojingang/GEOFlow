# Frontend Theme Preview and Activation

GEOFlow now supports preview-first frontend themes driven by theme packages.

This feature is intentionally scoped to:

- keep the existing homepage, category, article, and archive data contracts
- preserve current SEO, Open Graph, and structured-data output rules
- preserve the current routing and query logic
- replace only the presentation layer, template shell, and visual modules

## Current Capabilities

### 1. Theme Package Directory

Themes live under:

```text
themes/<theme-id>/
```

Current sample theme:

```text
themes/qiaomu-editorial-20260418/
├── manifest.json
├── tokens.json
├── mapping.json
├── assets/
│   └── theme.css
└── templates/
    ├── header.php
    ├── footer.php
    ├── home.php
    ├── category.php
    ├── article.php
    └── archive.php
```

### 2. Dynamic Preview Routes

Themes can be previewed without replacing the live frontend.

Preview route patterns:

```text
/preview/<theme-id>/
/preview/<theme-id>/category/<slug>
/preview/<theme-id>/article/<slug>
/preview/<theme-id>/archive
```

Preview routes render real database-backed content instead of static mock data.

### 3. Admin Activation

The `Site Settings` page now includes a `Site Templates` section that can:

- show the current active theme
- preview home / category / article / archive pages
- switch back to the default frontend
- activate a theme package

## SEO and Multilingual Rules

Theme switching does not change GEOFlow's existing SEO contract.

The following continue to use the system's current logic:

- `title`
- `description`
- `keywords`
- canonical URL
- Open Graph
- JSON-LD / structured data

Both preview mode and live theme activation reuse the system-generated SEO output.

For multilingual behavior:

- the frontend continues to follow the current `lang` parameter or session locale
- the theme controls presentation only; it does not override language logic
- the same theme package can be reused under `zh-CN`, `en`, or other interface languages

## Homepage Description Rules

Homepage, category, and archive list cards now use a display-safe summary layer:

- Markdown heading markers such as `#` and `##` are stripped
- bold markers such as `**` are stripped
- list and link artifacts are cleaned before rendering

This keeps card summaries readable instead of exposing raw Markdown fragments.

## Companion Skill

To turn a reference URL into a GEOFlow-compatible preview theme package, use:

- Skill repository: [yaojingang/yao-geo-skills](https://github.com/yaojingang/yao-geo-skills)
- Skill path: `skills/geoflow-template`

That skill is responsible for:

- mapping GEOFlow frontend modules and variable contracts
- analyzing visual tokens and layout patterns from a reference site
- producing `tokens.json / mapping.json / manifest.json`
- generating a preview-first theme package instead of replacing the live frontend directly
