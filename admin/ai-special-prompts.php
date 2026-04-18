<?php
/**
 * 智能GEO内容系统 - 特殊提示词配置
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
            case 'update_keyword_prompt':
                $content = trim($_POST['keyword_content'] ?? '');
                
                if (empty($content)) {
                    $error = __('ai_special.error.keyword_required');
                } else {
                    try {
                        // 检查是否已存在关键词提示词
                        $stmt = $db->prepare("SELECT id FROM prompts WHERE type = 'keyword' LIMIT 1");
                        $stmt->execute();
                        $existing = $stmt->fetch();
                        
                        if ($existing) {
                            // 更新现有的
                            $stmt = $db->prepare("
                                UPDATE prompts 
                                SET content = ?, updated_at = CURRENT_TIMESTAMP
                                WHERE type = 'keyword'
                            ");
                            $success = $stmt->execute([$content]);
                        } else {
                            // 创建新的
                            $stmt = $db->prepare("
                                INSERT INTO prompts (name, type, content, created_at, updated_at)
                                VALUES ('关键词生成提示词', 'keyword', ?, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)
                            ");
                            $success = $stmt->execute([$content]);
                        }
                        
                        if ($success) {
                            $message = __('ai_special.message.keyword_saved');
                        } else {
                            $error = __('ai_special.message.keyword_failed');
                        }
                    } catch (Exception $e) {
                        $error = __('ai_special.message.save_error', ['message' => $e->getMessage()]);
                    }
                }
                break;
                
            case 'update_description_prompt':
                $content = trim($_POST['description_content'] ?? '');
                
                if (empty($content)) {
                    $error = __('ai_special.error.description_required');
                } else {
                    try {
                        // 检查是否已存在描述提示词
                        $stmt = $db->prepare("SELECT id FROM prompts WHERE type = 'description' LIMIT 1");
                        $stmt->execute();
                        $existing = $stmt->fetch();
                        
                        if ($existing) {
                            // 更新现有的
                            $stmt = $db->prepare("
                                UPDATE prompts 
                                SET content = ?, updated_at = CURRENT_TIMESTAMP
                                WHERE type = 'description'
                            ");
                            $success = $stmt->execute([$content]);
                        } else {
                            // 创建新的
                            $stmt = $db->prepare("
                                INSERT INTO prompts (name, type, content, created_at, updated_at)
                                VALUES ('文章描述生成提示词', 'description', ?, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)
                            ");
                            $success = $stmt->execute([$content]);
                        }
                        
                        if ($success) {
                            $message = __('ai_special.message.description_saved');
                        } else {
                            $error = __('ai_special.message.description_failed');
                        }
                    } catch (Exception $e) {
                        $error = __('ai_special.message.save_error', ['message' => $e->getMessage()]);
                    }
                }
                break;
        }
    }
}

// 获取现有的特殊提示词
try {
    $keyword_prompt = $db->query("
        SELECT *
        FROM prompts
        WHERE type = 'keyword'
        ORDER BY updated_at DESC, id DESC
        LIMIT 1
    ")->fetch();
    $description_prompt = $db->query("
        SELECT *
        FROM prompts
        WHERE type = 'description'
        ORDER BY updated_at DESC, id DESC
        LIMIT 1
    ")->fetch();
} catch (Exception $e) {
    $keyword_prompt = null;
    $description_prompt = null;
}

// 设置页面信息
$page_title = __('ai_special.page_title');
$page_header = '
<div class="flex items-center justify-between">
    <div class="flex items-center space-x-4">
        <a href="ai-configurator.php" class="text-gray-400 hover:text-gray-600">
            <i data-lucide="arrow-left" class="w-5 h-5"></i>
        </a>
        <div>
            <h1 class="text-2xl font-bold text-gray-900">' . __('ai_special.heading') . '</h1>
            <p class="mt-1 text-sm text-gray-600">' . __('ai_special.subtitle') . '</p>
        </div>
    </div>
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

        <div class="space-y-8">
            <!-- 关键词提示词设置 -->
            <div class="bg-white shadow rounded-lg">
                <div class="px-6 py-4 border-b border-gray-200">
                    <div class="flex items-center">
                        <div class="flex-shrink-0">
                            <div class="w-8 h-8 bg-purple-500 rounded-md flex items-center justify-center">
                                <i data-lucide="key" class="w-5 h-5 text-white"></i>
                            </div>
                        </div>
                        <div class="ml-4">
                            <h3 class="text-lg font-medium text-gray-900"><?php echo __('ai_special.keyword_title'); ?></h3>
                            <p class="mt-1 text-sm text-gray-600"><?php echo __('ai_special.keyword_subtitle'); ?></p>
                        </div>
                    </div>
                </div>
                
                <div class="px-6 py-6">
                    <form method="POST" class="space-y-4">
                        <input type="hidden" name="csrf_token" value="<?php echo generate_csrf_token(); ?>">
                        <input type="hidden" name="action" value="update_keyword_prompt">
                        
                        <div>
                            <label for="keyword_content" class="block text-sm font-medium text-gray-700"><?php echo __('ai_special.keyword_field'); ?></label>
                            <textarea name="keyword_content" id="keyword_content" rows="8" required
                                      class="mt-1 block w-full border-gray-300 rounded-md shadow-sm focus:ring-purple-500 focus:border-purple-500 sm:text-sm"
                                      placeholder="<?php echo htmlspecialchars(__('ai_special.keyword_placeholder')); ?>"><?php echo htmlspecialchars($keyword_prompt['content'] ?? ''); ?></textarea>
                            <p class="mt-2 text-sm text-gray-500"><?php echo __('ai_special.keyword_help'); ?></p>
                            <p class="mt-1 text-xs text-gray-500"><?php echo __('ai_special.variable_help'); ?></p>
                        </div>
                        
                        <div class="flex justify-end">
                            <button type="submit"
                                    class="inline-flex items-center px-4 py-2 border border-transparent text-sm font-medium rounded-md text-white bg-purple-600 hover:bg-purple-700">
                                <i data-lucide="save" class="w-4 h-4 mr-2"></i>
                                <?php echo __('ai_special.keyword_save'); ?>
                            </button>
                        </div>
                    </form>
                </div>
            </div>

            <!-- 文章描述提示词设置 -->
            <div class="bg-white shadow rounded-lg">
                <div class="px-6 py-4 border-b border-gray-200">
                    <div class="flex items-center">
                        <div class="flex-shrink-0">
                            <div class="w-8 h-8 bg-orange-500 rounded-md flex items-center justify-center">
                                <i data-lucide="file-text" class="w-5 h-5 text-white"></i>
                            </div>
                        </div>
                        <div class="ml-4">
                            <h3 class="text-lg font-medium text-gray-900"><?php echo __('ai_special.description_title'); ?></h3>
                            <p class="mt-1 text-sm text-gray-600"><?php echo __('ai_special.description_subtitle'); ?></p>
                        </div>
                    </div>
                </div>
                
                <div class="px-6 py-6">
                    <form method="POST" class="space-y-4">
                        <input type="hidden" name="csrf_token" value="<?php echo generate_csrf_token(); ?>">
                        <input type="hidden" name="action" value="update_description_prompt">
                        
                        <div>
                            <label for="description_content" class="block text-sm font-medium text-gray-700"><?php echo __('ai_special.description_field'); ?></label>
                            <textarea name="description_content" id="description_content" rows="8" required
                                      class="mt-1 block w-full border-gray-300 rounded-md shadow-sm focus:ring-orange-500 focus:border-orange-500 sm:text-sm"
                                      placeholder="<?php echo htmlspecialchars(__('ai_special.description_placeholder')); ?>"><?php echo htmlspecialchars($description_prompt['content'] ?? ''); ?></textarea>
                            <p class="mt-2 text-sm text-gray-500"><?php echo __('ai_special.description_help'); ?></p>
                            <p class="mt-1 text-xs text-gray-500"><?php echo __('ai_special.variable_help'); ?></p>
                        </div>
                        
                        <div class="flex justify-end">
                            <button type="submit"
                                    class="inline-flex items-center px-4 py-2 border border-transparent text-sm font-medium rounded-md text-white bg-orange-600 hover:bg-orange-700">
                                <i data-lucide="save" class="w-4 h-4 mr-2"></i>
                                <?php echo __('ai_special.description_save'); ?>
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>

        <!-- 使用说明 -->
        <div class="mt-8 bg-blue-50 border border-blue-200 rounded-lg p-6">
            <div class="flex">
                <div class="flex-shrink-0">
                    <i data-lucide="info" class="h-5 w-5 text-blue-400"></i>
                </div>
                <div class="ml-3">
                    <h3 class="text-sm font-medium text-blue-800"><?php echo __('ai_special.help_title'); ?></h3>
                    <div class="mt-2 text-sm text-blue-700">
                        <ul class="list-disc list-inside space-y-1">
                            <li><?php echo __('ai_special.help_keyword'); ?></li>
                            <li><?php echo __('ai_special.help_description'); ?></li>
                            <li><?php echo __('ai_special.help_variables'); ?></li>
                            <li><?php echo __('ai_special.help_auto_apply'); ?></li>
                        </ul>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script>
        // 初始化
        document.addEventListener('DOMContentLoaded', function() {
            if (typeof lucide !== 'undefined') {
                lucide.createIcons();
            }
        });
    </script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
