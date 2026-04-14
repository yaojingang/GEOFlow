# API / CLI Phase 1 Implementation Plan

## 1. Document Purpose

This document defines the formal implementation plan for `GEOFlow`'s Phase 1 external interface system.

The goal is not to refactor the existing system, but to add a new set of formal APIs that can be called by a local CLI and subsequent skills, **without breaking the existing admin panel, scheduler, queue, worker, or database semantics**.

This document focuses on answering four questions:

1. What is the real backend architecture and business pipeline of the current system.
2. Among the existing pages and so-called "endpoints", which logic can be reused and which cannot be directly exposed externally.
3. What should the Phase 1 API achieve, and where are the boundaries.
4. When implementing Phase 1, specifically which files need to be added, which files need minor modifications, and which parts should explicitly not be changed.

---

## 2. Phase 1 Final Objectives

Phase 1 only requires enabling two main pipelines:

### 2.1 Task Pipeline

Enable the local CLI to:

- Retrieve basic resources such as models, prompts, title libraries, authors, and categories
- Create tasks
- View tasks
- Update tasks
- Start tasks
- Stop tasks
- Manually enqueue tasks
- Query job execution status

### 2.2 Article Pipeline

Enable the local CLI to:

- Query articles
- Directly upload article drafts
- Update articles
- Review articles
- Publish articles
- Soft-delete articles

These two pipelines cover the two most important usage patterns for subsequent skills:

1. Create tasks locally, remotely trigger the system to automatically generate articles
2. Generate articles locally first, then publish them to the system directly via the remote API

---

## 3. Phase 1 Principles

### 3.1 Do Not Modify the Existing Main Architecture

Phase 1 does not change the existing main operational model:

- The admin panel is still used for manual operations
- `cron` still handles scheduling
- `job_queue` still handles queuing
- `worker` still handles AI task execution
- PostgreSQL remains the single source of truth

Related core files:

- `/Users/laoyao/AI Coding/01-Projects/Active/GEO官网系统/bin/cron.php`
- `/Users/laoyao/AI Coding/01-Projects/Active/GEO官网系统/bin/worker.php`
- `/Users/laoyao/AI Coding/01-Projects/Active/GEO官网系统/includes/job_queue_service.php`
- `/Users/laoyao/AI Coding/01-Projects/Active/GEO官网系统/includes/ai_engine.php`

### 3.2 Do Not Override Existing Database Semantics

Phase 1 does not refactor existing core tables:

- `tasks`
- `articles`
- `article_reviews`
- `ai_models`
- `prompts`
- `title_libraries`
- `knowledge_bases`
- `job_queue`
- `task_runs`

New database objects are only allowed for API infrastructure and must not change the business source of truth:

- `api_tokens`
- Optional: `api_idempotency_keys`

### 3.3 Do Not Directly Reuse Admin Ajax Endpoints

Phase 1 must not allow the CLI to directly call these existing page-coupled endpoints:

- `/Users/laoyao/AI Coding/01-Projects/Active/GEO官网系统/admin/start_task_batch.php`
- `/Users/laoyao/AI Coding/01-Projects/Active/GEO官网系统/admin/get_task_status.php`
- `/Users/laoyao/AI Coding/01-Projects/Active/GEO官网系统/admin/title_generate_async.php`
- `/Users/laoyao/AI Coding/01-Projects/Active/GEO官网系统/admin/url-import-start.php`

Reasons:

- These endpoints depend on admin sessions
- Depend on CSRF
- Depend on admin page context
- Some logic still executes synchronously within the request
- They lack stable scope / token / idempotency / versioning standards

Therefore, what Phase 1 should reuse is the **existing business logic**, not the existing admin endpoint files themselves.

### 3.4 Build the API Adaptation Layer First, Then CLI, Then Skills

The order must be:

1. First, organize the server-side API layer
2. Then build the local CLI
3. Finally, have skills call the CLI

The reason is simple:

- Skills should not carry business state machines
- The CLI should not bypass the server to directly access the database
- The API is the stable boundary for cross-platform reuse going forward

