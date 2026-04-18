<?php
/**
 * SEO相关函数
 * 统一处理网站SEO设置和元数据生成
 *
 * @author 姚金刚
 * @version 1.0
 * @date 2025-10-14
 */

if (!defined('FEISHU_TREASURE')) {
    exit('Access denied');
}

/**
 * 统一读取站点设置，优先使用 site_settings，兼容旧 settings 表。
 */
function site_setting_value($key, $default = '') {
    $value = get_site_setting($key, '');
    if ($value !== '') {
        return $value;
    }

    if (function_exists('get_setting')) {
        $legacy_value = get_setting($key, '');
        if ($legacy_value !== '') {
            return $legacy_value;
        }
    }

    return $default;
}

/**
 * 输出站点级 head 扩展内容，例如 favicon 与统计代码。
 */
function output_site_head_extras() {
    $favicon = site_setting_value('site_favicon', '');
    if ($favicon !== '') {
        echo '<link rel="icon" href="' . htmlspecialchars($favicon, ENT_QUOTES, 'UTF-8') . '">' . PHP_EOL;
    }

    $analytics_code = site_setting_value('analytics_code', '');
    if ($analytics_code !== '') {
        echo $analytics_code . PHP_EOL;
    }
}

/**
 * 获取网站设置
 */
function get_site_setting($key, $default = '') {
    global $db;
    try {
        $stmt = $db->prepare("SELECT setting_value FROM site_settings WHERE setting_key = ? ORDER BY id DESC LIMIT 1");
        $stmt->execute([$key]);
        $result = $stmt->fetch();
        return $result ? $result['setting_value'] : $default;
    } catch (Exception $e) {
        return $default;
    }
}

/**
 * 生成页面标题
 */
function generate_page_title($title, $category = '', $site_name = '') {
    if (empty($site_name)) {
        $site_name = site_setting_value('site_name', 'GEO+AI内容生成系统');
    }
    
    $template = site_setting_value('seo_title_template', '{title} - {site_name}');
    
    $replacements = [
        '{title}' => $title,
        '{site_name}' => $site_name,
        '{category}' => $category
    ];
    
    return str_replace(array_keys($replacements), array_values($replacements), $template);
}

/**
 * 生成页面描述
 */
function generate_page_description($description, $keywords = '', $site_name = '') {
    if (empty($site_name)) {
        $site_name = site_setting_value('site_name', 'GEO+AI内容生成系统');
    }
    
    $template = site_setting_value('seo_description_template', '{description}');
    
    $replacements = [
        '{description}' => $description,
        '{site_name}' => $site_name,
        '{keywords}' => $keywords
    ];
    
    return str_replace(array_keys($replacements), array_values($replacements), $template);
}

/**
 * 生成页面关键词
 */
function generate_page_keywords($base_keywords = '', $additional_keywords = '') {
    $site_keywords = site_setting_value('site_keywords', 'AI,内容生成,SEO,GEO');
    
    $keywords = [];
    
    if (!empty($additional_keywords)) {
        $keywords[] = $additional_keywords;
    }
    
    if (!empty($base_keywords)) {
        $keywords[] = $base_keywords;
    }
    
    $keywords[] = $site_keywords;
    
    return implode(',', $keywords);
}

/**
 * 获取站点基础URL
 */
function geo_site_base_url() {
    if (defined('SITE_URL') && SITE_URL) {
        return rtrim(SITE_URL, '/');
    }

    $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    return $protocol . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost');
}

/**
 * 生成绝对URL
 */
function geo_absolute_url($path = '/') {
    return geo_site_base_url() . '/' . ltrim($path, '/');
}

/**
 * 生成Open Graph元数据
 */
function generate_og_meta($title, $description, $url = '', $image = '', $type = 'website') {
    $site_name = site_setting_value('site_name', 'GEO+AI内容生成系统');
    
    if (empty($url)) {
        $url = "http://{$_SERVER['HTTP_HOST']}{$_SERVER['REQUEST_URI']}";
    }
    
    $meta = [
        'og:title' => $title,
        'og:description' => $description,
        'og:type' => $type,
        'og:url' => $url,
        'og:site_name' => $site_name
    ];
    
    if (!empty($image)) {
        $meta['og:image'] = $image;
    }
    
    return $meta;
}

/**
 * 生成Twitter Card元数据
 */
function generate_twitter_meta($title, $description, $image = '', $card_type = 'summary_large_image') {
    $meta = [
        'twitter:card' => $card_type,
        'twitter:title' => $title,
        'twitter:description' => $description
    ];
    
    if (!empty($image)) {
        $meta['twitter:image'] = $image;
    }
    
    return $meta;
}

