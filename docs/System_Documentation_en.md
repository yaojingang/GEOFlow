# GEO+AI Intelligent Content Generation System - System Documentation

## 📋 System Overview

**Project Name**: GEO+AI Intelligent Content Generation System
**Project Type**: AI-driven Intelligent Content Generation and Publishing Platform
**Core Features**: Automated article creation, SEO optimization, content management
**Use Cases**: Batch content generation, automated blog operations, SEO content marketing

---

## 🛠 Technology Stack

### Backend Technologies
- **Language**: PHP 7.4+
- **Database**: PostgreSQL (runtime database)
  - Configured via environment variables (`DB_DRIVER=pgsql`)
  - Note: SQLite (`data/db/blog.db`) may exist only as a legacy/migration artifact and is not the primary runtime database
- **Server**: PHP built-in development server (localhost:8080)
- **Core Libraries**: PDO (database access), cURL (API calls)

### Frontend Technologies
- **CSS Framework**: TailwindCSS (CDN)
- **Icon Library**: Lucide Icons
- **Editors**:
  - EasyMDE (Markdown editor)
  - Tagify (tag input)
- **JavaScript**: Vanilla JS + utility function library

### Architecture Patterns
- MVC architecture concept
- Singleton pattern (Database class)
- Service class pattern (AIService, TaskService)
- Queue-based task processing (job_queue + worker pipeline)

---

## 📁 Directory Structure

```
GEOFlow/
├── /admin/                        # Admin management system (physical directory; served at /geo_admin/ URL via router.php / ADMIN_BASE_PATH)
│   ├── dashboard.php          # Admin dashboard - statistics display
│   ├── tasks-new.php          # Task management - create and manage AI generation tasks
│   ├── articles-new.php       # Article management - CRUD operations
│   ├── ai-configurator.php    # AI configuration center - unified configuration entry
│   ├── ai-models.php          # AI model management - API key configuration
│   ├── ai-prompts.php         # Prompt template management
│   ├── materials-new.php      # Material library management - unified material entry
│   ├── keyword-libraries.php  # Keyword library management
│   ├── title-libraries.php    # Title library management
│   ├── image-libraries.php    # Image library management
│   ├── knowledge-bases.php    # AI knowledge base management
│   ├── authors-new.php        # Author management
│   ├── categories.php         # Category management
│   ├── articles-review.php    # Article review workflow
│   ├── start_task_batch.php   # Batch execution launcher (enqueues jobs via JobQueueService)
│   └── includes/              # Admin-specific include files
│
├── /includes/                 # Core system files
│   ├── config.php             # Configuration file - site settings, constant definitions
│   ├── database.php           # Database class (singleton pattern)
│   ├── database_new.php       # Alternative database implementation
│   ├── functions.php          # Utility function library
│   ├── ai_engine.php          # AI content generation engine - core logic
│   ├── ai_service.php         # AI API service wrapper
│   ├── task_service.php       # Task management service
│   ├── job_queue_service.php  # Job queue orchestration/service
│   ├── task_lifecycle_service.php # Task lifecycle management
│   ├── security.php           # Security functions - CSRF, input validation
│   ├── seo_functions.php      # SEO optimization functions
│   └── header.php, footer.php # Layout templates
│
├── /assets/                   # Static resources
│   ├── /css/                  # Stylesheets
│   ├── /js/                   # JavaScript files
│   └── /images/               # Image resources
│
├── /data/db/                  # Application runtime data/backups; legacy SQLite storage only
├── /logs/                     # Application logs and process tracking
├── /uploads/                  # User uploaded files
│   ├── /images/               # Article images
│   └── /knowledge/            # Knowledge base files
│
├── index.php                  # Frontend homepage
├── article.php                # Article detail page
├── archive.php                # Article archive page
├── category.php               # Category page
├── /bin/                      # CLI execution scripts for the cron + job_queue + worker pipeline
│   ├── cron.php               # Task scheduler that enqueues due jobs into the job queue
│   ├── worker.php             # Queue worker that pulls jobs from job_queue and executes them
│   └── health_check_cron.php  # Health check script for cron / worker processing
├── router.php                 # URL routing (development environment)
├── install.php                # Installation script
└── *.sh                       # Deployment scripts
```

---

## 🗄 Database Structure

### Core Content Tables
- **articles** - Articles table
  - Includes AI generation tracking, review status, SEO metadata
  - Fields: id, title, content, status, author_id, category_id, created_at, etc.

- **categories** - Categories table (supports slug-friendly URLs)
- **tags** - Tags table
- **article_tags** - Article-tag association table (many-to-many)
- **comments** - Comments table (with moderation functionality)
- **authors** - Authors table
- **view_logs** - Article view records

### AI Content Generation Tables
- **tasks** - Content generation tasks table
  - Task configuration, scheduling settings, execution status

