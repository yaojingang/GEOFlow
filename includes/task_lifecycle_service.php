<?php
/**
 * 任务生命周期服务
 */

if (!defined('FEISHU_TREASURE')) {
    die('Access denied');
}

require_once __DIR__ . '/job_queue_service.php';

class TaskLifecycleService {
    private JobQueueService $queueService;

    public function __construct(private PDO $db) {
        $this->queueService = new JobQueueService($db);
    }

    public function listTasks(int $page = 1, int $perPage = 20, array $filters = []): array {
        $page = max(1, $page);
        $perPage = max(1, min(100, $perPage));
        $offset = ($page - 1) * $perPage;

        $where = ['1 = 1'];
        $params = [];

        if (!empty($filters['status'])) {
            $where[] = 't.status = ?';
            $params[] = $filters['status'];
        }

        if (!empty($filters['search'])) {
            $where[] = 't.name LIKE ?';
            $params[] = '%' . $filters['search'] . '%';
        }

        $whereSql = implode(' AND ', $where);

        $countStmt = $this->db->prepare("SELECT COUNT(*) FROM tasks t WHERE {$whereSql}");
        $countStmt->execute($params);
        $total = (int) $countStmt->fetchColumn();

        $sql = "
            SELECT t.*,
                   COALESCE((
                       SELECT COUNT(*)
                       FROM job_queue jq
                       WHERE jq.task_id = t.id
                         AND jq.status = 'pending'
                   ), 0) AS pending_jobs,
                   COALESCE((
                       SELECT COUNT(*)
                       FROM job_queue jq
                       WHERE jq.task_id = t.id
                         AND jq.status = 'running'
                   ), 0) AS running_jobs
            FROM tasks t
            WHERE {$whereSql}
            ORDER BY t.created_at DESC
            LIMIT ? OFFSET ?
        ";
        $stmt = $this->db->prepare($sql);
        $bindIndex = 1;
        foreach ($params as $param) {
            $stmt->bindValue($bindIndex++, $param);
        }
        $stmt->bindValue($bindIndex++, $perPage, PDO::PARAM_INT);
        $stmt->bindValue($bindIndex, $offset, PDO::PARAM_INT);
        $stmt->execute();

        return [
            'items' => $stmt->fetchAll(PDO::FETCH_ASSOC),
            'pagination' => [
                'page' => $page,
                'per_page' => $perPage,
                'total' => $total,
                'total_pages' => (int) ceil($total / $perPage)
            ]
        ];
    }