/**
 * 生成文章结构化数据
 */
function generate_article_structured_data($article, $site_name = '') {
    if (empty($site_name)) {
        $site_name = site_setting_value('site_name', 'GEO+AI内容生成系统');
    }
    
    $structured_data = [
        "@context" => "https://schema.org",
        "@type" => "Article",
        "headline" => $article['title'],
        "description" => $article['meta_description'] ?: mb_substr(strip_tags($article['content']), 0, 160),
        "author" => [
            "@type" => "Person",
            "name" => $article['author_name'] ?: "系统管理员"
        ],
        "publisher" => [
            "@type" => "Organization",
            "name" => $site_name
        ],
        "datePublished" => date('c', strtotime($article['created_at'])),
        "dateModified" => date('c', strtotime($article['updated_at'])),
        "wordCount" => mb_strlen(strip_tags($article['content'])),
        "mainEntityOfPage" => geo_absolute_url('article/' . $article['slug'])
    ];
    
    if (!empty($article['featured_image'])) {
        $structured_data['image'] = $article['featured_image'];
    }
    
    if (!empty($article['category_name'])) {
        $structured_data['articleSection'] = $article['category_name'];
    }
    
    return $structured_data;
}

/**
 * 生成分类页面结构化数据
 */
function generate_category_structured_data($category, $articles, $total_count) {
    $structured_data = [
        "@context" => "https://schema.org",
        "@type" => "CollectionPage",
        "name" => $category['name'],
        "description" => $category['description'] ?: "浏览{$category['name']}分类的最新内容",
        "url" => geo_absolute_url('category/' . ($category['slug'] ?: $category['id'])),
        "mainEntity" => [
            "@type" => "ItemList",
            "numberOfItems" => $total_count,
            "itemListElement" => []
        ]
    ];
    
    foreach (array_slice($articles, 0, 10) as $index => $article) {
        $structured_data['mainEntity']['itemListElement'][] = [
            "@type" => "ListItem",
            "position" => $index + 1,
            "item" => [
                "@type" => "Article",
                "headline" => $article['title'],
                "url" => geo_absolute_url('article/' . $article['slug'])
            ]
        ];
    }
    
    return $structured_data;
}

/**
 * 生成网站结构化数据
 */
function generate_website_structured_data() {
    $site_name = site_setting_value('site_name', 'GEO+AI内容生成系统');
    $site_description = site_setting_value('site_description', '基于AI的智能内容生成与发布平台');
    
    return [
        "@context" => "https://schema.org",
        "@type" => "WebSite",
        "name" => $site_name,
        "description" => $site_description,
        "url" => geo_absolute_url('/'),
        "potentialAction" => [
            "@type" => "SearchAction",
            "target" => geo_absolute_url('/?search={search_term_string}'),
            "query-input" => "required name=search_term_string"
        ]
    ];
}

/**
 * 生成通用集合页结构化数据
 */
function generate_collection_structured_data($name, $description, $url, $items = [], $type = 'CollectionPage') {
    $structured_data = [
        "@context" => "https://schema.org",
        "@type" => $type,
        "name" => $name,
        "description" => $description,
        "url" => $url
    ];

    if (!empty($items)) {
        $structured_data['mainEntity'] = [
            "@type" => "ItemList",
            "numberOfItems" => count($items),
            "itemListElement" => []
        ];

        foreach ($items as $index => $item) {
            $structured_data['mainEntity']['itemListElement'][] = [
                "@type" => "ListItem",
                "position" => $index + 1,
                "item" => $item
            ];
        }
    }

    return $structured_data;
}

/**
 * 生成FAQ结构化数据
 */
function generate_faq_structured_data($faq_items) {
    $main_entity = [];

    foreach ($faq_items as $faq_item) {
        if (empty($faq_item['question']) || empty($faq_item['answer'])) {
            continue;
        }

        $main_entity[] = [
            "@type" => "Question",
            "name" => $faq_item['question'],
            "acceptedAnswer" => [
                "@type" => "Answer",
                "text" => $faq_item['answer']
            ]
        ];
    }

    return [
        "@context" => "https://schema.org",
        "@type" => "FAQPage",
        "mainEntity" => $main_entity
    ];
}

/**
 * 输出多个结构化数据块
 */
function output_structured_data_blocks($blocks) {
    foreach ($blocks as $block) {
        if (!empty($block)) {
            output_structured_data($block);
        }
    }
}

