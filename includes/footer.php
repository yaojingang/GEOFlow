<?php
if (!defined('FEISHU_TREASURE')) {
    exit('Access denied');
}

$default_copyright = app_locale() === 'en'
    ? '© 2025 GEO+AI Content System. All rights reserved.'
    : '© 2025 GEO+AI内容生成系统。保留所有权利。';

$footer_copyright_text = function_exists('site_setting_value')
    ? site_setting_value('copyright_info', site_setting_value('copyright_text', $default_copyright))
    : get_setting('copyright_text', $default_copyright);
?>
<footer class="bg-white border-t border-gray-100 mt-16">
    <div class="site-container px-4 sm:px-6 lg:px-8 py-8">
        <div class="text-center">
            <p class="text-gray-500 text-sm"><?php echo htmlspecialchars($footer_copyright_text); ?></p>
        </div>
    </div>
</footer>

<script src="/assets/js/main.js"></script>
<script>
if (typeof lucide !== 'undefined') {
    lucide.createIcons();
}
</script>
