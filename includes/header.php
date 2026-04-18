<?php
if (!defined('FEISHU_TREASURE')) {
    exit('Access denied');
}

if (!isset($site_title)) {
    if (function_exists('site_setting_value')) {
        $site_title = site_setting_value('site_name', SITE_NAME);
    } elseif (function_exists('get_site_setting')) {
        $site_title = get_site_setting('site_name', get_setting('site_title', SITE_NAME));
    } else {
        $site_title = get_setting('site_title', SITE_NAME);
    }
}

$site_logo = function_exists('site_setting_value') ? site_setting_value('site_logo', '') : '';

if (!isset($categories)) {
    $categories = get_categories();
}

$request_path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
$is_home = $request_path === '/' || $request_path === '/index.php';
$is_archive = strpos($request_path, '/archive') === 0;
?>
<header class="site-header bg-white border-b border-gray-100 sticky top-0 z-50">
    <div class="site-container px-4 sm:px-6 lg:px-8">
        <div class="flex justify-between items-center h-16">
            <div class="flex items-center">
                <a href="/" class="flex items-center">
                    <?php if (!empty($site_logo)): ?>
                        <img src="<?php echo htmlspecialchars($site_logo); ?>" alt="<?php echo htmlspecialchars($site_title); ?>" class="h-9 w-auto max-w-48 object-contain">
                    <?php else: ?>
                        <span class="text-lg sm:text-xl font-bold text-gray-900"><?php echo htmlspecialchars($site_title); ?></span>
                    <?php endif; ?>
                </a>
            </div>

            <nav class="hidden md:flex items-center space-x-6">
                <a href="/" class="flex items-center text-sm font-medium <?php echo $is_home ? 'text-gray-900' : 'text-gray-600 hover:text-gray-900'; ?>">
                    <i data-lucide="home" class="w-4 h-4 mr-1"></i>
                    <?php echo __('front.nav.home'); ?>
                </a>

                <div class="relative" id="categoryDropdown">
                    <button type="button" class="flex items-center text-gray-600 hover:text-gray-900 font-medium text-sm" onclick="toggleCategoryDropdown()">
                        <i data-lucide="folder" class="w-4 h-4 mr-1"></i>
                        <?php echo __('front.nav.categories'); ?>
                        <i data-lucide="chevron-down" class="w-4 h-4 ml-1"></i>
                    </button>

                    <div id="categoryDropdownMenu" class="absolute left-0 mt-2 w-56 bg-white rounded-lg shadow-lg border border-gray-100 py-2 hidden">
                        <a href="/" class="flex items-center px-4 py-2 text-gray-700 hover:bg-gray-50 text-sm">
                            <i data-lucide="home" class="w-4 h-4 mr-3"></i>
                            <?php echo __('front.nav.all_articles'); ?>
                        </a>
                        <?php foreach ($categories as $category_item): ?>
                            <a href="/category/<?php echo htmlspecialchars($category_item['slug'] ?: $category_item['id']); ?>" class="flex items-center px-4 py-2 text-gray-700 hover:bg-gray-50 text-sm">
                                <i data-lucide="folder" class="w-4 h-4 mr-3"></i>
                                <?php echo htmlspecialchars($category_item['name']); ?>
                                <span class="ml-auto text-xs text-gray-400">(<?php echo get_category_article_count($category_item['id']); ?>)</span>
                            </a>
                        <?php endforeach; ?>
                    </div>
                </div>

            </nav>

            <button type="button" class="mobile-menu-toggle md:hidden flex items-center justify-center w-11 h-11 rounded-xl text-gray-600 hover:text-gray-900 hover:bg-gray-50" onclick="toggleMobileMenu()">
                <i data-lucide="menu" class="w-6 h-6"></i>
            </button>
        </div>

        <div id="mobileMenu" class="mobile-panel md:hidden hidden border-t border-gray-100 py-4">
            <nav class="flex flex-col space-y-4">
                <a href="/" class="mobile-nav-link flex items-center text-sm font-medium <?php echo $is_home ? 'text-gray-900' : 'text-gray-600 hover:text-gray-900'; ?>">
                    <i data-lucide="home" class="w-4 h-4 mr-3"></i>
                    <?php echo __('front.nav.home'); ?>
                </a>
                <button type="button" class="mobile-nav-link flex items-center justify-between text-gray-600 hover:text-gray-900 font-medium py-2 text-sm w-full" onclick="toggleMobileCategoryMenu()">
                    <span class="flex items-center">
                        <i data-lucide="folder" class="w-4 h-4 mr-3"></i>
                        <?php echo __('front.nav.categories'); ?>
                    </span>
                    <i data-lucide="chevron-down" class="w-4 h-4" id="mobileCategoryChevron"></i>
                </button>
                <div id="mobileCategoryMenu" class="hidden ml-4 space-y-2">
                    <?php foreach ($categories as $category_item): ?>
                        <a href="/category/<?php echo htmlspecialchars($category_item['slug'] ?: $category_item['id']); ?>" class="mobile-subnav-link flex items-center text-gray-600 hover:text-gray-900 py-1 text-sm">
                            <i data-lucide="folder" class="w-4 h-4 mr-3"></i>
                            <?php echo htmlspecialchars($category_item['name']); ?>
                        </a>
                    <?php endforeach; ?>
                </div>
                <a href="<?php echo htmlspecialchars(admin_url()); ?>" class="mobile-nav-link flex items-center text-sm font-medium text-gray-600 hover:text-gray-900">
                    <i data-lucide="shield" class="w-4 h-4 mr-3"></i>
                    <?php echo __('front.nav.admin'); ?>
                </a>
            </nav>
        </div>
    </div>
</header>

<script>
function toggleCategoryDropdown() {
    const menu = document.getElementById('categoryDropdownMenu');
    if (menu) {
        menu.classList.toggle('hidden');
    }
}

function toggleMobileMenu() {
    const menu = document.getElementById('mobileMenu');
    if (menu) {
        menu.classList.toggle('hidden');
    }
}

function toggleMobileCategoryMenu() {
    const menu = document.getElementById('mobileCategoryMenu');
    const chevron = document.getElementById('mobileCategoryChevron');
    if (menu) {
        menu.classList.toggle('hidden');
    }
    if (chevron) {
        chevron.classList.toggle('rotate-180');
    }
}

document.addEventListener('click', function(event) {
    const dropdown = document.getElementById('categoryDropdown');
    const menu = document.getElementById('categoryDropdownMenu');
    if (dropdown && menu && !dropdown.contains(event.target)) {
        menu.classList.add('hidden');
    }

});
</script>