---

## 4. Current System's Real Backend Architecture

### 4.1 Page Layer

The current admin panel follows a typical "page as controller" pattern.

Each admin page typically handles all of these responsibilities simultaneously:

- Admin authentication
- CSRF validation
- Processing POST requests
- Directly querying the database
- Assembling page output

Typical files:

- `/Users/laoyao/AI Coding/01-Projects/Active/GEO官网系统/admin/task-create.php`
- `/Users/laoyao/AI Coding/01-Projects/Active/GEO官网系统/admin/tasks.php`
- `/Users/laoyao/AI Coding/01-Projects/Active/GEO官网系统/admin/articles.php`
- `/Users/laoyao/AI Coding/01-Projects/Active/GEO官网系统/admin/articles-review.php`
- `/Users/laoyao/AI Coding/01-Projects/Active/GEO官网系统/admin/article-create.php`
- `/Users/laoyao/AI Coding/01-Projects/Active/GEO官网系统/admin/article-edit.php`
- `/Users/laoyao/AI Coding/01-Projects/Active/GEO官网系统/admin/article-view.php`

This is the current state, not a problem in itself. Phase 1 does not require converting the admin panel to MVC.

### 4.2 Data Layer

The admin runtime database schema is managed by `database_admin.php` for table creation and schema updates:

- `/Users/laoyao/AI Coding/01-Projects/Active/GEO官网系统/includes/database_admin.php`

Core business tables include:

- `tasks`
- `articles`
- `article_reviews`
- `task_schedules`
- `job_queue`
- `task_runs`
- `worker_heartbeats`
- `ai_models`
- `prompts`
- `authors`
- `categories`

### 4.3 Scheduling and Execution Layer

The system no longer uses a "click in the admin panel and synchronously generate articles" model. Instead, it follows a standard asynchronous pipeline:

1. Admin panel creates a task
2. Scheduler scans active tasks
3. Job enters the queue
4. Worker picks up the job
5. AI engine generates the article
6. Article enters the draft / review / publish workflow

Key files:

- `/Users/laoyao/AI Coding/01-Projects/Active/GEO官网系统/bin/cron.php`
- `/Users/laoyao/AI Coding/01-Projects/Active/GEO官网系统/includes/job_queue_service.php`
- `/Users/laoyao/AI Coding/01-Projects/Active/GEO官网系统/bin/worker.php`
- `/Users/laoyao/AI Coding/01-Projects/Active/GEO官网系统/includes/ai_engine.php`

This pipeline is the biggest reuse foundation for the Phase 1 API.

---

## 5. Current Business State Most Relevant to the API

## 5.1 Task Creation

Current task creation page:

- `/Users/laoyao/AI Coding/01-Projects/Active/GEO官网系统/admin/task-create.php`

Existing behavior:

- Validates `task_name`
- Validates `title_library_id`
- Validates `prompt_id` must be of type `content`
- Validates `ai_model_id` must be an enabled chat model
- Allows configuration of:
  - Title library
  - Image library / number of images
  - Content prompt
  - AI model
  - Review strategy
  - Publishing interval
  - Author
  - Keyword / description auto-generation
  - Draft limit
  - Whether to loop
  - Status
  - Knowledge base
  - Category strategy
- Inserts into `tasks`
- Calls `JobQueueService::initializeTaskSchedule`
- Active tasks are inserted into `task_schedules`

This indicates:

- When creating tasks via API, the same validation rules should be directly reused
- A separate set of "task field semantics" should not be redefined

## 5.2 Task Start/Stop and Manual Enqueue

There are currently two related sets of logic:

1. In-page status toggle:
   `/Users/laoyao/AI Coding/01-Projects/Active/GEO官网系统/admin/tasks.php`

2. JSON endpoint for manual start/stop and enqueue:
   `/Users/laoyao/AI Coding/01-Projects/Active/GEO官网系统/admin/start_task_batch.php`

Existing behavior:

