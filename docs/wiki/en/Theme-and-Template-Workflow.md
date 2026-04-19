# Theme and Template Workflow

GEOFlow’s theme system is not meant to replace live frontend code directly. It is built around a preview-first workflow.

## 1. Theme-package boundary

A theme package is responsible only for the presentation layer, including:

- header
- footer
- homepage
- category page
- article page
- archive page
- theme styles and design tokens

It should not break these system contracts:

- routing
- category / article / archive query logic
- SEO rules
- Open Graph
- structured data
- multilingual behavior

## 2. Theme-package structure

The recommended structure is:

```text
themes/<theme-id>/
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

Where:

- `manifest.json` holds theme metadata
- `tokens.json` holds colors, radii, shadows, fonts, and visual tokens
- `mapping.json` describes module mapping
- `theme.css` carries the theme styling

## 3. Recommended workflow

The safest workflow is:

1. define the reference site or target visual direction
2. use `geoflow-template` to extract module mapping and tokens
3. build the theme package
4. preview it dynamically first
5. activate it in admin only after preview approval

This matters because it:

- protects the live frontend
- uses real data in preview
- supports iteration
- keeps rollback simple

## 4. Dynamic preview routes

Current preview routes include:

- `/preview/<theme-id>/`
- `/preview/<theme-id>/category/<slug>`
- `/preview/<theme-id>/article/<slug>`
- `/preview/<theme-id>/archive`

Preview uses real database-backed content, not static sample HTML.

## 5. Admin activation

In `Site Settings`, GEOFlow can:

- show the active theme
- preview home / category / article / archive pages
- switch back to the default frontend
- activate a theme package

Recommendation:

- preview first
- keep the default theme available
- treat activation as a controlled step, not as experimentation

## 6. List summaries and Markdown cleaning

Homepage, category, and archive list cards already clean display summaries by removing:

- `#`
- `**`
- list markers
- leftover link artifacts

That keeps summaries readable instead of exposing raw Markdown fragments.

## 7. When theme work should happen

Theme work is most useful when:

- the content workflow is already stable
- the knowledge base is already meaningful
- the frontend direction is clear
- multi-site or multi-theme output is becoming important

If the content layer is still unstable, theme work should stay secondary.

## 8. Guiding principle

In one sentence:

> Themes should serve the content system, not override or distort it.

So the correct order is:

- stabilize the system first
- then theme it
- preview first
- activate second
