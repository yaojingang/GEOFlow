<?php
/**
 * 智能GEO内容系统 - AI模型配置
 *
 * @author 姚金刚
 * @version 1.0
 * @date 2025-10-14
 */

define('FEISHU_TREASURE', true);
session_start();

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/database_admin.php';
require_once __DIR__ . '/../includes/embedding-service.php';
require_once __DIR__ . '/../includes/functions.php';

// 检查管理员登录
require_admin_login();

// 立即释放session锁，允许其他页面并发访问
session_write_close();

$message = '';
$error = '';

function mask_api_key(string $api_key): string
{
    $api_key = decrypt_ai_api_key($api_key);
    $length = strlen($api_key);
    if ($length <= 8) {
        return str_repeat('*', max($length, 4));
    }

    return substr($api_key, 0, 4) . str_repeat('*', max($length - 8, 8)) . substr($api_key, -4);
}

function normalize_ai_model_type(string $modelType): string
{
    $modelType = trim(strtolower($modelType));
    return in_array($modelType, ['chat', 'embedding'], true) ? $modelType : 'chat';
}

$default_embedding_model_id = (int) get_setting('default_embedding_model_id', 0);
$pgvector_enabled = embedding_service_pgvector_available($db);

// 处理POST请求
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
        $error = __('message.csrf_failed');
    } else {
        $action = $_POST['action'] ?? '';
        
        switch ($action) {
            case 'create_model':
                $name = trim($_POST['name'] ?? '');
                $version = trim($_POST['version'] ?? '');
                $api_key = trim($_POST['api_key'] ?? '');
                $model_id = trim($_POST['model_id'] ?? '');
                $api_url = trim($_POST['api_url'] ?? 'https://api.deepseek.com');
                $failover_priority = max(1, intval($_POST['failover_priority'] ?? 100));
                $daily_limit = intval($_POST['daily_limit'] ?? 0);
                $model_type = normalize_ai_model_type($_POST['model_type'] ?? 'chat');
                
                if (empty($name) || empty($api_key) || empty($model_id)) {
                    $error = __('ai_models.error.required_fields');
                } else {
                    try {
                        $encrypted_api_key = encrypt_ai_api_key($api_key);
                        $stmt = $db->prepare("
                            INSERT INTO ai_models (name, version, api_key, model_id, model_type, api_url, failover_priority, daily_limit, status, created_at, updated_at)
                            VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'active', CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)
                        ");
                        
                        if ($stmt->execute([$name, $version, $encrypted_api_key, $model_id, $model_type, $api_url, $failover_priority, $daily_limit])) {
                            $newModelId = db_last_insert_id($db, 'ai_models');
                            if ($model_type === 'embedding' && $default_embedding_model_id <= 0) {
                                set_setting('default_embedding_model_id', (string) $newModelId);
                                $default_embedding_model_id = $newModelId;
                            }
                            $message = __('ai_models.message.create_success');
                        } else {
                            $error = __('ai_models.message.create_failed');
                        }
                    } catch (Exception $e) {
                        $error = __('message.create_failed') . ': ' . $e->getMessage();
                    }
                }
                break;
                
            case 'update_model':
                $id = intval($_POST['id'] ?? 0);
                $name = trim($_POST['name'] ?? '');
                $version = trim($_POST['version'] ?? '');
                $api_key = trim($_POST['api_key'] ?? '');
                $model_id = trim($_POST['model_id'] ?? '');
                $api_url = trim($_POST['api_url'] ?? '');
                $failover_priority = max(1, intval($_POST['failover_priority'] ?? 100));
                $daily_limit = intval($_POST['daily_limit'] ?? 0);
                $status = $_POST['status'] ?? 'active';
                $model_type = normalize_ai_model_type($_POST['model_type'] ?? 'chat');
                
                if ($id <= 0 || empty($name) || empty($model_id)) {
                    $error = __('ai_models.error.invalid_required_fields');
                } else {
                    try {
                        if ($api_key === '') {
                            $stmt = $db->prepare("
                                UPDATE ai_models 
                                SET name = ?, version = ?, model_id = ?, model_type = ?, api_url = ?, failover_priority = ?, daily_limit = ?, status = ?, updated_at = CURRENT_TIMESTAMP
                                WHERE id = ?
                            ");
                            $result = $stmt->execute([$name, $version, $model_id, $model_type, $api_url, $failover_priority, $daily_limit, $status, $id]);
                        } else {
                            $encrypted_api_key = encrypt_ai_api_key($api_key);
                            $stmt = $db->prepare("
                                UPDATE ai_models 
                                SET name = ?, version = ?, api_key = ?, model_id = ?, model_type = ?, api_url = ?, failover_priority = ?, daily_limit = ?, status = ?, updated_at = CURRENT_TIMESTAMP
                                WHERE id = ?
                            ");
                            $result = $stmt->execute([$name, $version, $encrypted_api_key, $model_id, $model_type, $api_url, $failover_priority, $daily_limit, $status, $id]);
                        }
                        
                        if ($result) {
                            if ($default_embedding_model_id === $id && ($model_type !== 'embedding' || $status !== 'active')) {
                                set_setting('default_embedding_model_id', '0');
                                $default_embedding_model_id = 0;
                            }
                            $message = __('ai_models.message.update_success');
                        } else {
                            $error = __('ai_models.message.update_failed');
                        }
                    } catch (Exception $e) {
                        $error = __('message.update_failed') . ': ' . $e->getMessage();
                    }
                }
                break;
                
            case 'delete_model':
                $id = intval($_POST['id'] ?? 0);
                
                if ($id <= 0) {
                    $error = __('ai_models.error.invalid_id');
                } else {
                    try {
                        $stmt = $db->prepare("SELECT id, name FROM ai_models WHERE id = ?");
                        $stmt->execute([$id]);
                        $model = $stmt->fetch(PDO::FETCH_ASSOC);

                        if (!$model) {
                            $error = __('ai_models.error.not_found');
                            break;
                        }

                        // 检查是否有任务在使用此模型
                        $stmt = $db->prepare("SELECT COUNT(*) FROM tasks WHERE ai_model_id = ?");
                        $stmt->execute([$id]);
                        $usage_count = $stmt->fetchColumn();
                        
                        if ($usage_count > 0) {
                            $error = __('ai_models.error.in_use', ['count' => $usage_count]);
                        } else {
                            $stmt = $db->prepare("DELETE FROM ai_models WHERE id = ?");
                            if ($stmt->execute([$id])) {
                                if ($default_embedding_model_id === $id) {
                                    set_setting('default_embedding_model_id', '0');
                                    $default_embedding_model_id = 0;
                                }
                                $message = __('ai_models.message.delete_success');
                            } else {
                                $error = __('ai_models.message.delete_failed');
                            }
                        }
                    } catch (Exception $e) {
                        $error = __('message.delete_failed') . ': ' . $e->getMessage();
                    }
                }
                break;

            case 'update_embedding_default':
                $embedding_model_id = intval($_POST['default_embedding_model_id'] ?? 0);

                if ($embedding_model_id > 0) {
                    $stmt = $db->prepare("
                        SELECT COUNT(*)
                        FROM ai_models
                        WHERE id = ?
                          AND status = 'active'
                          AND COALESCE(NULLIF(model_type, ''), 'chat') = 'embedding'
                    ");
                    $stmt->execute([$embedding_model_id]);
                    if ((int) $stmt->fetchColumn() === 0) {
                        $error = __('ai_models.error.embedding_unavailable');
                        break;
                    }
                }

                if (set_setting('default_embedding_model_id', (string) $embedding_model_id)) {
                    $default_embedding_model_id = $embedding_model_id;
                    $message = __('ai_models.message.embedding_default_updated');
                } else {
                    $error = __('ai_models.message.embedding_default_update_failed');
                }
                break;
        }
    }
}

try {
    migrate_ai_model_api_keys($db);
} catch (Exception $e) {
    write_log('AI模型密钥迁移失败: ' . $e->getMessage(), 'ERROR');
}

// 获取AI模型列表
try {
    $models = $db->query("
        SELECT m.id,
               m.name,
               m.version,
               m.api_key,
               m.model_id,
               COALESCE(NULLIF(m.model_type, ''), 'chat') as model_type,
               m.api_url,
               COALESCE(m.failover_priority, 100) AS failover_priority,
               m.daily_limit,
               m.used_today,
               m.total_used,
               m.status,
               m.created_at,
               m.updated_at,
               COALESCE(t.task_count, 0) as task_count,
               COALESCE(a.article_count, 0) as article_count
        FROM ai_models m
        LEFT JOIN (
            SELECT ai_model_id, COUNT(*) as task_count
            FROM tasks
            GROUP BY ai_model_id
        ) t ON m.id = t.ai_model_id
        LEFT JOIN (
            SELECT t.ai_model_id, COUNT(a.id) as article_count
            FROM articles a
            INNER JOIN tasks t ON a.task_id = t.id
            WHERE t.ai_model_id IS NOT NULL
            GROUP BY t.ai_model_id
        ) a ON m.id = a.ai_model_id
        ORDER BY m.created_at DESC
    ")->fetchAll();
} catch (Exception $e) {
    $models = [];
    $error = '获取模型列表失败: ' . $e->getMessage();
}

try {
    $embedding_models = $db->query("
        SELECT id, name, model_id
        FROM ai_models
        WHERE status = 'active'
          AND COALESCE(NULLIF(model_type, ''), 'chat') = 'embedding'
        ORDER BY name ASC, id DESC
    ")->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $embedding_models = [];
    if ($error === '') {
        $error = '获取 embedding 模型列表失败: ' . $e->getMessage();
    }
}

// 设置页面信息
$page_title = __('ai_models.page_title');
$page_header = '
<div class="flex items-center justify-between">
    <div class="flex items-center space-x-4">
        <a href="ai-configurator.php" class="text-gray-400 hover:text-gray-600">
            <i data-lucide="arrow-left" class="w-5 h-5"></i>
        </a>
        <div>
            <h1 class="text-2xl font-bold text-gray-900">' . htmlspecialchars(__('ai_models.page_title'), ENT_QUOTES) . '</h1>
            <p class="mt-1 text-sm text-gray-600">' . htmlspecialchars(__('ai_models.page_subtitle'), ENT_QUOTES) . '</p>
        </div>
    </div>
    <button onclick="showCreateModelModal()" class="inline-flex items-center px-4 py-2 border border-transparent text-sm font-medium rounded-md text-white bg-blue-600 hover:bg-blue-700">
        <i data-lucide="plus" class="w-4 h-4 mr-2"></i>
        ' . htmlspecialchars(__('ai_models.create'), ENT_QUOTES) . '
    </button>
</div>';

require_once __DIR__ . '/includes/header.php';
?>
<script>
const AI_MODELS_I18N = <?php echo json_encode([
    'modalCreate' => __('ai_models.modal_create'),
    'modalEdit' => __('ai_models.modal_edit'),
    'apiKeyPlaceholder' => __('ai_models.placeholder_api_key'),
    'apiKeyPlaceholderKeep' => __('ai_models.placeholder_api_key_keep'),
    'apiKeyHelpCreate' => __('ai_models.api_key_help_create'),
    'apiKeyHelpEdit' => __('ai_models.api_key_help_edit'),
    'confirmDelete' => __('ai_models.confirm_delete', ['name' => '__NAME__']),
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>;
</script>

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

        <div class="grid grid-cols-1 lg:grid-cols-2 gap-6 mb-6">
            <div class="bg-white shadow rounded-lg">
                <div class="px-6 py-4 border-b border-gray-200">
                    <h3 class="text-lg font-medium text-gray-900"><?php echo htmlspecialchars(__('ai_models.vector_title')); ?></h3>
                    <p class="mt-1 text-sm text-gray-600"><?php echo htmlspecialchars(__('ai_models.vector_desc')); ?></p>
                </div>
                <div class="px-6 py-5 space-y-4">
                    <div class="flex items-center justify-between">
                        <span class="text-sm text-gray-600"><?php echo htmlspecialchars(__('ai_models.pgvector')); ?></span>
                        <span class="inline-flex px-2 py-1 text-xs font-semibold rounded-full <?php echo $pgvector_enabled ? 'bg-green-100 text-green-800' : 'bg-yellow-100 text-yellow-800'; ?>">
                            <?php echo htmlspecialchars($pgvector_enabled ? __('ai_models.pgvector_enabled') : __('ai_models.pgvector_fallback')); ?>
                        </span>
                    </div>

                    <form method="POST" class="space-y-3">
                        <input type="hidden" name="csrf_token" value="<?php echo generate_csrf_token(); ?>">
                        <input type="hidden" name="action" value="update_embedding_default">
                        <div>
                            <label for="default_embedding_model_id" class="block text-sm font-medium text-gray-700"><?php echo htmlspecialchars(__('ai_models.default_embedding')); ?></label>
                            <select name="default_embedding_model_id" id="default_embedding_model_id"
                                    class="mt-1 block w-full border-gray-300 rounded-md shadow-sm focus:ring-blue-500 focus:border-blue-500 sm:text-sm">
                                <option value="0"><?php echo htmlspecialchars(__('ai_models.embedding_auto')); ?></option>
                                <?php foreach ($embedding_models as $model): ?>
                                    <option value="<?php echo (int) $model['id']; ?>" <?php echo $default_embedding_model_id === (int) $model['id'] ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($model['name'] . ' (' . $model['model_id'] . ')'); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <p class="mt-1 text-xs text-gray-500"><?php echo htmlspecialchars(__('ai_models.embedding_help')); ?></p>
                        </div>
                        <div class="flex justify-end">
                            <button type="submit"
                                    class="px-4 py-2 border border-transparent rounded-md shadow-sm text-sm font-medium text-white bg-slate-800 hover:bg-slate-900">
                                <?php echo htmlspecialchars(__('ai_models.save_default')); ?>
                            </button>
                        </div>
                    </form>
                </div>
            </div>

            <div class="bg-white shadow rounded-lg">
                <div class="px-6 py-4 border-b border-gray-200">
                    <h3 class="text-lg font-medium text-gray-900"><?php echo htmlspecialchars(__('ai_models.type_title')); ?></h3>
                    <p class="mt-1 text-sm text-gray-600"><?php echo htmlspecialchars(__('ai_models.type_desc')); ?></p>
                </div>
                <div class="px-6 py-5 space-y-3 text-sm text-gray-700">
                    <p><?php echo htmlspecialchars(__('ai_models.type_chat')); ?></p>
                    <p><?php echo htmlspecialchars(__('ai_models.type_embedding')); ?></p>
                    <p><?php echo htmlspecialchars(__('ai_models.type_rerank')); ?></p>
                    <p><?php echo htmlspecialchars(__('ai_models.type_fallback')); ?></p>
                </div>
            </div>
        </div>

        <!-- AI模型列表 -->
        <div class="bg-white shadow rounded-lg">
            <div class="px-6 py-4 border-b border-gray-200">
                <h3 class="text-lg font-medium text-gray-900"><?php echo htmlspecialchars(__('ai_models.list_title')); ?></h3>
                <p class="mt-1 text-sm text-gray-600"><?php echo htmlspecialchars(__('ai_models.list_desc')); ?></p>
            </div>
            
            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-gray-200">
                    <thead class="bg-gray-50">
                        <tr>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider"><?php echo htmlspecialchars(__('ai_models.column.info')); ?></th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider"><?php echo htmlspecialchars(__('ai_models.column.version')); ?></th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider"><?php echo htmlspecialchars(__('ai_models.column.usage')); ?></th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider"><?php echo htmlspecialchars(__('ai_models.column.limit')); ?></th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider"><?php echo htmlspecialchars(__('ai_models.column.status')); ?></th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider"><?php echo htmlspecialchars(__('ai_models.column.actions')); ?></th>
                        </tr>
                    </thead>
                    <tbody class="bg-white divide-y divide-gray-200">
                        <?php if (empty($models)): ?>
                            <tr>
                                <td colspan="6" class="px-6 py-4 text-center text-gray-500">
                                    <i data-lucide="cpu" class="w-8 h-8 mx-auto mb-2 text-gray-400"></i>
                                    <p><?php echo htmlspecialchars(__('ai_models.empty')); ?></p>
                                    <button onclick="showCreateModelModal()" class="mt-2 text-blue-600 hover:text-blue-800"><?php echo htmlspecialchars(__('ai_models.add_first')); ?></button>
                                </td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($models as $model): ?>
                                <tr>
                                    <td class="px-6 py-4">
                                        <div>
                                            <div class="flex items-center gap-2">
                                                <div class="text-sm font-medium text-gray-900"><?php echo htmlspecialchars($model['name']); ?></div>
                                                <span class="inline-flex px-2 py-0.5 text-xs font-semibold rounded-full <?php echo $model['model_type'] === 'embedding' ? 'bg-amber-100 text-amber-800' : 'bg-sky-100 text-sky-800'; ?>">
                                                    <?php echo htmlspecialchars($model['model_type'] === 'embedding' ? __('ai_models.type_embedding_option') : __('ai_models.chat')); ?>
                                                </span>
                                                <?php if ($model['model_type'] === 'embedding' && $default_embedding_model_id === (int) $model['id']): ?>
                                                    <span class="inline-flex px-2 py-0.5 text-xs font-semibold rounded-full bg-emerald-100 text-emerald-800"><?php echo htmlspecialchars(__('ai_models.embedding_default')); ?></span>
                                                <?php endif; ?>
                                            </div>
                                            <div class="text-sm text-gray-500"><?php echo htmlspecialchars($model['model_id']); ?></div>
                                            <div class="text-xs text-gray-400"><?php echo htmlspecialchars(__('ai_models.api_key_mask')); ?>: <?php echo htmlspecialchars(mask_api_key($model['api_key'])); ?></div>
                                            <div class="text-xs text-gray-400"><?php echo htmlspecialchars(__('ai_models.failover_priority_label', ['priority' => (int) ($model['failover_priority'] ?? 100)])); ?></div>
                                        </div>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900">
                                        <?php echo htmlspecialchars($model['version'] ?: '-'); ?>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap">
                                        <div class="text-sm text-gray-900">
                                            <div><?php echo htmlspecialchars(__('ai_models.usage_tasks', ['count' => (string) $model['task_count']])); ?></div>
                                            <div><?php echo htmlspecialchars(__('ai_models.usage_articles', ['count' => (string) $model['article_count']])); ?></div>
                                            <div><?php echo htmlspecialchars(__('ai_models.usage_total', ['count' => (string) number_format($model['total_used'])])); ?></div>
                                        </div>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900">
                                        <?php if ($model['daily_limit'] > 0): ?>
                                            <div><?php echo $model['used_today']; ?> / <?php echo $model['daily_limit']; ?></div>
                                            <div class="text-xs text-gray-500"><?php echo htmlspecialchars(__('ai_models.limit_today')); ?></div>
                                        <?php else: ?>
                                            <span class="text-green-600"><?php echo htmlspecialchars(__('ai_models.limit_unlimited')); ?></span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap">
                                        <?php if (isset($model['status']) && !empty($model['status'])): ?>
                                            <span class="inline-flex px-2 py-1 text-xs font-semibold rounded-full <?php echo $model['status'] === 'active' ? 'bg-green-100 text-green-800' : 'bg-red-100 text-red-800'; ?>">
                                                <?php echo htmlspecialchars($model['status'] === 'active' ? __('ai_models.status_active') : __('ai_models.status_inactive')); ?>
                                            </span>
                                        <?php else: ?>
                                            <span class="inline-flex px-2 py-1 text-xs font-semibold rounded-full bg-gray-100 text-gray-800">
                                                <?php echo htmlspecialchars(__('ai_models.status_unknown')); ?>
                                            </span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm font-medium space-x-2">
                                        <button onclick='editModel(<?php echo json_encode([
                                            "id" => (int) $model["id"],
                                            "name" => $model["name"],
                                            "version" => $model["version"],
                                            "model_id" => $model["model_id"],
                                            "model_type" => $model["model_type"],
                                            "api_url" => $model["api_url"],
                                            "failover_priority" => (int) ($model["failover_priority"] ?? 100),
                                            "daily_limit" => (int) $model["daily_limit"],
                                            "status" => $model["status"]
                                        ], JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP); ?>)' class="text-blue-600 hover:text-blue-900"><?php echo htmlspecialchars(__('ai_models.edit')); ?></button>
                                        <button onclick="deleteModel(<?php echo $model['id']; ?>, '<?php echo htmlspecialchars($model['name']); ?>')" class="text-red-600 hover:text-red-900"><?php echo htmlspecialchars(__('ai_models.delete')); ?></button>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- 创建/编辑模型模态框 -->
        <div id="modelModal" class="fixed inset-0 bg-gray-600 bg-opacity-50 overflow-y-auto h-full w-full hidden">
            <div class="relative top-20 mx-auto p-5 border w-11/12 md:w-3/4 lg:w-1/2 shadow-lg rounded-md bg-white">
                <div class="mt-3">
                    <div class="flex items-center justify-between mb-4">
                        <h3 class="text-lg font-medium text-gray-900" id="modalTitle"><?php echo htmlspecialchars(__('ai_models.modal_create')); ?></h3>
                        <button onclick="closeModelModal()" class="text-gray-400 hover:text-gray-600">
                            <i data-lucide="x" class="w-6 h-6"></i>
                        </button>
                    </div>

                    <form id="modelForm" method="POST" class="space-y-6">
                        <input type="hidden" name="csrf_token" value="<?php echo generate_csrf_token(); ?>">
                        <input type="hidden" name="action" id="formAction" value="create_model">
                        <input type="hidden" name="id" id="modelId" value="">

                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-2"><?php echo htmlspecialchars(__('ai_models.quick_chat')); ?></label>
                            <div class="flex flex-wrap gap-2">
                                <button type="button" onclick="fillPreset('minimax')"
                                        class="inline-flex items-center px-3 py-1.5 border border-gray-300 rounded-md text-xs font-medium text-gray-700 bg-white hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-blue-500">
                                    MiniMax
                                </button>
                                <button type="button" onclick="fillPreset('minimax_highspeed')"
                                        class="inline-flex items-center px-3 py-1.5 border border-gray-300 rounded-md text-xs font-medium text-gray-700 bg-white hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-blue-500">
                                    MiniMax Highspeed
                                </button>
                                <button type="button" onclick="fillPreset('openai')"
                                        class="inline-flex items-center px-3 py-1.5 border border-gray-300 rounded-md text-xs font-medium text-gray-700 bg-white hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-blue-500">
                                    OpenAI
                                </button>
                                <button type="button" onclick="fillPreset('deepseek')"
                                        class="inline-flex items-center px-3 py-1.5 border border-gray-300 rounded-md text-xs font-medium text-gray-700 bg-white hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-blue-500">
                                    DeepSeek
                                </button>
                                <button type="button" onclick="fillPreset('zhipu')"
                                        class="inline-flex items-center px-3 py-1.5 border border-gray-300 rounded-md text-xs font-medium text-gray-700 bg-white hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-blue-500">
                                    Zhipu GLM
                                </button>
                                <button type="button" onclick="fillPreset('volcengine_ark')"
                                        class="inline-flex items-center px-3 py-1.5 border border-gray-300 rounded-md text-xs font-medium text-gray-700 bg-white hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-blue-500">
                                    Volcengine Ark
                                </button>
                            </div>
                            <label class="block text-sm font-medium text-gray-700 mt-4 mb-2"><?php echo htmlspecialchars(__('ai_models.quick_embedding')); ?></label>
                            <div class="flex flex-wrap gap-2">
                                <button type="button" onclick="fillPreset('openai_embedding')"
                                        class="inline-flex items-center px-3 py-1.5 border border-gray-300 rounded-md text-xs font-medium text-gray-700 bg-white hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-blue-500">
                                    OpenAI Embedding
                                </button>
                                <button type="button" onclick="fillPreset('zhipu_embedding')"
                                        class="inline-flex items-center px-3 py-1.5 border border-gray-300 rounded-md text-xs font-medium text-gray-700 bg-white hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-blue-500">
                                    Zhipu Embedding
                                </button>
                            </div>
                            <p class="mt-1 text-xs text-gray-500"><?php echo htmlspecialchars(__('ai_models.quick_help')); ?></p>
                        </div>

                        <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                            <div>
                                <label for="name" class="block text-sm font-medium text-gray-700"><?php echo htmlspecialchars(__('ai_models.field_name')); ?></label>
                                <input type="text" name="name" id="name" required
                                       class="mt-1 block w-full border-gray-300 rounded-md shadow-sm focus:ring-blue-500 focus:border-blue-500 sm:text-sm"
                                       placeholder="<?php echo htmlspecialchars(__('ai_models.placeholder_name')); ?>">
                            </div>

                            <div>
                                <label for="version" class="block text-sm font-medium text-gray-700"><?php echo htmlspecialchars(__('ai_models.field_version')); ?></label>
                                <input type="text" name="version" id="version"
                                       class="mt-1 block w-full border-gray-300 rounded-md shadow-sm focus:ring-blue-500 focus:border-blue-500 sm:text-sm"
                                       placeholder="<?php echo htmlspecialchars(__('ai_models.placeholder_version')); ?>">
                            </div>
                        </div>

                        <div>
                            <label for="model_type" class="block text-sm font-medium text-gray-700"><?php echo htmlspecialchars(__('ai_models.field_type')); ?></label>
                            <select name="model_type" id="model_type"
                                    class="mt-1 block w-full border-gray-300 rounded-md shadow-sm focus:ring-blue-500 focus:border-blue-500 sm:text-sm">
                                <option value="chat"><?php echo htmlspecialchars(__('ai_models.type_chat_option')); ?></option>
                                <option value="embedding"><?php echo htmlspecialchars(__('ai_models.type_embedding_option')); ?></option>
                            </select>
                            <p class="mt-1 text-xs text-gray-500"><?php echo htmlspecialchars(__('ai_models.type_help')); ?></p>
                        </div>

                        <div>
                            <label for="model_id" class="block text-sm font-medium text-gray-700"><?php echo htmlspecialchars(__('ai_models.field_model_id')); ?></label>
                            <input type="text" name="model_id" id="model_id" required
                                   class="mt-1 block w-full border-gray-300 rounded-md shadow-sm focus:ring-blue-500 focus:border-blue-500 sm:text-sm"
                                   placeholder="<?php echo htmlspecialchars(__('ai_models.placeholder_model_id')); ?>">
                        </div>

                        <div>
                            <label for="api_key" class="block text-sm font-medium text-gray-700"><?php echo htmlspecialchars(__('ai_models.field_api_key')); ?></label>
                            <input type="password" name="api_key" id="api_key" required
                                   class="mt-1 block w-full border-gray-300 rounded-md shadow-sm focus:ring-blue-500 focus:border-blue-500 sm:text-sm"
                                   placeholder="<?php echo htmlspecialchars(__('ai_models.placeholder_api_key')); ?>">
                            <p id="apiKeyHelp" class="mt-1 text-xs text-gray-500"><?php echo htmlspecialchars(__('ai_models.api_key_help_create')); ?></p>
                        </div>

                        <div>
                            <label for="api_url" class="block text-sm font-medium text-gray-700"><?php echo htmlspecialchars(__('ai_models.field_api_url')); ?></label>
                            <input type="url" name="api_url" id="api_url"
                                   class="mt-1 block w-full border-gray-300 rounded-md shadow-sm focus:ring-blue-500 focus:border-blue-500 sm:text-sm"
                                   value="https://api.deepseek.com"
                                   placeholder="<?php echo htmlspecialchars(__('ai_models.placeholder_api_url')); ?>">
                            <p class="mt-1 text-xs text-gray-500"><?php echo htmlspecialchars(__('ai_models.api_url_help')); ?></p>
                        </div>

                        <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                            <div>
                                <label for="failover_priority" class="block text-sm font-medium text-gray-700"><?php echo htmlspecialchars(__('ai_models.field_failover_priority')); ?></label>
                                <input type="number" name="failover_priority" id="failover_priority" min="1"
                                       class="mt-1 block w-full border-gray-300 rounded-md shadow-sm focus:ring-blue-500 focus:border-blue-500 sm:text-sm"
                                       value="100">
                                <p class="mt-1 text-xs text-gray-500"><?php echo htmlspecialchars(__('ai_models.failover_priority_help')); ?></p>
                            </div>

                            <div>
                                <label for="daily_limit" class="block text-sm font-medium text-gray-700"><?php echo htmlspecialchars(__('ai_models.field_daily_limit')); ?></label>
                                <input type="number" name="daily_limit" id="daily_limit" min="0"
                                       class="mt-1 block w-full border-gray-300 rounded-md shadow-sm focus:ring-blue-500 focus:border-blue-500 sm:text-sm"
                                       placeholder="0">
                                <p class="mt-1 text-xs text-gray-500"><?php echo htmlspecialchars(__('ai_models.limit_help')); ?></p>
                            </div>

                            <div id="statusField" class="hidden">
                                <label for="status" class="block text-sm font-medium text-gray-700"><?php echo htmlspecialchars(__('ai_models.field_status')); ?></label>
                                <select name="status" id="status"
                                        class="mt-1 block w-full border-gray-300 rounded-md shadow-sm focus:ring-blue-500 focus:border-blue-500 sm:text-sm">
                                    <option value="active"><?php echo htmlspecialchars(__('ai_models.status_active')); ?></option>
                                    <option value="inactive"><?php echo htmlspecialchars(__('ai_models.status_inactive')); ?></option>
                                </select>
                            </div>
                        </div>

                        <div class="flex justify-end space-x-3 pt-4">
                            <button type="button" onclick="closeModelModal()"
                                    class="px-4 py-2 border border-gray-300 rounded-md text-sm font-medium text-gray-700 bg-white hover:bg-gray-50">
                                <?php echo htmlspecialchars(__('button.cancel')); ?>
                            </button>
                            <button type="submit"
                                    class="px-4 py-2 border border-transparent rounded-md shadow-sm text-sm font-medium text-white bg-blue-600 hover:bg-blue-700">
                                <?php echo htmlspecialchars(__('button.save')); ?>
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    <script>
        // 显示创建模型模态框
        function showCreateModelModal() {
            document.getElementById('modalTitle').textContent = AI_MODELS_I18N.modalCreate;
            document.getElementById('formAction').value = 'create_model';
            document.getElementById('modelId').value = '';
            document.getElementById('statusField').classList.add('hidden');
            document.getElementById('modelForm').reset();
            document.getElementById('model_type').value = 'chat';
            document.getElementById('api_key').required = true;
            document.getElementById('api_key').placeholder = AI_MODELS_I18N.apiKeyPlaceholder;
            document.getElementById('apiKeyHelp').textContent = AI_MODELS_I18N.apiKeyHelpCreate;
            document.getElementById('api_url').value = 'https://api.deepseek.com';
            document.getElementById('failover_priority').value = 100;
            document.getElementById('modelModal').classList.remove('hidden');
        }

        // 编辑模型
        function editModel(model) {
            document.getElementById('modalTitle').textContent = AI_MODELS_I18N.modalEdit;
            document.getElementById('formAction').value = 'update_model';
            document.getElementById('modelId').value = model.id;
            document.getElementById('name').value = model.name;
            document.getElementById('version').value = model.version || '';
            document.getElementById('model_id').value = model.model_id;
            document.getElementById('model_type').value = model.model_type || 'chat';
            document.getElementById('api_key').value = '';
            document.getElementById('api_key').required = false;
            document.getElementById('api_key').placeholder = AI_MODELS_I18N.apiKeyPlaceholderKeep;
            document.getElementById('apiKeyHelp').textContent = AI_MODELS_I18N.apiKeyHelpEdit;
            document.getElementById('api_url').value = model.api_url;
            document.getElementById('failover_priority').value = model.failover_priority || 100;
            document.getElementById('daily_limit').value = model.daily_limit;
            document.getElementById('status').value = model.status;
            document.getElementById('statusField').classList.remove('hidden');
            document.getElementById('modelModal').classList.remove('hidden');
        }

        // 关闭模态框
        function closeModelModal() {
            document.getElementById('modelModal').classList.add('hidden');
        }

        // 删除模型
        function deleteModel(id, name) {
            if (confirm(AI_MODELS_I18N.confirmDelete.replace('__NAME__', name))) {
                const form = document.createElement('form');
                form.method = 'POST';
                form.innerHTML = `
                    <input type="hidden" name="csrf_token" value="<?php echo generate_csrf_token(); ?>">
                    <input type="hidden" name="action" value="delete_model">
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
        const PROVIDER_PRESETS = {
            'minimax': {
                name: 'MiniMax M2.7',
                version: 'M2.7',
                model_id: 'MiniMax-M2.7',
                api_url: 'https://api.minimax.io',
                model_type: 'chat',
            },
            'minimax_highspeed': {
                name: 'MiniMax M2.7 Highspeed',
                version: 'M2.7',
                model_id: 'MiniMax-M2.7-highspeed',
                api_url: 'https://api.minimax.io',
                model_type: 'chat',
            },
            'openai': {
                name: 'GPT-4o',
                version: '',
                model_id: 'gpt-4o',
                api_url: 'https://api.openai.com',
                model_type: 'chat',
            },
            'deepseek': {
                name: 'DeepSeek Chat',
                version: '',
                model_id: 'deepseek-chat',
                api_url: 'https://api.deepseek.com',
                model_type: 'chat',
            },
            'zhipu': {
                name: '智谱 GLM-4.6',
                version: 'v4',
                model_id: 'glm-4.6',
                api_url: 'https://open.bigmodel.cn/api/paas/v4',
                model_type: 'chat',
            },
            'volcengine_ark': {
                name: '火山方舟 Chat',
                version: 'v3',
                model_id: '',
                api_url: 'https://ark.cn-beijing.volces.com/api/v3',
                model_type: 'chat',
            },
            'openai_embedding': {
                name: 'OpenAI Embedding 3 Small',
                version: '',
                model_id: 'text-embedding-3-small',
                api_url: 'https://api.openai.com',
                model_type: 'embedding',
            },
            'zhipu_embedding': {
                name: '智谱 Embedding-3',
                version: 'v4',
                model_id: 'embedding-3',
                api_url: 'https://open.bigmodel.cn/api/paas/v4',
                model_type: 'embedding',
            },
        };

        function fillPreset(provider) {
            const preset = PROVIDER_PRESETS[provider];
            if (!preset) return;
            document.getElementById('name').value = preset.name;
            document.getElementById('version').value = preset.version;
            document.getElementById('model_id').value = preset.model_id;
            document.getElementById('api_url').value = preset.api_url;
            document.getElementById('model_type').value = preset.model_type;
        }
    </script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