- **title_libraries** - Title library collections
- **titles** - Titles table (with usage count tracking)
- **keyword_libraries** - Keyword library collections
- **keywords** - Keywords table (with usage count tracking)
- **image_libraries** - Image library collections
- **images** - Images table (with metadata)
- **knowledge_bases** - AI knowledge base content
- **ai_models** - AI model configuration (API keys, endpoints)
- **prompts** - Prompt templates
- **task_schedules** - Task execution scheduling
- **article_queue** - Article generation queue
- **task_materials** - Task-material associations

### System Tables
- **settings** - Site configuration (key-value pairs)
- **admins** - Administrator accounts
- **sensitive_words** - Sensitive word filtering
- **job_queue** - Background job processing queue
- **worker_heartbeats** - Worker liveness tracking

---

## 🎯 Core Features

### 1. AI Content Generation
- **Multi-AI Model Support**: Supports different AI providers (兔子API (Tuzi API), etc.)
- **Prompt Management**: Customizable prompts for different content types
- **Batch Processing**: Background worker processes for continuous content generation
- **Task Scheduling**: Cron-based task execution with configurable intervals
- **Draft Management**: Configurable draft quantity limits before publishing

### 2. Material Library System
- **Title Library**: Pre-created or AI-generated article titles
- **Keyword Library**: SEO keyword optimization
- **Image Library**: Article illustration materials
- **Knowledge Base**: Custom context knowledge for AI
- **Author Management**: Multi-author configuration

### 3. Content Management
- **Article Lifecycle**: Draft → Review → Published
- **Bulk Operations**: Batch status updates, reviews, deletions
- **SEO Optimization**: Meta descriptions, keywords, Open Graph tags
- **Content Review**: Administrator approval workflow
- **Auto-Publishing**: Optional automatic publishing after review

### 4. Task Management
- **Task Creation**: Wizard-style task setup
- **Batch Execution**: Background process management
- **Status Tracking**: Real-time task status monitoring
- **Process Management**: PID-based process tracking and cleanup
- **Error Recovery**: Orphan process detection and cleanup

### 5. Admin Dashboard
- **Statistics**: Articles, tasks, materials, AI models, views, likes
- **Time-based Statistics**: Daily/weekly statistical analysis
- **Quick Actions**: Task management, article review
- **System Health**: Process monitoring and diagnostics

### 6. Security Features
- **CSRF Protection**: Token-based CSRF protection
- **Input Validation**: Comprehensive sanitization and validation functions
- **SQL Injection Prevention**: Prepared statements used globally
- **Security Headers**: XSS, clickjacking, MIME sniffing protection
- **Session Management**: Secure session handling and timeout
- **Password Encryption**: bcrypt password hashing

### 7. SEO Features
- **Meta Tags**: Dynamic titles, descriptions, keywords
- **Open Graph**: Social media sharing optimization
- **Structured Data**: Schema.org support
- **URL Slugs**: SEO-friendly URLs
- **Sitemap**: Article indexing support

---

## 🔄 Main Workflows

### Content Generation Workflow
```
1. Admin creates a task
   ├─ Select title library
   ├─ Select AI model and prompt
   ├─ Select image library (optional)
   ├─ Set publishing interval
   └─ Set draft limit

2. Task scheduler triggers batch execution
   └─ bin/cron.php

3. AI engine generates article content
   └─ AIEngine::executeTask()

4. Article saved as draft
   └─ Stored in articles table, status='draft'

5. Admin review (if required)
   └─ articles-review.php

6. Auto-publish or manual approval
   └─ status='published'

7. Article displayed on frontend
   └─ index.php, article.php
```

### Task Execution Flow
```
admin/start_task_batch.php (Create batch task from admin UI)
    ↓
Insert job into job_queue (Queued for background processing)
    ↓
bin/worker.php (Long-running queue consumer must be running)
    ↓
AIEngine::executeTask() (Generate single article)
    ↓
Job status updated in job_queue (completed / failed / retry)
    ↓
Logs written by the worker/job-processing pipeline
```

### Admin Access Flow
```
1. Login at /geo_admin/ (default: admin / admin888)
2. Dashboard displays system statistics
3. Navigate to task/article/material management
4. Perform CRUD operations
5. Monitor batch execution status
```

---

## ⚙️ Configuration Guide

### Key Configuration Files
- `/includes/config.php` - Site settings, database path, security constants
- `/includes/database.php` - Database initialization and schema

### Important Constants
```php
SITE_NAME: "智能GEO内容系统"
DB_DRIVER: "pgsql"  // PostgreSQL via environment variables
UPLOAD_PATH: "/assets/images/"
SESSION_TIMEOUT: 3600 seconds
MAX_FILE_SIZE: 2MB
```

### Default Admin Account
- **Username**: `admin`
- **Password**: `admin888`
- **Encryption**: bcrypt
- ⚠️ Please change the password immediately after first use

