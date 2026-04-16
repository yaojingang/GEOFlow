<?php
/**
 * 智能GEO内容系统 - 文章查看
 */

define('FEISHU_TREASURE', true);
session_start();

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/database_admin.php';
require_once __DIR__ . '/../includes/functions.php';

require_admin_login();
session_write_close();

$article_id = (int) ($_GET['id'] ?? 0);
$message = '';
$error = '';

if ($article_id <= 0) {
    header('Location: articles.php');
    exit;
}

$stmt = $db->prepare("
    SELECT a.*,
           t.name as task_name,
           au.name as author_name,
           c.name as category_name
    FROM articles a
    LEFT JOIN tasks t ON a.task_id = t.id
    LEFT JOIN authors au ON a.author_id = au.id
    LEFT JOIN categories c ON a.category_id = c.id
    WHERE a.id = ? AND a.deleted_at IS NULL
");
$stmt->execute([$article_id]);
$article = $stmt->fetch();

if (!$article) {
    header('Location: articles.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
        $error = 'CSRF验证失败';
    } else {
        $action = $_POST['action'] ?? '';

        if ($action === 'update_status') {
            $workflowState = normalize_article_workflow_state(
                $_POST['status'] ?? 'draft',
                $article['review_status'] ?? 'pending',
                $article['published_at'] ?? null
            );
            $stmt = $db->prepare("
                UPDATE articles
                SET status = ?, review_status = ?, published_at = ?, updated_at = CURRENT_TIMESTAMP
                WHERE id = ?
            ");
            if ($stmt->execute([$workflowState['status'], $workflowState['review_status'], $workflowState['published_at'], $article_id])) {
                $message = '发布状态已更新，审核结果与发布时间已同步收敛';
                $article['status'] = $workflowState['status'];
                $article['review_status'] = $workflowState['review_status'];
                $article['published_at'] = $workflowState['published_at'];
            } else {
                $error = '发布状态更新失败';
            }
        }

        if ($action === 'update_review') {
            $review_status = $_POST['review_status'] ?? '';
            if ($review_status !== '') {
                $desiredStatus = $article['status'] ?? 'draft';
                if (in_array($review_status, ['approved', 'auto_approved'], true)) {
                    $task_stmt = $db->prepare("SELECT need_review FROM tasks WHERE id = ?");
                    $task_stmt->execute([$article['task_id']]);
                    $task = $task_stmt->fetch();
                    if ($review_status === 'auto_approved' || ($task && !$task['need_review'])) {
                        $desiredStatus = 'published';
                    }
                }

                $workflowState = normalize_article_workflow_state($desiredStatus, $review_status, $article['published_at'] ?? null);
                $stmt = $db->prepare("
                    UPDATE articles
                    SET status = ?, review_status = ?, published_at = ?, updated_at = CURRENT_TIMESTAMP
                    WHERE id = ?
                ");
                if ($stmt->execute([$workflowState['status'], $workflowState['review_status'], $workflowState['published_at'], $article_id])) {
                    $message = '审核结果已更新';
                    if ($workflowState['status'] === 'published' && in_array($workflowState['review_status'], ['approved', 'auto_approved'], true)) {
                        $message .= '，文章已进入发布状态';
                    } elseif ($workflowState['status'] === 'draft' && $workflowState['review_status'] === 'rejected') {
                        $message .= '，文章已退回草稿';
                    }
                    $article['status'] = $workflowState['status'];
                    $article['review_status'] = $workflowState['review_status'];
                    $article['published_at'] = $workflowState['published_at'];
                } else {
                    $error = '审核结果更新失败';
                }
            }
        }
    }
}

$stmt = $db->prepare("
    SELECT ai.*, i.file_path, i.original_name
    FROM article_images ai
    LEFT JOIN images i ON ai.image_id = i.id
    WHERE ai.article_id = ?
    ORDER BY ai.position
");
$stmt->execute([$article_id]);
$article_images = $stmt->fetchAll();
foreach ($article_images as &$articleImage) {
    $articleImage['file_path'] = normalize_content_asset_url((string) ($articleImage['file_path'] ?? ''));
}
unset($articleImage);

$page_title = '查看文章';
$page_header = '
<div class="flex items-center space-x-4">
    <a href="articles.php" class="text-gray-400 hover:text-gray-600">
        <i data-lucide="arrow-left" class="w-5 h-5"></i>
    </a>
    <div>
        <h1 class="text-2xl font-bold text-gray-900">查看文章</h1>
        <p class="mt-1 text-sm text-gray-600">' . htmlspecialchars($article['title'], ENT_QUOTES, 'UTF-8') . '</p>
    </div>
</div>';
$additional_css = '
<script src="https://cdn.jsdelivr.net/npm/marked/marked.min.js"></script>
<style>
    .markdown-content { line-height: 1.6; }
    .markdown-content h1, .markdown-content h2, .markdown-content h3 { margin-top: 1.5em; margin-bottom: 0.5em; font-weight: 600; }
    .markdown-content h1 { font-size: 1.5em; }
    .markdown-content h2 { font-size: 1.3em; }
    .markdown-content h3 { font-size: 1.1em; }
    .markdown-content p { margin-bottom: 1em; }
    .markdown-content ul, .markdown-content ol { margin-bottom: 1em; padding-left: 1.5em; }
    .markdown-content img { max-width: 100%; height: auto; margin: 1em 0; }
    .markdown-content blockquote { border-left: 4px solid #e5e7eb; padding-left: 1em; margin: 1em 0; color: #6b7280; }
</style>';

require_once __DIR__ . '/includes/header.php';
?>

<div class="grid grid-cols-1 lg:grid-cols-4 gap-6">
    <div class="lg:col-span-3">
        <div class="bg-white shadow rounded-lg">
            <div class="px-6 py-4 border-b border-gray-200">
                <div class="flex items-center justify-between">
                    <h3 class="text-lg font-medium text-gray-900">文章内容</h3>
                    <div class="flex space-x-2">
                        <a href="article-edit.php?id=<?php echo (int) $article['id']; ?>" class="inline-flex items-center px-3 py-1.5 border border-gray-300 text-xs font-medium rounded text-gray-700 bg-white hover:bg-gray-50">
                            <i data-lucide="edit" class="w-4 h-4 mr-1"></i>编辑
                        </a>
                        <?php if ($article['status'] === 'published'): ?>
                            <a href="../article/<?php echo urlencode((string) $article['slug']); ?>" target="_blank" class="inline-flex items-center px-3 py-1.5 border border-transparent text-xs font-medium rounded text-white bg-blue-600 hover:bg-blue-700">
                                <i data-lucide="external-link" class="w-4 h-4 mr-1"></i>前台预览
                            </a>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
            <div class="px-6 py-6">
                <div class="space-y-6">
                    <div>
                        <h1 class="text-2xl font-bold text-gray-900 mb-2"><?php echo htmlspecialchars($article['title']); ?></h1>
                        <?php if ($article['excerpt']): ?>
                            <p class="text-gray-600 italic"><?php echo htmlspecialchars($article['excerpt']); ?></p>
                        <?php endif; ?>
                    </div>
                    <div class="prose max-w-none markdown-content" id="article-content"></div>
                </div>
            </div>
        </div>

        <?php if (!empty($article_images)): ?>
            <div class="mt-6 bg-white shadow rounded-lg">
                <div class="px-6 py-4 border-b border-gray-200">
                    <h3 class="text-lg font-medium text-gray-900">文章图片</h3>
                </div>
                <div class="px-6 py-4">
                    <div class="grid grid-cols-2 md:grid-cols-3 gap-4">
                        <?php foreach ($article_images as $img): ?>
                            <div class="relative">
                                <img src="<?php echo htmlspecialchars($img['file_path']); ?>" alt="<?php echo htmlspecialchars($img['original_name']); ?>" class="w-full h-32 object-cover rounded-lg">
                                <div class="absolute bottom-0 left-0 right-0 bg-black bg-opacity-50 text-white text-xs p-2 rounded-b-lg"><?php echo htmlspecialchars($img['original_name']); ?></div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>
        <?php endif; ?>
    </div>

    <div class="space-y-6">
        <div class="bg-white shadow rounded-lg">
            <div class="px-6 py-4 border-b border-gray-200">
                <h3 class="text-lg font-medium text-gray-900">文章信息</h3>
            </div>
            <div class="px-6 py-4 space-y-4 text-sm">
                <div><div class="text-gray-500">ID</div><div class="mt-1 text-gray-900"><?php echo (int) $article['id']; ?></div></div>
                <div><div class="text-gray-500">URL别名</div><div class="mt-1 font-mono text-gray-900"><?php echo htmlspecialchars($article['slug']); ?></div></div>
                <div><div class="text-gray-500">分类</div><div class="mt-1 text-gray-900"><?php echo htmlspecialchars($article['category_name'] ?: '未分类'); ?></div></div>
                <div><div class="text-gray-500">作者</div><div class="mt-1 text-gray-900"><?php echo htmlspecialchars($article['author_name']); ?></div></div>
                <div><div class="text-gray-500">来源任务</div><div class="mt-1 text-gray-900"><?php echo $article['task_name'] ? htmlspecialchars($article['task_name']) : '手动创建'; ?></div></div>
                <div><div class="text-gray-500">创建时间</div><div class="mt-1 text-gray-900"><?php echo date('Y-m-d H:i:s', strtotime($article['created_at'])); ?></div></div>
                <?php if ($article['published_at']): ?>
                    <div><div class="text-gray-500">发布时间</div><div class="mt-1 text-gray-900"><?php echo date('Y-m-d H:i:s', strtotime($article['published_at'])); ?></div></div>
                <?php endif; ?>
                <div><div class="text-gray-500">最后更新</div><div class="mt-1 text-gray-900"><?php echo date('Y-m-d H:i:s', strtotime($article['updated_at'])); ?></div></div>
            </div>
        </div>

        <div class="bg-white shadow rounded-lg">
            <div class="px-6 py-4 border-b border-gray-200">
                <h3 class="text-lg font-medium text-gray-900">状态管理</h3>
            </div>
            <div class="px-6 py-4 space-y-4">
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-2">发布状态</label>
                    <form method="POST" class="inline">
                        <input type="hidden" name="csrf_token" value="<?php echo generate_csrf_token(); ?>">
                        <input type="hidden" name="action" value="update_status">
                        <select name="status" onchange="this.form.submit()" class="block w-full border-gray-300 rounded-md shadow-sm focus:ring-blue-500 focus:border-blue-500 sm:text-sm">
                            <option value="draft" <?php echo $article['status'] === 'draft' ? 'selected' : ''; ?>>草稿</option>
                            <option value="published" <?php echo $article['status'] === 'published' ? 'selected' : ''; ?>>已发布</option>
                            <option value="private" <?php echo $article['status'] === 'private' ? 'selected' : ''; ?>>私有</option>
                        </select>
                    </form>
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-2">审核结果</label>
                    <form method="POST" class="inline">
                        <input type="hidden" name="csrf_token" value="<?php echo generate_csrf_token(); ?>">
                        <input type="hidden" name="action" value="update_review">
                        <select name="review_status" onchange="this.form.submit()" class="block w-full border-gray-300 rounded-md shadow-sm focus:ring-blue-500 focus:border-blue-500 sm:text-sm">
                            <option value="pending" <?php echo $article['review_status'] === 'pending' ? 'selected' : ''; ?>>待人工审核</option>
                            <option value="approved" <?php echo $article['review_status'] === 'approved' ? 'selected' : ''; ?>>人工通过</option>
                            <option value="rejected" <?php echo $article['review_status'] === 'rejected' ? 'selected' : ''; ?>>已拒绝</option>
                            <option value="auto_approved" <?php echo $article['review_status'] === 'auto_approved' ? 'selected' : ''; ?>>自动通过</option>
                        </select>
                    </form>
                    <p class="mt-2 text-xs text-gray-500">调整发布状态或审核结果时，系统会自动同步发布时间，并避免写出冲突流程状态。</p>
                </div>
            </div>
        </div>

        <div class="bg-white shadow rounded-lg">
            <div class="px-6 py-4 border-b border-gray-200">
                <h3 class="text-lg font-medium text-gray-900">SEO信息</h3>
            </div>
            <div class="px-6 py-4 space-y-4 text-sm">
                <?php if ($article['keywords']): ?><div><div class="text-gray-500">关键词</div><div class="mt-1 text-gray-900"><?php echo htmlspecialchars($article['keywords']); ?></div></div><?php endif; ?>
                <?php if ($article['meta_description']): ?><div><div class="text-gray-500">描述</div><div class="mt-1 text-gray-900"><?php echo htmlspecialchars($article['meta_description']); ?></div></div><?php endif; ?>
                <?php if ($article['original_keyword']): ?><div><div class="text-gray-500">原始关键词</div><div class="mt-1 text-gray-900"><?php echo htmlspecialchars($article['original_keyword']); ?></div></div><?php endif; ?>
            </div>
        </div>

        <div class="bg-white shadow rounded-lg">
            <div class="px-6 py-4 border-b border-gray-200">
                <h3 class="text-lg font-medium text-gray-900">快速操作</h3>
            </div>
            <div class="px-6 py-4 space-y-3">
                <a href="article-edit.php?id=<?php echo (int) $article['id']; ?>" class="w-full inline-flex items-center justify-center px-4 py-2 border border-gray-300 text-sm font-medium rounded-md text-gray-700 bg-white hover:bg-gray-50">
                    <i data-lucide="edit" class="w-4 h-4 mr-2"></i>编辑文章
                </a>
                <?php if ($article['status'] === 'published'): ?>
                    <a href="../article/<?php echo urlencode((string) $article['slug']); ?>" target="_blank" class="w-full inline-flex items-center justify-center px-4 py-2 border border-transparent text-sm font-medium rounded-md text-white bg-blue-600 hover:bg-blue-700">
                        <i data-lucide="external-link" class="w-4 h-4 mr-2"></i>前台查看
                    </a>
                <?php endif; ?>
                <button onclick="deleteArticle()" class="w-full inline-flex items-center justify-center px-4 py-2 border border-transparent text-sm font-medium rounded-md text-white bg-red-600 hover:bg-red-700">
                    <i data-lucide="trash-2" class="w-4 h-4 mr-2"></i>删除文章
                </button>
            </div>
        </div>
    </div>
</div>

<script>
    document.addEventListener('DOMContentLoaded', function () {
        const content = <?php echo json_encode($article['content']); ?>;
        const contentElement = document.getElementById('article-content');
        contentElement.innerHTML = typeof marked !== 'undefined' ? marked.parse(content) : content.replace(/\n/g, '<br>');
    });

    function deleteArticle() {
        if (!confirm('确定要删除这篇文章吗？')) {
            return;
        }
        const form = document.createElement('form');
        form.method = 'POST';
        form.action = 'articles.php';
        form.innerHTML = `
            <input type="hidden" name="csrf_token" value="<?php echo generate_csrf_token(); ?>">
            <input type="hidden" name="action" value="delete_articles">
            <input type="hidden" name="article_ids[]" value="<?php echo (int) $article['id']; ?>">
        `;
        document.body.appendChild(form);
        form.submit();
    }
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