    public function createTask(array $data): array {
        $normalized = $this->normalizeTaskInput($data, false);

        $this->db->beginTransaction();
        try {
            $stmt = $this->db->prepare("
                INSERT INTO tasks (
                    name, title_library_id, image_library_id, image_count,
                    prompt_id, ai_model_id, need_review, publish_interval,
                    author_id, auto_keywords, auto_description, draft_limit,
                    is_loop, model_selection_mode, status, knowledge_base_id, category_mode, fixed_category_id,
                    created_at, updated_at
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)
            ");
            $stmt->execute([
                $normalized['name'],
                $normalized['title_library_id'],
                $normalized['image_library_id'],
                $normalized['image_count'],
                $normalized['prompt_id'],
                $normalized['ai_model_id'],
                $normalized['need_review'],
                $normalized['publish_interval'],
                $normalized['author_id'],
                $normalized['auto_keywords'],
                $normalized['auto_description'],
                $normalized['draft_limit'],
                $normalized['is_loop'],
                $normalized['model_selection_mode'],
                $normalized['status'],
                $normalized['knowledge_base_id'],
                $normalized['category_mode'],
                $normalized['fixed_category_id']
            ]);

            $taskId = db_last_insert_id($this->db, 'tasks');
            $this->queueService->initializeTaskSchedule($taskId);

            if ($normalized['status'] === 'active') {
                $scheduleStmt = $this->db->prepare("
                    INSERT INTO task_schedules (task_id, next_run_time, created_at, updated_at)
                    VALUES (?, " . db_now_plus_minutes_sql(1) . ", CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)
                ");
                $scheduleStmt->execute([$taskId]);
            } else {
                $pauseStmt = $this->db->prepare("
                    UPDATE tasks
                    SET schedule_enabled = 0,
                        next_run_at = NULL,
                        updated_at = CURRENT_TIMESTAMP
                    WHERE id = ?
                ");
                $pauseStmt->execute([$taskId]);
            }

            $this->db->commit();
            return $this->getTask($taskId);
        } catch (Throwable $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $e;
        }
    }

    public function getTask(int $taskId): array {
        $stmt = $this->db->prepare("
            SELECT t.*
            FROM tasks t
            WHERE t.id = ?
            LIMIT 1
        ");
        $stmt->execute([$taskId]);
        $task = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$task) {
            throw new ApiException('task_not_found', '任务不存在', 404);
        }

        $queueSummaryStmt = $this->db->prepare("
            SELECT
                COALESCE(SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END), 0) AS pending_jobs,
                COALESCE(SUM(CASE WHEN status = 'running' THEN 1 ELSE 0 END), 0) AS running_jobs
            FROM job_queue
            WHERE task_id = ?
        ");
        $queueSummaryStmt->execute([$taskId]);
        $queueSummary = $queueSummaryStmt->fetch(PDO::FETCH_ASSOC) ?: ['pending_jobs' => 0, 'running_jobs' => 0];

        $lastJobStmt = $this->db->prepare("
            SELECT id, status
            FROM job_queue
            WHERE task_id = ?
            ORDER BY updated_at DESC, id DESC
            LIMIT 1
        ");
        $lastJobStmt->execute([$taskId]);
        $lastJob = $lastJobStmt->fetch(PDO::FETCH_ASSOC) ?: null;

        $articleSummaryStmt = $this->db->prepare("
            SELECT
                COUNT(*) FILTER (WHERE deleted_at IS NULL) AS total_count,
                COUNT(*) FILTER (WHERE deleted_at IS NULL AND status = 'draft') AS draft_count,
                COUNT(*) FILTER (WHERE deleted_at IS NULL AND status = 'published') AS published_count
            FROM articles
            WHERE task_id = ?
        ");
        $articleSummaryStmt->execute([$taskId]);
        $articleSummary = $articleSummaryStmt->fetch(PDO::FETCH_ASSOC) ?: ['total_count' => 0, 'draft_count' => 0, 'published_count' => 0];

        return [
            'id' => (int) $task['id'],
            'name' => $task['name'],
            'status' => $task['status'],
            'schedule_enabled' => (int) ($task['schedule_enabled'] ?? 1),
            'title_library_id' => $this->nullableInt($task['title_library_id'] ?? null),
            'prompt_id' => $this->nullableInt($task['prompt_id'] ?? null),
            'ai_model_id' => $this->nullableInt($task['ai_model_id'] ?? null),
            'knowledge_base_id' => $this->nullableInt($task['knowledge_base_id'] ?? null),
            'author_id' => $this->nullableInt($task['author_id'] ?? null),
            'image_library_id' => $this->nullableInt($task['image_library_id'] ?? null),
            'image_count' => (int) ($task['image_count'] ?? 0),
            'need_review' => (int) ($task['need_review'] ?? 1),
            'publish_interval' => (int) ($task['publish_interval'] ?? 3600),
            'auto_keywords' => (int) ($task['auto_keywords'] ?? 1),
            'auto_description' => (int) ($task['auto_description'] ?? 1),
            'draft_limit' => (int) ($task['draft_limit'] ?? 10),
            'is_loop' => (int) ($task['is_loop'] ?? 0),
            'model_selection_mode' => $task['model_selection_mode'] ?? 'fixed',
            'category_mode' => $task['category_mode'] ?? 'smart',
            'fixed_category_id' => $this->nullableInt($task['fixed_category_id'] ?? null),
            'created_count' => (int) ($task['created_count'] ?? 0),
            'published_count' => (int) ($task['published_count'] ?? 0),
            'queue_summary' => [
                'pending_jobs' => (int) ($queueSummary['pending_jobs'] ?? 0),
                'running_jobs' => (int) ($queueSummary['running_jobs'] ?? 0),
                'last_job_id' => $lastJob ? (int) $lastJob['id'] : null,
                'last_job_status' => $lastJob['status'] ?? null
            ],
            'article_summary' => [
                'draft_count' => (int) ($articleSummary['draft_count'] ?? 0),
                'published_count' => (int) ($articleSummary['published_count'] ?? 0),
                'total_count' => (int) ($articleSummary['total_count'] ?? 0)
            ],
            'last_run_at' => $task['last_run_at'] ?? null,
            'next_run_at' => $task['next_run_at'] ?? null,
            'created_at' => $task['created_at'] ?? null,
            'updated_at' => $task['updated_at'] ?? null
        ];
    }

    public function updateTask(int $taskId, array $data): array {
        $this->ensureTaskExists($taskId);
        $normalized = $this->normalizeTaskInput($data, true);
        if (empty($normalized)) {
            throw new ApiException('validation_failed', '没有可更新的字段', 422);
        }

        $status = $normalized['status'] ?? null;
        unset($normalized['status']);

        $this->db->beginTransaction();
        try {
            if (!empty($normalized)) {
                $fields = [];
                $values = [];
                foreach ($normalized as $field => $value) {
                    $fields[] = "{$field} = ?";
                    $values[] = $value;
                }
                $fields[] = "updated_at = CURRENT_TIMESTAMP";
                $values[] = $taskId;
                $stmt = $this->db->prepare("UPDATE tasks SET " . implode(', ', $fields) . " WHERE id = ?");
                $stmt->execute($values);
            }

            if ($status === 'active') {
                $this->activateTask($taskId, false);
            } elseif ($status === 'paused') {
                $this->pauseTask($taskId, '任务已暂停');
            }

            $this->db->commit();
            return $this->getTask($taskId);
        } catch (Throwable $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $e;
        }
    }

    public function startTask(int $taskId, bool $enqueueNow = false): array {
        $this->ensureTaskExists($taskId);
        $this->db->beginTransaction();
        try {
            $this->activateTask($taskId, true);
            $jobId = null;
            if ($enqueueNow) {
                $jobId = $this->queueService->enqueueTaskJob($taskId, 'generate_article', ['source' => 'api_manual_start']);
            }
            $this->db->commit();
            $task = $this->getTask($taskId);
            if ($jobId !== null) {
                $task['started_job_id'] = $jobId;
            }
            return $task;
        } catch (Throwable $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $e;
        }
    }

    public function stopTask(int $taskId): array {
        $this->ensureTaskExists($taskId);
        $this->db->beginTransaction();
        try {
            $cancelledJobs = $this->pauseTask($taskId, '任务已暂停');
            $runningStmt = $this->db->prepare("
                SELECT COUNT(*)
                FROM job_queue
                WHERE task_id = ?
                  AND status = 'running'
            ");
            $runningStmt->execute([$taskId]);
            $runningJobs = (int) $runningStmt->fetchColumn();
            $this->db->commit();
            $task = $this->getTask($taskId);
            $task['cancelled_jobs'] = $cancelledJobs;
            $task['running_jobs'] = $runningJobs;
            return $task;
        } catch (Throwable $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $e;
        }
    }

    public function enqueueTask(int $taskId, string $jobType = 'generate_article', array $payload = []): array {
        $stmt = $this->db->prepare("
            SELECT id, status, COALESCE(schedule_enabled, 1) AS schedule_enabled
            FROM tasks
            WHERE id = ?
            LIMIT 1
        ");
        $stmt->execute([$taskId]);
        $task = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$task) {
            throw new ApiException('task_not_found', '任务不存在', 404);
        }

        if (($task['status'] ?? 'paused') !== 'active' || (int) ($task['schedule_enabled'] ?? 1) !== 1) {
            throw new ApiException('task_not_active', '任务未启用，无法入队', 409);
        }

        $jobId = $this->queueService->enqueueTaskJob($taskId, $jobType, $payload);
        if ($jobId === null) {
            throw new ApiException('job_already_exists', '任务已处于排队或执行中', 409);
        }

        return [
            'task_id' => $taskId,
            'job_id' => $jobId,
            'status' => 'pending'
        ];
    }

    public function listTaskJobs(int $taskId, ?string $status = null, int $limit = 20): array {
        $this->ensureTaskExists($taskId);
        $limit = max(1, min(100, $limit));

        $sql = "
            SELECT id, task_id, job_type, status, attempt_count, max_attempts, worker_id,
                   claimed_at, finished_at, error_message, created_at, updated_at
            FROM job_queue
            WHERE task_id = ?
        ";
        $params = [$taskId];
        if ($status !== null && $status !== '') {
            $sql .= " AND status = ?";
            $params[] = $status;
        }
        $sql .= " ORDER BY updated_at DESC, id DESC LIMIT ?";
        $params[] = $limit;

        $stmt = $this->db->prepare($sql);
        foreach ($params as $index => $param) {
            $isLast = $index === count($params) - 1;
            $stmt->bindValue($index + 1, $param, $isLast ? PDO::PARAM_INT : PDO::PARAM_STR);
        }
        $stmt->execute();
        return ['items' => $stmt->fetchAll(PDO::FETCH_ASSOC)];
    }

    public function getJob(int $jobId): array {
        $stmt = $this->db->prepare("
            SELECT *
            FROM job_queue
            WHERE id = ?
            LIMIT 1
        ");
        $stmt->execute([$jobId]);
        $job = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$job) {
            throw new ApiException('job_not_found', 'Job 不存在', 404);
        }

        $runStmt = $this->db->prepare("
            SELECT article_id, duration_ms, meta, status, error_message
            FROM task_runs
            WHERE job_id = ?
            ORDER BY created_at DESC, id DESC
            LIMIT 1
        ");
        $runStmt->execute([$jobId]);
        $run = $runStmt->fetch(PDO::FETCH_ASSOC) ?: null;

        return [
            'id' => (int) $job['id'],
            'task_id' => (int) $job['task_id'],
            'job_type' => $job['job_type'],
            'status' => $job['status'],
            'attempt_count' => (int) ($job['attempt_count'] ?? 0),
            'max_attempts' => (int) ($job['max_attempts'] ?? 0),
            'worker_id' => $job['worker_id'] !== '' ? $job['worker_id'] : null,
            'claimed_at' => $job['claimed_at'] ?? null,
            'finished_at' => $job['finished_at'] ?? null,
            'error_message' => $job['error_message'] ?? '',
            'payload' => $this->decodeJsonField($job['payload'] ?? ''),
            'task_run_summary' => $run ? [
                'article_id' => isset($run['article_id']) ? (int) $run['article_id'] : null,
                'duration_ms' => (int) ($run['duration_ms'] ?? 0),
                'status' => $run['status'] ?? null,
                'error_message' => $run['error_message'] ?? '',
                'meta' => $this->decodeJsonField($run['meta'] ?? '')
            ] : null
        ];
    }

    private function normalizeTaskInput(array $data, bool $isUpdate): array {
        $output = [];
        $fieldErrors = [];

        if (array_key_exists('name', $data)) {
            $name = trim((string) $data['name']);
            if ($name === '') {
                $fieldErrors['name'] = '任务名称不能为空';
            } else {
                $output['name'] = $name;
            }
        } elseif (!$isUpdate) {
            $fieldErrors['name'] = '任务名称不能为空';
        }

        $referenceMap = [
            'title_library_id' => ['table' => 'title_libraries', 'message' => '选择的标题库不存在', 'required' => !$isUpdate],
            'image_library_id' => ['table' => 'image_libraries', 'message' => '选择的图片库不存在', 'required' => false],
            'prompt_id' => ['table' => 'prompts', 'message' => '选择的内容提示词不存在', 'required' => !$isUpdate],
            'ai_model_id' => ['table' => 'ai_models', 'message' => '选择的AI模型不存在或未激活', 'required' => !$isUpdate],
            'author_id' => ['table' => 'authors', 'message' => '选择的作者不存在', 'required' => false],
            'knowledge_base_id' => ['table' => 'knowledge_bases', 'message' => '选择的知识库不存在', 'required' => false],
            'fixed_category_id' => ['table' => 'categories', 'message' => '固定分类不存在', 'required' => false]
        ];

        foreach ($referenceMap as $field => $config) {
            if (!array_key_exists($field, $data)) {
                if (!$isUpdate && $config['required']) {
                    $fieldErrors[$field] = '缺少必填字段';
                }
                continue;
            }

            $value = $data[$field];
            if ($value === null || $value === '' || (int) $value <= 0) {
                $output[$field] = null;
                if (!$isUpdate && $config['required']) {
                    $fieldErrors[$field] = '缺少必填字段';
                }
                continue;
            }

            $id = (int) $value;
            if ($field === 'prompt_id') {
                $stmt = $this->db->prepare("SELECT COUNT(*) FROM prompts WHERE id = ? AND type = 'content'");
                $stmt->execute([$id]);
            } elseif ($field === 'ai_model_id') {
                $stmt = $this->db->prepare("
                    SELECT COUNT(*)
                    FROM ai_models
                    WHERE id = ?
                      AND status = 'active'
                      AND COALESCE(NULLIF(model_type, ''), 'chat') = 'chat'
                ");
                $stmt->execute([$id]);
            } else {
                $stmt = $this->db->prepare("SELECT COUNT(*) FROM {$config['table']} WHERE id = ?");
                $stmt->execute([$id]);
            }

            if ((int) $stmt->fetchColumn() === 0) {
                $fieldErrors[$field] = $config['message'];
            } else {
                $output[$field] = $id;
            }
        }

        $flagFields = ['need_review', 'auto_keywords', 'auto_description', 'is_loop'];
        foreach ($flagFields as $field) {
            if (array_key_exists($field, $data)) {
                $output[$field] = $this->toFlag($data[$field]);
            } elseif (!$isUpdate) {
                $output[$field] = in_array($field, ['need_review', 'auto_keywords', 'auto_description'], true) ? 1 : 0;
            }
        }

        if (array_key_exists('image_count', $data)) {
            $output['image_count'] = max(0, (int) $data['image_count']);
        } elseif (!$isUpdate) {
            $output['image_count'] = 0;
        }

        if (array_key_exists('publish_interval', $data)) {
            $output['publish_interval'] = max(60, (int) $data['publish_interval']);
        } elseif (!$isUpdate) {
            $output['publish_interval'] = 3600;
        }

        if (array_key_exists('draft_limit', $data)) {
            $output['draft_limit'] = max(1, (int) $data['draft_limit']);
        } elseif (!$isUpdate) {
            $output['draft_limit'] = 10;
        }

        if (array_key_exists('category_mode', $data)) {
            $categoryMode = trim((string) $data['category_mode']);
            if (!in_array($categoryMode, ['smart', 'fixed'], true)) {
                $fieldErrors['category_mode'] = '分类模式无效';
            } else {
                $output['category_mode'] = $categoryMode;
            }
        } elseif (!$isUpdate) {
            $output['category_mode'] = 'smart';
        }

        if (array_key_exists('model_selection_mode', $data)) {
            $modelSelectionMode = trim((string) $data['model_selection_mode']);
            if (!in_array($modelSelectionMode, ['fixed', 'smart_failover'], true)) {
                $fieldErrors['model_selection_mode'] = '模型选择模式无效';
            } else {
                $output['model_selection_mode'] = $modelSelectionMode;
            }
        } elseif (!$isUpdate) {
            $output['model_selection_mode'] = 'fixed';
        }

        if (array_key_exists('status', $data)) {
            $status = trim((string) $data['status']);
            if (!in_array($status, ['active', 'paused'], true)) {
                $fieldErrors['status'] = '任务状态无效';
            } else {
                $output['status'] = $status;
            }
        } elseif (!$isUpdate) {
            $output['status'] = 'active';
        }

        $effectiveCategoryMode = $output['category_mode'] ?? (($data['category_mode'] ?? 'smart') ?: 'smart');
        if ($effectiveCategoryMode === 'fixed') {
            $fixedCategoryId = $output['fixed_category_id'] ?? null;
            if ($fixedCategoryId === null || $fixedCategoryId <= 0) {
                $fieldErrors['fixed_category_id'] = '固定分类模式下必须选择一个分类';
            }
        }

        if (!empty($fieldErrors)) {
            throw new ApiException('validation_failed', '参数校验失败', 422, [
                'field_errors' => $fieldErrors
            ]);
        }

        return $output;
    }

    private function activateTask(int $taskId, bool $resetNextRun): void {
        $stmt = $this->db->prepare("
            UPDATE tasks
            SET status = 'active',
                schedule_enabled = 1,
                next_run_at = CASE
                    WHEN ? = 1 THEN CURRENT_TIMESTAMP
                    ELSE COALESCE(next_run_at, CURRENT_TIMESTAMP)
                END,
                updated_at = CURRENT_TIMESTAMP
            WHERE id = ?
        ");
        $stmt->execute([$resetNextRun ? 1 : 0, $taskId]);
        $this->queueService->initializeTaskSchedule($taskId);
    }

    private function pauseTask(int $taskId, string $reason): int {
        $stmt = $this->db->prepare("
            UPDATE tasks
            SET status = 'paused',
                schedule_enabled = 0,
                next_run_at = NULL,
                updated_at = CURRENT_TIMESTAMP
            WHERE id = ?
        ");
        $stmt->execute([$taskId]);

        $cancelStmt = $this->db->prepare("
            UPDATE job_queue
            SET status = 'cancelled',
                finished_at = CURRENT_TIMESTAMP,
                updated_at = CURRENT_TIMESTAMP,
                error_message = ?
            WHERE task_id = ?
              AND status = 'pending'
        ");
        $cancelStmt->execute([$reason, $taskId]);
        return $cancelStmt->rowCount();
    }

    private function ensureTaskExists(int $taskId): void {
        $stmt = $this->db->prepare("SELECT COUNT(*) FROM tasks WHERE id = ?");
        $stmt->execute([$taskId]);
        if ((int) $stmt->fetchColumn() === 0) {
            throw new ApiException('task_not_found', '任务不存在', 404);
        }
    }

    private function decodeJsonField(string $json): array {
        $decoded = json_decode($json, true);
        return is_array($decoded) ? $decoded : [];
    }

    private function nullableInt(mixed $value): ?int {
        if ($value === null || $value === '') {
            return null;
        }
        return (int) $value;
    }

    private function toFlag(mixed $value): int {
        if (is_bool($value)) {
            return $value ? 1 : 0;
        }

        if (is_numeric($value)) {
            return (int) $value > 0 ? 1 : 0;
        }

        $value = strtolower(trim((string) $value));
        return in_array($value, ['1', 'true', 'yes', 'on'], true) ? 1 : 0;
    }
}