---

## 🔌 API Integration

### AI API Integration
- **Supported Providers**: Any service exposing an OpenAI-compatible `/v1/chat/completions` endpoint
- **API URL Rule**: Enter only the provider base URL in the admin panel; GEOFlow appends `/v1/chat/completions` automatically
- **Common Examples**:
  - MiniMax: `https://api.minimax.io`
  - OpenAI: `https://api.openai.com`
  - DeepSeek: `https://api.deepseek.com`
  - Tu-zi API: `https://api.tu-zi.com`
- **Authentication**: Bearer Token (API key)
- **Parameter Configuration**:
  - Model ID: Configurable
  - Max Tokens: 4000
  - Temperature: 0.7

---

## 🚀 Deployment and Operations

### Startup Commands
```bash
./start.sh                         # Start the application using the repository's startup script
php -S localhost:8080 router.php   # Start the PHP built-in development server manually
```

### Logging System
- **Daily Logs**: `/logs/YYYY-MM-DD.log`
- **Batch Logs**: `/logs/batch_{task_id}.log`
- **Worker/Queue Observability**: Database-backed status in `worker_heartbeats` and `job_queue`

### Database
- PostgreSQL runtime database
- Configured via environment variables (`DB_DRIVER=pgsql`)
- Schema automatically created on first run
- Backup with `pg_dump`

---

## 🔧 Development Notes

### Code Quality
- Total of 129 source files (PHP, JS, CSS)
- Comprehensive error handling and logging
- Security-first design philosophy
- Modular architecture with separated service classes

### Important Implementation Details
- Database connection uses singleton pattern
- Transaction support ensures data consistency
- PID-based process lifecycle management
- Atomic status updates support concurrent operations
- Comprehensive logging for debugging

### Testing and Debugging Files
- Test files: `test.php`, `db-test.php`, `basic-test.php`
- Debugging tools: `debug.php`, `deep-debug.php`, `env-check.php`
- Emergency fixes: `emergency-fix.php`, `emergency-switch.php`

---

## 📊 System Highlights

### Advantages
✅ Fully automated content generation
✅ Comprehensive material management system
✅ Powerful task scheduling capabilities
✅ Professional admin management interface
✅ Security-first design
✅ Built-in SEO optimization
✅ Process management and error recovery

### Use Cases
- Content marketing automation
- SEO content batch generation
- Automated blog operations
- Multi-site content distribution
- AI writing assistant platform

---

## 🆘 FAQ

### Q: How to start the system?
A: Run `./start.sh` or `php -S localhost:8080 router.php` to start the PHP server, then visit `http://localhost:8080`

### Q: How to configure an AI model?
A: Log in to admin panel → AI Configuration Center → AI Model Management → Add API key

### Q: How to create a content generation task?
A: Admin panel → Task Management → New Task → Follow the wizard to configure

### Q: Articles are not auto-publishing?
A: Check the "Draft Limit" and "Auto-Publish" settings in the task configuration

### Q: How to view task execution logs?
A: Check the `/logs/batch_{task_id}.log` files or query the `job_queue` table status

### Q: Where is the database?
A: PostgreSQL database, configured via environment variables (`DB_DRIVER=pgsql`). A legacy SQLite file may exist at `data/db/blog.db` but is not the primary runtime database.

---

## 📝 Quick Start

### 1. Install the System
```bash
# Visit the installation page
http://localhost:8080/install.php
```

### 2. Log in to Admin Panel
```bash
# Visit the admin panel
http://localhost:8080/geo_admin/

# Default account
Username: admin
Password: admin888
```

### 3. Configure AI Model
```
Admin panel → AI Configuration Center → AI Model Management → Add Model
Fill in: Model name, API key, API endpoint
```

### 4. Create Material Libraries
```
Admin panel → Material Management → Title Library/Keyword Library/Image Library
Add material content
```

### 5. Create a Generation Task
```
Admin panel → Task Management → New Task
Configure: Title library, AI model, prompt, publishing settings
```

### 6. Start Batch Execution
```
Admin panel → Task Management → Click the "Start Batch Execution" button
Or run the worker locally: php bin/worker.php
Or start the Docker worker service
```

### 7. Review and Publish
```
Admin panel → Article Review → Review pending articles
Or set up auto-publishing
```

---

## 📞 Technical Support

- **Git Repository**: https://github.com/yaojingang/GEOFlow
- **Development Environment**: macOS / Linux / Windows
- **PHP Version**: 7.4+
- **Database**: PostgreSQL

---

## 📅 Document Updates

- **Created**: 2026-01-31
- **Version**: 1.0
- **Last Updated**: 2026-04-16

---

**Note**: This document was generated based on automated code analysis. It is recommended to regenerate or manually update it after each major update.
