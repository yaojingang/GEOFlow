# GEO+AI Intelligent Content Generation System - AI Development Guide

> **Document Purpose**: Help AI assistants quickly understand the project architecture, core logic, and development standards to improve development efficiency
> **Last Updated**: 2026-01-31
> **Project Version**: 1.0

---

## 📋 Project Overview

### Core Positioning
This is an **AI-driven automated content generation and publishing platform**, primarily used for batch generating SEO-optimized article content.

### Tech Stack Quick Reference
```
Backend: PHP 7.4+ (no framework, native development)
Database: PostgreSQL (runtime database, configured via environment variables)
Frontend: TailwindCSS + vanilla JavaScript + Lucide Icons
Server: PHP built-in development server (localhost:8080)
AI Integration: Tu-zi API (Tuzi API) - OpenAI-compatible interface
```

### Project Features
- ✅ No complex dependencies required, works out of the box
- ✅ PostgreSQL runtime database with robust backup and migration support
- ✅ Complete admin management system
- ✅ Supports batch AI content generation
- ✅ Process-level task management and monitoring
- ✅ Comprehensive security mechanisms (CSRF, XSS protection)

---

## 🏗️ Core Architecture

### 1. Directory Structure (Key Files)

```
GEOFlow/
├── /includes/                    # Core system libraries
│   ├── config.php               # Global configuration (constant definitions)
│   ├── database.php             # Database class (singleton pattern)
│   ├── functions.php            # Utility function library
│   ├── ai_engine.php            # AI content generation engine ⭐Core
│   ├── ai_service.php           # AI API service wrapper
│   ├── task_service.php         # Task management service
│   ├── job_queue_service.php    # Job queue orchestration/service
│   ├── task_lifecycle_service.php # Task lifecycle management ⭐Important
│   ├── security.php             # Security functions
│   ├── seo_functions.php        # SEO optimization functions
│   ├── header.php / footer.php  # Frontend layout templates
│
├── /admin/                       # Admin management system (physical dir; served at /geo_admin/ URL via router.php)
│   ├── dashboard.php            # Dashboard (data statistics)
│   ├── tasks-new.php            # Task management main page
│   ├── articles-new.php         # Article management main page
│   ├── ai-configurator.php      # AI configuration center
│   ├── ai-models.php            # AI model management
│   ├── ai-prompts.php           # Prompt management
│   ├── materials-new.php        # Material library entry
│   ├── start_task_batch.php     # Batch task queue launcher ⭐Core
│   └── includes/header.php      # Admin layout template
│
├── index.php                     # Frontend homepage
├── article.php                   # Article detail page
├── bin/
│   ├── worker.php                # job_queue worker process ⭐Core
│   └── cron.php                  # Task scheduler
├── router.php                    # URL routing (development environment)
├── install.php                   # Installation script
│
├── data/db/blog.db              # Legacy SQLite database (project-relative, migration-only)
├── /logs/                        # Log directory
│   └── batch_{task_id}.log      # Per-task batch execution logs
│
└── /uploads/                     # Uploaded files
    ├── /images/                 # Article images
    └── /knowledge/              # Knowledge base files
```

### 2. Core Class Details

#### Database Class (includes/database.php)
```php
// Singleton pattern, globally unique database connection
class Database {
    private static $instance = null;
    private $pdo;
    
    // Get instance
    public static function getInstance()
    
    // Core methods
    public function query($sql, $params = [])      // Execute query
    public function fetchOne($sql, $params = [])   // Fetch single record
    public function fetchAll($sql, $params = [])   // Fetch multiple records
    public function insert($table, $data)          // Insert record
    public function update($table, $data, $where, $params) // Update record
    public function delete($table, $where, $params) // Delete record
    public function count($table, $where, $params)  // Count records
}

// Global usage
$db = Database::getInstance()->getPDO();
```

#### AIEngine Class (includes/ai_engine.php)
```php
// AI content generation engine - system core
class AIEngine {
    // Main methods
    public function executeTask($task_id)  // Execute task, generate one article
    
    // Workflow:
    // 1. Get task configuration
    // 2. Check draft limit
    // 3. Get unused title from title library
    // 4. Call AI to generate content
    // 5. Insert images (if configured)
    // 6. Generate keywords and description
    // 7. Save article as draft
    // 8. Update statistics
}
```

