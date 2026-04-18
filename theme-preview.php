<?php
define('FEISHU_TREASURE', true);
session_start();

require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/database.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/seo_functions.php';
require_once __DIR__ . '/includes/theme_preview.php';

$database = Database::getInstance();
$db = $database->getPDO();
geoflow_theme_set_route_mode('preview');

$theme_id = clean_input($_GET['theme'] ?? '');
$preview_page = clean_input($_GET['preview_page'] ?? 'home');

if ($theme_id === '' || !geoflow_theme_exists($theme_id)) {
    header('HTTP/1.0 404 Not Found');
    exit('Theme preview not found');
}

$theme_manifest = geoflow_theme_manifest($theme_id);
$site_title = site_setting_value('site_name', SITE_NAME);
$site_subtitle = site_setting_value('site_subtitle', '');
$site_description = site_setting_value('site_description', SITE_DESCRIPTION);
$site_keywords = site_setting_value('site_keywords', SITE_KEYWORDS);
$site_logo = site_setting_value('site_logo', '');
$categories = get_categories();

$theme_request_path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
$is_home = $preview_page === 'home';
$is_archive = $preview_page === 'archive';
$page_template = '';
$template_vars = [];
$page_title = '';
$page_description = '';
$page_keywords = '';
$canonical_url = '';
$structured_data_blocks = [];

