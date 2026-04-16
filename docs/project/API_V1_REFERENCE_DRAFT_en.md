# API v1 Interface Design Draft

## 1. Document Purpose

This document is the interface design draft for the `GEOFlow` Phase 1 official API.

It is based on the following premises:

- No refactoring of the existing backend architecture
- No overhaul of the existing database business model
- The `cron + queue + worker + ai_engine` main pipeline remains unchanged
- The API serves as a new machine interface layer, providing a stable entry point for the CLI and future skills

For the overall plan, see:

- [API_CLI_PHASE1_PLAN_en.md](API_CLI_PHASE1_PLAN_en.md)

---

## 2. Version and Path

Phase 1 uses a unified path:

- `/api/v1`

Examples:

- `POST /api/v1/auth/login`
- `GET /api/v1/catalog`
- `POST /api/v1/tasks`
- `POST /api/v1/articles/101/publish`

Notes:

- Phase 1 only implements `v1`
- Path versioning prevents future API iterations from affecting the CLI

---

## 3. Authentication Design

## 3.1 Authentication Method

Uses Bearer Token:

```http
Authorization: Bearer gf_xxxxxxxxxxxxxxxxx
```

### First Login

For the first login, the CLI is recommended to exchange an admin username and password for a token:

- `POST /api/v1/auth/login`

After successful login, the CLI automatically saves the token to local configuration.

### Methods Not Adopted

Phase 1 does not adopt:

- Backend admin sessions
- CSRF
- Cookie-based login state
- OAuth

## 3.2 Token Storage

New table: `api_tokens`

Suggested fields:

```sql
CREATE TABLE IF NOT EXISTS api_tokens (
    id BIGSERIAL PRIMARY KEY,
    name VARCHAR(120) NOT NULL,
    token_hash VARCHAR(255) NOT NULL UNIQUE,
    scopes JSONB NOT NULL DEFAULT '[]'::jsonb,
    status VARCHAR(20) NOT NULL DEFAULT 'active',
    created_by_admin_id BIGINT DEFAULT NULL,
    last_used_at TIMESTAMP DEFAULT NULL,
    expires_at TIMESTAMP DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);
```

### Constraints

- The database stores hashes, not plaintext tokens
- The token is only displayed once at creation time
- `status` only supports:
  - `active`
  - `revoked`

## 3.3 Scope Design

Phase 1 scopes:

- `catalog:read`
- `tasks:read`
- `tasks:write`
- `jobs:read`
- `articles:read`
- `articles:write`
- `articles:publish`

### Scope to Endpoint Mapping

- `GET /api/v1/catalog` -> `catalog:read`
- `GET /api/v1/tasks*` -> `tasks:read`
- `POST /api/v1/tasks*` / `PATCH /api/v1/tasks*` -> `tasks:write`
- `GET /api/v1/jobs*` -> `jobs:read`
- `GET /api/v1/articles*` -> `articles:read`
- `POST /api/v1/articles` / `PATCH /api/v1/articles*` -> `articles:write`
- `POST /api/v1/articles/{id}/review` -> `articles:publish`
- `POST /api/v1/articles/{id}/publish` -> `articles:publish`
- `POST /api/v1/articles/{id}/trash` -> `articles:write`

---

## 4. Common Protocol

## 4.1 Request Headers

```http
Authorization: Bearer gf_xxx
Content-Type: application/json
Accept: application/json
X-Request-Id: cli-optional-id
X-Idempotency-Key: optional-uuid
```

## 4.2 Response Envelope

Success:

```json
{
  "success": true,
  "data": {},
  "error": null,
  "meta": {
    "request_id": "req_123",
    "timestamp": "2026-04-13T20:00:00+08:00"
  }
}
```

Failure:

```json
{
  "success": false,
  "data": null,
  "error": {
    "code": "validation_failed",
    "message": "Parameter validation failed",
    "details": {
      "field_errors": {
        "prompt_id": "Content prompt does not exist"
      }
    }
  },
  "meta": {
    "request_id": "req_123",
    "timestamp": "2026-04-13T20:00:00+08:00"
  }
}
```

## 4.3 HTTP Status Codes

- `200` Query successful
- `201` Creation successful
- `202` Accepted
- `400` Bad request format
- `401` Unauthenticated
- `403` Insufficient scope
- `404` Resource not found
- `409` State conflict
- `422` Business validation failed
- `500` Server error

