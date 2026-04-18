<?php
/**
 * 智能GEO内容系统 - AI配置器主页
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

// 设置页面信息
$page_title = __('ai_configurator.page_title');
$page_header = '
<div class="flex items-center justify-between">
    <div>
        <h1 class="text-2xl font-bold text-gray-900">' . __('ai_configurator.heading') . '</h1>
        <p class="mt-1 text-sm text-gray-600">' . __('ai_configurator.subtitle') . '</p>
    </div>
</div>';

require_once __DIR__ . '/includes/header.php';
?>

        <!-- 配置模块导航 -->
        <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6 mb-8">
            <!-- AI模型配置 -->
            <div class="bg-white overflow-hidden shadow rounded-lg">
                <div class="p-6">
                    <div class="flex items-center">
                        <div class="flex-shrink-0">
                            <div class="w-8 h-8 bg-blue-500 rounded-md flex items-center justify-center">
                                <i data-lucide="cpu" class="w-5 h-5 text-white"></i>
                            </div>
                        </div>
                        <div class="ml-5 w-0 flex-1">
                            <dl>
                                <dt class="text-sm font-medium text-gray-500 truncate"><?php echo __('ai_configurator.models_title'); ?></dt>
                                <dd class="text-lg font-medium text-gray-900"><?php echo __('ai_configurator.models_desc'); ?></dd>
                            </dl>
                        </div>
                    </div>
                </div>
                <div class="bg-gray-50 px-6 py-3">
                    <div class="text-sm">
                        <a href="ai-models.php" class="font-medium text-blue-600 hover:text-blue-500">
                            <?php echo __('ai_configurator.models_action'); ?> <span aria-hidden="true">&rarr;</span>
                        </a>
                    </div>
                </div>
            </div>

            <!-- 提示词配置 -->
            <div class="bg-white overflow-hidden shadow rounded-lg">
                <div class="p-6">
                    <div class="flex items-center">
                        <div class="flex-shrink-0">
                            <div class="w-8 h-8 bg-green-500 rounded-md flex items-center justify-center">
                                <i data-lucide="message-square" class="w-5 h-5 text-white"></i>
                            </div>
                        </div>
                        <div class="ml-5 w-0 flex-1">
                            <dl>
                                <dt class="text-sm font-medium text-gray-500 truncate"><?php echo __('ai_configurator.prompts_title'); ?></dt>
                                <dd class="text-lg font-medium text-gray-900"><?php echo __('ai_configurator.prompts_desc'); ?></dd>
                            </dl>
                        </div>
                    </div>
                </div>
                <div class="bg-gray-50 px-6 py-3">
                    <div class="text-sm">
                        <a href="ai-prompts.php" class="font-medium text-green-600 hover:text-green-500">
                            <?php echo __('ai_configurator.prompts_action'); ?> <span aria-hidden="true">&rarr;</span>
                        </a>
                    </div>
                </div>
            </div>

            <!-- 其他提示词 -->
            <div class="bg-white overflow-hidden shadow rounded-lg">
                <div class="p-6">
                    <div class="flex items-center">
                        <div class="flex-shrink-0">
                            <div class="w-8 h-8 bg-purple-500 rounded-md flex items-center justify-center">
                                <i data-lucide="settings" class="w-5 h-5 text-white"></i>
                            </div>
                        </div>
                        <div class="ml-5 w-0 flex-1">
                            <dl>
                                <dt class="text-sm font-medium text-gray-500 truncate"><?php echo __('ai_configurator.special_title'); ?></dt>
                                <dd class="text-lg font-medium text-gray-900"><?php echo __('ai_configurator.special_desc'); ?></dd>
                            </dl>
                        </div>
                    </div>
                </div>
                <div class="bg-gray-50 px-6 py-3">
                    <div class="text-sm">
                        <a href="ai-special-prompts.php" class="font-medium text-purple-600 hover:text-purple-500">
                            <?php echo __('ai_configurator.special_action'); ?> <span aria-hidden="true">&rarr;</span>
                        </a>
                    </div>
                </div>
            </div>
        </div>

        <!-- 快速统计 -->
        <div class="bg-white shadow rounded-lg">
            <div class="px-6 py-4 border-b border-gray-200">
                <h3 class="text-lg font-medium text-gray-900"><?php echo __('ai_configurator.overview'); ?></h3>
            </div>
            <div class="px-6 py-6">
                <div class="grid grid-cols-1 md:grid-cols-4 gap-6">
                    <?php
                    // 获取统计数据
                    try {
                        $model_count = $db->query("SELECT COUNT(*) FROM ai_models WHERE status = 'active'")->fetchColumn();
                        $prompt_count = $db->query("SELECT COUNT(*) FROM prompts")->fetchColumn();
                        $total_usage = $db->query("SELECT SUM(total_used) FROM ai_models")->fetchColumn() ?: 0;
                        $today_usage = $db->query("SELECT SUM(used_today) FROM ai_models")->fetchColumn() ?: 0;
                    } catch (Exception $e) {
                        error_log('AI configurator stats query failed: ' . $e->getMessage());
                        $model_count = 0;
                        $prompt_count = 0;
                        $total_usage = 0;
                        $today_usage = 0;
                    }
                    ?>
                    
                    <div class="text-center">
                        <div class="text-2xl font-bold text-blue-600"><?php echo $model_count; ?></div>
                        <div class="text-sm text-gray-500"><?php echo __('ai_configurator.active_models'); ?></div>
                    </div>
                    
                    <div class="text-center">
                        <div class="text-2xl font-bold text-green-600"><?php echo $prompt_count; ?></div>
                        <div class="text-sm text-gray-500"><?php echo __('ai_configurator.prompt_templates'); ?></div>
                    </div>
                    
                    <div class="text-center">
                        <div class="text-2xl font-bold text-purple-600"><?php echo number_format($total_usage); ?></div>
                        <div class="text-sm text-gray-500"><?php echo __('ai_configurator.total_calls'); ?></div>
                    </div>
                    
                    <div class="text-center">
                        <div class="text-2xl font-bold text-orange-600"><?php echo number_format($today_usage); ?></div>
                        <div class="text-sm text-gray-500"><?php echo __('ai_configurator.today_calls'); ?></div>
                    </div>
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
                    <h3 class="text-sm font-medium text-blue-800"><?php echo __('ai_configurator.help_title'); ?></h3>
                    <div class="mt-2 text-sm text-blue-700">
                        <ul class="list-disc list-inside space-y-1">
                            <li><?php echo __('ai_configurator.help_models'); ?></li>
                            <li><?php echo __('ai_configurator.help_content_prompts'); ?></li>
                            <li><?php echo __('ai_configurator.help_special_prompts'); ?></li>
                            <li><?php echo __('ai_configurator.help_pipeline'); ?></li>
                        </ul>
                    </div>
                </div>
            </div>
        </div>
	    <script>
	        // 初始化Lucide图标
	        document.addEventListener('DOMContentLoaded', function() {
            if (typeof lucide !== 'undefined') {
                lucide.createIcons();
            }
        });
    </script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
