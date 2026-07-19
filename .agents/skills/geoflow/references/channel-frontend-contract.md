# Channel Frontend Contract

Use this reference when the target surface is `channel_site`, `cross_channel_sync`, or `template_mapping`.

## First-Class Target

GEOFlow Agent channels are the first-class `channel_frontend` target. WordPress REST and Generic API channels can receive article/content payloads and selected settings; this mode must verify their advertised rendering capabilities before using GEOFlow homepage modules.

## Settings Contract

Channel frontend experience lives in `distribution_channels.site_settings` plus `distribution_channels.channel_config`.

`site_settings` may include:

- `site_name`, `site_subtitle`, `site_description`, `site_keywords`
- `copyright_info`, `site_logo`, `site_favicon`
- `seo_title_template`, `seo_description_template`
- `featured_limit`, `per_page`
- `homepage_style`
- `homepage_modules`
- `home_carousel_slides`

Default-site `homepage_modules` may include `lead_form` modules. Channel payloads must be filtered against the target package's live or cached `supported_modules` before sync.

`channel_config` may include:

- `frontend_experience_mode`
- `article_text_ad_policy`
- `frontend_capabilities_cache`
- channel-type-specific transport settings

`frontend_capabilities_cache` is a non-sensitive GeoFlow Agent capability snapshot. It may contain:

- `status`, `checked_at`, `message`, `reachable`
- `package_version`, `capability_version`
- `active_theme`, `front_mode`, `frontend_experience_mode`
- `supported_modules`, `supported_routes`
- `supports_homepage_style`, `supports_home_carousel_slides`, `supports_article_text_ads`, `supports_static_generation`
- `agent_base_url`

Target payload should include:

- `active_theme`
- `front_mode`
- `frontend_experience_mode`
- `homepage_style`
- `homepage_modules`
- `home_carousel_slides`
- `article_text_ads`

Sync previews and capability reports should summarize:

- `active_theme`
- `front_mode`
- `frontend_experience_mode`
- homepage module count and module types
- carousel slide count
- homepage style token keys
- text ad module count

GeoFlow Agent targets may expose `/geoflow-agent/v1/frontend-capabilities`. Treat this as the live remote support contract for package version, supported modules, supported routes, current theme, current `front_mode`, carousel support, homepage style support, and article text ad support.

Backend pages and `php artisan geoflow:frontend-experience <channel_id> --json` should read the cache by default. For a fresh read, use `scripts/compare_default_vs_channel_frontend.py --workspace <workspace> --channel <channel_id> --live-remote` or `scripts/build_sync_preview_report.py`. The helper validates the cached endpoint, then requires the application HTTP client to validate the endpoint loaded in the live request process immediately before use and to disable redirects. The supported application contract calls `assertSafeSignedEndpoint($endpoint)` between endpoint construction and `get($endpoint)` inside `DistributionHttpClient::signedGetJson`; the guard must enforce HTTPS or explicit loopback HTTP. Older application versions stay on cached reports until both protections are present. Apply the same gates before an admin refresh action. Live command reads do not persist the cache.

Sync confirmation uses a preview-first flow:

- single channel: `GET distribution/{channelId}/sync-settings/preview`
- all active GeoFlow Agent channels: `GET distribution/sync-settings-all/preview`
- selected channels: `POST distribution/sync-settings-selected/preview`
- confirmed sync: original sync POST plus `frontend_sync_confirmed=1`

## Experience Modes

- `inherit_default`: channel uses the current default-site frontend experience at sync time.
- `snapshot_default`: channel copies the current default-site frontend experience when saved, then diverges safely.
- `custom`: channel uses its own JSON settings.

## Module Semantics

The shared module vocabulary is:

- `hero`
- `rich_text`
- `image_band`
- `metric_band`
- `chart_band`
- `feature_grid`
- `article_collection`
- `cta_band`
- `custom_html`

The default site may additionally support `lead_form` with `lead_form_slug`. Do not assume a GeoFlow Agent target package can render `lead_form` unless `/geoflow-agent/v1/frontend-capabilities` explicitly includes it in `supported_modules`.

`article_collection` must use `data_source` values from `featured`, `hot`, and `latest`. Remote article payloads need `is_featured` and `is_hot` for this to work.

If a target package lacks `lead_form`, use one of these reviewed fallbacks before sync:

- replace it with a `cta_band` linking to `/forms/{slug}` on the default site or a channel-specific public form URL
- remove the module from channel payload and report the omission
- require target package upgrade before syncing the original module

## Skill Guardrails

- Prefer JSON settings and synchronization over editing remote PHP/Blade.
- Keep default-site identity fields and channel-site identity fields separate from homepage experience fields.
- Treat channel sync as a finalize step that requires operator review.
- Do not map unsupported external systems to GEOFlow renderer guarantees.
- Never silently sync unsupported module types just because the default site can render them.
