# GEOFlow Frontend Resource Index

Load only the section that matches the selected frontend phase.

## Public Frontend

- Surface and data contract: [geoflow-frontend-map.md](geoflow-frontend-map.md)
- Theme edit lifecycle: [theme-edit-workflow.md](theme-edit-workflow.md)
- Laravel Blade contract: [laravel-theme-contract.md](laravel-theme-contract.md)
- Theme package structure: [theme-package-contract.md](theme-package-contract.md)
- Homepage module composition: [homepage-composition-guide.md](homepage-composition-guide.md)
- Reference-site analysis and visual optimization: [design-optimization-playbook.md](design-optimization-playbook.md) and [template-boundary.md](template-boundary.md)
- Deterministic tools: [discover_themes.py](../scripts/discover_themes.py), [validate_homepage_design_payload.py](../scripts/validate_homepage_design_payload.py), [prepare_theme_edit_session.py](../scripts/prepare_theme_edit_session.py), [finalize_theme_edit_session.py](../scripts/finalize_theme_edit_session.py), and [serve_preview.py](../scripts/serve_preview.py)

## Channel Frontend

- Capability and sync contract: [channel-frontend-contract.md](channel-frontend-contract.md)
- Target package surface: [distribution-target-site-map.md](distribution-target-site-map.md)
- Backend connection workflow: [backend-connection-workflow.md](backend-connection-workflow.md)
- Deterministic tools: [discover_frontend_surfaces.py](../scripts/discover_frontend_surfaces.py), [compare_default_vs_channel_frontend.py](../scripts/compare_default_vs_channel_frontend.py), and [build_sync_preview_report.py](../scripts/build_sync_preview_report.py)

Keep preview, authenticated import, channel sync, activation, and publication as separate phases. Switch to `development` for product or target-package code changes and to `operations` for approved running-system mutations.
