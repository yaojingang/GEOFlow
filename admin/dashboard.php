<?php
/**
 * 智能GEO内容系统 - 管理后台首页
 */

define('FEISHU_TREASURE', true);
session_start();

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/database_admin.php';
require_once __DIR__ . '/../includes/update_check.php';

// 检查管理员登录
require_admin_login();

$flash_message = $_SESSION['admin_message_success'] ?? '';
$flash_error = $_SESSION['admin_message_error'] ?? '';
unset($_SESSION['admin_message_success'], $_SESSION['admin_message_error']);

// 立即释放session锁，允许其他页面并发访问
session_write_close();

// 设置页面标题
$page_title = __('dashboard.page_title');
$update_state = geoflow_get_update_state(false);
$update_copy = geoflow_get_update_copy($update_state);

// 获取全面的统计数据
try {
    $jobStatusCounts = $db->query("
        SELECT status, COUNT(*) as count
        FROM job_queue
        GROUP BY status
    ")->fetchAll(PDO::FETCH_KEY_PAIR);

    $completedJobs = (int) ($jobStatusCounts['completed'] ?? 0);
    $failedJobs = (int) ($jobStatusCounts['failed'] ?? 0);
    $runningJobs = (int) ($jobStatusCounts['running'] ?? 0);
    $pendingJobs = (int) ($jobStatusCounts['pending'] ?? 0);
    $totalFinishedJobs = $completedJobs + $failedJobs;

    // 基础文章统计
    $stats = [
        'total_articles' => $db->query("SELECT COUNT(*) as count FROM articles WHERE deleted_at IS NULL")->fetch()['count'] ?? 0,
        'published_articles' => $db->query("SELECT COUNT(*) as count FROM articles WHERE status = 'published' AND deleted_at IS NULL")->fetch()['count'] ?? 0,
        'draft_articles' => $db->query("SELECT COUNT(*) as count FROM articles WHERE status = 'draft' AND deleted_at IS NULL")->fetch()['count'] ?? 0,
        'ai_generated_articles' => $db->query("SELECT COUNT(*) as count FROM articles WHERE is_ai_generated = 1 AND deleted_at IS NULL")->fetch()['count'] ?? 0,

        // 任务统计
        'total_tasks' => $db->query("SELECT COUNT(*) as count FROM tasks")->fetch()['count'] ?? 0,
        'active_tasks' => $db->query("SELECT COUNT(*) as count FROM tasks WHERE status = 'active'")->fetch()['count'] ?? 0,
        'completed_tasks' => $completedJobs,
        'running_jobs' => $runningJobs,
        'pending_jobs' => $pendingJobs,
        'failed_jobs' => $failedJobs,

        // 素材库统计
        'total_keywords' => $db->query("SELECT COUNT(*) as count FROM keywords")->fetch()['count'] ?? 0,
        'total_titles' => $db->query("SELECT COUNT(*) as count FROM titles")->fetch()['count'] ?? 0,
        'total_images' => $db->query("SELECT COUNT(*) as count FROM images")->fetch()['count'] ?? 0,
        'total_categories' => $db->query("SELECT COUNT(*) as count FROM categories")->fetch()['count'] ?? 0,

        // AI模型统计
        'active_ai_models' => $db->query("SELECT COUNT(*) as count FROM ai_models WHERE status = 'active'")->fetch()['count'] ?? 0,
        'total_prompts' => $db->query("SELECT COUNT(*) as count FROM prompts")->fetch()['count'] ?? 0,

        // 内容质量统计
        'pending_review' => $db->query("SELECT COUNT(*) as count FROM articles WHERE review_status = 'pending' AND deleted_at IS NULL")->fetch()['count'] ?? 0,
        'approved_articles' => $db->query("SELECT COUNT(*) as count FROM articles WHERE review_status = 'approved' AND deleted_at IS NULL")->fetch()['count'] ?? 0,

        // 访问统计
        'total_views' => $db->query("SELECT SUM(view_count) as total FROM articles WHERE deleted_at IS NULL")->fetch()['total'] ?? 0,
        'total_likes' => $db->query("SELECT SUM(like_count) as total FROM articles WHERE deleted_at IS NULL")->fetch()['total'] ?? 0,
    ];

    // 今日统计
    $today_stats = [
        'today_articles' => $db->query("SELECT COUNT(*) as count FROM articles WHERE DATE(created_at) = CURRENT_DATE AND deleted_at IS NULL")->fetch()['count'] ?? 0,
        'today_tasks' => $db->query("SELECT COUNT(*) as count FROM tasks WHERE DATE(created_at) = CURRENT_DATE")->fetch()['count'] ?? 0,
        'today_views' => $db->query("SELECT COUNT(*) as count FROM view_logs WHERE DATE(created_at) = CURRENT_DATE")->fetch()['count'] ?? 0,
    ];

    // 本周统计
    $week_stats = [
        'week_articles' => $db->query("SELECT COUNT(*) as count FROM articles WHERE created_at >= " . db_now_minus_seconds_sql(7 * 24 * 60 * 60) . " AND deleted_at IS NULL")->fetch()['count'] ?? 0,
        'week_tasks' => $db->query("SELECT COUNT(*) as count FROM tasks WHERE created_at >= " . db_now_minus_seconds_sql(7 * 24 * 60 * 60))->fetch()['count'] ?? 0,
    ];

} catch (Exception $e) {
    // 默认值
    $stats = array_fill_keys([
        'total_articles', 'published_articles', 'draft_articles', 'ai_generated_articles',
        'total_tasks', 'active_tasks', 'completed_tasks', 'running_jobs', 'pending_jobs', 'failed_jobs',
        'total_keywords', 'total_titles', 'total_images', 'total_categories',
        'active_ai_models', 'total_prompts', 'pending_review', 'approved_articles',
        'total_views', 'total_likes'
    ], 0);

    $today_stats = ['today_articles' => 0, 'today_tasks' => 0, 'today_views' => 0];
    $week_stats = ['week_articles' => 0, 'week_tasks' => 0];
}

// 获取最新文章
try {
    $latest_articles = $db->query("
        SELECT a.*, c.name as category_name
        FROM articles a
        LEFT JOIN categories c ON a.category_id = c.id
        WHERE a.deleted_at IS NULL
        ORDER BY a.created_at DESC
        LIMIT 5
    ")->fetchAll();
} catch (Exception $e) {
    $latest_articles = [];
}

// 获取最近7天的文章发布趋势（确保返回完整7天数据）
try {
    // 先生成最近7天的日期列表
    $article_trend = [];
    for ($i = 6; $i >= 0; $i--) {
        $date = date('Y-m-d', strtotime("-$i days"));
        $article_trend[$date] = [
            'date' => $date,
            'count' => 0
        ];
    }

    // 查询实际的文章数据
    $actual_data = $db->query("
        SELECT DATE(created_at) as date, COUNT(*) as count
        FROM articles
        WHERE created_at >= " . db_now_minus_seconds_sql(7 * 24 * 60 * 60) . "
          AND deleted_at IS NULL
        GROUP BY DATE(created_at)
    ")->fetchAll();

    // 合并实际数据到日期列表
    foreach ($actual_data as $row) {
        if (isset($article_trend[$row['date']])) {
            $article_trend[$row['date']]['count'] = $row['count'];
        }
    }

    // 转换为索引数组
    $article_trend = array_values($article_trend);
} catch (Exception $e) {
    $article_trend = [];
}

// 获取分类文章分布
try {
    $category_distribution = $db->query("
        SELECT c.name, COUNT(a.id) as count
        FROM categories c
        LEFT JOIN articles a ON c.id = a.category_id AND a.deleted_at IS NULL
        GROUP BY c.id, c.name
        ORDER BY count DESC
        LIMIT 10
    ")->fetchAll();
} catch (Exception $e) {
    $category_distribution = [];
}

// 获取最活跃的任务
try {
    $active_tasks = $db->query("
        SELECT
            t.name,
            t.status,
            t.created_at,
            COALESCE((
                SELECT jq.status
                FROM job_queue jq
                WHERE jq.task_id = t.id
                ORDER BY jq.updated_at DESC, jq.id DESC
                LIMIT 1
            ), 'idle') as queue_status
        FROM tasks t
        WHERE t.status = 'active'
           OR EXISTS (
                SELECT 1
                FROM job_queue jq
                WHERE jq.task_id = t.id
                  AND jq.status IN ('pending', 'running')
           )
        ORDER BY t.updated_at DESC
        LIMIT 5
    ")->fetchAll();
} catch (Exception $e) {
    $active_tasks = [];
}

// 获取系统性能指标
try {
    $performance_stats = [
        'avg_generation_time' => $db->query("SELECT AVG(duration_ms) / 1000.0 as avg_time FROM task_runs WHERE duration_ms > 0")->fetch()['avg_time'] ?? 0,
        'success_rate' => $totalFinishedJobs > 0 ? round(($completedJobs * 100.0) / $totalFinishedJobs, 2) : 0,
        'daily_quota_used' => $db->query("SELECT COUNT(*) as count FROM articles WHERE DATE(created_at) = CURRENT_DATE AND is_ai_generated = 1")->fetch()['count'] ?? 0,
    ];
} catch (Exception $e) {
    $performance_stats = ['avg_generation_time' => 0, 'success_rate' => 0, 'daily_quota_used' => 0];
}

// 包含统一头部
require_once __DIR__ . '/includes/header.php';
?>
            <!-- 页面标题 -->
            <?php if ($update_state['is_update_available'] && !$update_state['is_ignored']): ?>
                <div class="mb-6 rounded-2xl border border-blue-200 bg-blue-50 px-5 py-4">
                    <div class="flex flex-col gap-4 lg:flex-row lg:items-start lg:justify-between">
                        <div class="min-w-0">
                            <div class="inline-flex items-center rounded-full bg-blue-100 px-2.5 py-1 text-xs font-semibold text-blue-700">
                                <?php echo __('update.new_version_available_badge'); ?>
                            </div>
                            <div class="mt-3 text-lg font-semibold text-gray-900">
                                <?php echo __('update.version_pair', ['current' => APP_VERSION, 'latest' => $update_state['latest_version']]); ?>
                            </div>
                            <p class="mt-1 text-sm text-gray-700">
                                <?php echo htmlspecialchars($update_copy['title'] !== '' ? $update_copy['title'] : __('update.new_version_available')); ?>
                            </p>
                            <p class="mt-2 text-sm text-gray-600">
                                <?php echo htmlspecialchars($update_copy['summary'] !== '' ? $update_copy['summary'] : __('update.summary_empty')); ?>
                            </p>
                            <?php if ($update_copy['upgrade_tip'] !== ''): ?>
                                <p class="mt-3 text-xs text-blue-800"><?php echo htmlspecialchars($update_copy['upgrade_tip']); ?></p>
                            <?php endif; ?>
                        </div>
                        <div class="flex shrink-0 flex-wrap items-center gap-3">
                            <?php if ($update_copy['changelog_url'] !== ''): ?>
                                <a href="<?php echo htmlspecialchars($update_copy['changelog_url']); ?>" target="_blank" rel="noopener noreferrer" class="inline-flex items-center rounded-lg border border-blue-200 bg-white px-4 py-2 text-sm font-medium text-blue-700 hover:bg-blue-100">
                                    <i data-lucide="book-open" class="mr-2 h-4 w-4"></i>
                                    <?php echo __('update.view_changelog'); ?>
                                </a>
                            <?php endif; ?>
                            <form method="POST" action="<?php echo htmlspecialchars(admin_url('update-ignore.php')); ?>">
                                <input type="hidden" name="csrf_token" value="<?php echo generate_csrf_token(); ?>">
                                <input type="hidden" name="redirect_target" value="dashboard">
                                <input type="hidden" name="version" value="<?php echo htmlspecialchars($update_state['latest_version']); ?>">
                                <button type="submit" class="inline-flex items-center rounded-lg border border-gray-200 bg-white px-4 py-2 text-sm font-medium text-gray-700 hover:bg-gray-100">
                                    <i data-lucide="bell-off" class="mr-2 h-4 w-4"></i>
                                    <?php echo __('update.ignore_version'); ?>
                                </button>
                            </form>
                        </div>
                    </div>
                </div>
            <?php endif; ?>

            <div class="mb-8">
                <div class="flex items-center justify-between">
                    <div>
                        <h1 class="text-3xl font-bold text-gray-900"><?php echo __('dashboard.heading'); ?></h1>
                        <p class="mt-1 text-sm text-gray-600"><?php echo __('dashboard.subtitle', ['site' => htmlspecialchars($admin_site_name)]); ?></p>
                    </div>
                    <div class="flex items-center space-x-3">
                        <span class="text-sm text-gray-500"><?php echo __('dashboard.last_updated', ['time' => date('Y-m-d H:i:s')]); ?></span>
                        <button onclick="location.reload()" class="inline-flex items-center px-3 py-2 border border-gray-300 shadow-sm text-sm leading-4 font-medium rounded-md text-gray-700 bg-white hover:bg-gray-50">
                            <i data-lucide="refresh-cw" class="w-4 h-4 mr-1"></i>
                            <?php echo __('dashboard.refresh'); ?>
                        </button>
                    </div>
                </div>
            </div>

            <?php if (!empty($flash_message)): ?>
                <div class="mb-6 rounded-lg border border-green-200 bg-green-50 p-4">
                    <div class="flex items-center text-sm text-green-700">
                        <i data-lucide="check-circle" class="mr-2 h-5 w-5 text-green-500"></i>
                        <span><?php echo htmlspecialchars($flash_message); ?></span>
                    </div>
                </div>
            <?php endif; ?>

            <?php if (!empty($flash_error)): ?>
                <div class="mb-6 rounded-lg border border-red-200 bg-red-50 p-4">
                    <div class="flex items-center text-sm text-red-700">
                        <i data-lucide="alert-circle" class="mr-2 h-5 w-5 text-red-500"></i>
                        <span><?php echo htmlspecialchars($flash_error); ?></span>
                    </div>
                </div>
            <?php endif; ?>

            <!-- 核心指标卡片 -->
            <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-6 mb-8">
                <!-- 总文章数 -->
                <div class="bg-white overflow-hidden shadow-lg rounded-lg">
                    <div class="p-5">
                        <div class="flex items-center">
                            <div class="flex-shrink-0">
                                <i data-lucide="file-text" class="h-8 w-8 text-blue-600"></i>
                            </div>
                            <div class="ml-5 w-0 flex-1">
                                <dl>
                                    <dt class="text-sm font-medium text-gray-500 truncate"><?php echo __('dashboard.total_articles'); ?></dt>
                                    <dd class="text-2xl font-bold text-gray-900"><?php echo number_format($stats['total_articles']); ?></dd>
                                    <dd class="text-xs text-gray-500"><?php echo __('dashboard.today_added', ['count' => $today_stats['today_articles']]); ?></dd>
                                </dl>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- 已发布文章 -->
                <div class="bg-white overflow-hidden shadow-lg rounded-lg">
                    <div class="p-5">
                        <div class="flex items-center">
                            <div class="flex-shrink-0">
                                <i data-lucide="globe" class="h-8 w-8 text-green-600"></i>
                            </div>
                            <div class="ml-5 w-0 flex-1">
                                <dl>
                                    <dt class="text-sm font-medium text-gray-500 truncate"><?php echo __('dashboard.published'); ?></dt>
                                    <dd class="text-2xl font-bold text-gray-900"><?php echo number_format($stats['published_articles']); ?></dd>
                                    <dd class="text-xs text-gray-500"><?php echo __('dashboard.publish_rate', ['rate' => $stats['total_articles'] > 0 ? round(($stats['published_articles'] / $stats['total_articles']) * 100, 1) : 0]); ?></dd>
                                </dl>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- AI生成文章 -->
                <div class="bg-white overflow-hidden shadow-lg rounded-lg">
                    <div class="p-5">
                        <div class="flex items-center">
                            <div class="flex-shrink-0">
                                <i data-lucide="brain" class="h-8 w-8 text-purple-600"></i>
                            </div>
                            <div class="ml-5 w-0 flex-1">
                                <dl>
                                    <dt class="text-sm font-medium text-gray-500 truncate"><?php echo __('dashboard.ai_generated'); ?></dt>
                                    <dd class="text-2xl font-bold text-gray-900"><?php echo number_format($stats['ai_generated_articles']); ?></dd>
                                    <dd class="text-xs text-gray-500"><?php echo __('dashboard.ai_generated_ratio', ['rate' => $stats['total_articles'] > 0 ? round(($stats['ai_generated_articles'] / $stats['total_articles']) * 100, 1) : 0]); ?></dd>
                                </dl>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- 总浏览量 -->
                <div class="bg-white overflow-hidden shadow-lg rounded-lg">
                    <div class="p-5">
                        <div class="flex items-center">
                            <div class="flex-shrink-0">
                                <i data-lucide="eye" class="h-8 w-8 text-orange-600"></i>
                            </div>
                            <div class="ml-5 w-0 flex-1">
                                <dl>
                                    <dt class="text-sm font-medium text-gray-500 truncate"><?php echo __('dashboard.total_views'); ?></dt>
                                    <dd class="text-2xl font-bold text-gray-900"><?php echo number_format($stats['total_views']); ?></dd>
                                    <dd class="text-xs text-gray-500"><?php echo __('dashboard.today_views', ['count' => number_format($today_stats['today_views'])]); ?></dd>
                                </dl>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- 任务和AI统计 -->
            <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-6 mb-8">
                <!-- 活跃任务 -->
                <div class="bg-white overflow-hidden shadow rounded-lg">
                    <div class="p-5">
                        <div class="flex items-center">
                            <div class="flex-shrink-0">
                                <i data-lucide="zap" class="h-6 w-6 text-yellow-600"></i>
                            </div>
                            <div class="ml-5 w-0 flex-1">
                                <dl>
                                    <dt class="text-sm font-medium text-gray-500 truncate"><?php echo __('dashboard.active_tasks'); ?></dt>
                                    <dd class="text-lg font-medium text-gray-900"><?php echo $stats['running_jobs'] + $stats['pending_jobs']; ?> / <?php echo $stats['total_tasks']; ?></dd>
                                    <dd class="text-xs text-gray-500"><?php echo __('dashboard.active_tasks_detail', ['running' => $stats['running_jobs'], 'pending' => $stats['pending_jobs']]); ?></dd>
                                </dl>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- AI模型 -->
                <div class="bg-white overflow-hidden shadow rounded-lg">
                    <div class="p-5">
                        <div class="flex items-center">
                            <div class="flex-shrink-0">
                                <i data-lucide="cpu" class="h-6 w-6 text-indigo-600"></i>
                            </div>
                            <div class="ml-5 w-0 flex-1">
                                <dl>
                                    <dt class="text-sm font-medium text-gray-500 truncate"><?php echo __('dashboard.ai_models'); ?></dt>
                                    <dd class="text-lg font-medium text-gray-900"><?php echo $stats['active_ai_models']; ?></dd>
                                </dl>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- 素材库 -->
                <div class="bg-white overflow-hidden shadow rounded-lg">
                    <div class="p-5">
                        <div class="flex items-center">
                            <div class="flex-shrink-0">
                                <i data-lucide="database" class="h-6 w-6 text-teal-600"></i>
                            </div>
                            <div class="ml-5 w-0 flex-1">
                                <dl>
                                    <dt class="text-sm font-medium text-gray-500 truncate"><?php echo __('dashboard.material_total'); ?></dt>
                                    <dd class="text-lg font-medium text-gray-900"><?php echo number_format($stats['total_keywords'] + $stats['total_titles'] + $stats['total_images']); ?></dd>
                                </dl>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- 待审核 -->
                <div class="bg-white overflow-hidden shadow rounded-lg">
                    <div class="p-5">
                        <div class="flex items-center">
                            <div class="flex-shrink-0">
                                <i data-lucide="clock" class="h-6 w-6 text-red-600"></i>
                            </div>
                            <div class="ml-5 w-0 flex-1">
                                <dl>
                                    <dt class="text-sm font-medium text-gray-500 truncate"><?php echo __('dashboard.pending_review'); ?></dt>
                                    <dd class="text-lg font-medium text-gray-900"><?php echo $stats['pending_review']; ?></dd>
                                </dl>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- 数据图表和详细信息 -->
            <div class="grid grid-cols-1 lg:grid-cols-3 gap-6 mb-8">
                <!-- 分类文章分布 -->
                <div class="bg-white shadow rounded-lg">
                    <div class="px-6 py-4 border-b border-gray-200">
                        <div class="flex items-center justify-between">
                            <h3 class="text-lg font-medium text-gray-900"><?php echo __('dashboard.category_distribution'); ?></h3>
                            <a href="categories.php" class="text-sm text-blue-600 hover:text-blue-800">
                                <i data-lucide="settings" class="w-4 h-4 inline mr-1"></i>
                                <?php echo __('dashboard.manage_categories'); ?>
                            </a>
                        </div>
                    </div>
                    <div class="p-6">
                        <?php if (empty($category_distribution)): ?>
                            <p class="text-gray-500 text-center py-4"><?php echo __('dashboard.no_data'); ?></p>
                        <?php else: ?>
                            <div class="space-y-3">
                                <?php foreach ($category_distribution as $category): ?>
                                    <div class="flex items-center justify-between">
                                        <div class="flex-1">
                                            <div class="flex items-center justify-between mb-1">
                                                <span class="text-sm font-medium text-gray-900"><?php echo htmlspecialchars($category['name']); ?></span>
                                                <span class="text-sm text-gray-500"><?php echo $category['count']; ?></span>
                                            </div>
                                            <div class="w-full bg-gray-200 rounded-full h-2">
                                                <div class="bg-blue-600 h-2 rounded-full" style="width: <?php echo $stats['total_articles'] > 0 ? ($category['count'] / $stats['total_articles']) * 100 : 0; ?>%"></div>
                                            </div>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- 系统性能指标 -->
                <div class="bg-white shadow rounded-lg">
                    <div class="px-6 py-4 border-b border-gray-200">
                        <h3 class="text-lg font-medium text-gray-900"><?php echo __('dashboard.system_performance'); ?></h3>
                    </div>
                    <div class="p-6">
                        <div class="space-y-4">
                            <!-- 任务成功率 -->
                            <div>
                                <div class="flex items-center justify-between mb-2">
                                    <span class="text-sm font-medium text-gray-700"><?php echo __('dashboard.task_success_rate'); ?></span>
                                    <span class="text-sm text-gray-900"><?php echo number_format($performance_stats['success_rate'], 1); ?>%</span>
                                </div>
                                <div class="w-full bg-gray-200 rounded-full h-2">
                                    <div class="bg-green-600 h-2 rounded-full" style="width: <?php echo $performance_stats['success_rate']; ?>%"></div>
                                </div>
                            </div>

                            <!-- 平均生成时间 -->
                            <div>
                                <div class="flex items-center justify-between mb-2">
                                    <span class="text-sm font-medium text-gray-700"><?php echo __('dashboard.avg_generation_time'); ?></span>
                                    <span class="text-sm text-gray-900"><?php echo number_format($performance_stats['avg_generation_time'], 1); ?>s</span>
                                </div>
                                <div class="w-full bg-gray-200 rounded-full h-2">
                                    <div class="bg-yellow-600 h-2 rounded-full" style="width: <?php echo min(($performance_stats['avg_generation_time'] / 60) * 100, 100); ?>%"></div>
                                </div>
                            </div>

                            <!-- 今日AI配额使用 -->
                            <div>
                                <div class="flex items-center justify-between mb-2">
                                    <span class="text-sm font-medium text-gray-700"><?php echo __('dashboard.daily_ai_quota'); ?></span>
                                    <span class="text-sm text-gray-900"><?php echo $performance_stats['daily_quota_used']; ?> / 100</span>
                                </div>
                                <div class="w-full bg-gray-200 rounded-full h-2">
                                    <div class="bg-purple-600 h-2 rounded-full" style="width: <?php echo min(($performance_stats['daily_quota_used'] / 100) * 100, 100); ?>%"></div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- 最新文章 -->
                <div class="bg-white shadow rounded-lg">
                    <div class="px-6 py-4 border-b border-gray-200">
                        <div class="flex items-center justify-between">
                            <h3 class="text-lg font-medium text-gray-900"><?php echo __('dashboard.latest_articles'); ?></h3>
                            <a href="articles.php" class="text-sm text-blue-600 hover:text-blue-800"><?php echo __('dashboard.view_all'); ?></a>
                        </div>
                    </div>
                    <div class="p-6">
                        <?php if (empty($latest_articles)): ?>
                            <p class="text-gray-500 text-center py-4"><?php echo __('dashboard.no_articles'); ?></p>
                        <?php else: ?>
                            <div class="space-y-3">
                                <?php foreach ($latest_articles as $article): ?>
                                    <div class="flex items-start space-x-3">
                                        <div class="flex-shrink-0">
                                            <?php if ($article['is_ai_generated']): ?>
                                                <i data-lucide="brain" class="w-4 h-4 text-purple-500 mt-0.5"></i>
                                            <?php else: ?>
                                                <i data-lucide="edit" class="w-4 h-4 text-gray-400 mt-0.5"></i>
                                            <?php endif; ?>
                                        </div>
                                        <div class="flex-1 min-w-0">
                                            <p class="text-sm font-medium text-gray-900 truncate">
                                                <?php echo htmlspecialchars($article['title']); ?>
                                            </p>
                                            <p class="text-xs text-gray-500">
                                                <?php echo htmlspecialchars($article['category_name'] ?? __('dashboard.uncategorized')); ?> •
                                                <?php echo date('m-d H:i', strtotime($article['created_at'])); ?>
                                            </p>
                                        </div>
                                        <span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium <?php
                                            echo $article['status'] === 'published' ? 'bg-green-100 text-green-800' : 'bg-yellow-100 text-yellow-800';
                                        ?>">
                                            <?php echo $article['status'] === 'published' ? __('status.published') : __('status.draft'); ?>
                                        </span>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>



            <!-- 数据趋势图表 -->
            <div class="bg-white shadow rounded-lg">
                <div class="px-6 py-4 border-b border-gray-200">
                    <h3 class="text-lg font-medium text-gray-900"><?php echo __('dashboard.trend_title'); ?></h3>
                </div>
                <div class="p-6">
                    <div class="grid grid-cols-1 md:grid-cols-3 gap-6">
                        <!-- 本周统计 -->
                        <div class="text-center">
                            <div class="text-2xl font-bold text-blue-600"><?php echo $week_stats['week_articles']; ?></div>
                            <div class="text-sm text-gray-500"><?php echo __('dashboard.week_articles'); ?></div>
                        </div>
                        <div class="text-center">
                            <div class="text-2xl font-bold text-green-600"><?php echo $week_stats['week_tasks']; ?></div>
                            <div class="text-sm text-gray-500"><?php echo __('dashboard.week_tasks'); ?></div>
                        </div>
                        <div class="text-center">
                            <div class="text-2xl font-bold text-purple-600"><?php echo $stats['approved_articles']; ?></div>
                            <div class="text-sm text-gray-500"><?php echo __('dashboard.approved_articles'); ?></div>
                        </div>
                    </div>

                    <!-- 折线图 -->
                    <?php if (!empty($article_trend)): ?>
                    <div class="mt-6">
                        <h4 class="text-sm font-medium text-gray-700 mb-4"><?php echo __('dashboard.article_trend'); ?></h4>

                        <!-- 图表容器 -->
                        <div class="relative rounded-2xl border border-slate-200 bg-gradient-to-b from-slate-50 via-white to-white px-4 pt-5 pb-10 overflow-hidden" style="height: 236px;">
                            <?php
                            $max_count = max(array_column($article_trend, 'count'));
                            if ($max_count == 0) $max_count = 10;

                            // 计算Y轴最大值（向上取整到合适的刻度）
                            $y_max = ceil($max_count * 1.2);
                            if ($y_max < 5) $y_max = 5;

                            // 计算坐标
                            $chart_height = 148;
                            $chart_width = 600;
                            $point_count = count($article_trend);
                            $x_step = $point_count > 1 ? ($chart_width / ($point_count - 1)) : $chart_width;

                            $points = [];
                            foreach ($article_trend as $index => $day) {
                                $x = $index * $x_step;
                                $y = $chart_height - (($day['count'] / $y_max) * $chart_height);
                                $points[] = ['x' => $x, 'y' => $y, 'count' => $day['count'], 'date' => $day['date']];
                            }

                            // 生成平滑曲线路径
                            $line_path = '';
                            if (!empty($points)) {
                                $line_path = 'M' . $points[0]['x'] . ',' . $points[0]['y'];
                                $total_points = count($points);

                                for ($i = 0; $i < $total_points - 1; $i++) {
                                    $p0 = $points[max($i - 1, 0)];
                                    $p1 = $points[$i];
                                    $p2 = $points[$i + 1];
                                    $p3 = $points[min($i + 2, $total_points - 1)];

                                    $cp1x = $p1['x'] + (($p2['x'] - $p0['x']) / 6);
                                    $cp1y = $p1['y'] + (($p2['y'] - $p0['y']) / 6);
                                    $cp2x = $p2['x'] - (($p3['x'] - $p1['x']) / 6);
                                    $cp2y = $p2['y'] - (($p3['y'] - $p1['y']) / 6);

                                    $line_path .= " C{$cp1x},{$cp1y} {$cp2x},{$cp2y} {$p2['x']},{$p2['y']}";
                                }
                            }

                            $area_path = '';
                            if (!empty($points)) {
                                $first_point = $points[0];
                                $last_point = $points[count($points) - 1];
                                $area_path = $line_path
                                    . ' L' . $last_point['x'] . ',' . $chart_height
                                    . ' L' . $first_point['x'] . ',' . $chart_height
                                    . ' Z';
                            }

                            $peak_index = 0;
                            foreach ($points as $index => $point) {
                                if ($point['count'] === $max_count) {
                                    $peak_index = $index;
                                    break;
                                }
                            }

                            // 计算Y轴刻度（4-5个刻度）
                            $y_ticks = [];
                            for ($i = 0; $i <= 4; $i++) {
                                $y_ticks[] = round($y_max - ($y_max / 4) * $i);
                            }
                            ?>

                            <!-- Y轴标签 -->
                            <div class="absolute left-0 top-0 flex flex-col justify-between text-[11px] text-slate-400" style="height: <?php echo $chart_height; ?>px; width: 28px;">
                                <?php foreach ($y_ticks as $tick): ?>
                                <span class="text-right"><?php echo $tick; ?></span>
                                <?php endforeach; ?>
                            </div>

                            <!-- SVG 图表 -->
                            <svg class="absolute top-0" style="left: 36px; height: <?php echo $chart_height; ?>px; width: calc(100% - 48px);" viewBox="0 0 <?php echo $chart_width; ?> <?php echo $chart_height; ?>" preserveAspectRatio="none">
                                <defs>
                                    <linearGradient id="articleTrendFill" x1="0" y1="0" x2="0" y2="1">
                                        <stop offset="0%" stop-color="#3b82f6" stop-opacity="0.18"/>
                                        <stop offset="100%" stop-color="#3b82f6" stop-opacity="0.02"/>
                                    </linearGradient>
                                </defs>

                                <!-- 水平网格线 -->
                                <?php for ($i = 0; $i <= 4; $i++):
                                    $y_pos = ($chart_height / 4) * $i;
                                ?>
                                <line x1="0" y1="<?php echo $y_pos; ?>" x2="<?php echo $chart_width; ?>" y2="<?php echo $y_pos; ?>"
                                      stroke="<?php echo $i === 4 ? '#cbd5e1' : '#e2e8f0'; ?>"
                                      stroke-width="1"
                                      stroke-dasharray="<?php echo $i === 4 ? '0' : '4 6'; ?>"/>
                                <?php endfor; ?>

                                <?php if ($area_path !== ''): ?>
                                <path d="<?php echo $area_path; ?>" fill="url(#articleTrendFill)"/>
                                <?php endif; ?>

                                <!-- 轻量曲线阴影 -->
                                <path d="<?php echo $line_path; ?>"
                                      fill="none"
                                      stroke="rgba(59, 130, 246, 0.12)"
                                      stroke-width="6"
                                      stroke-linecap="round"
                                      stroke-linejoin="round"
                                      vector-effect="non-scaling-stroke"/>

                                <!-- 主趋势线 -->
                                <path d="<?php echo $line_path; ?>"
                                      fill="none"
                                      stroke="#3b82f6"
                                      stroke-width="2"
                                      stroke-linecap="round"
                                      stroke-linejoin="round"
                                      vector-effect="non-scaling-stroke"/>

                                <!-- 数据点 -->
                                <?php foreach ($points as $index => $point): ?>
                                <circle cx="<?php echo $point['x']; ?>"
                                        cy="<?php echo $point['y']; ?>"
                                        r="<?php echo $index === $peak_index ? '3.8' : '2.4'; ?>"
                                        fill="<?php echo $index === $peak_index ? '#3b82f6' : '#ffffff'; ?>"
                                        stroke="#3b82f6"
                                        stroke-width="<?php echo $index === $peak_index ? '1.8' : '1.4'; ?>"
                                        vector-effect="non-scaling-stroke"/>
                                <?php endforeach; ?>
                            </svg>

                            <!-- X轴标签 -->
                            <div class="absolute flex justify-between text-xs text-slate-500" style="left: 36px; bottom: 0; width: calc(100% - 48px); height: 40px;">
                                <?php foreach ($article_trend as $day):
                                    $date_obj = new DateTime($day['date']);
                                ?>
                                <div class="flex items-start justify-center pt-2">
                                    <span><?php echo $date_obj->format('m/d'); ?></span>
                                </div>
                                <?php endforeach; ?>
                            </div>
                        </div>

                        <!-- 统计信息 -->
                        <div class="mt-3 flex items-center justify-center space-x-8 text-xs text-gray-600">
                            <?php
                            $total_articles = array_sum(array_column($article_trend, 'count'));
                            $avg_articles = round($total_articles / count($article_trend), 1);
                            ?>
                            <span><?php echo __('dashboard.total_stat', ['count' => '<strong class="text-gray-900">' . $total_articles . '</strong>']); ?></span>
                            <span><?php echo __('dashboard.avg_stat', ['count' => '<strong class="text-gray-900">' . $avg_articles . '</strong>']); ?></span>
                            <span><?php echo __('dashboard.peak_stat', ['count' => '<strong class="text-gray-900">' . $max_count . '</strong>']); ?></span>
                        </div>
                    </div>

                    <?php else: ?>
                    <div class="mt-6 text-center text-gray-500 py-8">
                        <p class="text-sm"><?php echo __('dashboard.no_data'); ?></p>
                    </div>
                    <?php endif; ?>
                </div>
            </div>

<?php
// 包含统一底部
require_once __DIR__ . '/includes/footer.php';
?>
