# Backend Connection Workflow

Use this workflow in `channel_frontend` when the user asks to connect to the GEOFlow backend, manage channel templates, or compare default and channel frontend capabilities.

## Discovery Steps

1. Confirm the workspace has `artisan`, `routes/web.php`, `resources/views/site`, and `resources/views/theme`.
2. Run `scripts/discover_frontend_surfaces.py <workspace>` for static capability detection.
3. If the system command exists and a channel id is known, run:

```bash
php artisan geoflow:frontend-experience <channel_id> --json
```

4. Compare default-site, channel-site, target-package, and cached remote capabilities before proposing UI or JSON changes. When the user needs a fresh remote read, pass `--live-remote` through the bundled comparison/report helper. The helper blocks the request unless `DistributionHttpClient::signedGetJson` validates its freshly loaded endpoint immediately before use, restricts it to HTTPS or explicit loopback HTTP, and disables redirects. The live read must not be treated as persisted state.
5. For homepage JSON, validate with `scripts/validate_homepage_design_payload.py`.

## Channel-Site Flow

1. Identify the channel type.
2. If it is not `geoflow_agent`, explain that module rendering is not guaranteed.
3. For GeoFlow Agent, choose mode:
   - `inherit_default`
   - `snapshot_default`
   - `custom`
4. Read cached `/geoflow-agent/v1/frontend-capabilities` through `geoflow:frontend-experience <channel_id> --json` when a channel id is available. If stale or not checked, use a bundled helper with `--live-remote` for a validated non-persistent read, or recommend the admin refresh action after the same endpoint safety check.
5. Prepare reviewed JSON for `homepage_style`, `homepage_modules`, and `home_carousel_slides`.
6. Summarize the sync delta: theme, `front_mode`, homepage module count, carousel count, text ad count, and remote support status.
7. Use the sync preview report before syncing. The original sync POST must include `frontend_sync_confirmed=1` after the operator approves the exact payload and target channel.

## Cross-Channel Flow

1. Inventory active GeoFlow Agent channels.
2. Compare each channel's `frontend_experience_mode`, `template_key`, `front_mode`, and supported modules.
3. Prefer `inherit_default` for channels that should track the default frontend.
4. Prefer `snapshot_default` for channels that need an initial copy but later diverge.
5. Prefer `custom` for brand/vertical-specific channels.

## Output Checklist

Every channel frontend recommendation should include:

- target surface and channel id or channel selector
- mode choice
- theme/template mapping
- module/style/slide JSON summary
- remote capability status
- remote cache status and checked time
- sync summary: theme, front mode, module count, carousel count, text ad count
- sync risk, recommended action, and rollback note
