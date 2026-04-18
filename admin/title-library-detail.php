<?php
/**
 * 智能GEO内容系统 - 标题库详情页
 *
 * @author 姚金刚
 * @version 1.0
 * @date 2025-10-08
 */

define('FEISHU_TREASURE', true);
session_start();

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/database_admin.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/includes/material-library-helpers.php';

// 检查管理员登录
require_admin_login();

$csrf_token = generate_csrf_token();
$success_message = '';
$error_message = '';

// 立即释放session锁，允许其他页面并发访问
session_write_close();

// 检查是否有库ID参数
if (!isset($_GET['id']) || !is_numeric($_GET['id'])) {
    header('Location: title-libraries.php');
    exit;
}

$library_id = (int)$_GET['id'];

try {
    // 获取标题库信息
    $stmt = $db->prepare("SELECT * FROM title_libraries WHERE id = ?");
    $stmt->execute([$library_id]);
    $library = $stmt->fetch();
    
    if (!$library) {
        header('Location: title-libraries.php');
        exit;
    }
    
    // 处理表单提交
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
            throw new Exception(__('title_detail.error.csrf'));
        }
        
        $action = $_POST['action'] ?? '';
        $db->beginTransaction();
        
        if ($action === 'add_title') {
            $title = trim($_POST['title'] ?? '');
            $keyword = trim($_POST['keyword'] ?? '');
            
            if (empty($title)) {
                throw new Exception(__('title_detail.error.title_required'));
            }
            
            // 检查标题是否已存在
            $stmt = $db->prepare("SELECT COUNT(*) FROM titles WHERE library_id = ? AND title = ?");
            $stmt->execute([$library_id, $title]);
            if ($stmt->fetchColumn() > 0) {
                throw new Exception(__('title_detail.error.title_exists'));
            }
            
            // 添加标题
            $stmt = $db->prepare("INSERT INTO titles (library_id, title, keyword) VALUES (?, ?, ?)");
            $stmt->execute([$library_id, $title, $keyword]);
            refresh_title_library_count($db, $library_id);
            
            $success_message = __('title_detail.message.add_success');
            
        } elseif ($action === 'delete_title') {
            $title_id = (int)($_POST['title_id'] ?? 0);
            
            $stmt = $db->prepare("DELETE FROM titles WHERE id = ? AND library_id = ?");
            $stmt->execute([$title_id, $library_id]);
            refresh_title_library_count($db, $library_id);
            
            $success_message = __('title_detail.message.delete_success');
            
        } elseif ($action === 'import_titles') {
            $titles_text = trim($_POST['titles_text'] ?? '');
            
            if (empty($titles_text)) {
                throw new Exception(__('title_detail.error.content_required'));
            }
            
            // 解析标题
            $lines = explode("\n", $titles_text);
            $imported_count = 0;
            $duplicate_count = 0;
            
            foreach ($lines as $line) {
                $line = trim($line);
                if (empty($line)) continue;
                
                // 支持 "标题|关键词" 格式
                $parts = explode('|', $line, 2);
                $title = trim($parts[0]);
                $keyword = isset($parts[1]) ? trim($parts[1]) : '';
                
                if (empty($title)) continue;
                
                // 检查是否已存在
                $stmt = $db->prepare("SELECT COUNT(*) FROM titles WHERE library_id = ? AND title = ?");
                $stmt->execute([$library_id, $title]);
                if ($stmt->fetchColumn() > 0) {
                    $duplicate_count++;
                    continue;
                }
                
                // 添加标题
                $stmt = $db->prepare("INSERT INTO titles (library_id, title, keyword) VALUES (?, ?, ?)");
                $stmt->execute([$library_id, $title, $keyword]);
                $imported_count++;
            }

            refresh_title_library_count($db, $library_id);

            $success_message = __('title_detail.message.import_success', ['count' => $imported_count]) . ($duplicate_count > 0 ? __('title_detail.message.import_skip', ['count' => $duplicate_count]) : '');
        }

        if ($db->inTransaction()) {
            $db->commit();
        }
    }
    
} catch (Exception $e) {
    if ($db->inTransaction()) {
        $db->rollBack();
    }
    $error_message = $e->getMessage();
}

// 获取标题列表
$page = (int)($_GET['page'] ?? 1);
$per_page = 20;
$offset = ($page - 1) * $per_page;

$stmt = $db->prepare("SELECT COUNT(*) FROM titles WHERE library_id = ?");
$stmt->execute([$library_id]);
$total_titles = $stmt->fetchColumn();

$stmt = $db->prepare("SELECT * FROM titles WHERE library_id = ? ORDER BY created_at DESC LIMIT ? OFFSET ?");
$stmt->execute([$library_id, $per_page, $offset]);
$titles = $stmt->fetchAll();

