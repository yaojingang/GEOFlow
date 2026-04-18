<header class="qt-header">
    <div class="qt-container qt-header__inner">
        <a href="<?php echo htmlspecialchars(geoflow_theme_preview_url($theme_id, 'home')); ?>" class="qt-brand">
            <?php if (!empty($site_logo)): ?>
                <img src="<?php echo htmlspecialchars($site_logo); ?>" alt="<?php echo htmlspecialchars($site_title); ?>">
            <?php else: ?>
                <span><?php echo htmlspecialchars($site_title); ?></span>
            <?php endif; ?>
        </a>

        <nav class="qt-nav">
            <a href="<?php echo htmlspecialchars(geoflow_theme_preview_url($theme_id, 'home')); ?>" class="qt-nav-link <?php echo $preview_page === 'home' ? 'is-active' : ''; ?>">
                <i data-lucide="home" class="w-4 h-4"></i>
                <?php echo __('front.nav.home'); ?>
            </a>
            <div class="qt-dropdown" id="qtPreviewCategoryDropdown">
                <button type="button" class="qt-dropdown-toggle <?php echo $preview_page === 'category' ? 'is-active' : ''; ?>" onclick="toggleQtPreviewCategoryDropdown()">
                    <i data-lucide="folder" class="w-4 h-4"></i>
                    <?php echo __('front.nav.categories'); ?>
                    <i data-lucide="chevron-down" class="w-4 h-4"></i>
                </button>
                <div class="qt-dropdown-menu" id="qtPreviewCategoryMenu">
                    <a href="<?php echo htmlspecialchars(geoflow_theme_preview_url($theme_id, 'home')); ?>" class="qt-dropdown-item">
                        <span><?php echo __('front.nav.all_articles'); ?></span>
                    </a>
                    <?php foreach ($categories as $category_item): ?>
                        <a href="<?php echo htmlspecialchars(geoflow_theme_preview_url($theme_id, 'category', ['slug' => $category_item['slug'] ?: $category_item['id']])); ?>" class="qt-dropdown-item">
                            <span><?php echo htmlspecialchars($category_item['name']); ?></span>
                            <span class="text-xs text-gray-400">(<?php echo get_category_article_count($category_item['id']); ?>)</span>
                        </a>
                    <?php endforeach; ?>
                </div>
            </div>
        </nav>

        <button type="button" class="qt-mobile-button" onclick="toggleQtPreviewMobileMenu()">
            <i data-lucide="menu" class="w-5 h-5"></i>
        </button>
    </div>

    <div class="qt-container qt-mobile-panel" id="qtPreviewMobileMenu">
        <a href="<?php echo htmlspecialchars(geoflow_theme_preview_url($theme_id, 'home')); ?>" class="qt-mobile-link <?php echo $preview_page === 'home' ? 'is-active' : ''; ?>">
            <i data-lucide="home" class="w-4 h-4"></i>
            <?php echo __('front.nav.home'); ?>
        </a>
        <?php foreach ($categories as $category_item): ?>
            <a href="<?php echo htmlspecialchars(geoflow_theme_preview_url($theme_id, 'category', ['slug' => $category_item['slug'] ?: $category_item['id']])); ?>" class="qt-mobile-link <?php echo $preview_page === 'category' && (($category['id'] ?? null) === $category_item['id']) ? 'is-active' : ''; ?>">
                <i data-lucide="folder" class="w-4 h-4"></i>
                <?php echo htmlspecialchars($category_item['name']); ?>
            </a>
        <?php endforeach; ?>
        <a href="<?php echo htmlspecialchars(geoflow_theme_preview_url($theme_id, 'archive')); ?>" class="qt-mobile-link <?php echo $preview_page === 'archive' ? 'is-active' : ''; ?>">
            <i data-lucide="archive" class="w-4 h-4"></i>
            <?php echo __('front.archive.title'); ?>
        </a>
    </div>
</header>

<script>
function toggleQtPreviewCategoryDropdown() {
    const menu = document.getElementById('qtPreviewCategoryMenu');
    if (menu) {
        menu.classList.toggle('is-open');
    }
}

function toggleQtPreviewMobileMenu() {
    const menu = document.getElementById('qtPreviewMobileMenu');
    if (menu) {
        menu.classList.toggle('is-open');
    }
}

document.addEventListener('click', function (event) {
    const dropdown = document.getElementById('qtPreviewCategoryDropdown');
    const menu = document.getElementById('qtPreviewCategoryMenu');
    if (dropdown && menu && !dropdown.contains(event.target)) {
        menu.classList.remove('is-open');
    }
});
</script>
