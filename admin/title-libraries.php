<?php
/**
 * 智能GEO内容系统 - 标题库管理
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
            case 'create_library':
                $name = trim($_POST['name'] ?? '');
                $description = trim($_POST['description'] ?? '');
                
                if (empty($name)) {
                    $error = __('title_libraries.error.name_required');
                } else {
                    try {
                        $stmt = $db->prepare("
                            INSERT INTO title_libraries (name, description, title_count, created_at, updated_at) 
                            VALUES (?, ?, 0, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)
                        ");
                        
                        if ($stmt->execute([$name, $description])) {
                            $message = __('title_libraries.message.create_success');
                        } else {
                            $error = __('title_libraries.message.create_failed');
                        }
                    } catch (Exception $e) {
                        $error = __('title_libraries.message.create_error', ['message' => $e->getMessage()]);
                    }
                }
                break;
                
            case 'delete_library':
                $library_id = intval($_POST['library_id'] ?? 0);
                
                if ($library_id > 0) {
                    try {
                        $taskCountStmt = $db->prepare("SELECT COUNT(*) FROM tasks WHERE title_library_id = ?");
                        $taskCountStmt->execute([$library_id]);
                        $referencedTaskCount = (int) $taskCountStmt->fetchColumn();

                        if ($referencedTaskCount > 0) {
                            $taskStmt = $db->prepare("
                                SELECT id, name
                                FROM tasks
                                WHERE title_library_id = ?
                                ORDER BY updated_at DESC NULLS LAST, id DESC
                                LIMIT 3
                            ");
                            $taskStmt->execute([$library_id]);
                            $taskNames = array_map(
                                static fn(array $task): string => sprintf('#%d %s', (int) $task['id'], (string) $task['name']),
                                $taskStmt->fetchAll()
                            );
                            $taskPreview = implode('、', $taskNames);
                            $remainingHint = $referencedTaskCount > count($taskNames)
                                ? __('title_libraries.error.delete_more_tasks', ['count' => $referencedTaskCount])
                                : '';
                            $error = __('title_libraries.error.delete_blocked', ['tasks' => $taskPreview . $remainingHint]);
                            break;
                        }

                        $db->beginTransaction();
                        
                        // 删除标题库中的所有标题
                        $stmt = $db->prepare("DELETE FROM titles WHERE library_id = ?");
                        $stmt->execute([$library_id]);
                        
                        // 删除标题库
                        $stmt = $db->prepare("DELETE FROM title_libraries WHERE id = ?");
                        $stmt->execute([$library_id]);
                        
                        $db->commit();
                        $message = __('title_libraries.message.delete_success');
                    } catch (Exception $e) {
                        $db->rollBack();
                        $error = __('title_libraries.message.delete_error', ['message' => $e->getMessage()]);
                    }
                }
                break;
                
            case 'import_titles':
                $library_id = intval($_POST['library_id'] ?? 0);
                $titles_text = trim($_POST['titles_text'] ?? '');
                
                if ($library_id <= 0) {
                    $error = __('title_libraries.error.library_required');
                } elseif (empty($titles_text)) {
                    $error = __('title_libraries.error.titles_required');
                } else {
                    try {
                        $db->beginTransaction();
                        
                        $titles = [];
                        $lines = explode("\n", $titles_text);
                        foreach ($lines as $line) {
                            $line = trim($line);
                            if (!empty($line)) {
                                $titles[] = $line;
                            }
                        }
                        
                        // 去重
                        $titles = array_unique($titles);
                        
                        // 插入标题
                        $stmt = $db->prepare("
                            INSERT INTO titles (library_id, title, is_ai_generated, created_at) 
                            VALUES (?, ?, FALSE, CURRENT_TIMESTAMP)
                        ");
                        
                        $imported_count = 0;
                        foreach ($titles as $title) {
                            if ($stmt->execute([$library_id, $title])) {
                                $imported_count++;
                            }
                        }
                        
                        refresh_title_library_count($db, $library_id);
                        
                        $db->commit();
                        $message = __('title_libraries.message.import_success', ['count' => $imported_count]);
                    } catch (Exception $e) {
                        $db->rollBack();
                        $error = __('title_libraries.message.import_error', ['message' => $e->getMessage()]);
                    }
                }
                break;
                
            case 'generate_titles':
                $library_id = intval($_POST['library_id'] ?? 0);
                $keyword = trim($_POST['keyword'] ?? '');
                $count = intval($_POST['count'] ?? 10);
                
                if ($library_id <= 0) {
                    $error = __('title_libraries.error.library_required');
                } elseif (empty($keyword)) {
                    $error = __('title_libraries.error.keyword_required');
                } else {
                    try {
                        require_once __DIR__ . '/../includes/ai_engine.php';
                        $ai_engine = new AIEngine($db);
                        
                        // 构建AI生成标题的提示词
                        $prompt = "请为关键词「{$keyword}」生成{$count}个吸引人的文章标题。要求：
1. 标题要有吸引力和点击欲望
2. 包含关键词「{$keyword}」
3. 长度控制在15-30字之间
4. 每行一个标题
5. 不要添加序号或其他标记
6. 标题要符合中文表达习惯";

                        // 获取AI模型配置
                        $stmt = $db->query("
                            SELECT *
                            FROM ai_models
                            WHERE status = 'active'
                              AND COALESCE(NULLIF(model_type, ''), 'chat') = 'chat'
                            LIMIT 1
                        ");
                        $ai_model = $stmt->fetch();
                        
                        if (!$ai_model) {
                            $error = __('title_libraries.error.no_ai_model');
                        } else {
                            $result = $ai_engine->callAI($ai_model, $prompt);
                            
                            if ($result) {
                                $db->beginTransaction();
                                
                                $titles = explode("\n", trim($result));
                                $stmt = $db->prepare("
                                    INSERT INTO titles (library_id, title, is_ai_generated, created_at) 
                                    VALUES (?, ?, TRUE, CURRENT_TIMESTAMP)
                                ");
                                
                                $generated_count = 0;
                                foreach ($titles as $title) {
                                    $title = trim($title);
                                    if (!empty($title)) {
                                        if ($stmt->execute([$library_id, $title])) {
                                            $generated_count++;
                                        }
                                    }
                                }
                                
                                refresh_title_library_count($db, $library_id);
                                
                                $db->commit();
                                $message = __('title_libraries.message.ai_success', ['count' => $generated_count]);
                            } else {
                                $error = __('title_libraries.error.ai_failed');
                            }
                        }
                    } catch (Exception $e) {
                        if ($db->inTransaction()) {
                            $db->rollBack();
                        }
                        $error = __('title_libraries.message.ai_error', ['message' => $e->getMessage()]);
                    }
                }
                break;
        }
    }
}

// 获取标题库列表
$libraries = $db->query("
    SELECT tl.*, 
           (SELECT COUNT(*) FROM titles WHERE library_id = tl.id) as actual_count,
           (SELECT COUNT(*) FROM titles WHERE library_id = tl.id AND is_ai_generated = TRUE) as ai_count
    FROM title_libraries tl 
    ORDER BY tl.created_at DESC
")->fetchAll();

// 获取统计数据
$stats = [
    'total_libraries' => count($libraries),
    'total_titles' => $db->query("SELECT COUNT(*) as count FROM titles")->fetch()['count'],
    'ai_titles' => $db->query("SELECT COUNT(*) as count FROM titles WHERE is_ai_generated = TRUE")->fetch()['count'],
    'avg_titles' => count($libraries) > 0 ? round($db->query("SELECT COUNT(*) as count FROM titles")->fetch()['count'] / count($libraries), 1) : 0
];

// 设置页面信息
$page_title = __('title_libraries.page_title');
$page_header = '
<div class="flex items-center justify-between">
    <div class="flex items-center space-x-4">
        <a href="materials.php" class="text-gray-400 hover:text-gray-600">
            <i data-lucide="arrow-left" class="w-5 h-5"></i>
        </a>
        <div>
            <h1 class="text-2xl font-bold text-gray-900">' . __('title_libraries.heading') . '</h1>
            <p class="mt-1 text-sm text-gray-600">' . __('title_libraries.subtitle') . '</p>
        </div>
    </div>
    <button onclick="showCreateModal()" class="inline-flex items-center px-4 py-2 border border-transparent text-sm font-medium rounded-md text-white bg-green-600 hover:bg-green-700">
        <i data-lucide="plus" class="w-4 h-4 mr-2"></i>
        ' . __('title_libraries.create') . '
    </button>
</div>
';

// 包含头部模块
require_once __DIR__ . '/includes/header.php';
?>

        <!-- 统计卡片 -->
        <div class="grid grid-cols-1 md:grid-cols-4 gap-6 mb-8">
            <div class="bg-white overflow-hidden shadow rounded-lg">
                <div class="p-5">
                    <div class="flex items-center">
                        <div class="flex-shrink-0">
                            <i data-lucide="folder" class="h-6 w-6 text-green-600"></i>
                        </div>
                        <div class="ml-5 w-0 flex-1">
                            <dl>
                                <dt class="text-sm font-medium text-gray-500 truncate"><?php echo __('title_libraries.total'); ?></dt>
                                <dd class="text-lg font-medium text-gray-900"><?php echo $stats['total_libraries']; ?></dd>
                            </dl>
                        </div>
                    </div>
                </div>
            </div>

            <div class="bg-white overflow-hidden shadow rounded-lg">
                <div class="p-5">
                    <div class="flex items-center">
                        <div class="flex-shrink-0">
                            <i data-lucide="type" class="h-6 w-6 text-blue-600"></i>
                        </div>
                        <div class="ml-5 w-0 flex-1">
                            <dl>
                                <dt class="text-sm font-medium text-gray-500 truncate"><?php echo __('title_libraries.total_titles'); ?></dt>
                                <dd class="text-lg font-medium text-gray-900"><?php echo $stats['total_titles']; ?></dd>
                            </dl>
                        </div>
                    </div>
                </div>
            </div>

            <div class="bg-white overflow-hidden shadow rounded-lg">
                <div class="p-5">
                    <div class="flex items-center">
                        <div class="flex-shrink-0">
                            <i data-lucide="zap" class="h-6 w-6 text-purple-600"></i>
                        </div>
                        <div class="ml-5 w-0 flex-1">
                            <dl>
                                <dt class="text-sm font-medium text-gray-500 truncate"><?php echo __('title_libraries.ai_generated'); ?></dt>
                                <dd class="text-lg font-medium text-gray-900"><?php echo $stats['ai_titles']; ?></dd>
                            </dl>
                        </div>
                    </div>
                </div>
            </div>

            <div class="bg-white overflow-hidden shadow rounded-lg">
                <div class="p-5">
                    <div class="flex items-center">
                        <div class="flex-shrink-0">
                            <i data-lucide="trending-up" class="h-6 w-6 text-orange-600"></i>
                        </div>
                        <div class="ml-5 w-0 flex-1">
                            <dl>
                                <dt class="text-sm font-medium text-gray-500 truncate"><?php echo __('common.avg_per_library'); ?></dt>
                                <dd class="text-lg font-medium text-gray-900"><?php echo $stats['avg_titles']; ?></dd>
                            </dl>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- 标题库列表 -->
        <div class="bg-white shadow rounded-lg">
            <div class="px-6 py-4 border-b border-gray-200">
                <h3 class="text-lg font-medium text-gray-900"><?php echo __('title_libraries.list_title'); ?></h3>
            </div>

            <?php if (empty($libraries)): ?>
                <div class="px-6 py-8 text-center">
                    <i data-lucide="folder-plus" class="w-12 h-12 mx-auto text-gray-400 mb-4"></i>
                    <h3 class="text-lg font-medium text-gray-900 mb-2"><?php echo __('title_libraries.empty'); ?></h3>
                    <p class="text-gray-500 mb-4"><?php echo __('title_libraries.empty_desc'); ?></p>
                    <button onclick="showCreateModal()" class="inline-flex items-center px-4 py-2 border border-transparent text-sm font-medium rounded-md text-white bg-green-600 hover:bg-green-700">
                        <i data-lucide="plus" class="w-4 h-4 mr-2"></i>
                        <?php echo __('title_libraries.create_first'); ?>
                    </button>
                </div>
            <?php else: ?>
                <div class="divide-y divide-gray-200">
                    <?php foreach ($libraries as $library): ?>
                        <div class="px-6 py-6">
                            <div class="flex items-center justify-between">
                                <div class="flex-1">
                                    <div class="flex items-center space-x-3">
                                        <h4 class="text-lg font-medium text-gray-900">
                                            <a href="title-library-detail.php?id=<?php echo $library['id']; ?>" class="hover:text-green-600">
                                                <?php echo htmlspecialchars($library['name']); ?>
                                            </a>
                                        </h4>
                                        <span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium bg-green-100 text-green-800">
                                            <?php echo __('title_libraries.title_count', ['count' => $library['actual_count']]); ?>
                                        </span>
                                        <?php if ($library['ai_count'] > 0): ?>
                                            <span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium bg-purple-100 text-purple-800">
                                                <?php echo __('title_libraries.ai_count', ['count' => $library['ai_count']]); ?>
                                            </span>
                                        <?php endif; ?>
                                    </div>
                                    <?php if ($library['description']): ?>
                                        <p class="mt-1 text-sm text-gray-600"><?php echo htmlspecialchars($library['description']); ?></p>
                                    <?php endif; ?>
                                    <div class="mt-2 flex items-center space-x-4 text-sm text-gray-500">
                                        <span><?php echo __('title_libraries.created_at', ['value' => date('Y-m-d H:i', strtotime($library['created_at']))]); ?></span>
                                        <span><?php echo __('title_libraries.updated_at', ['value' => date('Y-m-d H:i', strtotime($library['updated_at']))]); ?></span>
                                    </div>
                                </div>
                                
                                <div class="flex items-center space-x-2">
                                    <a href="title-library-ai-generate.php?id=<?php echo $library['id']; ?>" class="inline-flex items-center px-3 py-1.5 border border-transparent text-xs font-medium rounded text-white bg-purple-600 hover:bg-purple-700">
                                        <i data-lucide="zap" class="w-4 h-4 mr-1"></i>
                                        <?php echo __('title_detail.ai_generate'); ?>
                                    </a>
                                    <button onclick="showImportModal(<?php echo $library['id']; ?>, '<?php echo htmlspecialchars($library['name']); ?>')" class="inline-flex items-center px-3 py-1.5 border border-gray-300 text-xs font-medium rounded text-gray-700 bg-white hover:bg-gray-50">
                                        <i data-lucide="upload" class="w-4 h-4 mr-1"></i>
                                        <?php echo __('button.import'); ?>
                                    </button>
                                    <a href="title-library-detail.php?id=<?php echo $library['id']; ?>" class="inline-flex items-center px-3 py-1.5 border border-gray-300 text-xs font-medium rounded text-gray-700 bg-white hover:bg-gray-50">
                                        <i data-lucide="eye" class="w-4 h-4 mr-1"></i>
                                        <?php echo __('button.view'); ?>
                                    </a>
                                    <button onclick="deleteLibrary(<?php echo $library['id']; ?>, '<?php echo htmlspecialchars($library['name']); ?>')" class="inline-flex items-center px-3 py-1.5 border border-transparent text-xs font-medium rounded text-white bg-red-600 hover:bg-red-700">
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
    <!-- 创建标题库模态框 -->
    <div id="create-modal" class="hidden fixed inset-0 bg-gray-600 bg-opacity-50 overflow-y-auto h-full w-full z-50">
        <div class="relative top-20 mx-auto p-5 border w-96 shadow-lg rounded-md bg-white">
            <div class="mt-3">
                <h3 class="text-lg font-medium text-gray-900 mb-4"><?php echo __('title_libraries.modal_create'); ?></h3>
                <form method="POST">
                    <input type="hidden" name="csrf_token" value="<?php echo generate_csrf_token(); ?>">
                    <input type="hidden" name="action" value="create_library">
                    
                    <div class="space-y-4">
                        <div>
                            <label class="block text-sm font-medium text-gray-700"><?php echo __('title_libraries.field_name'); ?></label>
                            <input type="text" name="name" required 
                                   class="mt-1 block w-full border-gray-300 rounded-md shadow-sm focus:ring-green-500 focus:border-green-500 sm:text-sm"
                                   placeholder="<?php echo htmlspecialchars(__('title_libraries.placeholder_name')); ?>">
                        </div>
                        
                        <div>
                            <label class="block text-sm font-medium text-gray-700"><?php echo __('title_libraries.field_description'); ?></label>
                            <textarea name="description" rows="3"
                                      class="mt-1 block w-full border-gray-300 rounded-md shadow-sm focus:ring-green-500 focus:border-green-500 sm:text-sm"
                                      placeholder="<?php echo htmlspecialchars(__('title_libraries.placeholder_description')); ?>"></textarea>
                        </div>
                    </div>
                    
                    <div class="mt-6 flex justify-end space-x-3">
                        <button type="button" onclick="hideCreateModal()" class="px-4 py-2 border border-gray-300 rounded-md text-sm font-medium text-gray-700 hover:bg-gray-50">
                            <?php echo __('button.cancel'); ?>
                        </button>
                        <button type="submit" class="px-4 py-2 border border-transparent rounded-md shadow-sm text-sm font-medium text-white bg-green-600 hover:bg-green-700">
                            <?php echo __('button.create'); ?>
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- 导入标题模态框 -->
    <div id="import-modal" class="hidden fixed inset-0 bg-gray-600 bg-opacity-50 overflow-y-auto h-full w-full z-50">
        <div class="relative top-10 mx-auto p-5 border w-2/3 max-w-2xl shadow-lg rounded-md bg-white">
            <div class="mt-3">
                <h3 class="text-lg font-medium text-gray-900 mb-4"><?php echo __('title_libraries.modal_import'); ?> <span id="import-library-name" class="text-green-600"></span></h3>
                <form method="POST">
                    <input type="hidden" name="csrf_token" value="<?php echo generate_csrf_token(); ?>">
                    <input type="hidden" name="action" value="import_titles">
                    <input type="hidden" name="library_id" id="import-library-id">
                    
                    <div class="space-y-4">
                        <div>
                            <label class="block text-sm font-medium text-gray-700"><?php echo __('title_libraries.field_titles'); ?></label>
                            <textarea name="titles_text" rows="10" required
                                      class="mt-1 block w-full border-gray-300 rounded-md shadow-sm focus:ring-green-500 focus:border-green-500 sm:text-sm"
                                      placeholder="<?php echo htmlspecialchars(__('title_libraries.placeholder_titles')); ?>"></textarea>
                        </div>
                        
                        <div class="text-sm text-gray-500">
                            <p class="mb-2"><?php echo __('title_libraries.import_help'); ?></p>
                            <ul class="list-disc list-inside space-y-1">
                                <li><?php echo __('title_libraries.import_line'); ?></li>
                                <li><?php echo __('title_libraries.import_dedupe'); ?></li>
                                <li><?php echo __('title_libraries.import_length'); ?></li>
                            </ul>
                        </div>
                    </div>
                    
                    <div class="mt-6 flex justify-end space-x-3">
                        <button type="button" onclick="hideImportModal()" class="px-4 py-2 border border-gray-300 rounded-md text-sm font-medium text-gray-700 hover:bg-gray-50">
                            <?php echo __('button.cancel'); ?>
                        </button>
                        <button type="submit" class="px-4 py-2 border border-transparent rounded-md shadow-sm text-sm font-medium text-white bg-green-600 hover:bg-green-700">
                            <?php echo __('title_libraries.import_button'); ?>
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>



<?php ob_start(); ?>
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

        // 显示导入模态框
        function showImportModal(libraryId, libraryName) {
            document.getElementById('import-library-id').value = libraryId;
            document.getElementById('import-library-name').textContent = libraryName;
            document.getElementById('import-modal').classList.remove('hidden');
        }

        // 隐藏导入模态框
        function hideImportModal() {
            document.getElementById('import-modal').classList.add('hidden');
        }



        // 删除标题库
        function deleteLibrary(libraryId, libraryName) {
            if (confirm(`<?php echo __('title_libraries.confirm_delete', ['name' => '{name}']); ?>`.replace('{name}', libraryName))) {
                const form = document.createElement('form');
                form.method = 'POST';
                form.innerHTML = `
                    <input type="hidden" name="csrf_token" value="<?php echo generate_csrf_token(); ?>">
                    <input type="hidden" name="action" value="delete_library">
                    <input type="hidden" name="library_id" value="${libraryId}">
                `;
                document.body.appendChild(form);
                form.submit();
            }
        }

        // 点击模态框外部关闭
        window.onclick = function(event) {
            const createModal = document.getElementById('create-modal');
            const importModal = document.getElementById('import-modal');

            if (event.target === createModal) {
                hideCreateModal();
            }
            if (event.target === importModal) {
                hideImportModal();
            }
        }
    </script>
<?php
$additional_js = ob_get_clean();
require_once __DIR__ . '/includes/footer.php';
