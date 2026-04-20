<?php
/**
 * 智能GEO内容系统 - 网站设置
 */

define('FEISHU_TREASURE', true);
session_start();

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/database_admin.php';
require_once __DIR__ . '/../includes/theme_preview.php';
require_once __DIR__ . '/../includes/update_check.php';

// 检查管理员登录
require_admin_login();

$flash_message = $_SESSION['admin_message_success'] ?? '';
$flash_error = $_SESSION['admin_message_error'] ?? '';
unset($_SESSION['admin_message_success'], $_SESSION['admin_message_error']);

// 立即释放session锁，允许其他页面并发访问
session_write_close();

// 设置页面标题
$page_title = __('site_settings.page_title');

$message = '';
$error = '';
$available_themes = geoflow_discover_themes();
$update_state = geoflow_get_update_state(false);
$update_copy = geoflow_get_update_copy($update_state);

// 处理POST请求
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
        $error = __('message.csrf_failed');
    } else {
        $action = $_POST['action'] ?? '';

        switch ($action) {
            case 'update_site_settings':
                $site_name = trim($_POST['site_name'] ?? '');
                $site_subtitle = trim($_POST['site_subtitle'] ?? '');
                $site_description = trim($_POST['site_description'] ?? '');
                $site_keywords = trim($_POST['site_keywords'] ?? '');
                $copyright_info = trim($_POST['copyright_info'] ?? '');
                $site_logo = trim($_POST['site_logo'] ?? '');
                $site_favicon = trim($_POST['site_favicon'] ?? '');
                $analytics_code = trim($_POST['analytics_code'] ?? '');
                $seo_title_template = trim($_POST['seo_title_template'] ?? '');
                $seo_description_template = trim($_POST['seo_description_template'] ?? '');
                $featured_limit = max(1, intval($_POST['featured_limit'] ?? 6));
                $per_page = max(1, intval($_POST['per_page'] ?? 12));

                if (empty($site_name)) {
                    $error = __('site_settings.error.site_name_required');
                } else {
                    try {
                        // 更新网站设置
                        $settings = [
                            'site_name' => $site_name,
                            'site_title' => $site_name,
                            'site_subtitle' => $site_subtitle,
                            'site_description' => $site_description,
                            'site_keywords' => $site_keywords,
                            'copyright_info' => $copyright_info,
                            'site_logo' => $site_logo,
                            'site_favicon' => $site_favicon,
                            'analytics_code' => $analytics_code,
                            'seo_title_template' => $seo_title_template,
                            'seo_description_template' => $seo_description_template,
                            'featured_limit' => (string) $featured_limit,
                            'per_page' => (string) $per_page
                        ];

                        foreach ($settings as $key => $value) {
                            $update_stmt = $db->prepare("
                                UPDATE site_settings
                                SET setting_value = ?, updated_at = CURRENT_TIMESTAMP
                                WHERE setting_key = ?
                            ");
                            $update_stmt->execute([$value, $key]);

                            if ($update_stmt->rowCount() === 0) {
                                $insert_stmt = $db->prepare("
                                    INSERT INTO site_settings (setting_key, setting_value, updated_at)
                                    VALUES (?, ?, CURRENT_TIMESTAMP)
                                ");
                                $insert_stmt->execute([$key, $value]);
                            }
                        }

                        $message = __('site_settings.message.saved');
                    } catch (Exception $e) {
                        $error = __('site_settings.message.save_error', ['message' => $e->getMessage()]);
                    }
                }
                break;

            case 'update_article_detail_ads':
                $postedAds = $_POST['ads'] ?? [];
                if (!is_array($postedAds)) {
                    $postedAds = [];
                }

                $ads = [];
                $validationError = '';
                foreach ($postedAds as $index => $postedAd) {
                    if (!is_array($postedAd)) {
                        continue;
                    }

                    $name = trim((string) ($postedAd['name'] ?? ''));
                    $badge = trim((string) ($postedAd['badge'] ?? ''));
                    $title = trim((string) ($postedAd['title'] ?? ''));
                    $copy = trim((string) ($postedAd['copy'] ?? ''));
                    $buttonText = trim((string) ($postedAd['button_text'] ?? ''));
                    $buttonUrl = normalize_cta_target_url((string) ($postedAd['button_url'] ?? ''));
                    $enabled = !empty($postedAd['enabled']);
                    $id = trim((string) ($postedAd['id'] ?? ''));

                    if ($name === '' && $badge === '' && $title === '' && $copy === '' && $buttonText === '' && $buttonUrl === '') {
                        continue;
                    }

                    if ($copy === '' || $buttonText === '' || $buttonUrl === '') {
                        $validationError = __('site_settings.ads.validation_required', ['index' => $index + 1]);
                        break;
                    }

                    $ads[] = [
                        'id' => $id !== '' ? $id : uniqid('article_ad_', true),
                        'name' => $name !== '' ? $name : __('site_settings.ads.default_name', ['index' => count($ads) + 1]),
                        'badge' => $badge,
                        'title' => $title,
                        'copy' => $copy,
                        'button_text' => $buttonText,
                        'button_url' => $buttonUrl,
                        'enabled' => $enabled
                    ];
                }

                if ($validationError !== '') {
                    $error = $validationError;
                } elseif (!set_setting('article_detail_ads', json_encode($ads, JSON_UNESCAPED_UNICODE))) {
                    $error = __('site_settings.ads.save_failed');
                } else {
                    $message = __('site_settings.ads.saved');
                }
                break;

            case 'update_theme_settings':
                $selected_theme = trim((string) ($_POST['active_theme'] ?? ''));
                $allowed_theme_ids = array_column($available_themes, 'id');

                if ($selected_theme !== '' && !in_array($selected_theme, $allowed_theme_ids, true)) {
                    $error = __('site_settings.theme.invalid_selection');
                    break;
                }

                if (set_setting('active_theme', $selected_theme)) {
                    $message = $selected_theme === ''
                        ? __('site_settings.theme.message.default_enabled')
                        : __('site_settings.theme.message.activated', ['name' => $selected_theme]);
                } else {
                    $error = __('site_settings.theme.message.save_failed');
                }
                break;
        }
    }
}

// 获取当前网站设置
try {
    $current_settings = [];
    $stmt = $db->query("SELECT setting_key, setting_value FROM site_settings ORDER BY id ASC");
    while ($row = $stmt->fetch()) {
        $current_settings[$row['setting_key']] = $row['setting_value'];
    }
} catch (Exception $e) {
    $current_settings = [];
}

// 设置默认值
$defaults = [
    'site_name' => '智能GEO内容系统',
    'site_subtitle' => '',
    'site_description' => '基于AI的智能内容生成与发布平台',
    'site_keywords' => 'AI内容生成,GEO优化,智能发布,内容管理',
    'copyright_info' => '© 2024 智能GEO内容系统. All rights reserved.',
    'site_logo' => '',
    'site_favicon' => '',
    'analytics_code' => '',
    'seo_title_template' => '{title} - {site_name}',
    'seo_description_template' => '{description}',
    'featured_limit' => '6',
    'per_page' => '12',
    'article_detail_ads' => '[]',
    'active_theme' => ''
];

foreach ($defaults as $key => $default_value) {
    if (!isset($current_settings[$key])) {
        $current_settings[$key] = $default_value;
    }
}

$article_detail_ads = json_decode($current_settings['article_detail_ads'] ?? '[]', true);
if (!is_array($article_detail_ads)) {
    $article_detail_ads = [];
}

// 包含统一头部
require_once __DIR__ . '/includes/header.php';
?>

            <!-- 页面标题 -->
            <div class="mb-8">
                <h1 class="text-2xl font-bold text-gray-900"><?php echo __('site_settings.page_title'); ?></h1>
                <p class="mt-1 text-sm text-gray-600"><?php echo __('site_settings.page_subtitle'); ?></p>
            </div>

            <!-- 消息提示 -->
<?php if (!empty($flash_message)): ?>
                <div class="mb-6 p-4 bg-green-50 border border-green-200 rounded-lg">
                    <div class="flex items-center">
                        <i data-lucide="check-circle" class="w-5 h-5 text-green-500 mr-2"></i>
                        <span class="text-green-700"><?php echo htmlspecialchars($flash_message); ?></span>
                    </div>
                </div>
            <?php endif; ?>

            <?php if (!empty($flash_error)): ?>
                <div class="mb-6 p-4 bg-red-50 border border-red-200 rounded-lg">
                    <div class="flex items-center">
                        <i data-lucide="alert-circle" class="w-5 h-5 text-red-500 mr-2"></i>
                        <span class="text-red-700"><?php echo htmlspecialchars($flash_error); ?></span>
                    </div>
                </div>
            <?php endif; ?>

            <?php if (!empty($message)): ?>
                <div class="mb-6 p-4 bg-green-50 border border-green-200 rounded-lg">
                    <div class="flex items-center">
                        <i data-lucide="check-circle" class="w-5 h-5 text-green-500 mr-2"></i>
                        <span class="text-green-700"><?php echo htmlspecialchars($message); ?></span>
                    </div>
                </div>
            <?php endif; ?>

            <?php if (!empty($error)): ?>
                <div class="mb-6 p-4 bg-red-50 border border-red-200 rounded-lg">
                    <div class="flex items-center">
                        <i data-lucide="alert-circle" class="w-5 h-5 text-red-500 mr-2"></i>
                        <span class="text-red-700"><?php echo htmlspecialchars($error); ?></span>
                    </div>
                </div>
            <?php endif; ?>

            <style>
                .settings-accordion > summary {
                    list-style: none;
                }

                .settings-accordion > summary::-webkit-details-marker {
                    display: none;
                }

                .settings-accordion .accordion-chevron {
                    transition: transform 0.2s ease;
                }

                .settings-accordion[open] .accordion-chevron {
                    transform: rotate(180deg);
                }
            </style>

            <div class="space-y-6">
            <!-- 网站设置表单 -->
            <details class="settings-accordion bg-white shadow rounded-lg">
                <summary class="px-6 py-4 cursor-pointer flex items-center justify-between gap-4">
                    <div>
                        <h3 class="text-lg font-medium text-gray-900"><?php echo __('site_settings.section_basic'); ?></h3>
                        <p class="mt-1 text-sm text-gray-600"><?php echo __('site_settings.page_subtitle'); ?></p>
                    </div>
                    <i data-lucide="chevron-down" class="accordion-chevron w-5 h-5 text-gray-400"></i>
                </summary>
                <div class="px-6 py-6 border-t border-gray-200">
                    <form method="POST" class="space-y-6">
                        <input type="hidden" name="action" value="update_site_settings">
                        <input type="hidden" name="csrf_token" value="<?php echo generate_csrf_token(); ?>">

                        <!-- 基本信息 -->
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-2"><?php echo __('site_settings.field_site_name'); ?></label>
                                <input type="text" name="site_name" required
                                       value="<?php echo htmlspecialchars($current_settings['site_name']); ?>"
                                       class="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:ring-blue-500 focus:border-blue-500"
                                       placeholder="<?php echo htmlspecialchars(__('site_settings.placeholder_site_name')); ?>">
                            </div>

                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-2"><?php echo __('site_settings.field_logo'); ?></label>
                                <input type="url" name="site_logo"
                                       value="<?php echo htmlspecialchars($current_settings['site_logo']); ?>"
                                       class="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:ring-blue-500 focus:border-blue-500"
                                       placeholder="https://example.com/logo.png">
                            </div>
                        </div>

                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-2"><?php echo __('site_settings.field_description'); ?></label>
                            <textarea name="site_description" rows="3"
                                      class="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:ring-blue-500 focus:border-blue-500"
                                      placeholder="<?php echo htmlspecialchars(__('site_settings.placeholder_description')); ?>"><?php echo htmlspecialchars($current_settings['site_description']); ?></textarea>
                        </div>

                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-2"><?php echo __('site_settings.field_subtitle'); ?></label>
                            <input type="text" name="site_subtitle"
                                   value="<?php echo htmlspecialchars($current_settings['site_subtitle']); ?>"
                                   class="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:ring-blue-500 focus:border-blue-500"
                                   placeholder="<?php echo htmlspecialchars(__('site_settings.placeholder_subtitle')); ?>">
                        </div>

                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-2"><?php echo __('site_settings.field_keywords'); ?></label>
                            <input type="text" name="site_keywords"
                                   value="<?php echo htmlspecialchars($current_settings['site_keywords']); ?>"
                                   class="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:ring-blue-500 focus:border-blue-500"
                                   placeholder="<?php echo htmlspecialchars(__('site_settings.placeholder_keywords')); ?>">
                            <p class="mt-1 text-xs text-gray-500"><?php echo __('site_settings.keywords_help'); ?></p>
                        </div>

                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-2"><?php echo __('site_settings.field_copyright'); ?></label>
                            <input type="text" name="copyright_info"
                                   value="<?php echo htmlspecialchars($current_settings['copyright_info']); ?>"
                                   class="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:ring-blue-500 focus:border-blue-500"
                                   placeholder="© 2024 Site Name. All rights reserved.">
                        </div>

                        <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-2"><?php echo __('site_settings.field_featured_limit'); ?></label>
                                <input type="number" name="featured_limit" min="1"
                                       value="<?php echo htmlspecialchars($current_settings['featured_limit']); ?>"
                                       class="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:ring-blue-500 focus:border-blue-500"
                                       placeholder="6">
                            </div>

                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-2"><?php echo __('site_settings.field_per_page'); ?></label>
                                <input type="number" name="per_page" min="1"
                                       value="<?php echo htmlspecialchars($current_settings['per_page']); ?>"
                                       class="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:ring-blue-500 focus:border-blue-500"
                                       placeholder="12">
                            </div>
                        </div>

                        <!-- SEO设置 -->
                        <div class="border-t border-gray-200 pt-6">
                            <h4 class="text-lg font-medium text-gray-900 mb-4"><?php echo __('site_settings.section_seo'); ?></h4>

                            <div class="space-y-4">
                                <div>
                                    <label class="block text-sm font-medium text-gray-700 mb-2"><?php echo __('site_settings.field_seo_title_template'); ?></label>
                                    <input type="text" name="seo_title_template"
                                           value="<?php echo htmlspecialchars($current_settings['seo_title_template']); ?>"
                                           class="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:ring-blue-500 focus:border-blue-500"
                                           placeholder="{title} - {site_name}">
                                    <p class="mt-1 text-xs text-gray-500"><?php echo __('site_settings.seo_title_help'); ?></p>
                                </div>

                                <div>
                                    <label class="block text-sm font-medium text-gray-700 mb-2"><?php echo __('site_settings.field_seo_description_template'); ?></label>
                                    <input type="text" name="seo_description_template"
                                           value="<?php echo htmlspecialchars($current_settings['seo_description_template']); ?>"
                                           class="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:ring-blue-500 focus:border-blue-500"
                                           placeholder="{description}">
                                    <p class="mt-1 text-xs text-gray-500"><?php echo __('site_settings.seo_description_help'); ?></p>
                                </div>

                                <div>
                                    <label class="block text-sm font-medium text-gray-700 mb-2"><?php echo __('site_settings.field_favicon'); ?></label>
                                    <input type="url" name="site_favicon"
                                           value="<?php echo htmlspecialchars($current_settings['site_favicon']); ?>"
                                           class="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:ring-blue-500 focus:border-blue-500"
                                           placeholder="https://example.com/favicon.ico">
                                </div>
                            </div>
                        </div>

                        <!-- 统计代码 -->
                        <div class="border-t border-gray-200 pt-6">
                            <h4 class="text-lg font-medium text-gray-900 mb-4"><?php echo __('site_settings.section_analytics'); ?></h4>

                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-2"><?php echo __('site_settings.field_analytics'); ?></label>
                                <textarea name="analytics_code" rows="4"
                                          class="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:ring-blue-500 focus:border-blue-500 font-mono text-sm"
                                          placeholder="<?php echo htmlspecialchars(__('site_settings.placeholder_analytics')); ?>"><?php echo htmlspecialchars($current_settings['analytics_code']); ?></textarea>
                                <p class="mt-1 text-xs text-gray-500"><?php echo __('site_settings.analytics_help'); ?></p>
                            </div>
                        </div>

                        <!-- 提交按钮 -->
                        <div class="flex justify-end pt-6 border-t border-gray-200">
                            <button type="submit" class="inline-flex items-center px-6 py-3 border border-transparent text-base font-medium rounded-md text-white bg-blue-600 hover:bg-blue-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-blue-500">
                                <i data-lucide="save" class="w-5 h-5 mr-2"></i>
                                <?php echo __('site_settings.save_settings'); ?>
                            </button>
                        </div>
                    </form>
                </div>
            </details>

            <details id="system-update" class="settings-accordion bg-white shadow rounded-lg">
                <summary class="px-6 py-4 cursor-pointer flex items-center justify-between gap-4">
                    <div>
                        <h3 class="text-lg font-medium text-gray-900"><?php echo __('update.section_title'); ?></h3>
                        <p class="mt-1 text-sm text-gray-600"><?php echo __('update.section_desc'); ?></p>
                    </div>
                    <i data-lucide="chevron-down" class="accordion-chevron w-5 h-5 text-gray-400"></i>
                </summary>
                <div class="px-6 py-6 border-t border-gray-200 space-y-6">
                    <div class="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-4 gap-4">
                        <div class="rounded-2xl border border-gray-200 bg-gray-50 p-4">
                            <div class="text-xs uppercase tracking-wide text-gray-500"><?php echo __('update.current_version'); ?></div>
                            <div class="mt-2 text-2xl font-semibold text-gray-900"><?php echo htmlspecialchars(APP_VERSION); ?></div>
                            <div class="mt-1 text-xs text-gray-500"><?php echo htmlspecialchars(APP_VERSION_DATE); ?></div>
                        </div>
                        <div class="rounded-2xl border border-gray-200 bg-gray-50 p-4">
                            <div class="text-xs uppercase tracking-wide text-gray-500"><?php echo __('update.latest_version'); ?></div>
                            <div class="mt-2 text-2xl font-semibold text-gray-900"><?php echo htmlspecialchars($update_state['latest_version'] !== '' ? $update_state['latest_version'] : '—'); ?></div>
                            <div class="mt-1 text-xs text-gray-500"><?php echo htmlspecialchars($update_copy['release_date'] !== '' ? __('update.release_date_value', ['date' => $update_copy['release_date']]) : __('update.unchecked')); ?></div>
                        </div>
                        <div class="rounded-2xl border border-gray-200 bg-gray-50 p-4">
                            <div class="text-xs uppercase tracking-wide text-gray-500"><?php echo __('update.status_label'); ?></div>
                            <div class="mt-2">
                                <?php if ($update_state['is_update_available'] && !$update_state['is_ignored']): ?>
                                    <span class="inline-flex items-center rounded-full bg-blue-100 px-3 py-1 text-sm font-medium text-blue-700"><?php echo __('update.new_version_available'); ?></span>
                                <?php elseif ($update_state['is_ignored']): ?>
                                    <span class="inline-flex items-center rounded-full bg-gray-100 px-3 py-1 text-sm font-medium text-gray-700"><?php echo __('update.ignored'); ?></span>
                                <?php else: ?>
                                    <span class="inline-flex items-center rounded-full bg-green-100 px-3 py-1 text-sm font-medium text-green-700"><?php echo __('update.up_to_date'); ?></span>
                                <?php endif; ?>
                            </div>
                            <div class="mt-2 text-xs text-gray-500">
                                <?php echo htmlspecialchars(__('update.last_checked_at', ['time' => $update_state['last_checked_at'] ? date('Y-m-d H:i:s', (int) $update_state['last_checked_at']) : __('update.unchecked')])); ?>
                            </div>
                        </div>
                        <div class="rounded-2xl border border-gray-200 bg-gray-50 p-4">
                            <div class="text-xs uppercase tracking-wide text-gray-500"><?php echo __('update.release_type_label'); ?></div>
                            <div class="mt-2 text-lg font-semibold text-gray-900">
                                <?php echo __('update.release_type_' . $update_copy['release_type']); ?>
                            </div>
                        </div>
                    </div>

                    <div class="rounded-2xl border border-blue-100 bg-blue-50/60 p-5">
                        <div class="text-base font-semibold text-gray-900">
                            <?php echo htmlspecialchars($update_copy['title'] !== '' ? $update_copy['title'] : __('update.summary_title')); ?>
                        </div>
                        <p class="mt-2 text-sm text-gray-700">
                            <?php echo htmlspecialchars($update_copy['summary'] !== '' ? $update_copy['summary'] : __('update.summary_empty')); ?>
                        </p>
                        <?php if ($update_copy['upgrade_tip'] !== ''): ?>
                            <p class="mt-3 text-xs text-blue-800"><?php echo htmlspecialchars($update_copy['upgrade_tip']); ?></p>
                        <?php endif; ?>
                    </div>

                    <div class="flex flex-wrap items-center gap-3">
                        <form method="POST" action="<?php echo htmlspecialchars(admin_url('update-check.php')); ?>">
                            <input type="hidden" name="csrf_token" value="<?php echo generate_csrf_token(); ?>">
                            <input type="hidden" name="redirect_target" value="site-settings">
                            <button type="submit" class="inline-flex items-center rounded-lg bg-blue-600 px-4 py-2 text-sm font-medium text-white hover:bg-blue-700">
                                <i data-lucide="refresh-cw" class="mr-2 h-4 w-4"></i>
                                <?php echo __('update.check_now'); ?>
                            </button>
                        </form>
                        <?php if ($update_copy['changelog_url'] !== ''): ?>
                            <a href="<?php echo htmlspecialchars($update_copy['changelog_url']); ?>" target="_blank" rel="noopener noreferrer" class="inline-flex items-center rounded-lg border border-gray-200 bg-white px-4 py-2 text-sm font-medium text-gray-700 hover:bg-gray-100">
                                <i data-lucide="book-open" class="mr-2 h-4 w-4"></i>
                                <?php echo __('update.view_changelog'); ?>
                            </a>
                        <?php endif; ?>
                        <?php if ($update_state['is_update_available'] && !$update_state['is_ignored']): ?>
                            <form method="POST" action="<?php echo htmlspecialchars(admin_url('update-ignore.php')); ?>">
                                <input type="hidden" name="csrf_token" value="<?php echo generate_csrf_token(); ?>">
                                <input type="hidden" name="redirect_target" value="site-settings">
                                <input type="hidden" name="version" value="<?php echo htmlspecialchars($update_state['latest_version']); ?>">
                                <button type="submit" class="inline-flex items-center rounded-lg border border-gray-200 bg-white px-4 py-2 text-sm font-medium text-gray-700 hover:bg-gray-100">
                                    <i data-lucide="bell-off" class="mr-2 h-4 w-4"></i>
                                    <?php echo __('update.ignore_version'); ?>
                                </button>
                            </form>
                        <?php endif; ?>
                    </div>
                </div>
            </details>

            <details class="settings-accordion bg-white shadow rounded-lg">
                <summary class="px-6 py-4 cursor-pointer flex items-center justify-between gap-4">
                    <div>
                        <h3 class="text-lg font-medium text-gray-900"><?php echo __('site_settings.theme.section_title'); ?></h3>
                        <p class="mt-1 text-sm text-gray-600"><?php echo __('site_settings.theme.section_desc'); ?></p>
                    </div>
                    <i data-lucide="chevron-down" class="accordion-chevron w-5 h-5 text-gray-400"></i>
                </summary>
                <div class="px-6 py-6 border-t border-gray-200">
                    <form method="POST" class="space-y-5">
                        <input type="hidden" name="action" value="update_theme_settings">
                        <input type="hidden" name="csrf_token" value="<?php echo generate_csrf_token(); ?>">

                        <div class="rounded-2xl border border-blue-100 bg-blue-50/60 p-4 flex flex-col gap-1">
                            <div class="text-sm font-medium text-gray-900"><?php echo __('site_settings.theme.current_label'); ?></div>
                            <div class="text-base font-semibold text-gray-900">
                                <?php
                                $currentThemeLabel = __('site_settings.theme.default_name');
                                foreach ($available_themes as $themeOption) {
                                    if ($themeOption['id'] === $current_settings['active_theme']) {
                                        $currentThemeLabel = $themeOption['name'];
                                        break;
                                    }
                                }
                                echo htmlspecialchars($currentThemeLabel);
                                ?>
                            </div>
                            <div class="text-xs text-gray-500"><?php echo __('site_settings.theme.current_help'); ?></div>
                        </div>

                        <div class="space-y-4">
                            <label class="flex items-start gap-4 rounded-2xl border border-gray-200 bg-gray-50/70 p-4">
                                <input type="radio" name="active_theme" value="" class="mt-1 text-blue-600 focus:ring-blue-500" <?php echo $current_settings['active_theme'] === '' ? 'checked' : ''; ?>>
                                <div class="min-w-0 flex-1">
                                    <div class="text-sm font-semibold text-gray-900"><?php echo __('site_settings.theme.default_name'); ?></div>
                                    <div class="mt-1 text-sm text-gray-600"><?php echo __('site_settings.theme.default_desc'); ?></div>
                                </div>
                            </label>

                            <?php foreach ($available_themes as $themeOption): ?>
                                <?php $sampleRoutes = $themeOption['manifest']['sample_routes'] ?? []; ?>
                                <label class="flex items-start gap-4 rounded-2xl border border-gray-200 bg-white p-4">
                                    <input type="radio" name="active_theme" value="<?php echo htmlspecialchars($themeOption['id']); ?>" class="mt-1 text-blue-600 focus:ring-blue-500" <?php echo $current_settings['active_theme'] === $themeOption['id'] ? 'checked' : ''; ?>>
                                    <div class="min-w-0 flex-1">
                                        <div class="flex flex-wrap items-center gap-2">
                                            <div class="text-sm font-semibold text-gray-900"><?php echo htmlspecialchars($themeOption['name']); ?></div>
                                            <?php if ($themeOption['version'] !== ''): ?>
                                                <span class="inline-flex items-center rounded-full bg-gray-100 px-2 py-0.5 text-xs text-gray-500"><?php echo __('site_settings.theme.version_badge', ['version' => $themeOption['version']]); ?></span>
                                            <?php endif; ?>
                                            <?php if ($current_settings['active_theme'] === $themeOption['id']): ?>
                                                <span class="inline-flex items-center rounded-full bg-blue-100 px-2 py-0.5 text-xs font-medium text-blue-700"><?php echo __('site_settings.theme.active_badge'); ?></span>
                                            <?php endif; ?>
                                        </div>
                                        <div class="mt-1 text-sm text-gray-600">
                                            <?php echo htmlspecialchars($themeOption['description'] !== '' ? $themeOption['description'] : __('site_settings.theme.no_description')); ?>
                                        </div>
                                        <div class="mt-3 flex flex-wrap gap-2">
                                            <a href="<?php echo htmlspecialchars($sampleRoutes['home'] ?? geoflow_theme_preview_url($themeOption['id'], 'home')); ?>" target="_blank" rel="noopener noreferrer" class="inline-flex items-center rounded-lg border border-gray-200 bg-gray-50 px-3 py-1.5 text-xs font-medium text-gray-700 hover:bg-gray-100"><?php echo __('site_settings.theme.preview_home'); ?></a>
                                            <a href="<?php echo htmlspecialchars($sampleRoutes['category'] ?? geoflow_theme_preview_url($themeOption['id'], 'category', ['slug' => geoflow_preview_first_category_slug($db) ?? ''])); ?>" target="_blank" rel="noopener noreferrer" class="inline-flex items-center rounded-lg border border-gray-200 bg-gray-50 px-3 py-1.5 text-xs font-medium text-gray-700 hover:bg-gray-100"><?php echo __('site_settings.theme.preview_category'); ?></a>
                                            <a href="<?php echo htmlspecialchars($sampleRoutes['article'] ?? geoflow_theme_preview_url($themeOption['id'], 'article', ['slug' => geoflow_preview_latest_article_slug($db) ?? ''])); ?>" target="_blank" rel="noopener noreferrer" class="inline-flex items-center rounded-lg border border-gray-200 bg-gray-50 px-3 py-1.5 text-xs font-medium text-gray-700 hover:bg-gray-100"><?php echo __('site_settings.theme.preview_article'); ?></a>
                                            <a href="<?php echo htmlspecialchars($sampleRoutes['archive'] ?? geoflow_theme_preview_url($themeOption['id'], 'archive')); ?>" target="_blank" rel="noopener noreferrer" class="inline-flex items-center rounded-lg border border-gray-200 bg-gray-50 px-3 py-1.5 text-xs font-medium text-gray-700 hover:bg-gray-100"><?php echo __('site_settings.theme.preview_archive'); ?></a>
                                        </div>
                                    </div>
                                </label>
                            <?php endforeach; ?>
                        </div>

                        <div class="flex justify-end pt-2 border-t border-gray-200">
                            <button type="submit" class="inline-flex items-center px-6 py-3 border border-transparent text-base font-medium rounded-md text-white bg-blue-600 hover:bg-blue-700">
                                <i data-lucide="layout-template" class="w-5 h-5 mr-2"></i>
                                <?php echo __('site_settings.theme.save'); ?>
                            </button>
                        </div>
                    </form>
                </div>
            </details>

            <details class="settings-accordion bg-white shadow rounded-lg">
                <summary class="px-6 py-4 cursor-pointer flex items-center justify-between gap-4">
                    <div>
                        <h3 class="text-lg font-medium text-gray-900"><?php echo __('site_settings.ads.section_title'); ?></h3>
                        <p class="mt-1 text-sm text-gray-600"><?php echo __('site_settings.ads.section_desc'); ?></p>
                    </div>
                    <i data-lucide="chevron-down" class="accordion-chevron w-5 h-5 text-gray-400"></i>
                </summary>
                <div class="px-6 py-6 border-t border-gray-200">
                    <div class="flex items-center justify-end mb-6">
                        <button type="button" id="add-article-ad" class="inline-flex items-center px-3 py-2 border border-transparent text-sm font-medium rounded-md text-white bg-blue-600 hover:bg-blue-700">
                            <i data-lucide="plus" class="w-4 h-4 mr-2"></i>
                            <?php echo __('site_settings.ads.add'); ?>
                        </button>
                    </div>
                    <form method="POST" id="article-ad-form" class="space-y-6">
                        <input type="hidden" name="action" value="update_article_detail_ads">
                        <input type="hidden" name="csrf_token" value="<?php echo generate_csrf_token(); ?>">

                        <div class="rounded-2xl border border-blue-100 bg-blue-50/60 p-4">
                            <div class="text-sm font-medium text-gray-900"><?php echo __('site_settings.ads.preview_title'); ?></div>
                            <div class="mt-3 rounded-2xl border border-blue-200 bg-white p-4 shadow-sm">
                                <div class="flex items-center justify-between gap-4">
                                    <div class="min-w-0">
                                        <div class="inline-flex items-center rounded-full bg-blue-100 px-2.5 py-1 text-xs font-semibold text-blue-700"><?php echo __('site_settings.ads.preview_badge'); ?></div>
                                        <div class="mt-3 text-base font-semibold text-gray-900"><?php echo __('site_settings.ads.preview_heading'); ?></div>
                                        <p class="mt-1 text-sm text-gray-600"><?php echo __('site_settings.ads.preview_copy'); ?></p>
                                    </div>
                                    <button type="button" class="shrink-0 inline-flex items-center rounded-full bg-blue-600 px-4 py-2 text-sm font-semibold text-white"><?php echo __('site_settings.ads.preview_cta'); ?></button>
                                </div>
                            </div>
                        </div>

                        <div id="article-ad-list" class="space-y-5">
                            <?php foreach ($article_detail_ads as $index => $ad): ?>
                                <div class="article-ad-item rounded-2xl border border-gray-200 bg-gray-50/70 p-5" data-ad-index="<?php echo $index; ?>">
                                    <div class="flex items-center justify-between gap-4">
                                        <div>
                                            <div class="text-sm font-semibold text-gray-900"><?php echo htmlspecialchars((string) ($ad['name'] ?? __('site_settings.ads.default_name', ['index' => $index + 1]))); ?></div>
                                            <div class="mt-1 text-xs text-gray-500"><?php echo __('site_settings.ads.position_label'); ?></div>
                                        </div>
                                        <button type="button" class="remove-article-ad inline-flex items-center rounded-lg border border-red-200 bg-white px-3 py-2 text-sm font-medium text-red-600 hover:bg-red-50">
                                            <i data-lucide="trash-2" class="w-4 h-4 mr-2"></i>
                                            <?php echo __('button.delete'); ?>
                                        </button>
                                    </div>

                                    <div class="mt-5 grid grid-cols-1 md:grid-cols-2 gap-5">
                                        <input type="hidden" name="ads[<?php echo $index; ?>][id]" value="<?php echo htmlspecialchars((string) ($ad['id'] ?? '')); ?>">
                                        <div>
                                            <label class="block text-sm font-medium text-gray-700 mb-2"><?php echo __('site_settings.ads.field_name'); ?></label>
                                            <input type="text" name="ads[<?php echo $index; ?>][name]" value="<?php echo htmlspecialchars((string) ($ad['name'] ?? '')); ?>" class="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:ring-blue-500 focus:border-blue-500" placeholder="<?php echo htmlspecialchars(__('site_settings.ads.placeholder_name')); ?>">
                                        </div>
                                        <div>
                                            <label class="block text-sm font-medium text-gray-700 mb-2"><?php echo __('site_settings.ads.field_badge'); ?></label>
                                            <input type="text" name="ads[<?php echo $index; ?>][badge]" value="<?php echo htmlspecialchars((string) ($ad['badge'] ?? '')); ?>" class="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:ring-blue-500 focus:border-blue-500" placeholder="<?php echo htmlspecialchars(__('site_settings.ads.placeholder_badge')); ?>">
                                        </div>
                                    </div>

                                    <div class="mt-5">
                                        <label class="block text-sm font-medium text-gray-700 mb-2"><?php echo __('site_settings.ads.field_title'); ?></label>
                                        <input type="text" name="ads[<?php echo $index; ?>][title]" value="<?php echo htmlspecialchars((string) ($ad['title'] ?? '')); ?>" class="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:ring-blue-500 focus:border-blue-500" placeholder="<?php echo htmlspecialchars(__('site_settings.ads.placeholder_title')); ?>">
                                    </div>

                                    <div class="mt-5">
                                        <label class="block text-sm font-medium text-gray-700 mb-2"><?php echo __('site_settings.ads.field_copy'); ?></label>
                                        <textarea name="ads[<?php echo $index; ?>][copy]" rows="3" class="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:ring-blue-500 focus:border-blue-500" placeholder="<?php echo htmlspecialchars(__('site_settings.ads.placeholder_copy')); ?>"><?php echo htmlspecialchars((string) ($ad['copy'] ?? '')); ?></textarea>
                                    </div>

                                    <div class="mt-5 grid grid-cols-1 md:grid-cols-2 gap-5">
                                        <div>
                                            <label class="block text-sm font-medium text-gray-700 mb-2"><?php echo __('site_settings.ads.field_button_text'); ?></label>
                                            <input type="text" name="ads[<?php echo $index; ?>][button_text]" value="<?php echo htmlspecialchars((string) ($ad['button_text'] ?? '')); ?>" class="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:ring-blue-500 focus:border-blue-500" placeholder="<?php echo htmlspecialchars(__('site_settings.ads.placeholder_button_text')); ?>">
                                        </div>
                                        <div>
                                            <label class="block text-sm font-medium text-gray-700 mb-2"><?php echo __('site_settings.ads.field_button_url'); ?></label>
                                            <input type="text" name="ads[<?php echo $index; ?>][button_url]" value="<?php echo htmlspecialchars((string) ($ad['button_url'] ?? '')); ?>" class="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:ring-blue-500 focus:border-blue-500" placeholder="<?php echo htmlspecialchars(__('site_settings.ads.placeholder_button_url')); ?>">
                                        </div>
                                    </div>

                                    <div class="mt-5 flex items-center justify-between rounded-xl border border-gray-200 bg-white px-4 py-3">
                                        <div>
                                            <div class="text-sm font-medium text-gray-900"><?php echo __('site_settings.ads.field_enabled'); ?></div>
                                            <div class="text-xs text-gray-500"><?php echo __('site_settings.ads.enabled_help'); ?></div>
                                        </div>
                                        <label class="inline-flex items-center">
                                            <input type="checkbox" name="ads[<?php echo $index; ?>][enabled]" value="1" <?php echo !empty($ad['enabled']) ? 'checked' : ''; ?> class="rounded border-gray-300 text-blue-600 shadow-sm focus:border-blue-300 focus:ring focus:ring-blue-200 focus:ring-opacity-50">
                                        </label>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>

                        <div id="article-ad-empty" class="<?php echo !empty($article_detail_ads) ? 'hidden ' : ''; ?>rounded-2xl border border-dashed border-gray-300 bg-gray-50 px-6 py-10 text-center">
                            <div class="text-base font-medium text-gray-900"><?php echo __('site_settings.ads.empty_title'); ?></div>
                            <div class="mt-2 text-sm text-gray-500"><?php echo __('site_settings.ads.empty_desc'); ?></div>
                        </div>

                        <div class="flex justify-end pt-2 border-t border-gray-200">
                            <button type="submit" class="inline-flex items-center px-6 py-3 border border-transparent text-base font-medium rounded-md text-white bg-blue-600 hover:bg-blue-700">
                                <i data-lucide="save" class="w-5 h-5 mr-2"></i>
                                <?php echo __('site_settings.ads.save'); ?>
                            </button>
                        </div>
                    </form>
                </div>
            </details>
            </div>

<?php
// 包含统一底部
require_once __DIR__ . '/includes/footer.php';
?>
<template id="article-ad-template">
    <div class="article-ad-item rounded-2xl border border-gray-200 bg-gray-50/70 p-5" data-ad-index="__INDEX__">
        <div class="flex items-center justify-between gap-4">
            <div>
                <div class="text-sm font-semibold text-gray-900"><?php echo __('site_settings.ads.new_slot'); ?></div>
                <div class="mt-1 text-xs text-gray-500"><?php echo __('site_settings.ads.position_label'); ?></div>
            </div>
            <button type="button" class="remove-article-ad inline-flex items-center rounded-lg border border-red-200 bg-white px-3 py-2 text-sm font-medium text-red-600 hover:bg-red-50">
                <i data-lucide="trash-2" class="w-4 h-4 mr-2"></i>
                <?php echo __('button.delete'); ?>
            </button>
        </div>

        <div class="mt-5 grid grid-cols-1 md:grid-cols-2 gap-5">
            <input type="hidden" name="ads[__INDEX__][id]" value="">
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-2"><?php echo __('site_settings.ads.field_name'); ?></label>
                <input type="text" name="ads[__INDEX__][name]" value="" class="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:ring-blue-500 focus:border-blue-500" placeholder="<?php echo htmlspecialchars(__('site_settings.ads.placeholder_name')); ?>">
            </div>
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-2"><?php echo __('site_settings.ads.field_badge'); ?></label>
                <input type="text" name="ads[__INDEX__][badge]" value="" class="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:ring-blue-500 focus:border-blue-500" placeholder="<?php echo htmlspecialchars(__('site_settings.ads.placeholder_badge')); ?>">
            </div>
        </div>

        <div class="mt-5">
            <label class="block text-sm font-medium text-gray-700 mb-2"><?php echo __('site_settings.ads.field_title'); ?></label>
            <input type="text" name="ads[__INDEX__][title]" value="" class="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:ring-blue-500 focus:border-blue-500" placeholder="<?php echo htmlspecialchars(__('site_settings.ads.placeholder_title')); ?>">
        </div>

        <div class="mt-5">
            <label class="block text-sm font-medium text-gray-700 mb-2"><?php echo __('site_settings.ads.field_copy'); ?></label>
            <textarea name="ads[__INDEX__][copy]" rows="3" class="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:ring-blue-500 focus:border-blue-500" placeholder="<?php echo htmlspecialchars(__('site_settings.ads.placeholder_copy')); ?>"></textarea>
        </div>

        <div class="mt-5 grid grid-cols-1 md:grid-cols-2 gap-5">
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-2"><?php echo __('site_settings.ads.field_button_text'); ?></label>
                <input type="text" name="ads[__INDEX__][button_text]" value="" class="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:ring-blue-500 focus:border-blue-500" placeholder="<?php echo htmlspecialchars(__('site_settings.ads.placeholder_button_text')); ?>">
            </div>
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-2"><?php echo __('site_settings.ads.field_button_url'); ?></label>
                <input type="text" name="ads[__INDEX__][button_url]" value="" class="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:ring-blue-500 focus:border-blue-500" placeholder="<?php echo htmlspecialchars(__('site_settings.ads.placeholder_button_url')); ?>">
            </div>
        </div>

        <div class="mt-5 flex items-center justify-between rounded-xl border border-gray-200 bg-white px-4 py-3">
            <div>
                <div class="text-sm font-medium text-gray-900"><?php echo __('site_settings.ads.field_enabled'); ?></div>
                <div class="text-xs text-gray-500"><?php echo __('site_settings.ads.enabled_help'); ?></div>
            </div>
            <label class="inline-flex items-center">
                <input type="checkbox" name="ads[__INDEX__][enabled]" value="1" checked class="rounded border-gray-300 text-blue-600 shadow-sm focus:border-blue-300 focus:ring focus:ring-blue-200 focus:ring-opacity-50">
            </label>
        </div>
    </div>
</template>
<script>
document.addEventListener('DOMContentLoaded', function () {
    const adList = document.getElementById('article-ad-list');
    const emptyState = document.getElementById('article-ad-empty');
    const addButton = document.getElementById('add-article-ad');
    const template = document.getElementById('article-ad-template');

    if (!adList || !emptyState || !addButton || !template) {
        return;
    }

    let adIndex = adList.querySelectorAll('.article-ad-item').length;

    function refreshState() {
        emptyState.classList.toggle('hidden', adList.querySelectorAll('.article-ad-item').length > 0);
    }

    function bindRemove(scope) {
        const removeButton = scope.querySelector('.remove-article-ad');
        if (!removeButton) {
            return;
        }

        removeButton.addEventListener('click', function () {
            scope.remove();
            refreshState();
        });
    }

    addButton.addEventListener('click', function () {
        const wrapper = document.createElement('div');
        wrapper.innerHTML = template.innerHTML.replaceAll('__INDEX__', String(adIndex)).trim();
        adIndex += 1;
        const adItem = wrapper.firstElementChild;
        if (!adItem) {
            return;
        }

        adList.appendChild(adItem);
        bindRemove(adItem);
        refreshState();

        if (typeof lucide !== 'undefined') {
            lucide.createIcons();
        }
    });

    adList.querySelectorAll('.article-ad-item').forEach(bindRemove);
    refreshState();
});
</script>
