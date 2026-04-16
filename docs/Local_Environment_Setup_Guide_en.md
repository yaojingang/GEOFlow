# GEO+AI Intelligent Content Generation System - Local Environment Setup Guide

> **Last Updated**: 2026-02-02  
> **Applicable Version**: 1.0+  
> **Operating Systems**: macOS / Linux / Windows

---

## 📋 Table of Contents

1. [Environment Requirements](#environment-requirements)
2. [Quick Start](#quick-start)
3. [Detailed Configuration](#detailed-configuration)
4. [Common Commands](#common-commands)
5. [Access URLs](#access-urls)
6. [Troubleshooting](#troubleshooting)

---

## 🔧 Environment Requirements

### Required Software
- **PHP**: >= 7.4 (8.0+ recommended)
- **PostgreSQL**: >= 14

### Required PHP Extensions
- ✅ `pdo` - Database abstraction layer
- ✅ `pdo_pgsql` - PostgreSQL database support
- ✅ `json` - JSON processing
- ✅ `mbstring` - Multibyte string handling
- ✅ `session` - Session management
- ✅ `curl` - HTTP requests (AI API calls)

### Recommended Configuration
```ini
memory_limit = 256M
max_execution_time = 300
upload_max_filesize = 10M
post_max_size = 10M
```

---

## 🚀 Quick Start

### Method 1: Using the One-Click Startup Script (Recommended)

```bash
# 1. Clone the repository and navigate to the project directory
git clone https://github.com/yaojingang/GEOFlow.git
cd GEOFlow

# 2. Grant execution permission to the startup script
chmod +x start.sh

# 3. Start the server
./start.sh
```

### Method 2: Manual Startup

```bash
# Navigate to the project directory
git clone https://github.com/yaojingang/GEOFlow.git
cd GEOFlow

# Start the PHP built-in server
php -S localhost:8080 router.php
```

### First Access

1. **Check System Status**
   ```
   http://localhost:8080/geo_admin/system_diagnostics.php
   ```

2. **Log into the Admin Panel**
   ```
   http://localhost:8080/geo_admin/
   Username: admin
   Password: admin888
   ```

---

## 📝 Detailed Configuration

### 1. Install PHP (macOS)

```bash
# Install using Homebrew
brew install php

# Verify installation
php --version

# Check extensions
php -m | grep -E "pdo|pgsql|json|mbstring|curl"
```

### 2. Install PHP (Ubuntu/Debian)

```bash
# Update package list
sudo apt update

# Install PHP and extensions
sudo apt install php php-cli php-pgsql php-mbstring php-json php-curl postgresql-client

# Verify installation
php --version
```

### 3. Install PHP (Windows)

1. Download PHP: https://windows.php.net/download/
2. Extract to `C:\php`
3. Add to the system PATH
4. Copy `php.ini-development` to `php.ini`
5. Enable extensions (remove the semicolons):
   ```ini
   extension=pdo_pgsql
   extension=mbstring
   extension=curl
   ```

### 4. Directory Permission Settings

```bash
# Create required directories first
mkdir -p data/backups logs uploads

# Ensure key directories are writable
chmod -R 755 data/backups
chmod -R 755 logs
chmod -R 755 uploads

# If needed, change the owner
chown -R $(whoami) data/backups logs uploads
```

### 5. Configuration File Description

#### `includes/config.php`
The core system configuration file, containing:
- Website basic information
- Database connection configuration
- Administrator account
- Security settings
- Upload configuration

**Important Configuration Items**:

Environment variables for local PHP runs (**export these in your shell**):

> **Note**: This application does **not** automatically load variables from a `.env` file for direct local runs. A `.env` file is only used when it is passed to tooling that reads it (for example, Docker Compose with `--env-file`), or if you add another explicit loading mechanism yourself.

```bash
export DB_DRIVER=pgsql
export DB_HOST=127.0.0.1
export DB_PORT=5432
export DB_NAME=geo_system
export DB_USER=geo_user
export DB_PASSWORD=geo_password
```

PHP constants defined in `includes/config.php`:
```php
define('SITE_NAME', 'Intelligent GEO Content System');
define('ADMIN_USERNAME', 'admin');
define('SECRET_KEY', 'your-secret-key-change-this-in-production');
```

⚠️ **You must change `SECRET_KEY` in the production environment**

---

## 💻 Common Commands

### Start the Server

```bash
# Use the startup script (recommended)
./start.sh

# Manual startup
php -S localhost:8080 router.php

# Specify a different port
php -S localhost:3000 router.php
```

### Stop the Server

```bash
# In the terminal where the server is running, press
Ctrl + C
```

### Environment Check

```bash
# Check PHP version
php --version

# Check PHP extensions
php -m

# Check PHP configuration
php --ini
```

### View Logs

```bash
# View today's application log
tail -f logs/$(date +%Y-%m-%d).log

# View batch execution logs
tail -f logs/batch_*.log

# View task manager logs
tail -f logs/task_manager_$(date +%Y-%m-%d).log

# View all logs
ls -lh logs/
```

### Database Operations

```bash
# Check database connection
php bin/db_maintenance.php check

# Connect to PostgreSQL
psql -h 127.0.0.1 -U geo_user -d geo_system

# View all tables
\dt

# View table structure
\d articles
```

### Backup Database

```bash
# Create a PostgreSQL backup
pg_dump -h 127.0.0.1 -U geo_user -d geo_system > data/backups/geo_system_backup_$(date +%Y%m%d_%H%M%S).sql

# View backups
ls -lh data/backups/geo_system_backup_*.sql
```

---

## 🌐 Access URLs

### Frontend Pages

| Page | URL | Description |
|------|-----|-------------|
| Home | http://localhost:8080 | Article list |
| Article Detail | http://localhost:8080/article/{slug} | Single article |
| Category | http://localhost:8080/?category={id} | Category filter |
| Tag | http://localhost:8080/tag.php?id={id} | Tag filter |
| Search | http://localhost:8080/?search={keyword} | Search function |

### Admin Panel

| Feature | URL | Description |
|---------|-----|-------------|
| Login | http://localhost:8080/geo_admin/ | Admin login |
| Dashboard | http://localhost:8080/geo_admin/dashboard.php | Data statistics |
| Article Management | http://localhost:8080/geo_admin/articles-new.php | CRUD operations |
| Task Management | http://localhost:8080/geo_admin/tasks-new.php | AI tasks |
| AI Configuration | http://localhost:8080/geo_admin/ai-configurator.php | AI settings |
| Material Management | http://localhost:8080/geo_admin/materials-new.php | Material library |
| System Diagnostics | http://localhost:8080/geo_admin/system_diagnostics.php | Health check |

### Default Account

```
Username: admin
Password: admin888
```

⚠️ **Please change the password immediately after the first login**

---

## 🔍 Troubleshooting

### Issue 1: Page Displays Blank

**Possible Causes**:
- PHP error
- Database connection failure
- File permission issue

**Solution**:
```bash
# 1. Enable error display
php -d display_errors=1 -S localhost:8080 router.php

# 2. Check error logs
tail -f logs/$(date +%Y-%m-%d).log

# 3. Check the database connection
php bin/db_maintenance.php check
```

### Issue 2: Unable to Start the Server

**Possible Causes**:
- Port is occupied
- PHP is not installed
- Insufficient permissions

**Solution**:
```bash
# 1. Check port usage
lsof -i :8080

# 2. Use a different port
php -S localhost:3000 router.php

# 3. Check PHP
which php
php --version
```

### Issue 3: Database Error

**Possible Causes**:
- PostgreSQL service not running
- Incorrect connection credentials
- pdo_pgsql extension not installed

**Solution**:
```bash
# 1. Check PostgreSQL service status
sudo systemctl status postgresql

# 2. Test database connection
psql -h 127.0.0.1 -U geo_user -d geo_system -c "SELECT 1;"

# 3. Check pdo_pgsql extension
php -m | grep pgsql

# 4. Restart PostgreSQL if needed
sudo systemctl restart postgresql
```

### Issue 4: Unable to Log into the Admin Panel

**Possible Causes**:
- Incorrect username or password
- Session configuration issue
- Database issue

**Solution**:
```bash
# 1. Generate a new password hash
HASH=$(php -r "echo password_hash('admin888', PASSWORD_DEFAULT);")
echo "$HASH"

# 2. Update the admin password in the database
psql -h 127.0.0.1 -U geo_user -d geo_system -c \
  "UPDATE admins SET password_hash = '$HASH' WHERE username = 'admin';"

# 3. Check session directory
php -r "echo session_save_path();"

# 4. Clear sessions only after confirming the directory is dedicated to this app
SESSION_DIR=$(php -r 'echo session_save_path() ?: sys_get_temp_dir();')
echo "Review session directory before deleting anything: $SESSION_DIR"
# Example: remove only PHP session files from the configured session directory
# WARNING: run this only if SESSION_DIR is used exclusively by this application
rm -f "${SESSION_DIR%/}"/sess_*
```

### Issue 5: AI Features Not Working

**Possible Causes**:
- API key not configured
- Network connection issue
- API quota exhausted

**Solution**:
1. Check AI model configuration
2. Test API connection
3. Check error logs

---

## 📚 Related Documentation

- [AI_PROJECT_GUIDE_en.md](./AI_PROJECT_GUIDE_en.md) - AI Development Guide
- [System_Documentation_en.md](./System_Documentation_en.md) - System User Manual
- [system_diagnostics.php](../admin/system_diagnostics.php) - System diagnostics and troubleshooting guidance

---

## 🆘 Getting Help

### System Diagnostics Tool
```
http://localhost:8080/geo_admin/system_diagnostics.php
```

### Log Files
```
logs/YYYY-MM-DD.log - Application logs
logs/batch_*.log - Batch execution logs
logs/task_manager_*.log - Task manager logs
```

### Environment Check
```bash
php --version
php -m | grep -E "pdo|pgsql|json|mbstring|curl"
```

---

**After setup is complete, enjoy using the GEO+AI system!** 🎉
