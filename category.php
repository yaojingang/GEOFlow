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

$category_slug = clean_input($_GET['slug'] ?? '');
$category_id = intval($_GET['id'] ?? 0);
$page = max(1, intval($_GET['page'] ?? 1));
$per_page = max(1, intval(site_setting_value('per_page', 12)));

if (!empty($category_slug)) {
    $stmt = $db->prepare("SELECT * FROM categories WHERE slug = ? LIMIT 1");
    $stmt->execute([$category_slug]);
    $category = $stmt->fetch(PDO::FETCH_ASSOC);
} else {
    $category = get_category_by_id($category_id);
}

if (!$category) {
    header('HTTP/1.0 404 Not Found');
    exit(__('front.category.error.not_found'));
}

$site_title = site_setting_value('site_name', SITE_NAME);
$site_description = site_setting_value('site_description', SITE_DESCRIPTION);
$site_keywords = site_setting_value('site_keywords', SITE_KEYWORDS);
$categories = get_categories();
$articles = get_articles_by_category($category['id'], $page, $per_page);
$total_count = get_category_article_count($category['id']);
$total_pages = max(1, (int) ceil($total_count / $per_page));
$page_title = generate_page_title($category['name'], $category['name'], $site_title);
$page_description = generate_page_description((!empty($category['description']) ? $category['description'] : __('front.category.meta_fallback', ['name' => $category['name']])) . ' - ' . $site_description);
$page_keywords = generate_page_keywords($site_keywords, $category['name']);
$canonical_url = geo_absolute_url('category/' . ($category['slug'] ?: $category['id']));
$category_summary = build_collection_geo_summary(
    $category['name'],
    $page_description,
    $articles,
    [
        __('front.category.summary.name_label') => $category['name'],
        __('front.category.summary.count_label') => (string) $total_count,
        __('front.category.summary.page_label') => (string) $page
    ]
);

$structured_data_blocks = [
    generate_website_structured_data(),
    generate_category_structured_data($category, $articles, $total_count),
    generate_breadcrumb_structured_data([
        ['name' => __('front.nav.home'), 'url' => geo_absolute_url('/')],
        ['name' => $category['name'], 'url' => $canonical_url]
    ])
];

$active_theme_id = geoflow_active_theme_id();
if ($active_theme_id !== '' && geoflow_theme_has_template($active_theme_id, 'category')) {
    geoflow_theme_render_document(
        $active_theme_id,
        'category',
        compact('category', 'articles', 'total_count', 'total_pages', 'page'),
        [
            'site_title' => $site_title,
            'site_description' => $site_description,
            'categories' => $categories,
            'page_title' => $page_title,
            'page_description' => $page_description,
            'page_keywords' => $page_keywords,
            'canonical_url' => $canonical_url,
            'structured_data_blocks' => $structured_data_blocks,
            'og_type' => 'website',
        ],
        [
            'route_mode' => 'live',
            'page_key' => 'category',
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
    <meta name="keywords" content="<?php echo htmlspecialchars($page_keywords); ?>">
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

    <main class="site-container channel-page px-4 sm:px-6 lg:px-8 py-8 lg:py-10">
        <nav class="flex items-center space-x-2 text-sm text-gray-500 mb-8">
            <a href="/" class="hover:text-gray-700"><?php echo __('front.nav.home'); ?></a>
            <i data-lucide="chevron-right" class="w-4 h-4"></i>
            <span class="text-gray-900"><?php echo htmlspecialchars($category['name']); ?></span>
        </nav>

        <?php if (empty($articles)): ?>
            <div class="article-shell p-12 text-center">
                <div class="w-16 h-16 bg-gray-100 rounded-full flex items-center justify-center mx-auto mb-4">
                    <i data-lucide="file-text" class="w-8 h-8 text-gray-400"></i>
                </div>
                <h3 class="text-xl font-semibold text-gray-900 mb-2"><?php echo __('front.articles.empty_title'); ?></h3>
                <p class="text-gray-600 mb-6"><?php echo __('front.category.empty_description'); ?></p>
                <a href="/" class="inline-flex items-center px-4 py-2 bg-gray-900 text-white rounded-lg">
                    <i data-lucide="arrow-left" class="w-4 h-4 mr-2"></i>
                    <?php echo __('front.back_home'); ?>
                </a>
            </div>
        <?php else: ?>
            <div class="space-y-6">
                <?php foreach ($articles as $article): ?>
                    <article class="article-shell entry-card category-article-shell">
                        <div class="p-7 lg:p-8">
                            <div class="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between mb-5">
                                <div class="flex flex-wrap items-center gap-2">
                                    <?php if (!empty($article['is_featured'])): ?>
                                        <span class="pill-tag">
                                            <i data-lucide="star" class="w-3 h-3 mr-1"></i>
                                            <?php echo __('front.home.featured_badge'); ?>
                                        </span>
                                    <?php endif; ?>
                                    <span class="pill-tag">
                                        <?php echo htmlspecialchars($category['name']); ?>
                                    </span>
                                </div>
                                <time class="text-sm text-gray-500 sm:pl-4 sm:border-l sm:border-gray-200" datetime="<?php echo htmlspecialchars($article['published_at'] ?: $article['created_at']); ?>">
                                    <?php echo app_locale() === 'en'
                                        ? date('M j, Y', strtotime($article['published_at'] ?: $article['created_at']))
                                        : date('Y年m月d日', strtotime($article['published_at'] ?: $article['created_at'])); ?>
                                </time>
                            </div>

                            <h2 class="entry-title font-semibold text-gray-900 mb-3">
                                <a href="/article/<?php echo htmlspecialchars($article['slug']); ?>" class="hover:text-blue-600">
                                    <?php echo htmlspecialchars($article['title']); ?>
                                </a>
                            </h2>

                            <p class="entry-summary mb-5 leading-relaxed">
                                <?php echo htmlspecialchars(!empty($article['excerpt']) ? $article['excerpt'] : mb_substr(strip_tags($article['content']), 0, 120, 'UTF-8') . '...'); ?>
                            </p>

                            <?php $article_tags = get_article_tags($article['id']); ?>
                            <?php if (!empty($article_tags)): ?>
                                <div class="flex flex-wrap gap-2 mb-4">
                                    <?php foreach (array_slice($article_tags, 0, 4) as $tag): ?>
                                        <span class="pill-tag">
                                            <i data-lucide="tag" class="w-3 h-3 mr-1"></i>
                                            <?php echo htmlspecialchars($tag['name']); ?>
                                        </span>
                                    <?php endforeach; ?>
                                </div>
                            <?php endif; ?>

                            <div class="entry-card-footer flex flex-col sm:flex-row sm:items-center sm:justify-end gap-3">
                                <a href="/article/<?php echo htmlspecialchars($article['slug']); ?>" class="read-more-btn entry-read-more self-start sm:self-center">
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
                    <?php echo generate_pagination($page, $total_pages, '/category/' . urlencode($category['slug'] ?: $category['id']) . '?page='); ?>
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
