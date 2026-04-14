<?php
/**
 * 智能GEO内容系统 - 后台公共头部
 *
 * @author 姚金刚
 * @version 1.0
 * @date 2025-10-06
 */

// 确保已经包含必要的文件
if (!defined('FEISHU_TREASURE')) {
    die('Direct access not allowed');
}

$admin_site_name = function_exists('get_setting') ? get_setting('site_title', SITE_NAME) : SITE_NAME;
$current_admin = function_exists('get_current_admin') ? get_current_admin() : null;
$is_super_admin = function_exists('is_super_admin') ? is_super_admin() : false;
$admin_role_label = $is_super_admin ? '超级管理员' : '管理员';

// 获取当前页面名称，用于高亮菜单
$current_page = basename($_SERVER['PHP_SELF']);

// 定义菜单项和子页面映射
$menu_items = [
    'dashboard.php' => ['name' => '首页', 'icon' => 'home'],
    'tasks.php' => ['name' => '任务管理', 'icon' => 'zap'],
    'articles.php' => ['name' => '文章管理', 'icon' => 'file-text'],
    'materials.php' => ['name' => '素材管理', 'icon' => 'folder'],
    'ai-configurator.php' => ['name' => 'AI配置器', 'icon' => 'cpu'],
    'site-settings.php' => ['name' => '网站设置', 'icon' => 'settings'],
    'security-settings.php' => ['name' => '安全管理', 'icon' => 'shield']
];

if ($is_super_admin) {
    $menu_items['admin-users.php'] = ['name' => '管理员', 'icon' => 'users'];
}

// 定义子页面与主菜单的映射关系
$sub_page_mapping = [
    // 任务管理相关页面
    'task-create.php' => 'tasks.php',
    'task-edit.php' => 'tasks.php',
    'task-execute.php' => 'tasks.php',

    // 文章管理相关页面
    'article-create.php' => 'articles.php',
    'article-edit.php' => 'articles.php',
    'article-view.php' => 'articles.php',
    'articles-review.php' => 'articles.php',
    'articles-trash.php' => 'articles.php',

    // 素材管理相关页面
    'authors.php' => 'materials.php',
    'keyword-libraries.php' => 'materials.php',
    'keyword-library-detail.php' => 'materials.php',
    'title-libraries.php' => 'materials.php',
    'title-library-ai-generate.php' => 'materials.php',
    'image-libraries.php' => 'materials.php',
    'image-library-detail.php' => 'materials.php',
    'knowledge-bases.php' => 'materials.php',
    'url-import.php' => 'materials.php',
    'url-import-preview.php' => 'materials.php',
    'url-import-history.php' => 'materials.php',

    // AI配置器相关页面
    'ai-models.php' => 'ai-configurator.php',
    'ai-prompts.php' => 'ai-configurator.php',
    'ai-special-prompts.php' => 'ai-configurator.php',
    'ai-config-backup.php' => 'ai-configurator.php',
    'ai-config-simple.php' => 'ai-configurator.php',

    // 管理员相关页面
    'admin-activity-logs.php' => 'admin-users.php',
    'api-tokens.php' => 'admin-users.php'
];

// 定义历史/兼容页面，避免和正式入口混淆
$legacy_pages = [
    'ai-config-backup.php' => 'AI 配置历史备份页，请优先使用“AI配置器”。',
    'ai-config-simple.php' => 'AI 配置简化页，请优先使用“AI配置器”。',
    'dashboard-backup.php' => '仪表盘历史备份页，请优先使用“首页”。',
    'dashboard-simple.php' => '仪表盘简化页，请优先使用“首页”。',
    'tasks-safe.php' => '任务管理兼容页，请优先使用“任务管理”。',
    'materials-new.php' => '素材管理过渡入口，请优先使用正式菜单入口。',
    'tasks-new.php' => '任务管理过渡入口，请优先使用正式菜单入口。',
    'articles-new.php' => '文章管理过渡入口，请优先使用正式菜单入口。',
    'authors-new.php' => '作者管理过渡入口，请优先使用正式菜单入口。',
    'ai-config-new.php' => 'AI 配置过渡入口，请优先使用正式菜单入口。',
    'login-new.php' => '登录过渡入口，请优先使用 `/geo_admin/` 登录。',
    'minimal-test.php' => '测试页，仅用于排查，不属于正式后台功能。',
    'simple-test.php' => '测试页，仅用于排查，不属于正式后台功能。',
    'test-admin.php' => '测试页，仅用于排查，不属于正式后台功能。',
    'test-dashboard.php' => '测试页，仅用于排查，不属于正式后台功能。',
    'test-fixes.php' => '测试页，仅用于排查，不属于正式后台功能。',
    'test-navigation.php' => '测试页，仅用于排查，不属于正式后台功能。',
    'verify-fixes.php' => '验证页，仅用于排查，不属于正式后台功能。'
];

