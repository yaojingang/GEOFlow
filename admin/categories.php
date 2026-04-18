<?php
/**
 * 智能GEO内容系统 - 分类管理
 */

define('FEISHU_TREASURE', true);
session_start();

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/database_admin.php';

// 检查管理员登录
require_admin_login();

// 立即释放session锁，允许其他页面并发访问
session_write_close();

// 设置页面标题
$page_title = __('categories.page_title');

$action = $_GET['action'] ?? 'list';
$id = intval($_GET['id'] ?? 0);

$message = '';
$error = '';

function build_category_slug(PDO $db, string $name, string $raw_slug = '', int $exclude_id = 0): string
{
    $source = trim($raw_slug) !== '' ? trim($raw_slug) : trim($name);

    if (function_exists('iconv')) {
        $transliterated = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $source);
        if ($transliterated !== false && trim($transliterated) !== '') {
            $source = $transliterated;
        }
    }

    $slug = strtolower($source);
    $slug = preg_replace('/[^a-z0-9]+/', '-', $slug);
    $slug = preg_replace('/-+/', '-', $slug);
    $slug = trim($slug, '-');

    if ($slug === '') {
        $slug = 'cat-' . substr(md5($name), 0, 8);
    }

    $base_slug = $slug;
    $counter = 2;

    while (true) {
        if ($exclude_id > 0) {
            $stmt = $db->prepare("SELECT COUNT(*) FROM categories WHERE slug = ? AND id != ?");
            $stmt->execute([$slug, $exclude_id]);
        } else {
            $stmt = $db->prepare("SELECT COUNT(*) FROM categories WHERE slug = ?");
            $stmt->execute([$slug]);
        }

        if ((int) $stmt->fetchColumn() === 0) {
            return $slug;
        }

        $slug = $base_slug . '-' . $counter;
        $counter++;
    }
}

