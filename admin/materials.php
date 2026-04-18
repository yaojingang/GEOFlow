<?php
/**
 * 智能GEO内容系统 - 素材管理
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

// 检查管理员登录
require_admin_login();

// 立即释放session锁，允许其他页面并发访问
session_write_close();

// 获取统计数据
$stats = [
    'keyword_libraries' => $db->query("SELECT COUNT(*) as count FROM keyword_libraries")->fetch()['count'] ?? 0,
    'total_keywords' => $db->query("SELECT COUNT(*) as total FROM keywords")->fetch()['total'] ?? 0,
    'title_libraries' => $db->query("SELECT COUNT(*) as count FROM title_libraries")->fetch()['count'] ?? 0,
    'total_titles' => $db->query("SELECT COUNT(*) as total FROM titles")->fetch()['total'] ?? 0,
    'image_libraries' => $db->query("SELECT COUNT(*) as count FROM image_libraries")->fetch()['count'] ?? 0,
    'total_images' => $db->query("SELECT COUNT(*) as total FROM images")->fetch()['total'] ?? 0,
    'knowledge_bases' => $db->query("SELECT COUNT(*) as count FROM knowledge_bases")->fetch()['count'] ?? 0,
    'authors' => $db->query("SELECT COUNT(*) as count FROM authors")->fetch()['count'] ?? 0
];

// 设置页面信息
$page_title = __('materials.page_title');
$page_header = '
<div class="flex items-center justify-between">
    <div>
        <h1 class="text-2xl font-bold text-gray-900">' . __('materials.heading') . '</h1>
        <p class="mt-1 text-sm text-gray-600">' . __('materials.subtitle') . '</p>
    </div>
    <div class="flex space-x-3">
        <a href="authors.php" class="inline-flex items-center px-4 py-2 border border-transparent text-sm font-medium rounded-md text-white bg-indigo-600 hover:bg-indigo-700">
            <i data-lucide="users" class="w-4 h-4 mr-2"></i>
            ' . __('materials.author_manage') . '
        </a>
    </div>
</div>
';

// 包含头部模块
require_once __DIR__ . '/includes/header.php';
?>

        <!-- 统计卡片 -->
        <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-6 mb-8">
            <div class="bg-white overflow-hidden shadow rounded-lg">
                <div class="p-5">
                    <div class="flex items-center">
                        <div class="flex-shrink-0">
                            <i data-lucide="key" class="h-6 w-6 text-blue-600"></i>
                        </div>
                        <div class="ml-5 w-0 flex-1">
                            <dl>
                                <dt class="text-sm font-medium text-gray-500 truncate"><?php echo __('materials.keyword_libraries'); ?></dt>
                                <dd class="text-lg font-medium text-gray-900"><?php echo __('materials.library_count', ['count' => $stats['keyword_libraries']]); ?></dd>
                                <dd class="text-sm text-gray-500"><?php echo __('materials.keyword_count', ['count' => $stats['total_keywords']]); ?></dd>
                            </dl>
                        </div>
                    </div>
                </div>
            </div>

            <div class="bg-white overflow-hidden shadow rounded-lg">
                <div class="p-5">
                    <div class="flex items-center">
                        <div class="flex-shrink-0">
                            <i data-lucide="type" class="h-6 w-6 text-green-600"></i>
                        </div>
                        <div class="ml-5 w-0 flex-1">
                            <dl>
                                <dt class="text-sm font-medium text-gray-500 truncate"><?php echo __('materials.title_libraries'); ?></dt>
                                <dd class="text-lg font-medium text-gray-900"><?php echo __('materials.library_count', ['count' => $stats['title_libraries']]); ?></dd>
                                <dd class="text-sm text-gray-500"><?php echo __('materials.title_count', ['count' => $stats['total_titles']]); ?></dd>
                            </dl>
                        </div>
                    </div>
                </div>
            </div>

            <div class="bg-white overflow-hidden shadow rounded-lg">
                <div class="p-5">
                    <div class="flex items-center">
                        <div class="flex-shrink-0">
                            <i data-lucide="image" class="h-6 w-6 text-purple-600"></i>
                        </div>
                        <div class="ml-5 w-0 flex-1">
                            <dl>
                                <dt class="text-sm font-medium text-gray-500 truncate"><?php echo __('materials.image_libraries'); ?></dt>
                                <dd class="text-lg font-medium text-gray-900"><?php echo __('materials.library_count', ['count' => $stats['image_libraries']]); ?></dd>
                                <dd class="text-sm text-gray-500"><?php echo __('materials.image_count', ['count' => $stats['total_images']]); ?></dd>
                            </dl>
                        </div>
                    </div>
                </div>
            </div>

            <div class="bg-white overflow-hidden shadow rounded-lg">
                <div class="p-5">
                    <div class="flex items-center">
                        <div class="flex-shrink-0">
                            <i data-lucide="brain" class="h-6 w-6 text-orange-600"></i>
                        </div>
                        <div class="ml-5 w-0 flex-1">
                            <dl>
                                <dt class="text-sm font-medium text-gray-500 truncate"><?php echo __('materials.knowledge_bases'); ?></dt>
                                <dd class="text-lg font-medium text-gray-900"><?php echo __('materials.library_count', ['count' => $stats['knowledge_bases']]); ?></dd>
                                <dd class="text-sm text-gray-500"><?php echo __('materials.author_count', ['count' => $stats['authors']]); ?></dd>
                            </dl>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <?php $url_import_csrf = generate_csrf_token(); ?>

        <!-- 素材库管理 -->
        <div class="grid grid-cols-1 md:grid-cols-2 gap-6 mb-8">
            <!-- 关键词库 -->
            <div class="bg-white shadow rounded-lg">
                <div class="px-6 py-4 border-b border-gray-200">
                    <div class="flex items-center justify-between">
                        <h3 class="text-lg font-medium text-gray-900 flex items-center">
                            <i data-lucide="key" class="w-5 h-5 text-blue-600 mr-2"></i>
                            <?php echo __('materials.keyword_manage_title'); ?>
                        </h3>
                        <a href="keyword-libraries.php" class="text-sm text-blue-600 hover:text-blue-800"><?php echo __('materials.view_all'); ?></a>
                    </div>
                </div>
                <div class="px-6 py-6">
                    <p class="text-gray-600 mb-4"><?php echo __('materials.keywords_summary'); ?></p>
                    <div class="space-y-3">
                        <div class="flex items-center justify-between">
                            <span class="text-sm text-gray-500"><?php echo __('materials.keyword_library_count'); ?></span>
                            <span class="text-sm font-medium"><?php echo __('materials.unit_libraries', ['count' => $stats['keyword_libraries']]); ?></span>
                        </div>
                        <div class="flex items-center justify-between">
                            <span class="text-sm text-gray-500"><?php echo __('materials.keyword_total_count'); ?></span>
                            <span class="text-sm font-medium"><?php echo __('materials.unit_items', ['count' => $stats['total_keywords']]); ?></span>
                        </div>
                    </div>
                    <div class="mt-4">
                        <a href="keyword-libraries.php" class="inline-flex items-center px-4 py-2 border border-transparent text-sm font-medium rounded-md text-white bg-blue-600 hover:bg-blue-700">
                            <i data-lucide="settings" class="w-4 h-4 mr-2"></i>
                            <?php echo __('materials.manage_keyword_libraries'); ?>
                        </a>
                    </div>
                </div>
            </div>

            <!-- 标题库 -->
            <div class="bg-white shadow rounded-lg">
                <div class="px-6 py-4 border-b border-gray-200">
                    <div class="flex items-center justify-between">
                        <h3 class="text-lg font-medium text-gray-900 flex items-center">
                            <i data-lucide="type" class="w-5 h-5 text-green-600 mr-2"></i>
                            <?php echo __('materials.title_manage_title'); ?>
                        </h3>
                        <a href="title-libraries.php" class="text-sm text-green-600 hover:text-green-800"><?php echo __('materials.view_all'); ?></a>
                    </div>
                </div>
                <div class="px-6 py-6">
                    <p class="text-gray-600 mb-4"><?php echo __('materials.titles_summary'); ?></p>
                    <div class="space-y-3">
                        <div class="flex items-center justify-between">
                            <span class="text-sm text-gray-500"><?php echo __('materials.title_library_count'); ?></span>
                            <span class="text-sm font-medium"><?php echo __('materials.unit_libraries', ['count' => $stats['title_libraries']]); ?></span>
                        </div>
                        <div class="flex items-center justify-between">
                            <span class="text-sm text-gray-500"><?php echo __('materials.title_total_count'); ?></span>
                            <span class="text-sm font-medium"><?php echo __('materials.unit_items', ['count' => $stats['total_titles']]); ?></span>
                        </div>
                    </div>
                    <div class="mt-4">
                        <a href="title-libraries.php" class="inline-flex items-center px-4 py-2 border border-transparent text-sm font-medium rounded-md text-white bg-green-600 hover:bg-green-700">
                            <i data-lucide="settings" class="w-4 h-4 mr-2"></i>
                            <?php echo __('materials.manage_title_libraries'); ?>
                        </a>
                    </div>
                </div>
            </div>

            <!-- 图片库 -->
            <div class="bg-white shadow rounded-lg">
                <div class="px-6 py-4 border-b border-gray-200">
                    <div class="flex items-center justify-between">
                        <h3 class="text-lg font-medium text-gray-900 flex items-center">
                            <i data-lucide="image" class="w-5 h-5 text-purple-600 mr-2"></i>
                            <?php echo __('materials.image_manage_title'); ?>
                        </h3>
                        <a href="image-libraries.php" class="text-sm text-purple-600 hover:text-purple-800"><?php echo __('materials.view_all'); ?></a>
                    </div>
                </div>
                <div class="px-6 py-6">
                    <p class="text-gray-600 mb-4"><?php echo __('materials.images_summary'); ?></p>
                    <div class="space-y-3">
                        <div class="flex items-center justify-between">
                            <span class="text-sm text-gray-500"><?php echo __('materials.image_library_count'); ?></span>
                            <span class="text-sm font-medium"><?php echo __('materials.unit_libraries', ['count' => $stats['image_libraries']]); ?></span>
                        </div>
                        <div class="flex items-center justify-between">
                            <span class="text-sm text-gray-500"><?php echo __('materials.image_total_count'); ?></span>
                            <span class="text-sm font-medium"><?php echo __('materials.unit_images', ['count' => $stats['total_images']]); ?></span>
                        </div>
                    </div>
                    <div class="mt-4">
                        <a href="image-libraries.php" class="inline-flex items-center px-4 py-2 border border-transparent text-sm font-medium rounded-md text-white bg-purple-600 hover:bg-purple-700">
                            <i data-lucide="settings" class="w-4 h-4 mr-2"></i>
                            <?php echo __('materials.manage_image_libraries'); ?>
                        </a>
                    </div>
                </div>
            </div>

            <!-- AI知识库 -->
            <div class="bg-white shadow rounded-lg">
                <div class="px-6 py-4 border-b border-gray-200">
                    <div class="flex items-center justify-between">
                        <h3 class="text-lg font-medium text-gray-900 flex items-center">
                            <i data-lucide="brain" class="w-5 h-5 text-orange-600 mr-2"></i>
                            <?php echo __('materials.knowledge_manage_title'); ?>
                        </h3>
                        <a href="knowledge-bases.php" class="text-sm text-orange-600 hover:text-orange-800"><?php echo __('materials.view_all'); ?></a>
                    </div>
                </div>
                <div class="px-6 py-6">
                    <p class="text-gray-600 mb-4"><?php echo __('materials.knowledge_summary'); ?></p>
                    <div class="space-y-3">
                        <div class="flex items-center justify-between">
                            <span class="text-sm text-gray-500"><?php echo __('materials.knowledge_base_count'); ?></span>
                            <span class="text-sm font-medium"><?php echo __('materials.unit_libraries', ['count' => $stats['knowledge_bases']]); ?></span>
                        </div>
                        <div class="flex items-center justify-between">
                            <span class="text-sm text-gray-500"><?php echo __('materials.author_total_count'); ?></span>
                            <span class="text-sm font-medium"><?php echo __('materials.author_count', ['count' => $stats['authors']]); ?></span>
                        </div>
                    </div>
                    <div class="mt-4">
                        <a href="knowledge-bases.php" class="inline-flex items-center px-4 py-2 border border-transparent text-sm font-medium rounded-md text-white bg-orange-600 hover:bg-orange-700">
                            <i data-lucide="settings" class="w-4 h-4 mr-2"></i>
                            <?php echo __('materials.manage_knowledge_bases'); ?>
                        </a>
                    </div>
                </div>
            </div>
        </div>

        <!-- 快速操作 -->
        <div class="bg-white shadow rounded-lg">
            <div class="px-6 py-4 border-b border-gray-200">
                <h3 class="text-lg font-medium text-gray-900"><?php echo __('materials.quick_actions'); ?></h3>
            </div>
            <div class="px-6 py-6">
                <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-4">
                    <a href="keyword-libraries.php" class="flex items-center p-4 border border-gray-200 rounded-lg hover:bg-gray-50 transition-colors">
                        <i data-lucide="key" class="w-8 h-8 text-blue-600 mr-3"></i>
                        <div>
                            <h4 class="font-medium text-gray-900"><?php echo __('materials.keyword_libraries'); ?></h4>
                            <p class="text-sm text-gray-500"><?php echo __('materials.manage_keywords_short'); ?></p>
                        </div>
                    </a>
                    
                    <a href="title-libraries.php" class="flex items-center p-4 border border-gray-200 rounded-lg hover:bg-gray-50 transition-colors">
                        <i data-lucide="type" class="w-8 h-8 text-green-600 mr-3"></i>
                        <div>
                            <h4 class="font-medium text-gray-900"><?php echo __('materials.title_libraries'); ?></h4>
                            <p class="text-sm text-gray-500"><?php echo __('materials.manage_titles_short'); ?></p>
                        </div>
                    </a>
                    
                    <a href="image-libraries.php" class="flex items-center p-4 border border-gray-200 rounded-lg hover:bg-gray-50 transition-colors">
                        <i data-lucide="image" class="w-8 h-8 text-purple-600 mr-3"></i>
                        <div>
                            <h4 class="font-medium text-gray-900"><?php echo __('materials.image_libraries'); ?></h4>
                            <p class="text-sm text-gray-500"><?php echo __('materials.manage_images_short'); ?></p>
                        </div>
                    </a>
                    
                    <a href="knowledge-bases.php" class="flex items-center p-4 border border-gray-200 rounded-lg hover:bg-gray-50 transition-colors">
                        <i data-lucide="brain" class="w-8 h-8 text-orange-600 mr-3"></i>
                        <div>
                            <h4 class="font-medium text-gray-900"><?php echo __('materials.knowledge_bases'); ?></h4>
                            <p class="text-sm text-gray-500"><?php echo __('materials.manage_knowledge_short'); ?></p>
                        </div>
                    </a>

                    <a href="url-import.php" class="flex items-center p-4 border border-cyan-200 rounded-lg bg-cyan-50 hover:bg-cyan-100 transition-colors">
                        <i data-lucide="globe" class="w-8 h-8 text-cyan-600 mr-3"></i>
                        <div>
                            <div class="flex items-center gap-2">
                                <h4 class="font-medium text-gray-900"><?php echo __('materials.url_import'); ?></h4>
                                <span class="inline-flex items-center px-2 py-0.5 rounded-full bg-amber-100 text-amber-700 text-xs font-medium"><?php echo __('materials.url_import_iterating'); ?></span>
                            </div>
                            <p class="text-sm text-gray-500"><?php echo __('materials.url_import_short'); ?></p>
                        </div>
                    </a>

                    <a href="url-import-history.php" class="flex items-center p-4 border border-slate-200 rounded-lg hover:bg-gray-50 transition-colors">
                        <i data-lucide="history" class="w-8 h-8 text-slate-600 mr-3"></i>
                        <div>
                            <h4 class="font-medium text-gray-900"><?php echo __('materials.url_import_history'); ?></h4>
                            <p class="text-sm text-gray-500"><?php echo __('materials.url_import_history_short'); ?></p>
                        </div>
                    </a>
                </div>
            </div>
        </div>

        <!-- URL智能采集 -->
        <div class="bg-white shadow rounded-lg overflow-hidden mt-8">
            <div class="px-6 py-5 border-b border-gray-200">
                <div>
                    <div class="flex flex-wrap items-center gap-3 mb-4">
                        <div class="inline-flex items-center px-3 py-1 rounded-full bg-cyan-50 text-cyan-700 text-sm font-medium">
                            <i data-lucide="sparkles" class="w-4 h-4 mr-2"></i>
                            <?php echo __('materials.url_import'); ?>
                        </div>
                        <div class="inline-flex items-center px-3 py-1 rounded-full bg-amber-100 text-amber-800 text-sm font-medium">
                            <i data-lucide="alert-triangle" class="w-4 h-4 mr-2"></i>
                            <?php echo __('materials.url_import_warning'); ?>
                        </div>
                    </div>
                    <h2 class="text-2xl font-bold text-gray-900"><?php echo __('materials.url_import_title'); ?></h2>
                    <p class="mt-3 text-sm md:text-base text-gray-600 leading-7">
                        <?php echo __('materials.url_import_description'); ?>
                    </p>
                    <div class="mt-4 rounded-lg border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-900">
                        <?php echo __('materials.url_import_caution'); ?>
                    </div>
                    <form id="url-import-form" class="mt-6">
                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($url_import_csrf); ?>">
                        <div class="flex flex-col xl:flex-row gap-3 w-full">
                            <div class="flex-1">
                                <label for="url-import-input" class="sr-only"><?php echo __('materials.url_import_target_label'); ?></label>
                                <input
                                    id="url-import-input"
                                    name="url"
                                    type="url"
                                    placeholder="<?php echo htmlspecialchars(__('materials.url_import_placeholder')); ?>"
                                    class="block w-full rounded-xl border border-blue-200 bg-blue-50/40 px-5 py-4 text-base text-gray-900 shadow-sm placeholder:text-gray-400 focus:border-blue-500 focus:ring-2 focus:ring-blue-200"
                                >
                            </div>
                            <button type="submit" id="url-import-submit" class="inline-flex items-center justify-center px-5 py-4 rounded-xl text-sm font-medium text-white bg-blue-600 hover:bg-blue-700 whitespace-nowrap xl:min-w-[192px]">
                                <i data-lucide="globe" class="w-4 h-4 mr-2"></i>
                                <?php echo __('materials.url_import_start'); ?>
                            </button>
                        </div>
                        <div class="mt-3 flex flex-wrap gap-4 text-sm text-gray-600">
                            <label class="inline-flex items-center">
                                <input type="checkbox" name="import_knowledge" checked class="rounded border-gray-300 text-blue-600 focus:ring-blue-500">
                                <span class="ml-2"><?php echo __('materials.url_import_option_knowledge'); ?></span>
                            </label>
                            <label class="inline-flex items-center">
                                <input type="checkbox" name="import_keywords" checked class="rounded border-gray-300 text-blue-600 focus:ring-blue-500">
                                <span class="ml-2"><?php echo __('materials.url_import_option_keywords'); ?></span>
                            </label>
                            <label class="inline-flex items-center">
                                <input type="checkbox" name="import_titles" checked class="rounded border-gray-300 text-blue-600 focus:ring-blue-500">
                                <span class="ml-2"><?php echo __('materials.url_import_option_titles'); ?></span>
                            </label>
                            <label class="inline-flex items-center">
                                <input type="checkbox" name="import_images" checked class="rounded border-gray-300 text-blue-600 focus:ring-blue-500">
                                <span class="ml-2"><?php echo __('materials.url_import_option_images'); ?></span>
                            </label>
                            <label class="inline-flex items-center">
                                <input type="checkbox" name="enable_ai_cleaning" checked class="rounded border-gray-300 text-blue-600 focus:ring-blue-500">
                                <span class="ml-2"><?php echo __('materials.url_import_option_ai_cleaning'); ?></span>
                            </label>
                            <label class="inline-flex items-center">
                                <input type="checkbox" name="enable_semantic_analysis" checked class="rounded border-gray-300 text-blue-600 focus:ring-blue-500">
                                <span class="ml-2"><?php echo __('materials.url_import_option_semantic'); ?></span>
                            </label>
                        </div>
                        <div id="url-import-inline-error" class="hidden mt-3 text-sm text-red-600"></div>
                    </form>
                </div>
            </div>
            <div class="px-6 py-6">
                <div class="grid grid-cols-1 lg:grid-cols-3 gap-4">
                    <div class="rounded-lg border border-gray-200 bg-gray-50 px-4 py-4">
                        <div class="text-xs font-semibold uppercase tracking-wide text-gray-500"><?php echo __('materials.url_import_flow_label'); ?></div>
                        <div class="mt-2 text-sm font-medium text-gray-900"><?php echo __('materials.url_import_flow_title'); ?></div>
                        <p class="mt-2 text-xs text-gray-500"><?php echo __('materials.url_import_flow_desc'); ?></p>
                    </div>
                    <div class="rounded-lg border border-gray-200 bg-gray-50 px-4 py-4">
                        <div class="text-xs font-semibold uppercase tracking-wide text-gray-500"><?php echo __('materials.url_import_assets_label'); ?></div>
                        <div class="mt-2 text-sm font-medium text-gray-900"><?php echo __('materials.url_import_assets_title'); ?></div>
                        <p class="mt-2 text-xs text-gray-500"><?php echo __('materials.url_import_assets_desc'); ?></p>
                    </div>
                    <div class="rounded-lg border border-gray-200 bg-gray-50 px-4 py-4">
                        <div class="text-xs font-semibold uppercase tracking-wide text-gray-500"><?php echo __('materials.url_import_stage_label'); ?></div>
                        <div class="mt-2 text-sm font-medium text-gray-900"><?php echo __('materials.url_import_stage_title'); ?></div>
                        <p class="mt-2 text-xs text-gray-500"><?php echo __('materials.url_import_stage_desc'); ?></p>
                    </div>
                </div>
                <div class="mt-6 rounded-lg border border-blue-100 bg-blue-50 px-4 py-4">
                    <div class="text-sm font-medium text-gray-900"><?php echo __('materials.url_import_upcoming_title'); ?></div>
                    <ol class="mt-3 grid grid-cols-1 lg:grid-cols-3 gap-3 text-sm text-gray-600">
                        <li class="flex items-start">
                            <span class="w-6 h-6 rounded-full bg-blue-600 text-white text-xs font-semibold flex items-center justify-center mr-3 mt-0.5">1</span>
                            <?php echo __('materials.url_import_upcoming_step_1'); ?>
                        </li>
                        <li class="flex items-start">
                            <span class="w-6 h-6 rounded-full bg-blue-600 text-white text-xs font-semibold flex items-center justify-center mr-3 mt-0.5">2</span>
                            <?php echo __('materials.url_import_upcoming_step_2'); ?>
                        </li>
                        <li class="flex items-start">
                            <span class="w-6 h-6 rounded-full bg-blue-600 text-white text-xs font-semibold flex items-center justify-center mr-3 mt-0.5">3</span>
                            <?php echo __('materials.url_import_upcoming_step_3'); ?>
                        </li>
                    </ol>
                </div>

                <div id="url-import-progress-panel" class="hidden mt-6 border border-blue-200 bg-blue-50 rounded-lg overflow-hidden">
                    <div class="px-4 py-4 border-b border-blue-100">
                        <div class="flex flex-col lg:flex-row lg:items-center lg:justify-between gap-3">
                            <div>
                                <div class="text-sm font-medium text-gray-900"><?php echo __('materials.url_import_progress_title'); ?></div>
                                <div id="url-import-current-step" class="mt-1 text-sm text-gray-600"><?php echo __('materials.url_import_waiting_start'); ?></div>
                            </div>
                            <div class="text-sm text-blue-700 font-medium" id="url-import-progress-text">0%</div>
                        </div>
                        <div class="mt-3 h-2.5 bg-white rounded-full overflow-hidden">
                            <div id="url-import-progress-bar" class="h-full w-0 bg-blue-600 rounded-full transition-all duration-500"></div>
                        </div>
                    </div>
                    <div class="px-4 py-4">
                        <div class="grid grid-cols-1 xl:grid-cols-2 gap-4">
                            <div class="rounded-lg border border-gray-200 bg-white p-4">
                                <div class="text-sm font-medium text-gray-900"><?php echo __('materials.url_import_stage_status_title'); ?></div>
                                <div id="url-import-stage-list" class="mt-3 space-y-2 text-sm text-gray-600"></div>
                            </div>
                            <div class="rounded-lg border border-gray-200 bg-slate-950 p-4">
                                <div class="flex items-center justify-between">
                                    <div class="text-sm font-medium text-white"><?php echo __('materials.url_import_log_title'); ?></div>
                                    <div id="url-import-log-status" class="text-xs text-slate-400"><?php echo __('materials.url_import_waiting_log_status'); ?></div>
                                </div>
                                <div id="url-import-log-box" class="mt-3 h-56 overflow-y-auto rounded-md bg-slate-900 border border-slate-800 p-3 font-mono text-xs leading-6 text-slate-200"></div>
                            </div>
                        </div>
                    </div>
                </div>

                <div id="url-import-result-panel" class="hidden mt-6 rounded-lg border border-emerald-200 bg-emerald-50 overflow-hidden">
                    <div class="px-4 py-4 border-b border-emerald-100">
                        <div class="text-sm font-medium text-gray-900"><?php echo __('materials.url_import_preview_title'); ?></div>
                        <div class="mt-1 text-sm text-gray-600"><?php echo __('materials.url_import_preview_subtitle'); ?></div>
                    </div>
                    <div class="px-4 py-4 grid grid-cols-1 xl:grid-cols-2 gap-4">
                        <div class="rounded-lg border border-emerald-100 bg-white p-4">
                            <div class="text-sm font-medium text-gray-900"><?php echo __('materials.url_import_knowledge_preview_title'); ?></div>
                            <div id="url-import-summary" class="mt-3 text-sm text-gray-600 leading-7"></div>
                            <div id="url-import-knowledge-preview" class="mt-3 text-sm text-gray-700 leading-7"></div>
                        </div>
                        <div class="rounded-lg border border-emerald-100 bg-white p-4">
                            <div class="text-sm font-medium text-gray-900"><?php echo __('materials.url_import_result_title'); ?></div>
                            <div class="mt-3">
                                <div class="text-xs uppercase tracking-wide text-gray-500"><?php echo __('materials.url_import_keywords_label'); ?></div>
                                <div id="url-import-keywords" class="mt-2 flex flex-wrap gap-2"></div>
                            </div>
                            <div class="mt-4">
                                <div class="text-xs uppercase tracking-wide text-gray-500"><?php echo __('materials.url_import_titles_label'); ?></div>
                                <ul id="url-import-titles" class="mt-2 space-y-2 text-sm text-gray-700"></ul>
                            </div>
                            <div class="mt-4">
                                <div class="text-xs uppercase tracking-wide text-gray-500"><?php echo __('materials.url_import_images_label'); ?></div>
                                <ul id="url-import-images" class="mt-2 space-y-2 text-sm text-gray-700"></ul>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

<?php
// 包含底部模块
require_once __DIR__ . '/includes/footer.php';
?>
<script>
    (function () {
        const i18n = <?php echo json_encode([
            'stageCompleted' => __('materials.url_import_stage_completed'),
            'stageInProgress' => __('materials.url_import_stage_in_progress'),
            'stagePending' => __('materials.url_import_stage_pending'),
            'stepFetch' => __('materials.url_import_step_fetch'),
            'stepExtract' => __('materials.url_import_step_extract'),
            'stepImages' => __('materials.url_import_step_images'),
            'stepAiClean' => __('materials.url_import_step_ai_clean'),
            'stepKeywords' => __('materials.url_import_step_keywords'),
            'stepTitles' => __('materials.url_import_step_titles'),
            'stepKnowledge' => __('materials.url_import_step_knowledge'),
            'stepCompleted' => __('materials.url_import_step_completed'),
            'waitingLogs' => __('materials.url_import_waiting_logs'),
            'imagePending' => __('materials.url_import_image_pending'),
            'statusFetchFailed' => __('materials.url_import_status_fetch_failed'),
            'processingDone' => __('materials.url_import_processing_done'),
            'processing' => __('materials.url_import_processing'),
            'startLabel' => __('materials.url_import_start'),
            'taskFailed' => __('materials.url_import_task_failed'),
            'urlRequired' => __('materials.url_import_error_required'),
            'processingLabel' => __('materials.url_import_processing_button'),
            'taskCreateFailed' => __('materials.url_import_task_create_failed'),
            'taskStarted' => __('materials.url_import_task_started'),
        ], JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP); ?>;
        const form = document.getElementById('url-import-form');
        const input = document.getElementById('url-import-input');
        const submitButton = document.getElementById('url-import-submit');
        const errorBox = document.getElementById('url-import-inline-error');
        const progressPanel = document.getElementById('url-import-progress-panel');
        const resultPanel = document.getElementById('url-import-result-panel');
        const progressText = document.getElementById('url-import-progress-text');
        const progressBar = document.getElementById('url-import-progress-bar');
        const currentStepText = document.getElementById('url-import-current-step');
        const stageList = document.getElementById('url-import-stage-list');
        const logBox = document.getElementById('url-import-log-box');
        const logStatus = document.getElementById('url-import-log-status');
        const summaryBox = document.getElementById('url-import-summary');
        const knowledgePreviewBox = document.getElementById('url-import-knowledge-preview');
        const keywordsBox = document.getElementById('url-import-keywords');
        const titlesBox = document.getElementById('url-import-titles');
        const imagesBox = document.getElementById('url-import-images');

        const orderedSteps = ['fetch', 'extract', 'images', 'ai_clean', 'keywords', 'titles', 'knowledge', 'completed'];
        const localizedStepLabels = {
            fetch: i18n.stepFetch,
            extract: i18n.stepExtract,
            images: i18n.stepImages,
            ai_clean: i18n.stepAiClean,
            keywords: i18n.stepKeywords,
            titles: i18n.stepTitles,
            knowledge: i18n.stepKnowledge,
            completed: i18n.stepCompleted,
        };
        let pollingTimer = null;
        let activeJobId = null;

        function escapeHtml(value) {
            return String(value ?? '')
                .replace(/&/g, '&amp;')
                .replace(/</g, '&lt;')
                .replace(/>/g, '&gt;')
                .replace(/"/g, '&quot;')
                .replace(/'/g, '&#039;');
        }

        function setError(message) {
            if (!message) {
                errorBox.classList.add('hidden');
                errorBox.textContent = '';
                return;
            }

            errorBox.textContent = message;
            errorBox.classList.remove('hidden');
        }

        function renderStageList(stepLabels, currentStep, status) {
            const currentIndex = orderedSteps.indexOf(currentStep);
            stageList.innerHTML = orderedSteps.map((step, index) => {
                let stateClass = 'bg-gray-100 text-gray-500 border-gray-200';
                let dotClass = 'bg-gray-300';

                if (status === 'completed' || index < currentIndex) {
                    stateClass = 'bg-emerald-50 text-emerald-700 border-emerald-200';
                    dotClass = 'bg-emerald-500';
                } else if (step === currentStep) {
                    stateClass = 'bg-blue-50 text-blue-700 border-blue-200';
                    dotClass = 'bg-blue-500';
                }

                if (status === 'failed' && step === currentStep) {
                    stateClass = 'bg-red-50 text-red-700 border-red-200';
                    dotClass = 'bg-red-500';
                }

                return `
                    <div class="flex items-center justify-between rounded-md border px-3 py-2 ${stateClass}">
                        <div class="flex items-center">
                            <span class="w-2.5 h-2.5 rounded-full mr-3 ${dotClass}"></span>
                            <span>${escapeHtml(localizedStepLabels[step] || stepLabels[step]?.label || step)}</span>
                        </div>
                        <span class="text-xs">${index < currentIndex || status === 'completed' ? i18n.stageCompleted : (step === currentStep ? i18n.stageInProgress : i18n.stagePending)}</span>
                    </div>
                `;
            }).join('');
        }

        function renderLogs(logs) {
            if (!Array.isArray(logs) || logs.length === 0) {
                logBox.innerHTML = `<div class="text-slate-500">${escapeHtml(i18n.waitingLogs)}</div>`;
                return;
            }

            logBox.innerHTML = logs.map((log) => {
                return `<div><span class="text-slate-500">[${escapeHtml(log.created_at)}]</span> ${escapeHtml(log.message)}</div>`;
            }).join('');

            logBox.scrollTop = logBox.scrollHeight;
        }

        function renderResult(result) {
            if (!result || typeof result !== 'object') {
                resultPanel.classList.add('hidden');
                return;
            }

            summaryBox.textContent = result.summary || '';

            if (result.knowledge_preview) {
                knowledgePreviewBox.innerHTML = `
                    <div class="font-medium text-gray-900">${escapeHtml(result.knowledge_preview.title || '')}</div>
                    <div class="mt-2">${escapeHtml(result.knowledge_preview.content || '')}</div>
                `;
            } else {
                knowledgePreviewBox.textContent = '';
            }

            keywordsBox.innerHTML = Array.isArray(result.keywords)
                ? result.keywords.map((keyword) => `<span class="inline-flex items-center rounded-full bg-emerald-100 px-3 py-1 text-xs font-medium text-emerald-800">${escapeHtml(keyword)}</span>`).join('')
                : '';

            titlesBox.innerHTML = Array.isArray(result.titles)
                ? result.titles.map((title) => `<li class="rounded-md border border-emerald-100 bg-emerald-50 px-3 py-2">${escapeHtml(title)}</li>`).join('')
                : '';

            imagesBox.innerHTML = Array.isArray(result.images)
                ? result.images.map((image) => `<li class="rounded-md border border-emerald-100 bg-emerald-50 px-3 py-2">${escapeHtml(image.label || i18n.imagePending)}</li>`).join('')
                : '';

            resultPanel.classList.remove('hidden');
        }

        async function pollStatus(jobId) {
            try {
                const response = await fetch(`${window.adminUrl('url-import-status.php')}?job_id=${jobId}`, {
                    headers: {
                        'Accept': 'application/json'
                    }
                });
                const data = await response.json();

                if (!data.success) {
                    throw new Error(data.message || i18n.statusFetchFailed);
                }

                progressPanel.classList.remove('hidden');
                progressText.textContent = `${data.job.progress_percent}%`;
                progressBar.style.width = `${data.job.progress_percent}%`;
                currentStepText.textContent = localizedStepLabels[data.job.current_step] || data.step_labels[data.job.current_step]?.label || data.job.current_step;
                logStatus.textContent = data.job.status === 'completed' ? i18n.processingDone : i18n.processing;
                renderStageList(data.step_labels, data.job.current_step, data.job.status);
                renderLogs(data.logs);

                if (data.job.status === 'completed') {
                    submitButton.disabled = false;
                    submitButton.classList.remove('opacity-60', 'cursor-not-allowed');
                    submitButton.innerHTML = `<i data-lucide="globe" class="w-4 h-4 mr-2"></i>${i18n.startLabel}`;
                    if (typeof lucide !== 'undefined') {
                        lucide.createIcons();
                    }
                    renderResult(data.result);
                    clearInterval(pollingTimer);
                    pollingTimer = null;
                    return;
                }

                if (data.job.status === 'failed') {
                    throw new Error(data.job.error_message || i18n.taskFailed);
                }
            } catch (error) {
                clearInterval(pollingTimer);
                pollingTimer = null;
                submitButton.disabled = false;
                submitButton.classList.remove('opacity-60', 'cursor-not-allowed');
                submitButton.innerHTML = `<i data-lucide="globe" class="w-4 h-4 mr-2"></i>${i18n.startLabel}`;
                if (typeof lucide !== 'undefined') {
                    lucide.createIcons();
                }
                setError(error.message || i18n.taskFailed);
                logStatus.textContent = i18n.taskFailed;
            }
        }

        form.addEventListener('submit', async function (event) {
            event.preventDefault();
            setError('');
            resultPanel.classList.add('hidden');

            const url = input.value.trim();
            if (!url) {
                setError(i18n.urlRequired);
                input.focus();
                return;
            }

            const formData = new FormData(form);
            submitButton.disabled = true;
            submitButton.classList.add('opacity-60', 'cursor-not-allowed');
            submitButton.innerHTML = `<i data-lucide="loader-circle" class="w-4 h-4 mr-2 animate-spin"></i>${i18n.processingLabel}`;
            if (typeof lucide !== 'undefined') {
                lucide.createIcons();
            }

            try {
                const response = await fetch(window.adminUrl('url-import-start.php'), {
                    method: 'POST',
                    body: formData,
                    headers: {
                        'Accept': 'application/json'
                    }
                });
                const data = await response.json();

                if (!data.success) {
                    throw new Error(data.message || i18n.taskCreateFailed);
                }

                activeJobId = data.job_id;
                progressPanel.classList.remove('hidden');
                logStatus.textContent = i18n.taskStarted;
                renderLogs([]);
                if (pollingTimer) {
                    clearInterval(pollingTimer);
                }

                await pollStatus(activeJobId);
                if (activeJobId && !pollingTimer) {
                    pollingTimer = setInterval(() => pollStatus(activeJobId), 1200);
                }
            } catch (error) {
                submitButton.disabled = false;
                submitButton.classList.remove('opacity-60', 'cursor-not-allowed');
                submitButton.innerHTML = `<i data-lucide="globe" class="w-4 h-4 mr-2"></i>${i18n.startLabel}`;
                if (typeof lucide !== 'undefined') {
                    lucide.createIcons();
                }
                setError(error.message || i18n.taskCreateFailed);
            }
        });
    })();
</script>