- On start:
  - Task status changes to `active`
  - `schedule_enabled = 1`
  - `next_run_at = CURRENT_TIMESTAMP`
  - Calls `enqueueTaskJob`
- On stop:
  - Task status changes to `paused`
  - `schedule_enabled = 0`
  - `next_run_at = NULL`
  - Cancels `pending` jobs
  - `running` jobs are not forcefully killed; they are allowed to complete naturally

This means:

- The `start`, `stop`, and `enqueue` operations in the Phase 1 API should all reuse these rules
- Do not invent new semantics like "stopping a task immediately kills the worker"

## 5.3 Task Execution Status

The current `tasks.php` page already displays data that is very suitable for API exposure:

- Task basic information
- `pending_jobs`
- `running_jobs`
- `batch_status`
- `last_run_at`
- `next_run_at`
- `task_runs` success / failure counts
- `worker_heartbeats`
- Recent jobs

Therefore:

- Phase 1 `GET /tasks/{id}` and `GET /tasks/{id}/jobs` can be directly based on this page's data model
- There is no need to redefine a new "monitoring view"

## 5.4 Article Creation and Editing

Current manual article creation page:

- `/Users/laoyao/AI Coding/01-Projects/Active/GEO官网系统/admin/article-create.php`

Current article editing page:

- `/Users/laoyao/AI Coding/01-Projects/Active/GEO官网系统/admin/article-edit.php`

Existing behavior:

- Title and content are required
- Automatically generates a unique slug
- Summary can be auto-extracted
- Can set category, author, keywords, meta description
- Can directly specify `status`
- Can directly specify `review_status`
- But ultimately always goes through `normalize_article_workflow_state`

This indicates:

- When creating / updating articles via API, the unified slug generation strategy must be reused
- The unified workflow convergence logic must be reused
- The CLI must not be allowed to bypass workflow rules and directly write conflicting states

## 5.5 Article Review and Publishing

Current related files:

- `/Users/laoyao/AI Coding/01-Projects/Active/GEO官网系统/admin/articles-review.php`
- `/Users/laoyao/AI Coding/01-Projects/Active/GEO官网系统/admin/article-view.php`
- `/Users/laoyao/AI Coding/01-Projects/Active/GEO官网系统/admin/articles.php`
- `/Users/laoyao/AI Coding/01-Projects/Active/GEO官网系统/includes/functions.php`

Core rules are in:

- `normalize_article_workflow_state()`

Existing workflow characteristics:

- `pending` / `rejected` converge to `draft`
- `published` cannot coexist with `pending`
- `auto_approved` converges to published status
- `published_at` is automatically synced by the workflow
- Review records are written to `article_reviews`

Additional rules:

- If the source task has `need_review = 0`
- After review approval, the article can directly enter `published` status

Therefore:

- The Phase 1 API should not treat "publish" and "review" as independent, unconstrained field updates
- They must be handled uniformly through the service layer

---

## 6. Existing "API-Ready Capabilities" Worth Leveraging

Although the current system has no formal public API, there are some API-oriented tendencies worth leveraging:

### 6.1 Unified JSON Response Utility

- `/Users/laoyao/AI Coding/01-Projects/Active/GEO官网系统/includes/config.php`

The existing `json_response()` can continue to be reused, but a standard envelope needs to be wrapped on top at the API layer.

### 6.2 Queue and Task Execution Status Are Already Structured

- `job_queue`
- `task_runs`
- `worker_heartbeats`

These tables are already very suitable for machine-to-machine calls.

### 6.3 Admin Pages Have Already Established Real Field and Status Semantics

This means the API does not need to guess business rules. Instead, it can extract the already-stable behaviors from the pages into a service layer.

---

## 7. Parts of the Current System Not Suitable for Formal API Reuse

## 7.1 Session-Based "Async Endpoints"

- `/Users/laoyao/AI Coding/01-Projects/Active/GEO官网系统/admin/title_generate_async.php`

Issues:

- State is stored in `$_SESSION`
- Depends on browser login state
- Not suitable for CLI
- Not suitable for worker / multi-instance scenarios

