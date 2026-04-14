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
        $error = 'CSRF验证失败';
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
                    $error = '作者姓名不能为空';
                } else {
                    try {
                        $stmt = $db->prepare("
                            INSERT INTO authors (name, email, bio, website, social_links, created_at, updated_at) 
                            VALUES (?, ?, ?, ?, ?, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)
                        ");
                        
                        if ($stmt->execute([$name, $email, $bio, $website, $social_links])) {
                            $message = '作者创建成功';
                        } else {
                            $error = '作者创建失败';
                        }
                    } catch (Exception $e) {
                        $error = '创建失败: ' . $e->getMessage();
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
                    $error = '作者不存在';
                } elseif (empty($name)) {
                    $error = '作者姓名不能为空';
                } else {
                    try {
                        $stmt = $db->prepare("
                            UPDATE authors
                            SET name = ?, email = ?, bio = ?, website = ?, social_links = ?, updated_at = CURRENT_TIMESTAMP
                            WHERE id = ?
                        ");

                        if ($stmt->execute([$name, $email, $bio, $website, $social_links, $author_id])) {
                            $message = '作者更新成功';
                        } else {
                            $error = '作者更新失败';
                        }
                    } catch (Exception $e) {
                        $error = '更新失败: ' . $e->getMessage();
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
                            $error = "无法删除作者，还有 {$visible_count} 篇未删除文章使用此作者，请先迁移或删除这些文章";
                            $error_action_url = 'articles.php?author_id=' . $author_id;
                            $error_action_label = '查看该作者文章';
                        } elseif ($trashed_count > 0) {
                            $error = "无法删除作者，回收站中还有 {$trashed_count} 篇文章引用该作者，请先彻底删除或迁移";
                            $error_action_url = 'articles-trash.php?author_id=' . $author_id;
                            $error_action_label = '查看回收站文章';
                        } else {
                            $stmt = $db->prepare("DELETE FROM authors WHERE id = ?");
                            
                            if ($stmt->execute([$author_id])) {
                                $message = '作者删除成功';
                            } else {
                                $error = '删除失败';
                            }
                        }
                    } catch (Exception $e) {
                        $error = '删除失败: ' . $e->getMessage();
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
$page_title = '作者管理';
$page_header = '
<div class="flex items-center justify-between">
    <div class="flex items-center space-x-4">
        <a href="materials.php" class="text-gray-400 hover:text-gray-600">
            <i data-lucide="arrow-left" class="w-5 h-5"></i>
        </a>
        <div>
            <h1 class="text-2xl font-bold text-gray-900">作者管理</h1>
            <p class="mt-1 text-sm text-gray-600">管理文章作者信息</p>
        </div>
    </div>
    <button onclick="showCreateModal()" class="inline-flex items-center px-4 py-2 border border-transparent text-sm font-medium rounded-md text-white bg-indigo-600 hover:bg-indigo-700">
        <i data-lucide="plus" class="w-4 h-4 mr-2"></i>
        添加作者
    </button>
</div>
';

// 包含头部模块
require_once __DIR__ . '/includes/header.php';
?>

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
                                <dt class="text-sm font-medium text-gray-500 truncate">作者总数</dt>
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
                                <dt class="text-sm font-medium text-gray-500 truncate">活跃作者</dt>
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
                                <dt class="text-sm font-medium text-gray-500 truncate">平均文章数</dt>
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
                               placeholder="搜索作者姓名、邮箱或简介..."
                               class="block w-full border-gray-300 rounded-md shadow-sm focus:ring-indigo-500 focus:border-indigo-500 sm:text-sm">
                    </div>
                    <button type="submit" class="inline-flex items-center px-4 py-2 border border-transparent text-sm font-medium rounded-md text-white bg-indigo-600 hover:bg-indigo-700">
                        <i data-lucide="search" class="w-4 h-4 mr-2"></i>
                        搜索
                    </button>
                    <a href="authors.php" class="inline-flex items-center px-4 py-2 border border-gray-300 text-sm font-medium rounded-md text-gray-700 bg-white hover:bg-gray-50">
                        <i data-lucide="x" class="w-4 h-4 mr-2"></i>
                        清空
                    </a>
                </form>
            </div>
        </div>

        <!-- 作者列表 -->
        <div class="bg-white shadow rounded-lg">
            <div class="px-6 py-4 border-b border-gray-200">
                <h3 class="text-lg font-medium text-gray-900">
                    作者列表 
                    <span class="text-sm text-gray-500">(共 <?php echo $total_authors; ?> 位)</span>
                </h3>
            </div>

            <?php if (empty($authors)): ?>
                <div class="px-6 py-8 text-center">
                    <i data-lucide="user-plus" class="w-12 h-12 mx-auto text-gray-400 mb-4"></i>
                    <h3 class="text-lg font-medium text-gray-900 mb-2">暂无作者</h3>
                    <p class="text-gray-500 mb-4">
                        <?php echo !empty($search) ? '没有找到匹配的作者' : '开始添加作者信息'; ?>
                    </p>
                    <?php if (empty($search)): ?>
                        <button onclick="showCreateModal()" class="inline-flex items-center px-4 py-2 border border-transparent text-sm font-medium rounded-md text-white bg-indigo-600 hover:bg-indigo-700">
                            <i data-lucide="plus" class="w-4 h-4 mr-2"></i>
                            添加作者
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
                                            <span>文章: <?php echo $author['article_count']; ?> 篇</span>
                                            <span>已发布: <?php echo $author['published_count']; ?> 篇</span>
                                            <?php if (intval($author['trashed_count']) > 0): ?>
                                                <span>回收站: <?php echo intval($author['trashed_count']); ?> 篇</span>
                                            <?php endif; ?>
                                            <span>创建: <?php echo date('Y-m-d', strtotime($author['created_at'])); ?></span>
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
                                        编辑
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
                                        删除
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
                                显示第 <?php echo ($page - 1) * $per_page + 1; ?> - <?php echo min($page * $per_page, $total_authors); ?> 位，共 <?php echo $total_authors; ?> 位
                            </div>
                            <div class="flex space-x-1">
                                <?php if ($page > 1): ?>
                                    <a href="?<?php echo http_build_query(array_merge($_GET, ['page' => $page - 1])); ?>" class="px-3 py-2 text-sm font-medium text-gray-500 bg-white border border-gray-300 rounded-md hover:bg-gray-50">
                                        上一页
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
                                        下一页
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
                <h3 class="text-lg font-medium text-gray-900 mb-4">添加作者</h3>
                <form method="POST">
                    <input type="hidden" name="csrf_token" value="<?php echo generate_csrf_token(); ?>">
                    <input type="hidden" name="action" value="create_author">
                    
                    <div class="space-y-4">
                        <div>
                            <label class="block text-sm font-medium text-gray-700">姓名 *</label>
                            <input type="text" name="name" required 
                                   class="mt-1 block w-full border-gray-300 rounded-md shadow-sm focus:ring-indigo-500 focus:border-indigo-500 sm:text-sm"
                                   placeholder="请输入作者姓名">
                        </div>
                        
                        <div>
                            <label class="block text-sm font-medium text-gray-700">邮箱</label>
                            <input type="email" name="email" 
                                   class="mt-1 block w-full border-gray-300 rounded-md shadow-sm focus:ring-indigo-500 focus:border-indigo-500 sm:text-sm"
                                   placeholder="请输入邮箱地址">
                        </div>
                        
                        <div>
                            <label class="block text-sm font-medium text-gray-700">个人简介</label>
                            <textarea name="bio" rows="3"
                                      class="mt-1 block w-full border-gray-300 rounded-md shadow-sm focus:ring-indigo-500 focus:border-indigo-500 sm:text-sm"
                                      placeholder="作者的个人简介"></textarea>
                        </div>
                        
                        <div>
                            <label class="block text-sm font-medium text-gray-700">个人网站</label>
                            <input type="url" name="website" 
                                   class="mt-1 block w-full border-gray-300 rounded-md shadow-sm focus:ring-indigo-500 focus:border-indigo-500 sm:text-sm"
                                   placeholder="https://example.com">
                        </div>
                        
                        <div>
                            <label class="block text-sm font-medium text-gray-700">社交链接</label>
                            <textarea name="social_links" rows="2"
                                      class="mt-1 block w-full border-gray-300 rounded-md shadow-sm focus:ring-indigo-500 focus:border-indigo-500 sm:text-sm"
                                      placeholder="微博、微信等社交媒体链接"></textarea>
                        </div>
                    </div>
                    
                    <div class="mt-6 flex justify-end space-x-3">
                        <button type="button" onclick="hideCreateModal()" class="px-4 py-2 border border-gray-300 rounded-md text-sm font-medium text-gray-700 hover:bg-gray-50">
                            取消
                        </button>
                        <button type="submit" class="px-4 py-2 border border-transparent rounded-md shadow-sm text-sm font-medium text-white bg-indigo-600 hover:bg-indigo-700">
                            添加作者
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <div id="edit-modal" class="hidden fixed inset-0 bg-gray-600 bg-opacity-50 overflow-y-auto h-full w-full z-50">
        <div class="relative top-20 mx-auto p-5 border w-96 shadow-lg rounded-md bg-white">
            <div class="mt-3">
                <h3 class="text-lg font-medium text-gray-900 mb-4">编辑作者</h3>
                <form method="POST">
                    <input type="hidden" name="csrf_token" value="<?php echo generate_csrf_token(); ?>">
                    <input type="hidden" name="action" value="update_author">
                    <input type="hidden" name="author_id" id="edit-author-id" value="">

                    <div class="space-y-4">
                        <div>
                            <label class="block text-sm font-medium text-gray-700">姓名 *</label>
                            <input type="text" name="name" id="edit-author-name" required
                                   class="mt-1 block w-full border-gray-300 rounded-md shadow-sm focus:ring-indigo-500 focus:border-indigo-500 sm:text-sm"
                                   placeholder="请输入作者姓名">
                        </div>

                        <div>
                            <label class="block text-sm font-medium text-gray-700">邮箱</label>
                            <input type="email" name="email" id="edit-author-email"
                                   class="mt-1 block w-full border-gray-300 rounded-md shadow-sm focus:ring-indigo-500 focus:border-indigo-500 sm:text-sm"
                                   placeholder="请输入邮箱地址">
                        </div>

                        <div>
                            <label class="block text-sm font-medium text-gray-700">个人简介</label>
                            <textarea name="bio" id="edit-author-bio" rows="3"
                                      class="mt-1 block w-full border-gray-300 rounded-md shadow-sm focus:ring-indigo-500 focus:border-indigo-500 sm:text-sm"
                                      placeholder="作者的个人简介"></textarea>
                        </div>

                        <div>
                            <label class="block text-sm font-medium text-gray-700">个人网站</label>
                            <input type="url" name="website" id="edit-author-website"
                                   class="mt-1 block w-full border-gray-300 rounded-md shadow-sm focus:ring-indigo-500 focus:border-indigo-500 sm:text-sm"
                                   placeholder="https://example.com">
                        </div>

                        <div>
                            <label class="block text-sm font-medium text-gray-700">社交链接</label>
                            <textarea name="social_links" id="edit-author-social-links" rows="2"
                                      class="mt-1 block w-full border-gray-300 rounded-md shadow-sm focus:ring-indigo-500 focus:border-indigo-500 sm:text-sm"
                                      placeholder="微博、微信等社交媒体链接"></textarea>
                        </div>
                    </div>

                    <div class="mt-6 flex justify-end space-x-3">
                        <button type="button" onclick="hideEditModal()" class="px-4 py-2 border border-gray-300 rounded-md text-sm font-medium text-gray-700 hover:bg-gray-50">
                            取消
                        </button>
                        <button type="submit" class="px-4 py-2 border border-transparent rounded-md shadow-sm text-sm font-medium text-white bg-indigo-600 hover:bg-indigo-700">
                            保存修改
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

        function showEditModal(button) {
            document.getElementById('edit-author-id').value = button.dataset.authorId || '';
            document.getElementById('edit-author-name').value = button.dataset.authorName || '';
            document.getElementById('edit-author-email').value = button.dataset.authorEmail || '';
            document.getElementById('edit-author-bio').value = button.dataset.authorBio || '';
            document.getElementById('edit-author-website').value = button.dataset.authorWebsite || '';
            document.getElementById('edit-author-social-links').value = button.dataset.authorSocialLinks || '';
            document.getElementById('edit-modal').classList.remove('hidden');
        }

        function hideEditModal() {
            document.getElementById('edit-modal').classList.add('hidden');
        }

        function deleteAuthor(button) {
            const authorId = button.dataset.authorId || '';
            const authorName = button.dataset.authorName || '';
            const trashedCount = Number(button.dataset.trashedCount || 0);
            const warning = trashedCount > 0
                ? `确定要删除作者"${authorName}"吗？回收站中还有 ${trashedCount} 篇文章引用该作者，请先彻底删除或迁移。`
                : `确定要删除作者"${authorName}"吗？如果该作者有文章，将无法删除。`;

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