#### TaskLifecycleService Class (includes/task_lifecycle_service.php)
```php
// Task lifecycle management - handles task state transitions via job_queue
class TaskLifecycleService {
    // Manages task status transitions through the job_queue pipeline
    // Works with JobQueueService to enqueue, track, and update task execution
    
    // Core methods
    public function startBatchExecution($task_id)  // Enqueue task execution job
    public function stopBatchExecution($task_id)   // Request stop for running task
    public function getTaskExecutionStatus($task_id) // Get current task/job status
}
```

#### JobQueueService Class (includes/job_queue_service.php)
```php
// Job queue orchestration - manages background job processing
class JobQueueService {
    // Core methods
    public function enqueue($job_type, $payload)   // Add job to queue
    public function claimJob($worker_id)           // Claim next pending job
    public function completeJob($job_id, $result)  // Mark job as completed
    public function failJob($job_id, $error)       // Mark job as failed
}
```

---

## 🗄️ Database Structure (Core Tables)

### Article-Related Tables
```sql
-- Articles table (core content table)
articles (
    id, title, slug, excerpt, content,
    category_id, author_id, task_id,        -- task_id links to AI generation task
    keywords, meta_description,             -- SEO fields
    status,                                 -- draft/published/private/deleted
    review_status,                          -- pending/approved/rejected
    is_featured, view_count, like_count,
    created_at, updated_at, published_at
)

-- Categories table
categories (id, name, slug, description)

-- Tags table
tags (id, name, slug)

-- Article-tag association table (many-to-many)
article_tags (article_id, tag_id)
```

### AI Task-Related Tables
```sql
-- Tasks table (core configuration table)
tasks (
    id, name,
    title_library_id,                       -- Title library ID
    image_library_id, image_count,          -- Image configuration
    prompt_id, ai_model_id,                 -- AI configuration
    author_id,                              -- Author (NULL = random)
    need_review,                            -- Whether review is required
    publish_interval,                       -- Publishing interval (seconds)
    draft_limit,                            -- Draft count limit
    is_loop,                                -- Whether to generate in a loop
    status,                                 -- active/paused/completed
    batch_status,                           -- Batch execution status
    batch_started_at, batch_stopped_at,     -- Batch execution timestamps
    created_count, published_count          -- Statistics
)

-- Title library collections
title_libraries (id, name, description)

-- Titles table
titles (
    id, library_id, title, keyword,
    is_used, used_count, used_at
)

-- AI model configuration table
ai_models (
    id, name, version, api_key, model_id,
    api_url,                                -- API endpoint
    daily_limit, used_today, total_used,    -- Usage limits
    status
)

-- Prompt template table
prompts (
    id, name, type, content,
    variables                               -- Supported variables
)
```

### Material Library Tables
```sql
-- Image library collections
image_libraries (id, name, description)

-- Images table
images (id, library_id, filename, url, alt_text)

-- Knowledge base table
knowledge_bases (id, name, content, type)

-- Authors table
authors (id, name, bio, avatar)
```

---

## 🔄 Core Workflows

### 1. AI Content Generation Flow (Complete Pipeline)

```
User Action: Create task in admin panel
    ↓
Configure Task Parameters:
    - Select title library (required)
    - Select AI model and prompt (required)
    - Select image library (optional)
    - Set publishing interval, draft limit, etc.
    ↓
Start Batch Execution:
    User clicks "Start Batch Execution" button
    ↓
start_task_batch.php:
    1. Verify admin permissions
    2. Check task status and whether it can be started
    3. Update task status / execution metadata as needed
    4. Enqueue a task execution job via JobQueueService
    5. Return the enqueue result to the admin panel
    ↓
job_queue + bin/worker.php:
    1. The queued job is stored in the job_queue table
    2. bin/worker.php continuously polls for pending jobs
    3. After claiming the job, the worker executes the task
    4. During execution, the worker:
        a. Checks task status / stop conditions
        b. Checks draft limit and other execution constraints
        c. Calls AIEngine::executeTask()
        d. Reschedules or continues according to the publishing interval / queue logic
    5. Troubleshooting focus:
        a. Verify the corresponding record in job_queue
        b. Confirm bin/worker.php is running and consuming jobs
        c. Check worker logs / task status updates instead of PID files
    ↓
AIEngine::executeTask():
    1. Get task configuration
    2. Get unused title from title library
    3. Build prompt (replace variables)
    4. Call AI API to generate content
    5. Insert images (if configured)
    6. Generate keywords and description (if enabled)
    7. Save article (status='draft', review_status='pending')
    8. Update statistics
    ↓
Article Review (if required):
    Admin reviews in articles-review.php
    ↓
Publish Article:
    - Auto-publish: Automatically set status='published' after review approval
    - Manual publish: Admin manually changes status
    ↓
Frontend Display:
    index.php displays published articles
```