Explicitly excluded from the Phase 1 formal API.

## 7.2 In-Request Synchronous Scraping Endpoints

- `/Users/laoyao/AI Coding/01-Projects/Active/GEO官网系统/admin/url-import-start.php`

Issues:

- Appears to return JSON
- Actually calls `run_url_import_pipeline()` synchronously within the request
- Not a standard async task pattern

Explicitly excluded from Phase 1.

## 7.3 Ajax Endpoints Dependent on Admin Session / CSRF

Examples:

- `start_task_batch.php`
- `get_task_status.php`

Although these endpoints can return JSON, their identity model is coupled to the admin page. They are not suitable for direct CLI integration.

---

## 8. Proposed API System for Phase 1

Phase 1 proposes adding a separate:

- `/api/v1`

Using versioning, machine authentication, and a unified response structure.

## 8.1 Resource Boundaries

Phase 1 only exposes 4 resource types:

- `catalog`
- `tasks`
- `jobs`
- `articles`

This is sufficient to support the first round of end-to-end workflows for the CLI and subsequent skills.

## 8.2 Recommended Endpoints

### Catalog

- `GET /api/v1/catalog`

### Tasks

- `GET /api/v1/tasks`
- `POST /api/v1/tasks`
- `GET /api/v1/tasks/{id}`
- `PATCH /api/v1/tasks/{id}`
- `POST /api/v1/tasks/{id}/start`
- `POST /api/v1/tasks/{id}/stop`
- `POST /api/v1/tasks/{id}/enqueue`
- `GET /api/v1/tasks/{id}/jobs`

### Jobs

- `GET /api/v1/jobs/{id}`

### Articles

- `GET /api/v1/articles`
- `POST /api/v1/articles`
- `GET /api/v1/articles/{id}`
- `PATCH /api/v1/articles/{id}`
- `POST /api/v1/articles/{id}/review`
- `POST /api/v1/articles/{id}/publish`
- `POST /api/v1/articles/{id}/trash`

---

## 9. Authentication Scheme

Phase 1 cannot reuse admin panel sessions.

A new Bearer Token mechanism should be added.

### 9.1 New Table: `api_tokens`

Suggested fields:

- `id`
- `name`
- `token_hash`
- `scopes`
- `status`
- `created_by_admin_id`
- `last_used_at`
- `expires_at`
- `created_at`
- `updated_at`

Design principles:

- Only store the hash, never the plaintext token
- The token is displayed only once
- Scopes must be granular
- Allow revocation

### 9.2 Phase 1 Scopes

- `catalog:read`
- `tasks:read`
- `tasks:write`
- `jobs:read`
- `articles:read`
- `articles:write`
- `articles:publish`

### 9.3 Things Not Being Done

Phase 1 does not include:

- OAuth
- Session reuse
- Multi-tenancy
- User-level fine-grained data isolation

---

## 10. Request and Response Protocol

### 10.1 Request Headers

```http
Authorization: Bearer gf_xxxxxxxxx
Content-Type: application/json
Accept: application/json
X-Request-Id: optional
X-Idempotency-Key: optional
```

### 10.2 Unified Success Response

```json
{
  "success": true,
  "data": {},
  "error": null,
  "meta": {
    "request_id": "req_xxx",
    "timestamp": "2026-04-13T20:00:00+08:00"
  }
}
```

### 10.3 Unified Error Response

```json
{
  "success": false,
  "data": null,
  "error": {
    "code": "validation_failed",
    "message": "Parameter validation failed"
  },
  "meta": {
    "request_id": "req_xxx",
    "timestamp": "2026-04-13T20:00:00+08:00"
  }
}
```

### 10.4 HTTP Status Code Conventions

- `200` Query successful
- `201` Creation successful
- `202` Accepted
- `400` Parameter error
- `401` Unauthenticated
- `403` Insufficient permissions
- `404` Resource not found
- `409` State conflict or idempotency conflict
- `422` Business validation failed
- `500` Server error

---

## 11. Phase 1 Service Layer Design

Phase 1 should not pile SQL directly in the API entry points.

