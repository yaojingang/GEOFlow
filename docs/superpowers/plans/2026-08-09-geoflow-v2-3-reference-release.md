# GEOFlow v2.3 Reference Release Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Publish GEOFlow v2.3.0 with the Enterprise Signature theme, About and archive pages, 50 readable reference articles, and fresh-install-only sample data.

**Architecture:** The release ships a versioned reference-content package made of JSON metadata and Markdown bodies. `geoflow:install` imports it only after proving that the database is pristine, while upgrades retain every existing theme, site setting, category, author, and article.

**Tech Stack:** Laravel 12, PHP 8.3+, Blade, PostgreSQL/SQLite, Vite, PHPUnit.

---

### Task 1: Public page contract

**Files:**
- Create: `app/Http/Controllers/Site/AboutController.php`
- Create: `resources/views/site/about.blade.php`
- Create: `resources/views/site/partials/about-content.blade.php`
- Create: `resources/views/theme/geoflow-template-21-enterprise-signature/about.blade.php`
- Modify: `routes/web.php`
- Modify: `resources/views/theme/geoflow-template-21-enterprise-signature/manifest.json`
- Test: `tests/Feature/EnterpriseSignatureThemeTest.php`

- [ ] Add a failing test proving `/about`, `/archive`, and monthly archives are independent public pages.
- [ ] Run the focused test and confirm it fails because `site.about` is missing.
- [ ] Add the About controller, views, navigation, and published theme metadata while retaining archive routes.
- [ ] Run the focused test and confirm all page-contract assertions pass.

### Task 2: Versioned reference content

**Files:**
- Create: `database/seeders/data/frontend-reference-v1/manifest.json`
- Create: `database/seeders/data/frontend-reference-v1/articles/*.md`
- Create: `database/seeders/FrontendReferenceSeeder.php`
- Modify: `database/seeders/FrontendDemoSeeder.php`
- Test: `tests/Feature/FrontendReferenceContentTest.php`

- [ ] Add failing tests for an exact count of 50 articles, two active categories, stable unique slugs, and readable Markdown files.
- [ ] Export the approved local articles, update version-bound wording, remove local placeholders, and select featured/hot examples.
- [ ] Implement the manifest reader and insert-only reference seeder.
- [ ] Run the focused content and seeder tests until they pass.

### Task 3: Fresh-install default and upgrade protection

**Files:**
- Modify: `app/Console/Commands/GeoFlowInstallCommand.php`
- Modify: `database/seeders/DatabaseSeeder.php`
- Modify: `.env.example`
- Modify: `.env.prod.example`
- Test: `tests/Feature/GeoFlowInstallCommandTest.php`
- Test: `tests/Feature/DatabaseSeederTest.php`

- [ ] Add failing tests proving a pristine install receives theme 21 and 50 articles automatically.
- [ ] Add failing tests proving marked and unmarked existing databases retain all existing values.
- [ ] Add `--without-demo` for minimal fresh installations and keep raw `db:seed` opt-in.
- [ ] Record reference-content version, counts, and theme in the installation marker.
- [ ] Run the install and database-seeder test suites until they pass.

### Task 4: Release metadata and documentation

**Files:**
- Modify: `version.json`
- Modify: `docs/CHANGELOG.md`
- Modify: `docs/CHANGELOG_en.md`
- Modify: `README.md`
- Create: `docs/reference-content/frontend-reference-v1.md`

- [ ] Align version metadata and release URLs to v2.3.0.
- [ ] Document the theme, 50-article package, About/archive contract, and upgrade protection.
- [ ] Validate that no published reference article retains obsolete version or local-domain wording.

### Task 5: Release verification and delivery

**Files:**
- Modify: `bin/git/check-open-source-release.sh`
- Modify: `bin/git/prepare-open-source-release.sh`

- [ ] Run Pint, focused tests, the full PHPUnit suite, Vite build, JavaScript tests, and prototype verification.
- [ ] Run Composer and npm audits and inspect generated package contents.
- [ ] Run security, architecture, and adversarial reviews; fix confirmed findings.
- [ ] Pass the private open-source gate, sync the isolated private source into the public release worktree, and verify the synced diff.
- [ ] Commit, push `codex/unified-latest-updates`, create a GitHub pull request, and read back CI state.
