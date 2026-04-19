<?php
/**
 * AI知识库详情页面
 */

define('FEISHU_TREASURE', true);
session_start();

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/database_admin.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/knowledge-retrieval.php';

// 检查管理员登录
require_admin_login();
session_write_close();

$message = '';
$error = '';

// 获取知识库ID
$knowledge_id = intval($_GET['id'] ?? 0);

if ($knowledge_id <= 0) {
    header('Location: knowledge-bases.php');
    exit;
}

// 获取知识库详情
try {
    $stmt = $db->prepare("SELECT * FROM knowledge_bases WHERE id = ?");
    $stmt->execute([$knowledge_id]);
    $knowledge = $stmt->fetch();
    
    if (!$knowledge) {
        header('Location: knowledge-bases.php');
        exit;
    }
} catch (Exception $e) {
    $error = __('knowledge_bases.message.update_error', ['message' => $e->getMessage()]);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
        $error = __('message.csrf_failed');
    } else {
        switch ($_POST['action']) {
            case 'update_knowledge':
                $name = trim($_POST['name'] ?? '');
                $description = trim($_POST['description'] ?? '');
                $content = trim($_POST['content'] ?? '');
                
                if (empty($name)) {
                    $error = __('knowledge_bases.error.name_required');
                } elseif (empty($content)) {
                    $error = __('knowledge_bases.error.content_required');
                } else {
                    try {
                        $word_count = mb_strlen(strip_tags($content));
                        $db->beginTransaction();
                        
                        $stmt = $db->prepare("
                            UPDATE knowledge_bases 
                            SET name = ?, description = ?, content = ?, word_count = ?, updated_at = CURRENT_TIMESTAMP 
                            WHERE id = ?
                        ");
                        
                        if ($stmt->execute([$name, $description, $content, $word_count, $knowledge_id])) {
                            $chunk_count = knowledge_retrieval_sync_chunks($db, $knowledge_id, $content);
                            $db->commit();
                            $message = __('knowledge_bases.message.update_success', ['count' => $chunk_count]);
                            // 重新获取更新后的数据
                            $stmt = $db->prepare("SELECT * FROM knowledge_bases WHERE id = ?");
                            $stmt->execute([$knowledge_id]);
                            $knowledge = $stmt->fetch();
                        } else {
                            $db->rollBack();
                            $error = __('knowledge_bases.message.update_failed');
                        }
                    } catch (Exception $e) {
                        if ($db->inTransaction()) {
                            $db->rollBack();
                        }
                        $error = __('knowledge_bases.message.update_error', ['message' => $e->getMessage()]);
                    }
                }
                break;
        }
    }
}

