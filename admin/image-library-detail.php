<?php
/**
 * 智能GEO内容系统 - 图片库详情
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
    header('Location: image-libraries.php');
    exit;
}

// 获取图片库信息
$stmt = $db->prepare("SELECT * FROM image_libraries WHERE id = ?");
$stmt->execute([$library_id]);
$library = $stmt->fetch();

if (!$library) {
    header('Location: image-libraries.php');
    exit;
}

// 处理POST请求
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
        $error = __('message.csrf_failed');
    } else {
        $action = $_POST['action'] ?? '';
        
        switch ($action) {
            case 'upload_images':
                if (!isset($_FILES['images']) || empty($_FILES['images']['name'][0])) {
                    $error = __('image_detail.error.select_images');
                } else {
                    try {
                        $db->beginTransaction();
                        $uploaded_count = 0;
                        $skipped_files = [];
                        $stored_paths = [];

                        foreach ($_FILES['images']['name'] as $key => $name) {
                            $stored = null;
                            $file = [
                                'name' => $_FILES['images']['name'][$key] ?? '',
                                'type' => $_FILES['images']['type'][$key] ?? '',
                                'tmp_name' => $_FILES['images']['tmp_name'][$key] ?? '',
                                'error' => $_FILES['images']['error'][$key] ?? UPLOAD_ERR_NO_FILE,
                                'size' => $_FILES['images']['size'][$key] ?? 0,
                            ];

                            try {
                                $stored = store_uploaded_image_file($file);
                                $stored_paths[] = $stored['file_path'];

                                $stmt = $db->prepare("
                                    INSERT INTO images (library_id, original_name, filename, file_name, file_path, file_size, mime_type, width, height, created_at)
                                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, CURRENT_TIMESTAMP)
                                ");
                                $stmt->execute([
                                    $library_id,
                                    $stored['original_name'],
                                    $stored['filename'],
                                    $stored['file_name'],
                                    $stored['file_path'],
                                    $stored['file_size'],
                                    $stored['mime_type'],
                                    $stored['width'],
                                    $stored['height'],
                                ]);
                                $uploaded_count++;
                            } catch (InvalidArgumentException $e) {
                                $skipped_files[] = $name . '：' . $e->getMessage();
                            } catch (Throwable $e) {
                                if (is_array($stored) && !empty($stored['absolute_path'])) {
                                    @unlink($stored['absolute_path']);
                                }
                                $skipped_files[] = $name . '：' . $e->getMessage();
                            }
                        }

                        refresh_image_library_count($db, $library_id);
                        $db->commit();

                        if ($uploaded_count > 0) {
                                $message = __('image_detail.message.upload_success', ['count' => $uploaded_count]);
                                if (!empty($skipped_files)) {
                                $message .= __('image_detail.message.upload_skipped', ['count' => count($skipped_files)]);
                                }
                        } else {
                            $error = !empty($skipped_files)
                                ? __('image_detail.error.upload_none') . implode('；', array_slice($skipped_files, 0, 3))
                                : __('image_detail.error.upload_none');
                        }
                    } catch (Throwable $e) {
                        if ($db->inTransaction()) {
                            $db->rollBack();
                        }
                        delete_material_files($stored_paths ?? []);
                        $error = __('image_detail.message.upload_error', ['message' => $e->getMessage()]);
                    }
                }
                break;
                
            case 'delete_images':
                $image_ids = $_POST['image_ids'] ?? [];
                
                if (!empty($image_ids)) {
                    try {
                        $db->beginTransaction();
                        
                        // 获取要删除的图片文件路径
                        $placeholders = str_repeat('?,', count($image_ids) - 1) . '?';
                        $stmt = $db->prepare("SELECT file_path FROM images WHERE id IN ($placeholders) AND library_id = ?");
                        $params = array_merge($image_ids, [$library_id]);
                        $stmt->execute($params);
                        $images = $stmt->fetchAll();

                        if (empty($images)) {
                            throw new RuntimeException(__('image_detail.error.no_records'));
                        }

                        // 先清理文章图片关联，避免外键约束阻止删除
                        $unlinkStmt = $db->prepare("DELETE FROM article_images WHERE image_id IN ($placeholders)");
                        $unlinkStmt->execute($image_ids);
                        
                        // 删除数据库记录
                        $stmt = $db->prepare("DELETE FROM images WHERE id IN ($placeholders) AND library_id = ?");
                        $stmt->execute($params);
                        refresh_image_library_count($db, $library_id);
                        $db->commit();

                        $failedFiles = delete_material_files(array_column($images, 'file_path'));
                        $deletedCount = count($images);
                        $message = __('image_detail.message.delete_success', ['count' => $deletedCount]);
                        if (!empty($failedFiles)) {
                            $message .= __('image_detail.message.delete_cleanup_partial', ['count' => count($failedFiles)]);
                        }
                    } catch (Throwable $e) {
                        if ($db->inTransaction()) {
                            $db->rollBack();
                        }
                        $error = __('image_detail.message.delete_error', ['message' => $e->getMessage()]);
                    }
                }
                break;
                
            case 'update_library':
                $name = trim($_POST['name'] ?? '');
                $description = trim($_POST['description'] ?? '');
                
                if (empty($name)) {
                    $error = __('image_libraries.error.name_required');
                } else {
                    try {
                        $stmt = $db->prepare("
                            UPDATE image_libraries 
                            SET name = ?, description = ?, updated_at = CURRENT_TIMESTAMP 
                            WHERE id = ?
                        ");
                        
                        if ($stmt->execute([$name, $description, $library_id])) {
                            $library['name'] = $name;
                            $library['description'] = $description;
                            $message = __('image_detail.message.update_success');
                        } else {
                            $error = __('image_detail.message.update_failed');
                        }
                    } catch (Exception $e) {
                        $error = __('image_detail.message.update_error', ['message' => $e->getMessage()]);
                    }
                }
                break;
        }
    }
}

// 获取筛选参数
$search = trim($_GET['search'] ?? '');
$page = max(1, intval($_GET['page'] ?? 1));
$per_page = 24; // 图片展示用较大的分页

// 构建查询条件
$where_conditions = ['library_id = ?'];
$params = [$library_id];

if (!empty($search)) {
    $where_conditions[] = 'original_name LIKE ?';
    $params[] = "%{$search}%";
}

$where_clause = implode(' AND ', $where_conditions);

// 获取图片总数
$count_sql = "SELECT COUNT(*) as total FROM images WHERE {$where_clause}";
$stmt = $db->prepare($count_sql);
$stmt->execute($params);
$total_images = $stmt->fetch()['total'];
$total_pages = ceil($total_images / $per_page);

// 获取图片列表
$offset = ($page - 1) * $per_page;
$sql = "
    SELECT * FROM images 
    WHERE {$where_clause}
    ORDER BY created_at DESC
    LIMIT {$per_page} OFFSET {$offset}
";

$stmt = $db->prepare($sql);
$stmt->execute($params);
$images = $stmt->fetchAll();

// 获取使用统计
$usage_stats = $db->prepare("
    SELECT COUNT(*) as usage_count 
    FROM article_images ai
    JOIN images i ON ai.image_id = i.id
    WHERE i.library_id = ?
");
$usage_stats->execute([$library_id]);
$usage_count = $usage_stats->fetch()['usage_count'];

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
?>
<!DOCTYPE html>
<html lang="<?php echo htmlspecialchars(app_html_lang()); ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($library['name']); ?> - <?php echo __('image_detail.page_title'); ?> - <?php echo htmlspecialchars($admin_site_name); ?></title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/lucide/0.263.1/lucide.min.css">
    <script src="https://unpkg.com/lucide@latest/dist/umd/lucide.js"></script>
    <style>
        .image-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(200px, 1fr));
            gap: 1rem;
        }
        .image-item {
            aspect-ratio: 1;
            position: relative;
            overflow: hidden;
            border-radius: 0.5rem;
            border: 2px solid transparent;
            transition: all 0.2s;
        }
        .image-item:hover {
            border-color: #8b5cf6;
            transform: scale(1.02);
        }
        .image-item.selected {
            border-color: #8b5cf6;
            box-shadow: 0 0 0 2px rgba(139, 92, 246, 0.2);
        }
        .image-item img {
            width: 100%;
            height: 100%;
            object-fit: cover;
        }
        .image-overlay {
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: rgba(0, 0, 0, 0.7);
            color: white;
            display: flex;
            flex-direction: column;
            justify-content: center;
            align-items: center;
            opacity: 0;
            transition: opacity 0.2s;
        }
        .image-item:hover .image-overlay {
            opacity: 1;
        }
        .upload-area {
            border: 2px dashed #d1d5db;
            border-radius: 0.5rem;
            padding: 2rem;
            text-align: center;
            transition: all 0.2s;
        }
        .upload-area.dragover {
            border-color: #8b5cf6;
            background-color: #f3f4f6;
        }
    </style>
</head>
<body class="bg-gray-50">
    <!-- 导航栏 -->
    <nav class="bg-white shadow-sm border-b">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
            <div class="flex justify-between h-16">
                <div class="flex items-center space-x-8">
                    <a href="dashboard.php" class="text-xl font-semibold text-gray-900"><?php echo htmlspecialchars($admin_site_name); ?></a>
                    <nav class="flex space-x-8">
                        <a href="dashboard.php" class="text-gray-500 hover:text-gray-700"><?php echo __('nav.dashboard'); ?></a>
                        <a href="tasks.php" class="text-gray-500 hover:text-gray-700"><?php echo __('nav.tasks'); ?></a>
                        <a href="articles.php" class="text-gray-500 hover:text-gray-700"><?php echo __('nav.articles'); ?></a>
                        <a href="materials.php" class="text-blue-600 font-medium"><?php echo __('nav.materials'); ?></a>
                        <a href="ai-configurator.php" class="text-gray-500 hover:text-gray-700"><?php echo __('nav.ai_config'); ?></a>
                        <a href="site-settings.php" class="text-gray-500 hover:text-gray-700"><?php echo __('nav.site_settings'); ?></a>
                        <a href="security-settings.php" class="text-gray-500 hover:text-gray-700"><?php echo __('nav.security'); ?></a>
                    </nav>
                </div>
                <div class="flex items-center space-x-4">
                    <span class="text-sm text-gray-600"><?php echo __('header.welcome'); ?>，<?php echo $_SESSION['admin_username']; ?></span>
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
            <div class="flex items-center justify-between">
                <div class="flex items-center space-x-4">
                    <a href="image-libraries.php" class="text-gray-400 hover:text-gray-600">
                        <i data-lucide="arrow-left" class="w-5 h-5"></i>
                    </a>
                    <div>
                        <h1 class="text-2xl font-bold text-gray-900"><?php echo htmlspecialchars($library['name']); ?></h1>
                        <p class="mt-1 text-sm text-gray-600"><?php echo htmlspecialchars($library['description'] ?: __('common.none_desc')); ?></p>
                    </div>
                </div>
                <div class="flex space-x-2">
                    <button onclick="showEditModal()" class="inline-flex items-center px-3 py-1.5 border border-gray-300 text-sm font-medium rounded text-gray-700 bg-white hover:bg-gray-50">
                        <i data-lucide="edit" class="w-4 h-4 mr-1"></i>
                        <?php echo __('button.edit'); ?>
                    </button>
                    <button onclick="showUploadModal()" class="inline-flex items-center px-4 py-2 border border-transparent text-sm font-medium rounded-md text-white bg-purple-600 hover:bg-purple-700">
                        <i data-lucide="upload" class="w-4 h-4 mr-2"></i>
                        <?php echo __('button.upload'); ?>
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
                            <i data-lucide="image" class="h-6 w-6 text-purple-600"></i>
                        </div>
                        <div class="ml-5 w-0 flex-1">
                            <dl>
                                <dt class="text-sm font-medium text-gray-500 truncate"><?php echo __('image_detail.total_images'); ?></dt>
                                <dd class="text-lg font-medium text-gray-900"><?php echo $total_images; ?></dd>
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
                                <dt class="text-sm font-medium text-gray-500 truncate"><?php echo __('common.usage'); ?></dt>
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
                            <i data-lucide="calendar" class="h-6 w-6 text-blue-600"></i>
                        </div>
                        <div class="ml-5 w-0 flex-1">
                            <dl>
                                <dt class="text-sm font-medium text-gray-500 truncate"><?php echo __('common.created_at'); ?></dt>
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
                                <dt class="text-sm font-medium text-gray-500 truncate"><?php echo __('common.updated_at'); ?></dt>
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
                                   placeholder="<?php echo htmlspecialchars(__('image_detail.search_placeholder')); ?>"
                                   class="block w-full border-gray-300 rounded-md shadow-sm focus:ring-purple-500 focus:border-purple-500 sm:text-sm">
                        </div>
                        <button type="submit" class="inline-flex items-center px-4 py-2 border border-transparent text-sm font-medium rounded-md text-white bg-purple-600 hover:bg-purple-700">
                            <i data-lucide="search" class="w-4 h-4 mr-2"></i>
                            <?php echo __('button.search'); ?>
                        </button>
                        <a href="image-library-detail.php?id=<?php echo $library_id; ?>" class="inline-flex items-center px-4 py-2 border border-gray-300 text-sm font-medium rounded-md text-gray-700 bg-white hover:bg-gray-50">
                            <i data-lucide="x" class="w-4 h-4 mr-2"></i>
                            <?php echo __('button.clear'); ?>
                        </a>
                    </form>
                    
                    <div class="flex space-x-2">
                        <button onclick="toggleBatchActions()" class="inline-flex items-center px-3 py-1.5 border border-gray-300 text-xs font-medium rounded text-gray-700 bg-white hover:bg-gray-50">
                            <i data-lucide="check-square" class="w-4 h-4 mr-1"></i>
                            <?php echo __('button.bulk_actions'); ?>
                        </button>
                    </div>
                </div>
            </div>
        </div>

        <!-- 图片列表 -->
        <div class="bg-white shadow rounded-lg">
            <div class="px-6 py-4 border-b border-gray-200">
                <div class="flex items-center justify-between">
                    <h3 class="text-lg font-medium text-gray-900">
                        <?php echo __('image_detail.list_title'); ?>
                        <span class="text-sm text-gray-500">(<?php echo __('image_detail.total_images_count', ['count' => $total_images]); ?>)</span>
                    </h3>
                </div>
            </div>

            <?php if (empty($images)): ?>
                <div class="px-6 py-8 text-center">
                    <i data-lucide="image" class="w-12 h-12 mx-auto text-gray-400 mb-4"></i>
                    <h3 class="text-lg font-medium text-gray-900 mb-2"><?php echo __('image_detail.empty'); ?></h3>
                    <p class="text-gray-500 mb-4">
                        <?php echo !empty($search) ? __('image_detail.empty_search') : __('image_detail.empty_desc'); ?>
                    </p>
                    <?php if (empty($search)): ?>
                        <button onclick="showUploadModal()" class="inline-flex items-center px-4 py-2 border border-transparent text-sm font-medium rounded-md text-white bg-purple-600 hover:bg-purple-700">
                            <i data-lucide="upload" class="w-4 h-4 mr-2"></i>
                            <?php echo __('button.upload'); ?>
                        </button>
                    <?php endif; ?>
                </div>
            <?php else: ?>
                <!-- 批量操作栏 -->
                <div id="batch-actions" class="hidden px-6 py-3 bg-gray-50 border-b border-gray-200">
                    <form method="POST" id="batch-form">
                        <input type="hidden" name="csrf_token" value="<?php echo generate_csrf_token(); ?>">
                        <input type="hidden" name="action" value="delete_images">
                        <div id="selected-image-ids"></div>
                        <div class="flex items-center space-x-4">
                            <span class="text-sm text-gray-600"><?php echo __('image_detail.selected_count', ['count' => '<span id="selected-count">0</span>']); ?></span>
                            
                            <button type="submit" class="inline-flex items-center px-3 py-1.5 border border-transparent text-xs font-medium rounded text-white bg-red-600 hover:bg-red-700">
                                <i data-lucide="trash-2" class="w-4 h-4 mr-1"></i>
                                <?php echo __('image_detail.delete_selected'); ?>
                            </button>
                            
                            <button type="button" onclick="toggleBatchActions()" class="inline-flex items-center px-3 py-1.5 border border-gray-300 text-xs font-medium rounded text-gray-700 bg-white hover:bg-gray-50">
                                <?php echo __('button.cancel'); ?>
                            </button>
                        </div>
                    </form>
                </div>

                <div class="p-6">
                    <div class="image-grid">
                        <?php foreach ($images as $image): ?>
                            <div class="image-item" data-image-id="<?php echo $image['id']; ?>">
                                <input type="checkbox" name="image_ids[]" value="<?php echo $image['id']; ?>" class="image-checkbox hidden absolute top-2 left-2 rounded border-gray-300 text-purple-600 shadow-sm focus:border-purple-300 focus:ring focus:ring-purple-200 focus:ring-opacity-50 z-10">
                                <img src="../<?php echo htmlspecialchars($image['file_path']); ?>" 
                                     alt="<?php echo htmlspecialchars($image['original_name']); ?>"
                                     onclick="showImageModal('<?php echo htmlspecialchars($image['file_path']); ?>', '<?php echo htmlspecialchars($image['original_name']); ?>', '<?php echo $image['width']; ?>x<?php echo $image['height']; ?>', '<?php echo formatFileSize($image['file_size']); ?>')">
                                <div class="image-overlay">
                                    <p class="text-xs text-center mb-2"><?php echo htmlspecialchars($image['original_name']); ?></p>
                                    <p class="text-xs text-gray-300"><?php echo $image['width']; ?>×<?php echo $image['height']; ?></p>
                                    <p class="text-xs text-gray-300"><?php echo formatFileSize($image['file_size']); ?></p>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>

                <!-- 分页 -->
                <?php if ($total_pages > 1): ?>
                    <div class="px-6 py-4 border-t border-gray-200">
                        <div class="flex items-center justify-between">
                            <div class="text-sm text-gray-700">
                                <?php echo __('image_detail.pagination_summary', ['from' => ($page - 1) * $per_page + 1, 'to' => min($page * $per_page, $total_images), 'total' => $total_images]); ?>
                            </div>
                            <div class="flex space-x-1">
                                <?php if ($page > 1): ?>
                                    <a href="?<?php echo http_build_query(array_merge($_GET, ['page' => $page - 1])); ?>" class="px-3 py-2 text-sm font-medium text-gray-500 bg-white border border-gray-300 rounded-md hover:bg-gray-50">
                                        <?php echo __('button.previous'); ?>
                                    </a>
                                <?php endif; ?>
                                
                                <?php for ($i = max(1, $page - 2); $i <= min($total_pages, $page + 2); $i++): ?>
                                    <a href="?<?php echo http_build_query(array_merge($_GET, ['page' => $i])); ?>" 
                                       class="px-3 py-2 text-sm font-medium <?php echo $i === $page ? 'text-purple-600 bg-purple-50 border-purple-500' : 'text-gray-500 bg-white border-gray-300'; ?> border rounded-md hover:bg-gray-50">
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

    <!-- 上传图片模态框 -->
    <div id="upload-modal" class="hidden fixed inset-0 bg-gray-600 bg-opacity-50 overflow-y-auto h-full w-full z-50">
        <div class="relative top-10 mx-auto p-5 border w-2/3 max-w-2xl shadow-lg rounded-md bg-white">
            <div class="mt-3">
                <h3 class="text-lg font-medium text-gray-900 mb-4"><?php echo __('image_detail.modal_upload', ['name' => htmlspecialchars($library['name'])]); ?></h3>
                <form method="POST" enctype="multipart/form-data" id="upload-form">
                    <input type="hidden" name="csrf_token" value="<?php echo generate_csrf_token(); ?>">
                    <input type="hidden" name="action" value="upload_images">
                    
                    <div class="space-y-4">
                        <div class="upload-area cursor-pointer" id="upload-area" role="button" tabindex="0" aria-controls="images" aria-label="<?php echo htmlspecialchars(__('image_detail.upload_hint')); ?>">
                            <input type="file" name="images[]" id="images" multiple accept="image/*" class="hidden">
                            <div class="upload-content">
                                <i data-lucide="upload-cloud" class="w-12 h-12 mx-auto text-gray-400 mb-4"></i>
                                <p class="text-lg font-medium text-gray-900 mb-2"><?php echo __('image_detail.upload_hint'); ?></p>
                                <p class="text-sm text-gray-500 mb-4"><?php echo __('image_detail.upload_formats'); ?></p>
                                <button type="button" id="trigger-image-picker" class="inline-flex items-center px-4 py-2 border border-transparent text-sm font-medium rounded-md text-white bg-purple-600 hover:bg-purple-700">
                                    <i data-lucide="folder-open" class="w-4 h-4 mr-2"></i>
                                    <?php echo __('image_detail.select_images'); ?>
                                </button>
                            </div>
                        </div>
                        
                        <div id="file-list" class="hidden">
                            <h4 class="text-sm font-medium text-gray-900 mb-2"><?php echo __('image_detail.selected_files'); ?></h4>
                            <div id="file-items" class="space-y-2"></div>
                        </div>
                    </div>
                    
                    <div class="mt-6 flex justify-end space-x-3">
                        <button type="button" onclick="hideUploadModal()" class="px-4 py-2 border border-gray-300 rounded-md text-sm font-medium text-gray-700 hover:bg-gray-50">
                            <?php echo __('button.cancel'); ?>
                        </button>
                        <button type="submit" id="upload-btn" disabled class="px-4 py-2 border border-transparent rounded-md shadow-sm text-sm font-medium text-white bg-purple-600 hover:bg-purple-700 disabled:bg-gray-400 disabled:cursor-not-allowed">
                            <i data-lucide="upload" class="w-4 h-4 mr-2 inline"></i>
                            <?php echo __('button.upload'); ?>
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
                <h3 class="text-lg font-medium text-gray-900 mb-4"><?php echo __('image_detail.modal_edit'); ?></h3>
                <form method="POST">
                    <input type="hidden" name="csrf_token" value="<?php echo generate_csrf_token(); ?>">
                    <input type="hidden" name="action" value="update_library">
                    
                    <div class="space-y-4">
                        <div>
                            <label class="block text-sm font-medium text-gray-700"><?php echo __('image_libraries.field_name'); ?></label>
                            <input type="text" name="name" required 
                                   value="<?php echo htmlspecialchars($library['name']); ?>"
                                   class="mt-1 block w-full border-gray-300 rounded-md shadow-sm focus:ring-purple-500 focus:border-purple-500 sm:text-sm">
                        </div>
                        
                        <div>
                            <label class="block text-sm font-medium text-gray-700"><?php echo __('common.description'); ?></label>
                            <textarea name="description" rows="3"
                                      class="mt-1 block w-full border-gray-300 rounded-md shadow-sm focus:ring-purple-500 focus:border-purple-500 sm:text-sm"><?php echo htmlspecialchars($library['description']); ?></textarea>
                        </div>
                    </div>
                    
                    <div class="mt-6 flex justify-end space-x-3">
                        <button type="button" onclick="hideEditModal()" class="px-4 py-2 border border-gray-300 rounded-md text-sm font-medium text-gray-700 hover:bg-gray-50">
                            <?php echo __('button.cancel'); ?>
                        </button>
                        <button type="submit" class="px-4 py-2 border border-transparent rounded-md shadow-sm text-sm font-medium text-white bg-purple-600 hover:bg-purple-700">
                            <?php echo __('button.save'); ?>
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- 图片预览模态框 -->
    <div id="image-modal" class="hidden fixed inset-0 bg-black bg-opacity-75 overflow-y-auto h-full w-full z-50">
        <div class="relative top-10 mx-auto p-5 w-4/5 max-w-4xl">
            <div class="bg-white rounded-lg overflow-hidden">
                <div class="px-6 py-4 border-b border-gray-200 flex items-center justify-between">
                    <h3 id="image-title" class="text-lg font-medium text-gray-900"></h3>
                    <button onclick="hideImageModal()" class="text-gray-400 hover:text-gray-600">
                        <i data-lucide="x" class="w-6 h-6"></i>
                    </button>
                </div>
                <div class="p-6 text-center">
                    <img id="image-preview" src="" alt="" class="max-w-full max-h-96 mx-auto rounded">
                    <div id="image-info" class="mt-4 text-sm text-gray-600"></div>
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

        // 显示上传模态框
        function showUploadModal() {
            document.getElementById('upload-modal').classList.remove('hidden');
        }

        // 隐藏上传模态框
        function hideUploadModal() {
            document.getElementById('upload-modal').classList.add('hidden');
            document.getElementById('upload-form').reset();
            document.getElementById('file-list').classList.add('hidden');
            document.getElementById('upload-btn').disabled = true;
        }

        // 显示编辑模态框
        function showEditModal() {
            document.getElementById('edit-modal').classList.remove('hidden');
        }

        // 隐藏编辑模态框
        function hideEditModal() {
            document.getElementById('edit-modal').classList.add('hidden');
        }

        // 显示图片预览模态框
        function showImageModal(path, name, dimensions, size) {
            document.getElementById('image-title').textContent = name;
            document.getElementById('image-preview').src = '../' + path;
            document.getElementById('image-preview').alt = name;
            document.getElementById('image-info').textContent = `<?php echo addslashes(__('image_detail.dimensions_label')); ?>: ${dimensions} | <?php echo addslashes(__('image_detail.size_label')); ?>: ${size}`;
            document.getElementById('image-modal').classList.remove('hidden');
        }

        // 隐藏图片预览模态框
        function hideImageModal() {
            document.getElementById('image-modal').classList.add('hidden');
        }

        // 批量操作功能
        function toggleBatchActions() {
            const batchActions = document.getElementById('batch-actions');
            const checkboxes = document.querySelectorAll('.image-checkbox');
            const isHidden = batchActions.classList.contains('hidden');
            
            if (isHidden) {
                batchActions.classList.remove('hidden');
                checkboxes.forEach(cb => cb.classList.remove('hidden'));
            } else {
                batchActions.classList.add('hidden');
                checkboxes.forEach(cb => {
                    cb.classList.add('hidden');
                    cb.checked = false;
                });
                // 清除选中状态
                document.querySelectorAll('.image-item').forEach(item => {
                    item.classList.remove('selected');
                });
                updateSelectedCount();
            }
        }

        // 更新选中数量
        function updateSelectedCount() {
            const selected = document.querySelectorAll('.image-checkbox:checked').length;
            document.getElementById('selected-count').textContent = selected;
        }

        // 监听复选框变化
        document.querySelectorAll('.image-checkbox').forEach(cb => {
            cb.addEventListener('change', function() {
                const imageItem = this.closest('.image-item');
                if (this.checked) {
                    imageItem.classList.add('selected');
                } else {
                    imageItem.classList.remove('selected');
                }
                updateSelectedCount();
            });
        });

        // 批量表单提交
        const batchForm = document.getElementById('batch-form');
        if (batchForm) {
            batchForm.addEventListener('submit', function(e) {
                const selected = document.querySelectorAll('.image-checkbox:checked').length;
                if (selected === 0) {
                    e.preventDefault();
                    alert('<?php echo addslashes(__('image_detail.error.select_delete')); ?>');
                    return;
                }

                const selectedIdsContainer = document.getElementById('selected-image-ids');
                selectedIdsContainer.innerHTML = '';

                document.querySelectorAll('.image-checkbox:checked').forEach(cb => {
                    const input = document.createElement('input');
                    input.type = 'hidden';
                    input.name = 'image_ids[]';
                    input.value = cb.value;
                    selectedIdsContainer.appendChild(input);
                });
                
                if (!confirm(`<?php echo addslashes(__('image_detail.confirm_delete_selected_prefix')); ?> ${selected} <?php echo addslashes(__('image_detail.confirm_delete_selected_suffix')); ?>`)) {
                    e.preventDefault();
                    return;
                }
            });
        }

        // 文件上传处理
        const uploadArea = document.getElementById('upload-area');
        const fileInput = document.getElementById('images');
        const fileList = document.getElementById('file-list');
        const fileItems = document.getElementById('file-items');
        const uploadBtn = document.getElementById('upload-btn');
        const uploadForm = document.getElementById('upload-form');
        const triggerImagePicker = document.getElementById('trigger-image-picker');

        function openFilePicker() {
            fileInput.click();
        }

        triggerImagePicker.addEventListener('click', function(e) {
            e.preventDefault();
            openFilePicker();
        });

        uploadArea.addEventListener('click', function(e) {
            if (e.target.closest('#trigger-image-picker')) {
                return;
            }
            openFilePicker();
        });

        uploadArea.addEventListener('keydown', function(e) {
            if (e.key === 'Enter' || e.key === ' ') {
                e.preventDefault();
                openFilePicker();
            }
        });

        function setSelectedFiles(files) {
            fileItems.innerHTML = '';

            const validFiles = Array.from(files).filter(file => file.type.startsWith('image/'));
            if (validFiles.length === 0) {
                fileList.classList.add('hidden');
                uploadBtn.disabled = true;
                return;
            }

            validFiles.forEach(file => {
                const fileItem = document.createElement('div');
                fileItem.className = 'flex items-center justify-between p-2 bg-gray-50 rounded';
                fileItem.innerHTML = `
                    <span class="text-sm text-gray-700">${file.name}</span>
                    <span class="text-xs text-gray-500">${formatFileSize(file.size)}</span>
                `;
                fileItems.appendChild(fileItem);
            });

            fileList.classList.remove('hidden');
            uploadBtn.disabled = false;
        }

        // 拖拽上传
        uploadArea.addEventListener('dragover', function(e) {
            e.preventDefault();
            this.classList.add('dragover');
        });

        uploadArea.addEventListener('dragleave', function(e) {
            e.preventDefault();
            this.classList.remove('dragover');
        });

        uploadArea.addEventListener('drop', function(e) {
            e.preventDefault();
            this.classList.remove('dragover');
            
            const files = e.dataTransfer.files;
            const transfer = new DataTransfer();
            Array.from(files).forEach(file => transfer.items.add(file));
            fileInput.files = transfer.files;
            setSelectedFiles(fileInput.files);
        });

        // 文件选择
        fileInput.addEventListener('change', function() {
            setSelectedFiles(this.files);
        });

        uploadForm.addEventListener('submit', function(e) {
            const selectedFiles = fileInput.files ? fileInput.files.length : 0;
            if (selectedFiles === 0) {
                e.preventDefault();
                alert('<?php echo addslashes(__('image_detail.error.select_images')); ?>');
                return;
            }

            uploadBtn.disabled = true;
            uploadBtn.innerHTML = '<i data-lucide="loader-2" class="w-4 h-4 mr-2 inline animate-spin"></i><?php echo addslashes(__('image_detail.uploading')); ?>';
            if (typeof lucide !== 'undefined') {
                lucide.createIcons();
            }
        });

        // 格式化文件大小
        function formatFileSize(bytes) {
            if (bytes >= 1048576) {
                return (bytes / 1048576).toFixed(2) + ' MB';
            } else if (bytes >= 1024) {
                return (bytes / 1024).toFixed(2) + ' KB';
            } else {
                return bytes + ' B';
            }
        }

        // 点击模态框外部关闭
        window.onclick = function(event) {
            const uploadModal = document.getElementById('upload-modal');
            const editModal = document.getElementById('edit-modal');
            const imageModal = document.getElementById('image-modal');
            
            if (event.target === uploadModal) {
                hideUploadModal();
            }
            if (event.target === editModal) {
                hideEditModal();
            }
            if (event.target === imageModal) {
                hideImageModal();
            }
        }
    </script>
</body>
</html>