Three minimal service layers should be added.

## 11.1 `CatalogService`

Responsibilities:

- Read models
- Read prompts
- Read title libraries
- Read knowledge bases
- Read authors
- Read categories

Data sources:

- `ai_models`
- `prompts`
- `title_libraries`
- `knowledge_bases`
- `authors`
- `categories`

Notes:

- This is the simplest layer
- It is also the first layer the CLI needs during initialization

## 11.2 `TaskLifecycleService`

Responsibilities:

- Create tasks
- Update tasks
- View task list
- View task details
- Start tasks
- Stop tasks
- Manual enqueue
- Query task-associated jobs

Should reuse:

- `/Users/laoyao/AI Coding/01-Projects/Active/GEO官网系统/admin/task-create.php`
- `/Users/laoyao/AI Coding/01-Projects/Active/GEO官网系统/admin/tasks.php`
- `/Users/laoyao/AI Coding/01-Projects/Active/GEO官网系统/admin/start_task_batch.php`
- `/Users/laoyao/AI Coding/01-Projects/Active/GEO官网系统/includes/task_service.php`
- `/Users/laoyao/AI Coding/01-Projects/Active/GEO官网系统/includes/job_queue_service.php`

Notes:

- `TaskService` can serve as the underlying foundation
- But currently it is not complete enough to directly serve as the API service layer
- The more stable approach for Phase 1 is to add a new `TaskLifecycleService` as a facade closer to business behavior

## 11.3 `ArticleService`

Responsibilities:

- Query article list
- Query article details
- Create articles
- Update articles
- Review articles
- Publish articles
- Delete articles

Should reuse:

- `/Users/laoyao/AI Coding/01-Projects/Active/GEO官网系统/admin/article-create.php`
- `/Users/laoyao/AI Coding/01-Projects/Active/GEO官网系统/admin/article-edit.php`
- `/Users/laoyao/AI Coding/01-Projects/Active/GEO官网系统/admin/article-view.php`
- `/Users/laoyao/AI Coding/01-Projects/Active/GEO官网系统/admin/articles.php`
- `/Users/laoyao/AI Coding/01-Projects/Active/GEO官网系统/admin/articles-review.php`
- `/Users/laoyao/AI Coding/01-Projects/Active/GEO官网系统/includes/functions.php`

Specific requirements:

- Must reuse `normalize_article_workflow_state()`
- Must reuse the existing slug generation and uniqueness strategy
- Review changes must continue to be written to `article_reviews`

---

## 12. API Entry Point Design

Phase 1 does not recommend creating a bunch of scattered `api/*.php` files.

A single entry point is recommended:

- `/Users/laoyao/AI Coding/01-Projects/Active/GEO官网系统/api/v1/index.php`

With supporting base components:

- `includes/api_auth.php`
- `includes/api_request.php`
- `includes/api_response.php`
- `includes/api_token_service.php`

### 12.1 Benefits of a Single Entry Point

- Unified routing rules
- Unified authentication
- Unified error output
- Better suited for future OpenAPI documentation
- Better suited for long-term CLI/skill maintenance

### 12.2 Minimal Router Changes

Only need to add in:

- `/Users/laoyao/AI Coding/01-Projects/Active/GEO官网系统/router.php`

A single `/api/v1/*` route mapping.

This is a low-intrusion integration.

---

## 13. Phase 1 Database Change Recommendations

## 13.1 Required Addition: `api_tokens`

Purpose:

- Provide formal machine credentials for the CLI and subsequent skills

This is the only truly necessary new peripheral business table in Phase 1.

## 13.2 Optional: `api_idempotency_keys`

Purpose:

- Prevent duplicate task or article creation when CLI retries

Recommendation:

- If Phase 1 aims for more stability, add it
- If the goal is to ship quickly first, it can be deferred

## 13.3 Tables Explicitly Not Modified

Phase 1 does not refactor these table structures:

- `tasks`
- `articles`
- `article_reviews`
- `job_queue`
- `task_runs`
- `worker_heartbeats`