### 2. Process Management Flow

```
Start Process:
    admin/start_task_batch.php
    ↓
    Validate batch/task parameters
    ↓
    Create one or more records in job_queue
        - initial job status: 'pending' / 'queued' (depending on job type)
    ↓
    Update batch/task record to reflect that work has been submitted
    ↓
    bin/worker.php polls job_queue
    ↓
    Worker claims an available job
        - job status: queued → running
    ↓
    Worker executes content generation / processing logic
    ↓
    Persist result and update job status
        - running → completed
        - running → failed
        - running → retry/queued (if retry logic applies)

Stop Process:
    User clicks "Stop" button for a batch/task
    ↓
    System updates database state to request stop/cancel
        - prevents undispatched queued jobs from being started
        - in-progress jobs are handled by normal worker/job state checks
    ↓
    No PID files or stop-flag files are used by the current implementation
    ↓
    Batch/task status is derived from the underlying job_queue records
        - all jobs completed      → batch completed
        - any jobs failed         → batch failed/partial failure (per business rules)
        - remaining jobs canceled → batch stopped/canceled

Monitoring / Health Check:
    Primary source of truth: job_queue status transitions
    ↓
    Worker liveness: worker_heartbeats
    ↓
    Typical checks:
        1. Detect workers that stopped heartbeating
        2. Find jobs stuck in running for too long
        3. Requeue or mark stalled jobs according to recovery rules
```

### 3. Scheduled Task Dispatch Flow

```
Cron Task: */5 * * * * php bin/cron.php
    ↓
bin/cron.php:
    1. Query task_schedules table
    2. Find tasks where next_run_time <= current time
    3. Execute TaskService::executeTask()
    4. Update next_run_time = current time + publish_interval
    
Note: 
- bin/cron.php is for single execution (generates 1 article per run)
- bin/worker.php is the long-running queue consumer for batch execution (continuous generation)
```

---

## 🔑 Key Configuration Details

### 1. Environment Configuration (includes/config.php)
```php
// PostgreSQL connection (configured via environment variables: DB_HOST, DB_NAME, DB_USER, DB_PASSWORD)
// Legacy SQLite path, used only for migration and compatibility scripts
define('DB_PATH', db_get_sqlite_path());

// Default admin account
define('ADMIN_USERNAME', 'admin');
define('ADMIN_PASSWORD', '$2y$12$...');  // Password: admin888

// Security configuration
define('SESSION_TIMEOUT', 3600);  // Session timeout 1 hour
define('SECRET_KEY', 'your-secret-key-change-this-in-production');
```

### 2. AI API Configuration
```
Supported Providers (any OpenAI-compatible /v1/chat/completions endpoint):
- MiniMax:  https://api.minimax.io   (models: MiniMax-M2.7, MiniMax-M2.7-highspeed)
- OpenAI:   https://api.openai.com   (models: gpt-4o, etc.)
- DeepSeek: https://api.deepseek.com (models: deepseek-chat, etc.)
- Tu-zi:    https://api.tu-zi.com    (aggregator, supports many models)

Authentication: Bearer Token
Request Format: OpenAI-compatible
```

### 3. Prompt Variable System
```
Supported Variables:
- {title}      - Article title
- {keyword}    - Keyword
- {Knowledge}  - Knowledge base content

Usage Example:
"Please write an article based on the title '{title}' and the keyword '{keyword}'..."
```

---

## 🛠️ Development Standards

### 1. File Header Standard
```php
<?php
/**
 * File description
 *
 * @author Yao Jingang
 * @version 1.0
 * @date YYYY-MM-DD
 */

// Prevent direct access
if (!defined('FEISHU_TREASURE')) {
    die('Access denied');
}
```