## 4.4 Pagination Format

```json
{
  "items": [],
  "pagination": {
    "page": 1,
    "per_page": 20,
    "total": 120,
    "total_pages": 6
  }
}
```

---

## 5. Data Sources and Reuse Mapping

## 5.1 Task-Related

Actual logic sources:

- [task-create.php](../../admin/task-create.php)
- [tasks.php](../../admin/tasks.php)
- [start_task_batch.php](../../admin/start_task_batch.php)
- [task_service.php](../../includes/task_service.php)
- [job_queue_service.php](../../includes/job_queue_service.php)

## 5.2 Article-Related

Actual logic sources:

- [article-create.php](../../admin/article-create.php)
- [article-edit.php](../../admin/article-edit.php)
- [article-view.php](../../admin/article-view.php)
- [articles.php](../../admin/articles.php)
- [articles-review.php](../../admin/articles-review.php)
- [functions.php](../../includes/functions.php#L380)

## 5.3 Existing Interfaces Not Included in Phase 1

- [title_generate_async.php](../../admin/title_generate_async.php)
- [url-import-start.php](../../admin/url-import-start.php)

---

## 6. Authentication Endpoint

## `POST /api/v1/auth/login`

### Purpose

For CLI first-time login use.

User inputs:

- System address
- Admin username
- Admin password

After successful server-side verification, an API token is issued. The CLI saves it to local configuration, and subsequent business requests continue using Bearer Token.

### Scope

None, requires anonymous access.

### Request Body

```json
{
  "username": "admin",
  "password": "your-password"
}
```

### Success Response

```json
{
  "token": "gf_xxx",
  "expires_at": null,
  "admin": {
    "id": 1,
    "username": "admin",
    "display_name": "System Super Admin",
    "role": "super_admin",
    "status": "active"
  }
}
```

## 7. Catalog API

## 7.1 `GET /api/v1/catalog`

### Purpose

For the CLI to retrieve IDs of all commonly used resources.

Avoids:

- Hardcoding model IDs in the CLI
- Hardcoding prompt IDs
- Hardcoding category and author IDs

### Scope

- `catalog:read`

### Query Parameters

None

### Response Structure

```json
{
  "models": [
    {
      "id": 1,
      "name": "OpenAI GPT-4.1",
      "model_id": "gpt-4.1",
      "model_type": "chat",
      "status": "active"
    }
  ],
  "prompts": [
    {
      "id": 2,
      "name": "Default Content Prompt",
      "type": "content"
    }
  ],
  "title_libraries": [
    {
      "id": 3,
      "name": "AI Industry Title Library",
      "title_count": 240
    }
  ],
  "knowledge_bases": [
    {
      "id": 4,
      "name": "Brand Knowledge Base"
    }
  ],
  "authors": [
    {
      "id": 5,
      "name": "System Author"
    }
  ],
  "categories": [
    {
      "id": 6,
      "name": "Artificial Intelligence",
      "slug": "ai"
    }
  ]
}
```

### Data Rules

- `models` only returns models with `status = active` and `model_type = chat`
- `prompts` prioritizes returning `type = content` in Phase 1
- `title_libraries` returns title counts
- `categories` returns `id + name + slug`

---

## 8. Tasks API

## 8.1 `GET /api/v1/tasks`

### Scope

- `tasks:read`

### Query Parameters

- `page`
- `per_page`
- `status`
- `search`

### Response Fields

- `id`
- `name`
- `status`
- `schedule_enabled`
- `title_library_id`
- `prompt_id`
- `ai_model_id`
- `knowledge_base_id`
- `draft_limit`
- `publish_interval`
- `created_count`
- `published_count`
- `last_run_at`
- `next_run_at`
- `pending_jobs`
- `running_jobs`
- `updated_at`

### Data Source

Based primarily on the list query logic in [tasks.php](../../admin/tasks.php).

### Sorting

- `created_at DESC`

---

## 8.2 `POST /api/v1/tasks`

### Scope

- `tasks:write`

### Purpose

Create a task.

### Request Body

```json
{
  "name": "AI Weekly Report Task",
  "title_library_id": 3,
  "prompt_id": 2,
  "ai_model_id": 1,
  "knowledge_base_id": 4,
  "author_id": 5,
  "image_library_id": null,
  "image_count": 0,
  "need_review": true,
  "publish_interval": 3600,
  "auto_keywords": true,
  "auto_description": true,
  "draft_limit": 10,
  "is_loop": false,
  "status": "paused",
  "category_mode": "smart",
  "fixed_category_id": null
}
```

### Field Rules

Required:

- `name`
- `title_library_id`
- `prompt_id`
- `ai_model_id`

Optional:

- `knowledge_base_id`
- `author_id`
- `image_library_id`
- `image_count`
- `need_review`
- `publish_interval`
- `auto_keywords`
- `auto_description`
- `draft_limit`
- `is_loop`
- `status`
- `category_mode`
- `fixed_category_id`

### Validation Rules

Reuses existing logic from [task-create.php](../../admin/task-create.php):

- Name cannot be empty
- Title library must exist
- Prompt must exist and have `type = content`
- AI model must exist, be enabled, and be of type `chat`
- When `category_mode = fixed`, a valid `fixed_category_id` must be provided
- `publish_interval` minimum value should still be transmitted in seconds; server-side enforces `>= 60`

### Behavior

- Inserts into `tasks`
- Calls `JobQueueService::initializeTaskSchedule()`
- If `status = active`, initializes `task_schedules`

### Response

Returns:

- `id`
- `name`
- `status`
- `schedule_enabled`
- `next_run_at`

### Idempotency Recommendation

If the client includes `X-Idempotency-Key`, the same response should be reused based on route and request body hash.

---

## 8.3 `GET /api/v1/tasks/{id}`

### Scope

- `tasks:read`

### Response Fields

- Task basic fields
- `queue_summary`
- `article_summary`
- `last_run_at`
- `next_run_at`

### Recommended Response Structure

```json
{
  "id": 12,
  "name": "AI Weekly Report Task",
  "status": "active",
  "schedule_enabled": 1,
  "title_library_id": 3,
  "prompt_id": 2,
  "ai_model_id": 1,
  "knowledge_base_id": 4,
  "draft_limit": 10,
  "publish_interval": 3600,
  "queue_summary": {
    "pending_jobs": 1,
    "running_jobs": 0,
    "last_job_id": 88,
    "last_job_status": "pending"
  },
  "article_summary": {
    "draft_count": 3,
    "published_count": 9,
    "total_count": 18
  },
  "last_run_at": "2026-04-13 18:00:00",
  "next_run_at": "2026-04-13 19:00:00"
}
```

### Data Source

Primarily references the task list aggregate query in [tasks.php](../../admin/tasks.php).

---

## 8.4 `PATCH /api/v1/tasks/{id}`

### Scope

- `tasks:write`

### Updatable Fields

- `name`
- `prompt_id`
- `ai_model_id`
- `knowledge_base_id`
- `author_id`
- `need_review`
- `publish_interval`
- `draft_limit`
- `status`
- `category_mode`
- `fixed_category_id`
- `image_library_id`
- `image_count`
- `auto_keywords`
- `auto_description`
- `is_loop`

### Rules

- Only partial updates are allowed
- Updated fields must be re-validated for foreign key validity
- If `status` is updated, it should follow the same start/stop logic as the backend

### Design Recommendation

In Phase 1, `PATCH /tasks/{id}` is only responsible for field updates.

Explicit actions should still use:

- `/start`
- `/stop`
- `/enqueue`

This provides more stable semantics.

---

## 8.5 `POST /api/v1/tasks/{id}/start`

### Scope

- `tasks:write`

### Purpose

Activate a task, allowing scheduling.

### Rules

Reuses existing semantics from [start_task_batch.php](../../admin/start_task_batch.php) and [tasks.php](../../admin/tasks.php):

- `status = active`
- `schedule_enabled = 1`
- `next_run_at = CURRENT_TIMESTAMP` or the minimum reasonable time

### Whether to Auto-Enqueue

Phase 1 recommendation:

- `start` only handles activation, does not auto-enqueue
- Manual execution trigger uses `/enqueue` separately

Reasons:

- Clearer action semantics for the CLI
- Avoids the heavy side effect of "one start both changes state and triggers execution"

If backward compatibility with backend behavior is needed, the request body can include:

```json
{
  "enqueue_now": false
}
```

Default is `false`.

---

## 8.6 `POST /api/v1/tasks/{id}/stop`

### Scope

- `tasks:write`

### Purpose

Pause a task, cancel pending jobs.

### Rules

Reuses [start_task_batch.php](../../admin/start_task_batch.php):

- `status = paused`
- `schedule_enabled = 0`
- `next_run_at = NULL`
- Cancels `pending` jobs under the current task
- Does not force-kill `running` jobs

### Response Fields

- `id`
- `status`
- `schedule_enabled`
- `cancelled_jobs`
- `running_jobs`

---

## 8.7 `POST /api/v1/tasks/{id}/enqueue`

### Scope

- `tasks:write`

### Purpose

Immediately add the task to the queue.

### Request Body

```json
{
  "job_type": "generate_article",
  "source": "cli_manual"
}
```

### Rules

Underlying reuse:

- `JobQueueService::enqueueTaskJob()`

Semantics:

- If the current task already has a `pending` or `running` job, return `409`
- Duplicate enqueuing is not allowed

### Response

- `task_id`
- `job_id`
- `status = pending`

---

## 8.8 `GET /api/v1/tasks/{id}/jobs`

### Scope

- `tasks:read`

### Query Parameters

- `status`
- `limit`

### Response Fields

- `id`
- `task_id`
- `job_type`
- `status`
- `attempt_count`
- `max_attempts`
- `worker_id`
- `claimed_at`
- `finished_at`
- `error_message`
- `created_at`

### Purpose

For the CLI to view the recent execution status of a specific task.

---

## 9. Jobs API

## 9.1 `GET /api/v1/jobs/{id}`

### Scope

- `jobs:read`

### Purpose

View the execution status of a single job, for CLI polling.

### Response Fields

- `id`
- `task_id`
- `job_type`
- `status`
- `attempt_count`
- `max_attempts`
- `worker_id`
- `claimed_at`
- `finished_at`
- `error_message`
- `payload`
- `task_run_summary`

### `task_run_summary`

Recommended to include:

- `article_id`
- `duration_ms`
- `meta`

Data sources:

- `job_queue`
- `task_runs`

### Response Example

```json
{
  "id": 88,
  "task_id": 12,
  "job_type": "generate_article",
  "status": "completed",
  "attempt_count": 1,
  "max_attempts": 3,
  "worker_id": "host:12345",
  "claimed_at": "2026-04-13 20:01:00",
  "finished_at": "2026-04-13 20:01:12",
  "error_message": "",
  "payload": {
    "source": "cli_manual"
  },
  "task_run_summary": {
    "article_id": 101,
    "duration_ms": 12105,
    "meta": {
      "title": "AI Weekly Report",
      "message": "ok"
    }
  }
}
```

---

## 10. Articles API

## 10.1 `GET /api/v1/articles`

### Scope

- `articles:read`

### Query Parameters

- `page`
- `per_page`
- `task_id`
- `status`
- `review_status`
- `author_id`
- `search`

### Response Fields

- `id`
- `title`
- `slug`
- `status`
- `review_status`
- `task_id`
- `author_id`
- `category_id`
- `published_at`
- `created_at`
- `updated_at`

### Data Source

References the filtering logic in [articles.php](../../admin/articles.php).

### Default Conditions

- `deleted_at IS NULL`

---

## 10.2 `POST /api/v1/articles`

### Scope

- `articles:write`

### Purpose

Create an article directly through the API, suitable for:

- Content already generated by local AI
- Local Markdown already prepared
- Only needing remote storage and publishing

### Request Body

```json
{
  "title": "A New Article",
  "content": "# A New Article\n\nHere is the body text.",
  "excerpt": "Here is the excerpt.",
  "slug": null,
  "task_id": 12,
  "author_id": 5,
  "category_id": 6,
  "keywords": "AI,workflow",
  "meta_description": "Article summary",
  "status": "draft",
  "review_status": "pending",
  "auto_generate_slug": true
}
```

### Validation Rules

Reuses [article-create.php](../../admin/article-create.php):

- `title` is required
- `content` is required
- If `excerpt` is not provided, it is automatically truncated
- If `auto_generate_slug = true` or `slug` is empty, the server generates a unique slug
- If `slug` is provided, uniqueness must also be ensured

### Workflow Rules

Must go through:

- `normalize_article_workflow_state()`

Therefore:

- Invalid combinations are not allowed to be written
- If the request has `status = published` and `review_status = pending`
- The server must converge to a valid state

### Default Values

- `status` defaults to `draft`
- `review_status` defaults to `pending`
- `is_ai_generated` — Phase 1 recommends allowing explicit input, otherwise defaults to `0`

### Response

Returns:

- `id`
- `title`
- `slug`
- `status`
- `review_status`
- `published_at`

---

## 10.3 `GET /api/v1/articles/{id}`

### Scope

- `articles:read`

### Response Fields

- Article basic fields
- `task_name`
- `author_name`
- `category_name`
- `images`

### Data Source

References:

- [article-view.php](../../admin/article-view.php)

`images` comes from:

- `article_images`
- `images`

---

## 10.4 `PATCH /api/v1/articles/{id}`

### Scope

- `articles:write`

### Updatable Fields

- `title`
- `content`
- `excerpt`
- `keywords`
- `meta_description`
- `category_id`
- `author_id`
- `slug`
- `status`
- `review_status`

### Rules

- If the title changes and `slug` is not explicitly provided, a unique slug can be automatically regenerated
- If `status` / `review_status` is provided, it must go through `normalize_article_workflow_state()` again
- If only text fields are updated, the publish status should not be changed unnecessarily

### Data Source

References:

- [article-edit.php](../../admin/article-edit.php)

---

## 10.5 `POST /api/v1/articles/{id}/review`

### Scope

- `articles:publish`

### Purpose

Update the review result.

### Request Body

```json
{
  "review_status": "approved",
  "review_note": "Content approved, ready for publishing"
}
```

### Allowed Values

- `pending`
- `approved`
- `rejected`
- `auto_approved`

### Behavior

Reuses:

- [articles-review.php](../../admin/articles-review.php)
- [functions.php](../../includes/functions.php#L380)

Specific rules:

- Get the current article status
- If `review_status` is `approved` or `auto_approved`
- And the associated task has `need_review = 0` or the review status is `auto_approved`
- Then `desiredStatus = published`
- Finally, go through `normalize_article_workflow_state()` uniformly
- Write back to `articles`
- Insert into `article_reviews`

### Response

Returns:

- `id`
- `status`
- `review_status`
- `published_at`

---

## 10.6 `POST /api/v1/articles/{id}/publish`

### Scope

- `articles:publish`

### Purpose

Explicitly publish an article.

### Request Body

Can be empty:

```json
{}
```

### Core Rules

Cannot bypass the workflow.

The server should:

1. Read the current article
2. Get the current `review_status`
3. Set `desiredStatus = published`
4. Call `normalize_article_workflow_state()`

### Important Notes

If the current review status is:

- `pending`
- `rejected`

Then there are two design choices for whether to allow publishing:

#### Option A: Strict Mode

- Return `409`
- Require going through `/review` first

#### Option B: Auto-Convergence Mode

- Automatically converge `review_status` to `approved`

Phase 1 recommends adopting **Strict Mode**.

Reasons:

- More consistent with backend review logic
- Prevents the CLI from unknowingly skipping the review process

### Recommended Error Code

- `article_not_publishable`

---

## 10.7 `POST /api/v1/articles/{id}/trash`

### Scope

- `articles:write`

### Purpose

Soft delete an article.

### Behavior

- Sets `deleted_at = CURRENT_TIMESTAMP`

### Data Source

References:

- [articles.php](../../admin/articles.php)

### Response

```json
{
  "id": 101,
  "trashed": true
}
```

---

## 11. Idempotency Design

## 11.1 Write Endpoints Recommended for Phase 1 Support

The following endpoints are recommended to support `X-Idempotency-Key`:

- `POST /tasks`
- `POST /tasks/{id}/enqueue`
- `POST /articles`
- `POST /articles/{id}/publish`

## 11.2 Minimal Implementation

New table:

```sql
CREATE TABLE IF NOT EXISTS api_idempotency_keys (
    id BIGSERIAL PRIMARY KEY,
    idempotency_key VARCHAR(120) NOT NULL,
    route_key VARCHAR(120) NOT NULL,
    request_hash VARCHAR(64) NOT NULL,
    response_body TEXT NOT NULL,
    response_status INTEGER NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE (idempotency_key, route_key)
);
```

### Behavior

- Same `idempotency_key + route_key + request_hash` directly reuses the existing response
- Same `idempotency_key + route_key` but different `request_hash` returns `409`

---

## 12. Routing and Code Structure Recommendations

## 12.1 New Files

- `api/v1/index.php`
- `includes/api_auth.php`
- `includes/api_request.php`
- `includes/api_response.php`
- `includes/api_token_service.php`
- `includes/catalog_service.php`
- `includes/task_lifecycle_service.php`
- `includes/article_service.php`

## 12.2 Dispatcher Recommendation

Inside `index.php`, dispatch to corresponding handlers based on:

- HTTP method
- Path segments
- Scope

### Recommended Structure

```php
GET    /api/v1/catalog

GET    /api/v1/tasks
POST   /api/v1/tasks
GET    /api/v1/tasks/{id}
PATCH  /api/v1/tasks/{id}
POST   /api/v1/tasks/{id}/start
POST   /api/v1/tasks/{id}/stop
POST   /api/v1/tasks/{id}/enqueue
GET    /api/v1/tasks/{id}/jobs

GET    /api/v1/jobs/{id}

GET    /api/v1/articles
POST   /api/v1/articles
GET    /api/v1/articles/{id}
PATCH  /api/v1/articles/{id}
POST   /api/v1/articles/{id}/review
POST   /api/v1/articles/{id}/publish
POST   /api/v1/articles/{id}/trash
```

---

## 13. CLI Correspondence

Phase 1 CLI is recommended to only wrap core operations.

### Task-Related

```bash
geoflow catalog
geoflow task list --status active
geoflow task create --json task.json
geoflow task get 12
geoflow task update 12 --json patch.json
geoflow task start 12
geoflow task stop 12
geoflow task enqueue 12
geoflow task jobs 12
geoflow job get 88
```

### Article-Related

```bash
geoflow article list --task-id 12 --status draft
geoflow article create --title "Title" --content-file ./article.md --task-id 12 --category-id 6
geoflow article get 101
geoflow article update 101 --content-file ./article.md
geoflow article review 101 --status approved --note "Approved"
geoflow article publish 101
geoflow article trash 101
```

### Key Notes

The CLI should not touch:

- Database connections
- Backend sessions
- Backend form pages

The CLI only calls `/api/v1`.

---

## 14. Phase 1 Development Order

## 14.1 Step 1

Build the API infrastructure first:

- Token authentication
- Request parsing
- Response output
- Router integration

## 14.2 Step 2

Build `catalog`

Reasons:

- Simple
- Can quickly validate the full pipeline of token, routing, and JSON protocol

## 14.3 Step 3

Build `tasks` and `jobs`

Reasons:

- This is the foundation for CLI automatic task creation and batch generation

## 14.4 Step 4

Build `articles`

Reasons:

- Requires more careful handling of workflow and slug logic

---

## 15. Phase 1 Testing Recommendations

## 15.1 Endpoint-Level Testing

At minimum, cover:

- Unauthenticated returns `401`
- Wrong scope returns `403`
- Invalid parameters return `422`
- Resource not found returns `404`
- Duplicate enqueue returns `409`

## 15.2 Business-Level Testing

### Tasks

- Create a paused task
- Start a task
- Manually enqueue
- Worker consumes normally
- Query job status

### Articles

- Create a draft
- Update title and content
- Auto-generate or update slug
- Review approved
- Publish
- Soft delete

### Workflow Consistency

Key checks:

- `pending/rejected` does not coexist with `published`
- `published_at` auto-converges
- `article_reviews` is correctly written

---

## 16. Explicitly Not Included in Phase 1

The following capabilities are not included in this development round:

- URL Import API
- Async Title Generation API
- Image Upload API
- Knowledge Base Upload API
- Batch File Import API
- Webhooks
- OpenAPI Page
- SDK

Reasons:

- These capabilities are not currently part of a unified service layer model
- They could slow down Phase 1 delivery

---

## 17. Conclusion

The core purpose of this interface design draft is not to "redefine the system," but to formalize and expose the business logic that already exists in the backend as proper interfaces.

During Phase 1 development, two bottom lines must be maintained:

1. The new API must reuse existing business rules, especially the task start/stop logic and article workflow logic.
2. The new API must be decoupled from the backend pages — the CLI must not depend on session-based backend interfaces.

As long as these two bottom lines are upheld, Phase 1 development can establish a stable foundation for the CLI and future skills with minimal risk.
