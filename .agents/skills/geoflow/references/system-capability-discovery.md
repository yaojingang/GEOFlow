# GEOFlow System Capability Discovery

GEOFlow evolves across API, admin, public-site, worker, and channel-package surfaces. Discover the target workspace during each task so the skill follows deployed reality.

## Static Discovery

Run:

```bash
python3 scripts/discover_geoflow_workspace.py /path/to/GEOFlow
python3 scripts/discover_frontend_surfaces.py /path/to/GEOFlow
python3 scripts/discover_themes.py /path/to/GEOFlow
```

The first command inventories application layers and feature evidence. The frontend commands add homepage, theme, lead-form, and channel-renderer details.

## Runtime Discovery

When dependencies and environment configuration are available, inspect:

```bash
php artisan --version
php artisan route:list --json
php artisan list
php artisan about
```

For an installed CLI, inspect `bin/geoflow --help` and the selected subcommand help. For a remote instance, use preflight and authenticated read endpoints before mutations.

## Evidence Priority

Use evidence in this order:

1. Current target routes, command help, capability endpoints, and tests.
2. Current target source files and configuration.
3. The generated discovery snapshot.
4. This package's reference maps.

If layers disagree, report the mismatch and follow the current target. A missing route or class means the capability is unavailable in that workspace until implementation or upgrade adds it.

## Capability Groups

The workspace discovery script tracks these groups when evidence exists:

- Laravel application and local CLI
- API v1 and admin web
- public site and lead capture
- materials, tasks, jobs, and articles
- enterprise knowledge and growth center
- analytics and AI visibility
- distribution and channel frontend experience
- URL import and system updates
- theme catalog, replication, live editor, and homepage builder
- queues and Horizon
- legacy root PHP frontend

The group list is a navigation aid. It does not create routes or guarantee deployment state.
