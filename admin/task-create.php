<?php
/**
 * 智能GEO内容系统 - 任务创建向导
 *
 * @author 姚金刚
 * @version 2.0
 * @date 2025-10-07
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

// 处理任务创建
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
        $error = __('message.csrf_failed');
    } else {
        // 获取表单数据
        $task_name = trim($_POST['task_name'] ?? '');
        $title_library_id = intval($_POST['title_library_id'] ?? 0);
        $image_library_id = !empty($_POST['image_library_id']) ? intval($_POST['image_library_id']) : null;
        $image_count = intval($_POST['image_count'] ?? 0);
        $prompt_id = intval($_POST['prompt_id'] ?? 0);
        $ai_model_id = intval($_POST['ai_model_id'] ?? 0);
        $model_selection_mode = $_POST['model_selection_mode'] ?? 'fixed';
        $need_review = isset($_POST['need_review']) ? 1 : 0;
        $publish_interval_minutes = max(1, intval($_POST['publish_interval'] ?? 60));
        $publish_interval = $publish_interval_minutes * 60;
        $author_id = !empty($_POST['author_id']) ? intval($_POST['author_id']) : null;
        $auto_keywords = isset($_POST['auto_keywords']) ? 1 : 0;
        $auto_description = isset($_POST['auto_description']) ? 1 : 0;
        $draft_limit = intval($_POST['draft_limit'] ?? 10);
        $is_loop = isset($_POST['is_loop']) ? 1 : 0;
        $status = $_POST['status'] ?? 'active';
        $knowledge_base_id = !empty($_POST['knowledge_base_id']) ? intval($_POST['knowledge_base_id']) : null;

        // 分类设置
        $category_mode = $_POST['category_mode'] ?? 'smart';
        $fixed_category_id = null;
        if ($category_mode === 'fixed' && !empty($_POST['fixed_category_id'])) {
            $fixed_category_id = intval($_POST['fixed_category_id']);
        }

        // 验证必填字段
        if (empty($task_name)) {
            $error = __('task_create.error.name_required');
        } elseif ($title_library_id <= 0) {
            $error = __('task_create.error.title_library_required');
        } elseif ($prompt_id <= 0) {
            $error = __('task_create.error.prompt_required');
        } elseif ($ai_model_id <= 0) {
            $error = __('task_create.error.ai_model_required');
        } elseif (!in_array($model_selection_mode, ['fixed', 'smart_failover'], true)) {
            $error = __('task_create.error.model_selection_mode_invalid');
        } elseif ($category_mode === 'fixed' && $fixed_category_id <= 0) {
            $error = __('task_create.error.fixed_category_required');
        } else {
            // 验证外键关系是否存在
            $stmt = $db->prepare("SELECT COUNT(*) FROM title_libraries WHERE id = ?");
            $stmt->execute([$title_library_id]);
            if ($stmt->fetchColumn() == 0) {
                $error = __('task_create.error.title_library_missing');
            } else {
                $stmt = $db->prepare("SELECT COUNT(*) FROM prompts WHERE id = ? AND type = 'content'");
                $stmt->execute([$prompt_id]);
                if ($stmt->fetchColumn() == 0) {
                    $error = __('task_create.error.prompt_missing');
                } else {
                    $stmt = $db->prepare("
                        SELECT COUNT(*)
                        FROM ai_models
                        WHERE id = ?
                          AND status = 'active'
                          AND COALESCE(NULLIF(model_type, ''), 'chat') = 'chat'
                    ");
                    $stmt->execute([$ai_model_id]);
                    if ($stmt->fetchColumn() == 0) {
                        $error = __('task_create.error.ai_model_missing');
                    }
                }
            }
        }

        if (empty($error)) {
            try {
                $db->beginTransaction();

                // 创建任务
                $stmt = $db->prepare("
                    INSERT INTO tasks (
                        name, title_library_id, image_library_id, image_count,
                        prompt_id, ai_model_id, need_review, publish_interval,
                        author_id, auto_keywords, auto_description, draft_limit,
                        is_loop, model_selection_mode, status, knowledge_base_id, category_mode, fixed_category_id,
                        created_at, updated_at
                    ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)
                ");

                $result = $stmt->execute([
                    $task_name, $title_library_id, $image_library_id, $image_count,
                    $prompt_id, $ai_model_id, $need_review, $publish_interval,
                    $author_id, $auto_keywords, $auto_description, $draft_limit,
                    $is_loop, $model_selection_mode, $status, $knowledge_base_id, $category_mode, $fixed_category_id
                ]);

                if ($result) {
                    $task_id = db_last_insert_id($db, 'tasks');
                    $jobQueueService = new JobQueueService($db);
                    $jobQueueService->initializeTaskSchedule((int) $task_id);

                    // 如果任务是活跃状态，创建调度记录
                    if ($status === 'active') {
                        $stmt = $db->prepare("
                            INSERT INTO task_schedules (task_id, next_run_time, created_at)
                            VALUES (?, " . db_now_plus_minutes_sql(1) . ", CURRENT_TIMESTAMP)
                        ");
                        $stmt->execute([$task_id]);
                    } else {
                        $stmt = $db->prepare("
                            UPDATE tasks
                            SET schedule_enabled = 0,
                                next_run_at = NULL,
                                updated_at = CURRENT_TIMESTAMP
                            WHERE id = ?
                        ");
                        $stmt->execute([$task_id]);
                    }

                    $db->commit();

                    // 创建成功消息
                    $message = __('task_create.message.created');
                    if ($status === 'active') {
                        $message .= ' ' . __('task_create.message.created_active_suffix');
                    } else {
                        $message .= ' ' . __('task_create.message.created_paused_suffix');
                    }

                    // 重定向到任务列表
                    header('Location: tasks.php?message=' . urlencode($message));
                    exit;
                } else {
                    throw new Exception(__('task_create.error.create_failed'));
                }
            } catch (Exception $e) {
                $db->rollBack();
                $error = __('task_create.error.create_exception', ['message' => $e->getMessage()]);

                // 添加调试信息
                error_log("Task creation failed with data: " . json_encode([
                    'task_name' => $task_name,
                    'title_library_id' => $title_library_id,
                    'image_library_id' => $image_library_id,
                    'prompt_id' => $prompt_id,
                    'ai_model_id' => $ai_model_id,
                    'author_id' => $author_id,
                    'knowledge_base_id' => $knowledge_base_id
                ]));
            }
        }
    }
}

// 获取选项数据
$title_libraries = $db->query("SELECT id, name, (SELECT COUNT(*) FROM titles WHERE library_id = title_libraries.id) as title_count FROM title_libraries ORDER BY name")->fetchAll();
$image_libraries = $db->query("SELECT id, name, (SELECT COUNT(*) FROM images WHERE library_id = image_libraries.id) as image_count FROM image_libraries ORDER BY name")->fetchAll();
$content_prompts = $db->query("SELECT id, name FROM prompts WHERE type = 'content' ORDER BY name")->fetchAll();
$ai_models = $db->query("
    SELECT id, name, status, COALESCE(failover_priority, 100) AS failover_priority
    FROM ai_models
    WHERE status = 'active'
      AND COALESCE(NULLIF(model_type, ''), 'chat') = 'chat'
    ORDER BY failover_priority ASC, name
")->fetchAll();
$authors = $db->query("SELECT id, name FROM authors ORDER BY name")->fetchAll();
$knowledge_bases = $db->query("SELECT id, name FROM knowledge_bases ORDER BY name")->fetchAll();

// 设置页面信息
$page_title = __('task_create.page_title');
$page_header = '
<div class="flex items-center justify-between">
    <div class="flex items-center space-x-4">
        <a href="tasks.php" class="text-gray-400 hover:text-gray-600">
            <i data-lucide="arrow-left" class="w-5 h-5"></i>
        </a>
        <div>
            <h1 class="text-2xl font-bold text-gray-900">' . htmlspecialchars(__('task_create.page_heading'), ENT_QUOTES, 'UTF-8') . '</h1>
            <p class="mt-1 text-sm text-gray-600">' . htmlspecialchars(__('task_create.page_subtitle'), ENT_QUOTES, 'UTF-8') . '</p>
        </div>
    </div>
</div>
';

// 包含头部模块
require_once __DIR__ . '/includes/header.php';
?>

<!-- 任务创建表单 -->
<div class="max-w-4xl mx-auto">
    <form method="POST" class="space-y-8">
        <input type="hidden" name="csrf_token" value="<?php echo generate_csrf_token(); ?>">

        <!-- 基础信息 -->
        <div class="bg-white shadow rounded-lg">
            <div class="px-6 py-4 border-b border-gray-200">
                <h3 class="text-lg font-medium text-gray-900"><?php echo __('task_create.section.basic_title'); ?></h3>
                <p class="mt-1 text-sm text-gray-600"><?php echo __('task_create.section.basic_desc'); ?></p>
            </div>
            <div class="px-6 py-4">
                <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                    <div class="md:col-span-2">
                        <label for="task_name" class="block text-sm font-medium text-gray-700"><?php echo __('task_create.field.task_name'); ?> *</label>
                        <input type="text" name="task_name" id="task_name" required
                               class="mt-1 block w-full border-gray-300 rounded-md shadow-sm focus:ring-blue-500 focus:border-blue-500"
                               placeholder="<?php echo htmlspecialchars(__('task_create.placeholder.task_name'), ENT_QUOTES, 'UTF-8'); ?>">
                    </div>

                    <div>
                        <label for="title_library_id" class="block text-sm font-medium text-gray-700"><?php echo __('task_create.field.title_library'); ?> *</label>
                        <select name="title_library_id" id="title_library_id" required
                                class="mt-1 block w-full border-gray-300 rounded-md shadow-sm focus:ring-blue-500 focus:border-blue-500">
                            <option value=""><?php echo __('task_create.option.select_title_library'); ?></option>
                            <?php foreach ($title_libraries as $library): ?>
                                <option value="<?php echo $library['id']; ?>">
                                    <?php echo htmlspecialchars(__('task_create.option.library_count', ['name' => $library['name'], 'count' => $library['title_count']])); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div>
                        <label for="status" class="block text-sm font-medium text-gray-700"><?php echo __('task_create.field.task_status'); ?></label>
                        <select name="status" id="status"
                                class="mt-1 block w-full border-gray-300 rounded-md shadow-sm focus:ring-blue-500 focus:border-blue-500">
                            <option value="active"><?php echo __('task_create.option.status_active'); ?></option>
                            <option value="paused"><?php echo __('task_create.option.status_paused'); ?></option>
                        </select>
                    </div>
                </div>
            </div>
        </div>

        <!-- 内容配置 -->
        <div class="bg-white shadow rounded-lg">
            <div class="px-6 py-4 border-b border-gray-200">
                <h3 class="text-lg font-medium text-gray-900"><?php echo __('task_create.section.content_title'); ?></h3>
                <p class="mt-1 text-sm text-gray-600"><?php echo __('task_create.section.content_desc'); ?></p>
            </div>
            <div class="px-6 py-4">
                <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                    <div>
                        <label for="prompt_id" class="block text-sm font-medium text-gray-700"><?php echo __('task_create.field.content_prompt'); ?> *</label>
                        <select name="prompt_id" id="prompt_id" required
                                class="mt-1 block w-full border-gray-300 rounded-md shadow-sm focus:ring-blue-500 focus:border-blue-500">
                            <option value=""><?php echo __('task_create.option.select_prompt'); ?></option>
                            <?php foreach ($content_prompts as $prompt): ?>
                                <option value="<?php echo $prompt['id']; ?>">
                                    <?php echo htmlspecialchars($prompt['name']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div>
                        <label for="ai_model_id" class="block text-sm font-medium text-gray-700"><?php echo __('task_create.field.ai_model'); ?> *</label>
                        <select name="ai_model_id" id="ai_model_id" required
                                class="mt-1 block w-full border-gray-300 rounded-md shadow-sm focus:ring-blue-500 focus:border-blue-500">
                            <option value=""><?php echo __('task_create.option.select_ai_model'); ?></option>
                            <?php foreach ($ai_models as $model): ?>
                                <option value="<?php echo $model['id']; ?>" <?php echo ($ai_model_id ?? '') == $model['id'] ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars(__('task_create.option.ai_model_priority', ['name' => $model['name'], 'priority' => (int) $model['failover_priority']])); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div>
                        <label for="model_selection_mode" class="block text-sm font-medium text-gray-700"><?php echo __('task_create.field.model_selection_mode'); ?></label>
                        <select name="model_selection_mode" id="model_selection_mode"
                                class="mt-1 block w-full border-gray-300 rounded-md shadow-sm focus:ring-blue-500 focus:border-blue-500">
                            <option value="fixed" <?php echo ($model_selection_mode ?? 'fixed') === 'fixed' ? 'selected' : ''; ?>><?php echo __('task_create.option.model_selection_fixed'); ?></option>
                            <option value="smart_failover" <?php echo ($model_selection_mode ?? 'fixed') === 'smart_failover' ? 'selected' : ''; ?>><?php echo __('task_create.option.model_selection_smart_failover'); ?></option>
                        </select>
                        <p class="mt-1 text-sm text-gray-500"><?php echo __('task_create.help.model_selection_mode'); ?></p>
                    </div>

                    <div>
                        <label for="knowledge_base_id" class="block text-sm font-medium text-gray-700"><?php echo __('task_create.field.knowledge_base'); ?></label>
                        <select name="knowledge_base_id" id="knowledge_base_id"
                                class="mt-1 block w-full border-gray-300 rounded-md shadow-sm focus:ring-blue-500 focus:border-blue-500">
                            <option value=""><?php echo __('task_create.option.no_knowledge_base'); ?></option>
                            <?php foreach ($knowledge_bases as $kb): ?>
                                <option value="<?php echo $kb['id']; ?>">
                                    <?php echo htmlspecialchars($kb['name']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <p class="mt-1 text-sm text-gray-500"><?php echo __('task_create.help.knowledge_base'); ?></p>
                    </div>

                    <div>
                        <label for="author_id" class="block text-sm font-medium text-gray-700"><?php echo __('task_create.field.author'); ?></label>
                        <select name="author_id" id="author_id"
                                class="mt-1 block w-full border-gray-300 rounded-md shadow-sm focus:ring-blue-500 focus:border-blue-500">
                            <option value="0"><?php echo __('task_create.option.random_author'); ?></option>
                            <?php foreach ($authors as $author): ?>
                                <option value="<?php echo $author['id']; ?>">
                                    <?php echo htmlspecialchars($author['name']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
            </div>
        </div>

        <!-- 图片配置 -->
        <div class="bg-white shadow rounded-lg">
            <div class="px-6 py-4 border-b border-gray-200">
                <h3 class="text-lg font-medium text-gray-900"><?php echo __('task_create.section.image_title'); ?></h3>
                <p class="mt-1 text-sm text-gray-600"><?php echo __('task_create.section.image_desc'); ?></p>
            </div>
            <div class="px-6 py-4">
                <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                    <div>
                        <label for="image_library_id" class="block text-sm font-medium text-gray-700"><?php echo __('task_create.field.image_library'); ?></label>
                        <select name="image_library_id" id="image_library_id"
                                class="mt-1 block w-full border-gray-300 rounded-md shadow-sm focus:ring-blue-500 focus:border-blue-500">
                            <option value=""><?php echo __('task_create.option.no_images'); ?></option>
                            <?php foreach ($image_libraries as $library): ?>
                                <option value="<?php echo $library['id']; ?>">
                                    <?php echo htmlspecialchars(__('task_create.option.image_library_count', ['name' => $library['name'], 'count' => $library['image_count']])); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div>
                        <label for="image_count" class="block text-sm font-medium text-gray-700"><?php echo __('task_create.field.image_count'); ?></label>
                        <select name="image_count" id="image_count"
                                class="mt-1 block w-full border-gray-300 rounded-md shadow-sm focus:ring-blue-500 focus:border-blue-500">
                            <option value="0"><?php echo __('task_create.option.no_image_count'); ?></option>
                            <option value="1" selected><?php echo __('task_create.option.image_count', ['count' => 1]); ?></option>
                            <option value="2"><?php echo __('task_create.option.image_count', ['count' => 2]); ?></option>
                            <option value="3"><?php echo __('task_create.option.image_count', ['count' => 3]); ?></option>
                            <option value="4"><?php echo __('task_create.option.image_count', ['count' => 4]); ?></option>
                            <option value="5"><?php echo __('task_create.option.image_count', ['count' => 5]); ?></option>
                        </select>
                        <p class="mt-1 text-sm text-gray-500"><?php echo __('task_create.help.image_count'); ?></p>
                    </div>
                </div>
            </div>
        </div>

        <!-- 审核与发布设置 -->
        <div class="bg-white shadow rounded-lg">
            <div class="px-6 py-4 border-b border-gray-200">
                <h3 class="text-lg font-medium text-gray-900"><?php echo __('task_create.section.publish_title'); ?></h3>
                <p class="mt-1 text-sm text-gray-600"><?php echo __('task_create.section.publish_desc'); ?></p>
            </div>
            <div class="px-6 py-4">
                <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                    <div>
                        <div class="flex items-center">
                            <input type="checkbox" name="need_review" id="need_review"
                                   class="h-4 w-4 text-blue-600 focus:ring-blue-500 border-gray-300 rounded">
                            <label for="need_review" class="ml-2 block text-sm text-gray-900"><?php echo __('task_create.field.need_review'); ?></label>
                        </div>
                        <p class="mt-1 text-sm text-gray-500"><?php echo __('task_create.help.need_review'); ?></p>
                    </div>

                    <div>
                        <label for="publish_interval" class="block text-sm font-medium text-gray-700"><?php echo __('task_create.field.publish_interval'); ?></label>
                        <input type="number" name="publish_interval" id="publish_interval" min="1" value="60"
                               class="mt-1 block w-full border-gray-300 rounded-md shadow-sm focus:ring-blue-500 focus:border-blue-500">
                        <p class="mt-1 text-sm text-gray-500"><?php echo __('task_create.help.publish_interval'); ?></p>
                    </div>
                </div>
            </div>
        </div>

        <!-- SEO设置 -->
        <div class="bg-white shadow rounded-lg">
            <div class="px-6 py-4 border-b border-gray-200">
                <h3 class="text-lg font-medium text-gray-900"><?php echo __('task_create.section.seo_title'); ?></h3>
                <p class="mt-1 text-sm text-gray-600"><?php echo __('task_create.section.seo_desc'); ?></p>
            </div>
            <div class="px-6 py-4">
                <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                    <div>
                        <div class="flex items-center">
                            <input type="checkbox" name="auto_keywords" id="auto_keywords" checked
                                   class="h-4 w-4 text-blue-600 focus:ring-blue-500 border-gray-300 rounded">
                            <label for="auto_keywords" class="ml-2 block text-sm text-gray-900"><?php echo __('task_create.field.auto_keywords'); ?></label>
                        </div>
                        <p class="mt-1 text-sm text-gray-500"><?php echo __('task_create.help.auto_keywords'); ?></p>
                    </div>

                    <div>
                        <div class="flex items-center">
                            <input type="checkbox" name="auto_description" id="auto_description" checked
                                   class="h-4 w-4 text-blue-600 focus:ring-blue-500 border-gray-300 rounded">
                            <label for="auto_description" class="ml-2 block text-sm text-gray-900"><?php echo __('task_create.field.auto_description'); ?></label>
                        </div>
                        <p class="mt-1 text-sm text-gray-500"><?php echo __('task_create.help.auto_description'); ?></p>
                    </div>
                </div>
            </div>
        </div>

        <!-- 分类设置 -->
        <div class="bg-white shadow rounded-lg">
            <div class="px-6 py-4 border-b border-gray-200">
                <h3 class="text-lg font-medium text-gray-900"><?php echo __('task_create.section.category_title'); ?></h3>
                <p class="mt-1 text-sm text-gray-600"><?php echo __('task_create.section.category_desc'); ?></p>
            </div>
            <div class="px-6 py-4">
                <div class="space-y-4">
                    <!-- 分类模式选择 -->
                    <div>
                        <label class="text-base font-medium text-gray-900"><?php echo __('task_create.field.category_mode'); ?></label>
                        <p class="text-sm leading-5 text-gray-500"><?php echo __('task_create.help.category_mode'); ?></p>
                        <fieldset class="mt-4">
                            <legend class="sr-only"><?php echo __('task_create.field.category_mode'); ?></legend>
                            <div class="space-y-4">
                                <!-- 智能模式 -->
                                <div class="flex items-start">
                                    <div class="flex items-center h-5">
                                        <input id="category_smart" name="category_mode" type="radio" value="smart" checked
                                               class="focus:ring-blue-500 h-4 w-4 text-blue-600 border-gray-300">
                                    </div>
                                    <div class="ml-3 text-sm">
                                        <label for="category_smart" class="font-medium text-gray-700"><?php echo __('task_create.option.category_smart'); ?></label>
                                        <p class="text-gray-500"><?php echo __('task_create.help.category_smart'); ?></p>
                                    </div>
                                </div>

                                <!-- 固定分类模式 -->
                                <div class="flex items-start">
                                    <div class="flex items-center h-5">
                                        <input id="category_fixed" name="category_mode" type="radio" value="fixed"
                                               class="focus:ring-blue-500 h-4 w-4 text-blue-600 border-gray-300">
                                    </div>
                                    <div class="ml-3 text-sm">
                                        <label for="category_fixed" class="font-medium text-gray-700"><?php echo __('task_create.option.category_fixed'); ?></label>
                                        <p class="text-gray-500"><?php echo __('task_create.help.category_fixed'); ?></p>
                                    </div>
                                </div>

                                <!-- 随机分类模式 -->
                                <div class="flex items-start">
                                    <div class="flex items-center h-5">
                                        <input id="category_random" name="category_mode" type="radio" value="random"
                                               class="focus:ring-blue-500 h-4 w-4 text-blue-600 border-gray-300">
                                    </div>
                                    <div class="ml-3 text-sm">
                                        <label for="category_random" class="font-medium text-gray-700"><?php echo __('task_create.option.category_random'); ?></label>
                                        <p class="text-gray-500"><?php echo __('task_create.help.category_random'); ?></p>
                                    </div>
                                </div>
                            </div>
                        </fieldset>
                    </div>

                    <!-- 固定分类选择 -->
                    <div id="fixed-category-section" class="hidden">
                        <label for="fixed_category_id" class="block text-sm font-medium text-gray-700"><?php echo __('task_create.field.fixed_category'); ?></label>
                        <select name="fixed_category_id" id="fixed_category_id"
                                class="mt-1 block w-full pl-3 pr-10 py-2 text-base border-gray-300 focus:outline-none focus:ring-blue-500 focus:border-blue-500 sm:text-sm rounded-md">
                            <option value=""><?php echo __('task_create.option.select_category'); ?></option>
                            <?php
                            // 获取所有分类
                            try {
                                $stmt = $db->prepare("SELECT id, name, description FROM categories ORDER BY sort_order, name");
                                $stmt->execute();
                                $categories = $stmt->fetchAll();

                                foreach ($categories as $category) {
                                    echo '<option value="' . $category['id'] . '">' . htmlspecialchars($category['name']);
                                    if (!empty($category['description'])) {
                                        echo ' - ' . htmlspecialchars($category['description']);
                                    }
                                    echo '</option>';
                                }
                            } catch (Exception $e) {
                                echo '<option value="">' . htmlspecialchars(__('task_create.option.categories_load_failed'), ENT_QUOTES, 'UTF-8') . '</option>';
                            }
                            ?>
                        </select>
                        <p class="mt-2 text-sm text-gray-500"><?php echo __('task_create.help.fixed_category'); ?></p>
                    </div>

                    <!-- 分类预览 -->
                    <div class="bg-gray-50 rounded-lg p-4">
                        <h4 class="text-sm font-medium text-gray-900 mb-2"><?php echo __('task_create.preview.categories_title'); ?></h4>
                        <div class="flex flex-wrap gap-2">
                            <?php
                            foreach ($categories as $category) {
                                echo '<span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-blue-100 text-blue-800">';
                                echo htmlspecialchars($category['name']);
                                echo '</span>';
                            }
                            ?>
                        </div>
                        <p class="mt-2 text-xs text-gray-500">
                            <?php echo __('task_create.preview.categories_count', ['count' => count($categories)]); ?>
                        </p>
                    </div>
                </div>
            </div>
        </div>

        <!-- 高级设置 -->
        <div class="bg-white shadow rounded-lg">
            <div class="px-6 py-4 border-b border-gray-200">
                <h3 class="text-lg font-medium text-gray-900"><?php echo __('task_create.section.advanced_title'); ?></h3>
                <p class="mt-1 text-sm text-gray-600"><?php echo __('task_create.section.advanced_desc'); ?></p>
            </div>
            <div class="px-6 py-4">
                <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                    <div>
                        <label for="draft_limit" class="block text-sm font-medium text-gray-700"><?php echo __('task_create.field.draft_limit'); ?></label>
                        <input type="number" name="draft_limit" id="draft_limit" min="1" value="10"
                               class="mt-1 block w-full border-gray-300 rounded-md shadow-sm focus:ring-blue-500 focus:border-blue-500">
                        <p class="mt-1 text-sm text-gray-500"><?php echo __('task_create.help.draft_limit'); ?></p>
                    </div>

                    <div>
                        <div class="flex items-center">
                            <input type="checkbox" name="is_loop" id="is_loop" checked
                                   class="h-4 w-4 text-blue-600 focus:ring-blue-500 border-gray-300 rounded">
                            <label for="is_loop" class="ml-2 block text-sm text-gray-900"><?php echo __('task_create.field.loop_mode'); ?></label>
                        </div>
                        <p class="mt-1 text-sm text-gray-500"><?php echo __('task_create.help.loop_mode'); ?></p>
                    </div>
                </div>
            </div>
        </div>

        <!-- 提交按钮 -->
        <div class="flex justify-end space-x-4">
            <a href="tasks.php" class="px-6 py-2 border border-gray-300 rounded-md shadow-sm text-sm font-medium text-gray-700 bg-white hover:bg-gray-50">
                <?php echo __('button.cancel'); ?>
            </a>
            <button type="submit" class="px-6 py-2 border border-transparent rounded-md shadow-sm text-sm font-medium text-white bg-blue-600 hover:bg-blue-700">
                <?php echo __('button.create_task'); ?>
            </button>
        </div>
    </form>

<?php require_once __DIR__ . '/includes/footer.php'; ?>

<script>
// 表单交互逻辑
document.addEventListener('DOMContentLoaded', function() {
    // 初始化Lucide图标
    if (typeof lucide !== 'undefined') {
        lucide.createIcons();
    }

    // 图库选择联动
    const imageLibrarySelect = document.getElementById('image_library_id');
    const imageCountSelect = document.getElementById('image_count');

    imageLibrarySelect.addEventListener('change', function() {
        if (this.value === '') {
            imageCountSelect.value = '0';
            imageCountSelect.disabled = true;
        } else {
            imageCountSelect.disabled = false;
            if (imageCountSelect.value === '0') {
                imageCountSelect.value = '1';
            }
        }
    });

    // 审核设置联动
    const needReviewCheckbox = document.getElementById('need_review');
    const publishIntervalInput = document.getElementById('publish_interval');

    function togglePublishInterval() {
        if (needReviewCheckbox.checked) {
            publishIntervalInput.disabled = true;
            publishIntervalInput.parentElement.style.opacity = '0.5';
        } else {
            publishIntervalInput.disabled = false;
            publishIntervalInput.parentElement.style.opacity = '1';
        }
    }

    needReviewCheckbox.addEventListener('change', togglePublishInterval);
    togglePublishInterval(); // 初始化状态

    // 表单验证
    const form = document.querySelector('form');
    form.addEventListener('submit', function(e) {
        const taskName = document.getElementById('task_name').value.trim();
        const titleLibraryId = document.getElementById('title_library_id').value;
        const promptId = document.getElementById('prompt_id').value;
        const aiModelId = document.getElementById('ai_model_id').value;

        if (!taskName) {
            alert('<?php echo addslashes(__('task_create.error.name_required')); ?>');
            e.preventDefault();
            return;
        }

        if (!titleLibraryId) {
            alert('<?php echo addslashes(__('task_create.error.title_library_required')); ?>');
            e.preventDefault();
            return;
        }

        if (!promptId) {
            alert('<?php echo addslashes(__('task_create.error.prompt_required')); ?>');
            e.preventDefault();
            return;
        }

        if (!aiModelId) {
            alert('<?php echo addslashes(__('task_create.error.ai_model_required')); ?>');
            e.preventDefault();
            return;
        }

        // 确认创建
        if (!confirm('<?php echo addslashes(__('task_create.confirm.create')); ?>')) {
            e.preventDefault();
            return;
        }
    });

    // 显示消息提示
    <?php if ($message): ?>
        setTimeout(() => {
            const messageDiv = document.querySelector('.bg-green-100');
            if (messageDiv) messageDiv.style.display = 'none';
        }, 5000);
    <?php endif; ?>

    <?php if ($error): ?>
        setTimeout(() => {
            const errorDiv = document.querySelector('.bg-red-100');
            if (errorDiv) errorDiv.style.display = 'none';
        }, 8000);
    <?php endif; ?>

    // 分类模式切换处理
    const categoryModeRadios = document.querySelectorAll('input[name="category_mode"]');
    const fixedCategorySection = document.getElementById('fixed-category-section');
    const fixedCategorySelect = document.getElementById('fixed_category_id');

    function handleCategoryModeChange() {
        const selectedMode = document.querySelector('input[name="category_mode"]:checked').value;

        if (selectedMode === 'fixed') {
            fixedCategorySection.classList.remove('hidden');
            fixedCategorySelect.required = true;
        } else {
            fixedCategorySection.classList.add('hidden');
            fixedCategorySelect.required = false;
            fixedCategorySelect.value = '';
        }
    }

    // 绑定事件监听器
    categoryModeRadios.forEach(radio => {
        radio.addEventListener('change', handleCategoryModeChange);
    });

    // 初始化状态
    handleCategoryModeChange();
});
</script>