Adding indexes is allowed, but existing field business semantics must not be changed.

---

## 14. Files to Be Added in Phase 1

### API Entry Point and Infrastructure

- `/Users/laoyao/AI Coding/01-Projects/Active/GEO官网系统/api/v1/index.php`
- `/Users/laoyao/AI Coding/01-Projects/Active/GEO官网系统/includes/api_auth.php`
- `/Users/laoyao/AI Coding/01-Projects/Active/GEO官网系统/includes/api_request.php`
- `/Users/laoyao/AI Coding/01-Projects/Active/GEO官网系统/includes/api_response.php`
- `/Users/laoyao/AI Coding/01-Projects/Active/GEO官网系统/includes/api_token_service.php`

### Business Service Layer

- `/Users/laoyao/AI Coding/01-Projects/Active/GEO官网系统/includes/catalog_service.php`
- `/Users/laoyao/AI Coding/01-Projects/Active/GEO官网系统/includes/task_lifecycle_service.php`
- `/Users/laoyao/AI Coding/01-Projects/Active/GEO官网系统/includes/article_service.php`

### Documentation

- `/Users/laoyao/AI Coding/01-Projects/Active/GEO官网系统/docs/project/API_CLI_PHASE1_PLAN.md`
- To be added later: `/Users/laoyao/AI Coding/01-Projects/Active/GEO官网系统/docs/project/API_V1_REFERENCE.md`

---

## 15. Existing Files Requiring Minor Modifications in Phase 1

## 15.1 Router

- `/Users/laoyao/AI Coding/01-Projects/Active/GEO官网系统/router.php`

Changes:

- Add `/api/v1/*` route mapping

## 15.2 Database Initialization

- `/Users/laoyao/AI Coding/01-Projects/Active/GEO官网系统/includes/database_admin.php`

Changes:

- Add `api_tokens` table
- Optionally add `api_idempotency_keys` table

## 15.3 TaskService

- `/Users/laoyao/AI Coding/01-Projects/Active/GEO官网系统/includes/task_service.php`

Changes:

- Only make necessary enhancements
- No requirement to refactor into a perfect service layer

Suggested enhancements:

- Complete the allowed fields for task creation / update to match the admin page
- Complete support for `knowledge_base_id`, `category_mode`, and `fixed_category_id`

---

## 16. Areas Explicitly Not to Be Changed in Phase 1

The following are explicitly excluded from Phase 1:

### 16.1 Do Not Modify Worker Main Loop

- `/Users/laoyao/AI Coding/01-Projects/Active/GEO官网系统/bin/worker.php`

### 16.2 Do Not Modify Cron Scheduling Strategy

- `/Users/laoyao/AI Coding/01-Projects/Active/GEO官网系统/bin/cron.php`

### 16.3 Do Not Modify AI Engine Main Generation Logic

- `/Users/laoyao/AI Coding/01-Projects/Active/GEO官网系统/includes/ai_engine.php`

### 16.4 Do Not Modify URL Import System

- `/Users/laoyao/AI Coding/01-Projects/Active/GEO官网系统/admin/url-import-start.php`
- `/Users/laoyao/AI Coding/01-Projects/Active/GEO官网系统/admin/url-import-status.php`
- `/Users/laoyao/AI Coding/01-Projects/Active/GEO官网系统/admin/url-import-commit.php`

### 16.5 Do Not Modify Title Async Generation System

- `/Users/laoyao/AI Coding/01-Projects/Active/GEO官网系统/admin/title_generate_async.php`

### 16.6 Do Not Refactor Admin Pages

Phase 1 admin pages continue to work as-is.

The API is an overlay layer, not an admin replacement layer.

---

## 17. Phased Implementation Recommendations for Phase 1

## 17.1 Step 1: API Infrastructure

Deliverables:

- Bearer Token validation
- Unified request parsing
- Unified JSON output
- Request ID

Acceptance criteria:

- Accessing `/api/v1/catalog` without a token returns `401`
- Accessing with a valid token returns data normally

## 17.2 Step 2: Catalog API

