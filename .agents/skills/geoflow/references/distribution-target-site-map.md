# Distribution Target Site Map

Use this reference when evaluating or designing GeoFlow Agent target-site frontend behavior.

## GeoFlow Agent Package Surfaces

The target package contains:

- `config.php`: default site/channel settings, credentials, static publishing flags, frontend experience fields
- `public/index.php`: signed API endpoints, article storage, static rebuild, frontend renderer
- `assets/css/site.css`: target package base and module CSS
- `storage/articles/*.json`: remote article payload storage
- `index.html`, `llms.txt`, `sitemap.txt`: static outputs

## Expected Capability Endpoint

When available, inspect:

```text
GET /geoflow-agent/v1/frontend-capabilities
```

Signed request event may be `frontend.capabilities` or `health.check`.

Expected response fields:

- `ok`
- `service`
- `capability_version` (`1.2` for the current managed frontend experience contract)
- `package_version`
- `active_theme`
- `front_mode`
- `frontend_experience_mode`
- `current_settings`
- `supported_modules`
- `supported_routes`
- `supports_homepage_style`
- `supports_home_carousel_slides`
- `supports_article_text_ads`
- `supports_static_generation`

`current_settings` should summarize the runtime target state without exposing secrets:

- `active_theme`, `front_mode`, `frontend_experience_mode`
- `homepage_modules_count`, `homepage_module_types`
- `home_carousel_slides_count`
- `article_text_ads_count`
- `homepage_style_keys`

## Renderer Expectations

The generic remote homepage should:

- load normalized site settings
- render homepage modules before the article list when modules exist
- keep the original hero/list behavior when modules are empty
- allow static modules to render even when there are no articles
- filter `article_collection` by `featured`, `hot`, or `latest`
- sanitize `custom_html`

The current managed target package module list may be narrower than the default site. In particular, default-site `lead_form` modules require explicit target-package support before they can be synced as-is.

Special target themes may keep custom renderers, but they should still preserve article pages, SEO metadata, static rebuild, and signed update endpoints.

## Capability Caveats

- The target package is a controlled renderer, not a remote template editor.
- A missing capability endpoint does not prove the channel is broken; it may be an older package.
- Channel settings can be synced independently of content refresh, but frontend changes normally need static rebuild or content refresh to become visible in static mode.
- If `supported_modules` does not include `lead_form`, downgrade the module to CTA/link, omit it with an operator-visible note, or upgrade the target package before sync.