// 判断当前激活的菜单
function isActiveMenu($page, $current_page, $sub_page_mapping) {
    // 直接匹配
    if ($page === $current_page) {
        return true;
    }

    // 检查是否为子页面
    if (isset($sub_page_mapping[$current_page]) && $sub_page_mapping[$current_page] === $page) {
        return true;
    }

    return false;
}
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo isset($page_title) ? $page_title : '管理后台'; ?> - <?php echo htmlspecialchars($admin_site_name); ?></title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/lucide/0.263.1/lucide.min.css">
    <script src="https://unpkg.com/lucide@latest/dist/umd/lucide.js"></script>
    <?php if (isset($additional_css)): ?>
        <?php echo $additional_css; ?>
    <?php endif; ?>
</head>
<body class="bg-gray-50">
    <!-- 导航栏 -->
    <nav class="bg-white shadow-sm border-b">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
            <div class="flex justify-between h-16">
                <div class="flex items-center space-x-8">
                    <!-- Logo -->
                    <a href="<?php echo htmlspecialchars(admin_url('dashboard.php')); ?>" class="text-xl font-semibold text-gray-900"><?php echo htmlspecialchars($admin_site_name); ?></a>
                    
                    <!-- 主导航菜单 -->
                    <nav class="hidden md:flex space-x-8">
                        <?php foreach ($menu_items as $page => $item): ?>
                            <a href="<?php echo htmlspecialchars(admin_url($page)); ?>"
                               class="<?php echo isActiveMenu($page, $current_page, $sub_page_mapping) ? 'text-blue-600 font-medium' : 'text-gray-500 hover:text-gray-700'; ?> transition-colors duration-200">
                                <?php echo $item['name']; ?>
                            </a>
                        <?php endforeach; ?>
                    </nav>
                </div>
                
                <!-- 右侧用户信息 -->
                <div class="flex items-center space-x-4">
                    <!-- 通知图标 -->
                    <button class="text-gray-400 hover:text-gray-600 transition-colors duration-200">
                        <i data-lucide="bell" class="w-5 h-5"></i>
                    </button>
                    
                    <!-- 用户信息 -->
                    <div class="flex items-center space-x-3">
                        <div class="text-right">
                            <div class="text-sm text-gray-600">欢迎，<?php echo htmlspecialchars($current_admin['username'] ?? ($_SESSION['admin_username'] ?? 'Admin')); ?></div>
                            <div class="text-xs text-gray-400"><?php echo htmlspecialchars($admin_role_label); ?></div>
                        </div>
                        <div class="relative">
                            <button onclick="toggleUserMenu()" class="flex items-center space-x-1 text-sm text-gray-600 hover:text-gray-900 transition-colors duration-200">
                                <div class="w-8 h-8 bg-blue-100 rounded-full flex items-center justify-center">
                                    <i data-lucide="user" class="w-4 h-4 text-blue-600"></i>
                                </div>
                                <i data-lucide="chevron-down" class="w-4 h-4"></i>
                            </button>
                            
                            <!-- 用户下拉菜单 -->
                            <div id="user-menu" class="hidden absolute right-0 mt-2 w-48 bg-white rounded-md shadow-lg py-1 z-50">
                                <a href="<?php echo htmlspecialchars(admin_url('dashboard.php')); ?>" class="block px-4 py-2 text-sm text-gray-700 hover:bg-gray-100">
                                    <i data-lucide="home" class="w-4 h-4 inline mr-2"></i>
                                    返回首页
                                </a>
                                <a href="<?php echo htmlspecialchars(admin_url('site-settings.php')); ?>" class="block px-4 py-2 text-sm text-gray-700 hover:bg-gray-100">
                                    <i data-lucide="settings" class="w-4 h-4 inline mr-2"></i>
                                    系统设置
                                </a>
                                <?php if ($is_super_admin): ?>
                                    <a href="<?php echo htmlspecialchars(admin_url('admin-users.php')); ?>" class="block px-4 py-2 text-sm text-gray-700 hover:bg-gray-100">
                                        <i data-lucide="users" class="w-4 h-4 inline mr-2"></i>
                                        管理员管理
                                    </a>
                                    <a href="<?php echo htmlspecialchars(admin_url('admin-activity-logs.php')); ?>" class="block px-4 py-2 text-sm text-gray-700 hover:bg-gray-100">
                                        <i data-lucide="clipboard-list" class="w-4 h-4 inline mr-2"></i>
                                        操作日志
                                    </a>
                                    <a href="<?php echo htmlspecialchars(admin_url('api-tokens.php')); ?>" class="block px-4 py-2 text-sm text-gray-700 hover:bg-gray-100">
                                        <i data-lucide="key-round" class="w-4 h-4 inline mr-2"></i>
                                        API Tokens
                                    </a>
                                <?php endif; ?>
                                <div class="border-t border-gray-100"></div>
                                <a href="<?php echo htmlspecialchars(admin_url('logout.php')); ?>" class="block px-4 py-2 text-sm text-red-600 hover:bg-gray-100">
                                    <i data-lucide="log-out" class="w-4 h-4 inline mr-2"></i>
                                    退出登录
                                </a>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        
        <!-- 移动端菜单 -->
        <div id="mobile-menu" class="hidden md:hidden">
            <div class="px-2 pt-2 pb-3 space-y-1 sm:px-3 bg-gray-50 border-t">
                <?php foreach ($menu_items as $page => $item): ?>
                    <a href="<?php echo htmlspecialchars(admin_url($page)); ?>"
                       class="<?php echo isActiveMenu($page, $current_page, $sub_page_mapping) ? 'bg-blue-100 text-blue-600' : 'text-gray-600 hover:bg-gray-100'; ?> block px-3 py-2 rounded-md text-base font-medium transition-colors duration-200">
                        <i data-lucide="<?php echo $item['icon']; ?>" class="w-4 h-4 inline mr-2"></i>
                        <?php echo $item['name']; ?>
                    </a>
                <?php endforeach; ?>
            </div>
        </div>
    </nav>

    <!-- 移动端菜单按钮 -->
    <div class="md:hidden fixed top-4 right-4 z-50">
        <button onclick="toggleMobileMenu()" class="bg-white p-2 rounded-md shadow-md">
            <i data-lucide="menu" class="w-5 h-5 text-gray-600"></i>
        </button>
    </div>

    <!-- 主要内容区域开始 -->
    <div class="max-w-7xl mx-auto py-6 sm:px-6 lg:px-8">
        
        <!-- 消息提示区域 -->
        <?php if (isset($message) && !empty($message)): ?>
            <div class="admin-flash-alert mb-4 bg-green-100 border border-green-400 text-green-700 px-4 py-3 rounded relative">
                <span class="block sm:inline"><?php echo htmlspecialchars($message); ?></span>
                <button onclick="this.parentElement.style.display='none'" class="absolute top-0 bottom-0 right-0 px-4 py-3">
                    <i data-lucide="x" class="w-4 h-4"></i>
                </button>
            </div>
        <?php endif; ?>
        
        <?php if (isset($error) && !empty($error)): ?>
            <div class="admin-flash-alert mb-4 bg-red-100 border border-red-400 text-red-700 px-4 py-3 rounded relative">
                <span class="block sm:inline"><?php echo htmlspecialchars($error); ?></span>
                <?php if (!empty($error_action_url) && !empty($error_action_label)): ?>
                    <div class="mt-3">
                        <a href="<?php echo htmlspecialchars($error_action_url); ?>" class="inline-flex items-center px-3 py-1.5 border border-red-300 text-xs font-medium rounded-md text-red-700 bg-white hover:bg-red-50">
                            <i data-lucide="external-link" class="w-4 h-4 mr-1"></i>
                            <?php echo htmlspecialchars($error_action_label); ?>
                        </a>
                    </div>
                <?php endif; ?>
                <button onclick="this.parentElement.style.display='none'" class="absolute top-0 bottom-0 right-0 px-4 py-3">
                    <i data-lucide="x" class="w-4 h-4"></i>
                </button>
            </div>
        <?php endif; ?>

        <?php if (isset($legacy_pages[$current_page])): ?>
            <div class="mb-4 bg-amber-50 border border-amber-300 text-amber-900 px-4 py-3 rounded-lg">
                <div class="flex items-start gap-3">
                    <i data-lucide="triangle-alert" class="w-5 h-5 mt-0.5 text-amber-600"></i>
                    <div>
                        <div class="font-semibold">当前页面属于历史/兼容页面</div>
                        <div class="text-sm mt-1"><?php echo htmlspecialchars($legacy_pages[$current_page]); ?></div>
                    </div>
                </div>
            </div>
        <?php endif; ?>

        <!-- 页面标题区域 -->
        <?php if (isset($page_header) && $page_header): ?>
            <div class="mb-8">
                <?php echo $page_header; ?>
            </div>
        <?php endif; ?>

    <script>
        // 初始化Lucide图标
        document.addEventListener('DOMContentLoaded', function() {
            if (typeof lucide !== 'undefined') {
                lucide.createIcons();
            }
        });

        // 切换用户菜单
        function toggleUserMenu() {
            const menu = document.getElementById('user-menu');
            menu.classList.toggle('hidden');
        }

        // 切换移动端菜单
        function toggleMobileMenu() {
            const menu = document.getElementById('mobile-menu');
            menu.classList.toggle('hidden');
        }

        // 点击外部关闭菜单
        document.addEventListener('click', function(event) {
            const userMenu = document.getElementById('user-menu');
            const mobileMenu = document.getElementById('mobile-menu');
            
            if (!event.target.closest('[onclick="toggleUserMenu()"]') && !userMenu.contains(event.target)) {
                userMenu.classList.add('hidden');
            }
            
            if (!event.target.closest('[onclick="toggleMobileMenu()"]') && !mobileMenu.contains(event.target)) {
                mobileMenu.classList.add('hidden');
            }
        });

        // 自动隐藏消息提示
        setTimeout(function() {
            const alerts = document.querySelectorAll('.admin-flash-alert');
            alerts.forEach(function(alert) {
                if (alert.style.display !== 'none') {
                    alert.style.opacity = '0';
                    setTimeout(function() {
                        alert.style.display = 'none';
                    }, 300);
                }
            });
        }, 5000);
    </script>
