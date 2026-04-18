<?php

if (!defined('FEISHU_TREASURE')) {
    exit('Access denied');
}

function geoflow_theme_set_route_mode(string $mode): void {
    $allowed = ['preview', 'live'];
    $GLOBALS['geoflow_theme_route_mode'] = in_array($mode, $allowed, true) ? $mode : 'preview';
}

function geoflow_theme_route_mode(): string {
    return $GLOBALS['geoflow_theme_route_mode'] ?? 'preview';
}

function geoflow_themes_root(): string {
    return __DIR__ . '/../themes';
}

function geoflow_theme_path(string $themeId, string $relative = ''): string {
    $base = geoflow_themes_root() . '/' . trim($themeId, '/');
    if ($relative === '') {
        return $base;
    }

    return $base . '/' . ltrim($relative, '/');
}

function geoflow_theme_exists(string $themeId): bool {
    return is_dir(geoflow_theme_path($themeId));
}

function geoflow_discover_themes(): array {
    $themes = [];
    $root = geoflow_themes_root();
    if (!is_dir($root)) {
        return $themes;
    }

    $items = scandir($root);
    if ($items === false) {
        return $themes;
    }

    foreach ($items as $item) {
        if ($item === '.' || $item === '..') {
            continue;
        }

        if (!is_dir($root . '/' . $item)) {
            continue;
        }

        $manifest = geoflow_theme_manifest($item);
        $themes[] = [
            'id' => $item,
            'manifest' => $manifest,
            'name' => $manifest['name'] ?? $item,
            'description' => $manifest['description'] ?? '',
            'version' => $manifest['version'] ?? ''
        ];
    }

    usort($themes, static function (array $a, array $b): int {
        return strcmp($a['name'], $b['name']);
    });

    return $themes;
}

function geoflow_active_theme_id(): string {
    $themeId = trim((string) site_setting_value('active_theme', ''));
    return geoflow_theme_exists($themeId) ? $themeId : '';
}

function geoflow_theme_manifest(string $themeId): array {
    static $cache = [];

    if (isset($cache[$themeId])) {
        return $cache[$themeId];
    }

    $file = geoflow_theme_path($themeId, 'manifest.json');
    if (!is_file($file)) {
        return $cache[$themeId] = [];
    }

    $decoded = json_decode((string) file_get_contents($file), true);
    return $cache[$themeId] = is_array($decoded) ? $decoded : [];
}

function geoflow_theme_asset_url(string $themeId, string $relative): string {
    return '/themes/' . rawurlencode($themeId) . '/' . ltrim($relative, '/');
}

function geoflow_theme_page_url(string $themeId, string $page = 'home', array $args = []): string {
    if (geoflow_theme_route_mode() === 'live') {
        if ($page === 'article') {
            return '/article/' . rawurlencode((string) ($args['slug'] ?? ''));
        }

        if ($page === 'category') {
            $base = '/category/' . rawurlencode((string) ($args['slug'] ?? ''));
            if (!empty($args['page']) && (int) $args['page'] > 1) {
                $base .= '?page=' . rawurlencode((string) $args['page']);
            }
            return $base;
        }

        if ($page === 'archive_month') {
            $base = '/archive/' . rawurlencode((string) ($args['year'] ?? '')) . '/' . rawurlencode((string) ($args['month'] ?? ''));
            if (!empty($args['page']) && (int) $args['page'] > 1) {
                $base .= '?page=' . rawurlencode((string) $args['page']);
            }
            return $base;
        }

        if ($page === 'archive') {
            return '/archive';
        }

        $query = [];
        if (!empty($args['search'])) {
            $query['search'] = (string) $args['search'];
        }
        if (!empty($args['category'])) {
            $query['category'] = (string) $args['category'];
        }
        if (!empty($args['page']) && (int) $args['page'] > 1) {
            $query['page'] = (string) $args['page'];
        }

        return '/' . ($query ? '?' . http_build_query($query) : '');
    }

    $base = '/preview/' . rawurlencode($themeId);

    if ($page === 'article') {
        $slug = $args['slug'] ?? '';
        return $base . '/article/' . rawurlencode((string) $slug);
    }

    if ($page === 'category') {
        $slug = $args['slug'] ?? '';
        return $base . '/category/' . rawurlencode((string) $slug);
    }

    if ($page === 'archive_month') {
        return $base . '/archive/' . rawurlencode((string) ($args['year'] ?? '')) . '/' . rawurlencode((string) ($args['month'] ?? ''));
    }

    if ($page === 'archive') {
        return $base . '/archive';
    }

    $query = [];
    if (!empty($args['search'])) {
        $query['search'] = (string) $args['search'];
    }
    if (!empty($args['category'])) {
        $query['category'] = (string) $args['category'];
    }
    if (!empty($args['page']) && (int) $args['page'] > 1) {
        $query['page'] = (string) $args['page'];
    }

    return $base . '/' . ($query ? '?' . http_build_query($query) : '');
}

function geoflow_theme_preview_url(string $themeId, string $page = 'home', array $args = []): string {
    return geoflow_theme_page_url($themeId, $page, $args);
}

