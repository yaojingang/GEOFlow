<?php
/**
 * 智能GEO内容系统 - 作者管理
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
$error_action_url = '';
$error_action_label = '';

// 处理POST请求
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
        $error = __('message.csrf_failed');
    } else {
        $action = $_POST['action'] ?? '';
        
        switch ($action) {
            case 'create_author':
                $name = trim($_POST['name'] ?? '');
                $email = trim($_POST['email'] ?? '');
                $bio = trim($_POST['bio'] ?? '');
                $website = trim($_POST['website'] ?? '');
                $social_links = trim($_POST['social_links'] ?? '');
                
                if (empty($name)) {
                    $error = __('authors.error.name_required');
                } else {
                    try {
                        $stmt = $db->prepare("
                            INSERT INTO authors (name, email, bio, website, social_links, created_at, updated_at) 
                            VALUES (?, ?, ?, ?, ?, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)
                        ");
                        
                        if ($stmt->execute([$name, $email, $bio, $website, $social_links])) {
                            $message = __('authors.message.create_success');
                        } else {
                            $error = __('authors.message.create_failed');
                        }
                    } catch (Exception $e) {
                        $error = __('message.create_failed') . ': ' . $e->getMessage();
                    }
                }
                break;

            case 'update_author':
                $author_id = intval($_POST['author_id'] ?? 0);
                $name = trim($_POST['name'] ?? '');
                $email = trim($_POST['email'] ?? '');
                $bio = trim($_POST['bio'] ?? '');
                $website = trim($_POST['website'] ?? '');
                $social_links = trim($_POST['social_links'] ?? '');

                if ($author_id <= 0) {
                    $error = __('authors.error.not_found');
                } elseif (empty($name)) {
                    $error = __('authors.error.name_required');
                } else {
                    try {
                        $stmt = $db->prepare("
                            UPDATE authors
                            SET name = ?, email = ?, bio = ?, website = ?, social_links = ?, updated_at = CURRENT_TIMESTAMP
                            WHERE id = ?
                        ");

                        if ($stmt->execute([$name, $email, $bio, $website, $social_links, $author_id])) {
                            $message = __('authors.message.update_success');
                        } else {
                            $error = __('authors.message.update_failed');
                        }
                    } catch (Exception $e) {
                        $error = __('message.update_failed') . ': ' . $e->getMessage();
                    }
                }
                break;
                
            case 'delete_author':
                $author_id = intval($_POST['author_id'] ?? 0);
                
                if ($author_id > 0) {
                    try {
                        // 区分正常文章与回收站文章，给出更准确的删除提示
                        $stmt = $db->prepare("
                            SELECT
                                COUNT(*) FILTER (WHERE deleted_at IS NULL) as visible_count,
                                COUNT(*) FILTER (WHERE deleted_at IS NOT NULL) as trashed_count
                            FROM articles
                            WHERE author_id = ?
                        ");
                        $stmt->execute([$author_id]);
                        $article_usage = $stmt->fetch();
                        $visible_count = intval($article_usage['visible_count'] ?? 0);
                        $trashed_count = intval($article_usage['trashed_count'] ?? 0);
                        
                        if ($visible_count > 0) {
                            $error = __('authors.error.delete_visible', ['count' => $visible_count]);
                            $error_action_url = 'articles.php?author_id=' . $author_id;
                            $error_action_label = __('authors.action.view_articles');
                        } elseif ($trashed_count > 0) {
                            $error = __('authors.error.delete_trashed', ['count' => $trashed_count]);
                            $error_action_url = 'articles-trash.php?author_id=' . $author_id;
                            $error_action_label = __('authors.action.view_trashed_articles');
                        } else {
                            $stmt = $db->prepare("DELETE FROM authors WHERE id = ?");
                            
                            if ($stmt->execute([$author_id])) {
                                $message = __('authors.message.delete_success');
                            } else {
                                $error = __('authors.message.delete_failed');
                            }
                        }
                    } catch (Exception $e) {
                        $error = __('message.delete_failed') . ': ' . $e->getMessage();
                    }
                }
                break;
        }
    }
}

// 获取筛选参数
$search = trim($_GET['search'] ?? '');
$page = max(1, intval($_GET['page'] ?? 1));
$per_page = 20;

// 构建查询条件
$where_conditions = ['1=1'];
$params = [];

if (!empty($search)) {
    $where_conditions[] = '(name LIKE ? OR email LIKE ? OR bio LIKE ?)';
    $params[] = "%{$search}%";
    $params[] = "%{$search}%";
    $params[] = "%{$search}%";
}

$where_clause = implode(' AND ', $where_conditions);

// 获取作者总数
$count_sql = "SELECT COUNT(*) as total FROM authors WHERE {$where_clause}";
$stmt = $db->prepare($count_sql);
$stmt->execute($params);
$total_authors = $stmt->fetch()['total'];
$total_pages = ceil($total_authors / $per_page);

// 获取作者列表
$offset = ($page - 1) * $per_page;
$sql = "
    SELECT a.*, 
           (SELECT COUNT(*) FROM articles WHERE author_id = a.id AND deleted_at IS NULL) as article_count,
           (SELECT COUNT(*) FROM articles WHERE author_id = a.id AND status = 'published' AND deleted_at IS NULL) as published_count,
           (SELECT COUNT(*) FROM articles WHERE author_id = a.id AND deleted_at IS NOT NULL) as trashed_count
    FROM authors a
    WHERE {$where_clause}
    ORDER BY a.created_at DESC
    LIMIT {$per_page} OFFSET {$offset}
";

$stmt = $db->prepare($sql);
$stmt->execute($params);
$authors = $stmt->fetchAll();

// 获取统计数据
$stats = [
    'total_authors' => $total_authors,
    'active_authors' => $db->query("SELECT COUNT(DISTINCT author_id) as count FROM articles WHERE author_id IS NOT NULL AND deleted_at IS NULL")->fetch()['count'],
    'avg_articles' => $total_authors > 0 ? round($db->query("SELECT COUNT(*) as count FROM articles WHERE author_id IS NOT NULL AND deleted_at IS NULL")->fetch()['count'] / $total_authors, 1) : 0
];

// 设置页面信息
$page_title = __('authors.page_title');
$page_header = '
<div class="flex items-center justify-between">
    <div class="flex items-center space-x-4">
        <a href="materials.php" class="text-gray-400 hover:text-gray-600">
            <i data-lucide="arrow-left" class="w-5 h-5"></i>
        </a>
        <div>
            <h1 class="text-2xl font-bold text-gray-900">' . htmlspecialchars(__('authors.page_title'), ENT_QUOTES) . '</h1>
            <p class="mt-1 text-sm text-gray-600">' . htmlspecialchars(__('authors.page_subtitle'), ENT_QUOTES) . '</p>
        </div>
    </div>
    <button onclick="showCreateModal()" class="inline-flex items-center px-4 py-2 border border-transparent text-sm font-medium rounded-md text-white bg-indigo-600 hover:bg-indigo-700">
        <i data-lucide="plus" class="w-4 h-4 mr-2"></i>
        ' . htmlspecialchars(__('authors.create'), ENT_QUOTES) . '
    </button>
</div>
';

// 包含头部模块
require_once __DIR__ . '/includes/header.php';
?>

<script>
const AUTHORS_I18N = <?php echo json_encode([
    'confirmDelete' => __('authors.confirm_delete', ['name' => '__NAME__']),
    'confirmDeleteTrashed' => __('authors.confirm_delete_trashed', ['name' => '__NAME__', 'count' => '__COUNT__']),
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>;
</script>

        <!-- 统计卡片 -->
        <div class="grid grid-cols-1 md:grid-cols-3 gap-6 mb-8">
            <div class="bg-white overflow-hidden shadow rounded-lg">
                <div class="p-5">
                    <div class="flex items-center">
                        <div class="flex-shrink-0">
                            <i data-lucide="users" class="h-6 w-6 text-indigo-600"></i>
                        </div>
                        <div class="ml-5 w-0 flex-1">
                            <dl>
                                <dt class="text-sm font-medium text-gray-500 truncate"><?php echo htmlspecialchars(__('authors.stats_total')); ?></dt>
                                <dd class="text-lg font-medium text-gray-900"><?php echo $stats['total_authors']; ?></dd>
                            </dl>
                        </div>
                    </div>
                </div>
            </div>

            <div class="bg-white overflow-hidden shadow rounded-lg">
                <div class="p-5">
                    <div class="flex items-center">
                        <div class="flex-shrink-0">
                            <i data-lucide="user-check" class="h-6 w-6 text-green-600"></i>
                        </div>
                        <div class="ml-5 w-0 flex-1">
                            <dl>
                                <dt class="text-sm font-medium text-gray-500 truncate"><?php echo htmlspecialchars(__('authors.stats_active')); ?></dt>
                                <dd class="text-lg font-medium text-gray-900"><?php echo $stats['active_authors']; ?></dd>
                            </dl>
                        </div>
                    </div>
                </div>
            </div>

            <div class="bg-white overflow-hidden shadow rounded-lg">
                <div class="p-5">
                    <div class="flex items-center">
                        <div class="flex-shrink-0">
                            <i data-lucide="trending-up" class="h-6 w-6 text-blue-600"></i>
                        </div>
                        <div class="ml-5 w-0 flex-1">
                            <dl>
                                <dt class="text-sm font-medium text-gray-500 truncate"><?php echo htmlspecialchars(__('authors.stats_average')); ?></dt>
                                <dd class="text-lg font-medium text-gray-900"><?php echo $stats['avg_articles']; ?></dd>
                            </dl>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- 搜索和筛选 -->
        <div class="bg-white shadow rounded-lg mb-6">
            <div class="px-6 py-4">
                <form method="GET" class="flex items-center space-x-4">
                    <div class="flex-1">
                        <input type="text" name="search" value="<?php echo htmlspecialchars($search); ?>"
                               placeholder="<?php echo htmlspecialchars(__('authors.search_placeholder')); ?>"
                               class="block w-full border-gray-300 rounded-md shadow-sm focus:ring-indigo-500 focus:border-indigo-500 sm:text-sm">
                    </div>
                    <button type="submit" class="inline-flex items-center px-4 py-2 border border-transparent text-sm font-medium rounded-md text-white bg-indigo-600 hover:bg-indigo-700">
                        <i data-lucide="search" class="w-4 h-4 mr-2"></i>
                        <?php echo htmlspecialchars(__('button.search')); ?>
                    </button>
                    <a href="authors.php" class="inline-flex items-center px-4 py-2 border border-gray-300 text-sm font-medium rounded-md text-gray-700 bg-white hover:bg-gray-50">
                        <i data-lucide="x" class="w-4 h-4 mr-2"></i>
                        <?php echo htmlspecialchars(__('button.clear')); ?>
                    </a>
                </form>
            </div>
        </div>

        <!-- 作者列表 -->
        <div class="bg-white shadow rounded-lg">
            <div class="px-6 py-4 border-b border-gray-200">
                <h3 class="text-lg font-medium text-gray-900">
                    <?php echo htmlspecialchars(__('authors.list_title')); ?> 
                    <span class="text-sm text-gray-500">(<?php echo $total_authors; ?>)</span>
                </h3>
            </div>

            <?php if (empty($authors)): ?>
                <div class="px-6 py-8 text-center">
                    <i data-lucide="user-plus" class="w-12 h-12 mx-auto text-gray-400 mb-4"></i>
                    <h3 class="text-lg font-medium text-gray-900 mb-2"><?php echo htmlspecialchars(__('authors.empty_title')); ?></h3>
                    <p class="text-gray-500 mb-4">
                        <?php echo htmlspecialchars(!empty($search) ? __('authors.empty_search') : __('authors.empty_desc')); ?>
                    </p>
                    <?php if (empty($search)): ?>
                        <button onclick="showCreateModal()" class="inline-flex items-center px-4 py-2 border border-transparent text-sm font-medium rounded-md text-white bg-indigo-600 hover:bg-indigo-700">
                            <i data-lucide="plus" class="w-4 h-4 mr-2"></i>
                            <?php echo htmlspecialchars(__('authors.create')); ?>
                        </button>
                    <?php endif; ?>
                </div>
            <?php else: ?>
                <div class="divide-y divide-gray-200">
                    <?php foreach ($authors as $author): ?>
                        <div class="px-6 py-6">
                            <div class="flex items-center justify-between">
                                <div class="flex items-center space-x-4">
                                    <div class="flex-shrink-0">
                                        <div class="w-12 h-12 bg-indigo-100 rounded-full flex items-center justify-center">
                                            <i data-lucide="user" class="w-6 h-6 text-indigo-600"></i>
                                        </div>
                                    </div>
                                    <div class="flex-1">
                                        <h4 class="text-lg font-medium text-gray-900"><?php echo htmlspecialchars($author['name']); ?></h4>
                                        <?php if ($author['email']): ?>
                                            <p class="text-sm text-gray-600"><?php echo htmlspecialchars($author['email']); ?></p>
                                        <?php endif; ?>
                                        <?php if ($author['bio']): ?>
                                            <p class="text-sm text-gray-500 mt-1"><?php echo htmlspecialchars(mb_substr($author['bio'], 0, 100)); ?><?php echo mb_strlen($author['bio']) > 100 ? '...' : ''; ?></p>
                                        <?php endif; ?>
                                        <div class="mt-2 flex items-center space-x-4 text-sm text-gray-500">
                                            <span><?php echo htmlspecialchars(__('authors.article_count', ['count' => (string) $author['article_count']])); ?></span>
                                            <span><?php echo htmlspecialchars(__('authors.published_count', ['count' => (string) $author['published_count']])); ?></span>
                                            <?php if (intval($author['trashed_count']) > 0): ?>
                                                <span><?php echo htmlspecialchars(__('authors.trashed_count', ['count' => (string) intval($author['trashed_count'])])); ?></span>
                                            <?php endif; ?>
                                            <span><?php echo htmlspecialchars(__('authors.created_prefix', ['date' => date('Y-m-d', strtotime($author['created_at']))])); ?></span>
                                        </div>
                                    </div>
                                </div>
                                
                                <div class="flex items-center space-x-2">
                                    <button
                                        type="button"
                                        onclick="showEditModal(this)"
                                        data-author-id="<?php echo intval($author['id']); ?>"
                                        data-author-name="<?php echo htmlspecialchars($author['name'], ENT_QUOTES, 'UTF-8'); ?>"
                                        data-author-email="<?php echo htmlspecialchars($author['email'] ?? '', ENT_QUOTES, 'UTF-8'); ?>"
                                        data-author-bio="<?php echo htmlspecialchars($author['bio'] ?? '', ENT_QUOTES, 'UTF-8'); ?>"
                                        data-author-website="<?php echo htmlspecialchars($author['website'] ?? '', ENT_QUOTES, 'UTF-8'); ?>"
                                        data-author-social-links="<?php echo htmlspecialchars($author['social_links'] ?? '', ENT_QUOTES, 'UTF-8'); ?>"
                                        class="inline-flex items-center px-3 py-1.5 border border-gray-300 text-xs font-medium rounded text-gray-700 bg-white hover:bg-gray-50"
                                    >
                                        <i data-lucide="pencil" class="w-4 h-4 mr-1"></i>
                                        <?php echo htmlspecialchars(__('authors.edit')); ?>
                                    </button>
                                    <button
                                        type="button"
                                        onclick="deleteAuthor(this)"
                                        data-author-id="<?php echo intval($author['id']); ?>"
                                        data-author-name="<?php echo htmlspecialchars($author['name'], ENT_QUOTES, 'UTF-8'); ?>"
                                        data-trashed-count="<?php echo intval($author['trashed_count']); ?>"
                                        class="inline-flex items-center px-3 py-1.5 border border-transparent text-xs font-medium rounded text-white bg-red-600 hover:bg-red-700"
                                    >
                                        <i data-lucide="trash-2" class="w-4 h-4 mr-1"></i>
                                        <?php echo htmlspecialchars(__('authors.delete')); ?>
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
                                <?php echo htmlspecialchars(__('articles.pagination.summary', ['from' => (string) (($page - 1) * $per_page + 1), 'to' => (string) min($page * $per_page, $total_authors), 'total' => (string) $total_authors])); ?>
                            </div>
                            <div class="flex space-x-1">
                                <?php if ($page > 1): ?>
                                    <a href="?<?php echo http_build_query(array_merge($_GET, ['page' => $page - 1])); ?>" class="px-3 py-2 text-sm font-medium text-gray-500 bg-white border border-gray-300 rounded-md hover:bg-gray-50">
                                        <?php echo htmlspecialchars(__('articles.pagination.prev')); ?>
                                    </a>
                                <?php endif; ?>
                                
                                <?php for ($i = max(1, $page - 2); $i <= min($total_pages, $page + 2); $i++): ?>
                                    <a href="?<?php echo http_build_query(array_merge($_GET, ['page' => $i])); ?>" 
                                       class="px-3 py-2 text-sm font-medium <?php echo $i === $page ? 'text-indigo-600 bg-indigo-50 border-indigo-500' : 'text-gray-500 bg-white border-gray-300'; ?> border rounded-md hover:bg-gray-50">
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

    <!-- 添加作者模态框 -->
    <div id="create-modal" class="hidden fixed inset-0 bg-gray-600 bg-opacity-50 overflow-y-auto h-full w-full z-50">
        <div class="relative top-20 mx-auto p-5 border w-96 shadow-lg rounded-md bg-white">
            <div class="mt-3">
                <h3 class="text-lg font-medium text-gray-900 mb-4"><?php echo htmlspecialchars(__('authors.modal_create')); ?></h3>
                <form method="POST">
                    <input type="hidden" name="csrf_token" value="<?php echo generate_csrf_token(); ?>">
                    <input type="hidden" name="action" value="create_author">
                    
                    <div class="space-y-4">
                        <div>
                            <label class="block text-sm font-medium text-gray-700"><?php echo htmlspecialchars(__('authors.field_name')); ?></label>
                            <input type="text" name="name" required 
                                   class="mt-1 block w-full border-gray-300 rounded-md shadow-sm focus:ring-indigo-500 focus:border-indigo-500 sm:text-sm"
                                   placeholder="<?php echo htmlspecialchars(__('authors.placeholder_name')); ?>">
                        </div>
                        
                        <div>
                            <label class="block text-sm font-medium text-gray-700"><?php echo htmlspecialchars(__('authors.field_email')); ?></label>
                            <input type="email" name="email" 
                                   class="mt-1 block w-full border-gray-300 rounded-md shadow-sm focus:ring-indigo-500 focus:border-indigo-500 sm:text-sm"
                                   placeholder="<?php echo htmlspecialchars(__('authors.placeholder_email')); ?>">
                        </div>
                        
                        <div>
                            <label class="block text-sm font-medium text-gray-700"><?php echo htmlspecialchars(__('authors.field_bio')); ?></label>
                            <textarea name="bio" rows="3"
                                      class="mt-1 block w-full border-gray-300 rounded-md shadow-sm focus:ring-indigo-500 focus:border-indigo-500 sm:text-sm"
                                      placeholder="<?php echo htmlspecialchars(__('authors.placeholder_bio')); ?>"></textarea>
                        </div>
                        
                        <div>
                            <label class="block text-sm font-medium text-gray-700"><?php echo htmlspecialchars(__('authors.field_website')); ?></label>
                            <input type="url" name="website" 
                                   class="mt-1 block w-full border-gray-300 rounded-md shadow-sm focus:ring-indigo-500 focus:border-indigo-500 sm:text-sm"
                                   placeholder="https://example.com">
                        </div>
                        
                        <div>
                            <label class="block text-sm font-medium text-gray-700"><?php echo htmlspecialchars(__('authors.field_social')); ?></label>
                            <textarea name="social_links" rows="2"
                                      class="mt-1 block w-full border-gray-300 rounded-md shadow-sm focus:ring-indigo-500 focus:border-indigo-500 sm:text-sm"
                                      placeholder="<?php echo htmlspecialchars(__('authors.placeholder_social')); ?>"></textarea>
                        </div>
                    </div>
                    
                    <div class="mt-6 flex justify-end space-x-3">
                        <button type="button" onclick="hideCreateModal()" class="px-4 py-2 border border-gray-300 rounded-md text-sm font-medium text-gray-700 hover:bg-gray-50">
                            <?php echo htmlspecialchars(__('button.cancel')); ?>
                        </button>
                        <button type="submit" class="px-4 py-2 border border-transparent rounded-md shadow-sm text-sm font-medium text-white bg-indigo-600 hover:bg-indigo-700">
                            <?php echo htmlspecialchars(__('authors.save_create')); ?>
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <div id="edit-modal" class="hidden fixed inset-0 bg-gray-600 bg-opacity-50 overflow-y-auto h-full w-full z-50">
        <div class="relative top-20 mx-auto p-5 border w-96 shadow-lg rounded-md bg-white">
            <div class="mt-3">
                <h3 class="text-lg font-medium text-gray-900 mb-4"><?php echo htmlspecialchars(__('authors.modal_edit')); ?></h3>
                <form method="POST">
                    <input type="hidden" name="csrf_token" value="<?php echo generate_csrf_token(); ?>">
                    <input type="hidden" name="action" value="update_author">
                    <input type="hidden" name="author_id" id="edit-author-id" value="">

                    <div class="space-y-4">
                        <div>
                            <label class="block text-sm font-medium text-gray-700"><?php echo htmlspecialchars(__('authors.field_name')); ?></label>
                            <input type="text" name="name" id="edit-author-name" required
                                   class="mt-1 block w-full border-gray-300 rounded-md shadow-sm focus:ring-indigo-500 focus:border-indigo-500 sm:text-sm"
                                   placeholder="<?php echo htmlspecialchars(__('authors.placeholder_name')); ?>">
                        </div>

                        <div>
                            <label class="block text-sm font-medium text-gray-700"><?php echo htmlspecialchars(__('authors.field_email')); ?></label>
                            <input type="email" name="email" id="edit-author-email"
                                   class="mt-1 block w-full border-gray-300 rounded-md shadow-sm focus:ring-indigo-500 focus:border-indigo-500 sm:text-sm"
                                   placeholder="<?php echo htmlspecialchars(__('authors.placeholder_email')); ?>">
                        </div>

                        <div>
                            <label class="block text-sm font-medium text-gray-700"><?php echo htmlspecialchars(__('authors.field_bio')); ?></label>
                            <textarea name="bio" id="edit-author-bio" rows="3"
                                      class="mt-1 block w-full border-gray-300 rounded-md shadow-sm focus:ring-indigo-500 focus:border-indigo-500 sm:text-sm"
                                      placeholder="<?php echo htmlspecialchars(__('authors.placeholder_bio')); ?>"></textarea>
                        </div>

                        <div>
                            <label class="block text-sm font-medium text-gray-700"><?php echo htmlspecialchars(__('authors.field_website')); ?></label>
                            <input type="url" name="website" id="edit-author-website"
                                   class="mt-1 block w-full border-gray-300 rounded-md shadow-sm focus:ring-indigo-500 focus:border-indigo-500 sm:text-sm"
                                   placeholder="https://example.com">
                        </div>

                        <div>
                            <label class="block text-sm font-medium text-gray-700"><?php echo htmlspecialchars(__('authors.field_social')); ?></label>
                            <textarea name="social_links" id="edit-author-social-links" rows="2"
                                      class="mt-1 block w-full border-gray-300 rounded-md shadow-sm focus:ring-indigo-500 focus:border-indigo-500 sm:text-sm"
                                      placeholder="<?php echo htmlspecialchars(__('authors.placeholder_social')); ?>"></textarea>
                        </div>
                    </div>

                    <div class="mt-6 flex justify-end space-x-3">
                        <button type="button" onclick="hideEditModal()" class="px-4 py-2 border border-gray-300 rounded-md text-sm font-medium text-gray-700 hover:bg-gray-50">
                            <?php echo htmlspecialchars(__('button.cancel')); ?>
                        </button>
                        <button type="submit" class="px-4 py-2 border border-transparent rounded-md shadow-sm text-sm font-medium text-white bg-indigo-600 hover:bg-indigo-700">
                            <?php echo htmlspecialchars(__('authors.save_edit')); ?>
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

        // 显示编辑模态框
        function showEditModal(button) {
            document.getElementById('edit-author-id').value = button.dataset.authorId || '';
            document.getElementById('edit-author-name').value = button.dataset.authorName || '';
            document.getElementById('edit-author-email').value = button.dataset.authorEmail || '';
            document.getElementById('edit-author-bio').value = button.dataset.authorBio || '';
            document.getElementById('edit-author-website').value = button.dataset.authorWebsite || '';
            document.getElementById('edit-author-social-links').value = button.dataset.authorSocialLinks || '';
            document.getElementById('edit-modal').classList.remove('hidden');
        }

        // 隐藏编辑模态框
        function hideEditModal() {
            document.getElementById('edit-modal').classList.add('hidden');
        }

        // 删除作者
        function deleteAuthor(button) {
            const authorId = button.dataset.authorId || '';
            const authorName = button.dataset.authorName || '';
            const trashedCount = Number(button.dataset.trashedCount || 0);
            const warning = trashedCount > 0
                ? AUTHORS_I18N.confirmDeleteTrashed.replace('__NAME__', authorName).replace('__COUNT__', trashedCount)
                : AUTHORS_I18N.confirmDelete.replace('__NAME__', authorName);

            if (confirm(warning)) {
                const form = document.createElement('form');
                form.method = 'POST';
                form.innerHTML = `
                    <input type="hidden" name="csrf_token" value="<?php echo generate_csrf_token(); ?>">
                    <input type="hidden" name="action" value="delete_author">
                    <input type="hidden" name="author_id" value="${authorId}">
                `;
                document.body.appendChild(form);
                form.submit();
            }
        }

        // 点击模态框外部关闭
        window.onclick = function(event) {
            const createModal = document.getElementById('create-modal');
            const editModal = document.getElementById('edit-modal');
            
            if (event.target === createModal) {
                hideCreateModal();
            }
            if (event.target === editModal) {
                hideEditModal();
            }
        }
    </script>
</body>
</html>
