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

$category_id = intval($_GET['category'] ?? 0);
$search = clean_input($_GET['search'] ?? '');
$page = max(1, intval($_GET['page'] ?? 1));
$per_page = max(1, intval(site_setting_value('per_page', 12)));

$site_title = site_setting_value('site_name', SITE_NAME);
$site_subtitle = site_setting_value('site_subtitle', '');
$site_description = site_setting_value('site_description', SITE_DESCRIPTION);
$site_keywords = site_setting_value('site_keywords', SITE_KEYWORDS);
$featured_limit = max(1, intval(site_setting_value('featured_limit', 6)));
$site_stats = get_site_stats();
$category = null;

$categories = get_categories();
$featured_articles = get_featured_articles($featured_limit);

if (!empty($search)) {
    $articles = search_articles($search, $page, $per_page);
    $total_count = get_search_count($search);
    $view_title = "搜索：{$search}";
} elseif ($category_id > 0) {
    $category = get_category_by_id($category_id);
    if ($category) {
        $articles = get_articles_by_category($category_id, $page, $per_page);
        $total_count = get_category_article_count($category_id);
        $view_title = $category['name'];
    } else {
        $articles = [];
        $total_count = 0;
        $view_title = '分类不存在';
    }
} else {
    $offset = ($page - 1) * $per_page;
    $stmt = $db->prepare("
        SELECT a.*, c.name as category_name, au.name as author_name
        FROM articles a
        LEFT JOIN categories c ON a.category_id = c.id
        LEFT JOIN authors au ON a.author_id = au.id
        WHERE a.status = 'published'
          AND a.deleted_at IS NULL
        ORDER BY a.is_featured DESC, a.published_at DESC, a.created_at DESC
        LIMIT ? OFFSET ?
    ");
    $stmt->execute([$per_page, $offset]);
    $articles = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $total_count = intval($db->query("SELECT COUNT(*) FROM articles WHERE status = 'published' AND deleted_at IS NULL")->fetchColumn());
    $view_title = '最新文章';
}

$total_pages = max(1, (int) ceil($total_count / $per_page));

if (!empty($search)) {
    $page_title = generate_page_title("搜索：{$search}", '', $site_title);
    $page_description = generate_page_description("搜索结果：{$search} - {$site_description}");
} elseif (!empty($category)) {
    $page_title = generate_page_title($category['name'], $category['name'], $site_title);
    $page_description = generate_page_description((!empty($category['description']) ? $category['description'] : "{$category['name']}分类下的内容") . " - {$site_description}");
} else {
    if (!empty($site_subtitle)) {
        $page_title = generate_page_title($site_subtitle, '', $site_title);
    } else {
        $page_title = $site_title;
    }
    $page_description = generate_page_description($site_description, '', $site_title);
}

$canonical_url = !empty($search)
    ? geo_absolute_url('/?search=' . urlencode($search))
    : (!empty($category)
        ? geo_absolute_url('category/' . ($category['slug'] ?: $category['id']))
        : geo_absolute_url('/'));

$page_keywords = !empty($category)
    ? generate_page_keywords($site_keywords, $category['name'])
    : generate_page_keywords($site_keywords, !empty($search) ? $search : '');

$list_summary = build_collection_geo_summary(
    $view_title,
    $page_description,
    $articles,
    [
        '站点名称' => $site_title,
        '内容类型' => !empty($search) ? '搜索结果页' : (!empty($category) ? '分类列表页' : '首页内容聚合页'),
        '当前页码' => (string) $page
    ]
);

$structured_items = [];
foreach (array_slice($articles, 0, 10) as $item) {
    $structured_items[] = [
        "@type" => "Article",
        "headline" => $item['title'],
        "url" => geo_absolute_url('article/' . $item['slug'])
    ];
}

$breadcrumbs = [['name' => '首页', 'url' => geo_absolute_url('/')]];
if (!empty($search)) {
    $breadcrumbs[] = ['name' => '搜索', 'url' => $canonical_url];
} elseif (!empty($category)) {
    $breadcrumbs[] = ['name' => $category['name'], 'url' => $canonical_url];
}

$structured_data_blocks = [
    generate_website_structured_data(),
    generate_collection_structured_data($view_title, $page_description, $canonical_url, $structured_items),
    generate_breadcrumb_structured_data($breadcrumbs)
];

$active_theme_id = geoflow_active_theme_id();
if ($active_theme_id !== '' && geoflow_theme_has_template($active_theme_id, 'home')) {
    geoflow_theme_render_document(
        $active_theme_id,
        'home',
        compact('category_id', 'search', 'page', 'per_page', 'featured_limit', 'site_stats', 'category', 'featured_articles', 'articles', 'total_count', 'total_pages', 'view_title'),
        [
            'site_title' => $site_title,
            'site_subtitle' => $site_subtitle,
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
            'page_key' => empty($category) ? 'home' : 'category',
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

    <main class="site-container px-4 sm:px-6 lg:px-8 py-8">
        <?php if (empty($search) && $category_id === 0 && $page === 1): ?>
            <section class="home-hero article-shell mb-10">
                <div class="px-6 py-7 sm:px-8">
                    <h1 class="home-hero-title text-gray-900 mb-3"><?php echo htmlspecialchars($site_title); ?></h1>
                    <p class="home-hero-copy text-gray-600">
                        <?php echo htmlspecialchars(!empty($site_subtitle) ? $site_subtitle : $site_description); ?>
                    </p>
                </div>
            </section>
        <?php endif; ?>

        <?php if (empty($search) && $category_id === 0 && $page === 1 && !empty($featured_articles)): ?>
            <div class="flex items-center mb-6">
                <div class="section-label mr-4">
                    <i data-lucide="star" class="w-4 h-4 text-amber-400"></i>
                    <span><?php echo __('front.home.featured_articles'); ?></span>
                </div>
            </div>

            <section class="mb-8">
                <div class="space-y-6">
                    <?php foreach ($featured_articles as $article): ?>
                        <article class="article-shell entry-card">
                            <div class="p-6">
                                <div class="flex items-center justify-between mb-4">
                                    <div class="flex items-center space-x-2">
                                        <span class="pill-tag">
                                            <i data-lucide="star" class="w-3 h-3 mr-1"></i>
                                            <?php echo __('front.home.featured_badge'); ?>
                                        </span>
                                        <?php if (!empty($article['category_name'])): ?>
                                            <a href="/category/<?php echo htmlspecialchars($article['category_id']); ?>" class="pill-tag">
                                                <?php echo htmlspecialchars($article['category_name']); ?>
                                            </a>
                                        <?php endif; ?>
                                    </div>
                                    <time class="text-sm text-gray-500" datetime="<?php echo htmlspecialchars($article['published_at'] ?: $article['created_at']); ?>">
                                        <?php echo date('Y年m月d日', strtotime($article['published_at'] ?: $article['created_at'])); ?>
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
            </section>
        <?php endif; ?>

        <?php if (empty($search) && $category_id === 0 && $page === 1 && !empty($featured_articles)): ?>
            <div class="flex items-center mt-10 mb-4">
                <div class="section-label mr-4">
                    <i data-lucide="list" class="w-4 h-4 text-gray-400"></i>
                    <span><?php echo __('front.home.latest_articles'); ?></span>
                </div>
            </div>
        <?php endif; ?>

        <?php if (!empty($search)): ?>
            <nav class="flex items-center space-x-2 text-sm text-gray-500 mb-8">
                <a href="/" class="hover:text-gray-700"><?php echo __('front.nav.home'); ?></a>
                <i data-lucide="chevron-right" class="w-4 h-4"></i>
                <span class="text-gray-900"><?php echo __('front.search.breadcrumb', ['term' => htmlspecialchars($search)]); ?></span>
            </nav>
        <?php elseif (!empty($category)): ?>
            <div class="mb-8">
                <h1 class="text-3xl font-bold text-gray-900 mb-2"><?php echo htmlspecialchars($category['name']); ?></h1>
                <?php if (!empty($category['description'])): ?>
                    <p class="text-gray-500 max-w-3xl"><?php echo htmlspecialchars($category['description']); ?></p>
                <?php endif; ?>
            </div>
        <?php endif; ?>

        <section class="py-4">
            <?php if (empty($articles)): ?>
                <div class="article-shell p-12 text-center">
                    <div class="w-16 h-16 bg-gray-100 rounded-full flex items-center justify-center mx-auto mb-4">
                        <i data-lucide="file-text" class="w-8 h-8 text-gray-400"></i>
                    </div>
                    <h3 class="text-xl font-semibold text-gray-900 mb-2"><?php echo !empty($search) ? __('front.search.empty_title') : __('front.articles.empty_title'); ?></h3>
                    <p class="text-gray-600 mb-6"><?php echo !empty($search) ? __('front.search.empty_description') : __('front.articles.empty_description'); ?></p>
                    <a href="/" class="inline-flex items-center px-4 py-2 bg-gray-900 text-white rounded-lg">
                        <i data-lucide="arrow-left" class="w-4 h-4 mr-2"></i>
                        <?php echo __('front.back_home'); ?>
                    </a>
                </div>
            <?php else: ?>
                <div class="space-y-8">
                    <?php foreach ($articles as $article): ?>
                        <article class="article-shell entry-card">
                            <div class="p-6">
                                <div class="flex items-center justify-between mb-4">
                                    <div class="flex items-center space-x-2">
                                        <?php if (!empty($article['is_featured'])): ?>
                                            <span class="pill-tag">
                                                <i data-lucide="star" class="w-3 h-3 mr-1"></i>
                                                <?php echo __('front.home.featured_badge'); ?>
                                            </span>
                                        <?php endif; ?>
                                        <?php if (!empty($article['category_name'])): ?>
                                            <a href="/category/<?php echo htmlspecialchars($article['category_id']); ?>" class="pill-tag">
                                                <?php echo htmlspecialchars($article['category_name']); ?>
                                            </a>
                                        <?php endif; ?>
                                    </div>
                                    <time class="text-sm text-gray-500" datetime="<?php echo htmlspecialchars($article['published_at'] ?: $article['created_at']); ?>">
                                        <?php echo date('Y年m月d日', strtotime($article['published_at'] ?: $article['created_at'])); ?>
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
                        <?php
                        $base_url = '/';
                        $params = [];
                        if (!empty($search)) {
                            $params[] = 'search=' . urlencode($search);
                        }
                        if ($category_id > 0) {
                            $params[] = 'category=' . $category_id;
                        }
                        $pagination_url = $base_url . (!empty($params) ? '?' . implode('&', $params) . '&page=' : '?page=');
                        echo generate_pagination($page, $total_pages, $pagination_url);
                        ?>
                    </div>
                <?php endif; ?>
            <?php endif; ?>
        </section>
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
