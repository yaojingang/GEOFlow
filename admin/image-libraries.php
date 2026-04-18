<?php
/**
 * 智能GEO内容系统 - 图片库管理
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

$message = '';
$error = '';

// 处理POST请求
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
        $error = __('message.csrf_failed');
    } else {
        $action = $_POST['action'] ?? '';
        
        switch ($action) {
            case 'create_library':
                $name = trim($_POST['name'] ?? '');
                $description = trim($_POST['description'] ?? '');
                
                if (empty($name)) {
                    $error = __('image_libraries.error.name_required');
                } else {
                    try {
                        $stmt = $db->prepare("
                            INSERT INTO image_libraries (name, description, image_count, created_at, updated_at) 
                            VALUES (?, ?, 0, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)
                        ");
                        
                        if ($stmt->execute([$name, $description])) {
                            $message = __('image_libraries.message.create_success');
                        } else {
                            $error = __('image_libraries.message.create_failed');
                        }
                    } catch (Exception $e) {
                        $error = __('image_libraries.message.create_error', ['message' => $e->getMessage()]);
                    }
                }
                break;
                
            case 'delete_library':
                $library_id = intval($_POST['library_id'] ?? 0);
                
                if ($library_id > 0) {
                    try {
                        $db->beginTransaction();
                        
                        // 获取图片库中的所有图片文件路径
                        $stmt = $db->prepare("SELECT file_path FROM images WHERE library_id = ?");
                        $stmt->execute([$library_id]);
                        $images = $stmt->fetchAll();
                        
                        // 删除图片库中的所有图片记录
                        $stmt = $db->prepare("DELETE FROM images WHERE library_id = ?");
                        $stmt->execute([$library_id]);
                        
                        // 删除图片库
                        $stmt = $db->prepare("DELETE FROM image_libraries WHERE id = ?");
                        $stmt->execute([$library_id]);
                        
                        $db->commit();

                        $failedFiles = delete_material_files(array_column($images, 'file_path'));
                        $message = __('image_libraries.message.delete_success');
                        if (!empty($failedFiles)) {
                            $message .= __('image_libraries.message.delete_cleanup_partial', ['count' => count($failedFiles)]);
                        }
                    } catch (Throwable $e) {
                        if ($db->inTransaction()) {
                            $db->rollBack();
                        }
                        $error = __('image_libraries.message.delete_error', ['message' => $e->getMessage()]);
                    }
                }
                break;
        }
    }
}

// 获取图片库列表
$libraries = $db->query("
    SELECT il.*, 
           (SELECT COUNT(*) FROM images WHERE library_id = il.id) as actual_count,
           (SELECT SUM(file_size) FROM images WHERE library_id = il.id) as total_size
    FROM image_libraries il 
    ORDER BY il.created_at DESC
")->fetchAll();

// 获取统计数据
$stats = [
    'total_libraries' => count($libraries),
    'total_images' => $db->query("SELECT COUNT(*) as count FROM images")->fetch()['count'],
    'total_size' => $db->query("SELECT SUM(file_size) as size FROM images")->fetch()['size'] ?? 0,
    'avg_images' => count($libraries) > 0 ? round($db->query("SELECT COUNT(*) as count FROM images")->fetch()['count'] / count($libraries), 1) : 0
];

// 格式化文件大小
function formatFileSize($bytes) {
    if ($bytes >= 1073741824) {
        return number_format($bytes / 1073741824, 2) . ' GB';
    } elseif ($bytes >= 1048576) {
        return number_format($bytes / 1048576, 2) . ' MB';
    } elseif ($bytes >= 1024) {
        return number_format($bytes / 1024, 2) . ' KB';
    } else {
        return $bytes . ' B';
    }
}

// 设置页面信息
$page_title = __('image_libraries.page_title');
$page_header = '
<div class="flex items-center justify-between">
    <div class="flex items-center space-x-4">
        <a href="materials.php" class="text-gray-400 hover:text-gray-600">
            <i data-lucide="arrow-left" class="w-5 h-5"></i>
        </a>
        <div>
            <h1 class="text-2xl font-bold text-gray-900">' . __('image_libraries.heading') . '</h1>
            <p class="mt-1 text-sm text-gray-600">' . __('image_libraries.subtitle') . '</p>
        </div>
    </div>
    <button onclick="showCreateModal()" class="inline-flex items-center px-4 py-2 border border-transparent text-sm font-medium rounded-md text-white bg-purple-600 hover:bg-purple-700">
        <i data-lucide="plus" class="w-4 h-4 mr-2"></i>
        ' . __('image_libraries.create') . '
    </button>
</div>
';

// 包含头部模块
require_once __DIR__ . '/includes/header.php';
?>

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



        <!-- 统计卡片 -->
        <div class="grid grid-cols-1 md:grid-cols-4 gap-6 mb-8">
            <div class="bg-white overflow-hidden shadow rounded-lg">
                <div class="p-5">
                    <div class="flex items-center">
                        <div class="flex-shrink-0">
                            <i data-lucide="folder" class="h-6 w-6 text-purple-600"></i>
                        </div>
                        <div class="ml-5 w-0 flex-1">
                            <dl>
                                <dt class="text-sm font-medium text-gray-500 truncate"><?php echo __('image_libraries.total'); ?></dt>
                                <dd class="text-lg font-medium text-gray-900"><?php echo $stats['total_libraries']; ?></dd>
                            </dl>
                        </div>
                    </div>
                </div>
            </div>

            <div class="bg-white overflow-hidden shadow rounded-lg">
                <div class="p-5">
                    <div class="flex items-center">
                        <div class="flex-shrink-0">
                            <i data-lucide="image" class="h-6 w-6 text-blue-600"></i>
                        </div>
                        <div class="ml-5 w-0 flex-1">
                            <dl>
                                <dt class="text-sm font-medium text-gray-500 truncate"><?php echo __('image_libraries.total_images'); ?></dt>
                                <dd class="text-lg font-medium text-gray-900"><?php echo $stats['total_images']; ?></dd>
                            </dl>
                        </div>
                    </div>
                </div>
            </div>

            <div class="bg-white overflow-hidden shadow rounded-lg">
                <div class="p-5">
                    <div class="flex items-center">
                        <div class="flex-shrink-0">
                            <i data-lucide="hard-drive" class="h-6 w-6 text-green-600"></i>
                        </div>
                        <div class="ml-5 w-0 flex-1">
                            <dl>
                                <dt class="text-sm font-medium text-gray-500 truncate"><?php echo __('image_libraries.storage'); ?></dt>
                                <dd class="text-lg font-medium text-gray-900"><?php echo formatFileSize($stats['total_size']); ?></dd>
                            </dl>
                        </div>
                    </div>
                </div>
            </div>

            <div class="bg-white overflow-hidden shadow rounded-lg">
                <div class="p-5">
                    <div class="flex items-center">
                        <div class="flex-shrink-0">
                            <i data-lucide="trending-up" class="h-6 w-6 text-orange-600"></i>
                        </div>
                        <div class="ml-5 w-0 flex-1">
                            <dl>
                                <dt class="text-sm font-medium text-gray-500 truncate"><?php echo __('common.avg_per_library'); ?></dt>
                                <dd class="text-lg font-medium text-gray-900"><?php echo $stats['avg_images']; ?></dd>
                            </dl>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- 图片库列表 -->
        <div class="bg-white shadow rounded-lg">
            <div class="px-6 py-4 border-b border-gray-200">
                <h3 class="text-lg font-medium text-gray-900"><?php echo __('image_libraries.list_title'); ?></h3>
            </div>

            <?php if (empty($libraries)): ?>
                <div class="px-6 py-8 text-center">
                    <i data-lucide="folder-plus" class="w-12 h-12 mx-auto text-gray-400 mb-4"></i>
                    <h3 class="text-lg font-medium text-gray-900 mb-2"><?php echo __('image_libraries.empty'); ?></h3>
                    <p class="text-gray-500 mb-4"><?php echo __('image_libraries.empty_desc'); ?></p>
                    <button onclick="showCreateModal()" class="inline-flex items-center px-4 py-2 border border-transparent text-sm font-medium rounded-md text-white bg-purple-600 hover:bg-purple-700">
                        <i data-lucide="plus" class="w-4 h-4 mr-2"></i>
                        <?php echo __('image_libraries.create'); ?>
                    </button>
                </div>
            <?php else: ?>
                <div class="divide-y divide-gray-200">
                    <?php foreach ($libraries as $library): ?>
                        <div class="px-6 py-6">
                            <div class="flex items-center justify-between">
                                <div class="flex-1">
                                    <div class="flex items-center space-x-3">
                                        <h4 class="text-lg font-medium text-gray-900">
                                            <a href="image-library-detail.php?id=<?php echo $library['id']; ?>" class="hover:text-purple-600">
                                                <?php echo htmlspecialchars($library['name']); ?>
                                            </a>
                                        </h4>
                                        <span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium bg-purple-100 text-purple-800">
                                            <?php echo __('image_libraries.image_count', ['count' => $library['actual_count']]); ?>
                                        </span>
                                        <?php if ($library['total_size'] > 0): ?>
                                            <span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium bg-green-100 text-green-800">
                                                <?php echo formatFileSize($library['total_size']); ?>
                                            </span>
                                        <?php endif; ?>
                                    </div>
                                    <?php if ($library['description']): ?>
                                        <p class="mt-1 text-sm text-gray-600"><?php echo htmlspecialchars($library['description']); ?></p>
                                    <?php endif; ?>
                                    <div class="mt-2 flex items-center space-x-4 text-sm text-gray-500">
                                        <span><?php echo __('image_libraries.created_at', ['value' => date('Y-m-d H:i', strtotime($library['created_at']))]); ?></span>
                                        <span><?php echo __('image_libraries.updated_at', ['value' => date('Y-m-d H:i', strtotime($library['updated_at']))]); ?></span>
                                    </div>
                                </div>
                                
                                <div class="flex items-center space-x-2">
                                    <a href="image-library-detail.php?id=<?php echo $library['id']; ?>" class="inline-flex items-center px-3 py-1.5 border border-transparent text-xs font-medium rounded text-white bg-blue-600 hover:bg-blue-700">
                                        <i data-lucide="upload" class="w-4 h-4 mr-1"></i>
                                        <?php echo __('image_libraries.upload_images'); ?>
                                    </a>
                                    <a href="image-library-detail.php?id=<?php echo $library['id']; ?>" class="inline-flex items-center px-3 py-1.5 border border-gray-300 text-xs font-medium rounded text-gray-700 bg-white hover:bg-gray-50">
                                        <i data-lucide="eye" class="w-4 h-4 mr-1"></i>
                                        <?php echo __('button.view'); ?>
                                    </a>
                                    <button onclick="deleteLibrary(<?php echo $library['id']; ?>, '<?php echo htmlspecialchars($library['name']); ?>')" class="inline-flex items-center px-3 py-1.5 border border-transparent text-xs font-medium rounded text-white bg-red-600 hover:bg-red-700">
                                        <i data-lucide="trash-2" class="w-4 h-4 mr-1"></i>
                                        <?php echo __('button.delete'); ?>
                                    </button>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- 创建图片库模态框 -->
    <div id="create-modal" class="hidden fixed inset-0 bg-gray-600 bg-opacity-50 overflow-y-auto h-full w-full z-50">
        <div class="relative top-20 mx-auto p-5 border w-96 shadow-lg rounded-md bg-white">
            <div class="mt-3">
                <h3 class="text-lg font-medium text-gray-900 mb-4"><?php echo __('image_libraries.modal_create'); ?></h3>
                <form method="POST">
                    <input type="hidden" name="csrf_token" value="<?php echo generate_csrf_token(); ?>">
                    <input type="hidden" name="action" value="create_library">
                    
                    <div class="space-y-4">
                        <div>
                            <label class="block text-sm font-medium text-gray-700"><?php echo __('image_libraries.field_name'); ?></label>
                            <input type="text" name="name" required 
                                   class="mt-1 block w-full border-gray-300 rounded-md shadow-sm focus:ring-purple-500 focus:border-purple-500 sm:text-sm"
                                   placeholder="<?php echo htmlspecialchars(__('image_libraries.placeholder_name')); ?>">
                        </div>
                        
                        <div>
                            <label class="block text-sm font-medium text-gray-700"><?php echo __('image_libraries.field_description'); ?></label>
                            <textarea name="description" rows="3"
                                      class="mt-1 block w-full border-gray-300 rounded-md shadow-sm focus:ring-purple-500 focus:border-purple-500 sm:text-sm"
                                      placeholder="<?php echo htmlspecialchars(__('image_libraries.placeholder_description')); ?>"></textarea>
                        </div>
                        
                        <div class="text-sm text-gray-500">
                            <p class="mb-2"><?php echo __('image_libraries.supported_formats'); ?></p>
                            <ul class="list-disc list-inside space-y-1">
                                <li>JPEG/JPG</li>
                                <li>PNG</li>
                                <li>GIF</li>
                                <li>WebP</li>
                            </ul>
                        </div>
                    </div>
                    
                    <div class="mt-6 flex justify-end space-x-3">
                        <button type="button" onclick="hideCreateModal()" class="px-4 py-2 border border-gray-300 rounded-md text-sm font-medium text-gray-700 hover:bg-gray-50">
                            <?php echo __('button.cancel'); ?>
                        </button>
                        <button type="submit" class="px-4 py-2 border border-transparent rounded-md shadow-sm text-sm font-medium text-white bg-purple-600 hover:bg-purple-700">
                            <?php echo __('button.create'); ?>
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

        // 显示创建模态框
        function showCreateModal() {
            document.getElementById('create-modal').classList.remove('hidden');
        }

        // 隐藏创建模态框
        function hideCreateModal() {
            document.getElementById('create-modal').classList.add('hidden');
        }

        // 删除图片库
        function deleteLibrary(libraryId, libraryName) {
            if (confirm(`<?php echo __('image_libraries.confirm_delete', ['name' => '{name}']); ?>`.replace('{name}', libraryName))) {
                const form = document.createElement('form');
                form.method = 'POST';
                form.innerHTML = `
                    <input type="hidden" name="csrf_token" value="<?php echo generate_csrf_token(); ?>">
                    <input type="hidden" name="action" value="delete_library">
                    <input type="hidden" name="library_id" value="${libraryId}">
                `;
                document.body.appendChild(form);
                form.submit();
            }
        }

        // 点击模态框外部关闭
        window.onclick = function(event) {
            const createModal = document.getElementById('create-modal');
            
            if (event.target === createModal) {
                hideCreateModal();
            }
        }
    </script>
</body>
</html>
