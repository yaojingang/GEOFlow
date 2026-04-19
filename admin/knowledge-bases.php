<?php
/**
 * 智能GEO内容系统 - AI知识库管理
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
require_once __DIR__ . '/includes/knowledge-base-helpers.php';
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
            case 'create_knowledge':
                $name = trim($_POST['name'] ?? '');
                $description = trim($_POST['description'] ?? '');
                $content = trim($_POST['content'] ?? '');
                $file_type = $_POST['file_type'] ?? 'markdown';
                
                if (empty($name)) {
                    $error = __('knowledge_bases.error.name_required');
                } elseif (empty($content)) {
                    $error = __('knowledge_bases.error.content_required');
                } else {
                    try {
                        $word_count = mb_strlen(strip_tags($content));
                        $db->beginTransaction();

                        $stmt = $db->prepare("
                            INSERT INTO knowledge_bases (name, description, content, file_type, word_count, created_at, updated_at) 
                            VALUES (?, ?, ?, ?, ?, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)
                        ");
                        
                        if ($stmt->execute([$name, $description, $content, $file_type, $word_count])) {
                            $knowledge_id = db_last_insert_id($db, 'knowledge_bases');
                            $chunk_count = knowledge_retrieval_sync_chunks($db, $knowledge_id, $content);
                            $db->commit();
                            $message = __('knowledge_bases.message.create_success', ['count' => $chunk_count]);
                        } else {
                            $db->rollBack();
                            $error = __('knowledge_bases.message.create_failed');
                        }
                    } catch (Exception $e) {
                        if ($db->inTransaction()) {
                            $db->rollBack();
                        }
                        $error = __('knowledge_bases.message.create_error', ['message' => $e->getMessage()]);
                    }
                }
                break;
                
            case 'delete_knowledge':
                $knowledge_id = intval($_POST['knowledge_id'] ?? 0);
                
                if ($knowledge_id > 0) {
                    try {
                        $references = get_knowledge_base_task_references($db, $knowledge_id);
                        if ($references['count'] > 0) {
                            $taskLabels = array_map(
                                static fn(array $task): string => '#' . (int) $task['id'] . ' ' . (string) $task['name'],
                                $references['tasks']
                            );
                            $error = __('knowledge_bases.error.in_use', ['count' => $references['count']]);
                            if (!empty($taskLabels)) {
                                $error .= __('knowledge_bases.task_references', ['tasks' => implode('、', $taskLabels)]);
                            }
                            break;
                        }

                        $lookupStmt = $db->prepare("SELECT file_path FROM knowledge_bases WHERE id = ?");
                        $lookupStmt->execute([$knowledge_id]);
                        $knowledge = $lookupStmt->fetch();
                        if (!$knowledge) {
                            throw new Exception(__('knowledge_bases.error.not_found'));
                        }

                        $db->beginTransaction();
                        $stmt = $db->prepare("DELETE FROM knowledge_bases WHERE id = ?");
                        
                        if ($stmt->execute([$knowledge_id])) {
                            $db->commit();
                            cleanup_knowledge_file($knowledge['file_path'] ?? '');
                            $message = __('knowledge_bases.message.delete_success');
                        } else {
                            $db->rollBack();
                            $error = __('knowledge_bases.message.delete_failed');
                        }
                    } catch (Throwable $e) {
                        if ($db->inTransaction()) {
                            $db->rollBack();
                        }
                        $error = __('knowledge_bases.message.delete_error', ['message' => $e->getMessage()]);
                    }
                }
                break;
                
            case 'upload_file':
                if (!isset($_FILES['knowledge_file']) || $_FILES['knowledge_file']['error'] !== UPLOAD_ERR_OK) {
                    $error = __('knowledge_bases.error.file_required');
                } else {
                    $file = $_FILES['knowledge_file'];
                    $name = trim($_POST['name'] ?? '');
                    $description = trim($_POST['description'] ?? '');
                    $filepath = '';
                    
                    if (empty($name)) {
                        $name = pathinfo($file['name'], PATHINFO_FILENAME);
                    }
                    
                    try {
                        $upload_dir = dirname(__DIR__) . '/uploads/knowledge/';
                        if (!is_dir($upload_dir)) {
                            mkdir($upload_dir, 0755, true);
                        }
                        
                        // 检查文件大小（限制为10MB）
                        $max_size = 10 * 1024 * 1024; // 10MB
                        if ($file['size'] > $max_size) {
                            $error = __('knowledge_bases.error.file_too_large');
                        } else {
                            $extension = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
                            $allowed_extensions = ['txt', 'md', 'docx'];

                            if (!in_array($extension, $allowed_extensions)) {
                                $error = __('knowledge_bases.error.file_type_invalid');
                            } else {
                            $filename = uniqid() . '.' . $extension;
                            $filepath = $upload_dir . $filename;
                            $relative_path = 'uploads/knowledge/' . $filename;
                            
                            if (move_uploaded_file($file['tmp_name'], $filepath)) {
                                $parsed = parse_uploaded_knowledge_file($filepath, $file['name'], $extension);
                                $content = $parsed['content'];
                                $file_type = $parsed['file_type'];
                                
                                $word_count = mb_strlen(strip_tags($content));
                                $db->beginTransaction();
                                
                                $stmt = $db->prepare("
                                    INSERT INTO knowledge_bases (name, description, content, file_type, file_path, word_count, created_at, updated_at) 
                                    VALUES (?, ?, ?, ?, ?, ?, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)
                                ");
                                
                                if ($stmt->execute([$name, $description, $content, $file_type, $relative_path, $word_count])) {
                                    $knowledge_id = db_last_insert_id($db, 'knowledge_bases');
                                    $chunk_count = knowledge_retrieval_sync_chunks($db, $knowledge_id, $content);
                                    $db->commit();
                                    $message = __('knowledge_bases.message.upload_success', ['count' => $chunk_count]);
                                } else {
                                    $db->rollBack();
                                    $error = __('knowledge_bases.message.save_failed');
                                    cleanup_knowledge_file($relative_path);
                                }
                            } else {
                                $error = __('knowledge_bases.message.upload_failed');
                            }
                        }
                    }
                    } catch (Exception $e) {
                        if ($db->inTransaction()) {
                            $db->rollBack();
                        }
                        if ($filepath !== '' && is_file($filepath)) {
                            @unlink($filepath);
                        }
                        $error = __('knowledge_bases.message.upload_error', ['message' => $e->getMessage()]);
                    }
                }
                break;
        }
    }
}

// 获取知识库列表
$knowledge_bases = $db->query("
    SELECT * FROM knowledge_bases 
    ORDER BY created_at DESC
")->fetchAll();

// 获取统计数据
$stats = [
    'total_knowledge' => count($knowledge_bases),
    'total_words' => $db->query("SELECT SUM(word_count) as total FROM knowledge_bases")->fetch()['total'] ?? 0,
    'markdown_count' => $db->query("SELECT COUNT(*) as count FROM knowledge_bases WHERE file_type = 'markdown'")->fetch()['count'],
    'word_count' => $db->query("SELECT COUNT(*) as count FROM knowledge_bases WHERE file_type = 'word'")->fetch()['count']
];

// 设置页面信息
$page_title = __('knowledge_bases.page_title');
$page_header = '
<div class="flex items-center justify-between">
    <div class="flex items-center space-x-4">
        <a href="materials.php" class="text-gray-400 hover:text-gray-600">
            <i data-lucide="arrow-left" class="w-5 h-5"></i>
        </a>
        <div>
            <h1 class="text-2xl font-bold text-gray-900">' . __('knowledge_bases.heading') . '</h1>
            <p class="mt-1 text-sm text-gray-600">' . __('knowledge_bases.subtitle') . '</p>
        </div>
    </div>
    <button onclick="showUploadModal()" class="inline-flex items-center px-4 py-2 border border-transparent text-sm font-medium rounded-md text-white bg-orange-600 hover:bg-orange-700">
        <i data-lucide="upload" class="w-4 h-4 mr-2"></i>
        ' . __('knowledge_bases.upload') . '
    </button>
</div>
';

// 包含头部模块
require_once __DIR__ . '/includes/header.php';
?>

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
                            <i data-lucide="brain" class="h-6 w-6 text-orange-600"></i>
                        </div>
                        <div class="ml-5 w-0 flex-1">
                            <dl>
                                <dt class="text-sm font-medium text-gray-500 truncate"><?php echo __('knowledge_bases.total'); ?></dt>
                                <dd class="text-lg font-medium text-gray-900"><?php echo $stats['total_knowledge']; ?></dd>
                            </dl>
                        </div>
                    </div>
                </div>
            </div>

            <div class="bg-white overflow-hidden shadow rounded-lg">
                <div class="p-5">
                    <div class="flex items-center">
                        <div class="flex-shrink-0">
                            <i data-lucide="file-text" class="h-6 w-6 text-blue-600"></i>
                        </div>
                        <div class="ml-5 w-0 flex-1">
                            <dl>
                                <dt class="text-sm font-medium text-gray-500 truncate"><?php echo __('knowledge_bases.total_words'); ?></dt>
                                <dd class="text-lg font-medium text-gray-900"><?php echo number_format($stats['total_words']); ?></dd>
                            </dl>
                        </div>
                    </div>
                </div>
            </div>

            <div class="bg-white overflow-hidden shadow rounded-lg">
                <div class="p-5">
                    <div class="flex items-center">
                        <div class="flex-shrink-0">
                            <i data-lucide="hash" class="h-6 w-6 text-green-600"></i>
                        </div>
                        <div class="ml-5 w-0 flex-1">
                            <dl>
                                <dt class="text-sm font-medium text-gray-500 truncate"><?php echo __('knowledge_bases.markdown_count'); ?></dt>
                                <dd class="text-lg font-medium text-gray-900"><?php echo $stats['markdown_count']; ?></dd>
                            </dl>
                        </div>
                    </div>
                </div>
            </div>

            <div class="bg-white overflow-hidden shadow rounded-lg">
                <div class="p-5">
                    <div class="flex items-center">
                        <div class="flex-shrink-0">
                            <i data-lucide="file" class="h-6 w-6 text-purple-600"></i>
                        </div>
                        <div class="ml-5 w-0 flex-1">
                            <dl>
                                <dt class="text-sm font-medium text-gray-500 truncate"><?php echo __('knowledge_bases.word_count'); ?></dt>
                                <dd class="text-lg font-medium text-gray-900"><?php echo $stats['word_count']; ?></dd>
                            </dl>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- 知识库列表 -->
        <div class="bg-white shadow rounded-lg">
            <div class="px-6 py-4 border-b border-gray-200">
                <h3 class="text-lg font-medium text-gray-900"><?php echo __('knowledge_bases.list_title'); ?></h3>
            </div>

            <?php if (empty($knowledge_bases)): ?>
                <div class="px-6 py-8 text-center">
                    <i data-lucide="brain" class="w-12 h-12 mx-auto text-gray-400 mb-4"></i>
                    <h3 class="text-lg font-medium text-gray-900 mb-2"><?php echo __('knowledge_bases.empty'); ?></h3>
                    <p class="text-gray-500 mb-4"><?php echo __('knowledge_bases.empty_desc'); ?></p>
                    <div class="flex justify-center space-x-2">
                        <button onclick="showCreateModal()" class="inline-flex items-center px-4 py-2 border border-transparent text-sm font-medium rounded-md text-white bg-orange-600 hover:bg-orange-700">
                            <i data-lucide="plus" class="w-4 h-4 mr-2"></i>
                            <?php echo __('knowledge_bases.create_first'); ?>
                        </button>
                        <button onclick="showUploadModal()" class="inline-flex items-center px-4 py-2 border border-gray-300 text-sm font-medium rounded-md text-gray-700 bg-white hover:bg-gray-50">
                            <i data-lucide="upload" class="w-4 h-4 mr-2"></i>
                            <?php echo __('knowledge_bases.upload_doc'); ?>
                        </button>
                    </div>
                </div>
            <?php else: ?>
                <div class="divide-y divide-gray-200">
                    <?php foreach ($knowledge_bases as $knowledge): ?>
                        <div class="px-6 py-6">
                            <div class="flex items-center justify-between">
                                <div class="flex-1">
                                    <div class="flex items-center space-x-3">
                                        <h4 class="text-lg font-medium text-gray-900">
                                            <a href="knowledge-base-detail.php?id=<?php echo $knowledge['id']; ?>" class="hover:text-orange-600">
                                                <?php echo htmlspecialchars($knowledge['name']); ?>
                                            </a>
                                        </h4>
                                        <span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium <?php 
                                            echo $knowledge['file_type'] === 'markdown' ? 'bg-green-100 text-green-800' : 
                                                ($knowledge['file_type'] === 'word' ? 'bg-purple-100 text-purple-800' : 'bg-blue-100 text-blue-800'); 
                                        ?>">
                                            <?php 
                                            echo $knowledge['file_type'] === 'markdown' ? 'Markdown' : 
                                                ($knowledge['file_type'] === 'word' ? __('status.word_document') : __('status.text')); 
                                            ?>
                                        </span>
                                        <span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium bg-orange-100 text-orange-800">
                                            <?php echo __('knowledge_bases.text_unit', ['count' => number_format($knowledge['word_count'])]); ?>
                                        </span>
                                    </div>
                                    <?php if ($knowledge['description']): ?>
                                        <p class="mt-1 text-sm text-gray-600"><?php echo htmlspecialchars($knowledge['description']); ?></p>
                                    <?php endif; ?>
                                    <div class="mt-2 flex items-center space-x-4 text-sm text-gray-500">
                                        <span><?php echo __('knowledge_bases.created_at', ['value' => date('Y-m-d H:i', strtotime($knowledge['created_at']))]); ?></span>
                                        <span><?php echo __('knowledge_bases.updated_at', ['value' => date('Y-m-d H:i', strtotime($knowledge['updated_at']))]); ?></span>
                                        <?php if ($knowledge['usage_count'] > 0): ?>
                                            <span><?php echo __('knowledge_bases.usage_count', ['count' => $knowledge['usage_count']]); ?></span>
                                        <?php endif; ?>
                                    </div>
                                </div>
                                
                                <div class="flex items-center space-x-2">
                                    <a href="knowledge-base-detail.php?id=<?php echo $knowledge['id']; ?>#chunk-preview" class="inline-flex items-center px-3 py-1.5 border border-blue-200 text-xs font-medium rounded text-blue-700 bg-blue-50 hover:bg-blue-100">
                                        <i data-lucide="rows-3" class="w-4 h-4 mr-1"></i>
                                        <?php echo __('button.chunks'); ?>
                                    </a>
                                    <a href="knowledge-base-detail.php?id=<?php echo $knowledge['id']; ?>" class="inline-flex items-center px-3 py-1.5 border border-gray-300 text-xs font-medium rounded text-gray-700 bg-white hover:bg-gray-50">
                                        <i data-lucide="eye" class="w-4 h-4 mr-1"></i>
                                        <?php echo __('button.view'); ?>
                                    </a>
                                    <button onclick="deleteKnowledge(<?php echo $knowledge['id']; ?>, '<?php echo htmlspecialchars($knowledge['name']); ?>')" class="inline-flex items-center px-3 py-1.5 border border-transparent text-xs font-medium rounded text-white bg-red-600 hover:bg-red-700">
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

    <!-- 创建知识库模态框 -->
    <div id="create-modal" class="hidden fixed inset-0 bg-gray-600 bg-opacity-50 overflow-y-auto h-full w-full z-50">
        <div class="relative top-10 mx-auto p-5 border w-2/3 max-w-4xl shadow-lg rounded-md bg-white">
            <div class="mt-3">
                <h3 class="text-lg font-medium text-gray-900 mb-4"><?php echo __('knowledge_bases.modal_create'); ?></h3>
                <form method="POST">
                    <input type="hidden" name="csrf_token" value="<?php echo generate_csrf_token(); ?>">
                    <input type="hidden" name="action" value="create_knowledge">
                    
                    <div class="space-y-4">
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                            <div>
                                <label class="block text-sm font-medium text-gray-700"><?php echo __('knowledge_bases.field_name'); ?></label>
                                <input type="text" name="name" required 
                                       class="mt-1 block w-full border-gray-300 rounded-md shadow-sm focus:ring-orange-500 focus:border-orange-500 sm:text-sm"
                                       placeholder="<?php echo htmlspecialchars(__('knowledge_bases.placeholder_name')); ?>">
                            </div>
                            
                            <div>
                                <label class="block text-sm font-medium text-gray-700"><?php echo __('knowledge_bases.field_doc_type'); ?></label>
                                <select name="file_type" class="mt-1 block w-full border-gray-300 rounded-md shadow-sm focus:ring-orange-500 focus:border-orange-500 sm:text-sm">
                                    <option value="markdown">Markdown</option>
                                    <option value="text"><?php echo __('status.text'); ?></option>
                                </select>
                            </div>
                        </div>
                        
                        <div>
                            <label class="block text-sm font-medium text-gray-700"><?php echo __('knowledge_bases.field_description'); ?></label>
                            <textarea name="description" rows="2"
                                      class="mt-1 block w-full border-gray-300 rounded-md shadow-sm focus:ring-orange-500 focus:border-orange-500 sm:text-sm"
                                      placeholder="<?php echo htmlspecialchars(__('knowledge_bases.placeholder_description')); ?>"></textarea>
                        </div>
                        
                        <div>
                            <label class="block text-sm font-medium text-gray-700"><?php echo __('knowledge_bases.field_content'); ?></label>
                            <textarea name="content" rows="15" required
                                      class="mt-1 block w-full border-gray-300 rounded-md shadow-sm focus:ring-orange-500 focus:border-orange-500 sm:text-sm font-mono"
                                      placeholder="<?php echo htmlspecialchars(__('knowledge_bases.placeholder_content')); ?>"></textarea>
                        </div>
                    </div>
                    
                    <div class="mt-6 flex justify-end space-x-3">
                        <button type="button" onclick="hideCreateModal()" class="px-4 py-2 border border-gray-300 rounded-md text-sm font-medium text-gray-700 hover:bg-gray-50">
                            <?php echo __('button.cancel'); ?>
                        </button>
                        <button type="submit" class="px-4 py-2 border border-transparent rounded-md shadow-sm text-sm font-medium text-white bg-orange-600 hover:bg-orange-700">
                            <?php echo __('knowledge_bases.create_first'); ?>
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- 上传文档模态框 -->
    <div id="upload-modal" class="hidden fixed inset-0 bg-gray-600 bg-opacity-50 overflow-y-auto h-full w-full z-50">
        <div class="relative top-20 mx-auto p-5 border w-96 shadow-lg rounded-md bg-white">
            <div class="mt-3">
                <h3 class="text-lg font-medium text-gray-900 mb-4"><?php echo __('knowledge_bases.modal_upload'); ?></h3>
                <form method="POST" enctype="multipart/form-data">
                    <input type="hidden" name="csrf_token" value="<?php echo generate_csrf_token(); ?>">
                    <input type="hidden" name="action" value="upload_file">
                    
                    <div class="space-y-4">
                        <div>
                            <label class="block text-sm font-medium text-gray-700"><?php echo __('common.name'); ?></label>
                            <input type="text" name="name" 
                                   class="mt-1 block w-full border-gray-300 rounded-md shadow-sm focus:ring-orange-500 focus:border-orange-500 sm:text-sm"
                                   placeholder="<?php echo htmlspecialchars(__('knowledge_bases.placeholder_name_optional')); ?>">
                        </div>
                        
                        <div>
                            <label class="block text-sm font-medium text-gray-700"><?php echo __('knowledge_bases.field_description'); ?></label>
                            <textarea name="description" rows="2"
                                      class="mt-1 block w-full border-gray-300 rounded-md shadow-sm focus:ring-orange-500 focus:border-orange-500 sm:text-sm"
                                      placeholder="<?php echo htmlspecialchars(__('knowledge_bases.placeholder_upload_description')); ?>"></textarea>
                        </div>
                        
                        <div>
                            <label class="block text-sm font-medium text-gray-700"><?php echo __('knowledge_bases.field_file'); ?></label>
                            <div class="mt-1">
                                <input
                                    type="file"
                                    id="knowledge-file-input"
                                    name="knowledge_file"
                                    required
                                    accept=".txt,.md,.docx"
                                    class="sr-only"
                                >
                                <label for="knowledge-file-input" class="flex items-center gap-3 rounded-md border border-gray-300 px-4 py-3 text-sm text-gray-600 cursor-pointer hover:border-orange-300 hover:bg-orange-50/40">
                                    <span class="inline-flex items-center rounded-full bg-orange-50 px-4 py-2 font-semibold text-orange-700">
                                        <?php echo __('knowledge_bases.file_choose'); ?>
                                    </span>
                                    <span id="knowledge-file-name" class="min-w-0 truncate text-gray-500">
                                        <?php echo __('knowledge_bases.file_none_selected'); ?>
                                    </span>
                                </label>
                            </div>
                        </div>
                        
                        <div class="text-sm text-gray-500">
                            <p class="mb-2"><?php echo __('knowledge_bases.format_help'); ?></p>
                            <ul class="list-disc list-inside space-y-1">
                                <li><?php echo __('knowledge_bases.format_txt'); ?></li>
                                <li><?php echo __('knowledge_bases.format_md'); ?></li>
                                <li><?php echo __('knowledge_bases.format_docx'); ?></li>
                                <li><?php echo __('knowledge_bases.format_doc'); ?></li>
                            </ul>
                        </div>
                    </div>
                    
                    <div class="mt-6 flex justify-end space-x-3">
                        <button type="button" onclick="hideUploadModal()" class="px-4 py-2 border border-gray-300 rounded-md text-sm font-medium text-gray-700 hover:bg-gray-50">
                            <?php echo __('button.cancel'); ?>
                        </button>
                        <button type="submit" class="px-4 py-2 border border-transparent rounded-md shadow-sm text-sm font-medium text-white bg-orange-600 hover:bg-orange-700">
                            <i data-lucide="upload" class="w-4 h-4 mr-2 inline"></i>
                            <?php echo __('knowledge_bases.upload_doc'); ?>
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

            const fileInput = document.getElementById('knowledge-file-input');
            const fileName = document.getElementById('knowledge-file-name');
            if (fileInput && fileName) {
                fileInput.addEventListener('change', function () {
                    fileName.textContent = this.files && this.files.length > 0
                        ? this.files[0].name
                        : <?php echo json_encode(__('knowledge_bases.file_none_selected'), JSON_UNESCAPED_UNICODE); ?>;
                });
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

        // 显示上传模态框
        function showUploadModal() {
            document.getElementById('upload-modal').classList.remove('hidden');
        }

        // 隐藏上传模态框
        function hideUploadModal() {
            document.getElementById('upload-modal').classList.add('hidden');
        }

        // 删除知识库
        function deleteKnowledge(knowledgeId, knowledgeName) {
            if (confirm(`<?php echo __('knowledge_bases.confirm_delete', ['name' => '{name}']); ?>`.replace('{name}', knowledgeName))) {
                const form = document.createElement('form');
                form.method = 'POST';
                form.innerHTML = `
                    <input type="hidden" name="csrf_token" value="<?php echo generate_csrf_token(); ?>">
                    <input type="hidden" name="action" value="delete_knowledge">
                    <input type="hidden" name="knowledge_id" value="${knowledgeId}">
                `;
                document.body.appendChild(form);
                form.submit();
            }
        }

        // 点击模态框外部关闭
        window.onclick = function(event) {
            const createModal = document.getElementById('create-modal');
            const uploadModal = document.getElementById('upload-modal');
            
            if (event.target === createModal) {
                hideCreateModal();
            }
            if (event.target === uploadModal) {
                hideUploadModal();
            }
        }
    </script>
</body>
</html>
