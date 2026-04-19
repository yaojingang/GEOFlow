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
                </div>
            </div>
        </div>
<?php
// 包含底部模块
require_once __DIR__ . '/includes/footer.php';
?>
