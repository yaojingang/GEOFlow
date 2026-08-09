<?php

declare(strict_types=1);

/**
 * Convert a reviewed database export into the versioned GEOFlow frontend reference pack.
 *
 * Usage: php scripts/export-frontend-reference.php <output-directory>
 * The JSON source is read from STDIN so production database credentials never enter the pack.
 */
$outputDirectory = $argv[1] ?? null;
if (! is_string($outputDirectory) || trim($outputDirectory) === '') {
    fwrite(STDERR, "An output directory is required.\n");
    exit(1);
}

$payload = json_decode(stream_get_contents(STDIN), true, flags: JSON_THROW_ON_ERROR);
$articles = $payload['articles'] ?? [];
if (! is_array($articles) || count($articles) !== 50) {
    fwrite(STDERR, "The reviewed export must contain exactly 50 articles.\n");
    exit(1);
}

$categories = [
    [
        'slug' => 'geoflow-getting-started',
        'name' => '功能指南',
        'description' => '从快速上手到知识库、模型、任务、审核与分发的 GEOFlow 功能参考。',
        'sort_order' => 10,
    ],
    [
        'slug' => 'geoflow-deployment-operations',
        'name' => '部署运营',
        'description' => '覆盖安装、升级、容器、反向代理、队列、安全与日常运维的参考内容。',
        'sort_order' => 20,
    ],
];

$articleDirectory = rtrim($outputDirectory, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR.'articles';
if (! is_dir($articleDirectory) && ! mkdir($articleDirectory, 0775, true) && ! is_dir($articleDirectory)) {
    fwrite(STDERR, "Unable to create the article output directory.\n");
    exit(1);
}

$manifestArticles = [];
foreach (array_values($articles) as $index => $article) {
    $slug = trim((string) ($article['slug'] ?? ''));
    $title = sanitizeReferenceText((string) ($article['title'] ?? ''));
    $content = sanitizeReferenceText((string) ($article['content'] ?? ''));
    if ($slug === '' || $title === '' || trim($content) === '') {
        fwrite(STDERR, "Article at index {$index} is incomplete.\n");
        exit(1);
    }

    $filename = sprintf('%02d-%s.md', $index + 1, $slug);
    file_put_contents($articleDirectory.DIRECTORY_SEPARATOR.$filename, rtrim($content)."\n");

    $manifestArticles[] = [
        'category_slug' => (string) $article['category_slug'],
        'slug' => $slug,
        'title' => $title,
        'excerpt' => sanitizeReferenceText((string) ($article['excerpt'] ?? '')),
        'original_keyword' => sanitizeReferenceText((string) ($article['original_keyword'] ?? '')),
        'keywords' => sanitizeReferenceText((string) ($article['keywords'] ?? '')),
        'meta_description' => sanitizeReferenceText((string) ($article['meta_description'] ?? '')),
        'is_ai_generated' => false,
        'is_hot' => in_array($index, [0, 2, 4, 6, 8, 10], true),
        'is_featured' => $index < 6,
        'view_count' => 520 + ($index * 31),
        'published_offset_days' => $index,
        'file' => 'articles/'.$filename,
    ];
}

$manifest = [
    'version' => 'frontend-reference-v1',
    'release_version' => '2.3.0',
    'default_theme' => 'geoflow-template-21-enterprise-signature',
    'description' => 'GEOFlow 首次安装使用的官网参考内容，已有站点升级时不会自动导入。',
    'author' => [
        'name' => 'GEOFlow 编辑部',
        'email' => 'editor@geoflow.local',
        'bio' => 'GEOFlow 开源项目官网参考内容作者。',
    ],
    'categories' => $categories,
    'articles' => $manifestArticles,
];

file_put_contents(
    rtrim($outputDirectory, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR.'manifest.json',
    json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)."\n",
);

fwrite(STDOUT, "Exported 50 reference articles.\n");

function sanitizeReferenceText(string $value): string
{
    $value = str_replace(
        [
            '2.1.1',
            '2026-08-08',
            'http://127.0.0.1:18080',
            'http://localhost:18080',
            'GEOFlow 前台包含首页、分类页、归档页和文章页四种页型。v2.1.0 中 APIHot 主题覆盖全部页型',
            '该版本在 2.1.0 的基础上新增安全加固',
            'v2.3.0 对受管图片新增了 `managed_path_hash` 回填要求',
            '归档页可按年月或标签过滤',
            'APIHot 主题还包含配套静态资源与增强可发现性的元信息',
            '主题包位于 `themes/<theme-id>`，内含模板、资源文件与主题配置',
            '使用 `geoflow-template` 配套 skill',
        ],
        [
            '2.3.0',
            '2026-08-09',
            'http://geoflow-app:8080',
            'http://geoflow-app:8080',
            'GEOFlow 前台包含首页、分类页、文章页、关于页和归档页五种页型。v2.3.0 中 Enterprise Signature 21 主题覆盖全部页型',
            '该版本汇总了 2.1.0 以来的安全加固，并新增默认官网主题与参考内容',
            '早期安全版本对受管图片新增了 `managed_path_hash` 回填要求',
            '归档页按年份和月份自动聚合',
            'Enterprise Signature 21 主题还包含配套静态资源与增强可发现性的元信息',
            '主题视图位于 `resources/views/theme/<theme-id>`，静态资源位于 `public/themes/<theme-id>`，并包含模板、资源文件与主题配置',
            '使用仓库内置统一 `$geoflow` Skill',
        ],
        $value,
    );

    $value = preg_replace('/\*\* {2}\n(?=答：)/u', "**\n\n", $value) ?? $value;

    return preg_replace('/[ \t]+$/m', '', $value) ?? $value;
}
