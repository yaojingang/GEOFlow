<?php
/**
 * 智能GEO内容系统 - 关键词库详情
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
require_once __DIR__ . '/includes/material-library-helpers.php';

// 检查管理员登录
require_admin_login();

// 立即释放session锁，允许其他页面并发访问
session_write_close();

$library_id = intval($_GET['id'] ?? 0);
$message = '';
$error = '';
$admin_site_name = get_setting('site_title', SITE_NAME);

if ($library_id <= 0) {
    header('Location: keyword-libraries.php');
    exit;
}

// 获取关键词库信息
$stmt = $db->prepare("SELECT * FROM keyword_libraries WHERE id = ?");
$stmt->execute([$library_id]);
$library = $stmt->fetch();

if (!$library) {
    header('Location: keyword-libraries.php');
    exit;
}

// 处理POST请求
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
        $error = __('message.csrf_failed');
    } else {
        $action = $_POST['action'] ?? '';
        
        switch ($action) {
            case 'add_keyword':
                $keyword = trim($_POST['keyword'] ?? '');
                
                if (empty($keyword)) {
                    $error = __('keyword_detail.error.keyword_required');
                } else {
                    try {
                        $stmt = $db->prepare("
                            INSERT INTO keywords (library_id, keyword, created_at)
                            SELECT ?, ?, CURRENT_TIMESTAMP
                            WHERE NOT EXISTS (
                                SELECT 1 FROM keywords WHERE library_id = ? AND keyword = ?
                            )
                        ");
                        
                        if ($stmt->execute([$library_id, $keyword, $library_id, $keyword]) && $stmt->rowCount() > 0) {
                            refresh_keyword_library_count($db, $library_id);
                            $message = __('keyword_detail.message.add_success');
                        } else {
                            $error = __('keyword_detail.error.keyword_exists');
                        }
                    } catch (Exception $e) {
                        $error = __('keyword_libraries.message.create_error', ['message' => $e->getMessage()]);
                    }
                }
                break;
                
            case 'delete_keywords':
                $keyword_ids = $_POST['keyword_ids'] ?? [];
                
                if (!empty($keyword_ids)) {
                    try {
                        $placeholders = str_repeat('?,', count($keyword_ids) - 1) . '?';
                        $stmt = $db->prepare("DELETE FROM keywords WHERE id IN ($placeholders) AND library_id = ?");
                        $params = array_merge($keyword_ids, [$library_id]);
                        
                        if ($stmt->execute($params)) {
                            refresh_keyword_library_count($db, $library_id);
                            $message = __('keyword_detail.message.delete_success', ['count' => count($keyword_ids)]);
                        } else {
                            $error = __('keyword_libraries.message.delete_error', ['message' => '']);
                        }
                    } catch (Exception $e) {
                        $error = __('keyword_libraries.message.delete_error', ['message' => $e->getMessage()]);
                    }
                }
                break;
                
            case 'update_library':
                $name = trim($_POST['name'] ?? '');
                $description = trim($_POST['description'] ?? '');
                
                if (empty($name)) {
                    $error = __('keyword_detail.error.library_name_required');
                } else {
                    try {
                        $stmt = $db->prepare("
                            UPDATE keyword_libraries 
                            SET name = ?, description = ?, updated_at = CURRENT_TIMESTAMP 
                            WHERE id = ?
                        ");
                        
                        if ($stmt->execute([$name, $description, $library_id])) {
                            $library['name'] = $name;
                            $library['description'] = $description;
                            $message = __('keyword_detail.message.update_success');
                        } else {
                            $error = __('keyword_libraries.message.create_error', ['message' => '']);
                        }
                    } catch (Exception $e) {
                        $error = __('keyword_libraries.message.create_error', ['message' => $e->getMessage()]);
                    }
                }
                break;
        }
    }
}

// 获取筛选参数
$search = trim($_GET['search'] ?? '');
$page = max(1, intval($_GET['page'] ?? 1));
$per_page = 50;

// 构建查询条件
$where_conditions = ['library_id = ?'];
$params = [$library_id];

if (!empty($search)) {
    $where_conditions[] = 'keyword LIKE ?';
    $params[] = "%{$search}%";
}

$where_clause = implode(' AND ', $where_conditions);

// 获取关键词总数
$count_sql = "SELECT COUNT(*) as total FROM keywords WHERE {$where_clause}";
$stmt = $db->prepare($count_sql);
$stmt->execute($params);
$total_keywords = $stmt->fetch()['total'];
$total_pages = ceil($total_keywords / $per_page);

// 获取关键词列表
$offset = ($page - 1) * $per_page;
$sql = "
    SELECT * FROM keywords 
    WHERE {$where_clause}
    ORDER BY created_at DESC
    LIMIT {$per_page} OFFSET {$offset}
";

$stmt = $db->prepare($sql);
$stmt->execute($params);
$keywords = $stmt->fetchAll();

// 获取使用统计
$usage_stats = $db->prepare("
    SELECT COUNT(*) as usage_count 
    FROM articles 
    WHERE original_keyword IN (SELECT keyword FROM keywords WHERE library_id = ?)
");
$usage_stats->execute([$library_id]);
$usage_count = $usage_stats->fetch()['usage_count'];
?>
<!DOCTYPE html>
<html lang="<?php echo htmlspecialchars(app_html_lang()); ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($library['name']); ?><?php echo htmlspecialchars(__('keyword_detail.page_title_suffix')); ?> - <?php echo htmlspecialchars($admin_site_name); ?></title>
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
                    <a href="dashboard.php" class="text-xl font-semibold text-gray-900"><?php echo htmlspecialchars($admin_site_name); ?></a>
                    <nav class="flex space-x-8">
                        <a href="dashboard.php" class="text-gray-500 hover:text-gray-700"><?php echo __('keyword_detail.home'); ?></a>
                        <a href="tasks.php" class="text-gray-500 hover:text-gray-700"><?php echo __('keyword_detail.tasks'); ?></a>
                        <a href="articles.php" class="text-gray-500 hover:text-gray-700"><?php echo __('keyword_detail.articles'); ?></a>
                        <a href="materials.php" class="text-blue-600 font-medium"><?php echo __('keyword_detail.materials'); ?></a>
                        <a href="ai-configurator.php" class="text-gray-500 hover:text-gray-700"><?php echo __('keyword_detail.ai_config'); ?></a>
                        <a href="site-settings.php" class="text-gray-500 hover:text-gray-700"><?php echo __('keyword_detail.site_settings'); ?></a>
                        <a href="security-settings.php" class="text-gray-500 hover:text-gray-700"><?php echo __('keyword_detail.security'); ?></a>
                    </nav>
                </div>
                <div class="flex items-center space-x-4">
                    <span class="text-sm text-gray-600"><?php echo __('keyword_detail.welcome', ['name' => $_SESSION['admin_username']]); ?></span>
                    <a href="logout.php" class="text-sm text-red-600 hover:text-red-800"><?php echo __('keyword_detail.logout'); ?></a>
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
            <div class="flex items-center justify-between">
                <div class="flex items-center space-x-4">
                    <a href="keyword-libraries.php" class="text-gray-400 hover:text-gray-600">
                        <i data-lucide="arrow-left" class="w-5 h-5"></i>
                    </a>
                    <div>
                        <h1 class="text-2xl font-bold text-gray-900"><?php echo htmlspecialchars($library['name']); ?></h1>
                        <p class="mt-1 text-sm text-gray-600"><?php echo htmlspecialchars($library['description'] ?: __('keyword_detail.no_description')); ?></p>
                    </div>
                </div>
                <div class="flex space-x-2">
                    <button onclick="showEditModal()" class="inline-flex items-center px-3 py-1.5 border border-gray-300 text-sm font-medium rounded text-gray-700 bg-white hover:bg-gray-50">
                        <i data-lucide="edit" class="w-4 h-4 mr-1"></i>
                        <?php echo __('keyword_detail.edit_info'); ?>
                    </button>
                    <button onclick="showAddModal()" class="inline-flex items-center px-4 py-2 border border-transparent text-sm font-medium rounded-md text-white bg-blue-600 hover:bg-blue-700">
                        <i data-lucide="plus" class="w-4 h-4 mr-2"></i>
                        <?php echo __('keyword_detail.add_keyword'); ?>
                    </button>
                </div>
            </div>
        </div>

        <!-- 统计信息 -->
        <div class="grid grid-cols-1 md:grid-cols-4 gap-6 mb-8">
            <div class="bg-white overflow-hidden shadow rounded-lg">
                <div class="p-5">
                    <div class="flex items-center">
                        <div class="flex-shrink-0">
                            <i data-lucide="key" class="h-6 w-6 text-blue-600"></i>
                        </div>
                        <div class="ml-5 w-0 flex-1">
                            <dl>
                                <dt class="text-sm font-medium text-gray-500 truncate"><?php echo __('keyword_detail.total_keywords'); ?></dt>
                                <dd class="text-lg font-medium text-gray-900"><?php echo $total_keywords; ?></dd>
                            </dl>
                        </div>
                    </div>
                </div>
            </div>

            <div class="bg-white overflow-hidden shadow rounded-lg">
                <div class="p-5">
                    <div class="flex items-center">
                        <div class="flex-shrink-0">
                            <i data-lucide="trending-up" class="h-6 w-6 text-green-600"></i>
                        </div>
                        <div class="ml-5 w-0 flex-1">
                            <dl>
                                <dt class="text-sm font-medium text-gray-500 truncate"><?php echo __('keyword_detail.usage_total'); ?></dt>
                                <dd class="text-lg font-medium text-gray-900"><?php echo $usage_count; ?></dd>
                            </dl>
                        </div>
                    </div>
                </div>
            </div>

            <div class="bg-white overflow-hidden shadow rounded-lg">
                <div class="p-5">
                    <div class="flex items-center">
                        <div class="flex-shrink-0">
                            <i data-lucide="calendar" class="h-6 w-6 text-purple-600"></i>
                        </div>
                        <div class="ml-5 w-0 flex-1">
                            <dl>
                                <dt class="text-sm font-medium text-gray-500 truncate"><?php echo __('keyword_detail.created_date'); ?></dt>
                                <dd class="text-lg font-medium text-gray-900"><?php echo date('m-d', strtotime($library['created_at'])); ?></dd>
                            </dl>
                        </div>
                    </div>
                </div>
            </div>

            <div class="bg-white overflow-hidden shadow rounded-lg">
                <div class="p-5">
                    <div class="flex items-center">
                        <div class="flex-shrink-0">
                            <i data-lucide="clock" class="h-6 w-6 text-orange-600"></i>
                        </div>
                        <div class="ml-5 w-0 flex-1">
                            <dl>
                                <dt class="text-sm font-medium text-gray-500 truncate"><?php echo __('keyword_detail.updated_date'); ?></dt>
                                <dd class="text-lg font-medium text-gray-900"><?php echo date('m-d', strtotime($library['updated_at'])); ?></dd>
                            </dl>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- 搜索和操作 -->
        <div class="bg-white shadow rounded-lg mb-6">
            <div class="px-6 py-4">
                <div class="flex items-center justify-between">
                    <form method="GET" class="flex items-center space-x-4">
                        <input type="hidden" name="id" value="<?php echo $library_id; ?>">
                        <div class="flex-1">
                            <input type="text" name="search" value="<?php echo htmlspecialchars($search); ?>"
                                   placeholder="<?php echo htmlspecialchars(__('keyword_detail.search_placeholder')); ?>"
                                   class="block w-full border-gray-300 rounded-md shadow-sm focus:ring-blue-500 focus:border-blue-500 sm:text-sm">
                        </div>
                        <button type="submit" class="inline-flex items-center px-4 py-2 border border-transparent text-sm font-medium rounded-md text-white bg-blue-600 hover:bg-blue-700">
                            <i data-lucide="search" class="w-4 h-4 mr-2"></i>
                            <?php echo __('button.search'); ?>
                        </button>
                        <a href="keyword-library-detail.php?id=<?php echo $library_id; ?>" class="inline-flex items-center px-4 py-2 border border-gray-300 text-sm font-medium rounded-md text-gray-700 bg-white hover:bg-gray-50">
                            <i data-lucide="x" class="w-4 h-4 mr-2"></i>
                            <?php echo __('button.clear'); ?>
                        </a>
                    </form>
                    
                    <div class="flex space-x-2">
                        <button onclick="toggleBatchActions()" class="inline-flex items-center px-3 py-1.5 border border-gray-300 text-xs font-medium rounded text-gray-700 bg-white hover:bg-gray-50">
                            <i data-lucide="check-square" class="w-4 h-4 mr-1"></i>
                            <?php echo __('keyword_detail.batch_actions'); ?>
                        </button>
                    </div>
                </div>
            </div>
        </div>

        <!-- 关键词列表 -->
        <div class="bg-white shadow rounded-lg">
            <div class="px-6 py-4 border-b border-gray-200">
                <div class="flex items-center justify-between">
                    <h3 class="text-lg font-medium text-gray-900">
                        <?php echo __('keyword_detail.list_title'); ?> 
                        <span class="text-sm text-gray-500"><?php echo __('keyword_detail.list_total', ['count' => $total_keywords]); ?></span>
                    </h3>
                </div>
            </div>

            <?php if (empty($keywords)): ?>
                <div class="px-6 py-8 text-center">
                    <i data-lucide="search" class="w-12 h-12 mx-auto text-gray-400 mb-4"></i>
                    <h3 class="text-lg font-medium text-gray-900 mb-2"><?php echo __('keyword_detail.empty'); ?></h3>
                    <p class="text-gray-500 mb-4">
                        <?php echo !empty($search) ? __('keyword_detail.empty_search') : __('keyword_detail.empty_desc'); ?>
                    </p>
                    <?php if (empty($search)): ?>
                        <button onclick="showAddModal()" class="inline-flex items-center px-4 py-2 border border-transparent text-sm font-medium rounded-md text-white bg-blue-600 hover:bg-blue-700">
                            <i data-lucide="plus" class="w-4 h-4 mr-2"></i>
                            <?php echo __('keyword_detail.add_keyword'); ?>
                        </button>
                    <?php endif; ?>
                </div>
            <?php else: ?>
                <!-- 批量操作栏 -->
                <div id="batch-actions" class="hidden px-6 py-3 bg-gray-50 border-b border-gray-200">
                    <form method="POST" id="batch-form">
                        <input type="hidden" name="csrf_token" value="<?php echo generate_csrf_token(); ?>">
                        <input type="hidden" name="action" value="delete_keywords">
                        <div class="flex items-center space-x-4">
                            <span class="text-sm text-gray-600"><?php echo __('keyword_detail.selected_count', ['count' => '<span id="selected-count">0</span>']); ?></span>
                            
                            <button type="submit" class="inline-flex items-center px-3 py-1.5 border border-transparent text-xs font-medium rounded text-white bg-red-600 hover:bg-red-700">
                                <i data-lucide="trash-2" class="w-4 h-4 mr-1"></i>
                                <?php echo __('keyword_detail.delete_selected'); ?>
                            </button>
                            
                            <button type="button" onclick="toggleBatchActions()" class="inline-flex items-center px-3 py-1.5 border border-gray-300 text-xs font-medium rounded text-gray-700 bg-white hover:bg-gray-50">
                                <?php echo __('button.cancel'); ?>
                            </button>
                        </div>
                    </form>
                </div>

                <div class="px-6 py-4">
                    <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4 gap-3">
                        <?php foreach ($keywords as $keyword): ?>
                            <div class="flex items-center justify-between p-3 border border-gray-200 rounded-lg hover:bg-gray-50">
                                <div class="flex items-center space-x-2">
                                    <input type="checkbox" name="keyword_ids[]" value="<?php echo $keyword['id']; ?>" class="keyword-checkbox hidden rounded border-gray-300 text-blue-600 shadow-sm focus:border-blue-300 focus:ring focus:ring-blue-200 focus:ring-opacity-50">
                                    <span class="text-sm text-gray-900"><?php echo htmlspecialchars($keyword['keyword']); ?></span>
                                </div>
                                <button onclick="deleteKeyword(<?php echo $keyword['id']; ?>, '<?php echo htmlspecialchars($keyword['keyword']); ?>')" class="text-red-600 hover:text-red-800 opacity-0 group-hover:opacity-100 transition-opacity">
                                    <i data-lucide="x" class="w-4 h-4"></i>
                                </button>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>

                <!-- 分页 -->
                <?php if ($total_pages > 1): ?>
                    <div class="px-6 py-4 border-t border-gray-200">
                        <div class="flex items-center justify-between">
                            <div class="text-sm text-gray-700">
                                <?php echo __('keyword_detail.pagination', ['start' => (($page - 1) * $per_page + 1), 'end' => min($page * $per_page, $total_keywords), 'total' => $total_keywords]); ?>
                            </div>
                            <div class="flex space-x-1">
                                <?php if ($page > 1): ?>
                                    <a href="?<?php echo http_build_query(array_merge($_GET, ['page' => $page - 1])); ?>" class="px-3 py-2 text-sm font-medium text-gray-500 bg-white border border-gray-300 rounded-md hover:bg-gray-50">
                                        <?php echo __('button.previous'); ?>
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
                                        <?php echo __('button.next'); ?>
                                    </a>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                <?php endif; ?>
            <?php endif; ?>
        </div>
    </div>

    <!-- 添加关键词模态框 -->
    <div id="add-modal" class="hidden fixed inset-0 bg-gray-600 bg-opacity-50 overflow-y-auto h-full w-full z-50">
        <div class="relative top-20 mx-auto p-5 border w-96 shadow-lg rounded-md bg-white">
            <div class="mt-3">
                <h3 class="text-lg font-medium text-gray-900 mb-4"><?php echo __('keyword_detail.modal_add'); ?></h3>
                <form method="POST">
                    <input type="hidden" name="csrf_token" value="<?php echo generate_csrf_token(); ?>">
                    <input type="hidden" name="action" value="add_keyword">
                    
                    <div class="space-y-4">
                        <div>
                            <label class="block text-sm font-medium text-gray-700"><?php echo __('keyword_detail.field_keyword'); ?></label>
                            <input type="text" name="keyword" required 
                                   class="mt-1 block w-full border-gray-300 rounded-md shadow-sm focus:ring-blue-500 focus:border-blue-500 sm:text-sm"
                                   placeholder="<?php echo htmlspecialchars(__('keyword_detail.placeholder_keyword')); ?>">
                        </div>
                    </div>
                    
                    <div class="mt-6 flex justify-end space-x-3">
                        <button type="button" onclick="hideAddModal()" class="px-4 py-2 border border-gray-300 rounded-md text-sm font-medium text-gray-700 hover:bg-gray-50">
                            <?php echo __('button.cancel'); ?>
                        </button>
                        <button type="submit" class="px-4 py-2 border border-transparent rounded-md shadow-sm text-sm font-medium text-white bg-blue-600 hover:bg-blue-700">
                            <?php echo __('button.add'); ?>
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- 编辑库信息模态框 -->
    <div id="edit-modal" class="hidden fixed inset-0 bg-gray-600 bg-opacity-50 overflow-y-auto h-full w-full z-50">
        <div class="relative top-20 mx-auto p-5 border w-96 shadow-lg rounded-md bg-white">
            <div class="mt-3">
                <h3 class="text-lg font-medium text-gray-900 mb-4"><?php echo __('keyword_detail.modal_edit'); ?></h3>
                <form method="POST">
                    <input type="hidden" name="csrf_token" value="<?php echo generate_csrf_token(); ?>">
                    <input type="hidden" name="action" value="update_library">
                    
                    <div class="space-y-4">
                        <div>
                            <label class="block text-sm font-medium text-gray-700"><?php echo __('keyword_detail.field_name'); ?></label>
                            <input type="text" name="name" required 
                                   value="<?php echo htmlspecialchars($library['name']); ?>"
                                   class="mt-1 block w-full border-gray-300 rounded-md shadow-sm focus:ring-blue-500 focus:border-blue-500 sm:text-sm">
                        </div>
                        
                        <div>
                            <label class="block text-sm font-medium text-gray-700"><?php echo __('keyword_detail.field_description'); ?></label>
                            <textarea name="description" rows="3"
                                      class="mt-1 block w-full border-gray-300 rounded-md shadow-sm focus:ring-blue-500 focus:border-blue-500 sm:text-sm"><?php echo htmlspecialchars($library['description']); ?></textarea>
                        </div>
                    </div>
                    
                    <div class="mt-6 flex justify-end space-x-3">
                        <button type="button" onclick="hideEditModal()" class="px-4 py-2 border border-gray-300 rounded-md text-sm font-medium text-gray-700 hover:bg-gray-50">
                            <?php echo __('button.cancel'); ?>
                        </button>
                        <button type="submit" class="px-4 py-2 border border-transparent rounded-md shadow-sm text-sm font-medium text-white bg-blue-600 hover:bg-blue-700">
                            <?php echo __('button.save'); ?>
                        </button>
                    </div>
                </form>
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

        // 显示添加模态框
        function showAddModal() {
            document.getElementById('add-modal').classList.remove('hidden');
        }

        // 隐藏添加模态框
        function hideAddModal() {
            document.getElementById('add-modal').classList.add('hidden');
        }

        // 显示编辑模态框
        function showEditModal() {
            document.getElementById('edit-modal').classList.remove('hidden');
        }

        // 隐藏编辑模态框
        function hideEditModal() {
            document.getElementById('edit-modal').classList.add('hidden');
        }

        // 批量操作功能
        function toggleBatchActions() {
            const batchActions = document.getElementById('batch-actions');
            const checkboxes = document.querySelectorAll('.keyword-checkbox');
            const isHidden = batchActions.classList.contains('hidden');
            
            if (isHidden) {
                batchActions.classList.remove('hidden');
                checkboxes.forEach(cb => cb.classList.remove('hidden'));
            } else {
                batchActions.classList.add('hidden');
                checkboxes.forEach(cb => cb.classList.add('hidden'));
                // 清除所有选择
                checkboxes.forEach(cb => cb.checked = false);
                updateSelectedCount();
            }
        }

        // 更新选中数量
        function updateSelectedCount() {
            const selected = document.querySelectorAll('.keyword-checkbox:checked').length;
            document.getElementById('selected-count').textContent = selected;
        }

        // 监听复选框变化
        document.querySelectorAll('.keyword-checkbox').forEach(cb => {
            cb.addEventListener('change', updateSelectedCount);
        });

        // 批量表单提交
        document.getElementById('batch-form').addEventListener('submit', function(e) {
            const selected = document.querySelectorAll('.keyword-checkbox:checked').length;
            if (selected === 0) {
                e.preventDefault();
                alert('<?php echo __('keyword_detail.error.select_required'); ?>');
                return;
            }
            
            if (!confirm(`<?php echo __('keyword_detail.confirm_delete_selected', ['count' => '{count}']); ?>`.replace('{count}', selected))) {
                e.preventDefault();
                return;
            }
        });

        // 删除单个关键词
        function deleteKeyword(keywordId, keyword) {
            if (confirm(`<?php echo __('keyword_detail.confirm_delete_keyword', ['name' => '{name}']); ?>`.replace('{name}', keyword))) {
                const form = document.createElement('form');
                form.method = 'POST';
                form.innerHTML = `
                    <input type="hidden" name="csrf_token" value="<?php echo generate_csrf_token(); ?>">
                    <input type="hidden" name="action" value="delete_keywords">
                    <input type="hidden" name="keyword_ids[]" value="${keywordId}">
                `;
                document.body.appendChild(form);
                form.submit();
            }
        }

        // 点击模态框外部关闭
        window.onclick = function(event) {
            const addModal = document.getElementById('add-modal');
            const editModal = document.getElementById('edit-modal');
            
            if (event.target === addModal) {
                hideAddModal();
            }
            if (event.target === editModal) {
                hideEditModal();
            }
        }
    </script>
</body>
</html>
