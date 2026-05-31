# Admin GUI Redesign Design

## Goal

Make the admin interface easier to understand by turning the first screen of the product into an operator console, not a collection of unrelated backend modules.

## Scope

- Add one global admin shell marker and shared visual normalization in `resources/views/admin/layouts/app.blade.php`.
- Replace the text-only top navigation with a compact icon navigation that names the primary jobs: overview, GEO leads, AI inspection, retest, content, materials, models, settings, and users.
- Add a persistent GEO workflow rail for the main acquisition loop: enterprise profile, AI inspection, citation sources, content assets, publishing retest.
- Add the same GEO workflow entry to first-level operating pages through `resources/views/admin/partials/geo-operations-panel.blade.php`.
- Add a command-center block to the dashboard so the home page starts with the main actions and operational state.
- Add a guided first screen to the GEO workspace so operators can follow enterprise facts, AI visibility, citation sources, content assets, and retesting.

## Interaction Model

The admin should answer three questions above the fold:

1. Where am I in the acquisition loop?
2. What should I do next?
3. Which module owns that action?

The page-specific tables and forms remain below the new entry layer, so existing workflows are preserved while the first screen becomes more direct.

## Testing

Feature tests cover:

- Global GUI shell and primary navigation.
- Dashboard command center.
- GEO workflow entry on first-level operating pages.
- GEO workspace guided shell and simplified tab labels.