Deliverables:

- `GET /api/v1/catalog`

Acceptance criteria:

- CLI can retrieve model, prompt, title library, knowledge base, author, and category IDs

## 17.3 Step 3: Task API

Deliverables:

- Task list
- Task creation
- Task details
- Task update
- Start / stop / enqueue
- Task jobs query
- Single job query

Acceptance criteria:

- Local CLI can complete the full workflow:
  - Create a task
  - Start the task
  - Manually enqueue
  - Query the job
  - Wait for worker completion

## 17.4 Step 4: Article API

Deliverables:

- Query articles
- Create articles
- Update articles
- Review articles
- Publish articles
- Delete articles

Acceptance criteria:

- A local Markdown content file can be submitted to the system via API
- Can be reviewed / published
- Accessible on the frontend

## 17.5 Step 5: CLI Integration

After Phase 1 API is complete, begin CLI development.

Suggested command structure:

- `geoflow catalog`
- `geoflow task create`
- `geoflow task start`
- `geoflow task enqueue`
- `geoflow job get`
- `geoflow article create`
- `geoflow article publish`

---

## 18. Phase 1 Key Risks

## 18.1 Risk 1: Turning the API into a "Copy of the Admin Pages"

This is the most common pitfall.

Wrong approach:

- Directly copying SQL and form logic from `admin/*.php`

Issues:

- When the admin panel changes in one place, the API is forgotten
- Long-term drift
- Duplicate business rule maintenance

Mitigation:

- Build the service layer first
- API should only call the service layer

## 18.2 Risk 2: State Machine Being Bypassed

The most dangerous area is the article workflow.

If the API allows arbitrary writes such as:

- `status = published`
- `review_status = pending`

It will directly corrupt the existing system's state semantics.

Mitigation:

- All article state updates must be unified and converged through `normalize_article_workflow_state()`

## 18.3 Risk 3: CLI Directly Depending on Admin Endpoints

Looks fast in the short term, but will be very fragile long-term.

Reasons:

- Session
- CSRF
- Login state expiration
- Page context coupling

Mitigation:

- Establish a formal `/api/v1` from Phase 1

## 18.4 Risk 4: Scope Creep

It is very easy to let Phase 1 grow too large:

- URL import
- Title generation
- Image upload
- Knowledge base upload

None of these should enter Phase 1.

Mitigation:

- Phase 1 only covers the task and article pipelines

---

## 19. Phase 1 Acceptance Criteria

When Phase 1 is complete, it must at minimum satisfy:

1. The system has a new formal `/api/v1` entry point
2. Uses Bearer Token, does not depend on admin sessions
3. Can stably query the catalog
4. Can create, start, stop, and enqueue tasks via API
5. Can query job status via API
6. Can create, update, review, and publish articles via API
7. Article workflow remains consistent with the admin panel
8. Existing admin panel functionality is unaffected
9. The `cron + queue + worker` main pipeline is unaffected

---

## 20. Significance for Subsequent CLI / Skills

After the Phase 1 API is complete, the CLI and skills become much simpler.

### CLI is responsible for

- Command organization
- Parameter parsing
- Local file reading
- Human-friendly operational experience

### API is responsible for

- Authentication
- Business validation
- State machine
- Data persistence

### Skills are responsible for

- Calling the CLI
- Composing operation chains
- Wrapping complex workflows into capabilities

This way, subsequent skills do not need to understand:

- Database structure
- Sessions
- Admin page logic
- Article workflow details

---

## 21. Conclusion

The most reasonable approach for Phase 1 is neither "refactoring the entire system" nor "directly calling existing admin JSON files".

The correct approach is:

1. Keep the existing admin panel, queue, worker, and database main model unchanged
2. Add a new formal API adaptation layer on the periphery
3. The API reuses existing task and article business rules
4. First enable the task pipeline and article pipeline
5. Implement the CLI after the API is stable
6. Finally, wrap skills based on the CLI

This approach has the smallest footprint, lowest risk, and best aligns with the architectural reality the project has already established.
