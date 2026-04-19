<?php
/**
 * 智能GEO内容系统 - 文章审核
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

// 检查管理员登录
require_admin_login();

// 立即释放session锁，允许其他页面并发访问
session_write_close();

$message = '';
$error = '';
$admin_site_name = get_setting('site_title', SITE_NAME);

// 处理POST请求
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
        $error = 'CSRF验证失败';
    } else {
        $action = $_POST['action'] ?? '';
        
        switch ($action) {
            case 'review_article':
                $article_id = intval($_POST['article_id'] ?? 0);
                $review_status = $_POST['review_status'] ?? '';
                $review_note = trim($_POST['review_note'] ?? '');
                
                if ($article_id > 0 && !empty($review_status)) {
                    try {
                        $db->beginTransaction();
                        
                        // 更新文章审核状态
                        $articleStmt = $db->prepare("SELECT status, published_at, task_id FROM articles WHERE id = ? AND deleted_at IS NULL");
                        $articleStmt->execute([$article_id]);
                        $article = $articleStmt->fetch();
                        if (!$article) {
                            throw new Exception('文章不存在');
                        }

                        $desiredStatus = $article['status'] ?? 'draft';
                        if (in_array($review_status, ['approved', 'auto_approved'], true)) {
                            $taskStmt = $db->prepare("
                                SELECT need_review
                                FROM tasks
                                WHERE id = ?
                            ");
                            $taskStmt->execute([$article['task_id']]);
                            $task = $taskStmt->fetch();
                            if ($review_status === 'auto_approved' || ($task && !$task['need_review'])) {
                                $desiredStatus = 'published';
                            }
                        }

                        $workflowState = normalize_article_workflow_state($desiredStatus, $review_status, $article['published_at'] ?? null);
                        $stmt = $db->prepare("
                            UPDATE articles SET 
                                status = ?,
                                review_status = ?, 
                                published_at = ?,
                                updated_at = CURRENT_TIMESTAMP 
                            WHERE id = ?
                        ");
                        $stmt->execute([$workflowState['status'], $workflowState['review_status'], $workflowState['published_at'], $article_id]);
                        
                        // 记录审核日志
                        $admin_id = $_SESSION['admin_id'] ?? 3; // 默认使用admin用户ID

                        // 确保admin_id存在于数据库中
                        $stmt_check = $db->prepare("SELECT id FROM admins WHERE id = ?");
                        $stmt_check->execute([$admin_id]);
                        if (!$stmt_check->fetch()) {
                            // 如果admin_id不存在，使用第一个可用的admin
                            $first_admin = $db->query("SELECT id FROM admins ORDER BY id LIMIT 1")->fetch();
                            $admin_id = $first_admin ? $first_admin['id'] : 3;
                        }

                        $stmt = $db->prepare("
                            INSERT INTO article_reviews (article_id, admin_id, review_status, review_note, created_at)
                            VALUES (?, ?, ?, ?, CURRENT_TIMESTAMP)
                        ");
                        $stmt->execute([$article_id, $admin_id, $review_status, $review_note]);
                        
                        if ($workflowState['status'] === 'published' && in_array($workflowState['review_status'], ['approved', 'auto_approved'], true)) {
                            $message = '审核结果已更新，文章已进入发布状态';
                        } elseif ($workflowState['review_status'] === 'approved') {
                            $message = '审核结果已更新为人工通过';
                        } elseif ($workflowState['review_status'] === 'rejected') {
                            $message = '审核结果已更新为拒绝，文章已退回草稿';
                        } else {
                            $message = '审核结果已更新';
                        }
                        
                        $db->commit();
                    } catch (Exception $e) {
                        $db->rollBack();
                        $error = '审核失败: ' . $e->getMessage();
                    }
                }
                break;
                
            case 'batch_review':
                $article_ids = $_POST['article_ids'] ?? [];
                $review_status = $_POST['review_status'] ?? '';
                $review_note = trim($_POST['review_note'] ?? '');
                
                if (!empty($article_ids) && !empty($review_status)) {
                    try {
                        $db->beginTransaction();
                        
                        $placeholders = str_repeat('?,', count($article_ids) - 1) . '?';

                        // 批量记录审核日志
                        $admin_id = $_SESSION['admin_id'] ?? 3; // 默认使用admin用户ID

                        // 确保admin_id存在于数据库中
                        $stmt_check = $db->prepare("SELECT id FROM admins WHERE id = ?");
                        $stmt_check->execute([$admin_id]);
                        if (!$stmt_check->fetch()) {
                            // 如果admin_id不存在，使用第一个可用的admin
                            $first_admin = $db->query("SELECT id FROM admins ORDER BY id LIMIT 1")->fetch();
                            $admin_id = $first_admin ? $first_admin['id'] : 3;
                        }

                        foreach ($article_ids as $article_id) {
                            $articleStmt = $db->prepare("SELECT status, published_at, task_id FROM articles WHERE id = ? AND deleted_at IS NULL");
                            $articleStmt->execute([$article_id]);
                            $article = $articleStmt->fetch();
                            if (!$article) {
                                continue;
                            }

                            $desiredStatus = $article['status'] ?? 'draft';
                            if (in_array($review_status, ['approved', 'auto_approved'], true)) {
                                $taskStmt = $db->prepare("
                                    SELECT need_review
                                    FROM tasks
                                    WHERE id = ?
                                ");
                                $taskStmt->execute([$article['task_id']]);
                                $task = $taskStmt->fetch();
                                if ($review_status === 'auto_approved' || ($task && !$task['need_review'])) {
                                    $desiredStatus = 'published';
                                }
                            }

                            $workflowState = normalize_article_workflow_state($desiredStatus, $review_status, $article['published_at'] ?? null);
                            $updateStmt = $db->prepare("
                                UPDATE articles SET
                                    status = ?,
                                    review_status = ?,
                                    published_at = ?,
                                    updated_at = CURRENT_TIMESTAMP
                                WHERE id = ?
                            ");
                            $updateStmt->execute([
                                $workflowState['status'],
                                $workflowState['review_status'],
                                $workflowState['published_at'],
                                $article_id
                            ]);

                            $stmt = $db->prepare("
                                INSERT INTO article_reviews (article_id, admin_id, review_status, review_note, created_at)
                                VALUES (?, ?, ?, ?, CURRENT_TIMESTAMP)
                            ");
                            $stmt->execute([$article_id, $admin_id, $review_status, $review_note]);
                        }
                        
                        $db->commit();
                        $message = '批量审核结果已更新，共处理 ' . count($article_ids) . ' 篇文章';
                    } catch (Exception $e) {
                        $db->rollBack();
                        $error = '批量审核失败: ' . $e->getMessage();
                    }
                }
                break;
        }
    }
}

// 获取筛选参数
$review_status = $_GET['review_status'] ?? 'pending';
$task_id = intval($_GET['task_id'] ?? 0);
$search = trim($_GET['search'] ?? '');
$page = max(1, intval($_GET['page'] ?? 1));
$per_page = 20;

// 构建查询条件
$where_conditions = ['a.deleted_at IS NULL'];
$params = [];

if (!empty($review_status)) {
    $where_conditions[] = 'a.review_status = ?';
    $params[] = $review_status;
}

if ($task_id > 0) {
    $where_conditions[] = 'a.task_id = ?';
    $params[] = $task_id;
}

if (!empty($search)) {
    $where_conditions[] = '(a.title LIKE ? OR a.content LIKE ?)';
    $params[] = "%{$search}%";
    $params[] = "%{$search}%";
}

$where_clause = implode(' AND ', $where_conditions);

// 获取文章总数
$count_sql = "
    SELECT COUNT(*) as total
    FROM articles a
    WHERE {$where_clause}
";
$stmt = $db->prepare($count_sql);
$stmt->execute($params);
$total_articles = $stmt->fetch()['total'];
$total_pages = ceil($total_articles / $per_page);

// 获取文章列表
$offset = ($page - 1) * $per_page;
$sql = "
    SELECT a.*, 
           t.name as task_name,
           au.name as author_name,
           c.name as category_name,
           (SELECT review_note FROM article_reviews WHERE article_id = a.id ORDER BY created_at DESC LIMIT 1) as last_review_note
    FROM articles a
    LEFT JOIN tasks t ON a.task_id = t.id
    LEFT JOIN authors au ON a.author_id = au.id
    LEFT JOIN categories c ON a.category_id = c.id
    WHERE {$where_clause}
    ORDER BY a.created_at ASC
    LIMIT {$per_page} OFFSET {$offset}
";

$stmt = $db->prepare($sql);
$stmt->execute($params);
$articles = $stmt->fetchAll();

// 获取任务列表
$tasks = $db->query("SELECT id, name FROM tasks ORDER BY name")->fetchAll();

// 获取统计数据
$stats = [
    'pending' => $db->query("SELECT COUNT(*) as count FROM articles WHERE review_status = 'pending' AND deleted_at IS NULL")->fetch()['count'],
    'approved' => $db->query("SELECT COUNT(*) as count FROM articles WHERE review_status = 'approved' AND deleted_at IS NULL")->fetch()['count'],
    'rejected' => $db->query("SELECT COUNT(*) as count FROM articles WHERE review_status = 'rejected' AND deleted_at IS NULL")->fetch()['count'],
    'auto_approved' => $db->query("SELECT COUNT(*) as count FROM articles WHERE review_status = 'auto_approved' AND deleted_at IS NULL")->fetch()['count']
];
function review_status_meta(string $reviewStatus): array {
    return match ($reviewStatus) {
        'approved' => ['label' => __('articles.review.approved'), 'class' => 'bg-green-100 text-green-800'],
        'rejected' => ['label' => __('articles.review.rejected'), 'class' => 'bg-red-100 text-red-800'],
        'auto_approved' => ['label' => __('articles.review.auto_approved'), 'class' => 'bg-blue-100 text-blue-800'],
        default => ['label' => __('articles.review.pending'), 'class' => 'bg-yellow-100 text-yellow-800'],
    };
}

$page_title = __('articles.review.title');
$page_header = '
<div class="flex items-center justify-between">
    <div class="flex items-center space-x-4">
        <a href="articles.php" class="text-gray-400 hover:text-gray-600">
            <i data-lucide="arrow-left" class="w-5 h-5"></i>
        </a>
        <div>
            <h1 class="text-2xl font-bold text-gray-900">' . htmlspecialchars(__('articles.review.title'), ENT_QUOTES) . '</h1>
            <p class="mt-1 text-sm text-gray-600">' . htmlspecialchars(__('articles.review.subtitle'), ENT_QUOTES) . '</p>
        </div>
    </div>
    <a href="articles.php" class="inline-flex items-center px-3 py-1.5 border border-gray-300 text-sm font-medium rounded text-gray-700 bg-white hover:bg-gray-50">
        <i data-lucide="arrow-left" class="w-4 h-4 mr-1"></i>
        ' . htmlspecialchars(__('articles.review.back'), ENT_QUOTES) . '
    </a>
</div>
';

require_once __DIR__ . '/includes/header.php';
?>
<script>
const REVIEW_I18N = <?php echo json_encode([
    'selectArticles' => __('articles.review.alert_select_articles'),
    'selectResult' => __('articles.review.alert_select_result'),
    'approved' => __('articles.review.approved'),
    'rejected' => __('articles.review.rejected'),
    'confirmBatch' => __('articles.review.confirm_batch', ['action' => '__ACTION__', 'count' => '__COUNT__']),
    'promptNote' => __('articles.review.prompt_note'),
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>;
</script>

        <!-- 统计卡片 -->
        <div class="grid grid-cols-1 md:grid-cols-4 gap-6 mb-8">
            <div class="bg-white overflow-hidden shadow rounded-lg">
                <div class="p-5">
                    <div class="flex items-center">
                        <div class="flex-shrink-0">
                            <i data-lucide="clock" class="h-6 w-6 text-yellow-600"></i>
                        </div>
                        <div class="ml-5 w-0 flex-1">
                            <dl>
                                <dt class="text-sm font-medium text-gray-500 truncate"><?php echo htmlspecialchars(__('articles.review.stats_pending')); ?></dt>
                                <dd class="text-lg font-medium text-gray-900"><?php echo $stats['pending']; ?></dd>
                            </dl>
                        </div>
                    </div>
                </div>
            </div>

            <div class="bg-white overflow-hidden shadow rounded-lg">
                <div class="p-5">
                    <div class="flex items-center">
                        <div class="flex-shrink-0">
                            <i data-lucide="check-circle" class="h-6 w-6 text-green-600"></i>
                        </div>
                        <div class="ml-5 w-0 flex-1">
                            <dl>
                                <dt class="text-sm font-medium text-gray-500 truncate"><?php echo htmlspecialchars(__('articles.review.stats_approved')); ?></dt>
                                <dd class="text-lg font-medium text-gray-900"><?php echo $stats['approved']; ?></dd>
                            </dl>
                        </div>
                    </div>
                </div>
            </div>

            <div class="bg-white overflow-hidden shadow rounded-lg">
                <div class="p-5">
                    <div class="flex items-center">
                        <div class="flex-shrink-0">
                            <i data-lucide="x-circle" class="h-6 w-6 text-red-600"></i>
                        </div>
                        <div class="ml-5 w-0 flex-1">
                            <dl>
                                <dt class="text-sm font-medium text-gray-500 truncate"><?php echo htmlspecialchars(__('articles.review.stats_rejected')); ?></dt>
                                <dd class="text-lg font-medium text-gray-900"><?php echo $stats['rejected']; ?></dd>
                            </dl>
                        </div>
                    </div>
                </div>
            </div>

            <div class="bg-white overflow-hidden shadow rounded-lg">
                <div class="p-5">
                    <div class="flex items-center">
                        <div class="flex-shrink-0">
                            <i data-lucide="zap" class="h-6 w-6 text-blue-600"></i>
                        </div>
                        <div class="ml-5 w-0 flex-1">
                            <dl>
                                <dt class="text-sm font-medium text-gray-500 truncate"><?php echo htmlspecialchars(__('articles.review.stats_auto')); ?></dt>
                                <dd class="text-lg font-medium text-gray-900"><?php echo $stats['auto_approved']; ?></dd>
                            </dl>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- 筛选和搜索 -->
        <div class="bg-white shadow rounded-lg mb-6">
            <div class="px-6 py-4 border-b border-gray-200">
                <h3 class="text-lg font-medium text-gray-900"><?php echo htmlspecialchars(__('articles.review.filter_title')); ?></h3>
            </div>
            <div class="px-6 py-4">
                <form method="GET" class="space-y-4">
                    <div class="grid grid-cols-1 md:grid-cols-4 gap-4">
                        <div>
                            <label class="block text-sm font-medium text-gray-700"><?php echo htmlspecialchars(__('articles.review.filter_status')); ?></label>
                            <select name="review_status" class="mt-1 block w-full border-gray-300 rounded-md shadow-sm focus:ring-blue-500 focus:border-blue-500 sm:text-sm">
                                <option value="pending" <?php echo $review_status === 'pending' ? 'selected' : ''; ?>><?php echo htmlspecialchars(__('articles.review.pending')); ?></option>
                                <option value="approved" <?php echo $review_status === 'approved' ? 'selected' : ''; ?>><?php echo htmlspecialchars(__('articles.review.approved')); ?></option>
                                <option value="rejected" <?php echo $review_status === 'rejected' ? 'selected' : ''; ?>><?php echo htmlspecialchars(__('articles.review.rejected')); ?></option>
                                <option value="auto_approved" <?php echo $review_status === 'auto_approved' ? 'selected' : ''; ?>><?php echo htmlspecialchars(__('articles.review.auto_approved')); ?></option>
                            </select>
                        </div>

                        <div>
                            <label class="block text-sm font-medium text-gray-700"><?php echo htmlspecialchars(__('articles.review.filter_task')); ?></label>
                            <select name="task_id" class="mt-1 block w-full border-gray-300 rounded-md shadow-sm focus:ring-blue-500 focus:border-blue-500 sm:text-sm">
                                <option value=""><?php echo htmlspecialchars(__('articles.filters.all_tasks')); ?></option>
                                <?php foreach ($tasks as $task): ?>
                                    <option value="<?php echo $task['id']; ?>" <?php echo $task_id == $task['id'] ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($task['name']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div>
                            <label class="block text-sm font-medium text-gray-700"><?php echo htmlspecialchars(__('articles.review.filter_search')); ?></label>
                            <input type="text" name="search" value="<?php echo htmlspecialchars($search); ?>"
                                   placeholder="<?php echo htmlspecialchars(__('articles.filters.search_placeholder')); ?>"
                                   class="mt-1 block w-full border-gray-300 rounded-md shadow-sm focus:ring-blue-500 focus:border-blue-500 sm:text-sm">
                        </div>

                        <div class="flex items-end">
                            <button type="submit" class="w-full inline-flex items-center justify-center px-4 py-2 border border-transparent text-sm font-medium rounded-md text-white bg-blue-600 hover:bg-blue-700">
                                <i data-lucide="search" class="w-4 h-4 mr-2"></i>
                                <?php echo htmlspecialchars(__('button.search')); ?>
                            </button>
                        </div>
                    </div>
                </form>
            </div>
        </div>

        <!-- 文章列表 -->
        <div class="bg-white shadow rounded-lg">
            <div class="px-6 py-4 border-b border-gray-200">
                <div class="flex items-center justify-between">
                    <h3 class="text-lg font-medium text-gray-900">
                        <?php echo htmlspecialchars(__('articles.review.list_title')); ?> 
                        <span class="text-sm text-gray-500"><?php echo htmlspecialchars(__('articles.list_total', ['count' => (string) $total_articles])); ?></span>
                    </h3>
                    <?php if ($review_status === 'pending' && !empty($articles)): ?>
                        <button onclick="toggleBatchReview()" class="inline-flex items-center px-3 py-1.5 border border-gray-300 text-xs font-medium rounded text-gray-700 bg-white hover:bg-gray-50">
                            <i data-lucide="check-square" class="w-4 h-4 mr-1"></i>
                            <?php echo htmlspecialchars(__('articles.review.bulk')); ?>
                        </button>
                    <?php endif; ?>
                </div>
            </div>

            <?php if (empty($articles)): ?>
                <div class="px-6 py-8 text-center">
                    <i data-lucide="inbox" class="w-12 h-12 mx-auto text-gray-400 mb-4"></i>
                    <h3 class="text-lg font-medium text-gray-900 mb-2"><?php echo htmlspecialchars(__('articles.review.empty_title')); ?></h3>
                    <p class="text-gray-500"><?php echo htmlspecialchars(__('articles.review.empty_desc')); ?></p>
                </div>
            <?php else: ?>
                <!-- 批量审核栏 -->
                <?php if ($review_status === 'pending'): ?>
                    <div id="batch-review" class="hidden px-6 py-3 bg-gray-50 border-b border-gray-200">
                        <form method="POST" id="batch-review-form">
                            <input type="hidden" name="csrf_token" value="<?php echo generate_csrf_token(); ?>">
                            <input type="hidden" name="action" value="batch_review">
                            <div class="space-y-3">
                                <div class="flex items-center space-x-4">
                                    <span class="text-sm text-gray-600">
                                        <?php if (__('articles.review.selected_prefix') !== ''): ?><span><?php echo htmlspecialchars(__('articles.review.selected_prefix')); ?></span><?php endif; ?>
                                        <span id="selected-count">0</span>
                                        <span><?php echo htmlspecialchars(__('articles.review.selected_suffix')); ?></span>
                                    </span>
                                    
                                    <select name="review_status" required class="border-gray-300 rounded-md shadow-sm focus:ring-blue-500 focus:border-blue-500 text-sm">
                                        <option value=""><?php echo htmlspecialchars(__('articles.review.select_result')); ?></option>
                                        <option value="approved"><?php echo htmlspecialchars(__('articles.review.approved')); ?></option>
                                        <option value="rejected"><?php echo htmlspecialchars(__('articles.review.rejected')); ?></option>
                                    </select>
                                    
                                    <button type="submit" class="inline-flex items-center px-3 py-1.5 border border-transparent text-xs font-medium rounded text-white bg-blue-600 hover:bg-blue-700">
                                        <?php echo htmlspecialchars(__('articles.review.apply')); ?>
                                    </button>
                                    
                                    <button type="button" onclick="toggleBatchReview()" class="inline-flex items-center px-3 py-1.5 border border-gray-300 text-xs font-medium rounded text-gray-700 bg-white hover:bg-gray-50">
                                        <?php echo htmlspecialchars(__('button.cancel')); ?>
                                    </button>
                                </div>
                                <div>
                                    <textarea name="review_note" rows="2" placeholder="<?php echo htmlspecialchars(__('articles.review.note_placeholder')); ?>"
                                              class="block w-full border-gray-300 rounded-md shadow-sm focus:ring-blue-500 focus:border-blue-500 sm:text-sm"></textarea>
                                </div>
                            </div>
                        </form>
                    </div>
                <?php endif; ?>

                <div class="divide-y divide-gray-200">
                    <?php foreach ($articles as $article): ?>
                        <div class="px-6 py-6">
                            <div class="flex items-start space-x-4">
                                <?php if ($review_status === 'pending'): ?>
                                    <div class="batch-checkbox hidden mt-1">
                                        <input type="checkbox" name="article_ids[]" value="<?php echo $article['id']; ?>" class="article-checkbox rounded border-gray-300 text-blue-600 shadow-sm focus:border-blue-300 focus:ring focus:ring-blue-200 focus:ring-opacity-50">
                                    </div>
                                <?php endif; ?>
                                
                                <div class="flex-1 min-w-0">
                                    <div class="flex items-start justify-between">
                                        <div class="flex-1">
                                            <h4 class="text-lg font-medium text-gray-900">
                                                <a href="article-view.php?id=<?php echo $article['id']; ?>" class="hover:text-blue-600">
                                                    <?php echo htmlspecialchars($article['title']); ?>
                                                </a>
                                            </h4>
                                            <div class="mt-1 flex items-center space-x-4 text-sm text-gray-500">
                                                <span><?php echo htmlspecialchars(__('articles.review.task_prefix')); ?>: <?php echo htmlspecialchars($article['task_name']); ?></span>
                                                <span><?php echo htmlspecialchars(__('articles.review.author_prefix')); ?>: <?php echo htmlspecialchars($article['author_name']); ?></span>
                                                <span><?php echo htmlspecialchars(__('articles.review.created_prefix')); ?>: <?php echo date('Y-m-d H:i', strtotime($article['created_at'])); ?></span>
                                            </div>
                                            <?php if ($article['excerpt']): ?>
                                                <p class="mt-2 text-sm text-gray-600 line-clamp-2">
                                                    <?php echo htmlspecialchars($article['excerpt']); ?>
                                                </p>
                                            <?php endif; ?>
                                            <?php if ($article['last_review_note']): ?>
                                                <div class="mt-2 p-2 bg-yellow-50 border border-yellow-200 rounded">
                                                    <p class="text-xs text-yellow-800">
                                                        <strong><?php echo htmlspecialchars(__('articles.review.last_note')); ?>:</strong> <?php echo htmlspecialchars($article['last_review_note']); ?>
                                                    </p>
                                                </div>
                                            <?php endif; ?>
                                        </div>
                                        
                                        <div class="flex items-center space-x-2">
                                            <?php $reviewMeta = review_status_meta((string) ($article['review_status'] ?? 'pending')); ?>
                                            <span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium <?php echo $reviewMeta['class']; ?>">
                                                <?php echo $reviewMeta['label']; ?>
                                            </span>
                                        </div>
                                    </div>
                                    
                                    <?php if ($review_status === 'pending'): ?>
                                        <div class="mt-4 flex items-center space-x-2">
                                            <button onclick="quickReview(<?php echo $article['id']; ?>, 'approved')" class="inline-flex items-center px-3 py-1.5 border border-transparent text-xs font-medium rounded text-white bg-green-600 hover:bg-green-700">
                                                <i data-lucide="check" class="w-4 h-4 mr-1"></i>
                                                <?php echo htmlspecialchars(__('articles.review.approved')); ?>
                                            </button>
                                            <button onclick="quickReview(<?php echo $article['id']; ?>, 'rejected')" class="inline-flex items-center px-3 py-1.5 border border-transparent text-xs font-medium rounded text-white bg-red-600 hover:bg-red-700">
                                                <i data-lucide="x" class="w-4 h-4 mr-1"></i>
                                                <?php echo htmlspecialchars(__('articles.review.rejected')); ?>
                                            </button>
                                            <a href="article-view.php?id=<?php echo $article['id']; ?>" class="inline-flex items-center px-3 py-1.5 border border-gray-300 text-xs font-medium rounded text-gray-700 bg-white hover:bg-gray-50">
                                                <i data-lucide="eye" class="w-4 h-4 mr-1"></i>
                                                <?php echo htmlspecialchars(__('articles.review.view_detail')); ?>
                                            </a>
                                        </div>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>

                <!-- 分页 -->
                <?php if ($total_pages > 1): ?>
                    <div class="px-6 py-4 border-t border-gray-200">
                        <div class="flex items-center justify-between">
                            <div class="text-sm text-gray-700">
                                <?php echo htmlspecialchars(__('articles.pagination.summary', ['from' => (string) (($page - 1) * $per_page + 1), 'to' => (string) min($page * $per_page, $total_articles), 'total' => (string) $total_articles])); ?>
                            </div>
                            <div class="flex space-x-1">
                                <?php if ($page > 1): ?>
                                    <a href="?<?php echo http_build_query(array_merge($_GET, ['page' => $page - 1])); ?>" class="px-3 py-2 text-sm font-medium text-gray-500 bg-white border border-gray-300 rounded-md hover:bg-gray-50">
                                        <?php echo htmlspecialchars(__('articles.pagination.prev')); ?>
                                    </a>
                                <?php endif; ?>
                                
                                <?php for ($i = max(1, $page - 2); $i <= min($total_pages, $page + 2); $i++): ?>
                                    <a href="?<?php echo http_build_query(array_merge($_GET, ['page' => $i])); ?>" 
                                       class="px-3 py-2 text-sm font-medium <?php echo $i === $page ? 'text-blue-600 bg-blue-50 border-blue-500' : 'text-gray-500 bg-white border-gray-300'; ?> border rounded-md hover:bg-gray-50">
                                        <?php echo $i; ?>
                                    </a>
                                <?php endfor; ?>
                                
                                <?php if ($page < $total_pages): ?>
                                    <a href="?<?php echo http_build_query(array_merge($_GET, ['page' => $page + 1])); ?>" class="px-3 py-2 text-sm font-medium text-gray-500 bg-white border border-gray-300 rounded-md hover:bg-gray-50">
                                        <?php echo htmlspecialchars(__('articles.pagination.next')); ?>
                                    </a>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                <?php endif; ?>
            <?php endif; ?>
        </div>
    </div>

    <script>
        // 初始化Lucide图标
        document.addEventListener('DOMContentLoaded', function() {
            if (typeof lucide !== 'undefined') {
                lucide.createIcons();
            }
        });

        // 批量审核功能
        function toggleBatchReview() {
            const batchReview = document.getElementById('batch-review');
            const checkboxes = document.querySelectorAll('.batch-checkbox');
            const isHidden = batchReview.classList.contains('hidden');
            
            if (isHidden) {
                batchReview.classList.remove('hidden');
                checkboxes.forEach(cb => cb.classList.remove('hidden'));
            } else {
                batchReview.classList.add('hidden');
                checkboxes.forEach(cb => cb.classList.add('hidden'));
                // 清除所有选择
                document.querySelectorAll('.article-checkbox').forEach(cb => cb.checked = false);
                updateSelectedCount();
            }
        }

        // 更新选中数量
        function updateSelectedCount() {
            const selected = document.querySelectorAll('.article-checkbox:checked').length;
            document.getElementById('selected-count').textContent = selected;
        }

        // 监听复选框变化
        document.querySelectorAll('.article-checkbox').forEach(cb => {
            cb.addEventListener('change', updateSelectedCount);
        });

        // 批量审核表单提交
        document.getElementById('batch-review-form').addEventListener('submit', function(e) {
            const selected = document.querySelectorAll('.article-checkbox:checked').length;
            if (selected === 0) {
                e.preventDefault();
                alert(REVIEW_I18N.selectArticles);
                return;
            }
            
            const reviewStatus = this.querySelector('select[name="review_status"]').value;
            if (!reviewStatus) {
                e.preventDefault();
                alert(REVIEW_I18N.selectResult);
                return;
            }
            
            const actionText = reviewStatus === 'approved' ? REVIEW_I18N.approved : REVIEW_I18N.rejected;
            if (!confirm(REVIEW_I18N.confirmBatch.replace('__ACTION__', actionText).replace('__COUNT__', selected))) {
                e.preventDefault();
                return;
            }
            
            // 添加选中的文章ID
            const selected_checkboxes = document.querySelectorAll('.article-checkbox:checked');
            selected_checkboxes.forEach(cb => {
                const input = document.createElement('input');
                input.type = 'hidden';
                input.name = 'article_ids[]';
                input.value = cb.value;
                this.appendChild(input);
            });
        });

        // 快速审核
        function quickReview(articleId, status) {
            const note = prompt(REVIEW_I18N.promptNote);
            if (note !== null) { // 用户没有取消
                const form = document.createElement('form');
                form.method = 'POST';
                form.innerHTML = `
                    <input type="hidden" name="csrf_token" value="<?php echo generate_csrf_token(); ?>">
                    <input type="hidden" name="action" value="review_article">
                    <input type="hidden" name="article_id" value="${articleId}">
                    <input type="hidden" name="review_status" value="${status}">
                    <input type="hidden" name="review_note" value="${note}">
                `;
                document.body.appendChild(form);
                form.submit();
            }
        }
    </script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
