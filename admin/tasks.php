<?php
/**
 * 智能GEO内容系统 - 任务管理（重新设计）
 *
 * @author 姚金刚
 * @version 1.0
 * @date 2025-10-06
 */

define('FEISHU_TREASURE', true);
session_start();

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/database_admin.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/job_queue_service.php';

// 检查管理员登录
require_admin_login();

// 立即释放session锁，允许其他页面并发访问
session_write_close();

$message = '';
$error = '';

// 检查URL参数中的消息
if (isset($_GET['message'])) {
    $message = $_GET['message'];
}

// 处理POST请求
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
        $error = __('message.csrf_failed');
    } else {
        $action = $_POST['action'] ?? '';
        
        switch ($action) {
            case 'toggle_status':
                $task_id = intval($_POST['task_id'] ?? 0);
                $new_status = $_POST['status'] === 'active' ? 'paused' : 'active';
                $queueService = new JobQueueService($db);

                $stmt = $db->prepare("
                    UPDATE tasks
                    SET status = ?,
                        schedule_enabled = ?,
                        next_run_at = CASE WHEN ? = 'active' THEN COALESCE(next_run_at, " . db_now_plus_minutes_sql(1) . ") ELSE NULL END,
                        updated_at = CURRENT_TIMESTAMP
                    WHERE id = ?
                ");
                if ($stmt->execute([$new_status, $new_status === 'active' ? 1 : 0, $new_status, $task_id])) {
                    if ($new_status === 'paused') {
                        $cancelStmt = $db->prepare("
                            UPDATE job_queue
                            SET status = 'cancelled',
                                finished_at = CURRENT_TIMESTAMP,
                                updated_at = CURRENT_TIMESTAMP,
                                error_message = '任务已暂停'
                            WHERE task_id = ?
                              AND status = 'pending'
                        ");
                        $cancelStmt->execute([$task_id]);
                    } else {
                        $queueService->initializeTaskSchedule($task_id);
                    }
                    $message = $new_status === 'paused'
                        ? __('tasks.message.paused_stopped')
                        : __('tasks.message.activated');
                } else {
                    $error = __('tasks.message.status_update_failed');
                }
                break;
                
            case 'delete_task':
                $task_id = intval($_POST['task_id'] ?? 0);

                try {
                    $db->beginTransaction();

                    // 1. 软删除相关文章（标记为已删除）
                    $db->prepare("UPDATE articles SET deleted_at = CURRENT_TIMESTAMP WHERE task_id = ? AND deleted_at IS NULL")->execute([$task_id]);

                    // 2. 删除文章队列记录
                    $db->prepare("DELETE FROM article_queue WHERE task_id = ?")->execute([$task_id]);

                    // 3. 删除任务素材
                    $db->prepare("DELETE FROM task_materials WHERE task_id = ?")->execute([$task_id]);

                    // 4. 删除任务调度
                    $db->prepare("DELETE FROM task_schedules WHERE task_id = ?")->execute([$task_id]);

                    // 5. 将文章的task_id设置为NULL（解除外键约束）
                    $db->prepare("UPDATE articles SET task_id = NULL WHERE task_id = ?")->execute([$task_id]);

                    // 6. 删除任务
                    $db->prepare("DELETE FROM tasks WHERE id = ?")->execute([$task_id]);

                    $db->commit();
                    $message = __('tasks.message.delete_success');
                } catch (Exception $e) {
                    $db->rollBack();
                    $error = __('tasks.message.delete_failed', ['message' => $e->getMessage()]);
                }
                break;
        }
    }
}

function formatTaskErrorSnippet(?string $message, int $maxLength = 72): string {
    $message = trim((string) $message);
    if ($message === '') {
        return '';
    }

    if (
        mb_strpos($message, '任务已暂停', 0, 'UTF-8') !== false ||
        mb_strpos($message, '管理员手动停止', 0, 'UTF-8') !== false
    ) {
        return __('tasks.failure.paused_detail');
    }

    if (mb_strpos($message, 'AI返回空正文', 0, 'UTF-8') !== false) {
        return __('tasks.failure.empty_content_detail');
    }

    if (mb_strpos($message, '正文过短', 0, 'UTF-8') !== false) {
        return __('tasks.failure.content_too_short_detail');
    }

    if (mb_strpos($message, '没有可用的标题', 0, 'UTF-8') !== false) {
        return __('tasks.failure.title_exhausted_detail');
    }

    if (preg_match('/CURL错误:\s*Operation timed out after\s+(\d+)\s+milliseconds/i', $message, $matches)) {
        $seconds = max(1, (int) round(((int) $matches[1]) / 1000));
        return __('tasks.failure.model_timeout_detail', ['seconds' => $seconds]);
    }

    if (mb_strlen($message, 'UTF-8') <= $maxLength) {
        return $message;
    }

    return mb_substr($message, 0, $maxLength - 1, 'UTF-8') . '…';
}

function describeTaskFailure(?string $message): array {
    $message = trim((string) $message);
    if ($message === '') {
        return [
            'label' => __('tasks.failure.execution_failed'),
            'detail' => '',
            'tone' => 'red',
        ];
    }

    if (mb_strpos($message, 'AI返回空正文', 0, 'UTF-8') !== false) {
        return [
            'label' => __('tasks.failure.empty_content'),
            'detail' => __('tasks.failure.empty_content_detail'),
            'tone' => 'red',
        ];
    }

    if (mb_strpos($message, '正文过短', 0, 'UTF-8') !== false) {
        return [
            'label' => __('tasks.failure.content_too_short'),
            'detail' => __('tasks.failure.content_too_short_detail'),
            'tone' => 'amber',
        ];
    }

    if (mb_strpos($message, '没有可用的标题', 0, 'UTF-8') !== false) {
        return [
            'label' => __('tasks.failure.title_exhausted'),
            'detail' => __('tasks.failure.title_exhausted_detail'),
            'tone' => 'amber',
        ];
    }

    if (
        mb_strpos($message, '任务已暂停', 0, 'UTF-8') !== false ||
        mb_strpos($message, '管理员手动停止', 0, 'UTF-8') !== false
    ) {
        return [
            'label' => __('tasks.failure.paused'),
            'detail' => __('tasks.failure.paused_detail'),
            'tone' => 'slate',
        ];
    }

    if (preg_match('/CURL错误:\s*Operation timed out after\s+(\d+)\s+milliseconds/i', $message, $matches)) {
        $seconds = max(1, (int) round(((int) $matches[1]) / 1000));
        return [
            'label' => __('tasks.failure.model_timeout'),
            'detail' => __('tasks.failure.model_timeout_detail', ['seconds' => $seconds]),
            'tone' => 'amber',
        ];
    }

    return [
        'label' => __('tasks.failure.execution_failed'),
        'detail' => formatTaskErrorSnippet($message, 110),
        'tone' => 'red',
    ];
}

function getFailureToneClasses(string $tone): array {
    return match ($tone) {
        'amber' => [
            'chip' => 'bg-amber-50 text-amber-700 border-amber-200',
            'card' => 'border-amber-200 bg-amber-50 text-amber-800',
            'detail' => 'text-amber-700',
        ],
        'slate' => [
            'chip' => 'bg-slate-50 text-slate-700 border-slate-200',
            'card' => 'border-slate-200 bg-slate-50 text-slate-800',
            'detail' => 'text-slate-600',
        ],
        default => [
            'chip' => 'bg-red-50 text-red-700 border-red-200',
            'card' => 'border-red-200 bg-red-50 text-red-800',
            'detail' => 'text-red-700',
        ],
    };
}

// 获取任务列表
// 包含批量执行状态信息、标题库名称和AI模型名称
$sql = "
    SELECT t.*,
           tl.name as title_library_name,
           am.name as ai_model_name,
           (SELECT COUNT(*) FROM articles WHERE task_id = t.id AND deleted_at IS NULL) as total_articles,
           (SELECT COUNT(*) FROM articles WHERE task_id = t.id AND status = 'published' AND deleted_at IS NULL) as published_articles,
           COALESCE((
               SELECT jq.status
               FROM job_queue jq
               WHERE jq.task_id = t.id
                 AND jq.status IN ('running', 'pending')
               ORDER BY
                   CASE jq.status
                       WHEN 'running' THEN 1
                       WHEN 'pending' THEN 2
                       ELSE 3
                   END,
                   jq.updated_at DESC,
                   jq.id DESC
               LIMIT 1
           ), (
               SELECT jq.status
               FROM job_queue jq
               WHERE jq.task_id = t.id
                 AND jq.status IN ('failed', 'completed', 'cancelled')
               ORDER BY jq.updated_at DESC, jq.id DESC
               LIMIT 1
           ), 'idle') as batch_status,
           COALESCE((
               SELECT COUNT(*)
               FROM task_runs tr
               WHERE tr.task_id = t.id
                 AND tr.status = 'completed'
           ), 0) as batch_success_count,
           COALESCE((
               SELECT COUNT(*)
               FROM task_runs tr
               WHERE tr.task_id = t.id
                 AND tr.status IN ('failed', 'retrying')
           ), 0) as batch_error_count,
           COALESCE((
               SELECT jq.error_message
               FROM job_queue jq
               WHERE jq.task_id = t.id
                 AND jq.status IN ('failed', 'cancelled')
                 AND COALESCE(jq.error_message, '') <> ''
               ORDER BY jq.updated_at DESC, jq.id DESC
               LIMIT 1
           ), NULLIF(t.last_error_message, ''), '') as batch_error_message,
           COALESCE((
               SELECT jq.attempt_count
               FROM job_queue jq
               WHERE jq.task_id = t.id
               ORDER BY jq.updated_at DESC, jq.id DESC
               LIMIT 1
           ), 0) as latest_attempt_count,
           COALESCE((
               SELECT jq.max_attempts
               FROM job_queue jq
               WHERE jq.task_id = t.id
               ORDER BY jq.updated_at DESC, jq.id DESC
               LIMIT 1
           ), 0) as latest_max_attempts,
           COALESCE((
               SELECT jq.status
               FROM job_queue jq
               WHERE jq.task_id = t.id
               ORDER BY jq.updated_at DESC, jq.id DESC
               LIMIT 1
           ), 'idle') as latest_job_status,
           t.last_run_at as batch_last_run,
           t.last_error_at,
           COALESCE((
               SELECT COUNT(*)
               FROM job_queue jq
               WHERE jq.task_id = t.id
                 AND jq.status = 'pending'
           ), 0) as pending_jobs,
           COALESCE((
               SELECT COUNT(*)
               FROM job_queue jq
               WHERE jq.task_id = t.id
                 AND jq.status = 'running'
           ), 0) as running_jobs,
           t.next_run_at,
           t.schedule_enabled
    FROM tasks t
    LEFT JOIN title_libraries tl ON t.title_library_id = tl.id
    LEFT JOIN ai_models am ON t.ai_model_id = am.id
    ORDER BY t.created_at DESC
";

try {
    $tasks = $db->query($sql)->fetchAll();
    foreach ($tasks as &$task) {
        if (($task['status'] ?? '') === 'paused' && (int) ($task['running_jobs'] ?? 0) === 0) {
            $task['batch_status'] = 'idle';
        }
        $task['batch_error_message'] = trim((string) ($task['batch_error_message'] ?? ''));
    }
    unset($task);
} catch (Exception $e) {
    $tasks = [];
    $error = __('tasks.message.query_failed', ['message' => $e->getMessage()]);
}

try {
    $workers = $db->query("
        SELECT worker_id, status, current_job_id, last_seen_at
        FROM worker_heartbeats
        ORDER BY last_seen_at DESC
        LIMIT 5
    ")->fetchAll();

    $queueStatsRows = $db->query("
        SELECT status, COUNT(*) as count
        FROM job_queue
        GROUP BY status
    ")->fetchAll();
    $queueStats = [];
    foreach ($queueStatsRows as $row) {
        $queueStats[$row['status']] = (int) $row['count'];
    }

    $recentJobs = $db->query("
        SELECT jq.id, jq.task_id, jq.status, jq.error_message, jq.updated_at, t.name as task_name
        FROM job_queue jq
        LEFT JOIN tasks t ON t.id = jq.task_id
        ORDER BY jq.updated_at DESC, jq.id DESC
        LIMIT 5
    ")->fetchAll();
} catch (Exception $e) {
    $workers = [];
    $queueStats = [];
    $recentJobs = [];
}

// 设置页面信息
$page_title = __('tasks.page_title');
$page_header = '
<div class="flex items-center justify-between">
    <div>
        <h1 class="text-2xl font-bold text-gray-900">' . htmlspecialchars(__('tasks.page_title'), ENT_QUOTES) . '</h1>
        <p class="mt-1 text-sm text-gray-600">' . htmlspecialchars(__('tasks.page_subtitle'), ENT_QUOTES) . '</p>
    </div>
    <div class="flex space-x-3">
        <a href="task-create.php" class="inline-flex items-center px-4 py-2 border border-transparent text-sm font-medium rounded-md text-white bg-blue-600 hover:bg-blue-700">
            <i data-lucide="plus" class="w-4 h-4 mr-2"></i>
            ' . htmlspecialchars(__('button.create_task'), ENT_QUOTES) . '
        </a>
        <button onclick="executeAllActiveTasks()" class="inline-flex items-center px-4 py-2 border border-gray-300 text-sm font-medium rounded-md text-gray-700 bg-white hover:bg-gray-50">
            <i data-lucide="play" class="w-4 h-4 mr-2"></i>
            ' . htmlspecialchars(__('button.run_all_tasks'), ENT_QUOTES) . '
        </button>
    </div>
</div>
';

// 包含头部模块
require_once __DIR__ . '/includes/header.php';
?>

        <!-- 任务列表 -->
        <div class="bg-white shadow rounded-lg">
            <div class="px-6 py-4 border-b border-gray-200">
                <h3 class="text-lg font-medium text-gray-900"><?php echo htmlspecialchars(__('tasks.list_title')); ?></h3>
            </div>
            
            <?php if (empty($tasks)): ?>
                <div class="px-6 py-8 text-center">
                    <i data-lucide="inbox" class="w-12 h-12 mx-auto text-gray-400 mb-4"></i>
                    <h3 class="text-lg font-medium text-gray-900 mb-2"><?php echo htmlspecialchars(__('tasks.empty_title')); ?></h3>
                    <p class="text-gray-500 mb-4"><?php echo htmlspecialchars(__('tasks.empty_desc')); ?></p>
                    <a href="task-create.php" class="inline-flex items-center px-4 py-2 border border-transparent text-sm font-medium rounded-md text-white bg-blue-600 hover:bg-blue-700">
                        <i data-lucide="plus" class="w-4 h-4 mr-2"></i>
                        <?php echo htmlspecialchars(__('button.new_task')); ?>
                    </a>
                </div>
            <?php else: ?>
                <div class="overflow-x-auto">
                    <table class="w-full min-w-[1110px] table-fixed divide-y divide-gray-200">
                        <colgroup>
                            <col class="w-[220px]">
                            <col class="w-[150px]">
                            <col class="w-[160px]">
                            <col class="w-[150px]">
                            <col class="w-[110px]">
                            <col class="w-[150px]">
                            <col class="w-[170px]">
                        </colgroup>
                        <thead class="bg-gray-50">
                            <tr>
                                <th class="px-5 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider"><?php echo htmlspecialchars(__('tasks.column.name')); ?></th>
                                <th class="px-5 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider"><?php echo htmlspecialchars(__('tasks.column.created_at')); ?></th>
                                <th class="px-5 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider"><?php echo htmlspecialchars(__('tasks.column.model')); ?></th>
                                <th class="px-5 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider"><?php echo htmlspecialchars(__('tasks.column.article_stats')); ?></th>
                                <th class="px-5 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider"><?php echo htmlspecialchars(__('tasks.column.loop_count')); ?></th>
                                <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider"><?php echo htmlspecialchars(__('tasks.column.status')); ?></th>
                                <th class="px-3 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider"><?php echo htmlspecialchars(__('tasks.column.actions')); ?></th>
                            </tr>
                        </thead>
                        <tbody class="bg-white divide-y divide-gray-200">
                            <?php foreach ($tasks as $task): ?>
                                <?php
                                $failureInfo = describeTaskFailure($task['batch_error_message'] ?? '');
                                $failureClasses = getFailureToneClasses($failureInfo['tone']);
                                $hasVisibleFailure = !empty($task['batch_error_message']) && in_array($task['batch_status'], ['failed', 'cancelled'], true);
                                $isRetrying = (($task['latest_job_status'] ?? '') === 'pending')
                                    && ((int) ($task['latest_attempt_count'] ?? 0) > 0)
                                    && ((int) ($task['latest_max_attempts'] ?? 0) > 1);
                                ?>
                                <tr class="hover:bg-gray-50">
                                    <td class="px-5 py-4 align-top">
                                        <div class="text-sm font-medium leading-6 text-gray-900 break-words"><?php echo htmlspecialchars($task['name']); ?></div>
                                        <div class="mt-1 text-sm text-gray-500 break-words"><?php echo htmlspecialchars(__('tasks.label.title_library')); ?>: <?php echo htmlspecialchars($task['title_library_name']); ?></div>
                                        <?php if ($hasVisibleFailure): ?>
                                            <div class="mt-2 rounded-md border px-3 py-2 text-xs <?php echo $failureClasses['card']; ?>">
                                                <div class="flex items-center gap-2">
                                                    <span class="inline-flex items-center rounded-full border px-2 py-0.5 font-medium <?php echo $failureClasses['chip']; ?>">
                                                        <?php echo htmlspecialchars($failureInfo['label']); ?>
                                                    </span>
                                                    <?php if (!empty($task['last_error_at'])): ?>
                                                        <span class="text-[11px] opacity-75"><?php echo date('m-d H:i', strtotime($task['last_error_at'])); ?></span>
                                                    <?php endif; ?>
                                                </div>
                                                <?php if (!empty($failureInfo['detail'])): ?>
                                                    <div class="mt-1 <?php echo $failureClasses['detail']; ?>">
                                                        <?php echo htmlspecialchars($failureInfo['detail']); ?>
                                                    </div>
                                                <?php endif; ?>
                                                <?php
                                                $failureSnippet = formatTaskErrorSnippet($task['batch_error_message'], 100);
                                                if ($failureSnippet !== '' && $failureSnippet !== ($failureInfo['detail'] ?? '')):
                                                ?>
                                                    <div class="mt-1 break-words opacity-90">
                                                        <?php echo htmlspecialchars($failureSnippet); ?>
                                                    </div>
                                                <?php endif; ?>
                                            </div>
                                        <?php endif; ?>
                                    </td>
                                    <td class="px-5 py-4 align-top whitespace-nowrap text-sm text-gray-500">
                                        <?php echo date('Y-m-d H:i', strtotime($task['created_at'])); ?>
                                    </td>
                                    <td class="px-5 py-4 align-top text-sm text-gray-500">
                                        <div class="break-words leading-6"><?php echo htmlspecialchars($task['ai_model_name']); ?></div>
                                        <div class="mt-1">
                                            <span class="inline-flex items-center rounded-full px-2 py-0.5 text-xs font-medium <?php echo (($task['model_selection_mode'] ?? 'fixed') === 'smart_failover') ? 'bg-violet-100 text-violet-800' : 'bg-slate-100 text-slate-700'; ?>">
                                                <?php echo htmlspecialchars(($task['model_selection_mode'] ?? 'fixed') === 'smart_failover' ? __('tasks.mode.smart_failover') : __('tasks.mode.fixed')); ?>
                                            </span>
                                        </div>
                                    </td>
                                    <td class="px-5 py-4 align-top whitespace-nowrap text-sm text-gray-500">
                                        <div><?php echo htmlspecialchars(__('tasks.label.created_articles', ['count' => $task['total_articles']])); ?></div>
                                        <div><?php echo htmlspecialchars(__('tasks.label.published_articles', ['count' => $task['published_articles']])); ?></div>
                                    </td>
                                    <td class="px-5 py-4 align-top whitespace-nowrap text-sm text-gray-500">
                                        <?php echo htmlspecialchars(__('tasks.label.loop_times', ['count' => $task['loop_count']])); ?>
                                        <?php if ($task['is_loop']): ?>
                                            <span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium bg-blue-100 text-blue-800 ml-1"><?php echo htmlspecialchars(__('tasks.label.loop')); ?></span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="px-4 py-4 align-top">
                                        <form method="POST" class="inline" id="status-form-<?php echo $task['id']; ?>">
                                            <input type="hidden" name="csrf_token" value="<?php echo generate_csrf_token(); ?>">
                                            <input type="hidden" name="action" value="toggle_status">
                                            <input type="hidden" name="task_id" value="<?php echo $task['id']; ?>">
                                            <input type="hidden" name="status" value="<?php echo $task['status']; ?>">
                                            <label class="inline-flex items-center">
                                                <input type="checkbox"
                                                       <?php echo $task['status'] === 'active' ? 'checked' : ''; ?>
                                                       onchange="handleStatusToggle(<?php echo $task['id']; ?>, this)"
                                                       class="rounded border-gray-300 text-blue-600 shadow-sm focus:border-blue-300 focus:ring focus:ring-blue-200 focus:ring-opacity-50">
                                                <span class="ml-2 text-sm <?php echo $task['status'] === 'active' ? 'text-green-600' : 'text-gray-500'; ?>">
                                                    <?php echo htmlspecialchars($task['status'] === 'active' ? __('tasks.status.running') : __('tasks.status.paused')); ?>
                                                </span>

                                                <!-- 动态文章数量显示 -->
                                                <?php if ($task['status'] === 'active' && in_array($task['batch_status'], ['running', 'pending'], true)): ?>
                                                    <span class="ml-2 inline-flex items-center text-xs text-blue-600 bg-blue-50 px-2 py-1 rounded-full border border-blue-200"
                                                          id="article-count-<?php echo $task['id']; ?>">
                                                        <i data-lucide="file-text" class="w-3 h-3 mr-1"></i>
                                                        <span class="article-count-number"><?php echo $task['created_count']; ?></span><?php echo htmlspecialchars(__('common.articles_unit')); ?>
                                                    </span>
                                                <?php endif; ?>
                                            </label>
                                        </form>
                                    </td>
                                    <td class="px-3 py-4 align-top">
                                        <!-- 一行显示四个操作按钮 -->
                                        <div class="flex w-fit items-center gap-1.5">
                                            <!-- 1. 批量执行控制按钮 -->
                                            <?php if ($task['status'] === 'active'): ?>
                                                <?php if (in_array($task['batch_status'], ['running', 'pending'], true)): ?>
                                                    <!-- 正在运行 - 显示停止按钮 -->
                                                    <button onclick="stopBatchExecution(<?php echo $task['id']; ?>, '<?php echo htmlspecialchars($task['name'], ENT_QUOTES); ?>')"
                                                            class="inline-flex items-center justify-center w-8 h-8 text-red-600 hover:text-red-800 hover:bg-red-50 rounded-md transition-colors border border-red-200"
                                                            title="<?php echo htmlspecialchars(__('tasks.action.stop_batch')); ?>"
                                                            id="batch-btn-<?php echo $task['id']; ?>">
                                                        <i data-lucide="square" class="w-4 h-4"></i>
                                                    </button>
                                                <?php else: ?>
                                                    <!-- 空闲状态 - 显示开始按钮 -->
                                                    <button onclick="startBatchExecution(<?php echo $task['id']; ?>, '<?php echo htmlspecialchars($task['name'], ENT_QUOTES); ?>')"
                                                            class="inline-flex items-center justify-center w-8 h-8 text-green-600 hover:text-green-800 hover:bg-green-50 rounded-md transition-colors border border-green-200"
                                                            title="<?php echo htmlspecialchars(__('tasks.action.start_batch')); ?>"
                                                            id="batch-btn-<?php echo $task['id']; ?>">
                                                        <i data-lucide="play" class="w-4 h-4"></i>
                                                    </button>
                                                <?php endif; ?>
                                            <?php else: ?>
                                                <!-- 任务暂停状态 -->
                                                <span class="inline-flex items-center justify-center w-8 h-8 text-gray-400 bg-gray-50 rounded-md border border-gray-200" title="<?php echo htmlspecialchars(__('tasks.action.paused')); ?>">
                                                    <i data-lucide="pause" class="w-4 h-4"></i>
                                                </span>
                                            <?php endif; ?>

                                            <!-- 2. 设置任务 -->
                                            <a href="task-edit.php?id=<?php echo $task['id']; ?>"
                                               class="inline-flex items-center justify-center w-8 h-8 text-blue-600 hover:text-blue-800 hover:bg-blue-50 rounded-md transition-colors border border-blue-200"
                                               title="<?php echo htmlspecialchars(__('tasks.action.settings')); ?>">
                                                <i data-lucide="settings" class="w-4 h-4"></i>
                                            </a>

                                            <!-- 3. 文章管理 -->
                                            <a href="articles.php?task_id=<?php echo $task['id']; ?>"
                                               class="inline-flex items-center justify-center w-8 h-8 text-green-600 hover:text-green-800 hover:bg-green-50 rounded-md transition-colors border border-green-200 relative"
                                               title="<?php echo htmlspecialchars(__('tasks.action.articles')); ?>">
                                                <i data-lucide="file-text" class="w-4 h-4"></i>
                                                <?php if ($task['total_articles'] > 0): ?>
                                                    <span class="absolute -top-1 -right-1 bg-green-500 text-white text-xs rounded-full w-4 h-4 flex items-center justify-center" style="font-size: 10px;">
                                                        <?php echo $task['total_articles'] > 99 ? '99+' : $task['total_articles']; ?>
                                                    </span>
                                                <?php endif; ?>
                                            </a>

                                            <!-- 4. 删除任务 -->
                                            <form method="POST" class="inline" onsubmit="return confirm(<?php echo json_encode(__('tasks.confirm.delete'), JSON_UNESCAPED_UNICODE); ?>)">
                                                <input type="hidden" name="csrf_token" value="<?php echo generate_csrf_token(); ?>">
                                                <input type="hidden" name="action" value="delete_task">
                                                <input type="hidden" name="task_id" value="<?php echo $task['id']; ?>">
                                                <button type="submit"
                                                        class="inline-flex items-center justify-center w-8 h-8 text-red-600 hover:text-red-800 hover:bg-red-50 rounded-md transition-colors border border-red-200"
                                                        title="<?php echo htmlspecialchars(__('tasks.action.delete')); ?>">
                                                    <i data-lucide="trash-2" class="w-4 h-4"></i>
                                                </button>
                                            </form>
                                        </div>

                                        <!-- 批量执行统计信息 -->
                                        <?php if ($task['batch_success_count'] > 0 || $task['batch_error_count'] > 0): ?>
                                            <div class="mt-2 php-stats-status">
                                                <span class="text-xs text-gray-500 bg-gray-50 px-2 py-1 rounded-full border border-gray-200">
                                                    <?php echo htmlspecialchars(__('tasks.label.success_fail', ['success' => $task['batch_success_count'], 'fail' => $task['batch_error_count']])); ?>
                                                </span>
                                            </div>
                                        <?php endif; ?>
                                        <div class="mt-2 max-w-[165px]" id="batch-status-<?php echo $task['id']; ?>">
                                            <?php if ($task['batch_status'] === 'pending'): ?>
                                                <div class="flex flex-col gap-1 text-xs">
                                                    <span class="inline-flex items-center rounded-full border px-2 py-1 <?php echo $isRetrying ? 'bg-amber-50 text-amber-700 border-amber-200' : 'bg-blue-50 text-blue-700 border-blue-200'; ?>">
                                                        <?php if ($isRetrying): ?>
                                                            <?php echo htmlspecialchars(__('tasks.label.retrying_with_attempts', ['current' => (int) $task['latest_attempt_count'], 'max' => (int) $task['latest_max_attempts']])); ?>
                                                        <?php else: ?>
                                                            <?php
                                                            echo htmlspecialchars(
                                                                !empty($task['pending_jobs'])
                                                                    ? __('tasks.label.pending_queue', ['count' => (int) $task['pending_jobs']])
                                                                    : __('tasks.status.pending')
                                                            );
                                                            ?>
                                                        <?php endif; ?>
                                                    </span>
                                                    <?php if ($isRetrying && !empty($task['batch_error_message'])): ?>
                                                        <div class="break-words leading-5 text-amber-700">
                                                            <?php echo htmlspecialchars(__('tasks.label.latest_reason', ['message' => formatTaskErrorSnippet($task['batch_error_message'], 56)])); ?>
                                                        </div>
                                                    <?php endif; ?>
                                                </div>
                                            <?php elseif ($task['batch_status'] === 'failed'): ?>
                                                <div class="flex flex-col gap-1 text-xs">
                                                    <span class="inline-flex items-center rounded-full border px-2 py-1 <?php echo $failureClasses['chip']; ?>">
                                                        <?php echo htmlspecialchars($failureInfo['label']); ?>
                                                    </span>
                                                    <?php
                                                    $failureStatusSnippet = formatTaskErrorSnippet($task['batch_error_message'], 60);
                                                    if (!empty($task['batch_error_message']) && $failureStatusSnippet !== ($failureInfo['detail'] ?? '')):
                                                    ?>
                                                        <div class="break-words leading-5 <?php echo $failureClasses['detail']; ?>">
                                                            <?php echo htmlspecialchars($failureStatusSnippet); ?>
                                                        </div>
                                                    <?php endif; ?>
                                                </div>
                                            <?php elseif ($task['batch_status'] === 'completed'): ?>
                                                <span class="text-xs text-emerald-600 bg-emerald-50 px-2 py-1 rounded-full border border-emerald-200">
                                                    <?php echo htmlspecialchars(__('tasks.status.completed')); ?>
                                                </span>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>

        <!-- 统计信息 -->
        <div class="mt-8 grid grid-cols-1 md:grid-cols-4 gap-6">
            <div class="bg-white overflow-hidden shadow rounded-lg">
                <div class="p-5">
                    <div class="flex items-center">
                        <div class="flex-shrink-0">
                            <i data-lucide="zap" class="h-6 w-6 text-blue-600"></i>
                        </div>
                        <div class="ml-5 w-0 flex-1">
                            <dl>
                                <dt class="text-sm font-medium text-gray-500 truncate"><?php echo htmlspecialchars(__('tasks.stats.total_tasks')); ?></dt>
                                <dd class="text-lg font-medium text-gray-900"><?php echo count($tasks); ?></dd>
                            </dl>
                        </div>
                    </div>
                </div>
            </div>

            <div class="bg-white overflow-hidden shadow rounded-lg">
                <div class="p-5">
                    <div class="flex items-center">
                        <div class="flex-shrink-0">
                            <i data-lucide="play" class="h-6 w-6 text-green-600"></i>
                        </div>
                        <div class="ml-5 w-0 flex-1">
                            <dl>
                                <dt class="text-sm font-medium text-gray-500 truncate"><?php echo htmlspecialchars(__('tasks.stats.running')); ?></dt>
                                <dd class="text-lg font-medium text-gray-900">
                                    <?php echo count(array_filter($tasks, function($t) { return $t['status'] === 'active'; })); ?>
                                </dd>
                            </dl>
                        </div>
                    </div>
                </div>
            </div>

            <div class="bg-white overflow-hidden shadow rounded-lg">
                <div class="p-5">
                    <div class="flex items-center">
                        <div class="flex-shrink-0">
                            <i data-lucide="file-text" class="h-6 w-6 text-purple-600"></i>
                        </div>
                        <div class="ml-5 w-0 flex-1">
                            <dl>
                                <dt class="text-sm font-medium text-gray-500 truncate"><?php echo htmlspecialchars(__('tasks.stats.total_articles')); ?></dt>
                                <dd class="text-lg font-medium text-gray-900">
                                    <?php echo array_sum(array_column($tasks, 'total_articles')); ?>
                                </dd>
                            </dl>
                        </div>
                    </div>
                </div>
            </div>

            <div class="bg-white overflow-hidden shadow rounded-lg">
                <div class="p-5">
                    <div class="flex items-center">
                        <div class="flex-shrink-0">
                            <i data-lucide="globe" class="h-6 w-6 text-orange-600"></i>
                        </div>
                        <div class="ml-5 w-0 flex-1">
                            <dl>
                                <dt class="text-sm font-medium text-gray-500 truncate"><?php echo htmlspecialchars(__('tasks.stats.total_published')); ?></dt>
                                <dd class="text-lg font-medium text-gray-900">
                                    <?php echo array_sum(array_column($tasks, 'published_articles')); ?>
                                </dd>
                            </dl>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="mt-8 grid grid-cols-1 xl:grid-cols-3 gap-6">
            <div class="bg-white overflow-hidden shadow rounded-lg">
                <div class="px-5 py-4 border-b border-gray-200">
                    <h3 class="text-base font-medium text-gray-900"><?php echo htmlspecialchars(__('tasks.worker.title')); ?></h3>
                </div>
                <div class="p-5">
                    <?php if (empty($workers)): ?>
                        <p class="text-sm text-gray-500"><?php echo htmlspecialchars(__('tasks.worker.none')); ?></p>
                    <?php else: ?>
                        <div class="space-y-3">
                            <?php foreach ($workers as $worker): ?>
                                <div class="rounded-lg border border-gray-200 px-3 py-3">
                                    <div class="flex items-center justify-between gap-3">
                                        <span class="font-mono text-xs text-gray-700"><?php echo htmlspecialchars($worker['worker_id']); ?></span>
                                        <span class="inline-flex items-center rounded-full px-2 py-0.5 text-xs font-medium <?php echo $worker['status'] === 'running' ? 'bg-emerald-50 text-emerald-700 border border-emerald-200' : 'bg-gray-50 text-gray-700 border border-gray-200'; ?>">
                                            <?php echo htmlspecialchars($worker['status']); ?>
                                        </span>
                                    </div>
                                    <div class="mt-2 text-xs text-gray-500">
                                        <div><?php echo htmlspecialchars(__('tasks.worker.current_job')); ?>: <?php echo $worker['current_job_id'] ? '#' . (int) $worker['current_job_id'] : htmlspecialchars(__('tasks.worker.idle')); ?></div>
                                        <div><?php echo htmlspecialchars(__('tasks.worker.last_seen')); ?>: <?php echo htmlspecialchars($worker['last_seen_at']); ?></div>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <div class="bg-white overflow-hidden shadow rounded-lg">
                <div class="px-5 py-4 border-b border-gray-200">
                    <h3 class="text-base font-medium text-gray-900"><?php echo htmlspecialchars(__('tasks.queue.title')); ?></h3>
                </div>
                <div class="p-5">
                    <div class="grid grid-cols-2 gap-3">
                        <div class="rounded-lg border border-blue-200 bg-blue-50 px-4 py-3">
                            <div class="text-xs text-blue-700"><?php echo htmlspecialchars(__('tasks.queue.pending')); ?></div>
                            <div class="mt-1 text-2xl font-semibold text-blue-900"><?php echo (int) ($queueStats['pending'] ?? 0); ?></div>
                        </div>
                        <div class="rounded-lg border border-emerald-200 bg-emerald-50 px-4 py-3">
                            <div class="text-xs text-emerald-700"><?php echo htmlspecialchars(__('tasks.queue.running')); ?></div>
                            <div class="mt-1 text-2xl font-semibold text-emerald-900"><?php echo (int) ($queueStats['running'] ?? 0); ?></div>
                        </div>
                        <div class="rounded-lg border border-red-200 bg-red-50 px-4 py-3">
                            <div class="text-xs text-red-700"><?php echo htmlspecialchars(__('tasks.queue.failed')); ?></div>
                            <div class="mt-1 text-2xl font-semibold text-red-900"><?php echo (int) ($queueStats['failed'] ?? 0); ?></div>
                        </div>
                        <div class="rounded-lg border border-gray-200 bg-gray-50 px-4 py-3">
                            <div class="text-xs text-gray-700"><?php echo htmlspecialchars(__('tasks.queue.completed')); ?></div>
                            <div class="mt-1 text-2xl font-semibold text-gray-900"><?php echo (int) ($queueStats['completed'] ?? 0); ?></div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="bg-white overflow-hidden shadow rounded-lg">
                <div class="px-5 py-4 border-b border-gray-200">
                    <h3 class="text-base font-medium text-gray-900"><?php echo htmlspecialchars(__('tasks.jobs.recent')); ?></h3>
                </div>
                <div class="p-5">
                    <?php if (empty($recentJobs)): ?>
                        <p class="text-sm text-gray-500"><?php echo htmlspecialchars(__('tasks.jobs.none')); ?></p>
                    <?php else: ?>
                        <div class="space-y-3">
                            <?php foreach ($recentJobs as $job): ?>
                                <div class="rounded-lg border border-gray-200 px-3 py-3">
                                    <div class="flex items-center justify-between gap-3">
                                        <div class="min-w-0">
                                            <div class="text-sm font-medium text-gray-900 truncate"><?php echo htmlspecialchars($job['task_name'] ?: __('tasks.jobs.unknown_task')); ?></div>
                                            <div class="text-xs text-gray-500">Job #<?php echo (int) $job['id']; ?> · <?php echo htmlspecialchars(__('tasks.jobs.task_prefix')); ?> #<?php echo (int) $job['task_id']; ?></div>
                                        </div>
                                        <span class="inline-flex items-center rounded-full px-2 py-0.5 text-xs font-medium border <?php
                                            echo match ($job['status']) {
                                                'running' => 'bg-emerald-50 text-emerald-700 border-emerald-200',
                                                'pending' => 'bg-blue-50 text-blue-700 border-blue-200',
                                                'failed' => 'bg-red-50 text-red-700 border-red-200',
                                                'completed' => 'bg-gray-50 text-gray-700 border-gray-200',
                                                default => 'bg-gray-50 text-gray-700 border-gray-200',
                                            };
                                        ?>">
                                            <?php echo htmlspecialchars($job['status']); ?>
                                        </span>
                                    </div>
                                    <div class="mt-2 text-xs text-gray-500">
                                        <div><?php echo htmlspecialchars(__('tasks.jobs.updated_at')); ?>: <?php echo htmlspecialchars($job['updated_at']); ?></div>
                                        <?php if (!empty($job['error_message'])): ?>
                                            <?php
                                            $jobFailureInfo = describeTaskFailure($job['error_message']);
                                            $jobFailureClasses = getFailureToneClasses($jobFailureInfo['tone']);
                                            ?>
                                            <div class="mt-2 rounded-md border px-2.5 py-2 <?php echo $jobFailureClasses['card']; ?>">
                                                <div class="font-medium"><?php echo htmlspecialchars($jobFailureInfo['label']); ?></div>
                                                <?php if (!empty($jobFailureInfo['detail'])): ?>
                                                    <div class="mt-1 <?php echo $jobFailureClasses['detail']; ?>"><?php echo htmlspecialchars($jobFailureInfo['detail']); ?></div>
                                                <?php endif; ?>
                                                <?php
                                                $jobFailureSnippet = formatTaskErrorSnippet($job['error_message'], 100);
                                                if ($jobFailureSnippet !== '' && $jobFailureSnippet !== ($jobFailureInfo['detail'] ?? '')):
                                                ?>
                                                    <div class="mt-1 break-words opacity-90"><?php echo htmlspecialchars($jobFailureSnippet); ?></div>
                                                <?php endif; ?>
                                            </div>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

<script>
const TASK_STATUS_POLL_MS = 10000;
let taskStatusInterval;
const TASK_I18N = <?php echo json_encode([
    'stopBatch' => __('tasks.action.stop_batch'),
    'startBatch' => __('tasks.action.start_batch'),
    'secondsSuffix' => __('common.seconds'),
    'minutesSuffix' => __('common.minutes'),
    'hoursSuffix' => __('common.hours'),
    'daysSuffix' => __('common.days'),
    'completed' => __('tasks.status.completed'),
    'queued' => __('tasks.status.pending'),
    'running' => __('tasks.status.running'),
    'retryingWithAttempts' => __('tasks.label.retrying_with_attempts', ['current' => '__CURRENT__', 'max' => '__MAX__']),
    'pendingRunning' => __('tasks.label.pending_running', ['pending' => '__PENDING__', 'running' => '__RUNNING__']),
    'estimated' => __('tasks.label.estimated', ['time' => '__TIME__']),
    'latestReason' => __('tasks.label.latest_reason', ['message' => '__MESSAGE__']),
    'emptyContent' => __('tasks.failure.empty_content'),
    'emptyContentDetail' => __('tasks.failure.empty_content_detail'),
    'contentTooShort' => __('tasks.failure.content_too_short'),
    'contentTooShortDetail' => __('tasks.failure.content_too_short_detail'),
    'titleExhausted' => __('tasks.failure.title_exhausted'),
    'titleExhaustedDetail' => __('tasks.failure.title_exhausted_detail'),
    'taskPaused' => __('tasks.failure.paused'),
    'taskPausedDetail' => __('tasks.failure.paused_detail'),
    'modelTimeout' => __('tasks.failure.model_timeout'),
    'modelTimeoutDetail' => __('tasks.failure.model_timeout_detail', ['seconds' => '__SECONDS__']),
    'recentFailed' => __('tasks.failure.recent_failed'),
    'syncFailed' => __('tasks.message.status_update_failed'),
    'confirmStart' => __('tasks.confirm.start', ['name' => '__NAME__']),
    'confirmStop' => __('tasks.confirm.stop', ['name' => '__NAME__']),
    'starting' => __('tasks.action.starting'),
    'stopping' => __('tasks.action.stopping'),
    'startFailed' => __('tasks.message.start_failed', ['message' => '__MESSAGE__']),
    'stopFailed' => __('tasks.message.stop_failed', ['message' => '__MESSAGE__']),
    'requestFailed' => __('tasks.message.request_failed', ['message' => '__MESSAGE__']),
    'taskQueued' => __('tasks.message.task_queued', ['name' => '__NAME__']),
    'taskStopped' => __('tasks.message.task_stopped', ['name' => '__NAME__']),
    'noRunnable' => __('tasks.message.no_runnable'),
    'confirmRunAll' => __('tasks.confirm.run_all'),
    'bulkSubmitted' => __('tasks.message.bulk_submitted', ['success' => '__SUCCESS__', 'total' => '__TOTAL__']),
    'bulkSubmittedPartial' => __('tasks.message.bulk_submitted_partial', ['success' => '__SUCCESS__', 'total' => '__TOTAL__']),
    'activating' => __('tasks.action.activating'),
    'pausing' => __('tasks.action.pausing'),
    'confirmActivate' => __('tasks.confirm.activate'),
    'confirmPause' => __('tasks.confirm.pause'),
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>;

function renderIcons() {
    if (typeof lucide !== 'undefined') {
        lucide.createIcons();
    }
}

function showNotification(type, message) {
    const styles = {
        success: { border: 'border-green-200', icon: 'check-circle', iconColor: 'text-green-500' },
        error: { border: 'border-red-200', icon: 'alert-circle', iconColor: 'text-red-500' },
        warning: { border: 'border-orange-200', icon: 'alert-triangle', iconColor: 'text-orange-500' },
        info: { border: 'border-blue-200', icon: 'info', iconColor: 'text-blue-500' }
    };

    const style = styles[type] || styles.info;
    const notification = document.createElement('div');
    notification.className = `fixed top-4 right-4 z-50 max-w-md rounded-lg border-2 bg-white p-4 shadow-xl ${style.border}`;
    notification.innerHTML = `
        <div class="flex items-start gap-3">
            <div class="${style.iconColor}">
                <i data-lucide="${style.icon}" class="h-5 w-5"></i>
            </div>
            <pre class="flex-1 whitespace-pre-wrap text-sm leading-relaxed text-gray-800">${message}</pre>
            <button onclick="this.closest('.fixed').remove()" class="text-gray-400 hover:text-gray-600">
                <i data-lucide="x" class="h-4 w-4"></i>
            </button>
        </div>
    `;
    document.body.appendChild(notification);
    renderIcons();

    setTimeout(() => {
        if (notification.parentElement) {
            notification.remove();
        }
    }, type === 'error' ? 8000 : 5000);
}

function setButtonLoading(btn, text, classes) {
    btn.disabled = true;
    btn.className = classes;
    btn.innerHTML = `<i data-lucide="loader-2" class="h-4 w-4 animate-spin"></i><span class="sr-only">${text}</span>`;
    renderIcons();
}

function updateBatchButton(btn, taskId, taskName, isRunning) {
    if (!btn) return;
    btn.disabled = false;
    btn.className = isRunning
        ? 'inline-flex items-center justify-center w-8 h-8 text-red-600 hover:text-red-800 hover:bg-red-50 rounded-md transition-colors border border-red-200'
        : 'inline-flex items-center justify-center w-8 h-8 text-green-600 hover:text-green-800 hover:bg-green-50 rounded-md transition-colors border border-green-200';
    btn.innerHTML = isRunning
        ? '<i data-lucide="square" class="w-4 h-4"></i>'
        : '<i data-lucide="play" class="w-4 h-4"></i>';
    btn.title = isRunning ? TASK_I18N.stopBatch : TASK_I18N.startBatch;
    btn.onclick = isRunning
        ? () => stopBatchExecution(taskId, taskName)
        : () => startBatchExecution(taskId, taskName);
    renderIcons();
}

function formatEstimatedTime(seconds) {
    if (seconds < 60) return `${seconds}${TASK_I18N.secondsSuffix}`;
    if (seconds < 3600) return `${Math.round(seconds / 60)}${TASK_I18N.minutesSuffix}`;
    if (seconds < 86400) return `${Math.round(seconds / 3600)}${TASK_I18N.hoursSuffix}`;
    return `${Math.round(seconds / 86400)}${TASK_I18N.daysSuffix}`;
}

function updateBatchStatus(task) {
    const statusDiv = document.getElementById(`batch-status-${task.id}`);
    if (!statusDiv) return;

    const createdCount = Number(task.created_count || 0);
    const draftLimit = Number(task.draft_limit || 0);
    const successCount = Number(task.batch_success_count || 0);
    const errorCount = Number(task.batch_error_count || 0);
    const publishInterval = Number(task.publish_interval || 3600);
    const pendingJobs = Number(task.pending_jobs || 0);
    const runningJobs = Number(task.running_jobs || 0);
    const isRunning = task.batch_status === 'running' || task.batch_status === 'pending';
    const errorMessage = normalizeRuntimeError(task.batch_error_message || '');
    const latestAttemptCount = Number(task.latest_attempt_count || 0);
    const latestMaxAttempts = Number(task.latest_max_attempts || 0);
    const latestJobStatus = String(task.latest_job_status || '');
    const isRetrying = latestJobStatus === 'pending' && latestAttemptCount > 0 && latestMaxAttempts > 1;

    if (!isRunning) {
        if (task.batch_status === 'failed') {
            const failureMeta = getFailureMeta(errorMessage);
            statusDiv.innerHTML = `
                <div class="flex flex-col gap-1 text-xs">
                    <span class="inline-flex items-center justify-center rounded-full border px-2 py-1 ${failureMeta.chipClasses}">
                        ${escapeHtml(failureMeta.label)}
                    </span>
                    ${errorMessage ? `<div class="mx-auto max-w-[220px] break-words leading-5 ${failureMeta.detailClasses}">${escapeHtml(truncateText(errorMessage, 60))}</div>` : ''}
                </div>
            `;
        } else if (task.batch_status === 'completed') {
            statusDiv.innerHTML = `<span class="text-xs text-emerald-600 bg-emerald-50 px-2 py-1 rounded-full border border-emerald-200">${escapeHtml(TASK_I18N.completed)}</span>`;
        } else {
            statusDiv.innerHTML = '';
        }
        return;
    }

    const stateLabel = task.batch_status === 'pending' ? TASK_I18N.queued : TASK_I18N.running;
    const stateClasses = task.batch_status === 'pending'
        ? 'bg-blue-50 text-blue-700 border-blue-200'
        : 'bg-emerald-50 text-emerald-700 border-emerald-200';
    const remainingArticles = Math.max(0, draftLimit - createdCount);
    const estimatedTime = formatEstimatedTime(remainingArticles * publishInterval);

    statusDiv.innerHTML = `
        <div class="flex flex-col gap-1 text-xs">
            <div class="flex items-center gap-2">
                ${task.batch_status === 'pending' ? `
                    <span class="inline-flex items-center rounded-full border px-2 py-0.5 ${isRetrying ? 'bg-amber-50 text-amber-700 border-amber-200' : stateClasses}">
                        <i data-lucide="activity" class="h-3 w-3 mr-1"></i>${isRetrying ? TASK_I18N.retryingWithAttempts.replace('__CURRENT__', latestAttemptCount).replace('__MAX__', latestMaxAttempts) : stateLabel}
                    </span>
                ` : `
                    <span class="inline-flex items-center rounded-full border px-2 py-0.5 ${stateClasses}">
                        <i data-lucide="activity" class="h-3 w-3 mr-1"></i>${stateLabel}
                    </span>
                `}
                <span class="text-gray-600">${createdCount}/${draftLimit}</span>
            </div>
            <div class="text-gray-500">
                ${TASK_I18N.pendingRunning.replace('__PENDING__', pendingJobs).replace('__RUNNING__', runningJobs)}
                ${remainingArticles > 0 ? ` · ${TASK_I18N.estimated.replace('__TIME__', estimatedTime)}` : ''}
            </div>
            ${isRetrying && errorMessage ? `
                <div class="max-w-[220px] break-words leading-5 text-amber-700">
                    ${escapeHtml(TASK_I18N.latestReason.replace('__MESSAGE__', truncateText(errorMessage, 56)))}
                </div>
            ` : ''}
            ${(successCount > 0 || errorCount > 0) ? `
                <div class="text-gray-500">
                    <span class="text-green-600">✓ ${successCount}</span>
                    ${errorCount > 0 ? `<span class="ml-2 text-red-600">✗ ${errorCount}</span>` : ''}
                </div>
            ` : ''}
        </div>
    `;
    renderIcons();
}

function escapeHtml(value) {
    return String(value)
        .replaceAll('&', '&amp;')
        .replaceAll('<', '&lt;')
        .replaceAll('>', '&gt;')
        .replaceAll('"', '&quot;')
        .replaceAll("'", '&#039;');
}

function truncateText(value, maxLength) {
    if (value.length <= maxLength) {
        return value;
    }
    return `${value.slice(0, maxLength - 1)}…`;
}

function normalizeRuntimeError(message) {
    const value = String(message || '').trim();
    if (!value) {
        return '';
    }

    if (value.includes('任务已暂停') || value.includes('管理员手动停止')) {
        return TASK_I18N.taskPausedDetail;
    }

    if (value.includes('AI返回空正文')) {
        return TASK_I18N.emptyContentDetail;
    }

    if (value.includes('正文过短')) {
        return TASK_I18N.contentTooShortDetail;
    }

    if (value.includes('没有可用的标题')) {
        return TASK_I18N.titleExhaustedDetail;
    }

    const timeoutMatch = value.match(/CURL错误:\s*Operation timed out after\s+(\d+)\s+milliseconds/i);
    if (timeoutMatch) {
        const seconds = Math.max(1, Math.round(Number(timeoutMatch[1]) / 1000));
        return TASK_I18N.modelTimeoutDetail.replace('__SECONDS__', String(seconds));
    }

    return value;
}

function getFailureMeta(message) {
    const rawMessage = String(message || '').trim();
    const normalizedMessage = normalizeRuntimeError(message);

    if (
        rawMessage.includes('AI返回空正文') ||
        normalizedMessage === TASK_I18N.emptyContentDetail
    ) {
        return {
            label: TASK_I18N.emptyContent,
            chipClasses: 'bg-red-50 text-red-700 border-red-200',
            detailClasses: 'text-red-700',
        };
    }
    if (
        rawMessage.includes('正文过短') ||
        normalizedMessage === TASK_I18N.contentTooShortDetail
    ) {
        return {
            label: TASK_I18N.contentTooShort,
            chipClasses: 'bg-amber-50 text-amber-700 border-amber-200',
            detailClasses: 'text-amber-700',
        };
    }
    if (
        rawMessage.includes('没有可用的标题') ||
        normalizedMessage === TASK_I18N.titleExhaustedDetail
    ) {
        return {
            label: TASK_I18N.titleExhausted,
            chipClasses: 'bg-amber-50 text-amber-700 border-amber-200',
            detailClasses: 'text-amber-700',
        };
    }
    if (
        rawMessage.includes('任务已暂停') ||
        rawMessage.includes('管理员手动停止') ||
        normalizedMessage === TASK_I18N.taskPausedDetail
    ) {
        return {
            label: TASK_I18N.taskPaused,
            chipClasses: 'bg-slate-50 text-slate-700 border-slate-200',
            detailClasses: 'text-slate-600',
        };
    }
    if (
        rawMessage.includes('模型接口超时') ||
        /CURL错误:\s*Operation timed out after\s+\d+\s+milliseconds/i.test(rawMessage)
    ) {
        return {
            label: TASK_I18N.modelTimeout,
            chipClasses: 'bg-amber-50 text-amber-700 border-amber-200',
            detailClasses: 'text-amber-700',
        };
    }
    return {
        label: TASK_I18N.recentFailed,
        chipClasses: 'bg-red-50 text-red-700 border-red-200',
        detailClasses: 'text-red-700',
    };
}

function updateTaskUI(task) {
    const btn = document.getElementById(`batch-btn-${task.id}`);
    const shouldBeRunning = task.batch_status === 'running' || task.batch_status === 'pending';
    updateBatchButton(btn, task.id, task.name, shouldBeRunning);
    updateBatchStatus(task);
}

function refreshTaskStatuses() {
    fetch(`${window.adminUrl('task_health_check.php')}?action=status`)
        .then(response => response.json())
        .then(data => {
            if (!data.success) return;
            data.tasks.forEach(updateTaskUI);
        })
        .catch(error => {
            console.error(TASK_I18N.syncFailed, error);
        });
}

function startBatchExecution(taskId, taskName) {
    if (!confirm(TASK_I18N.confirmStart.replace('__NAME__', taskName))) {
        return;
    }

    const btn = document.getElementById(`batch-btn-${taskId}`);
    setButtonLoading(btn, TASK_I18N.starting, 'inline-flex items-center justify-center w-8 h-8 rounded-md border border-blue-200 bg-blue-50 text-blue-600 cursor-wait');

    fetch(window.adminUrl('start_task_batch.php'), {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ task_id: taskId, action: 'start' })
    })
        .then(response => response.json())
        .then(data => {
            if (!data.success) {
                showNotification('error', TASK_I18N.startFailed.replace('__MESSAGE__', data.message));
                updateBatchButton(btn, taskId, taskName, false);
                return;
            }
            showNotification('success', TASK_I18N.taskQueued.replace('__NAME__', taskName));
            updateBatchButton(btn, taskId, taskName, true);
            refreshTaskStatuses();
        })
        .catch(error => {
            showNotification('error', TASK_I18N.requestFailed.replace('__MESSAGE__', error.message));
            updateBatchButton(btn, taskId, taskName, false);
        });
}

function stopBatchExecution(taskId, taskName) {
    if (!confirm(TASK_I18N.confirmStop.replace('__NAME__', taskName))) {
        return;
    }

    const btn = document.getElementById(`batch-btn-${taskId}`);
    setButtonLoading(btn, TASK_I18N.stopping, 'inline-flex items-center justify-center w-8 h-8 rounded-md border border-orange-200 bg-orange-50 text-orange-600 cursor-wait');

    fetch(window.adminUrl('start_task_batch.php'), {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ task_id: taskId, action: 'stop' })
    })
        .then(response => response.json())
        .then(data => {
            if (!data.success) {
                showNotification('error', TASK_I18N.stopFailed.replace('__MESSAGE__', data.message));
                updateBatchButton(btn, taskId, taskName, true);
                return;
            }
            showNotification('success', TASK_I18N.taskStopped.replace('__NAME__', taskName));
            updateBatchButton(btn, taskId, taskName, false);
            refreshTaskStatuses();
        })
        .catch(error => {
            showNotification('error', TASK_I18N.requestFailed.replace('__MESSAGE__', error.message));
            updateBatchButton(btn, taskId, taskName, true);
        });
}

function executeAllActiveTasks() {
    const buttons = Array.from(document.querySelectorAll('[id^="batch-btn-"]')).filter(btn => {
        return btn.className.includes('text-green-600');
    });

    if (buttons.length === 0) {
        showNotification('info', TASK_I18N.noRunnable);
        return;
    }

    if (!confirm(TASK_I18N.confirmRunAll)) {
        return;
    }

    let completed = 0;
    let success = 0;

    buttons.forEach((btn, index) => {
        const taskId = Number(btn.id.replace('batch-btn-', ''));
        const taskName = btn.closest('tr').querySelector('td:first-child .text-sm.font-medium').textContent.trim();
        setTimeout(() => {
            fetch(window.adminUrl('start_task_batch.php'), {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ task_id: taskId, action: 'start' })
            })
                .then(response => response.json())
                .then(data => {
                    completed += 1;
                    if (data.success) success += 1;
                    if (completed === buttons.length) {
                        showNotification('success', TASK_I18N.bulkSubmitted.replace('__SUCCESS__', success).replace('__TOTAL__', buttons.length));
                        refreshTaskStatuses();
                    }
                })
                .catch(() => {
                    completed += 1;
                    if (completed === buttons.length) {
                        showNotification('warning', TASK_I18N.bulkSubmittedPartial.replace('__SUCCESS__', success).replace('__TOTAL__', buttons.length));
                        refreshTaskStatuses();
                    }
                });
        }, index * 150);
    });
}

function handleStatusToggle(taskId, checkbox) {
    const form = checkbox.closest('form');
    const currentStatus = form.querySelector('input[name="status"]').value;
    const nextLabel = checkbox.checked ? TASK_I18N.activating : TASK_I18N.pausing;
    const statusSpan = form.querySelector('label span');

    if (!confirm(checkbox.checked ? TASK_I18N.confirmActivate : TASK_I18N.confirmPause)) {
        checkbox.checked = currentStatus === 'active';
        return;
    }

    checkbox.disabled = true;
    statusSpan.textContent = nextLabel;
    statusSpan.className = `ml-2 text-sm ${checkbox.checked ? 'text-blue-600' : 'text-orange-600'}`;
    form.submit();
}

document.addEventListener('DOMContentLoaded', () => {
    renderIcons();
    refreshTaskStatuses();
    taskStatusInterval = window.setInterval(refreshTaskStatuses, TASK_STATUS_POLL_MS);
});

window.addEventListener('beforeunload', () => {
    if (taskStatusInterval) {
        window.clearInterval(taskStatusInterval);
    }
});
</script>

<?php
// 包含底部模块
require_once __DIR__ . '/includes/footer.php';
?>