### 2. Database Operation Standards
```php
// ✅ Correct: Use prepared statements
$stmt = $db->prepare("SELECT * FROM articles WHERE id = ?");
$stmt->execute([$id]);

// ❌ Wrong: Direct SQL concatenation (SQL injection risk)
$sql = "SELECT * FROM articles WHERE id = $id";
```

### 3. Logging Standards
```php
// Use the write_log() function
write_log("Operation description", 'INFO');   // Levels: INFO, WARNING, ERROR, DEBUG
```

### 4. Security Standards
```php
// Must escape when outputting to HTML
echo htmlspecialchars($user_input);

// CSRF protection
generate_csrf_token();  // Generate token
verify_csrf_token();    // Verify token

// Input validation
$clean_input = sanitize_input($_POST['data']);
```

---

## 🚀 Quick Start Development Guide

### Starting the Development Environment
```bash
# 1. Start the PHP server
./start.sh
# or
php -S localhost:8080 router.php

# 2. Access the system
Frontend: http://localhost:8080
Admin Panel: http://localhost:8080/geo_admin/
```

### Common Development Tasks

#### Adding a New AI Model
```
1. Admin Panel → AI Configuration Center → AI Model Management
2. Click "Add Model"
3. Fill in: Name, API Key, Model ID, API Endpoint
4. Save and it's ready to use in tasks
```

#### Creating a New Prompt Template
```
1. Admin Panel → AI Configuration Center → Prompt Management
2. Click "Add Prompt"
3. Fill in: Name, Type, Content
4. Use variables: {title}, {keyword}, {Knowledge}
```

#### Debugging Batch Execution
```bash
# View real-time logs
tail -f logs/batch_{task_id}.log

# View task manager logs
tail -f logs/task_manager_$(date +%Y-%m-%d).log

# Check process status (direct deployment)
ps aux | grep bin/worker.php

# Check worker container status (Docker deployment)
docker ps --filter "name=worker"
```

---

## 🐛 Common Troubleshooting

### Issue 1: Batch Execution Cannot Start
```
Troubleshooting Steps:
1. Check if task status is 'active'
2. Check for orphaned processes: Admin Panel → System Diagnostics
3. View logs: logs/batch_{task_id}.log and worker-related logs
4. Check `job_queue` and `worker_heartbeats` for stalled jobs or stale workers; if needed, restart `bin/worker.php` (or the worker container)
```

### Issue 2: AI Generation Failure
```
Troubleshooting Steps:
1. Check AI model configuration (is the API key correct?)
2. Check daily call limits
3. View error logs
4. Test API connection: admin/system_diagnostics.php
```

### Issue 3: Articles Not Displaying
```
Troubleshooting Steps:
1. Check article status: status='published'
2. Check review status: review_status='approved'
3. Check if category exists
4. Clear cache (if enabled)
```

---

## 📝 Important Notes

### Security Notes
1. ⚠️ Change the admin password immediately after first use
2. ⚠️ Must change SECRET_KEY in production environment
3. ⚠️ Regularly back up the PostgreSQL database (e.g., using `pg_dump`)
4. ⚠️ Do not use PHP's built-in server in production

### Performance Notes
1. PostgreSQL is the runtime database and scales well for production workloads
2. Pay attention to publish_interval settings during batch execution (avoid API rate limiting)
3. Regularly clean up log files
4. External CDN is recommended for images

### Development Notes
1. All PHP files must include the `define('FEISHU_TREASURE', true);` check
2. Database operations must use prepared statements
3. Restart batch execution processes after modifying core classes
4. Test files (test-*.php) should not be deployed to production

---

## 📚 Further Reading

### Related Files
- `系统说明文档.md` - User manual
- `install.php` - Installation wizard
- `admin/system_diagnostics.php` - System diagnostics tool

### Technical Documentation
- PHP PDO: https://www.php.net/manual/zh/book.pdo.php
- PostgreSQL: https://www.postgresql.org/docs/
- TailwindCSS: https://tailwindcss.com/docs

---

**Document Maintenance**: Please update this document after each major update
**Contact**: Project Author - Yao Jingang