/**
 * 从标题和标签提取机器可读关键词
 */
function extract_geo_keywords($title, $tags = []) {
    $keywords = [];

    foreach ($tags as $tag) {
        if (!empty($tag['name'])) {
            $keywords[] = trim($tag['name']);
        }
    }

    if (!empty($title)) {
        $normalized_title = preg_replace('/[：:|,，。！？!？、\\/]+/u', ' ', $title);
        $segments = preg_split('/\s+/u', $normalized_title, -1, PREG_SPLIT_NO_EMPTY);

        foreach ($segments as $segment) {
            $segment = trim($segment);
            if ($segment !== '' && mb_strlen($segment) >= 2) {
                $keywords[] = $segment;
            }
        }

        $title_parts = preg_split('/[：:]/u', $title, -1, PREG_SPLIT_NO_EMPTY);
        foreach ($title_parts as $part) {
            $part = trim($part);
            if ($part !== '' && mb_strlen($part) >= 2) {
                $keywords[] = $part;
            }
        }
    }

    return array_slice(array_values(array_unique(array_filter($keywords))), 0, 6);
}

/**
 * 清理 Markdown 标记，生成更适合摘要展示的纯文本
 */
function clean_markdown_for_summary($text, $max_length = 220) {
    if ($text === '') {
        return '';
    }

    $text = preg_replace('/```.*?```/su', ' ', $text);
    $text = preg_replace('/`([^`]+)`/u', '$1', $text);
    $text = preg_replace('/!\[[^\]]*\]\([^)]+\)/u', ' ', $text);
    $text = preg_replace('/\[[^\]]+\]\([^)]+\)/u', '$1', $text);
    $text = preg_replace('/^\s{0,3}#{1,6}\s*/mu', '', $text);
    $text = preg_replace('/^\s*[-*+]\s+/mu', '', $text);
    $text = preg_replace('/^\s*\d+\.\s+/mu', '', $text);
    $text = preg_replace('/[*_~>#]+/u', ' ', $text);

    return clean_text_for_seo($text, $max_length);
}

/**
 * 生成详情页内容摘要，优先使用后台摘要字段
 */
function generate_article_summary_text($article) {
    $excerpt = clean_markdown_for_summary(trim($article['excerpt'] ?? ''), 220);
    if ($excerpt !== '') {
        return $excerpt;
    }

    $content = clean_markdown_for_summary($article['content'] ?? '', 260);
    if ($content === '') {
        return '';
    }

    $parts = preg_split('/(?<=[。！？!?])\s*/u', $content, -1, PREG_SPLIT_NO_EMPTY);
    if (!empty($parts)) {
        return clean_text_for_seo(implode(' ', array_slice($parts, 0, 2)), 220);
    }

    return $content;
}

/**
 * 生成列表卡片使用的纯文本摘要，避免把 Markdown 标记直接露到首页/列表页
 */
function generate_article_card_summary_text($article, $max_length = 160) {
    $excerpt = clean_markdown_for_summary(trim((string) ($article['excerpt'] ?? '')), $max_length);
    if ($excerpt !== '') {
        return $excerpt;
    }

    return clean_markdown_for_summary((string) ($article['content'] ?? ''), $max_length);
}

/**
 * 构造文章机器可读摘要
 */
function build_article_geo_summary($article, $tags = []) {
    $clean_content = clean_markdown_for_summary($article['content'] ?? '', 320);
    $summary_text = generate_article_summary_text($article);
    $keywords = extract_geo_keywords($article['title'] ?? '', $tags);
    $summary = [
        'heading' => '内容摘要',
        'items' => [
            '主题' => $article['title'] ?? '',
            '分类' => $article['category_name'] ?? '未分类',
            '文章标签' => !empty($keywords) ? implode('、', $keywords) : '',
            '适用场景' => !empty($tags) ? implode('、', array_slice(array_column($tags, 'name'), 0, 3)) : 'AI 搜索、内容研究、信息查询',
            '内容摘要' => $summary_text,
            '阅读时间' => (isset($article['content']) && function_exists('get_reading_time')) ? get_reading_time($article['content']) . ' 分钟' : ''
        ],
        'points' => []
    ];

    if ($summary_text !== '') {
        $summary['points'][] = $summary_text;
    }

    if (!empty($clean_content)) {
        $parts = preg_split('/(?<=[。！？!?])\s*/u', $clean_content, -1, PREG_SPLIT_NO_EMPTY);
        foreach (array_slice($parts, 0, 3) as $part) {
            if ($part !== '' && !in_array($part, $summary['points'], true)) {
                $summary['points'][] = $part;
            }
        }
    }

    return $summary;
}