$total_pages = ceil($total_titles / $per_page);

// 设置页面信息
$page_title = $library['name'] . __('title_detail.page_title_suffix');
$page_header = '
<div class="flex items-center justify-between">
    <div class="flex items-center space-x-4">
        <a href="title-libraries.php" class="text-gray-400 hover:text-gray-600">
            <i data-lucide="arrow-left" class="w-5 h-5"></i>
        </a>
        <div>
            <h1 class="text-2xl font-bold text-gray-900">' . htmlspecialchars($library['name']) . '</h1>
            <p class="mt-1 text-sm text-gray-600">' . __('title_detail.subtitle') . '</p>
        </div>
    </div>
    <div class="flex space-x-2">
        <button onclick="showImportModal()" class="inline-flex items-center px-4 py-2 border border-gray-300 text-sm font-medium rounded-md text-gray-700 bg-white hover:bg-gray-50">
            <i data-lucide="upload" class="w-4 h-4 mr-2"></i>
            ' . __('title_detail.import_batch') . '
        </button>
        <button onclick="showAddModal()" class="inline-flex items-center px-4 py-2 border border-transparent text-sm font-medium rounded-md text-white bg-green-600 hover:bg-green-700">
            <i data-lucide="plus" class="w-4 h-4 mr-2"></i>
            ' . __('title_detail.add_title') . '
        </button>
        <a href="title-library-ai-generate.php?id=' . $library_id . '" class="inline-flex items-center px-4 py-2 border border-transparent text-sm font-medium rounded-md text-white bg-blue-600 hover:bg-blue-700">
            <i data-lucide="zap" class="w-4 h-4 mr-2"></i>
            ' . __('title_detail.ai_generate') . '
        </a>
    </div>
</div>';

