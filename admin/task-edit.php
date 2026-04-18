<?php
/**
 * 智能GEO内容系统 - 编辑任务
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

$task_id = intval($_GET['id'] ?? 0);
$message = '';
$error = '';

if ($task_id <= 0) {
    header('Location: tasks.php');
    exit;
}

// 获取任务信息
$stmt = $db->prepare("SELECT * FROM tasks WHERE id = ?");
$stmt->execute([$task_id]);
$task = $stmt->fetch();

if (!$task) {
    header('Location: tasks.php');
    exit;
}

// 处理POST请求
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
        $error = __('message.csrf_failed');
    } else {
        try {
            $ai_model_id = (int) ($_POST['ai_model_id'] ?? 0);
            $model_selection_mode = trim((string) ($_POST['model_selection_mode'] ?? 'fixed'));
            $modelCheckStmt = $db->prepare("
                SELECT COUNT(*)
                FROM ai_models
                WHERE id = ?
                  AND status = 'active'
                  AND COALESCE(NULLIF(model_type, ''), 'chat') = 'chat'
            ");
            $modelCheckStmt->execute([$ai_model_id]);

            if ((int) $modelCheckStmt->fetchColumn() === 0) {
                throw new Exception(__('task_edit.error.invalid_chat_model'));
            }

            if (!in_array($model_selection_mode, ['fixed', 'smart_failover'], true)) {
                throw new Exception(__('task_edit.error.invalid_model_selection_mode'));
            }

            $stmt = $db->prepare("
                UPDATE tasks SET
                    name = ?, title_library_id = ?, image_library_id = ?, image_count = ?,
                    prompt_id = ?, ai_model_id = ?, need_review = ?, publish_interval = ?,
                    knowledge_base_id = ?, author_id = ?, auto_keywords = ?, auto_description = ?,
                    draft_limit = ?, is_loop = ?, model_selection_mode = ?, status = ?, updated_at = CURRENT_TIMESTAMP
                WHERE id = ?
            ");

            // 处理作者ID
            $author_id = null;
            if (isset($_POST['author_type'])) {
                if ($_POST['author_type'] === 'custom' && !empty($_POST['custom_author_id'])) {
                    $author_id = intval($_POST['custom_author_id']);
                } elseif ($_POST['author_type'] === 'fixed' && !empty($_POST['author_id'])) {
                    $author_id = intval($_POST['author_id']);
                }
            }

            $result = $stmt->execute([
                $_POST['name'],
                $_POST['title_library_id'],
                $_POST['image_library_id'] ?: null,
                intval($_POST['image_count']),
                $_POST['prompt_id'],
                $ai_model_id,
                isset($_POST['need_review']) ? 1 : 0,
                intval($_POST['publish_interval']),
                $_POST['knowledge_base_id'] ?: null,
                $author_id,
                isset($_POST['auto_keywords']) ? 1 : 0,
                isset($_POST['auto_description']) ? 1 : 0,
                intval($_POST['draft_limit']),
                isset($_POST['is_loop']) ? 1 : 0,
                $model_selection_mode,
                $_POST['status'],
                $task_id
            ]);
            
            if ($result) {
                $message = __('task_edit.message.update_success');
                
                // 重新获取任务信息
                $stmt = $db->prepare("SELECT * FROM tasks WHERE id = ?");
                $stmt->execute([$task_id]);
                $task = $stmt->fetch();
            } else {
                $error = __('task_edit.error.update_failed');
            }
        } catch (Exception $e) {
            $error = __('task_edit.error.update_exception', ['message' => $e->getMessage()]);
        }
    }
}

// 获取选项数据
$title_libraries = $db->query("
    SELECT tl.*, (SELECT COUNT(*) FROM titles WHERE library_id = tl.id) AS title_count
    FROM title_libraries tl
    ORDER BY name
")->fetchAll();
$image_libraries = $db->query("
    SELECT il.*, (SELECT COUNT(*) FROM images WHERE library_id = il.id) AS image_count
    FROM image_libraries il
    ORDER BY name
")->fetchAll();
$content_prompts = $db->query("SELECT * FROM prompts WHERE type = 'content' ORDER BY name")->fetchAll();
$ai_models = $db->query("
    SELECT *
    FROM ai_models
    WHERE status = 'active'
      AND COALESCE(NULLIF(model_type, ''), 'chat') = 'chat'
    ORDER BY COALESCE(failover_priority, 100) ASC, name
")->fetchAll();
$authors = $db->query("SELECT * FROM authors ORDER BY name")->fetchAll();
$knowledge_bases = $db->query("SELECT * FROM knowledge_bases ORDER BY name")->fetchAll();

// 获取任务统计
$stats = [];

// 总文章数
$stmt = $db->prepare("SELECT COUNT(*) FROM articles WHERE task_id = ? AND deleted_at IS NULL");
$stmt->execute([$task_id]);
$stats['total_articles'] = (int)$stmt->fetchColumn();

// 已发布文章数
$stmt = $db->prepare("SELECT COUNT(*) FROM articles WHERE task_id = ? AND status = 'published' AND deleted_at IS NULL");
$stmt->execute([$task_id]);
$stats['published_articles'] = (int)$stmt->fetchColumn();

// 草稿文章数
$stmt = $db->prepare("SELECT COUNT(*) FROM articles WHERE task_id = ? AND status = 'draft' AND deleted_at IS NULL");
$stmt->execute([$task_id]);
$stats['draft_articles'] = (int)$stmt->fetchColumn();

// 待审核文章数
$stmt = $db->prepare("SELECT COUNT(*) FROM articles WHERE task_id = ? AND review_status = 'pending' AND deleted_at IS NULL");
$stmt->execute([$task_id]);
$stats['pending_review'] = (int)$stmt->fetchColumn();
?>
<!DOCTYPE html>
<html lang="<?php echo htmlspecialchars(app_html_lang()); ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars(__('task_edit.page_title')); ?> - <?php echo htmlspecialchars(__('site_settings.system_name')); ?></title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/lucide/0.263.1/lucide.min.css">
    <script src="https://unpkg.com/lucide@latest/dist/umd/lucide.js"></script>
</head>
<body class="bg-gray-50">
    <!-- 导航栏 -->
    <nav class="bg-white shadow-sm border-b">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
            <div class="flex justify-between h-16">
                <div class="flex items-center space-x-8">
                    <a href="dashboard.php" class="text-xl font-semibold text-gray-900"><?php echo __('site_settings.system_name'); ?></a>
                    <nav class="flex space-x-8">
                        <a href="dashboard.php" class="text-gray-500 hover:text-gray-700"><?php echo __('nav.dashboard'); ?></a>
                        <a href="tasks.php" class="text-blue-600 font-medium"><?php echo __('nav.tasks'); ?></a>
                        <a href="articles.php" class="text-gray-500 hover:text-gray-700"><?php echo __('nav.articles'); ?></a>
                        <a href="materials.php" class="text-gray-500 hover:text-gray-700"><?php echo __('nav.materials'); ?></a>
                        <a href="ai-configurator.php" class="text-gray-500 hover:text-gray-700"><?php echo __('nav.ai_config'); ?></a>
                        <a href="site-settings.php" class="text-gray-500 hover:text-gray-700"><?php echo __('nav.site_settings'); ?></a>
                        <a href="security-settings.php" class="text-gray-500 hover:text-gray-700"><?php echo __('nav.security'); ?></a>
                    </nav>
                </div>
                <div class="flex items-center space-x-4">
                    <span class="text-sm text-gray-600"><?php echo __('header.welcome'); ?>，<?php echo $_SESSION['admin_username']; ?></span>
                    <a href="admin-settings.php" class="text-sm text-gray-600 hover:text-gray-800">
                        <i data-lucide="settings" class="w-4 h-4"></i>
                    </a>
                    <a href="logout.php" class="text-sm text-red-600 hover:text-red-800"><?php echo __('button.logout'); ?></a>
                </div>
            </div>
        </div>
    </nav>

    <div class="max-w-7xl mx-auto py-6 sm:px-6 lg:px-8">
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
            <div class="flex items-center space-x-4">
                <a href="tasks.php" class="text-gray-400 hover:text-gray-600">
                    <i data-lucide="arrow-left" class="w-5 h-5"></i>
                </a>
                <div>
                    <h1 class="text-2xl font-bold text-gray-900"><?php echo __('task_edit.page_heading'); ?></h1>
                    <p class="mt-1 text-sm text-gray-600"><?php echo htmlspecialchars($task['name']); ?></p>
                </div>
            </div>
        </div>

        <div class="grid grid-cols-1 lg:grid-cols-4 gap-6">
            <!-- 主要内容区域 -->
            <div class="lg:col-span-3">
                <!-- 任务配置表单 -->
                <form method="POST" class="space-y-8">
                    <input type="hidden" name="csrf_token" value="<?php echo generate_csrf_token(); ?>">
                    
                    <!-- 基本信息 -->
                    <div class="bg-white shadow rounded-lg">
                        <div class="px-6 py-4 border-b border-gray-200">
                            <h3 class="text-lg font-medium text-gray-900"><?php echo __('task_edit.section.basic_title'); ?></h3>
                        </div>
                        <div class="px-6 py-4 space-y-6">
                            <div>
                                <label class="block text-sm font-medium text-gray-700"><?php echo __('task_edit.field.task_name'); ?> *</label>
                                <input type="text" name="name" required value="<?php echo htmlspecialchars($task['name']); ?>"
                                       class="mt-1 block w-full border-gray-300 rounded-md shadow-sm focus:ring-blue-500 focus:border-blue-500 sm:text-sm">
                            </div>

                            <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                                <div>
                                    <label class="block text-sm font-medium text-gray-700"><?php echo __('task_edit.field.title_library'); ?> *</label>
                                    <select name="title_library_id" required 
                                            class="mt-1 block w-full border-gray-300 rounded-md shadow-sm focus:ring-blue-500 focus:border-blue-500 sm:text-sm">
                                        <option value=""><?php echo __('task_edit.option.select_title_library'); ?></option>
                                        <?php foreach ($title_libraries as $lib): ?>
                                            <option value="<?php echo $lib['id']; ?>" <?php echo $task['title_library_id'] == $lib['id'] ? 'selected' : ''; ?>>
                                                <?php echo htmlspecialchars(__('task_edit.option.library_count', ['name' => $lib['name'], 'count' => $lib['title_count']])); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>

                                <div>
                                    <label class="block text-sm font-medium text-gray-700"><?php echo __('task_edit.field.image_library'); ?></label>
                                    <select name="image_library_id" 
                                            class="mt-1 block w-full border-gray-300 rounded-md shadow-sm focus:ring-blue-500 focus:border-blue-500 sm:text-sm">
                                        <option value=""><?php echo __('task_edit.option.no_images'); ?></option>
                                        <?php foreach ($image_libraries as $lib): ?>
                                            <option value="<?php echo $lib['id']; ?>" <?php echo $task['image_library_id'] == $lib['id'] ? 'selected' : ''; ?>>
                                                <?php echo htmlspecialchars(__('task_edit.option.image_library_count', ['name' => $lib['name'], 'count' => $lib['image_count']])); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                            </div>

                            <div>
                                <label class="block text-sm font-medium text-gray-700"><?php echo __('task_edit.field.image_count'); ?></label>
                                <select name="image_count" 
                                        class="mt-1 block w-full border-gray-300 rounded-md shadow-sm focus:ring-blue-500 focus:border-blue-500 sm:text-sm">
                                    <?php for ($i = 0; $i <= 5; $i++): ?>
                                        <option value="<?php echo $i; ?>" <?php echo $task['image_count'] == $i ? 'selected' : ''; ?>>
                                            <?php echo $i == 0 ? __('task_edit.option.no_image_count') : __('task_edit.option.image_count', ['count' => $i]); ?>
                                        </option>
                                    <?php endfor; ?>
                                </select>
                            </div>
                        </div>
                    </div>

                    <!-- AI配置 -->
                    <div class="bg-white shadow rounded-lg">
                        <div class="px-6 py-4 border-b border-gray-200">
                            <h3 class="text-lg font-medium text-gray-900"><?php echo __('task_edit.section.ai_title'); ?></h3>
                        </div>
                        <div class="px-6 py-4 space-y-6">
                            <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                                <div>
                                    <label class="block text-sm font-medium text-gray-700"><?php echo __('task_edit.field.content_prompt'); ?> *</label>
                                    <select name="prompt_id" required
                                            class="mt-1 block w-full border-gray-300 rounded-md shadow-sm focus:ring-blue-500 focus:border-blue-500 sm:text-sm">
                                        <option value=""><?php echo __('task_edit.option.select_prompt'); ?></option>
                                        <?php foreach ($content_prompts as $prompt): ?>
                                            <option value="<?php echo $prompt['id']; ?>" <?php echo $task['prompt_id'] == $prompt['id'] ? 'selected' : ''; ?>>
                                                <?php echo htmlspecialchars($prompt['name']); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>

                                <div>
                                    <label class="block text-sm font-medium text-gray-700"><?php echo __('task_edit.field.ai_model'); ?> *</label>
                                    <select name="ai_model_id" required 
                                            class="mt-1 block w-full border-gray-300 rounded-md shadow-sm focus:ring-blue-500 focus:border-blue-500 sm:text-sm">
                                        <option value=""><?php echo __('task_edit.option.select_ai_model'); ?></option>
                                        <?php foreach ($ai_models as $model): ?>
                                            <option value="<?php echo $model['id']; ?>" <?php echo $task['ai_model_id'] == $model['id'] ? 'selected' : ''; ?>>
                                                <?php echo htmlspecialchars(__('task_edit.option.ai_model_priority', ['name' => $model['name'], 'priority' => (int) ($model['failover_priority'] ?? 100)])); ?>
                                                <?php if ($model['daily_limit'] > 0): ?>
                                                    (<?php echo __('task_edit.option.daily_remaining', ['count' => max(0, $model['daily_limit'] - $model['used_today'])]); ?>)
                                                <?php endif; ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>

                                <div>
                                    <label class="block text-sm font-medium text-gray-700"><?php echo __('task_edit.field.model_selection_mode'); ?></label>
                                    <select name="model_selection_mode"
                                            class="mt-1 block w-full border-gray-300 rounded-md shadow-sm focus:ring-blue-500 focus:border-blue-500 sm:text-sm">
                                        <option value="fixed" <?php echo ($task['model_selection_mode'] ?? 'fixed') === 'fixed' ? 'selected' : ''; ?>><?php echo __('task_edit.option.model_selection_fixed'); ?></option>
                                        <option value="smart_failover" <?php echo ($task['model_selection_mode'] ?? 'fixed') === 'smart_failover' ? 'selected' : ''; ?>><?php echo __('task_edit.option.model_selection_smart_failover'); ?></option>
                                    </select>
                                    <p class="mt-1 text-sm text-gray-500"><?php echo __('task_edit.help.model_selection_mode'); ?></p>
                                </div>
                            </div>

                            <div>
                                <label class="block text-sm font-medium text-gray-700"><?php echo __('task_edit.field.knowledge_base'); ?></label>
                                <select name="knowledge_base_id"
                                        class="mt-1 block w-full border-gray-300 rounded-md shadow-sm focus:ring-blue-500 focus:border-blue-500 sm:text-sm">
                                    <option value=""><?php echo __('task_edit.option.no_knowledge_base'); ?></option>
                                    <?php foreach ($knowledge_bases as $kb): ?>
                                        <option value="<?php echo $kb['id']; ?>" <?php echo (int) ($task['knowledge_base_id'] ?? 0) === (int) $kb['id'] ? 'selected' : ''; ?>>
                                            <?php echo htmlspecialchars($kb['name']); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                                <p class="mt-1 text-sm text-gray-500"><?php echo __('task_edit.help.knowledge_base'); ?></p>
                            </div>
                        </div>
                    </div>

                    <!-- 发布设置 -->
                    <div class="bg-white shadow rounded-lg">
                        <div class="px-6 py-4 border-b border-gray-200">
                            <h3 class="text-lg font-medium text-gray-900"><?php echo __('task_edit.section.publish_title'); ?></h3>
                        </div>
                        <div class="px-6 py-4 space-y-6">
                            <div class="flex items-center">
                                <input type="checkbox" name="need_review" id="need_review" <?php echo $task['need_review'] ? 'checked' : ''; ?>
                                       class="rounded border-gray-300 text-blue-600 shadow-sm focus:border-blue-300 focus:ring focus:ring-blue-200 focus:ring-opacity-50">
                                <label for="need_review" class="ml-2 text-sm text-gray-700"><?php echo __('task_edit.field.need_review'); ?></label>
                            </div>

                            <div>
                                <label class="block text-sm font-medium text-gray-700"><?php echo __('task_edit.field.publish_interval'); ?></label>
                                <select name="publish_interval" 
                                        class="mt-1 block w-full border-gray-300 rounded-md shadow-sm focus:ring-blue-500 focus:border-blue-500 sm:text-sm">
                                    <option value="1800" <?php echo $task['publish_interval'] == 1800 ? 'selected' : ''; ?>><?php echo __('task_edit.option.interval_30m'); ?></option>
                                    <option value="3600" <?php echo $task['publish_interval'] == 3600 ? 'selected' : ''; ?>><?php echo __('task_edit.option.interval_1h'); ?></option>
                                    <option value="7200" <?php echo $task['publish_interval'] == 7200 ? 'selected' : ''; ?>><?php echo __('task_edit.option.interval_2h'); ?></option>
                                    <option value="14400" <?php echo $task['publish_interval'] == 14400 ? 'selected' : ''; ?>><?php echo __('task_edit.option.interval_4h'); ?></option>
                                    <option value="28800" <?php echo $task['publish_interval'] == 28800 ? 'selected' : ''; ?>><?php echo __('task_edit.option.interval_8h'); ?></option>
                                    <option value="86400" <?php echo $task['publish_interval'] == 86400 ? 'selected' : ''; ?>><?php echo __('task_edit.option.interval_24h'); ?></option>
                                </select>
                            </div>
                        </div>
                    </div>

                    <!-- 作者设置 -->
                    <div class="bg-white shadow rounded-lg">
                        <div class="px-6 py-4 border-b border-gray-200">
                            <h3 class="text-lg font-medium text-gray-900"><?php echo __('task_edit.section.author_title'); ?></h3>
                        </div>
                        <div class="px-6 py-4 space-y-6">
                            <div>
                                <label class="block text-sm font-medium text-gray-700"><?php echo __('task_edit.field.author_mode'); ?></label>
                                <div class="mt-2 space-y-2">
                                    <label class="flex items-center">
                                        <input type="radio" name="author_type" value="random" <?php echo empty($task['author_id']) ? 'checked' : ''; ?>
                                               class="border-gray-300 text-blue-600 shadow-sm focus:border-blue-300 focus:ring focus:ring-blue-200 focus:ring-opacity-50">
                                        <span class="ml-2 text-sm text-gray-700"><?php echo __('task_edit.option.author_random'); ?></span>
                                    </label>
                                    <label class="flex items-center">
                                        <input type="radio" name="author_type" value="custom" <?php echo !empty($task['author_id']) ? 'checked' : ''; ?>
                                               class="border-gray-300 text-blue-600 shadow-sm focus:border-blue-300 focus:ring focus:ring-blue-200 focus:ring-opacity-50">
                                        <span class="ml-2 text-sm text-gray-700"><?php echo __('task_edit.option.author_custom'); ?></span>
                                    </label>
                                </div>
                            </div>

                            <div id="custom_author_section" class="<?php echo empty($task['author_id']) ? 'hidden' : ''; ?>">
                                <label class="block text-sm font-medium text-gray-700"><?php echo __('task_edit.field.custom_author'); ?></label>
                                <select name="custom_author_id"
                                        class="mt-1 block w-full border-gray-300 rounded-md shadow-sm focus:ring-blue-500 focus:border-blue-500 sm:text-sm">
                                    <option value=""><?php echo __('task_edit.option.select_author'); ?></option>
                                    <?php foreach ($authors as $author): ?>
                                        <option value="<?php echo $author['id']; ?>" <?php echo $task['author_id'] == $author['id'] ? 'selected' : ''; ?>>
                                            <?php echo htmlspecialchars($author['name']); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>
                    </div>

                    <!-- 内容优化设置 -->
                    <div class="bg-white shadow rounded-lg">
                        <div class="px-6 py-4 border-b border-gray-200">
                            <h3 class="text-lg font-medium text-gray-900"><?php echo __('task_edit.section.optimize_title'); ?></h3>
                        </div>
                        <div class="px-6 py-4 space-y-6">
                            <div class="space-y-4">
                                <div class="flex items-center">
                                    <input type="checkbox" name="auto_keywords" id="auto_keywords" <?php echo $task['auto_keywords'] ? 'checked' : ''; ?>
                                           class="rounded border-gray-300 text-blue-600 shadow-sm focus:border-blue-300 focus:ring focus:ring-blue-200 focus:ring-opacity-50">
                                    <label for="auto_keywords" class="ml-2 text-sm text-gray-700"><?php echo __('task_edit.field.auto_keywords'); ?></label>
                                </div>

                                <div class="flex items-center">
                                    <input type="checkbox" name="auto_description" id="auto_description" <?php echo $task['auto_description'] ? 'checked' : ''; ?>
                                           class="rounded border-gray-300 text-blue-600 shadow-sm focus:border-blue-300 focus:ring focus:ring-blue-200 focus:ring-opacity-50">
                                    <label for="auto_description" class="ml-2 text-sm text-gray-700"><?php echo __('task_edit.field.auto_description'); ?></label>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- 任务控制设置 -->
                    <div class="bg-white shadow rounded-lg">
                        <div class="px-6 py-4 border-b border-gray-200">
                            <h3 class="text-lg font-medium text-gray-900"><?php echo __('task_edit.section.control_title'); ?></h3>
                        </div>
                        <div class="px-6 py-4 space-y-6">
                            <div>
                                <label class="block text-sm font-medium text-gray-700"><?php echo __('task_edit.field.draft_limit'); ?></label>
                                <input type="number" name="draft_limit" value="<?php echo $task['draft_limit']; ?>" min="1" max="100"
                                       class="mt-1 block w-full border-gray-300 rounded-md shadow-sm focus:ring-blue-500 focus:border-blue-500 sm:text-sm">
                            </div>

                            <div class="flex items-center">
                                <input type="checkbox" name="is_loop" id="is_loop" <?php echo $task['is_loop'] ? 'checked' : ''; ?>
                                       class="rounded border-gray-300 text-blue-600 shadow-sm focus:border-blue-300 focus:ring focus:ring-blue-200 focus:ring-opacity-50">
                                <label for="is_loop" class="ml-2 text-sm text-gray-700"><?php echo __('task_edit.field.loop_mode'); ?></label>
                            </div>

                            <div>
                                <label class="block text-sm font-medium text-gray-700"><?php echo __('task_edit.field.task_status'); ?></label>
                                <div class="mt-2 space-y-2">
                                    <label class="flex items-center">
                                        <input type="radio" name="status" value="active" <?php echo $task['status'] === 'active' ? 'checked' : ''; ?>
                                               class="border-gray-300 text-blue-600 shadow-sm focus:border-blue-300 focus:ring focus:ring-blue-200 focus:ring-opacity-50">
                                        <span class="ml-2 text-sm text-gray-700"><?php echo __('task_edit.option.status_active'); ?></span>
                                    </label>
                                    <label class="flex items-center">
                                        <input type="radio" name="status" value="paused" <?php echo $task['status'] === 'paused' ? 'checked' : ''; ?>
                                               class="border-gray-300 text-blue-600 shadow-sm focus:border-blue-300 focus:ring focus:ring-blue-200 focus:ring-opacity-50">
                                        <span class="ml-2 text-sm text-gray-700"><?php echo __('task_edit.option.status_paused'); ?></span>
                                    </label>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- 提交按钮 -->
                    <div class="flex justify-end space-x-3">
                        <a href="tasks.php" class="inline-flex items-center px-4 py-2 border border-gray-300 text-sm font-medium rounded-md text-gray-700 bg-white hover:bg-gray-50">
                            <?php echo __('button.cancel'); ?>
                        </a>
                        <button type="submit" class="inline-flex items-center px-4 py-2 border border-transparent text-sm font-medium rounded-md text-white bg-blue-600 hover:bg-blue-700">
                            <i data-lucide="save" class="w-4 h-4 mr-2"></i>
                            <?php echo __('task_edit.button.save_changes'); ?>
                        </button>
                    </div>
                </form>
            </div>

            <!-- 侧边栏 -->
            <div class="space-y-6">
                <!-- 任务统计 -->
                <div class="bg-white shadow rounded-lg">
                    <div class="px-6 py-4 border-b border-gray-200">
                        <h3 class="text-lg font-medium text-gray-900"><?php echo __('task_edit.sidebar.stats_title'); ?></h3>
                    </div>
                    <div class="px-6 py-4 space-y-4">
                        <div class="flex justify-between">
                            <span class="text-sm text-gray-600"><?php echo __('task_edit.sidebar.total_articles'); ?></span>
                            <span class="text-sm font-medium text-gray-900"><?php echo $stats['total_articles']; ?></span>
                        </div>
                        <div class="flex justify-between">
                            <span class="text-sm text-gray-600"><?php echo __('task_edit.sidebar.published_articles'); ?></span>
                            <span class="text-sm font-medium text-green-600"><?php echo $stats['published_articles']; ?></span>
                        </div>
                        <div class="flex justify-between">
                            <span class="text-sm text-gray-600"><?php echo __('task_edit.sidebar.draft_articles'); ?></span>
                            <span class="text-sm font-medium text-yellow-600"><?php echo $stats['draft_articles']; ?></span>
                        </div>
                        <div class="flex justify-between">
                            <span class="text-sm text-gray-600"><?php echo __('task_edit.sidebar.pending_review'); ?></span>
                            <span class="text-sm font-medium text-blue-600"><?php echo $stats['pending_review']; ?></span>
                        </div>
                        <div class="flex justify-between">
                            <span class="text-sm text-gray-600"><?php echo __('task_edit.sidebar.loop_count'); ?></span>
                            <span class="text-sm font-medium text-purple-600"><?php echo $task['loop_count']; ?></span>
                        </div>
                    </div>
                </div>

                <!-- 快速操作 -->
                <div class="bg-white shadow rounded-lg">
                    <div class="px-6 py-4 border-b border-gray-200">
                        <h3 class="text-lg font-medium text-gray-900"><?php echo __('task_edit.sidebar.quick_actions_title'); ?></h3>
                    </div>
                    <div class="px-6 py-4 space-y-3">
                        <a href="articles.php?task_id=<?php echo $task_id; ?>" class="w-full inline-flex items-center justify-center px-4 py-2 border border-gray-300 text-sm font-medium rounded-md text-gray-700 bg-white hover:bg-gray-50">
                            <i data-lucide="file-text" class="w-4 h-4 mr-2"></i>
                            <?php echo __('task_edit.button.manage_articles'); ?>
                        </a>
                        
                        <a href="task-execute.php?id=<?php echo $task_id; ?>" class="w-full inline-flex items-center justify-center px-4 py-2 border border-transparent text-sm font-medium rounded-md text-white bg-green-600 hover:bg-green-700">
                            <i data-lucide="play" class="w-4 h-4 mr-2"></i>
                            <?php echo __('task_edit.button.test_execute'); ?>
                        </a>
                    </div>
                </div>

                <!-- 任务信息 -->
                <div class="bg-white shadow rounded-lg">
                    <div class="px-6 py-4 border-b border-gray-200">
                        <h3 class="text-lg font-medium text-gray-900"><?php echo __('task_edit.sidebar.info_title'); ?></h3>
                    </div>
                    <div class="px-6 py-4 space-y-3">
                        <div>
                            <dt class="text-sm font-medium text-gray-500"><?php echo __('common.created_at'); ?></dt>
                            <dd class="mt-1 text-sm text-gray-900"><?php echo date('Y-m-d H:i', strtotime($task['created_at'])); ?></dd>
                        </div>
                        <div>
                            <dt class="text-sm font-medium text-gray-500"><?php echo __('task_edit.sidebar.last_updated'); ?></dt>
                            <dd class="mt-1 text-sm text-gray-900"><?php echo date('Y-m-d H:i', strtotime($task['updated_at'])); ?></dd>
                        </div>
                        <div>
                            <dt class="text-sm font-medium text-gray-500"><?php echo __('task_edit.sidebar.current_status'); ?></dt>
                            <dd class="mt-1">
                                <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium <?php 
                                    echo $task['status'] === 'active' ? 'bg-green-100 text-green-800' : 
                                        ($task['status'] === 'paused' ? 'bg-yellow-100 text-yellow-800' : 'bg-gray-100 text-gray-800'); 
                                ?>">
                                    <?php 
                                    echo $task['status'] === 'active' ? __('status.running') : 
                                        ($task['status'] === 'paused' ? __('status.paused') : __('task_edit.status.completed')); 
                                    ?>
                                </span>
                            </dd>
                        </div>
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
            
            // 作者类型切换
            const authorTypeRadios = document.querySelectorAll('input[name="author_type"]');
            const customAuthorSection = document.getElementById('custom_author_section');
            
            authorTypeRadios.forEach(radio => {
                radio.addEventListener('change', function() {
                    if (this.value === 'custom') {
                        customAuthorSection.classList.remove('hidden');
                    } else {
                        customAuthorSection.classList.add('hidden');
                    }
                });
            });
        });
    </script>
</body>
</html>
