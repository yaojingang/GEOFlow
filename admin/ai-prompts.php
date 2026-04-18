<?php
/**
 * 智能GEO内容系统 - 提示词配置
 *
 * @author 姚金刚
 * @version 1.0
 * @date 2025-10-14
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

// 处理POST请求
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
        $error = __('message.csrf_failed');
    } else {
        $action = $_POST['action'] ?? '';
        
        switch ($action) {
            case 'create_prompt':
                $name = trim($_POST['name'] ?? '');
                $type = 'content';
                $content = trim($_POST['content'] ?? '');
                
                if (empty($name) || empty($content)) {
                    $error = __('ai_prompts.error.required');
                } else {
                    try {
                        $stmt = $db->prepare("
                            INSERT INTO prompts (name, type, content, created_at, updated_at)
                            VALUES (?, ?, ?, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)
                        ");
                        
                        if ($stmt->execute([$name, $type, $content])) {
                            $message = __('ai_prompts.message.create_success');
                        } else {
                            $error = __('ai_prompts.message.create_failed');
                        }
                    } catch (Exception $e) {
                        $error = __('ai_prompts.message.create_error', ['message' => $e->getMessage()]);
                    }
                }
                break;
                
            case 'update_prompt':
                $id = intval($_POST['id'] ?? 0);
                $name = trim($_POST['name'] ?? '');
                $type = 'content';
                $content = trim($_POST['content'] ?? '');
                
                if ($id <= 0 || empty($name) || empty($content)) {
                    $error = __('ai_prompts.error.invalid_fields');
                } else {
                    try {
                        $stmt = $db->prepare("
                            UPDATE prompts 
                            SET name = ?, type = ?, content = ?, updated_at = CURRENT_TIMESTAMP
                            WHERE id = ? AND type = 'content'
                        ");
                        
                        if ($stmt->execute([$name, $type, $content, $id])) {
                            $message = __('ai_prompts.message.update_success');
                        } else {
                            $error = __('ai_prompts.message.update_failed');
                        }
                    } catch (Exception $e) {
                        $error = __('ai_prompts.message.update_error', ['message' => $e->getMessage()]);
                    }
                }
                break;
                
            case 'delete_prompt':
                $id = intval($_POST['id'] ?? 0);
                
                if ($id <= 0) {
                    $error = __('ai_prompts.error.invalid_id');
                } else {
                    try {
                        // 检查是否有任务在使用此提示词
                        $stmt = $db->prepare("SELECT COUNT(*) FROM tasks WHERE prompt_id = ?");
                        $stmt->execute([$id]);
                        $usage_count = $stmt->fetchColumn();
                        
                        if ($usage_count > 0) {
                            $error = __('ai_prompts.error.in_use', ['count' => $usage_count]);
                        } else {
                            $stmt = $db->prepare("DELETE FROM prompts WHERE id = ? AND type = 'content'");
                            if ($stmt->execute([$id])) {
                                $message = __('ai_prompts.message.delete_success');
                            } else {
                                $error = __('ai_prompts.message.delete_failed');
                            }
                        }
                    } catch (Exception $e) {
                        $error = __('ai_prompts.message.delete_error', ['message' => $e->getMessage()]);
                    }
                }
                break;
        }
    }
}

// 获取提示词列表
try {
    $prompts = $db->query("
        SELECT p.*, 
               COALESCE(t.task_count, 0) as task_count
        FROM prompts p
        LEFT JOIN (
            SELECT prompt_id, COUNT(*) as task_count 
            FROM tasks 
            WHERE prompt_id IS NOT NULL
            GROUP BY prompt_id
        ) t ON p.id = t.prompt_id
        WHERE p.type = 'content'
        ORDER BY p.created_at DESC
    ")->fetchAll();
} catch (Exception $e) {
    $prompts = [];
    $error = __('ai_prompts.error.fetch_failed', ['message' => $e->getMessage()]);
}

// 设置页面信息
$page_title = __('ai_prompts.page_title');
$page_header = '
<div class="flex items-center justify-between">
    <div class="flex items-center space-x-4">
        <a href="ai-configurator.php" class="text-gray-400 hover:text-gray-600">
            <i data-lucide="arrow-left" class="w-5 h-5"></i>
        </a>
        <div>
            <h1 class="text-2xl font-bold text-gray-900">' . __('ai_prompts.heading') . '</h1>
            <p class="mt-1 text-sm text-gray-600">' . __('ai_prompts.subtitle') . '</p>
        </div>
    </div>
    <button onclick="showCreatePromptModal()" class="inline-flex items-center px-4 py-2 border border-transparent text-sm font-medium rounded-md text-white bg-green-600 hover:bg-green-700">
        <i data-lucide="plus" class="w-4 h-4 mr-2"></i>
        ' . __('ai_prompts.add') . '
    </button>
</div>';

require_once __DIR__ . '/includes/header.php';
?>

        <!-- 消息显示 -->
        <?php if (!empty($message)): ?>
            <div class="mb-6 bg-green-50 border border-green-200 rounded-md p-4">
                <div class="flex">
                    <div class="flex-shrink-0">
                        <i data-lucide="check-circle" class="h-5 w-5 text-green-400"></i>
                    </div>
                    <div class="ml-3">
                        <p class="text-sm text-green-700"><?php echo htmlspecialchars($message); ?></p>
                    </div>
                </div>
            </div>
        <?php endif; ?>

        <?php if (!empty($error)): ?>
            <div class="mb-6 bg-red-50 border border-red-200 rounded-md p-4">
                <div class="flex">
                    <div class="flex-shrink-0">
                        <i data-lucide="alert-circle" class="h-5 w-5 text-red-400"></i>
                    </div>
                    <div class="ml-3">
                        <p class="text-sm text-red-700"><?php echo htmlspecialchars($error); ?></p>
                    </div>
                </div>
            </div>
        <?php endif; ?>

        <div class="mb-6 rounded-md border border-blue-200 bg-blue-50 p-4 text-sm text-blue-800">
            <?php echo __('ai_prompts.help_banner'); ?>
        </div>

        <!-- 提示词列表 -->
        <div class="bg-white shadow rounded-lg">
            <div class="px-6 py-4 border-b border-gray-200">
                <h3 class="text-lg font-medium text-gray-900"><?php echo __('ai_prompts.list_title'); ?></h3>
                <p class="mt-1 text-sm text-gray-600"><?php echo __('ai_prompts.list_subtitle'); ?></p>
            </div>
            
            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-gray-200">
                    <thead class="bg-gray-50">
                        <tr>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider"><?php echo __('ai_prompts.column_info'); ?></th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider"><?php echo __('ai_prompts.column_type'); ?></th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider"><?php echo __('ai_prompts.column_usage'); ?></th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider"><?php echo __('ai_prompts.column_created_at'); ?></th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider"><?php echo __('common.actions'); ?></th>
                        </tr>
                    </thead>
                    <tbody class="bg-white divide-y divide-gray-200">
                        <?php if (empty($prompts)): ?>
                            <tr>
                                <td colspan="5" class="px-6 py-4 text-center text-gray-500">
                                    <i data-lucide="message-square" class="w-8 h-8 mx-auto mb-2 text-gray-400"></i>
                                    <p><?php echo __('ai_prompts.empty'); ?></p>
                                    <button onclick="showCreatePromptModal()" class="mt-2 text-green-600 hover:text-green-800"><?php echo __('ai_prompts.add_first'); ?></button>
                                </td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($prompts as $prompt): ?>
                                <tr>
                                    <td class="px-6 py-4">
                                        <div>
                                            <div class="text-sm font-medium text-gray-900"><?php echo htmlspecialchars($prompt['name']); ?></div>
                                            <div class="text-sm text-gray-500 max-w-xs truncate"><?php echo htmlspecialchars(substr($prompt['content'], 0, 100)) . (strlen($prompt['content']) > 100 ? '...' : ''); ?></div>
                                        </div>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap">
                                        <span class="inline-flex px-2 py-1 text-xs font-semibold rounded-full bg-green-100 text-green-800">
                                            <?php echo __('ai_prompts.type_content'); ?>
                                        </span>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900">
                                        <div><?php echo __('ai_prompts.task_usage', ['count' => $prompt['task_count']]); ?></div>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                                        <?php echo date('Y-m-d H:i', strtotime($prompt['created_at'])); ?>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm font-medium space-x-2">
                                        <button onclick="editPrompt(<?php echo htmlspecialchars(json_encode($prompt)); ?>)" class="text-green-600 hover:text-green-900"><?php echo __('button.edit'); ?></button>
                                        <button onclick="deletePrompt(<?php echo $prompt['id']; ?>, '<?php echo htmlspecialchars($prompt['name']); ?>')" class="text-red-600 hover:text-red-900"><?php echo __('button.delete'); ?></button>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- 创建/编辑提示词模态框 -->
        <div id="promptModal" class="fixed inset-0 bg-gray-600 bg-opacity-50 overflow-y-auto h-full w-full hidden">
            <div class="relative top-10 mx-auto p-5 border w-11/12 md:w-4/5 lg:w-3/4 shadow-lg rounded-md bg-white">
                <div class="mt-3">
                    <div class="flex items-center justify-between mb-4">
                        <h3 class="text-lg font-medium text-gray-900" id="promptModalTitle"><?php echo __('ai_prompts.modal_create'); ?></h3>
                        <button onclick="closePromptModal()" class="text-gray-400 hover:text-gray-600">
                            <i data-lucide="x" class="w-6 h-6"></i>
                        </button>
                    </div>

                    <form id="promptForm" method="POST" class="space-y-6">
                        <input type="hidden" name="csrf_token" value="<?php echo generate_csrf_token(); ?>">
                        <input type="hidden" name="action" id="promptFormAction" value="create_prompt">
                        <input type="hidden" name="id" id="promptId" value="">
                        <input type="hidden" name="type" value="content">

                        <div>
                            <label for="prompt_name" class="block text-sm font-medium text-gray-700"><?php echo __('ai_prompts.field_name'); ?></label>
                            <input type="text" name="name" id="prompt_name" required
                                   class="mt-1 block w-full border-gray-300 rounded-md shadow-sm focus:ring-green-500 focus:border-green-500 sm:text-sm"
                                   placeholder="<?php echo htmlspecialchars(__('ai_prompts.placeholder_name')); ?>">
                        </div>

                        <div>
                            <label for="prompt_content" class="block text-sm font-medium text-gray-700"><?php echo __('ai_prompts.field_content'); ?></label>
                            <textarea name="content" id="prompt_content" required rows="12"
                                      class="mt-1 block w-full border-gray-300 rounded-md shadow-sm focus:ring-green-500 focus:border-green-500 sm:text-sm"
                                      placeholder="<?php echo htmlspecialchars(__('ai_prompts.placeholder_content')); ?>"></textarea>

                            <!-- 变量说明 -->
                            <div class="mt-2 p-3 bg-blue-50 border border-blue-200 rounded-md">
                                <h4 class="text-sm font-medium text-blue-800 mb-2"><?php echo __('ai_prompts.variable_title'); ?></h4>
                                <div class="grid grid-cols-1 md:grid-cols-3 gap-2 text-xs text-blue-700">
                                    <div><?php echo __('ai_prompts.variable_title_label'); ?></div>
                                    <div><?php echo __('ai_prompts.variable_keyword_label'); ?></div>
                                    <div><?php echo __('ai_prompts.variable_knowledge_label'); ?></div>
                                </div>
                                <p class="mt-2 text-xs text-blue-600"><?php echo __('ai_prompts.variable_help'); ?></p>
                            </div>
                        </div>

                        <div class="flex justify-end space-x-3 pt-4">
                            <button type="button" onclick="closePromptModal()"
                                    class="px-4 py-2 border border-gray-300 rounded-md text-sm font-medium text-gray-700 bg-white hover:bg-gray-50">
                                <?php echo __('button.cancel'); ?>
                            </button>
                            <button type="submit"
                                    class="px-4 py-2 border border-transparent rounded-md shadow-sm text-sm font-medium text-white bg-green-600 hover:bg-green-700">
                                <?php echo __('button.save'); ?>
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    <script>
        // 显示创建提示词模态框
        function showCreatePromptModal() {
            document.getElementById('promptModalTitle').textContent = <?php echo json_encode(__('ai_prompts.modal_create')); ?>;
            document.getElementById('promptFormAction').value = 'create_prompt';
            document.getElementById('promptId').value = '';
            document.getElementById('promptForm').reset();
            document.getElementById('promptModal').classList.remove('hidden');
        }

        // 编辑提示词
        function editPrompt(prompt) {
            document.getElementById('promptModalTitle').textContent = <?php echo json_encode(__('ai_prompts.modal_edit')); ?>;
            document.getElementById('promptFormAction').value = 'update_prompt';
            document.getElementById('promptId').value = prompt.id;
            document.getElementById('prompt_name').value = prompt.name;
            document.getElementById('prompt_content').value = prompt.content;
            document.getElementById('promptModal').classList.remove('hidden');
        }

        // 关闭模态框
        function closePromptModal() {
            document.getElementById('promptModal').classList.add('hidden');
        }

        // 删除提示词
        function deletePrompt(id, name) {
            const template = <?php echo json_encode(__('ai_prompts.confirm_delete', ['name' => '__NAME__'])); ?>;
            if (confirm(template.replace('__NAME__', name))) {
                const form = document.createElement('form');
                form.method = 'POST';
                form.innerHTML = `
                    <input type="hidden" name="csrf_token" value="<?php echo generate_csrf_token(); ?>">
                    <input type="hidden" name="action" value="delete_prompt">
                    <input type="hidden" name="id" value="${id}">
                `;
                document.body.appendChild(form);
                form.submit();
            }
        }

        // 初始化
        document.addEventListener('DOMContentLoaded', function() {
            if (typeof lucide !== 'undefined') {
                lucide.createIcons();
            }
        });
    </script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
