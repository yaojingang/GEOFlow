# Legacy GEOFlow Template Migration

Use this reference when the input explicitly contains historical GEOFlow PHP template packages or mentions the retired template skill.

## Legacy Signals

- root `index.php`, `article.php`, `category.php`, or `archive.php`
- `includes/header.php`, `includes/footer.php`, or `includes/functions.php`
- output packages with `tokens.json`, `mapping.json`, and `manifest.json`
- Tailwind CDN or root `assets/css/custom.css` assumptions

These signals describe the historical PHP frontend contract.

## Current Laravel Destination

Map reusable visual decisions into current GEOFlow surfaces:

| Legacy artifact | Current destination |
|---|---|
| page-level PHP file | `resources/views/theme/{theme_id}/*.blade.php` or built-in `site.*` fallback |
| includes header/footer | theme `partials/` and `layout.blade.php` |
| CSS tokens | `tokens.json`, theme CSS, or homepage style JSON |
| module mapping | `mapping.json` plus supported homepage modules |
| static homepage sections | HomepageModuleBuilder payload when the module type exists |
| activation notes | preview theme, reviewed import, or explicit theme activation handoff |

Preserve public routes, controller-provided data, SEO/schema, markdown-rendered article content, lead-form slugs, and channel capability checks.

## Migration Output

Produce:

1. detected legacy files and assumptions
2. reusable tokens and layout patterns
3. current Laravel theme and homepage-module mapping
4. unsupported or data-dependent sections
5. preview plan and verification routes
6. activation or channel-sync handoff with rollback notes

Historical preview files demonstrate design intent only. Validate every migrated artifact against the current workspace before activation.
