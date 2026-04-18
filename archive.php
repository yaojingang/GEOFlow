<?php
define('FEISHU_TREASURE', true);
session_start();

require_once 'includes/config.php';
require_once 'includes/database.php';
require_once 'includes/functions.php';
require_once 'includes/seo_functions.php';
require_once 'includes/theme_preview.php';

$database = Database::getInstance();
$db = $database->getPDO();

$year = clean_input($_GET['year'] ?? '');
$month = clean_input($_GET['month'] ?? '');
$page = max(1, intval($_GET['page'] ?? 1));
$per_page = max(1, intval(site_setting_value('per_page', 12)));

$site_title = site_setting_value('site_name', SITE_NAME);
$site_description = site_setting_value('site_description', SITE_DESCRIPTION);
$categories = get_categories();

if (!empty($year) && !empty($month)) {
    if (!preg_match('/^\d{4}$/', $year) || !preg_match('/^\d{2}$/', $month)) {
        header('HTTP/1.0 404 Not Found');
        exit(__('front.archive.error.invalid_date'));
    }

    $count_stmt = $db->prepare("
        SELECT COUNT(*)
        FROM articles
        WHERE status = 'published'
          AND deleted_at IS NULL
          AND EXTRACT(YEAR FROM COALESCE(published_at, created_at)) = ?
          AND EXTRACT(MONTH FROM COALESCE(published_at, created_at)) = ?
    ");
    $count_stmt->execute([(int) $year, (int) $month]);
    $total_count = intval($count_stmt->fetchColumn());
    $total_pages = max(1, (int) ceil($total_count / $per_page));
    $offset = ($page - 1) * $per_page;

    $stmt = $db->prepare("
        SELECT a.*, c.name as category_name, c.slug as category_slug, au.name as author_name
        FROM articles a
        LEFT JOIN categories c ON a.category_id = c.id
        LEFT JOIN authors au ON a.author_id = au.id
        WHERE a.status = 'published'
          AND a.deleted_at IS NULL
          AND EXTRACT(YEAR FROM COALESCE(a.published_at, a.created_at)) = ?
          AND EXTRACT(MONTH FROM COALESCE(a.published_at, a.created_at)) = ?
        ORDER BY COALESCE(a.published_at, a.created_at) DESC
        LIMIT ? OFFSET ?
    ");
    $stmt->execute([(int) $year, (int) $month, $per_page, $offset]);
    $articles = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $archive_title = app_locale() === 'en'
        ? date('F Y', strtotime("{$year}-{$month}-01"))
        : "{$year}年{$month}月";
    $page_title = generate_page_title(__('front.archive.page_title_month', ['period' => $archive_title]), __('front.archive.title'), $site_title);
    $page_description = generate_page_description(__('front.archive.page_description_month', ['period' => $archive_title, 'site' => $site_description]));
} else {
    $stmt = $db->query("
        SELECT
            EXTRACT(YEAR FROM COALESCE(published_at, created_at))::text as year,
            LPAD(EXTRACT(MONTH FROM COALESCE(published_at, created_at))::text, 2, '0') as month,
            COUNT(*) as count
        FROM articles
        WHERE status = 'published'
          AND deleted_at IS NULL
        GROUP BY year, month
        ORDER BY year DESC, month DESC
    ");
    $archives = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $archive_title = __('front.archive.page_title');
    $page_title = generate_page_title($archive_title, __('front.archive.title'), $site_title);
    $page_description = generate_page_description(__('front.archive.page_description', ['site' => $site_description]));
}

$canonical_url = !empty($year) && !empty($month)
    ? geo_absolute_url('archive/' . $year . '/' . $month)
    : geo_absolute_url('archive');

$summary_source = !empty($year) && !empty($month) ? $articles : $archives;
$archive_summary = build_collection_geo_summary(
    $archive_title,
    $page_description,
    $summary_source,
    [
        __('front.archive.summary.type_label') => !empty($year) && !empty($month)
            ? __('front.archive.summary.month_type')
            : __('front.archive.summary.overview_type'),
        __('front.archive.summary.page_label') => (string) $page
    ]
);

$collection_items = [];
if (!empty($year) && !empty($month)) {
    foreach (array_slice($articles, 0, 10) as $item) {
        $collection_items[] = [
            "@type" => "Article",
            "headline" => $item['title'],
            "url" => geo_absolute_url('article/' . $item['slug'])
        ];
    }
}

$structured_data_blocks = [
    generate_website_structured_data(),
    generate_collection_structured_data($archive_title, $page_description, $canonical_url, $collection_items, 'CollectionPage'),
    generate_breadcrumb_structured_data(array_values(array_filter([
        ['name' => __('front.nav.home'), 'url' => geo_absolute_url('/')],
        ['name' => __('front.archive.title'), 'url' => geo_absolute_url('archive')],
        (!empty($year) && !empty($month)) ? ['name' => $archive_title, 'url' => $canonical_url] : null
    ])))
];

$active_theme_id = geoflow_active_theme_id();
if ($active_theme_id !== '' && geoflow_theme_has_template($active_theme_id, 'archive')) {
    geoflow_theme_render_document(
        $active_theme_id,
        'archive',
        compact('year', 'month', 'archive_title', 'articles', 'archives', 'total_count', 'total_pages', 'page'),
        [
            'site_title' => $site_title,
            'site_description' => $site_description,
            'categories' => $categories,
            'page_title' => $page_title,
            'page_description' => $page_description,
            'page_keywords' => __('front.archive.meta_keywords'),
            'canonical_url' => $canonical_url,
            'structured_data_blocks' => $structured_data_blocks,
            'og_type' => 'website',
        ],
        [
            'route_mode' => 'live',
            'page_key' => 'archive',
        ]
    );
    exit;
}
?>
<!DOCTYPE html>
<html lang="<?php echo htmlspecialchars(app_html_lang(), ENT_QUOTES); ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($page_title); ?></title>
    <meta name="description" content="<?php echo htmlspecialchars($page_description); ?>">
    <meta name="keywords" content="<?php echo htmlspecialchars(__('front.archive.meta_keywords')); ?>">
    <link rel="canonical" href="<?php echo htmlspecialchars($canonical_url); ?>">
    <meta property="og:title" content="<?php echo htmlspecialchars($page_title); ?>">
    <meta property="og:description" content="<?php echo htmlspecialchars($page_description); ?>">
    <meta property="og:type" content="website">
    <meta property="og:url" content="<?php echo htmlspecialchars($canonical_url); ?>">
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="/assets/css/style.css">
    <link rel="stylesheet" href="/assets/css/custom.css">
    <script src="https://unpkg.com/lucide@latest/dist/umd/lucide.js"></script>
    <?php output_site_head_extras(); ?>
    <?php output_structured_data_blocks($structured_data_blocks); ?>
</head>
<body class="bg-white">
    <?php include 'includes/header.php'; ?>

    <main class="site-container px-4 sm:px-6 lg:px-8 py-8">
        <nav class="flex items-center space-x-2 text-sm text-gray-500 mb-8">
            <a href="/" class="hover:text-gray-700"><?php echo __('front.nav.home'); ?></a>
            <i data-lucide="chevron-right" class="w-4 h-4"></i>
            <?php if (!empty($year) && !empty($month)): ?>
                <a href="/archive" class="hover:text-gray-700"><?php echo __('front.archive.title'); ?></a>
                <i data-lucide="chevron-right" class="w-4 h-4"></i>
                <span class="text-gray-900"><?php echo htmlspecialchars($archive_title); ?></span>
            <?php else: ?>
                <span class="text-gray-900"><?php echo __('front.archive.title'); ?></span>
            <?php endif; ?>
        </nav>

        <div class="article-shell page-intro-card mb-8 text-center">
            <div class="inline-flex items-center justify-center w-16 h-16 bg-gray-100 rounded-full mb-4">
                <i data-lucide="archive" class="w-8 h-8 text-gray-600"></i>
            </div>
            <h1 class="page-intro-title font-bold text-gray-900 mb-2"><?php echo htmlspecialchars($archive_title); ?></h1>
            <?php if (!empty($year) && !empty($month)): ?>
                <p class="text-gray-600"><?php echo __('front.archive.month_description', ['count' => $total_count]); ?></p>
            <?php else: ?>
                <p class="text-gray-600"><?php echo __('front.archive.overview_description'); ?></p>
            <?php endif; ?>
        </div>

        <?php if (!empty($year) && !empty($month)): ?>
            <?php if (empty($articles)): ?>
                <div class="article-shell p-12 text-center">
                    <div class="w-16 h-16 bg-gray-100 rounded-full flex items-center justify-center mx-auto mb-4">
                        <i data-lucide="file-text" class="w-8 h-8 text-gray-400"></i>
                    </div>
                    <h3 class="text-xl font-semibold text-gray-900 mb-2"><?php echo __('front.articles.empty_title'); ?></h3>
                    <p class="text-gray-500 mb-6"><?php echo __('front.archive.month_empty_description'); ?></p>
                    <a href="/archive" class="inline-flex items-center px-4 py-2 bg-gray-900 text-white rounded-lg">
                        <i data-lucide="arrow-left" class="w-4 h-4 mr-2"></i>
                        <?php echo __('front.back_archive'); ?>
                    </a>
                </div>
            <?php else: ?>
                <div class="space-y-8">
                    <?php foreach ($articles as $article): ?>
                        <article class="article-shell entry-card">
                            <div class="p-6">
                                <div class="flex items-center justify-between mb-4">
                                    <div class="flex items-center space-x-2">
                                        <?php if (!empty($article['category_name'])): ?>
                                            <a href="/category/<?php echo htmlspecialchars($article['category_slug'] ?: $article['category_id']); ?>" class="pill-tag">
                                                <?php echo htmlspecialchars($article['category_name']); ?>
                                            </a>
                                        <?php endif; ?>
                                        <?php if (!empty($article['is_featured'])): ?>
                                            <span class="pill-tag">
                                                <i data-lucide="star" class="w-3 h-3 mr-1"></i>
                                                <?php echo __('front.home.featured_badge'); ?>
                                            </span>
                                        <?php endif; ?>
                                    </div>
                                    <time class="text-sm text-gray-500" datetime="<?php echo htmlspecialchars($article['published_at'] ?: $article['created_at']); ?>">
                                        <?php echo app_locale() === 'en'
                                            ? date('M j', strtotime($article['published_at'] ?: $article['created_at']))
                                            : date('m月d日', strtotime($article['published_at'] ?: $article['created_at'])); ?>
                                    </time>
                                </div>

                                <h2 class="entry-title font-semibold text-gray-900 mb-3">
                                    <a href="/article/<?php echo htmlspecialchars($article['slug']); ?>" class="hover:text-blue-600">
                                        <?php echo htmlspecialchars($article['title']); ?>
                                    </a>
                                </h2>

                                <p class="entry-summary mb-4 leading-relaxed">
                                    <?php echo htmlspecialchars(!empty($article['excerpt']) ? $article['excerpt'] : mb_substr(strip_tags($article['content']), 0, 120, 'UTF-8') . '...'); ?>
                                </p>

                                <div class="flex flex-col sm:flex-row sm:items-center sm:justify-end gap-3">
                                    <a href="/article/<?php echo htmlspecialchars($article['slug']); ?>" class="read-more-btn self-start sm:self-center">
                                        <?php echo __('front.home.read_more'); ?>
                                        <i data-lucide="arrow-right" class="w-4 h-4 ml-1"></i>
                                    </a>
                                </div>
                            </div>
                        </article>
                    <?php endforeach; ?>
                </div>

                <?php if ($total_pages > 1): ?>
                    <div class="mt-12">
                        <?php echo generate_pagination($page, $total_pages, '/archive/' . urlencode($year) . '/' . urlencode($month) . '?page='); ?>
                    </div>
                <?php endif; ?>
            <?php endif; ?>
        <?php else: ?>
            <?php if (empty($archives)): ?>
                <div class="article-shell p-12 text-center">
                    <div class="w-16 h-16 bg-gray-100 rounded-full flex items-center justify-center mx-auto mb-4">
                        <i data-lucide="file-text" class="w-8 h-8 text-gray-400"></i>
                    </div>
                    <h3 class="text-xl font-semibold text-gray-900 mb-2"><?php echo __('front.archive.empty_title'); ?></h3>
                    <p class="text-gray-500 mb-6"><?php echo __('front.archive.empty_description'); ?></p>
                    <a href="/" class="inline-flex items-center px-4 py-2 bg-gray-900 text-white rounded-lg">
                        <i data-lucide="arrow-left" class="w-4 h-4 mr-2"></i>
                        <?php echo __('front.back_home'); ?>
                    </a>
                </div>
            <?php else: ?>
                <div class="article-shell overflow-hidden">
                    <div class="divide-y divide-gray-100">
                        <?php $current_year = null; ?>
                        <?php foreach ($archives as $archive): ?>
                            <?php if ($current_year !== $archive['year']): ?>
                                <?php if ($current_year !== null): ?>
                                    </div>
                                </div>
                                <?php endif; ?>
                                <div class="p-6">
                                    <h2 class="text-2xl font-bold text-gray-900 mb-4"><?php echo htmlspecialchars($archive['year']); ?><?php echo app_locale() === 'en' ? '' : '年'; ?></h2>
                                    <div class="space-y-3">
                                <?php $current_year = $archive['year']; ?>
                            <?php endif; ?>

                            <a href="/archive/<?php echo htmlspecialchars($archive['year']); ?>/<?php echo htmlspecialchars($archive['month']); ?>" class="archive-row flex items-center justify-between p-4 rounded-lg hover:bg-gray-50 transition-colors group">
                                <div class="flex items-center space-x-3">
                                    <div class="w-10 h-10 bg-gray-100 rounded-lg flex items-center justify-center group-hover:bg-gray-200 transition-colors">
                                        <i data-lucide="calendar" class="w-5 h-5 text-gray-600"></i>
                                    </div>
                                    <div>
                                        <h3 class="font-medium text-gray-900"><?php echo app_locale() === 'en'
                                            ? date('F', strtotime('2000-' . $archive['month'] . '-01'))
                                            : intval($archive['month']) . '月'; ?></h3>
                                        <p class="text-sm text-gray-500"><?php echo __('front.archive.article_count', ['count' => intval($archive['count'])]); ?></p>
                                    </div>
                                </div>
                                <i data-lucide="chevron-right" class="w-5 h-5 text-gray-400 group-hover:text-gray-600 transition-colors"></i>
                            </a>
                        <?php endforeach; ?>
                        <?php if ($current_year !== null): ?>
                            </div>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endif; ?>
        <?php endif; ?>
    </main>

    <?php include 'includes/footer.php'; ?>

    <script>
    document.addEventListener('DOMContentLoaded', function() {
        if (typeof lucide !== 'undefined') {
            lucide.createIcons();
        }
    });
    </script>
</body>
</html>