// 获取使用此知识库的任务
$related_tasks = [];
$knowledge_chunk_count = 0;
$vectorized_chunk_count = 0;
$chunk_preview_rows = [];
$default_embedding_model = null;
$pgvector_enabled = false;
try {
    $stmt = $db->prepare("
        SELECT id, name, status, created_at 
        FROM tasks 
        WHERE knowledge_base_id = ? 
        ORDER BY created_at DESC
    ");
    $stmt->execute([$knowledge_id]);
    $related_tasks = $stmt->fetchAll();

    $chunkStmt = $db->prepare("SELECT COUNT(*) FROM knowledge_chunks WHERE knowledge_base_id = ?");
    $chunkStmt->execute([$knowledge_id]);
    $knowledge_chunk_count = (int) $chunkStmt->fetchColumn();

    $vectorizedStmt = $db->prepare("
        SELECT COUNT(*)
        FROM knowledge_chunks
        WHERE knowledge_base_id = ?
          AND embedding_model_id IS NOT NULL
          AND embedding_dimensions > 0
    ");
    $vectorizedStmt->execute([$knowledge_id]);
    $vectorized_chunk_count = (int) $vectorizedStmt->fetchColumn();

    $chunkPreviewStmt = $db->prepare("
        SELECT
            chunk_index,
            token_count,
            CHAR_LENGTH(content) AS content_length,
            embedding_model_id,
            embedding_dimensions,
            embedding_provider,
            LEFT(REPLACE(REPLACE(content, CHR(10), ' '), CHR(13), ' '), 180) AS content_preview
        FROM knowledge_chunks
        WHERE knowledge_base_id = ?
        ORDER BY chunk_index ASC
        LIMIT 20
    ");
    $chunkPreviewStmt->execute([$knowledge_id]);
    $chunk_preview_rows = $chunkPreviewStmt->fetchAll();

    $default_embedding_model = embedding_service_get_default_model($db);
    $pgvector_enabled = knowledge_retrieval_pgvector_storage_available($db);
} catch (Exception $e) {
    // 如果knowledge_base_id字段不存在，忽略错误
}

$page_title = __('knowledge_detail.page_title');
$page_header = '
<div class="flex items-center justify-between">
    <div class="flex items-center space-x-4">
        <a href="knowledge-bases.php" class="text-gray-400 hover:text-gray-600">
            <i data-lucide="arrow-left" class="w-5 h-5"></i>
        </a>
        <div>
            <h1 class="text-2xl font-bold text-gray-900">' . __('knowledge_detail.heading') . '</h1>
            <p class="mt-1 text-sm text-gray-600">' . __('knowledge_detail.subtitle') . '</p>
        </div>
    </div>
    <a href="knowledge-bases.php" class="inline-flex items-center px-4 py-2 border border-gray-300 text-sm font-medium rounded-md text-gray-700 bg-white hover:bg-gray-50">
        <i data-lucide="list" class="w-4 h-4 mr-2"></i>
        ' . __('knowledge_detail.back_to_list') . '
    </a>
</div>
';

require_once __DIR__ . '/includes/header.php';
?>

<div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
    <div class="lg:col-span-2">
        <?php if ($message): ?>
            <div class="mb-6 bg-green-50 border border-green-200 rounded-md p-4">
                <div class="flex items-start">
                    <i data-lucide="check-circle" class="w-5 h-5 text-green-400 mt-0.5"></i>
                    <div class="ml-3">
                        <p class="text-sm font-medium text-green-800"><?php echo htmlspecialchars($message); ?></p>
                    </div>
                </div>
            </div>
        <?php endif; ?>

        <?php if ($error): ?>
            <div class="mb-6 bg-red-50 border border-red-200 rounded-md p-4">
                <div class="flex items-start">
                    <i data-lucide="alert-circle" class="w-5 h-5 text-red-400 mt-0.5"></i>
                    <div class="ml-3">
                        <p class="text-sm font-medium text-red-800"><?php echo htmlspecialchars($error); ?></p>
                    </div>
                </div>
            </div>
        <?php endif; ?>

        <div class="bg-white shadow rounded-lg overflow-hidden">
            <div class="px-6 py-4 border-b border-gray-200">
                <h3 class="text-lg font-medium text-gray-900"><?php echo __('knowledge_detail.content_title'); ?></h3>
            </div>

            <form method="POST" class="p-6">
                <input type="hidden" name="csrf_token" value="<?php echo generate_csrf_token(); ?>">
                <input type="hidden" name="action" value="update_knowledge">

                <div class="space-y-6">
                    <div>
                        <label for="name" class="block text-sm font-medium text-gray-700"><?php echo __('knowledge_detail.field_name'); ?></label>
                        <input
                            type="text"
                            name="name"
                            id="name"
                            value="<?php echo htmlspecialchars($knowledge['name']); ?>"
                            class="mt-1 block w-full border-gray-300 rounded-md shadow-sm focus:ring-orange-500 focus:border-orange-500"
                            required
                        >
                    </div>

                    <div>
                        <label for="description" class="block text-sm font-medium text-gray-700"><?php echo __('knowledge_detail.field_description'); ?></label>
                        <textarea
                            name="description"
                            id="description"
                            rows="3"
                            class="mt-1 block w-full border-gray-300 rounded-md shadow-sm focus:ring-orange-500 focus:border-orange-500"
                            placeholder="<?php echo htmlspecialchars(__('knowledge_detail.placeholder_description')); ?>"
                        ><?php echo htmlspecialchars($knowledge['description'] ?? ''); ?></textarea>
                    </div>

                    <div>
                        <label for="content" class="block text-sm font-medium text-gray-700"><?php echo __('knowledge_detail.field_content'); ?></label>
                        <textarea
                            name="content"
                            id="content"
                            rows="20"
                            class="mt-1 block w-full border-gray-300 rounded-md shadow-sm focus:ring-orange-500 focus:border-orange-500 font-mono text-sm"
                            required
                        ><?php echo htmlspecialchars($knowledge['content']); ?></textarea>
                        <p class="mt-2 text-sm text-gray-500"><?php echo __('common.current_word_count', ['count' => '<span id="word-count">' . (int) ($knowledge['word_count'] ?? 0) . '</span>']); ?></p>
                    </div>

                    <div class="flex justify-end">
                        <button type="submit" class="inline-flex items-center px-4 py-2 border border-transparent text-sm font-medium rounded-md text-white bg-orange-600 hover:bg-orange-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-orange-500">
                            <i data-lucide="save" class="w-4 h-4 mr-2"></i>
                            <?php echo __('knowledge_detail.save_changes'); ?>
                        </button>
                    </div>
                </div>
            </form>
        </div>
    </div>

    <div class="space-y-6">
        <div class="bg-white shadow rounded-lg overflow-hidden">
            <div class="px-6 py-4 border-b border-gray-200">
                <h3 class="text-lg font-medium text-gray-900"><?php echo __('common.basic_info'); ?></h3>
            </div>
            <div class="p-6">
                <dl class="space-y-4">
                    <div>
                        <dt class="text-sm font-medium text-gray-500"><?php echo __('common.file_type'); ?></dt>
                        <dd class="mt-1">
                            <span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium <?php
                                echo $knowledge['file_type'] === 'markdown' ? 'bg-green-100 text-green-800' :
                                    ($knowledge['file_type'] === 'word' ? 'bg-blue-100 text-blue-800' : 'bg-gray-100 text-gray-800');
                            ?>">
                                <?php
                                switch ($knowledge['file_type']) {
                                    case 'markdown':
                                        echo __('status.markdown');
                                        break;
                                    case 'word':
                                        echo __('status.word_document');
                                        break;
                                    case 'text':
                                        echo __('status.text');
                                        break;
                                    default:
                                        echo __('status.unknown');
                                        break;
                                }
                                ?>
                            </span>
                        </dd>
                    </div>

                    <div>
                        <dt class="text-sm font-medium text-gray-500"><?php echo __('common.word_count'); ?></dt>
                        <dd class="mt-1 text-sm text-gray-900"><?php echo __('knowledge_bases.text_unit', ['count' => number_format((int) ($knowledge['word_count'] ?? 0))]); ?></dd>
                    </div>

                    <div>
                        <dt class="text-sm font-medium text-gray-500"><?php echo __('knowledge_detail.chunk_count'); ?></dt>
                        <dd class="mt-1 text-sm text-gray-900"><?php echo __('common.total_records', ['count' => number_format($knowledge_chunk_count)]); ?></dd>
                    </div>

                    <div>
                        <dt class="text-sm font-medium text-gray-500"><?php echo __('knowledge_detail.created_at'); ?></dt>
                        <dd class="mt-1 text-sm text-gray-900"><?php echo date('Y-m-d H:i:s', strtotime($knowledge['created_at'])); ?></dd>
                    </div>

                    <div>
                        <dt class="text-sm font-medium text-gray-500"><?php echo __('knowledge_detail.updated_at'); ?></dt>
                        <dd class="mt-1 text-sm text-gray-900"><?php echo date('Y-m-d H:i:s', strtotime($knowledge['updated_at'])); ?></dd>
                    </div>

                    <?php if (!empty($knowledge['file_path'])): ?>
                        <div>
                            <dt class="text-sm font-medium text-gray-500"><?php echo __('common.file_path'); ?></dt>
                            <dd class="mt-1 text-sm text-gray-900 break-all"><?php echo htmlspecialchars($knowledge['file_path']); ?></dd>
                        </div>
                    <?php endif; ?>
                </dl>
            </div>
        </div>

        <?php if (!empty($related_tasks)): ?>
            <div class="bg-white shadow rounded-lg overflow-hidden">
                <div class="px-6 py-4 border-b border-gray-200">
                    <h3 class="text-lg font-medium text-gray-900"><?php echo __('common.related_tasks'); ?></h3>
                </div>
                <div class="p-6">
                    <div class="space-y-3">
                        <?php foreach ($related_tasks as $task): ?>
                            <div class="flex items-center justify-between">
                                <div>
                                    <a href="task-edit.php?id=<?php echo $task['id']; ?>" class="text-sm font-medium text-gray-900 hover:text-orange-600">
                                        <?php echo htmlspecialchars($task['name']); ?>
                                    </a>
                                    <p class="text-xs text-gray-500"><?php echo date('Y-m-d', strtotime($task['created_at'])); ?></p>
                                </div>
                                <span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium <?php
                                    echo $task['status'] === 'active' ? 'bg-green-100 text-green-800' : 'bg-gray-100 text-gray-800';
                                ?>">
                                    <?php echo $task['status'] === 'active' ? __('status.running') : __('status.paused'); ?>
                                </span>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>
        <?php endif; ?>
    </div>
</div>

<div id="chunk-preview" class="mt-6 bg-white shadow rounded-lg overflow-hidden">
    <div class="px-6 py-4 border-b border-gray-200 flex items-center justify-between gap-4">
        <div>
            <h3 class="text-lg font-medium text-gray-900"><?php echo __('knowledge_detail.chunk_preview_title'); ?></h3>
            <p class="mt-1 text-sm text-gray-500"><?php echo __('knowledge_detail.chunk_preview_desc'); ?></p>
        </div>
        <div class="text-right text-sm text-gray-500 whitespace-nowrap">
            <div><?php echo __('knowledge_detail.chunk_count'); ?>: <span class="font-medium text-gray-900"><?php echo number_format($knowledge_chunk_count); ?></span></div>
            <div><?php echo __('knowledge_detail.vectorized_count'); ?>: <span class="font-medium text-gray-900"><?php echo number_format($vectorized_chunk_count); ?></span></div>
        </div>
    </div>

    <?php if ($knowledge_chunk_count > 0 && $vectorized_chunk_count === 0): ?>
        <div class="px-6 py-4 border-b border-gray-200">
            <?php if (!$pgvector_enabled): ?>
                <div class="rounded-md border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-800">
                    <?php echo __('knowledge_detail.vector_notice_pgvector_disabled'); ?>
                </div>
            <?php elseif (!$default_embedding_model): ?>
                <div class="rounded-md border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-800">
                    <?php echo __('knowledge_detail.vector_notice_no_model'); ?>
                    <button type="button" onclick="redirectToEmbeddingConfig()" class="ml-2 font-medium text-amber-900 underline hover:text-amber-950">
                        <?php echo __('knowledge_detail.vector_notice_configure_link'); ?>
                    </button>
                </div>
            <?php else: ?>
                <div class="rounded-md border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-800">
                    <?php echo __('knowledge_detail.vector_notice_fallback', ['model' => (string) ($default_embedding_model['name'] ?? __('status.unknown'))]); ?>
                </div>
            <?php endif; ?>
        </div>
    <?php endif; ?>

    <?php if (empty($chunk_preview_rows)): ?>
        <div class="px-6 py-8 text-center text-sm text-gray-500">
            <?php echo __('knowledge_detail.chunk_preview_empty'); ?>
        </div>
    <?php else: ?>
        <div class="overflow-x-auto">
            <table class="min-w-full divide-y divide-gray-200">
                <thead class="bg-gray-50">
                    <tr>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider"><?php echo __('knowledge_detail.chunk_index'); ?></th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider"><?php echo __('knowledge_detail.chunk_status'); ?></th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider"><?php echo __('knowledge_detail.chunk_length'); ?></th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider"><?php echo __('knowledge_detail.chunk_tokens'); ?></th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider"><?php echo __('knowledge_detail.chunk_embedding'); ?></th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider"><?php echo __('knowledge_detail.chunk_preview_column'); ?></th>
                    </tr>
                </thead>
                <tbody class="bg-white divide-y divide-gray-200">
                    <?php foreach ($chunk_preview_rows as $chunk_row): ?>
                        <?php $isVectorized = !empty($chunk_row['embedding_model_id']) && (int) $chunk_row['embedding_dimensions'] > 0; ?>
                        <tr>
                            <td class="px-6 py-4 whitespace-nowrap text-sm font-medium text-gray-900">#<?php echo (int) $chunk_row['chunk_index']; ?></td>
                            <td class="px-6 py-4 whitespace-nowrap text-sm">
                                <span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium <?php echo $isVectorized ? 'bg-green-100 text-green-800' : 'bg-amber-100 text-amber-800'; ?>">
                                    <?php echo $isVectorized ? __('knowledge_detail.chunk_status_vectorized') : __('knowledge_detail.chunk_status_fallback'); ?>
                                </span>
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-700"><?php echo __('knowledge_bases.text_unit', ['count' => number_format((int) $chunk_row['content_length'])]); ?></td>
                            <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-700"><?php echo number_format((int) $chunk_row['token_count']); ?></td>
                            <td class="px-6 py-4 text-sm text-gray-700">
                                <?php if ($isVectorized): ?>
                                    <div><?php echo htmlspecialchars((string) ($chunk_row['embedding_provider'] ?: __('status.unknown'))); ?></div>
                                    <div class="text-xs text-gray-500">
                                        <?php echo __('knowledge_detail.chunk_embedding_meta', [
                                            'model_id' => (int) $chunk_row['embedding_model_id'],
                                            'dimensions' => (int) $chunk_row['embedding_dimensions']
                                        ]); ?>
                                    </div>
                                <?php else: ?>
                                    <span class="text-xs text-gray-500"><?php echo __('knowledge_detail.chunk_embedding_none'); ?></span>
                                <?php endif; ?>
                            </td>
                            <td class="px-6 py-4 text-sm text-gray-700 max-w-2xl">
                                <div class="leading-6 whitespace-normal break-words"><?php echo htmlspecialchars(trim((string) $chunk_row['content_preview'])); ?></div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>

<div id="embedding-config-modal" class="hidden fixed inset-0 z-50">
    <div class="absolute inset-0 bg-slate-900/45"></div>
    <div class="relative flex min-h-screen items-center justify-center p-4">
        <div class="w-full max-w-lg rounded-2xl bg-white shadow-2xl ring-1 ring-slate-200">
            <div class="border-b border-slate-100 px-6 py-5">
                <h3 class="text-lg font-semibold text-slate-900"><?php echo __('knowledge_detail.vector_config_modal_title'); ?></h3>
            </div>
            <div class="px-6 py-5">
                <div class="text-sm leading-7 text-slate-600 whitespace-pre-line"><?php echo htmlspecialchars(__('knowledge_detail.vector_config_prompt')); ?></div>
            </div>
            <div class="flex items-center justify-end gap-3 border-t border-slate-100 px-6 py-4">
                <button type="button" onclick="hideEmbeddingConfigModal()" class="inline-flex items-center rounded-xl border border-slate-200 px-4 py-2 text-sm font-medium text-slate-700 hover:bg-slate-50">
                    <?php echo __('button.cancel'); ?>
                </button>
                <button type="button" onclick="goToEmbeddingConfig()" class="inline-flex items-center rounded-xl bg-amber-500 px-4 py-2 text-sm font-medium text-white hover:bg-amber-600">
                    <?php echo __('knowledge_detail.vector_notice_configure_link'); ?>
                </button>
            </div>
        </div>
    </div>
</div>

<script>
function redirectToEmbeddingConfig() {
    showEmbeddingConfigModal();
}

function showEmbeddingConfigModal() {
    const modal = document.getElementById('embedding-config-modal');
    if (!modal) {
        window.location.href = 'ai-models.php';
        return;
    }
    modal.classList.remove('hidden');
}

function hideEmbeddingConfigModal() {
    const modal = document.getElementById('embedding-config-modal');
    if (modal) {
        modal.classList.add('hidden');
    }
}

function goToEmbeddingConfig() {
    window.location.href = 'ai-models.php';
}

// 实时字数统计
document.getElementById('content').addEventListener('input', function() {
    const content = this.value;
    const wordCount = content.length;
    document.getElementById('word-count').textContent = wordCount.toLocaleString();
});

// 初始化图标
if (typeof lucide !== 'undefined') {
    lucide.createIcons();
}

window.addEventListener('click', function(event) {
    const modal = document.getElementById('embedding-config-modal');
    if (modal && (event.target === modal || event.target === modal.firstElementChild)) {
        hideEmbeddingConfigModal();
    }
});
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
