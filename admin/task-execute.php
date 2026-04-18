<?php
/**
 * 智能GEO内容系统 - 任务执行测试
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
require_once __DIR__ . '/../includes/api_response.php';
require_once __DIR__ . '/../includes/task_lifecycle_service.php';

// 检查管理员登录
require_admin_login();

// 立即释放session锁，允许其他页面并发访问
session_write_close();

$task_id = intval($_GET['id'] ?? 0);
$message = '';
$error = '';
$execution_log = [];

if ($task_id <= 0) {
    die(__('task_execute.error.invalid_task_id'));
}

// 处理POST请求
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
        $error = __('message.csrf_invalid');
    } else {
        $action = $_POST['action'] ?? '';
        
        if ($action === 'execute') {
            $taskLifecycleService = new TaskLifecycleService($db);
            
            // 记录执行开始
            $execution_log[] = [
                'time' => date('H:i:s'),
                'type' => 'info',
                'message' => __('task_execute.log.queueing')
            ];
            
            try {
                $result = $taskLifecycleService->enqueueTask($task_id, 'generate_article', ['source' => 'admin_task_execute']);

                $execution_log[] = [
                    'time' => date('H:i:s'),
                    'type' => 'success',
                    'message' => __('task_execute.log.queued', ['job_id' => (string) ($result['job_id'] ?? 0)])
                ];
                $message = __('task_execute.message.queued', ['job_id' => (string) ($result['job_id'] ?? 0)]);
            } catch (ApiException $e) {
                $execution_log[] = [
                    'time' => date('H:i:s'),
                    'type' => 'error',
                    'message' => __('task_execute.log.queue_failed', ['message' => $e->getMessage()])
                ];
                $error = __('task_execute.error.queue_failed', ['message' => $e->getMessage()]);
            } catch (Exception $e) {
                $execution_log[] = [
                    'time' => date('H:i:s'),
                    'type' => 'error',
                    'message' => __('task_execute.log.exception', ['message' => $e->getMessage()])
                ];
                $error = __('task_execute.error.exception', ['message' => $e->getMessage()]);
            }
        }
    }
}

// 获取任务信息
$stmt = $db->prepare("
    SELECT t.*, tl.name as title_library_name, il.name as image_library_name, 
           p.name as prompt_name, am.name as ai_model_name, au.name as author_name
    FROM tasks t
    LEFT JOIN title_libraries tl ON t.title_library_id = tl.id
    LEFT JOIN image_libraries il ON t.image_library_id = il.id
    LEFT JOIN prompts p ON t.prompt_id = p.id
    LEFT JOIN ai_models am ON t.ai_model_id = am.id
    LEFT JOIN authors au ON t.author_id = au.id
    WHERE t.id = ?
");
$stmt->execute([$task_id]);
$task = $stmt->fetch();

if (!$task) {
    die(__('task_execute.error.task_not_found'));
}

function fetchCountByTask(PDO $db, string $sql, int $taskId): int {
    $stmt = $db->prepare($sql);
    $stmt->execute([$taskId]);
    return (int) $stmt->fetchColumn();
}

function formatTaskExecutionError(?string $message, int $maxLength = 120): string {
    $message = trim((string) $message);
    if ($message === '') {
        return '';
    }

    if (mb_strlen($message, 'UTF-8') <= $maxLength) {
        return $message;
    }

    return mb_substr($message, 0, $maxLength - 1, 'UTF-8') . '…';
}

function describeTaskExecutionError(?string $message): array {
    $message = trim((string) $message);
    if ($message === '') {
        return [
            'label' => __('task_execute.failure.no_error'),
            'detail' => '',
            'tone' => 'slate',
        ];
    }

    if (mb_strpos($message, 'AI返回空正文', 0, 'UTF-8') !== false) {
        return [
            'label' => __('task_execute.failure.empty_content_label'),
            'detail' => __('task_execute.failure.empty_content_detail'),
            'tone' => 'red',
        ];
    }

    if (mb_strpos($message, '正文过短', 0, 'UTF-8') !== false) {
        return [
            'label' => __('task_execute.failure.too_short_label'),
            'detail' => __('task_execute.failure.too_short_detail'),
            'tone' => 'amber',
        ];
    }

    if (mb_strpos($message, '没有可用的标题', 0, 'UTF-8') !== false) {
        return [
            'label' => __('task_execute.failure.no_titles_label'),
            'detail' => __('task_execute.failure.no_titles_detail'),
            'tone' => 'amber',
        ];
    }

    if (
        mb_strpos($message, '任务已暂停', 0, 'UTF-8') !== false ||
        mb_strpos($message, '管理员手动停止', 0, 'UTF-8') !== false
    ) {
        return [
            'label' => __('task_execute.failure.task_paused_label'),
            'detail' => __('task_execute.failure.task_paused_detail'),
            'tone' => 'slate',
        ];
    }

    return [
        'label' => __('task_execute.failure.default_label'),
        'detail' => formatTaskExecutionError($message),
        'tone' => 'red',
    ];
}

function describeTaskExecuteStatus(string $status): string {
    return match ($status) {
        'active' => __('status.running'),
        'paused' => __('status.paused'),
        default => __('task_edit.status.completed'),
    };
}

function describeTaskRunStatus(string $status): string {
    return match ($status) {
        'completed' => __('status.success'),
        'failed' => __('status.failed'),
        'retrying' => __('status.retrying'),
        'cancelled' => __('task_execute.run.cancelled'),
        default => $status,
    };
}

function getExecutionToneClasses(string $tone): array {
    return match ($tone) {
        'amber' => [
            'chip' => 'bg-amber-50 text-amber-700 border-amber-200',
            'card' => 'border-amber-200 bg-amber-50 text-amber-800',
            'text' => 'text-amber-700',
        ],
        'slate' => [
            'chip' => 'bg-slate-50 text-slate-700 border-slate-200',
            'card' => 'border-slate-200 bg-slate-50 text-slate-800',
            'text' => 'text-slate-600',
        ],
        default => [
            'chip' => 'bg-red-50 text-red-700 border-red-200',
            'card' => 'border-red-200 bg-red-50 text-red-800',
            'text' => 'text-red-700',
        ],
    };
}

// 获取任务统计
$stats = [
    'total_articles' => fetchCountByTask($db, "SELECT COUNT(*) FROM articles WHERE task_id = ?", $task_id),
    'published_articles' => fetchCountByTask($db, "SELECT COUNT(*) FROM articles WHERE task_id = ? AND status = 'published'", $task_id),
    'pending_articles' => fetchCountByTask($db, "SELECT COUNT(*) FROM articles WHERE task_id = ? AND review_status = 'pending'", $task_id)
];

// 获取最近生成的文章
$recentArticlesStmt = $db->prepare("
    SELECT * FROM articles 
    WHERE task_id = ? 
    ORDER BY created_at DESC 
    LIMIT 5
");
$recentArticlesStmt->execute([$task_id]);
$recent_articles = $recentArticlesStmt->fetchAll(PDO::FETCH_ASSOC);

$recentRunsStmt = $db->prepare("
    SELECT
        tr.id,
        tr.job_id,
        tr.status,
        tr.article_id,
        tr.error_message,
        tr.duration_ms,
        tr.meta,
        tr.started_at,
        tr.finished_at,
        tr.created_at,
        jq.status AS queue_status,
        jq.attempt_count,
        jq.max_attempts,
        jq.error_message AS queue_error_message
    FROM task_runs tr
    LEFT JOIN job_queue jq ON jq.id = tr.job_id
    WHERE tr.task_id = ?
    ORDER BY tr.created_at DESC, tr.id DESC
    LIMIT 8
");
$recentRunsStmt->execute([$task_id]);
$recent_runs = $recentRunsStmt->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="<?php echo htmlspecialchars(app_html_lang()); ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars(__('task_execute.page_title') . ' - ' . __('site_settings.system_name')); ?></title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/lucide/0.263.1/lucide.min.css">
    <script src="https://unpkg.com/lucide@latest/dist/umd/lucide.js"></script>
</head>
<body class="bg-gray-50">
    <div class="max-w-6xl mx-auto py-6 sm:px-6 lg:px-8">
        <!-- 消息提示 -->
        <?php if ($message): ?>
            <div class="mb-4 bg-green-100 border border-green-400 text-green-700 px-4 py-3 rounded">
                <?php echo htmlspecialchars($message); ?>
            </div>
        <?php endif; ?>
        
        <?php if ($error): ?>
            <div class="mb-4 bg-red-100 border border-red-400 text-red-700 px-4 py-3 rounded">
                <?php echo htmlspecialchars($error); ?>
            </div>
        <?php endif; ?>

        <!-- 页面标题 -->
        <div class="mb-8">
            <div class="flex items-center justify-between">
                <div>
                    <h1 class="text-2xl font-bold text-gray-900"><?php echo __('task_execute.page_heading'); ?></h1>
                    <p class="mt-1 text-sm text-gray-600"><?php echo htmlspecialchars($task['name']); ?></p>
                </div>
                <a href="tasks.php" class="inline-flex items-center px-4 py-2 border border-gray-300 rounded-md text-sm font-medium text-gray-700 bg-white hover:bg-gray-50">
                    <i data-lucide="arrow-left" class="w-4 h-4 mr-2"></i>
                    <?php echo __('task_execute.button.back_to_tasks'); ?>
                </a>
            </div>
        </div>

        <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
            <!-- 任务信息 -->
            <div class="lg:col-span-2">
                <div class="bg-white shadow rounded-lg mb-6">
                    <div class="px-6 py-4 border-b border-gray-200">
                        <h3 class="text-lg font-medium text-gray-900"><?php echo __('task_execute.section.task_info'); ?></h3>
                    </div>
                    <div class="px-6 py-4">
                        <?php if (!empty($task['last_error_message'])): ?>
                            <?php
                            $lastFailureInfo = describeTaskExecutionError($task['last_error_message']);
                            $lastFailureClasses = getExecutionToneClasses($lastFailureInfo['tone']);
                            ?>
                            <div class="mb-5 rounded-lg border px-4 py-3 <?php echo $lastFailureClasses['card']; ?>">
                                <div class="flex items-center gap-2">
                                    <span class="inline-flex items-center rounded-full border px-2 py-0.5 text-xs font-medium <?php echo $lastFailureClasses['chip']; ?>">
                                        <?php echo htmlspecialchars($lastFailureInfo['label']); ?>
                                    </span>
                                    <?php if (!empty($task['last_error_at'])): ?>
                                        <span class="text-xs opacity-75"><?php echo __('task_execute.label.last_failed_at', ['time' => date('m-d H:i', strtotime($task['last_error_at']))]); ?></span>
                                    <?php endif; ?>
                                </div>
                                <?php if (!empty($lastFailureInfo['detail'])): ?>
                                    <div class="mt-2 text-sm <?php echo $lastFailureClasses['text']; ?>">
                                        <?php echo htmlspecialchars($lastFailureInfo['detail']); ?>
                                    </div>
                                <?php endif; ?>
                                <div class="mt-2 text-sm break-words opacity-90">
                                    <?php echo htmlspecialchars($task['last_error_message']); ?>
                                </div>
                            </div>
                        <?php endif; ?>
                        <dl class="grid grid-cols-1 gap-x-4 gap-y-6 sm:grid-cols-2">
                            <div>
                                <dt class="text-sm font-medium text-gray-500"><?php echo __('task_execute.label.task_name'); ?></dt>
                                <dd class="mt-1 text-sm text-gray-900"><?php echo htmlspecialchars($task['name']); ?></dd>
                            </div>
                            <div>
                                <dt class="text-sm font-medium text-gray-500"><?php echo __('task_execute.label.status'); ?></dt>
                                <dd class="mt-1">
                                    <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium <?php 
                                        echo $task['status'] === 'active' ? 'bg-green-100 text-green-800' : 
                                            ($task['status'] === 'paused' ? 'bg-yellow-100 text-yellow-800' : 'bg-gray-100 text-gray-800'); 
                                    ?>">
                                        <?php 
                                        echo describeTaskExecuteStatus((string) $task['status']);
                                        ?>
                                    </span>
                                </dd>
                            </div>
                            <div>
                                <dt class="text-sm font-medium text-gray-500"><?php echo __('task_execute.label.title_library'); ?></dt>
                                <dd class="mt-1 text-sm text-gray-900"><?php echo htmlspecialchars($task['title_library_name']); ?></dd>
                            </div>
                            <div>
                                <dt class="text-sm font-medium text-gray-500"><?php echo __('task_execute.label.ai_model'); ?></dt>
                                <dd class="mt-1 text-sm text-gray-900"><?php echo htmlspecialchars($task['ai_model_name']); ?></dd>
                            </div>
                            <div>
                                <dt class="text-sm font-medium text-gray-500"><?php echo __('task_execute.label.publish_interval'); ?></dt>
                                <dd class="mt-1 text-sm text-gray-900"><?php echo __('task_execute.value.seconds', ['count' => (int) $task['publish_interval']]); ?></dd>
                            </div>
                            <div>
                                <dt class="text-sm font-medium text-gray-500"><?php echo __('task_execute.label.draft_limit'); ?></dt>
                                <dd class="mt-1 text-sm text-gray-900"><?php echo __('task_execute.value.articles', ['count' => (int) $task['draft_limit']]); ?></dd>
                            </div>
                            <div>
                                <dt class="text-sm font-medium text-gray-500"><?php echo __('task_execute.label.created_articles'); ?></dt>
                                <dd class="mt-1 text-sm text-gray-900"><?php echo __('task_execute.value.articles', ['count' => (int) $task['created_count']]); ?></dd>
                            </div>
                            <div>
                                <dt class="text-sm font-medium text-gray-500"><?php echo __('task_execute.label.published_articles'); ?></dt>
                                <dd class="mt-1 text-sm text-gray-900"><?php echo __('task_execute.value.articles', ['count' => (int) $task['published_count']]); ?></dd>
                            </div>
                        </dl>
                    </div>
                </div>

                <!-- 执行控制 -->
                <div class="bg-white shadow rounded-lg mb-6">
                    <div class="px-6 py-4 border-b border-gray-200">
                        <h3 class="text-lg font-medium text-gray-900"><?php echo __('task_execute.section.execution_control'); ?></h3>
                    </div>
                    <div class="px-6 py-4">
                        <form method="POST" onsubmit="return confirm('<?php echo addslashes(__('task_execute.confirm.execute')); ?>')">
                            <input type="hidden" name="csrf_token" value="<?php echo generate_csrf_token(); ?>">
                            <input type="hidden" name="action" value="execute">
                            
                            <div class="flex items-center justify-between">
                                <div>
                                    <p class="text-sm text-gray-600"><?php echo __('task_execute.help.execute_once'); ?></p>
                                    <p class="text-xs text-gray-500 mt-1"><?php echo __('task_execute.help.shared_engine'); ?></p>
                                    <p class="text-xs text-gray-500 mt-1"><?php echo __('task_execute.help.queue_mode'); ?></p>
                                </div>
                                <button type="submit" class="inline-flex items-center px-4 py-2 border border-transparent text-sm font-medium rounded-md text-white bg-blue-600 hover:bg-blue-700">
                                    <i data-lucide="play" class="w-4 h-4 mr-2"></i>
                                    <?php echo __('task_execute.button.execute'); ?>
                                </button>
                            </div>
                        </form>
                    </div>
                </div>

                <!-- 执行日志 -->
                <?php if (!empty($execution_log)): ?>
                    <div class="bg-white shadow rounded-lg">
                        <div class="px-6 py-4 border-b border-gray-200">
                            <h3 class="text-lg font-medium text-gray-900"><?php echo __('task_execute.section.execution_log'); ?></h3>
                        </div>
                        <div class="px-6 py-4">
                            <div class="space-y-2">
                                <?php foreach ($execution_log as $log): ?>
                                    <div class="flex items-start space-x-3">
                                        <span class="text-xs text-gray-500 mt-0.5"><?php echo $log['time']; ?></span>
                                        <div class="flex-1">
                                            <span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium <?php 
                                                echo $log['type'] === 'success' ? 'bg-green-100 text-green-800' : 
                                                    ($log['type'] === 'error' ? 'bg-red-100 text-red-800' : 'bg-blue-100 text-blue-800'); 
                                            ?>">
                                                <?php echo htmlspecialchars($log['message']); ?>
                                            </span>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    </div>
                <?php endif; ?>

                <div class="bg-white shadow rounded-lg <?php echo !empty($execution_log) ? 'mt-6' : ''; ?>">
                    <div class="px-6 py-4 border-b border-gray-200">
                        <h3 class="text-lg font-medium text-gray-900"><?php echo __('task_execute.section.recent_runs'); ?></h3>
                        <p class="mt-1 text-sm text-gray-500"><?php echo __('task_execute.section.recent_runs_desc'); ?></p>
                    </div>
                    <div class="px-6 py-4">
                        <?php if (empty($recent_runs)): ?>
                            <p class="text-sm text-gray-500"><?php echo __('task_execute.empty.no_runs'); ?></p>
                        <?php else: ?>
                            <div class="space-y-4">
                                <?php foreach ($recent_runs as $run): ?>
                                    <?php
                                    $runErrorMessage = trim((string) ($run['error_message'] ?: $run['queue_error_message'] ?: ''));
                                    $runFailureInfo = describeTaskExecutionError($runErrorMessage);
                                    $runFailureClasses = getExecutionToneClasses($runFailureInfo['tone']);
                                    $runMeta = [];
                                    if (!empty($run['meta'])) {
                                        $decoded = json_decode((string) $run['meta'], true);
                                        if (is_array($decoded)) {
                                            $runMeta = $decoded;
                                        }
                                    }
                                    $statusClasses = match ($run['status']) {
                                        'completed' => 'bg-emerald-50 text-emerald-700 border-emerald-200',
                                        'failed' => 'bg-red-50 text-red-700 border-red-200',
                                        'retrying' => 'bg-amber-50 text-amber-700 border-amber-200',
                                        'cancelled' => 'bg-slate-50 text-slate-700 border-slate-200',
                                        default => 'bg-blue-50 text-blue-700 border-blue-200',
                                    };
                                    ?>
                                    <div class="rounded-lg border border-gray-200 px-4 py-4">
                                        <div class="flex items-start justify-between gap-4">
                                            <div>
                                                <div class="flex items-center gap-2">
                                                    <span class="inline-flex items-center rounded-full border px-2 py-0.5 text-xs font-medium <?php echo $statusClasses; ?>">
                                                        <?php echo htmlspecialchars(describeTaskRunStatus((string) $run['status'])); ?>
                                                    </span>
                                                    <span class="text-sm font-medium text-gray-900"><?php echo __('task_execute.run.run_id', ['id' => (int) $run['id']]); ?></span>
                                                    <?php if (!empty($run['job_id'])): ?>
                                                        <span class="text-xs text-gray-500"><?php echo __('task_execute.run.job_id', ['id' => (int) $run['job_id']]); ?></span>
                                                    <?php endif; ?>
                                                </div>
                                                <div class="mt-2 text-xs text-gray-500 space-y-1">
                                                    <div><?php echo __('task_execute.run.started_at', ['time' => htmlspecialchars($run['started_at'] ?: $run['created_at'])]); ?></div>
                                                    <?php if (!empty($run['finished_at'])): ?>
                                                        <div><?php echo __('task_execute.run.finished_at', ['time' => htmlspecialchars($run['finished_at'])]); ?></div>
                                                    <?php endif; ?>
                                                    <?php if ((int) $run['duration_ms'] > 0): ?>
                                                        <div><?php echo __('task_execute.run.duration_seconds', ['seconds' => number_format(((int) $run['duration_ms']) / 1000, 2)]); ?></div>
                                                    <?php endif; ?>
                                                    <?php if (!empty($run['article_id'])): ?>
                                                        <div><?php echo __('task_execute.run.article_id', ['id' => (int) $run['article_id']]); ?></div>
                                                    <?php endif; ?>
                                                    <?php if (isset($run['attempt_count']) && isset($run['max_attempts']) && (int) $run['max_attempts'] > 0): ?>
                                                        <div><?php echo __('task_execute.run.attempts', ['current' => (int) $run['attempt_count'], 'max' => (int) $run['max_attempts']]); ?></div>
                                                    <?php endif; ?>
                                                </div>
                                            </div>
                                            <?php if ($run['status'] === 'completed' && !empty($runMeta['title'])): ?>
                                                <div class="max-w-xs text-right text-sm text-gray-600">
                                                    <div class="font-medium text-gray-900"><?php echo __('task_execute.label.generated_title'); ?></div>
                                                    <div class="mt-1 break-words"><?php echo htmlspecialchars((string) $runMeta['title']); ?></div>
                                                </div>
                                            <?php endif; ?>
                                        </div>

                                        <?php if ($runErrorMessage !== ''): ?>
                                            <div class="mt-3 rounded-md border px-3 py-3 <?php echo $runFailureClasses['card']; ?>">
                                                <div class="flex items-center gap-2">
                                                    <span class="inline-flex items-center rounded-full border px-2 py-0.5 text-xs font-medium <?php echo $runFailureClasses['chip']; ?>">
                                                        <?php echo htmlspecialchars($runFailureInfo['label']); ?>
                                                    </span>
                                                </div>
                                                <?php if (!empty($runFailureInfo['detail'])): ?>
                                                    <div class="mt-2 text-sm <?php echo $runFailureClasses['text']; ?>">
                                                        <?php echo htmlspecialchars($runFailureInfo['detail']); ?>
                                                    </div>
                                                <?php endif; ?>
                                                <div class="mt-2 text-sm break-words opacity-90">
                                                    <?php echo htmlspecialchars($runErrorMessage); ?>
                                                </div>
                                            </div>
                                        <?php endif; ?>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <!-- 侧边栏 -->
            <div class="space-y-6">
                <!-- 统计信息 -->
                <div class="bg-white shadow rounded-lg">
                    <div class="px-6 py-4 border-b border-gray-200">
                        <h3 class="text-lg font-medium text-gray-900"><?php echo __('task_execute.section.stats'); ?></h3>
                    </div>
                    <div class="px-6 py-4 space-y-4">
                        <div class="flex justify-between">
                            <span class="text-sm text-gray-600"><?php echo __('task_execute.stats.total_articles'); ?></span>
                            <span class="text-sm font-medium text-gray-900"><?php echo $stats['total_articles']; ?></span>
                        </div>
                        <div class="flex justify-between">
                            <span class="text-sm text-gray-600"><?php echo __('task_execute.stats.published'); ?></span>
                            <span class="text-sm font-medium text-green-600"><?php echo $stats['published_articles']; ?></span>
                        </div>
                        <div class="flex justify-between">
                            <span class="text-sm text-gray-600"><?php echo __('task_execute.stats.pending_review'); ?></span>
                            <span class="text-sm font-medium text-yellow-600"><?php echo $stats['pending_articles']; ?></span>
                        </div>
                    </div>
                </div>

                <!-- 最近文章 -->
                <div class="bg-white shadow rounded-lg">
                    <div class="px-6 py-4 border-b border-gray-200">
                        <h3 class="text-lg font-medium text-gray-900"><?php echo __('task_execute.section.recent_articles'); ?></h3>
                    </div>
                    <div class="px-6 py-4">
                        <?php if (!empty($recent_articles)): ?>
                            <div class="space-y-3">
                                <?php foreach ($recent_articles as $article): ?>
                                    <div class="border-l-4 border-blue-400 pl-3">
                                        <p class="text-sm font-medium text-gray-900"><?php echo htmlspecialchars($article['title']); ?></p>
                                        <p class="text-xs text-gray-500">
                                            <?php echo date('m-d H:i', strtotime($article['created_at'])); ?> • 
                                            <span class="<?php echo $article['status'] === 'published' ? 'text-green-600' : 'text-yellow-600'; ?>">
                                                <?php echo $article['status'] === 'published' ? __('articles.status.published') : __('articles.status.draft'); ?>
                                            </span>
                                        </p>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php else: ?>
                            <p class="text-sm text-gray-500"><?php echo __('task_execute.empty.no_articles'); ?></p>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script>
        // 初始化Lucide图标
        document.addEventListener('DOMContentLoaded', function() {
            if (typeof lucide !== 'undefined') {
                lucide.createIcons();
            }
        });
    </script>
</body>
</html>