/**
 * 构造列表页机器可读摘要
 */
function build_collection_geo_summary($title, $description, $items = [], $extra = []) {
    $summary_items = array_merge([
        '页面主题' => $title,
        '页面说明' => $description,
        '内容数量' => (string) count($items)
    ], $extra);

    $points = [];
    foreach (array_slice($items, 0, 3) as $item) {
        if (!empty($item['title'])) {
            $points[] = $item['title'];
        }
    }

    return [
        'heading' => '页面摘要',
        'items' => $summary_items,
        'points' => $points
    ];
}

/**
 * 输出HTML meta标签
 */
function output_meta_tags($title, $description, $keywords, $og_meta = [], $twitter_meta = []) {
    echo "<title>" . htmlspecialchars($title) . "</title>\n";
    echo "<meta name=\"description\" content=\"" . htmlspecialchars($description) . "\">\n";
    echo "<meta name=\"keywords\" content=\"" . htmlspecialchars($keywords) . "\">\n";
    
    // Open Graph
    foreach ($og_meta as $property => $content) {
        echo "<meta property=\"{$property}\" content=\"" . htmlspecialchars($content) . "\">\n";
    }
    
    // Twitter Card
    foreach ($twitter_meta as $name => $content) {
        echo "<meta name=\"{$name}\" content=\"" . htmlspecialchars($content) . "\">\n";
    }
}

/**
 * 输出结构化数据
 */
function output_structured_data($data) {
    echo "<script type=\"application/ld+json\">\n";
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    echo "\n</script>\n";
}

/**
 * 清理和截断文本用于SEO
 */
function clean_text_for_seo($text, $max_length = 160) {
    // 移除HTML标签
    $text = strip_tags($text);
    
    // 移除多余的空白字符
    $text = preg_replace('/\s+/', ' ', $text);
    
    // 截断文本
    if (mb_strlen($text) > $max_length) {
        $text = mb_substr($text, 0, $max_length - 3) . '...';
    }
    
    return trim($text);
}

/**
 * 生成面包屑导航结构化数据
 */
function generate_breadcrumb_structured_data($breadcrumbs) {
    $structured_data = [
        "@context" => "https://schema.org",
        "@type" => "BreadcrumbList",
        "itemListElement" => []
    ];
    
    foreach ($breadcrumbs as $index => $breadcrumb) {
        $structured_data['itemListElement'][] = [
            "@type" => "ListItem",
            "position" => $index + 1,
            "name" => $breadcrumb['name'],
            "item" => $breadcrumb['url']
        ];
    }
    
    return $structured_data;
}

/**
 * 获取当前页面的规范URL
 */
function get_canonical_url() {
    $protocol = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'];
    $uri = $_SERVER['REQUEST_URI'];
    
    // 移除查询参数中的分页等参数，保留重要参数
    $parsed_url = parse_url($uri);
    $path = $parsed_url['path'];
    
    if (isset($parsed_url['query'])) {
        parse_str($parsed_url['query'], $query_params);
        
        // 保留重要的查询参数
        $important_params = ['category', 'search'];
        $filtered_params = array_intersect_key($query_params, array_flip($important_params));
        
        if (!empty($filtered_params)) {
            $path .= '?' . http_build_query($filtered_params);
        }
    }
    
    return $protocol . '://' . $host . $path;
}

/**
 * 初始化网站设置（如果不存在）
 */
function init_site_settings() {
    global $db;
    
    $default_settings = [
        'site_name' => 'GEO+AI内容生成系统',
        'site_description' => '基于AI的智能内容生成与发布平台',
        'site_keywords' => 'AI,内容生成,SEO,GEO',
        'seo_title_template' => '{title} - {site_name}',
        'seo_description_template' => '{description}',
        'copyright_info' => '© 2025 GEO+AI内容生成系统. All rights reserved.'
    ];
    
    try {
        foreach ($default_settings as $key => $value) {
            $stmt = $db->prepare("SELECT COUNT(*) FROM site_settings WHERE setting_key = ?");
            $stmt->execute([$key]);
            
            if ($stmt->fetchColumn() == 0) {
                $stmt = $db->prepare("INSERT INTO site_settings (setting_key, setting_value, created_at, updated_at) VALUES (?, ?, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)");
                $stmt->execute([$key, $value]);
            }
        }
    } catch (Exception $e) {
        // 忽略错误，可能是表不存在
    }
}
?>