// 处理POST请求
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
        $error = __('message.csrf_failed');
    } else {
        $post_action = $_POST['action'] ?? '';
        
        switch ($post_action) {
            case 'add':
            case 'edit':
                $name = trim($_POST['name'] ?? '');
                $slug = trim($_POST['slug'] ?? '');
                $description = trim($_POST['description'] ?? '');
                $sort_order = intval($_POST['sort_order'] ?? 0);
                
                if (empty($name)) {
                    $error = __('categories.error.name_required');
                } else {
                    try {
                        $slug = build_category_slug($db, $name, $slug, $post_action === 'edit' ? $id : 0);
                        
                        // 检查名称和slug是否重复
                        if ($post_action === 'edit') {
                            $check_stmt = $db->prepare("SELECT COUNT(*) FROM categories WHERE name = ? AND id != ?");
                            $check_stmt->execute([$name, $id]);
                        } else {
                            $check_stmt = $db->prepare("SELECT COUNT(*) FROM categories WHERE name = ?");
                            $check_stmt->execute([$name]);
                        }
                        
                        if ($check_stmt->fetchColumn() > 0) {
                            $error = __('categories.error.name_exists');
                        } else {
                            if ($post_action === 'add') {
                                $stmt = $db->prepare("
                                    INSERT INTO categories (name, slug, description, sort_order, created_at)
                                    VALUES (?, ?, ?, ?, CURRENT_TIMESTAMP)
                                ");
                                $stmt->execute([$name, $slug, $description, $sort_order]);
                                $message = __('categories.message.add_success');
                            } else {
                                $stmt = $db->prepare("
                                    UPDATE categories 
                                    SET name = ?, slug = ?, description = ?, sort_order = ?
                                    WHERE id = ?
                                ");
                                $stmt->execute([$name, $slug, $description, $sort_order, $id]);
                                $message = __('categories.message.update_success');
                            }
                            
                            // 重定向到列表页面
                            header('Location: categories.php?message=' . urlencode($message));
                            exit;
                        }
                    } catch (Exception $e) {
                        $error = __('categories.message.action_failed', ['message' => $e->getMessage()]);
                    }
                }
                break;
                
            case 'delete':
                $delete_id = intval($_POST['id'] ?? 0);
                if ($delete_id > 0) {
                    try {
                        // 检查是否有关联的文章
                        $stmt = $db->prepare("SELECT COUNT(*) FROM articles WHERE category_id = ?");
                        $stmt->execute([$delete_id]);
                        $article_count = $stmt->fetchColumn();
                        
                        if ($article_count > 0) {
                            $error = __('categories.error.delete_blocked', ['count' => $article_count]);
                        } else {
                            $stmt = $db->prepare("DELETE FROM categories WHERE id = ?");
                            $stmt->execute([$delete_id]);
                            $message = __('categories.message.delete_success');
                            
                            header('Location: categories.php?message=' . urlencode($message));
                            exit;
                        }
                    } catch (Exception $e) {
                        $error = __('categories.message.delete_failed', ['message' => $e->getMessage()]);
                    }
                }
                break;
        }
    }
}

// 获取URL参数中的消息
if (isset($_GET['message'])) {
    $message = $_GET['message'];
}

// 获取分类列表
try {
    $categories = $db->query("
        SELECT c.*, 
               COUNT(a.id) as article_count
        FROM categories c
        LEFT JOIN articles a ON c.id = a.category_id
        GROUP BY c.id
        ORDER BY c.sort_order ASC, c.name ASC
    ")->fetchAll();
} catch (Exception $e) {
    $categories = [];
    $error = __('categories.error.fetch_list_failed', ['message' => $e->getMessage()]);
}

// 获取编辑数据
$category_data = [];
if ($action === 'edit' && $id > 0) {
    try {
        $stmt = $db->prepare("SELECT * FROM categories WHERE id = ?");
        $stmt->execute([$id]);
        $category_data = $stmt->fetch();
        if (!$category_data) {
            $error = __('categories.error.not_found');
            $action = 'list';
        }
    } catch (Exception $e) {
        $error = __('categories.error.fetch_detail_failed', ['message' => $e->getMessage()]);
        $action = 'list';
    }
}

// 包含统一头部
require_once __DIR__ . '/includes/header.php';
?>

            <!-- 页面标题 -->
            <div class="mb-8">
                <div class="flex items-center justify-between">
                    <div>
                        <h1 class="text-2xl font-bold text-gray-900"><?php echo __('categories.heading'); ?></h1>
                        <p class="mt-1 text-sm text-gray-600"><?php echo __('categories.subtitle'); ?></p>
                    </div>
                    <div class="flex space-x-3">
                        <?php if ($action !== 'add' && $action !== 'edit'): ?>
                            <a href="categories.php?action=add" class="inline-flex items-center px-4 py-2 border border-transparent text-sm font-medium rounded-md text-white bg-blue-600 hover:bg-blue-700">
                                <i data-lucide="plus" class="w-4 h-4 mr-2"></i>
                                <?php echo __('categories.add'); ?>
                            </a>
                        <?php endif; ?>
                        <a href="articles.php" class="inline-flex items-center px-4 py-2 border border-gray-300 text-sm font-medium rounded-md text-gray-700 bg-white hover:bg-gray-50">
                            <i data-lucide="arrow-left" class="w-4 h-4 mr-2"></i>
                            <?php echo __('categories.back_to_articles'); ?>
                        </a>
                    </div>
                </div>
            </div>

            <!-- 消息提示 -->
            <?php if (!empty($message)): ?>
                <div class="mb-6 p-4 bg-green-50 border border-green-200 rounded-lg">
                    <div class="flex items-center">
                        <i data-lucide="check-circle" class="w-5 h-5 text-green-500 mr-2"></i>
                        <span class="text-green-700"><?php echo htmlspecialchars($message); ?></span>
                    </div>
                </div>
            <?php endif; ?>

            <?php if (!empty($error)): ?>
                <div class="mb-6 p-4 bg-red-50 border border-red-200 rounded-lg">
                    <div class="flex items-center">
                        <i data-lucide="alert-circle" class="w-5 h-5 text-red-500 mr-2"></i>
                        <span class="text-red-700"><?php echo htmlspecialchars($error); ?></span>
                    </div>
                </div>
            <?php endif; ?>

            <?php if ($action === 'add' || $action === 'edit'): ?>
                <!-- 添加/编辑分类表单 -->
                <div class="bg-white shadow rounded-lg">
                    <div class="px-6 py-4 border-b border-gray-200">
                        <h3 class="text-lg font-medium text-gray-900">
                            <?php echo $action === 'add' ? __('categories.add_form') : __('categories.edit_form'); ?>
                        </h3>
                    </div>
                    <div class="px-6 py-6">
                        <form method="POST" class="space-y-6">
                            <input type="hidden" name="action" value="<?php echo $action; ?>">
                            <input type="hidden" name="csrf_token" value="<?php echo generate_csrf_token(); ?>">
                            
                            <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                                <div>
                                    <label class="block text-sm font-medium text-gray-700 mb-2"><?php echo __('categories.field_name'); ?></label>
                                    <input type="text" name="name" required
                                           value="<?php echo htmlspecialchars($category_data['name'] ?? ''); ?>"
                                           class="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:ring-blue-500 focus:border-blue-500"
                                           placeholder="<?php echo htmlspecialchars(__('categories.placeholder_name')); ?>">
                                </div>
                                
                                <div>
                                    <label class="block text-sm font-medium text-gray-700 mb-2"><?php echo __('categories.field_slug'); ?></label>
                                    <input type="text" name="slug"
                                           value="<?php echo htmlspecialchars($category_data['slug'] ?? ''); ?>"
                                           class="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:ring-blue-500 focus:border-blue-500"
                                           placeholder="<?php echo htmlspecialchars(__('categories.placeholder_slug')); ?>">
                                    <p class="mt-1 text-xs text-gray-500"><?php echo __('categories.slug_help'); ?></p>
                                </div>
                            </div>

                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-2"><?php echo __('categories.field_description'); ?></label>
                                <textarea name="description" rows="3"
                                          class="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:ring-blue-500 focus:border-blue-500"
                                          placeholder="<?php echo htmlspecialchars(__('categories.placeholder_description')); ?>"><?php echo htmlspecialchars($category_data['description'] ?? ''); ?></textarea>
                            </div>

                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-2"><?php echo __('categories.field_sort_order'); ?></label>
                                <input type="number" name="sort_order" min="0"
                                       value="<?php echo intval($category_data['sort_order'] ?? 0); ?>"
                                       class="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:ring-blue-500 focus:border-blue-500"
                                       placeholder="<?php echo htmlspecialchars(__('categories.placeholder_sort_order')); ?>">
                                <p class="mt-1 text-xs text-gray-500"><?php echo __('categories.sort_help'); ?></p>
                            </div>

                            <div class="flex justify-end space-x-3">
                                <a href="categories.php" class="inline-flex items-center px-4 py-2 border border-gray-300 text-sm font-medium rounded-md text-gray-700 bg-white hover:bg-gray-50">
                                    <?php echo __('button.cancel'); ?>
                                </a>
                                <button type="submit" class="inline-flex items-center px-4 py-2 border border-transparent text-sm font-medium rounded-md text-white bg-blue-600 hover:bg-blue-700">
                                    <i data-lucide="save" class="w-4 h-4 mr-2"></i>
                                    <?php echo $action === 'add' ? __('categories.save_add') : __('categories.save_edit'); ?>
                                </button>
                            </div>
                        </form>
                    </div>
                </div>
            <?php else: ?>
                <!-- 分类列表 -->
                <div class="bg-white shadow rounded-lg">
                    <div class="px-6 py-4 border-b border-gray-200">
                        <h3 class="text-lg font-medium text-gray-900"><?php echo __('categories.list_title'); ?></h3>
                    </div>
                    <div class="overflow-hidden">
                        <?php if (empty($categories)): ?>
                            <div class="px-6 py-12 text-center">
                                <i data-lucide="folder-x" class="w-12 h-12 mx-auto text-gray-400 mb-4"></i>
                                <h3 class="text-lg font-medium text-gray-900 mb-2"><?php echo __('categories.empty'); ?></h3>
                                <p class="text-gray-500 mb-4"><?php echo __('categories.empty_desc'); ?></p>
                                <a href="categories.php?action=add" class="inline-flex items-center px-4 py-2 border border-transparent text-sm font-medium rounded-md text-white bg-blue-600 hover:bg-blue-700">
                                    <i data-lucide="plus" class="w-4 h-4 mr-2"></i>
                                    <?php echo __('categories.add_first'); ?>
                                </a>
                            </div>
                        <?php else: ?>
                            <table class="min-w-full divide-y divide-gray-200">
                                <thead class="bg-gray-50">
                                    <tr>
                                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider"><?php echo __('categories.column_info'); ?></th>
                                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider"><?php echo __('categories.column_article_count'); ?></th>
                                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider"><?php echo __('categories.column_sort_order'); ?></th>
                                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider"><?php echo __('categories.column_created_at'); ?></th>
                                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider"><?php echo __('common.actions'); ?></th>
                                    </tr>
                                </thead>
                                <tbody class="bg-white divide-y divide-gray-200">
                                    <?php foreach ($categories as $category): ?>
                                        <tr class="hover:bg-gray-50">
                                            <td class="px-6 py-4">
                                                <div>
                                                    <div class="text-sm font-medium text-gray-900"><?php echo htmlspecialchars($category['name']); ?></div>
                                                    <div class="text-sm text-gray-500">
                                                        <?php echo __('categories.url_label'); ?>: <?php echo htmlspecialchars($category['slug']); ?>
                                                    </div>
                                                    <?php if (!empty($category['description'])): ?>
                                                        <div class="text-sm text-gray-500 mt-1">
                                                            <?php echo htmlspecialchars($category['description']); ?>
                                                        </div>
                                                    <?php endif; ?>
                                                </div>
                                            </td>
                                            <td class="px-6 py-4 whitespace-nowrap">
                                                <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-blue-100 text-blue-800">
                                                    <?php echo __('categories.article_count_badge', ['count' => intval($category['article_count'])]); ?>
                                                </span>
                                            </td>
                                            <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                                                <?php echo intval($category['sort_order']); ?>
                                            </td>
                                            <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                                                <?php echo date('Y-m-d H:i', strtotime($category['created_at'])); ?>
                                            </td>
                                            <td class="px-6 py-4 whitespace-nowrap text-sm font-medium">
                                                <div class="flex space-x-2">
                                                    <a href="categories.php?action=edit&id=<?php echo $category['id']; ?>" 
                                                       class="text-blue-600 hover:text-blue-900">
                                                        <i data-lucide="edit" class="w-4 h-4"></i>
                                                    </a>
                                                    <?php if ($category['article_count'] == 0): ?>
                                                        <form method="POST" class="inline" onsubmit="return confirm('<?php echo addslashes(__('categories.confirm_delete')); ?>')">
                                                            <input type="hidden" name="action" value="delete">
                                                            <input type="hidden" name="id" value="<?php echo $category['id']; ?>">
                                                            <input type="hidden" name="csrf_token" value="<?php echo generate_csrf_token(); ?>">
                                                            <button type="submit" class="text-red-600 hover:text-red-900">
                                                                <i data-lucide="trash-2" class="w-4 h-4"></i>
                                                            </button>
                                                        </form>
                                                    <?php else: ?>
                                                        <span class="text-gray-400" title="<?php echo htmlspecialchars(__('categories.delete_disabled')); ?>">
                                                            <i data-lucide="trash-2" class="w-4 h-4"></i>
                                                        </span>
                                                    <?php endif; ?>
                                                </div>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endif; ?>

<?php
// 包含统一底部
require_once __DIR__ . '/includes/footer.php';
?>