require_once __DIR__ . '/includes/header.php';
?>

        <?php if ($success_message !== ''): ?>
            <div class="mb-6 bg-green-50 border border-green-200 rounded-md p-4 text-green-700">
                <?php echo htmlspecialchars($success_message); ?>
            </div>
        <?php endif; ?>

        <?php if ($error_message !== ''): ?>
            <div class="mb-6 bg-red-50 border border-red-200 rounded-md p-4 text-red-700">
                <?php echo htmlspecialchars($error_message); ?>
            </div>
        <?php endif; ?>

        <!-- 统计信息 -->
        <div class="grid grid-cols-1 md:grid-cols-3 gap-6 mb-8">
            <div class="bg-white overflow-hidden shadow rounded-lg">
                <div class="p-5">
                    <div class="flex items-center">
                        <div class="flex-shrink-0">
                            <i data-lucide="list" class="h-6 w-6 text-green-600"></i>
                        </div>
                        <div class="ml-5 w-0 flex-1">
                            <dl>
                                <dt class="text-sm font-medium text-gray-500 truncate"><?php echo __('title_detail.total_titles'); ?></dt>
                                <dd class="text-lg font-medium text-gray-900"><?php echo $total_titles; ?></dd>
                            </dl>
                        </div>
                    </div>
                </div>
            </div>

            <div class="bg-white overflow-hidden shadow rounded-lg">
                <div class="p-5">
                    <div class="flex items-center">
                        <div class="flex-shrink-0">
                            <i data-lucide="calendar" class="h-6 w-6 text-blue-600"></i>
                        </div>
                        <div class="ml-5 w-0 flex-1">
                            <dl>
                                <dt class="text-sm font-medium text-gray-500 truncate"><?php echo __('title_detail.created_date'); ?></dt>
                                <dd class="text-lg font-medium text-gray-900"><?php echo date('Y-m-d', strtotime($library['created_at'])); ?></dd>
                            </dl>
                        </div>
                    </div>
                </div>
            </div>

            <div class="bg-white overflow-hidden shadow rounded-lg">
                <div class="p-5">
                    <div class="flex items-center">
                        <div class="flex-shrink-0">
                            <i data-lucide="trending-up" class="h-6 w-6 text-purple-600"></i>
                        </div>
                        <div class="ml-5 w-0 flex-1">
                            <dl>
                                <dt class="text-sm font-medium text-gray-500 truncate"><?php echo __('title_detail.usage_total'); ?></dt>
                                <dd class="text-lg font-medium text-gray-900"><?php echo array_sum(array_column($titles, 'used_count')); ?></dd>
                            </dl>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- 标题列表 -->
        <div class="bg-white shadow rounded-lg">
            <div class="px-6 py-4 border-b border-gray-200">
                <h3 class="text-lg font-medium text-gray-900"><?php echo __('title_detail.list_title'); ?></h3>
            </div>

            <?php if (empty($titles)): ?>
                <div class="px-6 py-8 text-center">
                    <i data-lucide="list" class="w-12 h-12 mx-auto text-gray-400 mb-4"></i>
                    <h3 class="text-lg font-medium text-gray-900 mb-2"><?php echo __('title_detail.empty'); ?></h3>
                    <p class="text-gray-500 mb-4"><?php echo __('title_detail.empty_desc'); ?></p>
                    <div class="flex justify-center space-x-2">
                        <button onclick="showAddModal()" class="inline-flex items-center px-4 py-2 border border-transparent text-sm font-medium rounded-md text-white bg-green-600 hover:bg-green-700">
                            <i data-lucide="plus" class="w-4 h-4 mr-2"></i>
                            <?php echo __('title_detail.add_title'); ?>
                        </button>
                        <button onclick="showImportModal()" class="inline-flex items-center px-4 py-2 border border-gray-300 text-sm font-medium rounded-md text-gray-700 bg-white hover:bg-gray-50">
                            <i data-lucide="upload" class="w-4 h-4 mr-2"></i>
                            <?php echo __('title_detail.import_batch'); ?>
                        </button>
                    </div>
                </div>
            <?php else: ?>
                <div class="divide-y divide-gray-200">
                    <?php foreach ($titles as $title): ?>
                        <div class="px-6 py-4">
                            <div class="flex items-center justify-between">
                                <div class="flex-1">
                                    <div class="flex items-center space-x-3">
                                        <h4 class="text-lg font-medium text-gray-900">
                                            <?php echo htmlspecialchars($title['title']); ?>
                                        </h4>
                                        <?php if ($title['is_ai_generated']): ?>
                                            <span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium bg-blue-100 text-blue-800">
                                                <i data-lucide="zap" class="w-3 h-3 mr-1"></i>
                                                <?php echo __('title_detail.ai_badge'); ?>
                                            </span>
                                        <?php endif; ?>
                                        <?php if (!empty($title['keyword'])): ?>
                                            <span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium bg-gray-100 text-gray-800">
                                                <?php echo htmlspecialchars($title['keyword']); ?>
                                            </span>
                                        <?php endif; ?>
                                    </div>
                                    <div class="mt-2 flex items-center space-x-4 text-sm text-gray-500">
                                        <span><?php echo __('title_detail.usage_count', ['count' => $title['used_count']]); ?></span>
                                        <span><?php echo __('title_detail.created_at', ['value' => date('Y-m-d H:i', strtotime($title['created_at']))]); ?></span>
                                    </div>
                                </div>
                                
                                <div class="flex items-center space-x-2">
                                    <button onclick="deleteTitle(<?php echo $title['id']; ?>, '<?php echo htmlspecialchars($title['title']); ?>')" class="inline-flex items-center px-3 py-1.5 border border-transparent text-xs font-medium rounded text-white bg-red-600 hover:bg-red-700">
                                        <i data-lucide="trash-2" class="w-4 h-4 mr-1"></i>
                                        <?php echo __('button.delete'); ?>
                                    </button>
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
                                <?php echo __('title_detail.pagination', ['start' => ($offset + 1), 'end' => min($offset + $per_page, $total_titles), 'total' => $total_titles]); ?>
                            </div>
                            <div class="flex space-x-2">
                                <?php if ($page > 1): ?>
                                    <a href="?id=<?php echo $library_id; ?>&page=<?php echo $page - 1; ?>" class="px-3 py-2 border border-gray-300 text-sm font-medium rounded-md text-gray-700 bg-white hover:bg-gray-50">
                                        <?php echo __('button.previous'); ?>
                                    </a>
                                <?php endif; ?>
                                
                                <?php for ($i = max(1, $page - 2); $i <= min($total_pages, $page + 2); $i++): ?>
                                    <a href="?id=<?php echo $library_id; ?>&page=<?php echo $i; ?>" class="px-3 py-2 border text-sm font-medium rounded-md <?php echo $i === $page ? 'border-blue-500 bg-blue-50 text-blue-600' : 'border-gray-300 text-gray-700 bg-white hover:bg-gray-50'; ?>">
                                        <?php echo $i; ?>
                                    </a>
                                <?php endfor; ?>
                                
                                <?php if ($page < $total_pages): ?>
                                    <a href="?id=<?php echo $library_id; ?>&page=<?php echo $page + 1; ?>" class="px-3 py-2 border border-gray-300 text-sm font-medium rounded-md text-gray-700 bg-white hover:bg-gray-50">
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

    <!-- 添加标题模态框 -->
    <div id="add-modal" class="hidden fixed inset-0 bg-gray-600 bg-opacity-50 overflow-y-auto h-full w-full z-50">
        <div class="relative top-20 mx-auto p-5 border w-96 shadow-lg rounded-md bg-white">
            <div class="mt-3">
                <h3 class="text-lg font-medium text-gray-900 mb-4"><?php echo __('title_detail.modal_add'); ?></h3>
                <form method="POST">
                    <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                    <input type="hidden" name="action" value="add_title">

                    <div class="space-y-4">
                        <div>
                            <label class="block text-sm font-medium text-gray-700"><?php echo __('title_detail.field_title'); ?></label>
                            <input type="text" name="title" required
                                   class="mt-1 block w-full border-gray-300 rounded-md shadow-sm focus:ring-green-500 focus:border-green-500 sm:text-sm"
                                   placeholder="<?php echo htmlspecialchars(__('title_detail.placeholder_title')); ?>">
                        </div>

                        <div>
                            <label class="block text-sm font-medium text-gray-700"><?php echo __('title_detail.field_keyword'); ?></label>
                            <input type="text" name="keyword"
                                   class="mt-1 block w-full border-gray-300 rounded-md shadow-sm focus:ring-green-500 focus:border-green-500 sm:text-sm"
                                   placeholder="<?php echo htmlspecialchars(__('title_detail.placeholder_keyword')); ?>">
                        </div>
                    </div>

                    <div class="mt-6 flex justify-end space-x-3">
                        <button type="button" onclick="hideAddModal()" class="px-4 py-2 border border-gray-300 rounded-md text-sm font-medium text-gray-700 hover:bg-gray-50">
                            <?php echo __('button.cancel'); ?>
                        </button>
                        <button type="submit" class="px-4 py-2 border border-transparent rounded-md shadow-sm text-sm font-medium text-white bg-green-600 hover:bg-green-700">
                            <?php echo __('button.add'); ?>
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- 批量导入模态框 -->
    <div id="import-modal" class="hidden fixed inset-0 bg-gray-600 bg-opacity-50 overflow-y-auto h-full w-full z-50">
        <div class="relative top-10 mx-auto p-5 border w-2/3 max-w-2xl shadow-lg rounded-md bg-white">
            <div class="mt-3">
                <h3 class="text-lg font-medium text-gray-900 mb-4"><?php echo __('title_detail.modal_import'); ?></h3>
                <form method="POST">
                    <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                    <input type="hidden" name="action" value="import_titles">

                    <div class="space-y-4">
                        <div>
                            <label class="block text-sm font-medium text-gray-700"><?php echo __('title_detail.field_titles'); ?></label>
                            <textarea name="titles_text" rows="10" required
                                      class="mt-1 block w-full border-gray-300 rounded-md shadow-sm focus:ring-green-500 focus:border-green-500 sm:text-sm"
                                      placeholder="<?php echo htmlspecialchars(__('title_detail.placeholder_titles')); ?>"></textarea>
                        </div>

                        <div class="text-sm text-gray-500">
                            <p class="mb-2"><?php echo __('title_detail.import_format_title'); ?></p>
                            <ul class="list-disc list-inside space-y-1">
                                <li><?php echo __('title_detail.import_format_line'); ?></li>
                                <li><?php echo __('title_detail.import_format_pipe'); ?></li>
                                <li><?php echo __('title_detail.import_format_dedupe'); ?></li>
                            </ul>
                        </div>
                    </div>

                    <div class="mt-6 flex justify-end space-x-3">
                        <button type="button" onclick="hideImportModal()" class="px-4 py-2 border border-gray-300 rounded-md text-sm font-medium text-gray-700 hover:bg-gray-50">
                            <?php echo __('button.cancel'); ?>
                        </button>
                        <button type="submit" class="px-4 py-2 border border-transparent rounded-md shadow-sm text-sm font-medium text-white bg-green-600 hover:bg-green-700">
                            <?php echo __('title_detail.import_button'); ?>
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

        // 显示导入模态框
        function showImportModal() {
            document.getElementById('import-modal').classList.remove('hidden');
        }

        // 隐藏导入模态框
        function hideImportModal() {
            document.getElementById('import-modal').classList.add('hidden');
        }

        // 删除标题
        function deleteTitle(titleId, titleName) {
            if (confirm(`<?php echo __('title_detail.confirm_delete', ['name' => '{name}']); ?>`.replace('{name}', titleName))) {
                const form = document.createElement('form');
                form.method = 'POST';
                form.innerHTML = `
                    <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                    <input type="hidden" name="action" value="delete_title">
                    <input type="hidden" name="title_id" value="${titleId}">
                `;
                document.body.appendChild(form);
                form.submit();
            }
        }

        // 点击模态框外部关闭
        window.onclick = function(event) {
            const addModal = document.getElementById('add-modal');
            const importModal = document.getElementById('import-modal');

            if (event.target === addModal) {
                hideAddModal();
            }
            if (event.target === importModal) {
                hideImportModal();
            }
        }
    </script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
