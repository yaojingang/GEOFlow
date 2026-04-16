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
        $error = 'CSRF验证失败';
    } else {
        $action = $_POST['action'] ?? '';
        
        switch ($action) {
            case 'create_model':
                $name = trim($_POST['name'] ?? '');
                $version = trim($_POST['version'] ?? '');
                $api_key = trim($_POST['api_key'] ?? '');
                $model_id = trim($_POST['model_id'] ?? '');
                $api_url = trim($_POST['api_url'] ?? 'https://api.tu-zi.com');
                $daily_limit = intval($_POST['daily_limit'] ?? 0);
                $model_type = normalize_ai_model_type($_POST['model_type'] ?? 'chat');
                
                if (empty($name) || empty($api_key) || empty($model_id)) {
                    $error = '模型名称、API密钥和模型ID不能为空';
                } else {
                    try {
                        $encrypted_api_key = encrypt_ai_api_key($api_key);
                        $stmt = $db->prepare("
                            INSERT INTO ai_models (name, version, api_key, model_id, model_type, api_url, daily_limit, status, created_at, updated_at)
                            VALUES (?, ?, ?, ?, ?, ?, ?, 'active', CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)
                        ");
                        
                        if ($stmt->execute([$name, $version, $encrypted_api_key, $model_id, $model_type, $api_url, $daily_limit])) {
                            $newModelId = db_last_insert_id($db, 'ai_models');
                            if ($model_type === 'embedding' && $default_embedding_model_id <= 0) {
                                set_setting('default_embedding_model_id', (string) $newModelId);
                                $default_embedding_model_id = $newModelId;
                            }
                            $message = 'AI模型创建成功';
                        } else {
                            $error = 'AI模型创建失败';
                        }
                    } catch (Exception $e) {
                        $error = '创建失败: ' . $e->getMessage();
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
                $daily_limit = intval($_POST['daily_limit'] ?? 0);
                $status = $_POST['status'] ?? 'active';
                $model_type = normalize_ai_model_type($_POST['model_type'] ?? 'chat');
                
                if ($id <= 0 || empty($name) || empty($model_id)) {
                    $error = '参数错误或必填字段为空';
                } else {
                    try {
                        if ($api_key === '') {
                            $stmt = $db->prepare("
                                UPDATE ai_models 
                                SET name = ?, version = ?, model_id = ?, model_type = ?, api_url = ?, daily_limit = ?, status = ?, updated_at = CURRENT_TIMESTAMP
                                WHERE id = ?
                            ");
                            $result = $stmt->execute([$name, $version, $model_id, $model_type, $api_url, $daily_limit, $status, $id]);
                        } else {
                            $encrypted_api_key = encrypt_ai_api_key($api_key);
                            $stmt = $db->prepare("
                                UPDATE ai_models 
                                SET name = ?, version = ?, api_key = ?, model_id = ?, model_type = ?, api_url = ?, daily_limit = ?, status = ?, updated_at = CURRENT_TIMESTAMP
                                WHERE id = ?
                            ");
                            $result = $stmt->execute([$name, $version, $encrypted_api_key, $model_id, $model_type, $api_url, $daily_limit, $status, $id]);
                        }
                        
                        if ($result) {
                            if ($default_embedding_model_id === $id && ($model_type !== 'embedding' || $status !== 'active')) {
                                set_setting('default_embedding_model_id', '0');
                                $default_embedding_model_id = 0;
                            }
                            $message = 'AI模型更新成功';
                        } else {
                            $error = 'AI模型更新失败';
                        }
                    } catch (Exception $e) {
                        $error = '更新失败: ' . $e->getMessage();
                    }
                }
                break;
                
            case 'delete_model':
                $id = intval($_POST['id'] ?? 0);
                
                if ($id <= 0) {
                    $error = '无效的模型ID';
                } else {
                    try {
                        $stmt = $db->prepare("SELECT id, name FROM ai_models WHERE id = ?");
                        $stmt->execute([$id]);
                        $model = $stmt->fetch(PDO::FETCH_ASSOC);

                        if (!$model) {
                            $error = 'AI模型不存在';
                            break;
                        }

                        // 检查是否有任务在使用此模型
                        $stmt = $db->prepare("SELECT COUNT(*) FROM tasks WHERE ai_model_id = ?");
                        $stmt->execute([$id]);
                        $usage_count = $stmt->fetchColumn();
                        
                        if ($usage_count > 0) {
                            $error = "无法删除：有 {$usage_count} 个任务正在使用此模型";
                        } else {
                            $stmt = $db->prepare("DELETE FROM ai_models WHERE id = ?");
                            if ($stmt->execute([$id])) {
                                if ($default_embedding_model_id === $id) {
                                    set_setting('default_embedding_model_id', '0');
                                    $default_embedding_model_id = 0;
                                }
                                $message = 'AI模型删除成功';
                            } else {
                                $error = 'AI模型删除失败';
                            }
                        }
                    } catch (Exception $e) {
                        $error = '删除失败: ' . $e->getMessage();
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
                        $error = '所选默认 embedding 模型不可用';
                        break;
                    }
                }

                if (set_setting('default_embedding_model_id', (string) $embedding_model_id)) {
                    $default_embedding_model_id = $embedding_model_id;
                    $message = '默认 embedding 模型已更新';
                } else {
                    $error = '默认 embedding 模型更新失败';
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
$page_title = 'AI模型配置';
$page_header = '
<div class="flex items-center justify-between">
    <div class="flex items-center space-x-4">
        <a href="ai-configurator.php" class="text-gray-400 hover:text-gray-600">
            <i data-lucide="arrow-left" class="w-5 h-5"></i>
        </a>
        <div>
            <h1 class="text-2xl font-bold text-gray-900">AI模型配置</h1>
            <p class="mt-1 text-sm text-gray-600">统一管理聊天模型与 embedding 检索模型</p>
        </div>
    </div>
    <button onclick="showCreateModelModal()" class="inline-flex items-center px-4 py-2 border border-transparent text-sm font-medium rounded-md text-white bg-blue-600 hover:bg-blue-700">
        <i data-lucide="plus" class="w-4 h-4 mr-2"></i>
        新增模型
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

        <div class="grid grid-cols-1 lg:grid-cols-2 gap-6 mb-6">
            <div class="bg-white shadow rounded-lg">
                <div class="px-6 py-4 border-b border-gray-200">
                    <h3 class="text-lg font-medium text-gray-900">向量检索状态</h3>
                    <p class="mt-1 text-sm text-gray-600">知识库检索使用独立 embedding 模型与 pgvector</p>
                </div>
                <div class="px-6 py-5 space-y-4">
                    <div class="flex items-center justify-between">
                        <span class="text-sm text-gray-600">pgvector 扩展</span>
                        <span class="inline-flex px-2 py-1 text-xs font-semibold rounded-full <?php echo $pgvector_enabled ? 'bg-green-100 text-green-800' : 'bg-yellow-100 text-yellow-800'; ?>">
                            <?php echo $pgvector_enabled ? '已启用' : '未启用，当前会回退'; ?>
                        </span>
                    </div>

                    <form method="POST" class="space-y-3">
                        <input type="hidden" name="csrf_token" value="<?php echo generate_csrf_token(); ?>">
                        <input type="hidden" name="action" value="update_embedding_default">
                        <div>
                            <label for="default_embedding_model_id" class="block text-sm font-medium text-gray-700">默认 embedding 模型</label>
                            <select name="default_embedding_model_id" id="default_embedding_model_id"
                                    class="mt-1 block w-full border-gray-300 rounded-md shadow-sm focus:ring-blue-500 focus:border-blue-500 sm:text-sm">
                                <option value="0">自动选择最新可用 embedding 模型</option>
                                <?php foreach ($embedding_models as $model): ?>
                                    <option value="<?php echo (int) $model['id']; ?>" <?php echo $default_embedding_model_id === (int) $model['id'] ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($model['name'] . ' (' . $model['model_id'] . ')'); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <p class="mt-1 text-xs text-gray-500">正文生成仍然使用聊天模型；这里仅用于知识库切块向量化和检索查询。</p>
                        </div>
                        <div class="flex justify-end">
                            <button type="submit"
                                    class="px-4 py-2 border border-transparent rounded-md shadow-sm text-sm font-medium text-white bg-slate-800 hover:bg-slate-900">
                                保存默认模型
                            </button>
                        </div>
                    </form>
                </div>
            </div>

            <div class="bg-white shadow rounded-lg">
                <div class="px-6 py-4 border-b border-gray-200">
                    <h3 class="text-lg font-medium text-gray-900">模型类型说明</h3>
                    <p class="mt-1 text-sm text-gray-600">避免把 embedding 模型误配到任务中心</p>
                </div>
                <div class="px-6 py-5 space-y-3 text-sm text-gray-700">
                    <p><span class="font-medium text-gray-900">聊天模型：</span>用于任务中心正文生成、关键词生成、描述生成和标题 AI 生成。</p>
                    <p><span class="font-medium text-gray-900">Embedding 模型：</span>用于知识库切块向量化和检索召回，不会出现在任务中心的 AI 模型下拉框里。</p>
                    <p><span class="font-medium text-gray-900">回退策略：</span>如果当前数据库没启用 pgvector 或 embedding 模型不可用，系统会自动回退到轻量检索，不会阻断文章生成。</p>
                </div>
            </div>
        </div>

        <!-- AI模型列表 -->
        <div class="bg-white shadow rounded-lg">
            <div class="px-6 py-4 border-b border-gray-200">
                <h3 class="text-lg font-medium text-gray-900">AI模型列表</h3>
                <p class="mt-1 text-sm text-gray-600">统一查看聊天模型和 embedding 模型</p>
            </div>
            
            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-gray-200">
                    <thead class="bg-gray-50">
                        <tr>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">模型信息</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">版本</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">调用统计</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">限制</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">状态</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">操作</th>
                        </tr>
                    </thead>
                    <tbody class="bg-white divide-y divide-gray-200">
                        <?php if (empty($models)): ?>
                            <tr>
                                <td colspan="6" class="px-6 py-4 text-center text-gray-500">
                                    <i data-lucide="cpu" class="w-8 h-8 mx-auto mb-2 text-gray-400"></i>
                                    <p>暂无AI模型配置</p>
                                    <button onclick="showCreateModelModal()" class="mt-2 text-blue-600 hover:text-blue-800">添加第一个模型</button>
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
                                                    <?php echo $model['model_type'] === 'embedding' ? 'Embedding' : '聊天模型'; ?>
                                                </span>
                                                <?php if ($model['model_type'] === 'embedding' && $default_embedding_model_id === (int) $model['id']): ?>
                                                    <span class="inline-flex px-2 py-0.5 text-xs font-semibold rounded-full bg-emerald-100 text-emerald-800">默认检索模型</span>
                                                <?php endif; ?>
                                            </div>
                                            <div class="text-sm text-gray-500"><?php echo htmlspecialchars($model['model_id']); ?></div>
                                            <div class="text-xs text-gray-400">密钥: <?php echo htmlspecialchars(mask_api_key($model['api_key'])); ?></div>
                                        </div>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900">
                                        <?php echo htmlspecialchars($model['version'] ?: '-'); ?>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap">
                                        <div class="text-sm text-gray-900">
                                            <div>任务: <?php echo $model['task_count']; ?></div>
                                            <div>文章: <?php echo $model['article_count']; ?></div>
                                            <div>总计: <?php echo number_format($model['total_used']); ?></div>
                                        </div>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900">
                                        <?php if ($model['daily_limit'] > 0): ?>
                                            <div><?php echo $model['used_today']; ?> / <?php echo $model['daily_limit']; ?></div>
                                            <div class="text-xs text-gray-500">今日/限制</div>
                                        <?php else: ?>
                                            <span class="text-green-600">无限制</span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap">
                                        <?php if (isset($model['status']) && !empty($model['status'])): ?>
                                            <span class="inline-flex px-2 py-1 text-xs font-semibold rounded-full <?php echo $model['status'] === 'active' ? 'bg-green-100 text-green-800' : 'bg-red-100 text-red-800'; ?>">
                                                <?php echo $model['status'] === 'active' ? '活跃' : '禁用'; ?>
                                            </span>
                                        <?php else: ?>
                                            <span class="inline-flex px-2 py-1 text-xs font-semibold rounded-full bg-gray-100 text-gray-800">
                                                未知
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
                                            "daily_limit" => (int) $model["daily_limit"],
                                            "status" => $model["status"]
                                        ], JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP); ?>)' class="text-blue-600 hover:text-blue-900">编辑</button>
                                        <button onclick="deleteModel(<?php echo $model['id']; ?>, '<?php echo htmlspecialchars($model['name']); ?>')" class="text-red-600 hover:text-red-900">删除</button>
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
                        <h3 class="text-lg font-medium text-gray-900" id="modalTitle">新增AI模型</h3>
                        <button onclick="closeModelModal()" class="text-gray-400 hover:text-gray-600">
                            <i data-lucide="x" class="w-6 h-6"></i>
                        </button>
                    </div>

                    <form id="modelForm" method="POST" class="space-y-6">
                        <input type="hidden" name="csrf_token" value="<?php echo generate_csrf_token(); ?>">
                        <input type="hidden" name="action" id="formAction" value="create_model">
                        <input type="hidden" name="id" id="modelId" value="">

                        <!-- 服务商快速填充 -->
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-2">服务商快速填充</label>
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
                            </div>
                            <p class="mt-1 text-xs text-gray-500">点击自动填充服务商配置，填写 API 密钥后即可保存</p>
                        </div>

                        <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                            <div>
                                <label for="name" class="block text-sm font-medium text-gray-700">模型名称 *</label>
                                <input type="text" name="name" id="name" required
                                       class="mt-1 block w-full border-gray-300 rounded-md shadow-sm focus:ring-blue-500 focus:border-blue-500 sm:text-sm"
                                       placeholder="例如：Claude Sonnet 4">
                            </div>

                            <div>
                                <label for="version" class="block text-sm font-medium text-gray-700">模型版本</label>
                                <input type="text" name="version" id="version"
                                       class="mt-1 block w-full border-gray-300 rounded-md shadow-sm focus:ring-blue-500 focus:border-blue-500 sm:text-sm"
                                       placeholder="例如：20250514">
                            </div>
                        </div>

                        <div>
                            <label for="model_type" class="block text-sm font-medium text-gray-700">模型类型 *</label>
                            <select name="model_type" id="model_type"
                                    class="mt-1 block w-full border-gray-300 rounded-md shadow-sm focus:ring-blue-500 focus:border-blue-500 sm:text-sm">
                                <option value="chat">聊天模型</option>
                                <option value="embedding">Embedding 检索模型</option>
                            </select>
                            <p class="mt-1 text-xs text-gray-500">聊天模型用于任务中心生成内容；embedding 模型用于知识库向量化与召回。</p>
                        </div>

                        <div>
                            <label for="model_id" class="block text-sm font-medium text-gray-700">模型ID *</label>
                            <input type="text" name="model_id" id="model_id" required
                                   class="mt-1 block w-full border-gray-300 rounded-md shadow-sm focus:ring-blue-500 focus:border-blue-500 sm:text-sm"
                                   placeholder="例如：claude-sonnet-4-20250514">
                        </div>

                        <div>
                            <label for="api_key" class="block text-sm font-medium text-gray-700">API密钥 *</label>
                            <input type="password" name="api_key" id="api_key" required
                                   class="mt-1 block w-full border-gray-300 rounded-md shadow-sm focus:ring-blue-500 focus:border-blue-500 sm:text-sm"
                                   placeholder="输入API密钥">
                            <p id="apiKeyHelp" class="mt-1 text-xs text-gray-500">创建模型时必填。</p>
                        </div>

                        <div>
                            <label for="api_url" class="block text-sm font-medium text-gray-700">API地址</label>
                            <input type="url" name="api_url" id="api_url"
                                   class="mt-1 block w-full border-gray-300 rounded-md shadow-sm focus:ring-blue-500 focus:border-blue-500 sm:text-sm"
                                   value="https://api.tu-zi.com"
                                   placeholder="https://api.tu-zi.com">
                        </div>

                        <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                            <div>
                                <label for="daily_limit" class="block text-sm font-medium text-gray-700">每日调用限制</label>
                                <input type="number" name="daily_limit" id="daily_limit" min="0"
                                       class="mt-1 block w-full border-gray-300 rounded-md shadow-sm focus:ring-blue-500 focus:border-blue-500 sm:text-sm"
                                       placeholder="0表示无限制">
                                <p class="mt-1 text-xs text-gray-500">0表示无限制，大于0表示每日最大调用次数</p>
                            </div>

                            <div id="statusField" class="hidden">
                                <label for="status" class="block text-sm font-medium text-gray-700">状态</label>
                                <select name="status" id="status"
                                        class="mt-1 block w-full border-gray-300 rounded-md shadow-sm focus:ring-blue-500 focus:border-blue-500 sm:text-sm">
                                    <option value="active">活跃</option>
                                    <option value="inactive">禁用</option>
                                </select>
                            </div>
                        </div>

                        <div class="flex justify-end space-x-3 pt-4">
                            <button type="button" onclick="closeModelModal()"
                                    class="px-4 py-2 border border-gray-300 rounded-md text-sm font-medium text-gray-700 bg-white hover:bg-gray-50">
                                取消
                            </button>
                            <button type="submit"
                                    class="px-4 py-2 border border-transparent rounded-md shadow-sm text-sm font-medium text-white bg-blue-600 hover:bg-blue-700">
                                保存
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    <script>
        // 显示创建模型模态框
        function showCreateModelModal() {
            document.getElementById('modalTitle').textContent = '新增AI模型';
            document.getElementById('formAction').value = 'create_model';
            document.getElementById('modelId').value = '';
            document.getElementById('statusField').classList.add('hidden');
            document.getElementById('modelForm').reset();
            document.getElementById('model_type').value = 'chat';
            document.getElementById('api_key').required = true;
            document.getElementById('api_key').placeholder = '输入API密钥';
            document.getElementById('apiKeyHelp').textContent = '创建模型时必填。';
            document.getElementById('api_url').value = 'https://api.tu-zi.com';
            document.getElementById('modelModal').classList.remove('hidden');
        }

        // 编辑模型
        function editModel(model) {
            document.getElementById('modalTitle').textContent = '编辑AI模型';
            document.getElementById('formAction').value = 'update_model';
            document.getElementById('modelId').value = model.id;
            document.getElementById('name').value = model.name;
            document.getElementById('version').value = model.version || '';
            document.getElementById('model_id').value = model.model_id;
            document.getElementById('model_type').value = model.model_type || 'chat';
            document.getElementById('api_key').value = '';
            document.getElementById('api_key').required = false;
            document.getElementById('api_key').placeholder = '留空则保留当前API密钥';
            document.getElementById('apiKeyHelp').textContent = '编辑时留空即可保留现有密钥；仅在需要轮换密钥时填写新值。';
            document.getElementById('api_url').value = model.api_url;
            document.getElementById('daily_limit').value = model.daily_limit;
            document.getElementById('status').value = model.status;
            document.getElementById('statusField').classList.remove('hidden');
            document.getElementById('modelModal').classList.remove('hidden');
        }

        // 关闭模态框
        function closeModelModal() {
            document.getElementById('modelModal').classList.add('hidden');
        }

        // 服务商预设配置
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

        // 删除模型
        function deleteModel(id, name) {
            if (confirm(`确定要删除模型"${name}"吗？此操作不可恢复。`)) {
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
    </script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