if ($preview_page === 'article') {
    $slug = clean_input($_GET['slug'] ?? '');
    if ($slug === '') {
        $fallback = geoflow_preview_latest_article_slug($db);
        if ($fallback) {
            header('Location: ' . geoflow_theme_preview_url($theme_id, 'article', ['slug' => $fallback]));
            exit;
        }
        header('HTTP/1.0 404 Not Found');
        exit('No article available for preview');
    }

    $article = get_article_by_slug($slug);
    if (!$article) {
        header('HTTP/1.0 404 Not Found');
        exit('Article not found');
    }

    try {
        $stmt = $db->prepare("UPDATE articles SET view_count = view_count + 1 WHERE id = ?");
        $stmt->execute([$article['id']]);
        $article['view_count'] = intval($article['view_count'] ?? 0) + 1;
    } catch (Throwable $e) {
    }

    $article_detail_ad = get_active_article_detail_ad();
    $article_tags = get_article_tags($article['id']);
    $related_articles = get_related_articles($article['id'], $article['category_id'], 3);
    $article_content = $article['content'] ?? '';
    $article_excerpt = $article['excerpt'] ?? '';
    $article_content_summary = $article_content;

    if ($article_content !== '') {
        $title_pattern = preg_quote(trim($article['title']), '/');
        $article_content = preg_replace('/^\s*#\s*' . $title_pattern . '\s*(?:\r?\n)+/u', '', $article_content, 1);
        $article_content_summary = preg_replace('/^\s*#\s*' . $title_pattern . '\s*(?:\r?\n)+/u', '', $article_content_summary, 1);
        $article_excerpt = preg_replace('/^\s*#\s*' . $title_pattern . '\s*/u', '', $article_excerpt, 1);
    }

    $article_excerpt = preg_replace('/!\[[^\]]*\]\(([^)]+)\)/u', '', $article_excerpt);
    $article_content_summary = preg_replace('/!\[[^\]]*\]\(([^)]+)\)/u', '', $article_content_summary);
    $article_content_summary = trim(preg_replace('/\n{3,}/', "\n\n", $article_content_summary));
    $article_excerpt = clean_markdown_for_summary($article_excerpt, 220);
    $article_content_summary = clean_markdown_for_summary($article_content_summary, 320);

    $page_title = generate_page_title($article['title'], $article['category_name'] ?? '', $site_title);
    $page_description = generate_page_description(!empty($article_excerpt) ? $article_excerpt : mb_substr($article_content_summary, 0, 160, 'UTF-8'));
    $page_keywords = generate_page_keywords(
        $article['category_name'] ?? '',
        !empty($article_tags) ? implode(',', array_column($article_tags, 'name')) : ''
    );
    $canonical_url = geo_absolute_url('article/' . $article['slug']);
    $structured_data_blocks = [
        generate_website_structured_data(),
        generate_article_structured_data($article, $site_title),
        generate_breadcrumb_structured_data(array_values(array_filter([
            ['name' => __('front.nav.home'), 'url' => geo_absolute_url('/')],
            !empty($article['category_name']) ? ['name' => $article['category_name'], 'url' => geo_absolute_url('category/' . ($article['category_slug'] ?: $article['category_id']))] : null,
            ['name' => $article['title'], 'url' => $canonical_url]
        ])))
    ];

    $page_template = 'article';
    $template_vars = compact(
        'article',
        'article_detail_ad',
        'article_tags',
        'related_articles',
        'article_content',
        'article_excerpt'
    );
} elseif ($preview_page === 'category') {
    $category_slug = clean_input($_GET['slug'] ?? '');
    if ($category_slug === '') {
        $fallback = geoflow_preview_first_category_slug($db);
        if ($fallback) {
            header('Location: ' . geoflow_theme_preview_url($theme_id, 'category', ['slug' => $fallback]));
            exit;
        }
        header('HTTP/1.0 404 Not Found');
        exit('No category available for preview');
    }

    $stmt = $db->prepare("SELECT * FROM categories WHERE slug = ? OR id::text = ? LIMIT 1");
    $stmt->execute([$category_slug, $category_slug]);
    $category = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$category) {
        header('HTTP/1.0 404 Not Found');
        exit(__('front.category.error.not_found'));
    }

    $page = max(1, intval($_GET['page'] ?? 1));
    $per_page = max(1, intval(site_setting_value('per_page', 12)));
    $articles = get_articles_by_category($category['id'], $page, $per_page);
    $total_count = get_category_article_count($category['id']);
    $total_pages = max(1, (int) ceil($total_count / $per_page));
    $page_title = generate_page_title($category['name'], $category['name'], $site_title);
    $page_description = generate_page_description((!empty($category['description']) ? $category['description'] : __('front.category.meta_fallback', ['name' => $category['name']])) . ' - ' . $site_description);
    $page_keywords = generate_page_keywords($site_keywords, $category['name']);
    $canonical_url = geo_absolute_url('category/' . ($category['slug'] ?: $category['id']));
    $structured_data_blocks = [
        generate_website_structured_data(),
        generate_category_structured_data($category, $articles, $total_count),
        generate_breadcrumb_structured_data([
            ['name' => __('front.nav.home'), 'url' => geo_absolute_url('/')],
            ['name' => $category['name'], 'url' => $canonical_url]
        ])
    ];

    $page_template = 'category';
    $template_vars = compact('category', 'articles', 'total_count', 'total_pages', 'page');
} elseif ($preview_page === 'archive') {
    $year = clean_input($_GET['year'] ?? '');
    $month = clean_input($_GET['month'] ?? '');
    $page = max(1, intval($_GET['page'] ?? 1));
    $per_page = max(1, intval(site_setting_value('per_page', 12)));

    if ($year !== '' && $month !== '') {
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
        $canonical_url = geo_absolute_url('archive/' . $year . '/' . $month);

        $collection_items = [];
        foreach (array_slice($articles, 0, 10) as $item) {
            $collection_items[] = [
                "@type" => "Article",
                "headline" => $item['title'],
                "url" => geo_absolute_url('article/' . $item['slug'])
            ];
        }
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
        $canonical_url = geo_absolute_url('archive');
        $articles = [];
        $total_count = 0;
        $total_pages = 1;
        $collection_items = [];
    }

    $page_keywords = generate_page_keywords('', __('front.archive.meta_keywords'));
    $structured_data_blocks = [
        generate_website_structured_data(),
        generate_collection_structured_data($archive_title, $page_description, $canonical_url, $collection_items, 'CollectionPage'),
        generate_breadcrumb_structured_data(array_values(array_filter([
            ['name' => __('front.nav.home'), 'url' => geo_absolute_url('/')],
            ['name' => __('front.archive.title'), 'url' => geo_absolute_url('archive')],
            ($year !== '' && $month !== '') ? ['name' => $archive_title, 'url' => $canonical_url] : null
        ])))
    ];

    $page_template = 'archive';
    $template_vars = compact('year', 'month', 'archive_title', 'articles', 'archives', 'total_count', 'total_pages', 'page');
} else {
    $category_id = intval($_GET['category'] ?? 0);
    $search = clean_input($_GET['search'] ?? '');
    $page = max(1, intval($_GET['page'] ?? 1));
    $per_page = max(1, intval(site_setting_value('per_page', 12)));
    $featured_limit = max(1, intval(site_setting_value('featured_limit', 6)));
    $site_stats = get_site_stats();
    $category = null;
    $featured_articles = get_featured_articles($featured_limit);

    if ($search !== '') {
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
            SELECT a.*, c.name as category_name, c.slug as category_slug, au.name as author_name
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
        $view_title = __('front.home.latest_articles');
    }

    $total_pages = max(1, (int) ceil($total_count / $per_page));

    if ($search !== '') {
        $page_title = generate_page_title("搜索：{$search}", '', $site_title);
        $page_description = generate_page_description("搜索结果：{$search} - {$site_description}");
    } elseif (!empty($category)) {
        $page_title = generate_page_title($category['name'], $category['name'], $site_title);
        $page_description = generate_page_description((!empty($category['description']) ? $category['description'] : "{$category['name']}分类下的内容") . " - {$site_description}");
    } else {
        $page_title = !empty($site_subtitle) ? generate_page_title($site_subtitle, '', $site_title) : $site_title;
        $page_description = generate_page_description($site_description, '', $site_title);
    }

    $canonical_url = $search !== ''
        ? geo_absolute_url('/?search=' . urlencode($search))
        : (!empty($category)
            ? geo_absolute_url('category/' . ($category['slug'] ?: $category['id']))
            : geo_absolute_url('/'));

    $page_keywords = !empty($category)
        ? generate_page_keywords($site_keywords, $category['name'])
        : generate_page_keywords($site_keywords, $search !== '' ? $search : '');

    $structured_items = [];
    foreach (array_slice($articles, 0, 10) as $item) {
        $structured_items[] = [
            "@type" => "Article",
            "headline" => $item['title'],
            "url" => geo_absolute_url('article/' . $item['slug'])
        ];
    }

    $breadcrumbs = [['name' => __('front.nav.home'), 'url' => geo_absolute_url('/')]];
    if ($search !== '') {
        $breadcrumbs[] = ['name' => __('front.home.search_results'), 'url' => $canonical_url];
    } elseif (!empty($category)) {
        $breadcrumbs[] = ['name' => $category['name'], 'url' => $canonical_url];
    }

    $structured_data_blocks = [
        generate_website_structured_data(),
        generate_collection_structured_data($view_title, $page_description, $canonical_url, $structured_items),
        generate_breadcrumb_structured_data($breadcrumbs)
    ];

    $page_template = 'home';
    $template_vars = compact('category_id', 'search', 'page', 'per_page', 'featured_limit', 'site_stats', 'category', 'featured_articles', 'articles', 'total_count', 'total_pages', 'view_title');
}

if ($page_template === '') {
    header('HTTP/1.0 500 Internal Server Error');
    exit('Theme preview template not resolved');
}
geoflow_theme_render_document(
    $theme_id,
    $page_template,
    $template_vars,
    [
        'site_title' => $site_title,
        'site_subtitle' => $site_subtitle,
        'site_description' => $site_description,
        'site_logo' => $site_logo,
        'categories' => $categories,
        'page_title' => $page_title,
        'page_description' => $page_description,
        'page_keywords' => $page_keywords,
        'canonical_url' => $canonical_url,
        'structured_data_blocks' => $structured_data_blocks,
        'og_type' => $preview_page === 'article' ? 'article' : 'website',
    ],
    [
        'route_mode' => 'preview',
        'page_key' => $preview_page,
    ]
);