function geoflow_preview_latest_article_slug(PDO $db): ?string {
    $stmt = $db->query("
        SELECT slug
        FROM articles
        WHERE status = 'published'
          AND deleted_at IS NULL
        ORDER BY COALESCE(published_at, created_at) DESC, id DESC
        LIMIT 1
    ");
    $slug = $stmt ? $stmt->fetchColumn() : null;
    return $slug ? (string) $slug : null;
}

function geoflow_preview_first_category_slug(PDO $db): ?string {
    $stmt = $db->query("
        SELECT COALESCE(NULLIF(slug, ''), id::text)
        FROM categories
        ORDER BY id ASC
        LIMIT 1
    ");
    $slug = $stmt ? $stmt->fetchColumn() : null;
    return $slug ? (string) $slug : null;
}

function geoflow_preview_public_date(string $datetime, string $mode = 'full'): string {
    $timestamp = strtotime($datetime);
    if (!$timestamp) {
        return '';
    }

    if (app_locale() === 'en') {
        return $mode === 'short' ? date('M j', $timestamp) : date('M j, Y', $timestamp);
    }

    return $mode === 'short' ? date('m月d日', $timestamp) : date('Y年m月d日', $timestamp);
}

function geoflow_theme_summary_text(array $article, int $maxLength = 160): string {
    $excerpt = trim((string) ($article['excerpt'] ?? ''));
    if ($excerpt !== '') {
        return clean_markdown_for_summary($excerpt, $maxLength);
    }

    return clean_markdown_for_summary((string) ($article['content'] ?? ''), $maxLength);
}

function geoflow_theme_render(string $themeId, string $template, array $vars = []): void {
    $file = geoflow_theme_path($themeId, 'templates/' . $template . '.php');
    if (!is_file($file)) {
        throw new RuntimeException('Theme template not found: ' . $template);
    }

    extract($vars, EXTR_SKIP);
    require $file;
}

function geoflow_theme_has_template(string $themeId, string $template): bool {
    return is_file(geoflow_theme_path($themeId, 'templates/' . $template . '.php'));
}

function geoflow_theme_render_document(string $themeId, string $pageTemplate, array $templateVars, array $meta = [], array $runtime = []): void {
    geoflow_theme_set_route_mode($runtime['route_mode'] ?? 'preview');

    $site_title = $meta['site_title'] ?? site_setting_value('site_name', SITE_NAME);
    $site_subtitle = $meta['site_subtitle'] ?? site_setting_value('site_subtitle', '');
    $site_description = $meta['site_description'] ?? site_setting_value('site_description', SITE_DESCRIPTION);
    $site_logo = $meta['site_logo'] ?? site_setting_value('site_logo', '');
    $categories = $meta['categories'] ?? get_categories();
    $page_title = $meta['page_title'] ?? $site_title;
    $page_description = $meta['page_description'] ?? $site_description;
    $page_keywords = $meta['page_keywords'] ?? site_setting_value('site_keywords', SITE_KEYWORDS);
    $canonical_url = $meta['canonical_url'] ?? geo_absolute_url('/');
    $structured_data_blocks = $meta['structured_data_blocks'] ?? [];
    $og_type = $meta['og_type'] ?? 'website';
    $page_key = $runtime['page_key'] ?? 'home';
    $is_preview = ($runtime['route_mode'] ?? 'preview') === 'preview';
    $theme_css_url = geoflow_theme_asset_url($themeId, 'assets/theme.css');
    $footer_copyright_text = $meta['footer_copyright_text']
        ?? site_setting_value(
            'copyright_info',
            site_setting_value(
                'copyright_text',
                app_locale() === 'en'
                    ? '© 2025 GEO+AI Content System. All rights reserved.'
                    : '© 2025 GEO+AI内容生成系统。保留所有权利。'
            )
        );

    ?>
<!DOCTYPE html>
<html lang="<?php echo htmlspecialchars(app_html_lang(), ENT_QUOTES); ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($page_title); ?></title>
    <meta name="description" content="<?php echo htmlspecialchars($page_description); ?>">
    <meta name="keywords" content="<?php echo htmlspecialchars($page_keywords); ?>">
    <?php if ($is_preview): ?>
    <meta name="robots" content="noindex,nofollow">
    <meta name="theme-preview" content="<?php echo htmlspecialchars($themeId); ?>">
    <?php endif; ?>
    <link rel="canonical" href="<?php echo htmlspecialchars($canonical_url); ?>">
    <meta property="og:title" content="<?php echo htmlspecialchars($page_title); ?>">
    <meta property="og:description" content="<?php echo htmlspecialchars($page_description); ?>">
    <meta property="og:type" content="<?php echo htmlspecialchars($og_type); ?>">
    <meta property="og:url" content="<?php echo htmlspecialchars($canonical_url); ?>">
    <meta property="og:site_name" content="<?php echo htmlspecialchars($site_title); ?>">
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="/assets/css/style.css">
    <link rel="stylesheet" href="/assets/css/custom.css">
    <link rel="stylesheet" href="<?php echo htmlspecialchars($theme_css_url); ?>">
    <script src="https://unpkg.com/lucide@latest/dist/umd/lucide.js"></script>
    <?php output_site_head_extras(); ?>
    <?php output_structured_data_blocks($structured_data_blocks); ?>
</head>
<body class="qiaomu-theme theme-<?php echo $is_preview ? 'preview' : 'live'; ?> theme-<?php echo htmlspecialchars($themeId); ?>">
<?php
    geoflow_theme_render($themeId, 'header', [
        'site_title' => $site_title,
        'site_logo' => $site_logo,
        'categories' => $categories,
        'preview_page' => $page_key,
        'theme_id' => $themeId
    ]);

    geoflow_theme_render($themeId, $pageTemplate, array_merge($templateVars, [
        'theme_id' => $themeId,
        'site_title' => $site_title,
        'site_subtitle' => $site_subtitle,
        'site_description' => $site_description
    ]));

    geoflow_theme_render($themeId, 'footer', [
        'footer_copyright_text' => $footer_copyright_text
    ]);
?>
    <script>
    if (typeof lucide !== 'undefined') {
        lucide.createIcons();
    }
    </script>
</body>
</html>
<?php
}
