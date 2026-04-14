<?php
/**
 * 智能GEO内容系统 - 文章垃圾箱
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
$admin_site_name = get_setting('site_title', SITE_NAME);

// 处理POST请求
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
        $error = 'CSRF验证失败';
    } else {
        $action = $_POST['action'] ?? '';
        
        switch ($action) {
            case 'restore_articles':
                $article_ids = $_POST['article_ids'] ?? [];
                
                if (!empty($article_ids)) {
                    $placeholders = str_repeat('?,', count($article_ids) - 1) . '?';
                    $stmt = $db->prepare("UPDATE articles SET deleted_at = NULL WHERE id IN ($placeholders)");
                    
                    if ($stmt->execute($article_ids)) {
                        $message = '成功恢复 ' . count($article_ids) . ' 篇文章';
                    } else {
                        $error = '恢复失败';
                    }
                }
                break;
                
            case 'permanent_delete':
                $article_ids = $_POST['article_ids'] ?? [];
                
                if (!empty($article_ids)) {
                    try {
                        $db->beginTransaction();
                        
                        // 删除文章关联的图片记录
                        $placeholders = str_repeat('?,', count($article_ids) - 1) . '?';
                        $stmt = $db->prepare("DELETE FROM article_images WHERE article_id IN ($placeholders)");
                        $stmt->execute($article_ids);
                        
                        // 永久删除文章
                        $stmt = $db->prepare("DELETE FROM articles WHERE id IN ($placeholders)");
                        $stmt->execute($article_ids);
                        
                        $db->commit();
                        $message = '成功永久删除 ' . count($article_ids) . ' 篇文章';
                    } catch (Exception $e) {
                        $db->rollBack();
                        $error = '永久删除失败: ' . $e->getMessage();
                    }
                }
                break;
                
            case 'empty_trash':
                try {
                    $db->beginTransaction();
                    
                    // 获取所有垃圾箱文章ID
                    $stmt = $db->query("SELECT id FROM articles WHERE deleted_at IS NOT NULL");
                    $article_ids = $stmt->fetchAll(PDO::FETCH_COLUMN);
                    
                    if (!empty($article_ids)) {
                        // 删除文章关联的图片记录
                        $placeholders = str_repeat('?,', count($article_ids) - 1) . '?';
                        $stmt = $db->prepare("DELETE FROM article_images WHERE article_id IN ($placeholders)");
                        $stmt->execute($article_ids);
                        
                        // 永久删除所有垃圾箱文章
                        $stmt = $db->query("DELETE FROM articles WHERE deleted_at IS NOT NULL");
                        $deleted_count = $stmt->rowCount();
                        
                        $db->commit();
                        $message = "成功清空垃圾箱，永久删除了 {$deleted_count} 篇文章";
                    } else {
                        $message = '垃圾箱已经是空的';
                    }
                } catch (Exception $e) {
                    $db->rollBack();
                    $error = '清空垃圾箱失败: ' . $e->getMessage();
                }
                break;
        }
    }
}

// 获取筛选参数
$search = trim($_GET['search'] ?? '');
$author_id = max(0, intval($_GET['author_id'] ?? 0));
$page = max(1, intval($_GET['page'] ?? 1));
$per_page = 20;

// 构建查询条件
$where_conditions = ['a.deleted_at IS NOT NULL'];
$params = [];

if (!empty($search)) {
    $where_conditions[] = '(a.title LIKE ? OR a.content LIKE ?)';
    $params[] = "%{$search}%";
    $params[] = "%{$search}%";
}

if ($author_id > 0) {
    $where_conditions[] = 'a.author_id = ?';
    $params[] = $author_id;
}

$where_clause = implode(' AND ', $where_conditions);

// 获取文章总数
$count_sql = "
    SELECT COUNT(*) as total
    FROM articles a
    WHERE {$where_clause}
";
$stmt = $db->prepare($count_sql);
$stmt->execute($params);
$total_articles = $stmt->fetch()['total'];
$total_pages = ceil($total_articles / $per_page);

// 获取文章列表
$offset = ($page - 1) * $per_page;
$sql = "
    SELECT a.*, 
           t.name as task_name,
           au.name as author_name,
           c.name as category_name
    FROM articles a
    LEFT JOIN tasks t ON a.task_id = t.id
    LEFT JOIN authors au ON a.author_id = au.id
    LEFT JOIN categories c ON a.category_id = c.id
    WHERE {$where_clause}
    ORDER BY a.deleted_at DESC
    LIMIT {$per_page} OFFSET {$offset}
";

$stmt = $db->prepare($sql);
$stmt->execute($params);
$articles = $stmt->fetchAll();

// 获取统计数据
$stats = [
    'total_trash' => $total_articles,
    'sensitive_words' => $db->query("
        SELECT COUNT(*) as count 
        FROM articles a
        JOIN system_logs sl ON (NULLIF(sl.data, '')::jsonb ->> 'article_id')::bigint = a.id
        WHERE a.deleted_at IS NOT NULL 
        AND sl.type = 'sensitive_word'
    ")->fetch()['count'],
    'manual_delete' => $total_articles - $db->query("
        SELECT COUNT(*) as count 
        FROM articles a
        JOIN system_logs sl ON (NULLIF(sl.data, '')::jsonb ->> 'article_id')::bigint = a.id
        WHERE a.deleted_at IS NOT NULL 
        AND sl.type = 'sensitive_word'
    ")->fetch()['count']
];

$authors = $db->query("SELECT id, name FROM authors ORDER BY name")->fetchAll();
$current_author_name = '';
if ($author_id > 0) {
    $stmt = $db->prepare("SELECT name FROM authors WHERE id = ?");
    $stmt->execute([$author_id]);
    $current_author_name = $stmt->fetchColumn() ?: '';
}
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>文章垃圾箱 - <?php echo htmlspecialchars($admin_site_name); ?></title>
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
                        <a href="dashboard.php" class="text-gray-500 hover:text-gray-700">首页</a>
                        <a href="tasks.php" class="text-gray-500 hover:text-gray-700">任务管理</a>
                        <a href="articles.php" class="text-blue-600 font-medium">文章管理</a>
                        <a href="materials.php" class="text-gray-500 hover:text-gray-700">素材管理</a>
                        <a href="ai-configurator.php" class="text-gray-500 hover:text-gray-700">AI配置</a>
                        <a href="site-settings.php" class="text-gray-500 hover:text-gray-700">网站设置</a>
                        <a href="security-settings.php" class="text-gray-500 hover:text-gray-700">安全管理</a>
                    </nav>
                </div>
                <div class="flex items-center space-x-4">
                    <span class="text-sm text-gray-600">欢迎，<?php echo $_SESSION['admin_username']; ?></span>
                    <a href="logout.php" class="text-sm text-red-600 hover:text-red-800">退出登录</a>
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
                    <a href="articles.php" class="text-gray-400 hover:text-gray-600">
                        <i data-lucide="arrow-left" class="w-5 h-5"></i>
                    </a>
                    <div>
                        <h1 class="text-2xl font-bold text-gray-900">文章垃圾箱</h1>
                        <p class="mt-1 text-sm text-gray-600">管理已删除的文章，支持恢复或永久删除</p>
                    </div>
                </div>
                <div class="flex space-x-2">
                    <a href="articles.php" class="inline-flex items-center px-3 py-1.5 border border-gray-300 text-sm font-medium rounded text-gray-700 bg-white hover:bg-gray-50">
                        <i data-lucide="arrow-left" class="w-4 h-4 mr-1"></i>
                        返回文章列表
                    </a>
                    <?php if ($total_articles > 0): ?>
                        <button onclick="emptyTrash()" class="inline-flex items-center px-3 py-1.5 border border-transparent text-sm font-medium rounded text-white bg-red-600 hover:bg-red-700">
                            <i data-lucide="trash-2" class="w-4 h-4 mr-1"></i>
                            清空垃圾箱
                        </button>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- 统计卡片 -->
        <div class="grid grid-cols-1 md:grid-cols-3 gap-6 mb-8">
            <div class="bg-white overflow-hidden shadow rounded-lg">
                <div class="p-5">
                    <div class="flex items-center">
                        <div class="flex-shrink-0">
                            <i data-lucide="trash-2" class="h-6 w-6 text-red-600"></i>
                        </div>
                        <div class="ml-5 w-0 flex-1">
                            <dl>
                                <dt class="text-sm font-medium text-gray-500 truncate">垃圾箱总数</dt>
                                <dd class="text-lg font-medium text-gray-900"><?php echo $stats['total_trash']; ?></dd>
                            </dl>
                        </div>
                    </div>
                </div>
            </div>

            <div class="bg-white overflow-hidden shadow rounded-lg">
                <div class="p-5">
                    <div class="flex items-center">
                        <div class="flex-shrink-0">
                            <i data-lucide="shield-alert" class="h-6 w-6 text-orange-600"></i>
                        </div>
                        <div class="ml-5 w-0 flex-1">
                            <dl>
                                <dt class="text-sm font-medium text-gray-500 truncate">敏感词删除</dt>
                                <dd class="text-lg font-medium text-gray-900"><?php echo $stats['sensitive_words']; ?></dd>
                            </dl>
                        </div>
                    </div>
                </div>
            </div>

            <div class="bg-white overflow-hidden shadow rounded-lg">
                <div class="p-5">
                    <div class="flex items-center">
                        <div class="flex-shrink-0">
                            <i data-lucide="user-x" class="h-6 w-6 text-blue-600"></i>
                        </div>
                        <div class="ml-5 w-0 flex-1">
                            <dl>
                                <dt class="text-sm font-medium text-gray-500 truncate">手动删除</dt>
                                <dd class="text-lg font-medium text-gray-900"><?php echo $stats['manual_delete']; ?></dd>
                            </dl>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- 搜索 -->
        <div class="bg-white shadow rounded-lg mb-6">
            <div class="px-6 py-4">
                <form method="GET" class="flex items-center space-x-4">
                    <div class="w-56">
                        <select name="author_id" class="block w-full border-gray-300 rounded-md shadow-sm focus:ring-blue-500 focus:border-blue-500 sm:text-sm">
                            <option value="">所有作者</option>
                            <?php foreach ($authors as $author): ?>
                                <option value="<?php echo intval($author['id']); ?>" <?php echo $author_id === intval($author['id']) ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($author['name']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="flex-1">
                        <input type="text" name="search" value="<?php echo htmlspecialchars($search); ?>"
                               placeholder="搜索已删除的文章..."
                               class="block w-full border-gray-300 rounded-md shadow-sm focus:ring-blue-500 focus:border-blue-500 sm:text-sm">
                    </div>
                    <button type="submit" class="inline-flex items-center px-4 py-2 border border-transparent text-sm font-medium rounded-md text-white bg-blue-600 hover:bg-blue-700">
                        <i data-lucide="search" class="w-4 h-4 mr-2"></i>
                        搜索
                    </button>
                    <a href="articles-trash.php" class="inline-flex items-center px-4 py-2 border border-gray-300 text-sm font-medium rounded-md text-gray-700 bg-white hover:bg-gray-50">
                        <i data-lucide="x" class="w-4 h-4 mr-2"></i>
                        清空
                    </a>
                </form>
                <?php if ($author_id > 0 && $current_author_name): ?>
                    <div class="mt-3 text-sm text-gray-600">
                        当前仅显示作者“<?php echo htmlspecialchars($current_author_name); ?>”的回收站文章。
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- 文章列表 -->
        <div class="bg-white shadow rounded-lg">
            <div class="px-6 py-4 border-b border-gray-200">
                <div class="flex items-center justify-between">
                    <h3 class="text-lg font-medium text-gray-900">
                        已删除文章 
                        <span class="text-sm text-gray-500">(共 <?php echo $total_articles; ?> 篇)</span>
                    </h3>
                    <div class="flex space-x-2">
                        <?php if ($total_articles > 0): ?>
                            <button onclick="emptyTrash()" class="inline-flex items-center px-3 py-1.5 border border-transparent text-xs font-medium rounded text-white bg-red-600 hover:bg-red-700">
                                <i data-lucide="trash-2" class="w-4 h-4 mr-1"></i>
                                一键清空
                            </button>
                        <?php endif; ?>
                        <button onclick="toggleBatchActions()" class="inline-flex items-center px-3 py-1.5 border border-gray-300 text-xs font-medium rounded text-gray-700 bg-white hover:bg-gray-50">
                            <i data-lucide="check-square" class="w-4 h-4 mr-1"></i>
                            批量操作
                        </button>
                    </div>
                </div>
            </div>

            <?php if (empty($articles)): ?>
                <div class="px-6 py-8 text-center">
                    <i data-lucide="trash-2" class="w-12 h-12 mx-auto text-gray-400 mb-4"></i>
                    <h3 class="text-lg font-medium text-gray-900 mb-2">垃圾箱为空</h3>
                    <p class="text-gray-500">没有找到已删除的文章</p>
                </div>
            <?php else: ?>
                <!-- 批量操作栏 -->
                <div id="batch-actions" class="hidden px-6 py-3 bg-gray-50 border-b border-gray-200">
                    <form method="POST" id="batch-form">
                        <input type="hidden" name="csrf_token" value="<?php echo generate_csrf_token(); ?>">
                        <div class="flex items-center space-x-4">
                            <span class="text-sm text-gray-600">已选择 <span id="selected-count">0</span> 篇文章</span>
                            
                            <button type="button" onclick="batchAction('restore_articles')" class="inline-flex items-center px-3 py-1.5 border border-transparent text-xs font-medium rounded text-white bg-green-600 hover:bg-green-700">
                                <i data-lucide="rotate-ccw" class="w-4 h-4 mr-1"></i>
                                恢复
                            </button>
                            
                            <button type="button" onclick="batchAction('permanent_delete')" class="inline-flex items-center px-3 py-1.5 border border-transparent text-xs font-medium rounded text-white bg-red-600 hover:bg-red-700">
                                <i data-lucide="trash-2" class="w-4 h-4 mr-1"></i>
                                永久删除
                            </button>
                            
                            <button type="button" onclick="toggleBatchActions()" class="inline-flex items-center px-3 py-1.5 border border-gray-300 text-xs font-medium rounded text-gray-700 bg-white hover:bg-gray-50">
                                取消
                            </button>
                        </div>
                    </form>
                </div>

                <div class="overflow-hidden">
                    <table class="min-w-full divide-y divide-gray-200">
                        <thead class="bg-gray-50">
                            <tr>
                                <th class="batch-checkbox hidden px-6 py-3 text-left">
                                    <input type="checkbox" id="select-all" class="rounded border-gray-300 text-blue-600 shadow-sm focus:border-blue-300 focus:ring focus:ring-blue-200 focus:ring-opacity-50">
                                </th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">文章信息</th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">任务/作者</th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">删除时间</th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">操作</th>
                            </tr>
                        </thead>
                        <tbody class="bg-white divide-y divide-gray-200">
                            <?php foreach ($articles as $article): ?>
                                <tr class="hover:bg-gray-50">
                                    <td class="batch-checkbox hidden px-6 py-4">
                                        <input type="checkbox" name="article_ids[]" value="<?php echo $article['id']; ?>" class="article-checkbox rounded border-gray-300 text-blue-600 shadow-sm focus:border-blue-300 focus:ring focus:ring-blue-200 focus:ring-opacity-50">
                                    </td>
                                    <td class="px-6 py-4">
                                        <div class="flex items-start space-x-3">
                                            <div class="flex-1 min-w-0">
                                                <p class="text-sm font-medium text-gray-900 truncate">
                                                    <?php echo htmlspecialchars($article['title']); ?>
                                                </p>
                                                <?php if ($article['excerpt']): ?>
                                                    <p class="text-xs text-gray-500 mt-1 line-clamp-2">
                                                        <?php echo htmlspecialchars(mb_substr($article['excerpt'], 0, 100)); ?>...
                                                    </p>
                                                <?php endif; ?>
                                                <div class="mt-1 flex items-center space-x-2">
                                                    <span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium bg-red-100 text-red-800">
                                                        已删除
                                                    </span>
                                                    <?php if ($article['is_ai_generated']): ?>
                                                        <span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium bg-purple-100 text-purple-800">AI生成</span>
                                                    <?php endif; ?>
                                                </div>
                                            </div>
                                        </div>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                                        <?php if ($article['task_name']): ?>
                                            <div class="text-blue-600"><?php echo htmlspecialchars($article['task_name']); ?></div>
                                        <?php endif; ?>
                                        <div><?php echo htmlspecialchars($article['author_name']); ?></div>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                                        <div><?php echo date('Y-m-d H:i', strtotime($article['deleted_at'])); ?></div>
                                        <div class="text-xs text-gray-400">
                                            创建: <?php echo date('m-d H:i', strtotime($article['created_at'])); ?>
                                        </div>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm font-medium">
                                        <div class="flex items-center space-x-2">
                                            <button onclick="restoreArticle(<?php echo $article['id']; ?>)" class="text-green-600 hover:text-green-800" title="恢复">
                                                <i data-lucide="rotate-ccw" class="w-4 h-4"></i>
                                            </button>
                                            <button onclick="permanentDelete(<?php echo $article['id']; ?>)" class="text-red-600 hover:text-red-800" title="永久删除">
                                                <i data-lucide="trash-2" class="w-4 h-4"></i>
                                            </button>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>

                <!-- 分页 -->
                <?php if ($total_pages > 1): ?>
                    <div class="px-6 py-4 border-t border-gray-200">
                        <div class="flex items-center justify-between">
                            <div class="text-sm text-gray-700">
                                显示第 <?php echo ($page - 1) * $per_page + 1; ?> - <?php echo min($page * $per_page, $total_articles); ?> 条，共 <?php echo $total_articles; ?> 条
                            </div>
                            <div class="flex space-x-1">
                                <?php if ($page > 1): ?>
                                    <a href="?<?php echo http_build_query(array_merge($_GET, ['page' => $page - 1])); ?>" class="px-3 py-2 text-sm font-medium text-gray-500 bg-white border border-gray-300 rounded-md hover:bg-gray-50">
                                        上一页
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

    <script>
        // 初始化Lucide图标
        document.addEventListener('DOMContentLoaded', function() {
            if (typeof lucide !== 'undefined') {
                lucide.createIcons();
            }
        });

        // 批量操作功能
        function toggleBatchActions() {
            const batchActions = document.getElementById('batch-actions');
            const checkboxes = document.querySelectorAll('.batch-checkbox');
            const isHidden = batchActions.classList.contains('hidden');
            
            if (isHidden) {
                batchActions.classList.remove('hidden');
                checkboxes.forEach(cb => cb.classList.remove('hidden'));
            } else {
                batchActions.classList.add('hidden');
                checkboxes.forEach(cb => cb.classList.add('hidden'));
                // 清除所有选择
                document.querySelectorAll('.article-checkbox').forEach(cb => cb.checked = false);
                document.getElementById('select-all').checked = false;
                updateSelectedCount();
            }
        }

        // 全选功能
        document.getElementById('select-all').addEventListener('change', function() {
            const checkboxes = document.querySelectorAll('.article-checkbox');
            checkboxes.forEach(cb => cb.checked = this.checked);
            updateSelectedCount();
        });

        // 更新选中数量
        function updateSelectedCount() {
            const selected = document.querySelectorAll('.article-checkbox:checked').length;
            document.getElementById('selected-count').textContent = selected;
        }

        // 监听复选框变化
        document.querySelectorAll('.article-checkbox').forEach(cb => {
            cb.addEventListener('change', updateSelectedCount);
        });

        // 批量操作
        function batchAction(action) {
            const selected = document.querySelectorAll('.article-checkbox:checked');
            if (selected.length === 0) {
                alert('请选择要操作的文章');
                return;
            }
            
            let confirmMessage = '';
            if (action === 'restore_articles') {
                confirmMessage = `确定要恢复选中的 ${selected.length} 篇文章吗？`;
            } else if (action === 'permanent_delete') {
                confirmMessage = `确定要永久删除选中的 ${selected.length} 篇文章吗？此操作不可恢复！`;
            }
            
            if (confirm(confirmMessage)) {
                const form = document.getElementById('batch-form');
                const actionInput = document.createElement('input');
                actionInput.type = 'hidden';
                actionInput.name = 'action';
                actionInput.value = action;
                form.appendChild(actionInput);
                
                // 添加选中的文章ID
                selected.forEach(cb => {
                    const input = document.createElement('input');
                    input.type = 'hidden';
                    input.name = 'article_ids[]';
                    input.value = cb.value;
                    form.appendChild(input);
                });
                
                form.submit();
            }
        }

        // 恢复文章
        function restoreArticle(articleId) {
            if (confirm('确定要恢复这篇文章吗？')) {
                const form = document.createElement('form');
                form.method = 'POST';
                form.innerHTML = `
                    <input type="hidden" name="csrf_token" value="<?php echo generate_csrf_token(); ?>">
                    <input type="hidden" name="action" value="restore_articles">
                    <input type="hidden" name="article_ids[]" value="${articleId}">
                `;
                document.body.appendChild(form);
                form.submit();
            }
        }

        // 永久删除文章
        function permanentDelete(articleId) {
            if (confirm('确定要永久删除这篇文章吗？此操作不可恢复！')) {
                const form = document.createElement('form');
                form.method = 'POST';
                form.innerHTML = `
                    <input type="hidden" name="csrf_token" value="<?php echo generate_csrf_token(); ?>">
                    <input type="hidden" name="action" value="permanent_delete">
                    <input type="hidden" name="article_ids[]" value="${articleId}">
                `;
                document.body.appendChild(form);
                form.submit();
            }
        }

        // 清空垃圾箱
        function emptyTrash() {
            if (confirm('确定要清空垃圾箱吗？这将永久删除所有已删除的文章，此操作不可恢复！')) {
                const form = document.createElement('form');
                form.method = 'POST';
                form.innerHTML = `
                    <input type="hidden" name="csrf_token" value="<?php echo generate_csrf_token(); ?>">
                    <input type="hidden" name="action" value="empty_trash">
                `;
                document.body.appendChild(form);
                form.submit();
            }
        }
    </script>
</body>
</html>
