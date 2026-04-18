<?php
/**
 * 智能GEO内容系统 - 创建文章
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

require_admin_login();
session_write_close();

$message = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
        $error = __('message.csrf_failed');
    } else {
        try {
            $title = trim($_POST['title'] ?? '');
            $content = trim($_POST['content'] ?? '');
            $excerpt = trim($_POST['excerpt'] ?? '');
            $keywords = trim($_POST['keywords'] ?? '');
            $meta_description = trim($_POST['meta_description'] ?? '');
            $category_id = (int) ($_POST['category_id'] ?? 0);
            $author_id = (int) ($_POST['author_id'] ?? 0);
            $workflowState = normalize_article_workflow_state(
                $_POST['status'] ?? 'draft',
                $_POST['review_status'] ?? 'pending'
            );
            $status = $workflowState['status'];
            $review_status = $workflowState['review_status'];
            $published_at = $workflowState['published_at'];

            if ($title === '') {
                $error = __('article_create.error.title_required');
            } elseif ($content === '') {
                $error = __('article_create.error.content_required');
            } elseif ($category_id <= 0) {
                $error = __('article_create.error.category_required');
            } elseif ($author_id <= 0) {
                $error = __('article_create.error.author_required');
            } else {
                $slug = generate_unique_article_slug($db, $title);

                if ($excerpt === '') {
                    $excerpt = mb_substr(strip_tags($content), 0, 200);
                }

                $stmt = $db->prepare("
                    INSERT INTO articles (
                        title, slug, content, excerpt, keywords, meta_description,
                        category_id, author_id, status, review_status,
                        is_ai_generated, created_at, updated_at, published_at
                    ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 0, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP, ?)
                ");

                    $result = $stmt->execute([
                        $title,
                        $slug,
                        $content,
                        $excerpt,
                        $keywords,
                        $meta_description,
                        $category_id,
                        $author_id,
                        $status,
                        $review_status,
                        $published_at
                ]);

                if ($result) {
                    $article_id = db_last_insert_id($db, 'articles');
                    header("Location: article-view.php?id={$article_id}");
                    exit;
                }

                $error = __('article_create.error.create_failed');
            }
        } catch (Exception $e) {
            $error = __('article_create.error.create_exception', ['message' => $e->getMessage()]);
        }
    }
}

$categories = $db->query("SELECT * FROM categories ORDER BY name")->fetchAll();
$authors = $db->query("SELECT * FROM authors ORDER BY name")->fetchAll();

$page_title = __('article_create.page_title');
$page_header = '
<div class="flex items-center space-x-4">
    <a href="articles.php" class="text-gray-400 hover:text-gray-600">
        <i data-lucide="arrow-left" class="w-5 h-5"></i>
    </a>
    <div>
        <h1 class="text-2xl font-bold text-gray-900">' . htmlspecialchars(__('article_create.page_heading'), ENT_QUOTES, 'UTF-8') . '</h1>
        <p class="mt-1 text-sm text-gray-600">' . htmlspecialchars(__('article_create.page_subtitle'), ENT_QUOTES, 'UTF-8') . '</p>
    </div>
</div>';
$additional_css = '
<script src="https://cdn.jsdelivr.net/npm/marked/marked.min.js"></script>
<style>
    .editor-container {
        display: grid;
        grid-template-columns: 1fr 1fr;
        gap: 1rem;
        height: 500px;
    }
    .editor-textarea {
        resize: none;
        font-family: Monaco, Menlo, "Ubuntu Mono", monospace;
        font-size: 14px;
        line-height: 1.5;
    }
    .preview-content {
        border: 1px solid #d1d5db;
        border-radius: 0.375rem;
        padding: 1rem;
        background-color: #f9fafb;
        overflow-y: auto;
        line-height: 1.6;
    }
    .preview-content h1, .preview-content h2, .preview-content h3 {
        margin-top: 1.5em;
        margin-bottom: 0.5em;
        font-weight: 600;
    }
    .preview-content h1 { font-size: 1.5em; }
    .preview-content h2 { font-size: 1.3em; }
    .preview-content h3 { font-size: 1.1em; }
    .preview-content p { margin-bottom: 1em; }
    .preview-content ul, .preview-content ol { margin-bottom: 1em; padding-left: 1.5em; }
    .preview-content img { max-width: 100%; height: auto; margin: 1em 0; }
    .preview-content blockquote {
        border-left: 4px solid #e5e7eb;
        padding-left: 1em;
        margin: 1em 0;
        color: #6b7280;
    }
    .preview-content code {
        background-color: #f3f4f6;
        padding: 0.2em 0.4em;
        border-radius: 0.25em;
        font-size: 0.9em;
    }
    .preview-content pre {
        background-color: #f3f4f6;
        padding: 1em;
        border-radius: 0.5em;
        overflow-x: auto;
        margin: 1em 0;
    }
</style>';

require_once __DIR__ . '/includes/header.php';
?>

<form method="POST" class="space-y-8">
    <input type="hidden" name="csrf_token" value="<?php echo generate_csrf_token(); ?>">

    <div class="grid grid-cols-1 lg:grid-cols-4 gap-6">
        <div class="lg:col-span-3 space-y-6">
            <div class="bg-white shadow rounded-lg">
                <div class="px-6 py-4 border-b border-gray-200">
                    <h3 class="text-lg font-medium text-gray-900"><?php echo __('article_create.section.basic_title'); ?></h3>
                </div>
                <div class="px-6 py-4 space-y-6">
                    <div>
                        <label class="block text-sm font-medium text-gray-700"><?php echo __('article_create.field.title'); ?> *</label>
                        <input type="text" name="title" required value="<?php echo htmlspecialchars($_POST['title'] ?? ''); ?>" class="mt-1 block w-full border-gray-300 rounded-md shadow-sm focus:ring-blue-500 focus:border-blue-500 sm:text-sm" placeholder="<?php echo htmlspecialchars(__('article_create.placeholder.title'), ENT_QUOTES, 'UTF-8'); ?>">
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700"><?php echo __('article_create.field.excerpt'); ?></label>
                        <textarea name="excerpt" rows="3" class="mt-1 block w-full border-gray-300 rounded-md shadow-sm focus:ring-blue-500 focus:border-blue-500 sm:text-sm" placeholder="<?php echo htmlspecialchars(__('article_create.placeholder.excerpt'), ENT_QUOTES, 'UTF-8'); ?>"><?php echo htmlspecialchars($_POST['excerpt'] ?? ''); ?></textarea>
                    </div>
                </div>
            </div>

            <div class="bg-white shadow rounded-lg">
                <div class="px-6 py-4 border-b border-gray-200">
                    <div class="flex items-center justify-between">
                        <h3 class="text-lg font-medium text-gray-900"><?php echo __('article_create.section.content_title'); ?></h3>
                        <div class="flex items-center space-x-2">
                            <span class="text-sm text-gray-500"><?php echo __('article_create.help.markdown_supported'); ?></span>
                            <button type="button" onclick="togglePreview()" class="inline-flex items-center px-3 py-1.5 border border-gray-300 text-xs font-medium rounded text-gray-700 bg-white hover:bg-gray-50">
                                <i data-lucide="eye" class="w-4 h-4 mr-1"></i>
                                <span id="preview-toggle-text"><?php echo __('article_create.button.show_preview'); ?></span>
                            </button>
                        </div>
                    </div>
                </div>
                <div class="px-6 py-4">
                    <div id="editor-single" class="block">
                        <textarea name="content" id="content-textarea" required class="block w-full h-96 border-gray-300 rounded-md shadow-sm focus:ring-blue-500 focus:border-blue-500 sm:text-sm editor-textarea" placeholder="<?php echo htmlspecialchars(__('article_create.placeholder.content'), ENT_QUOTES, 'UTF-8'); ?>"><?php echo htmlspecialchars($_POST['content'] ?? ''); ?></textarea>
                    </div>
                    <div id="editor-split" class="hidden editor-container">
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-2"><?php echo __('button.edit'); ?></label>
                            <textarea id="content-textarea-split" class="block w-full h-full border-gray-300 rounded-md shadow-sm focus:ring-blue-500 focus:border-blue-500 sm:text-sm editor-textarea" placeholder="<?php echo htmlspecialchars(__('article_create.placeholder.content'), ENT_QUOTES, 'UTF-8'); ?>"><?php echo htmlspecialchars($_POST['content'] ?? ''); ?></textarea>
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-2"><?php echo __('button.view'); ?></label>
                            <div id="content-preview" class="preview-content h-full"></div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="bg-white shadow rounded-lg">
                <div class="px-6 py-4 border-b border-gray-200">
                    <h3 class="text-lg font-medium text-gray-900"><?php echo __('article_create.section.seo_title'); ?></h3>
                </div>
                <div class="px-6 py-4 space-y-6">
                    <div>
                        <label class="block text-sm font-medium text-gray-700"><?php echo __('article_create.field.keywords'); ?></label>
                        <input type="text" name="keywords" value="<?php echo htmlspecialchars($_POST['keywords'] ?? ''); ?>" class="mt-1 block w-full border-gray-300 rounded-md shadow-sm focus:ring-blue-500 focus:border-blue-500 sm:text-sm" placeholder="<?php echo htmlspecialchars(__('article_create.placeholder.keywords'), ENT_QUOTES, 'UTF-8'); ?>">
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700"><?php echo __('article_create.field.meta_description'); ?></label>
                        <textarea name="meta_description" rows="3" class="mt-1 block w-full border-gray-300 rounded-md shadow-sm focus:ring-blue-500 focus:border-blue-500 sm:text-sm" placeholder="<?php echo htmlspecialchars(__('article_create.placeholder.meta_description'), ENT_QUOTES, 'UTF-8'); ?>"><?php echo htmlspecialchars($_POST['meta_description'] ?? ''); ?></textarea>
                    </div>
                </div>
            </div>
        </div>

        <div class="space-y-6">
            <div class="bg-white shadow rounded-lg">
                <div class="px-6 py-4 border-b border-gray-200">
                    <h3 class="text-lg font-medium text-gray-900"><?php echo __('article_create.section.publish_title'); ?></h3>
                </div>
                <div class="px-6 py-4 space-y-4">
                    <div>
                        <label class="block text-sm font-medium text-gray-700"><?php echo __('article_create.field.publish_status'); ?></label>
                        <select name="status" class="mt-1 block w-full border-gray-300 rounded-md shadow-sm focus:ring-blue-500 focus:border-blue-500 sm:text-sm">
                            <option value="draft" <?php echo ($_POST['status'] ?? 'draft') === 'draft' ? 'selected' : ''; ?>><?php echo __('articles.status.draft'); ?></option>
                            <option value="published" <?php echo ($_POST['status'] ?? '') === 'published' ? 'selected' : ''; ?>><?php echo __('articles.status.published'); ?></option>
                            <option value="private" <?php echo ($_POST['status'] ?? '') === 'private' ? 'selected' : ''; ?>><?php echo __('articles.status.private'); ?></option>
                        </select>
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700"><?php echo __('article_create.field.review_status'); ?></label>
                        <select name="review_status" class="mt-1 block w-full border-gray-300 rounded-md shadow-sm focus:ring-blue-500 focus:border-blue-500 sm:text-sm">
                            <option value="pending" <?php echo ($_POST['review_status'] ?? 'pending') === 'pending' ? 'selected' : ''; ?>><?php echo __('articles.review.pending'); ?></option>
                            <option value="approved" <?php echo ($_POST['review_status'] ?? '') === 'approved' ? 'selected' : ''; ?>><?php echo __('articles.review.approved'); ?></option>
                            <option value="rejected" <?php echo ($_POST['review_status'] ?? '') === 'rejected' ? 'selected' : ''; ?>><?php echo __('articles.review.rejected'); ?></option>
                            <option value="auto_approved" <?php echo ($_POST['review_status'] ?? '') === 'auto_approved' ? 'selected' : ''; ?>><?php echo __('articles.review.auto_approved'); ?></option>
                        </select>
                        <p class="mt-2 text-xs text-gray-500"><?php echo __('article_create.help.review_status'); ?></p>
                    </div>
                </div>
            </div>

            <div class="bg-white shadow rounded-lg">
                <div class="px-6 py-4 border-b border-gray-200">
                    <h3 class="text-lg font-medium text-gray-900"><?php echo __('article_create.section.category_author_title'); ?></h3>
                </div>
                <div class="px-6 py-4 space-y-4">
                    <div>
                        <label class="block text-sm font-medium text-gray-700"><?php echo __('article_create.field.category'); ?> *</label>
                        <select name="category_id" required class="mt-1 block w-full border-gray-300 rounded-md shadow-sm focus:ring-blue-500 focus:border-blue-500 sm:text-sm">
                            <option value="" disabled <?php echo empty($_POST['category_id']) ? 'selected' : ''; ?>><?php echo __('article_create.option.select_category'); ?></option>
                            <?php foreach ($categories as $category): ?>
                                <option value="<?php echo (int) $category['id']; ?>" <?php echo ($_POST['category_id'] ?? '') == $category['id'] ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($category['name']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700"><?php echo __('article_create.field.author'); ?> *</label>
                        <select name="author_id" required class="mt-1 block w-full border-gray-300 rounded-md shadow-sm focus:ring-blue-500 focus:border-blue-500 sm:text-sm">
                            <option value="" disabled <?php echo empty($_POST['author_id']) ? 'selected' : ''; ?>><?php echo __('article_create.option.select_author'); ?></option>
                            <?php foreach ($authors as $author): ?>
                                <option value="<?php echo (int) $author['id']; ?>" <?php echo ($_POST['author_id'] ?? '') == $author['id'] ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($author['name']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
            </div>

            <div class="bg-white shadow rounded-lg">
                <div class="px-6 py-4 space-y-3">
                    <button type="submit" class="w-full inline-flex items-center justify-center px-4 py-2 border border-transparent text-sm font-medium rounded-md text-white bg-blue-600 hover:bg-blue-700">
                        <i data-lucide="save" class="w-4 h-4 mr-2"></i>
                        <?php echo __('button.create_article'); ?>
                    </button>
                    <a href="articles.php" class="w-full inline-flex items-center justify-center px-4 py-2 border border-gray-300 text-sm font-medium rounded-md text-gray-700 bg-white hover:bg-gray-50">
                        <i data-lucide="x" class="w-4 h-4 mr-2"></i>
                        <?php echo __('button.cancel'); ?>
                    </a>
                </div>
            </div>

            <div class="bg-white shadow rounded-lg">
                <div class="px-6 py-4 border-b border-gray-200">
                    <h3 class="text-lg font-medium text-gray-900"><?php echo __('article_create.section.markdown_help_title'); ?></h3>
                </div>
                <div class="px-6 py-4">
                    <div class="text-xs text-gray-600 space-y-1">
                        <div><code># Heading 1</code></div>
                        <div><code>## Heading 2</code></div>
                        <div><code>**Bold**</code></div>
                        <div><code>*Italic*</code></div>
                        <div><code>[Link](URL)</code></div>
                        <div><code>![Image](URL)</code></div>
                        <div><code>- List item</code></div>
                        <div><code>> Quote</code></div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</form>

<script>
    let previewMode = false;

    function togglePreview() {
        const editorSingle = document.getElementById('editor-single');
        const editorSplit = document.getElementById('editor-split');
        const toggleText = document.getElementById('preview-toggle-text');
        const contentTextarea = document.getElementById('content-textarea');
        const contentTextareaSplit = document.getElementById('content-textarea-split');

        previewMode = !previewMode;

        if (previewMode) {
            contentTextareaSplit.value = contentTextarea.value;
            editorSingle.classList.add('hidden');
            editorSplit.classList.remove('hidden');
            toggleText.textContent = '<?php echo addslashes(__('article_create.button.hide_preview')); ?>';
            updatePreview();
            contentTextareaSplit.addEventListener('input', function () {
                contentTextarea.value = this.value;
                updatePreview();
            });
        } else {
            contentTextarea.value = contentTextareaSplit.value;
            editorSplit.classList.add('hidden');
            editorSingle.classList.remove('hidden');
            toggleText.textContent = '<?php echo addslashes(__('article_create.button.show_preview')); ?>';
        }
    }

    function updatePreview() {
        const content = document.getElementById('content-textarea-split').value;
        const preview = document.getElementById('content-preview');
        preview.innerHTML = typeof marked !== 'undefined' ? marked.parse(content) : content.replace(/\n/g, '<br>');
    }
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
