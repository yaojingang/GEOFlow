# Project Structure

This document defines the current official directory conventions for the project, ensuring consistency during future reorganization.

## Directory Responsibilities

### Root Directory

The root directory only holds site entry files and the most commonly used local startup files:

- `index.php`: Front-end homepage
- `article.php`: Article detail page
- `category.php`: Category page
- `archive.php`: Archive page
- `router.php`: Local development environment router
- `start.sh`: Local startup entry

### `bin/`

Only CLI scripts:

- `bin/cron.php`: Task scheduler
- `bin/worker.php`: Queue worker that pulls jobs from job_queue and executes them
- `bin/health_check_cron.php`: Health check script

### `admin/`

Admin pages, admin interfaces, task startup entry, and diagnostics pages.

### `includes/`

Configuration, database wrappers, common functions, AI engine, and task state management.

### `assets/`

Static resources including CSS, JS, images, etc.

### `data/`

Runtime data, backup files, and legacy SQLite databases (migration-only).

### `logs/`

Application logs and task log files.

### `uploads/`

Images, knowledge bases, and other uploaded content.

### `docs/`

Documentation, archives, legacy pages, maintenance scripts, and analysis records.

## Convention Rules

- Front-end accessible pages should not be placed in `docs/` or `bin/`
- CLI scripts should not remain in the root directory — consolidate them in `bin/`
- Documentation and legacy compatibility files go in `docs/`
- Admin business files are centralized in `admin/`
- When adding new scripts, determine whether they are runtime scripts or documentation scripts before deciding to place them in `bin/` or `docs/scripts/`

## Recommended Mental Model

- `Root = Site entry points`
- `bin = Runtime scripts`
- `admin = Admin panel`
- `includes = Core logic`
- `docs = Documentation & archives`
