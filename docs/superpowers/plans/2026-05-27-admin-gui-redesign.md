# Admin GUI Redesign Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Redesign the admin GUI layout so all first-level pages expose the GEO acquisition workflow and the top navigation is easier to scan.

**Architecture:** Implement the redesign through shared Blade surfaces first, then add page-specific first-screen improvements. Keep backend routes and data contracts unchanged so the UI refactor is low risk.

**Tech Stack:** Laravel Blade, Tailwind CDN utilities, Lucide icons, PHPUnit feature tests.

---

### Task 1: Global Shell And Navigation

**Files:**
- Modify: `resources/views/admin/layouts/app.blade.php`
- Modify: `resources/views/admin/partials/header.blade.php`
- Modify: `lang/zh_CN/admin.php`
- Modify: `lang/en/admin.php`
- Test: `tests/Feature/AdminDashboardQuickStartTest.php`

- [x] **Step 1: Write the failing test**

Add assertions for `data-admin-gui-shell`, `data-admin-primary-nav`, `GEO获客`, `AI检视`, `内容资产`, and `发布复测` on the dashboard.

- [x] **Step 2: Run test to verify it fails**

Run: `php artisan test --compact tests/Feature/AdminDashboardQuickStartTest.php`

Expected: fail because `data-admin-gui-shell` is missing.

- [x] **Step 3: Write minimal implementation**

Add a global shell marker to the admin main element, normalize common card/table styling, and render icon-based primary navigation from translated short labels and hints.

- [x] **Step 4: Run test to verify it passes**

Run: `php artisan test --compact tests/Feature/AdminDashboardQuickStartTest.php`

Expected: pass.

### Task 2: First-Level GEO Workflow Entry

**Files:**
- Modify: `resources/views/admin/partials/geo-operations-panel.blade.php`
- Test: `tests/Feature/AdminGeoOperationsModulesTest.php`

- [x] **Step 1: Write the failing test**

Assert `data-geo-primary-entry`, `GEO 获客主线`, `企业资料`, `AI检视`, `引用来源`, `内容资产`, and `发布复测` on task, article, material, AI config, and site setting pages.

- [x] **Step 2: Run test to verify it fails**

Run: `php artisan test --compact tests/Feature/AdminGeoOperationsModulesTest.php --filter=test_admin_task_article_material_ai_and_site_pages_show_geo_operations_panel`

Expected: fail because the workflow entry is missing.

- [x] **Step 3: Write minimal implementation**

Render a five-step workflow entry above the existing GEO operations panel and link each step to the relevant module.

- [x] **Step 4: Run test to verify it passes**

Run: `php artisan test --compact tests/Feature/AdminGeoOperationsModulesTest.php --filter=test_admin_task_article_material_ai_and_site_pages_show_geo_operations_panel`

Expected: pass.

### Task 3: Dashboard And GEO Workspace First Screen

**Files:**
- Modify: `resources/views/admin/dashboard.blade.php`
- Modify: `resources/views/admin/geo/workspace.blade.php`
- Test: `tests/Feature/AdminDashboardQuickStartTest.php`
- Test: `tests/Feature/AdminGeoWorkspaceTest.php`

- [x] **Step 1: Write the failing test**

Assert `data-admin-home-command-center`, `GEO 获客中控台`, `data-geo-flow-shell`, `AI 可见度工作台`, `企业业务身份证`, `AI 里搜得到你吗`, `AI 引用了哪些网页`, `内容资产`, and `发布与复测`.

- [x] **Step 2: Run test to verify it fails**

Run: `php artisan test --compact tests/Feature/AdminGeoWorkspaceTest.php --filter=test_authenticated_admin_can_open_geo_workspace`

Expected: fail because the guided shell is missing.

- [x] **Step 3: Write minimal implementation**

Add a dashboard command center and a GEO workspace guided shell. Rename workspace tabs to business labels while keeping the existing tab panels and routes.

- [x] **Step 4: Run test to verify it passes**

Run: `php artisan test --compact tests/Feature/AdminDashboardQuickStartTest.php`

Run: `php artisan test --compact tests/Feature/AdminGeoWorkspaceTest.php --filter=test_authenticated_admin_can_open_geo_workspace`

Expected: pass.
