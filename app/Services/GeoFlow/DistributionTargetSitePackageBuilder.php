<?php

namespace App\Services\GeoFlow;

use App\Models\DistributionChannel;
use RuntimeException;
use ZipArchive;

class DistributionTargetSitePackageBuilder
{
    /**
     * @return array{path:string,filename:string}
     */
    public function build(DistributionChannel $channel, string $keyId, string $plainSecret): array
    {
        $filename = 'geoflow-target-site-'.$this->slug((string) ($channel->domain ?: $channel->name)).'.zip';
        $directory = storage_path('app/tmp/distribution-packages');
        if (! is_dir($directory) && ! mkdir($directory, 0755, true) && ! is_dir($directory)) {
            throw new RuntimeException('目标站点包临时目录创建失败');
        }

        $path = $directory.'/'.uniqid('package-', true).'-'.$filename;
        $zip = new ZipArchive;
        if ($zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException('目标站点包 ZIP 创建失败');
        }

        $zip->addFromString('.htaccess', $this->rootHtaccess());
        $zip->addFromString('index.html', $this->initialStaticIndex($channel));
        $zip->addFromString('llms.txt', $this->initialLlmsText($channel));
        $zip->addFromString('sitemap.txt', $this->initialSitemapText($channel));
        $zip->addFromString('assets/css/site.css', $this->targetSiteCss());
        $zip->addFromString('assets/js/site.js', $this->targetSiteJs());
        $zip->addFromString('assets/images/.gitkeep', '');
        $zip->addFromString('index.php', $this->rootIndex());
        $zip->addFromString('config.php', $this->config($channel, $keyId, $plainSecret));
        $zip->addFromString('public/index.php', $this->frontController());
        $zip->addFromString('public/.htaccess', $this->publicHtaccess());
        $zip->addFromString('nginx.example.conf', $this->nginxExample());
        $zip->addFromString('nginx.rewrite.conf', DistributionRewriteRuleGenerator::nginxRewrite($channel));
        $zip->addFromString('bt.rewrite.conf', DistributionRewriteRuleGenerator::baotaRewriteOnly($channel));
        $zip->addFromString('storage/.htaccess', $this->storageHtaccess());
        $zip->addFromString('storage/articles/.gitkeep', '');
        $zip->close();

        return [
            'path' => $path,
            'filename' => $filename,
        ];
    }

    private function config(DistributionChannel $channel, string $keyId, string $plainSecret): string
    {
        $siteSettings = $channel->resolvedSiteSettings();
        $frontMode = method_exists($channel, 'frontMode') ? $channel->frontMode() : (string) ($channel->front_mode ?? 'static');
        $frontMode = in_array($frontMode, ['static', 'rewrite'], true) ? $frontMode : 'static';
        $staticPublishEnabled = $frontMode === 'static';
        $config = [
            'site_name' => $siteSettings['site_name'],
            'site_subtitle' => $siteSettings['site_subtitle'],
            'site_description' => $siteSettings['site_description'],
            'site_keywords' => $siteSettings['site_keywords'],
            'copyright_info' => $siteSettings['copyright_info'],
            'site_logo' => $siteSettings['site_logo'],
            'site_favicon' => $siteSettings['site_favicon'],
            'seo_title_template' => $siteSettings['seo_title_template'],
            'seo_description_template' => $siteSettings['seo_description_template'],
            'featured_limit' => $siteSettings['featured_limit'],
            'per_page' => $siteSettings['per_page'],
            'active_theme' => (string) ($channel->template_key ?? ''),
            'front_mode' => $frontMode,
            'static_publish_enabled' => $staticPublishEnabled,
            'domain' => (string) $channel->domain,
            'public_base_url' => rtrim((string) $channel->endpoint_url, '/'),
            'base_path' => $this->basePath((string) $channel->endpoint_url),
            'static_output_dir' => '__DIR__',
            'key_id' => $keyId,
            'secret' => $plainSecret,
            'storage_dir' => "__DIR__.'/storage/articles'",
            'max_asset_bytes' => 5 * 1024 * 1024,
            'clock_skew_seconds' => 300,
        ];

        return "<?php\n\nreturn [\n"
            ."    'site_name' => ".var_export($config['site_name'], true).",\n"
            ."    'site_subtitle' => ".var_export($config['site_subtitle'], true).",\n"
            ."    'site_description' => ".var_export($config['site_description'], true).",\n"
            ."    'site_keywords' => ".var_export($config['site_keywords'], true).",\n"
            ."    'copyright_info' => ".var_export($config['copyright_info'], true).",\n"
            ."    'site_logo' => ".var_export($config['site_logo'], true).",\n"
            ."    'site_favicon' => ".var_export($config['site_favicon'], true).",\n"
            ."    'seo_title_template' => ".var_export($config['seo_title_template'], true).",\n"
            ."    'seo_description_template' => ".var_export($config['seo_description_template'], true).",\n"
            ."    'featured_limit' => ".$config['featured_limit'].",\n"
            ."    'per_page' => ".$config['per_page'].",\n"
            ."    'active_theme' => ".var_export($config['active_theme'], true).",\n"
            ."    'front_mode' => ".var_export($config['front_mode'], true).",\n"
            ."    'static_publish_enabled' => ".($config['static_publish_enabled'] ? 'true' : 'false').",\n"
            ."    'domain' => ".var_export($config['domain'], true).",\n"
            ."    'public_base_url' => ".var_export($config['public_base_url'], true).",\n"
            ."    'base_path' => ".var_export($config['base_path'], true).",\n"
            ."    'static_output_dir' => ".$config['static_output_dir'].",\n"
            ."    'key_id' => ".var_export($config['key_id'], true).",\n"
            ."    'secret' => ".var_export($config['secret'], true).",\n"
            ."    'storage_dir' => ".$config['storage_dir'].",\n"
            ."    'max_asset_bytes' => ".$config['max_asset_bytes'].",\n"
            ."    'clock_skew_seconds' => ".$config['clock_skew_seconds'].",\n"
            ."    'categories' => [\n"
            ."        ['name' => '默认分类', 'slug' => 'default'],\n"
            ."    ],\n"
            ."];\n";
    }

    private function rootIndex(): string
    {
        return <<<'PHP'
<?php

require __DIR__.'/public/index.php';
PHP;
    }

    private function rootHtaccess(): string
    {
        return DistributionRewriteRuleGenerator::apacheHtaccess();
    }

    private function publicHtaccess(): string
    {
        return <<<'HTACCESS'
<IfModule mod_rewrite.c>
    RewriteEngine On
    RewriteCond %{REQUEST_FILENAME} !-f
    RewriteCond %{REQUEST_FILENAME} !-d
    RewriteRule ^ index.php [L]
</IfModule>
HTACCESS;
    }

    private function storageHtaccess(): string
    {
        return <<<'HTACCESS'
Require all denied
Deny from all
HTACCESS;
    }

    private function initialStaticIndex(DistributionChannel $channel): string
    {
        $settings = $channel->resolvedSiteSettings();
        $siteName = (string) $settings['site_name'];
        $description = (string) $settings['site_description'];
        $copyright = (string) $settings['copyright_info'];
        $settings['active_theme'] = (string) ($channel->template_key ?? '');
        $themeClass = $this->targetThemeClass($settings);

        return '<!DOCTYPE html><html lang="zh-CN"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">'
            .'<title>'.htmlspecialchars('首页 - '.$siteName, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8').'</title>'
            .'<meta name="description" content="'.htmlspecialchars($description, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8').'">'
            .'<link rel="stylesheet" href="assets/css/site.css"><script defer src="assets/js/site.js"></script>'
            .'</head><body class="'.htmlspecialchars($themeClass, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8').'"><header><div class="wrap bar"><div class="brand">'.htmlspecialchars($siteName, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8').'</div></div></header><main class="wrap">'
            .'<section class="hero"><h1>'.htmlspecialchars($siteName, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8').'</h1><p>'.htmlspecialchars($description, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8').'</p></section>'
            .'<div class="empty">暂无文章。请先从 GEOFlow 发布一篇绑定此渠道的文章。</div></main>'
            .'<footer><div class="wrap">'.htmlspecialchars($copyright, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8').'</div></footer></body></html>';
    }

    /**
     * @param  array<string, mixed>  $settings
     */
    private function targetThemeClass(array $settings): string
    {
        $theme = strtolower(trim((string) ($settings['active_theme'] ?? '')));

        if (str_contains($theme, 'toutiao')) {
            return 'target-theme-toutiao';
        }

        if (str_contains($theme, 'netease')) {
            return 'target-theme-netease';
        }

        if (str_contains($theme, 'tdwh')) {
            return 'target-theme-tdwh';
        }

        return 'target-theme-default';
    }

    private function initialLlmsText(DistributionChannel $channel): string
    {
        $settings = $channel->resolvedSiteSettings();
        $siteName = (string) $settings['site_name'];
        $description = trim((string) $settings['site_description']);
        $homeUrl = $this->publicFrontBaseUrl((string) $channel->endpoint_url).'/';

        return "# {$siteName}\n\n"
            .($description !== '' ? "> {$description}\n\n" : '')
            ."## Site\n\n"
            ."- Home: {$homeUrl}\n"
            ."- Sitemap: {$homeUrl}sitemap.txt\n\n"
            ."## Articles\n\n"
            ."No articles have been published yet.\n";
    }

    private function initialSitemapText(DistributionChannel $channel): string
    {
        return $this->publicFrontBaseUrl((string) $channel->endpoint_url)."/\n";
    }

    private function publicFrontBaseUrl(string $endpointUrl): string
    {
        $baseUrl = rtrim($endpointUrl, '/');
        if (str_ends_with($baseUrl, '/index.php')) {
            $baseUrl = substr($baseUrl, 0, -strlen('/index.php'));
        }

        return rtrim($baseUrl, '/');
    }

    private function targetSiteCss(): string
    {
        return <<<'CSS'
:root{color-scheme:light;font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif;color:#111827;background:#f8fafc}
body{margin:0;background:#f8fafc}
.wrap{max-width:1040px;margin:0 auto;padding:0 24px}
header{position:sticky;top:0;background:rgba(255,255,255,.94);border-bottom:1px solid #e5e7eb;backdrop-filter:saturate(180%) blur(10px);z-index:10}
header .bar{height:64px;display:flex;align-items:center;justify-content:space-between}
.brand{font-weight:800;font-size:20px;color:#111827;text-decoration:none}
nav a{color:#4b5563;text-decoration:none;font-size:14px;margin-left:20px}
.hero{padding:42px 0 22px}.hero h1{font-size:34px;line-height:1.15;margin:0 0 12px}.hero p{margin:0;color:#6b7280;max-width:680px}
.card{background:#fff;border:1px solid #e5e7eb;border-radius:8px;box-shadow:0 1px 3px rgba(15,23,42,.06)}
.list{display:grid;gap:18px;padding:0 0 44px}
article.card{padding:24px}
.meta{display:flex;flex-wrap:wrap;gap:10px;color:#6b7280;font-size:13px;margin-bottom:12px}
.chip{display:inline-flex;align-items:center;border:1px solid #dbeafe;background:#eff6ff;color:#2563eb;border-radius:999px;padding:3px 9px}
h2{font-size:22px;margin:0 0 10px}h2 a{color:#111827;text-decoration:none}h2 a:hover{color:#2563eb}
.summary{color:#4b5563;line-height:1.75;margin:0 0 18px}.read{color:#2563eb;text-decoration:none;font-weight:600;font-size:14px}
.detail{padding:34px;margin-bottom:44px}.detail h1{font-size:38px;line-height:1.14;margin:0 0 16px}
.content{font-size:17px;line-height:1.9;color:#1f2937}.content p{margin:0 0 1em}
.content h2{font-size:28px;line-height:1.25;margin:1.65em 0 .7em;color:#111827}.content h3{font-size:22px;line-height:1.35;margin:1.45em 0 .65em;color:#111827}.content h4{font-size:18px;margin:1.25em 0 .55em}
.content ul,.content ol{margin:0 0 1.2em 1.25em;padding:0}.content li{margin:.35em 0}.content strong{font-weight:700;color:#111827}.content a{color:#2563eb;text-decoration:underline;text-underline-offset:3px}
.content img{display:block;max-width:100%;height:auto;border-radius:8px;margin:24px auto}.content blockquote{margin:1.4em 0;padding:14px 18px;border-left:4px solid #dbeafe;background:#f8fafc;color:#374151}
.content pre{overflow:auto;border-radius:8px;background:#111827;color:#f9fafb;padding:16px;margin:1.4em 0}.content code{border-radius:4px;background:#f3f4f6;padding:2px 5px;font-size:.92em}.content pre code{background:transparent;padding:0;color:inherit}
.content .article-table-wrap{overflow-x:auto;margin:1.4em 0;border:1px solid #e5e7eb;border-radius:8px}.content .article-table{width:100%;border-collapse:collapse;background:#fff}.content .article-table th,.content .article-table td{border-bottom:1px solid #e5e7eb;padding:10px 12px;text-align:left;vertical-align:top}.content .article-table th{background:#f9fafb;color:#111827;font-weight:700}
.tags{display:flex;flex-wrap:wrap;gap:8px;margin-top:28px;padding-top:22px;border-top:1px solid #e5e7eb}.tags span{display:inline-flex;border:1px solid #e5e7eb;background:#f9fafb;color:#4b5563;border-radius:999px;padding:5px 10px;font-size:13px}
.empty{padding:52px;text-align:center;color:#6b7280}.back{display:inline-block;margin:28px 0 18px;color:#4b5563;text-decoration:none}
footer{border-top:1px solid #e5e7eb;color:#6b7280;font-size:13px;padding:24px 0 36px}
body.target-theme-toutiao{background:#fffafa}.target-theme-toutiao header{border-bottom-color:#fecaca}.target-theme-toutiao .brand,.target-theme-toutiao h2 a:hover,.target-theme-toutiao .read{color:#dc2626}.target-theme-toutiao .chip{border-color:#fecaca;background:#fef2f2;color:#b91c1c}.target-theme-toutiao .card{border-color:#fee2e2}
body.target-theme-netease{background:#f7f7f7}.target-theme-netease header{border-top:3px solid #d7000f}.target-theme-netease .brand,.target-theme-netease h2 a:hover,.target-theme-netease .read{color:#b91c1c}.target-theme-netease .chip{border-color:#fee2e2;background:#fff1f2;color:#991b1b}.target-theme-netease .hero h1{font-weight:900}.target-theme-netease .card{box-shadow:none}
body.target-theme-tdwh{background:#f8fbff}.target-theme-tdwh header{border-bottom-color:#bfdbfe}.target-theme-tdwh .brand,.target-theme-tdwh h2 a:hover,.target-theme-tdwh .read{color:#1d4ed8}.target-theme-tdwh .chip{border-color:#bfdbfe;background:#eff6ff;color:#1e40af}.target-theme-tdwh .card{border-color:#dbeafe}
CSS;
    }

    private function targetSiteJs(): string
    {
        return <<<'JS'
document.addEventListener('click', function (event) {
  var trigger = event.target.closest('[data-copy-target]');
  if (!trigger) return;
  var target = document.querySelector(trigger.getAttribute('data-copy-target'));
  if (!target || !navigator.clipboard) return;
  navigator.clipboard.writeText(target.textContent || target.value || '');
});
JS;
    }

    private function nginxExample(): string
    {
        return <<<'NGINX'
server {
    listen 80;
    server_name example.com;
    root /path/to/geoflow-target-site/public;
    index index.php;

    location ~ ^/(config\.php|storage/|nginx\.example\.conf|nginx\.rewrite\.conf|bt\.rewrite\.conf) {
        deny all;
    }

    location / {
        try_files $uri $uri/ /index.php?$query_string;
    }

    location ~ \.php$ {
        include fastcgi_params;
        fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
        fastcgi_pass 127.0.0.1:9000;
    }
}
NGINX;
    }

    private function frontController(): string
    {
        return <<<'PHP'
<?php

declare(strict_types=1);

$config = require dirname(__DIR__).'/config.php';
if (! is_array($config)) {
    $config = [];
}

function h(?string $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function jsonResponse(int $status, array $payload): void
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function textResponse(string $content, int $status = 200): void
{
    http_response_code($status);
    header('Content-Type: text/plain; charset=utf-8');
    echo $content;
    exit;
}

function requestHeader(string $name): string
{
    $key = 'HTTP_'.strtoupper(str_replace('-', '_', $name));

    return is_string($_SERVER[$key] ?? null) ? (string) $_SERVER[$key] : '';
}

function storageDir(array $config): string
{
    return rtrim((string) ($config['storage_dir'] ?? dirname(__DIR__).'/storage/articles'), '/');
}

function storageRoot(array $config): string
{
    return dirname(storageDir($config));
}

function siteSettingsFile(array $config): string
{
    return storageRoot($config).'/site-settings.json';
}

function imageAssetsDir(array $config): string
{
    return staticRoot($config).'/assets/images';
}

function normalizeSiteSettings(array $settings, array $config = []): array
{
    $siteName = trim((string) ($settings['site_name'] ?? $config['site_name'] ?? 'GEOFlow Target Site'));
    $siteName = $siteName !== '' ? $siteName : 'GEOFlow Target Site';
    $frontMode = (string) ($settings['front_mode'] ?? $config['front_mode'] ?? 'static');
    $frontMode = in_array($frontMode, ['static', 'rewrite'], true) ? $frontMode : 'static';

    return [
        'site_name' => $siteName,
        'site_subtitle' => trim((string) ($settings['site_subtitle'] ?? $config['site_subtitle'] ?? '')),
        'site_description' => trim((string) ($settings['site_description'] ?? $config['site_description'] ?? '由 GEOFlow 自动分发和管理的目标站点。')),
        'site_keywords' => trim((string) ($settings['site_keywords'] ?? $config['site_keywords'] ?? '')),
        'copyright_info' => trim((string) ($settings['copyright_info'] ?? $config['copyright_info'] ?? '© '.date('Y').' '.$siteName)),
        'site_logo' => trim((string) ($settings['site_logo'] ?? $config['site_logo'] ?? '')),
        'site_favicon' => trim((string) ($settings['site_favicon'] ?? $config['site_favicon'] ?? '')),
        'seo_title_template' => trim((string) ($settings['seo_title_template'] ?? $config['seo_title_template'] ?? '{title} - {site_name}')),
        'seo_description_template' => trim((string) ($settings['seo_description_template'] ?? $config['seo_description_template'] ?? '{description}')),
        'featured_limit' => min(100, max(1, (int) ($settings['featured_limit'] ?? $config['featured_limit'] ?? 6))),
        'per_page' => min(200, max(1, (int) ($settings['per_page'] ?? $config['per_page'] ?? 12))),
        'active_theme' => trim((string) ($settings['active_theme'] ?? $config['active_theme'] ?? '')),
        'front_mode' => $frontMode,
    ];
}

function siteSettings(array $config): array
{
    $settings = $config;
    $settingsFile = siteSettingsFile($config);
    if (is_file($settingsFile)) {
        $decoded = json_decode((string) file_get_contents($settingsFile), true);
        if (is_array($decoded)) {
            $settings = array_merge($settings, $decoded);
        }
    }

    return normalizeSiteSettings($settings, $config);
}

function activeTheme(array $settings): string
{
    $theme = strtolower(trim((string) ($settings['active_theme'] ?? '')));

    return $theme !== '' ? $theme : 'default';
}

function themeClass(array $settings): string
{
    $theme = activeTheme($settings);
    if (str_contains($theme, 'toutiao')) {
        return 'target-theme-toutiao';
    }
    if (str_contains($theme, 'netease')) {
        return 'target-theme-netease';
    }
    if (str_contains($theme, 'tdwh')) {
        return 'target-theme-tdwh';
    }

    return 'target-theme-default';
}

function stripLeadingTitleHeading(string $content, string $title): string
{
    $title = trim($title);
    if ($title === '') {
        return $content;
    }

    $pattern = '/^\s*#\s*'.preg_quote($title, '/').'\s*(?:\r?\n)+/u';

    return (string) preg_replace($pattern, '', $content, 1);
}

function safeContentUrl(string $url): string
{
    $url = trim(html_entity_decode($url, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'));
    if ($url === '') {
        return '';
    }

    $lower = strtolower($url);
    if (str_starts_with($lower, 'javascript:') || str_starts_with($lower, 'data:text/html')) {
        return '';
    }

    if (preg_match('~^(?:https?://|/|#)~i', $url) === 1) {
        return $url;
    }

    return '';
}

function inlineMarkdown(string $text): string
{
    $tokens = [];
    $store = static function (string $html) use (&$tokens): string {
        $token = '@@GFMD'.count($tokens).'@@';
        $tokens[$token] = $html;

        return $token;
    };

    $text = preg_replace_callback('/`([^`]+)`/u', static fn (array $m): string => $store('<code>'.h((string) $m[1]).'</code>'), $text) ?? $text;
    $text = preg_replace_callback('/!\[([^\]]*)\]\(([^)\s]+)(?:\s+(".*?"|\'.*?\'))?\)/u', static function (array $m) use ($store): string {
        $url = safeContentUrl((string) ($m[2] ?? ''));
        if ($url === '') {
            return h((string) ($m[1] ?? ''));
        }

        return $store('<img loading="lazy" decoding="async" src="'.h($url).'" alt="'.h((string) ($m[1] ?? '')).'">');
    }, $text) ?? $text;
    $text = preg_replace_callback('/\[([^\]]+)\]\(([^)\s]+)\)/u', static function (array $m) use ($store): string {
        $url = safeContentUrl((string) ($m[2] ?? ''));
        if ($url === '') {
            return (string) ($m[1] ?? '');
        }

        return $store('<a href="'.h($url).'" rel="nofollow noopener noreferrer">'.h((string) ($m[1] ?? '')).'</a>');
    }, $text) ?? $text;

    $html = h($text);
    foreach ($tokens as $token => $value) {
        $html = str_replace($token, $value, $html);
    }

    $html = preg_replace('/\*\*(.+?)\*\*/us', '<strong>$1</strong>', $html) ?? $html;
    $html = preg_replace('/(?<!\*)\*([^*]+)\*(?!\*)/us', '<em>$1</em>', $html) ?? $html;

    return $html;
}

function isMarkdownTableDivider(string $line): bool
{
    $line = trim($line);
    if (! str_contains($line, '|')) {
        return false;
    }

    $cells = array_filter(array_map('trim', explode('|', trim($line, '|'))), static fn (string $cell): bool => $cell !== '');
    if ($cells === []) {
        return false;
    }

    foreach ($cells as $cell) {
        if (preg_match('/^:?-{3,}:?$/', $cell) !== 1) {
            return false;
        }
    }

    return true;
}

function markdownTableCells(string $line): array
{
    return array_map('trim', explode('|', trim($line, '|')));
}

function markdownTableToHtml(array $rows): string
{
    if (count($rows) < 2 || ! isMarkdownTableDivider((string) $rows[1])) {
        return '<p>'.inlineMarkdown(implode(' ', array_map('trim', $rows))).'</p>';
    }

    $header = markdownTableCells((string) $rows[0]);
    $bodyRows = array_slice($rows, 2);
    $html = '<div class="article-table-wrap"><table class="article-table"><thead><tr>';
    foreach ($header as $cell) {
        $html .= '<th>'.inlineMarkdown($cell).'</th>';
    }
    $html .= '</tr></thead><tbody>';
    foreach ($bodyRows as $row) {
        if (trim((string) $row) === '') {
            continue;
        }
        $html .= '<tr>';
        foreach (markdownTableCells((string) $row) as $cell) {
            $html .= '<td>'.inlineMarkdown($cell).'</td>';
        }
        $html .= '</tr>';
    }
    $html .= '</tbody></table></div>';

    return $html;
}

function markdownListToHtml(array $items, string $tag): string
{
    $html = '<'.$tag.'>';
    foreach ($items as $item) {
        $html .= '<li>'.inlineMarkdown((string) $item).'</li>';
    }

    return $html.'</'.$tag.'>';
}

function markdownToHtml(string $markdown, string $title = ''): string
{
    $markdown = trim(stripLeadingTitleHeading($markdown, $title));
    if ($markdown === '') {
        return '';
    }

    $lines = preg_split('/\R/u', $markdown) ?: [];
    $html = [];
    $paragraph = [];
    $flushParagraph = static function () use (&$paragraph, &$html): void {
        if ($paragraph === []) {
            return;
        }
        $html[] = '<p>'.inlineMarkdown(implode("\n", $paragraph)).'</p>';
        $paragraph = [];
    };

    for ($i = 0, $total = count($lines); $i < $total; $i++) {
        $line = rtrim((string) $lines[$i]);
        $trimmed = trim($line);
        if ($trimmed === '') {
            $flushParagraph();
            continue;
        }

        if (str_starts_with($trimmed, '```')) {
            $flushParagraph();
            $code = [];
            $i++;
            while ($i < $total && ! str_starts_with(trim((string) $lines[$i]), '```')) {
                $code[] = (string) $lines[$i];
                $i++;
            }
            $html[] = '<pre><code>'.h(implode("\n", $code)).'</code></pre>';
            continue;
        }

        if (preg_match('/^(#{1,6})\s+(.+)$/u', $trimmed, $m) === 1) {
            $flushParagraph();
            $level = min(6, max(1, strlen((string) $m[1])));
            $html[] = '<h'.$level.'>'.inlineMarkdown((string) $m[2]).'</h'.$level.'>';
            continue;
        }

        if (str_contains($trimmed, '|') && isset($lines[$i + 1]) && isMarkdownTableDivider((string) $lines[$i + 1])) {
            $flushParagraph();
            $rows = [$line, (string) $lines[$i + 1]];
            $i += 2;
            while ($i < $total && str_contains((string) $lines[$i], '|') && trim((string) $lines[$i]) !== '') {
                $rows[] = (string) $lines[$i];
                $i++;
            }
            $i--;
            $html[] = markdownTableToHtml($rows);
            continue;
        }

        if (preg_match('/^>\s?(.*)$/u', $trimmed, $m) === 1) {
            $flushParagraph();
            $quote = [(string) $m[1]];
            while (isset($lines[$i + 1]) && preg_match('/^>\s?(.*)$/u', trim((string) $lines[$i + 1]), $next) === 1) {
                $quote[] = (string) $next[1];
                $i++;
            }
            $html[] = '<blockquote><p>'.inlineMarkdown(implode("\n", $quote)).'</p></blockquote>';
            continue;
        }

        if (preg_match('/^[-*+]\s+(.+)$/u', $trimmed, $m) === 1) {
            $flushParagraph();
            $items = [(string) $m[1]];
            while (isset($lines[$i + 1]) && preg_match('/^[-*+]\s+(.+)$/u', trim((string) $lines[$i + 1]), $next) === 1) {
                $items[] = (string) $next[1];
                $i++;
            }
            $html[] = markdownListToHtml($items, 'ul');
            continue;
        }

        if (preg_match('/^\d+[.)]\s+(.+)$/u', $trimmed, $m) === 1) {
            $flushParagraph();
            $items = [(string) $m[1]];
            while (isset($lines[$i + 1]) && preg_match('/^\d+[.)]\s+(.+)$/u', trim((string) $lines[$i + 1]), $next) === 1) {
                $items[] = (string) $next[1];
                $i++;
            }
            $html[] = markdownListToHtml($items, 'ol');
            continue;
        }

        $paragraph[] = $trimmed;
    }
    $flushParagraph();

    return implode("\n", $html);
}

function sanitizeArticleHtml(string $html): string
{
    $html = preg_replace('#<(script|style|iframe|object|embed|form)\b[^>]*>.*?</\1>#is', '', $html) ?? $html;
    $html = preg_replace('#</?(script|style|iframe|object|embed|form)\b[^>]*>#i', '', $html) ?? $html;
    $html = preg_replace('/\s+on[a-z0-9_-]+\s*=\s*("[^"]*"|\'[^\']*\'|[^\s>]+)/i', '', $html) ?? $html;
    $html = preg_replace('/\s+(href|src)\s*=\s*([\'"])\s*(javascript:|data:text\/html)[^\'"]*\2/i', ' $1="#"', $html) ?? $html;

    return $html;
}

function articleContentHtml(array $article): string
{
    $html = is_string($article['content_html'] ?? null) ? trim((string) $article['content_html']) : '';
    if ($html !== '') {
        return sanitizeArticleHtml($html);
    }

    return markdownToHtml((string) ($article['content'] ?? ''), (string) ($article['title'] ?? ''));
}

function keywordTags(string $keywords): array
{
    $keywords = trim($keywords);
    if ($keywords === '') {
        return [];
    }

    $parts = preg_split('/[,，、\n]+/u', $keywords) ?: [];
    $tags = [];
    foreach ($parts as $part) {
        $tag = trim((string) $part);
        if ($tag !== '' && ! in_array($tag, $tags, true)) {
            $tags[] = $tag;
        }
    }

    return array_slice($tags, 0, 12);
}

function renderTemplateString(string $template, array $vars): string
{
    foreach ($vars as $key => $value) {
        $template = str_replace('{'.$key.'}', (string) $value, $template);
    }

    return $template;
}

function jsonLdScript(array $data): string
{
    $json = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
    if (! is_string($json) || $json === '') {
        return '';
    }

    return '<script type="application/ld+json">'.$json.'</script>';
}

function configuredBasePath(array $config): string
{
    $basePath = (string) ($config['base_path'] ?? '');
    if ($basePath === '') {
        $publicBaseUrl = (string) ($config['public_base_url'] ?? '');
        $parsedPath = parse_url($publicBaseUrl, PHP_URL_PATH);
        $basePath = is_string($parsedPath) ? $parsedPath : '';
    }

    $basePath = trim($basePath, '/');

    return $basePath === '' ? '' : '/'.$basePath;
}

function normalizeRequestPath(array $config, string $path): string
{
    $path = '/'.ltrim($path, '/');
    $path = rtrim($path, '/') ?: '/';
    $basePath = configuredBasePath($config);

    if ($basePath !== '' && ($path === $basePath || str_starts_with($path, $basePath.'/'))) {
        $path = substr($path, strlen($basePath));
        $path = is_string($path) && $path !== '' ? $path : '/';
    }

    $path = '/'.ltrim($path, '/');
    if ($path === '/index.php' || str_starts_with($path, '/index.php/')) {
        $path = substr($path, strlen('/index.php'));
        $path = is_string($path) && $path !== '' ? $path : '/';
    }

    return rtrim($path, '/') ?: '/';
}

function shouldUseIndexPhpPath(array $config): bool
{
    $basePath = configuredBasePath($config);
    if ($basePath !== '' && str_ends_with($basePath, '/index.php')) {
        return false;
    }

    $scriptName = (string) ($_SERVER['SCRIPT_NAME'] ?? '');
    $requestPath = parse_url((string) ($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_PATH);
    $requestPath = is_string($requestPath) ? $requestPath : '';

    return str_ends_with($scriptName, '/index.php') && str_contains($requestPath, '/index.php');
}

function sitePath(array $config, string $path): string
{
    $basePath = configuredBasePath($config);
    $path = '/'.ltrim($path, '/');
    if (shouldUseIndexPhpPath($config)) {
        $basePath = rtrim($basePath, '/').'/index.php';
    }

    return ($basePath !== '' ? $basePath : '').($path === '/' ? '/' : $path);
}

function siteUrl(array $config, string $path): string
{
    $publicBaseUrl = rtrim((string) ($config['public_base_url'] ?? ''), '/');
    if ($publicBaseUrl === '') {
        return sitePath($config, $path);
    }

    $scheme = parse_url($publicBaseUrl, PHP_URL_SCHEME);
    $host = parse_url($publicBaseUrl, PHP_URL_HOST);
    if (! is_string($scheme) || ! is_string($host) || $scheme === '' || $host === '') {
        return sitePath($config, $path);
    }

    $port = parse_url($publicBaseUrl, PHP_URL_PORT);
    $origin = $scheme.'://'.$host.(is_int($port) ? ':'.$port : '');

    return $origin.sitePath($config, $path);
}

function staticPublishEnabled(array $config): bool
{
    return (string) siteSettings($config)['front_mode'] === 'static'
        && (bool) ($config['static_publish_enabled'] ?? true);
}

function staticRoot(array $config): string
{
    return rtrim((string) ($config['static_output_dir'] ?? dirname(__DIR__)), '/');
}

function staticBasePath(array $config): string
{
    $basePath = configuredBasePath($config);
    if ($basePath === '/index.php') {
        return '';
    }
    if (str_ends_with($basePath, '/index.php')) {
        $basePath = substr($basePath, 0, -strlen('/index.php'));
    }

    return rtrim((string) $basePath, '/');
}

function staticSitePath(array $config, string $path): string
{
    $basePath = staticBasePath($config);
    $path = '/'.trim($path, '/');
    if ($path === '/') {
        return ($basePath !== '' ? $basePath : '').'/';
    }

    return ($basePath !== '' ? $basePath : '').$path.'/';
}

function frontSitePath(array $config, string $path): string
{
    return staticPublishEnabled($config) ? staticSitePath($config, $path) : sitePath($config, $path);
}

function frontAssetPath(array $config, string $path): string
{
    $basePath = staticBasePath($config);
    $path = '/'.ltrim($path, '/');

    return ($basePath !== '' ? $basePath : '').$path;
}

function frontSiteUrl(array $config, string $path): string
{
    $publicBaseUrl = rtrim((string) ($config['public_base_url'] ?? ''), '/');
    $scheme = parse_url($publicBaseUrl, PHP_URL_SCHEME);
    $host = parse_url($publicBaseUrl, PHP_URL_HOST);
    if (! is_string($scheme) || ! is_string($host) || $scheme === '' || $host === '') {
        return frontSitePath($config, $path);
    }

    $port = parse_url($publicBaseUrl, PHP_URL_PORT);
    $origin = $scheme.'://'.$host.(is_int($port) ? ':'.$port : '');

    return $origin.frontSitePath($config, $path);
}

function renderHomePageHtml(array $config): string
{
    ob_start();
    renderHomePage($config);

    return (string) ob_get_clean();
}

function renderArticlePageHtml(array $config, string $slug): string
{
    ob_start();
    renderArticlePage($config, $slug);

    return (string) ob_get_clean();
}

function writeStaticFile(array $config, string $relativePath, string $html): void
{
    $relativePath = ltrim(str_replace('\\', '/', $relativePath), '/');
    if ($relativePath === '' || str_contains($relativePath, '..')) {
        jsonResponse(500, ['ok' => false, 'error' => 'invalid_static_path']);
    }

    $file = staticRoot($config).'/'.$relativePath;
    $directory = dirname($file);
    if (! is_dir($directory) && ! mkdir($directory, 0755, true) && ! is_dir($directory)) {
        jsonResponse(500, ['ok' => false, 'error' => 'static_directory_not_writable', 'path' => $relativePath]);
    }
    if (file_put_contents($file, $html) === false) {
        jsonResponse(500, ['ok' => false, 'error' => 'static_file_not_writable', 'path' => $relativePath]);
    }
}

function writeJsonFile(string $file, array $payload, string $error): void
{
    $directory = dirname($file);
    if (! is_dir($directory) && ! mkdir($directory, 0755, true) && ! is_dir($directory)) {
        jsonResponse(500, ['ok' => false, 'error' => $error]);
    }

    $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
    if (! is_string($json) || file_put_contents($file, $json) === false) {
        jsonResponse(500, ['ok' => false, 'error' => $error]);
    }
}

function removeStaticArticle(array $config, string $slug): void
{
    if ($slug === '') {
        return;
    }

    $directory = staticRoot($config).'/article/'.safeFileName($slug);
    $file = $directory.'/index.html';
    if (is_file($file)) {
        @unlink($file);
    }
    if (is_dir($directory)) {
        @rmdir($directory);
    }
}

function removeStaticDirectory(string $directory): void
{
    if (! is_dir($directory) || is_link($directory)) {
        return;
    }

    $entries = scandir($directory);
    if (! is_array($entries)) {
        return;
    }

    foreach ($entries as $entry) {
        if ($entry === '.' || $entry === '..') {
            continue;
        }

        $path = $directory.'/'.$entry;
        if (is_dir($path) && ! is_link($path)) {
            removeStaticDirectory($path);
        } elseif (is_file($path) || is_link($path)) {
            @unlink($path);
        }
    }

    @rmdir($directory);
}

function pruneStaticArticlePages(array $config, array $activeSlugs): int
{
    $articleRoot = staticRoot($config).'/article';
    if (! is_dir($articleRoot)) {
        return 0;
    }

    $active = [];
    foreach ($activeSlugs as $slug) {
        $safeSlug = safeFileName((string) $slug);
        if ($safeSlug !== '') {
            $active[$safeSlug] = true;
        }
    }

    $entries = scandir($articleRoot);
    if (! is_array($entries)) {
        return 0;
    }

    $removed = 0;
    foreach ($entries as $entry) {
        if ($entry === '.' || $entry === '..' || isset($active[$entry])) {
            continue;
        }

        $path = $articleRoot.'/'.$entry;
        if (! is_dir($path) || is_link($path)) {
            continue;
        }

        removeStaticDirectory($path);
        $removed++;
    }

    return $removed;
}

function rebuildStaticSite(array $config): array
{
    if (! staticPublishEnabled($config)) {
        return ['enabled' => false, 'articles' => 0];
    }

    writeStaticFile($config, 'index.html', renderHomePageHtml($config));
    writeStaticFile($config, 'llms.txt', renderLlmsText($config));
    writeStaticFile($config, 'sitemap.txt', renderSitemapText($config));

    $count = 0;
    $activeSlugs = [];
    foreach (loadArticles($config) as $article) {
        $slug = (string) ($article['slug'] ?? '');
        if ($slug === '') {
            continue;
        }
        $activeSlugs[] = $slug;
        writeStaticFile($config, 'article/'.safeFileName($slug).'/index.html', renderArticlePageHtml($config, $slug));
        $count++;
    }
    $removed = pruneStaticArticlePages($config, $activeSlugs);

    return ['enabled' => true, 'articles' => $count, 'removed' => $removed];
}

function ensureStorage(array $config): void
{
    $dir = storageDir($config);
    if (! is_dir($dir) && ! mkdir($dir, 0755, true) && ! is_dir($dir)) {
        jsonResponse(500, ['ok' => false, 'error' => 'storage_not_writable']);
    }
}

function ensureImageAssets(array $config): void
{
    $dir = imageAssetsDir($config);
    if (! is_dir($dir) && ! mkdir($dir, 0755, true) && ! is_dir($dir)) {
        jsonResponse(500, ['ok' => false, 'error' => 'image_assets_not_writable']);
    }
}

function maxAssetBytes(array $config): int
{
    return max(1024, (int) ($config['max_asset_bytes'] ?? 5242880));
}

function safeFileName(string $value): string
{
    $safe = preg_replace('/[^a-zA-Z0-9._-]+/', '-', $value);

    return trim(is_string($safe) ? $safe : '', '-_.') ?: hash('sha256', $value);
}

function safeAssetFileName(string $value): string
{
    $safe = preg_replace('/[^a-zA-Z0-9._-]+/', '-', basename($value));

    return trim(is_string($safe) ? $safe : '', '-_.') ?: hash('sha256', $value).'.img';
}

function localizeArticleAssets(array $config, array $article, array $assets): array
{
    $images = is_array($assets['images'] ?? null) ? $assets['images'] : [];
    if ($images === []) {
        return $article;
    }

    ensureImageAssets($config);
    $maxBytes = maxAssetBytes($config);
    $replacements = [];
    foreach ($images as $image) {
        if (! is_array($image)) {
            continue;
        }
        $sourceUrl = trim((string) ($image['source_url'] ?? ''));
        if ($sourceUrl === '') {
            continue;
        }

        $filename = safeAssetFileName((string) ($image['filename'] ?? hash('sha256', $sourceUrl).'.img'));
        $target = imageAssetsDir($config).'/'.$filename;
        $content = '';
        if (is_string($image['content_base64'] ?? null) && (string) $image['content_base64'] !== '') {
            $decoded = base64_decode((string) $image['content_base64'], true);
            $content = is_string($decoded) ? $decoded : '';
            if ($content !== '' && strlen($content) > $maxBytes) {
                $content = '';
            }
        } elseif (preg_match('~^https?://~i', $sourceUrl) === 1) {
            $context = stream_context_create([
                'http' => ['timeout' => 5, 'follow_location' => 1],
                'https' => ['timeout' => 5, 'follow_location' => 1],
            ]);
            $remote = @file_get_contents($sourceUrl, false, $context, 0, $maxBytes + 1);
            $content = is_string($remote) ? $remote : '';
            if ($content !== '' && strlen($content) > $maxBytes) {
                $content = '';
            }
        }

        if ($content !== '' && file_put_contents($target, $content) !== false) {
            $localizedUrl = frontAssetPath($config, '/assets/images/'.$filename);
            $replacements[$sourceUrl] = $localizedUrl;
            $path = parse_url($sourceUrl, PHP_URL_PATH);
            if (is_string($path) && $path !== '') {
                $replacements[$path] = $localizedUrl;
            }
        }
    }

    if ($replacements === []) {
        return $article;
    }

    foreach (['content', 'content_html'] as $field) {
        if (is_string($article[$field] ?? null)) {
            $article[$field] = str_replace(array_keys($replacements), array_values($replacements), (string) $article[$field]);
        }
    }

    return $article;
}

function verifySignedRequest(array $config, string $method, string $path, string $body): array
{
    $expectedKeyId = (string) ($config['key_id'] ?? '');
    $secret = (string) ($config['secret'] ?? '');
    if ($expectedKeyId === '' || $secret === '') {
        jsonResponse(500, ['ok' => false, 'error' => 'agent_not_configured']);
    }

    $keyId = requestHeader('X-GEOFlow-Key-Id');
    $timestamp = requestHeader('X-GEOFlow-Timestamp');
    $nonce = requestHeader('X-GEOFlow-Nonce');
    $idempotencyKey = requestHeader('X-GEOFlow-Idempotency-Key');
    $bodyHash = requestHeader('X-GEOFlow-Body-SHA256');
    $signature = requestHeader('X-GEOFlow-Signature');
    $event = requestHeader('X-GEOFlow-Event');

    if ($keyId === '' || $timestamp === '' || $nonce === '' || $idempotencyKey === '' || $bodyHash === '' || $signature === '' || $event === '') {
        jsonResponse(401, ['ok' => false, 'error' => 'missing_signature_headers']);
    }
    if (! hash_equals($expectedKeyId, $keyId)) {
        jsonResponse(403, ['ok' => false, 'error' => 'key_id_not_allowed']);
    }

    try {
        $requestTime = new DateTimeImmutable($timestamp);
    } catch (Throwable) {
        jsonResponse(401, ['ok' => false, 'error' => 'invalid_timestamp']);
    }

    $clockSkew = max(30, (int) ($config['clock_skew_seconds'] ?? 300));
    if (abs(time() - $requestTime->getTimestamp()) > $clockSkew) {
        jsonResponse(401, ['ok' => false, 'error' => 'timestamp_out_of_range']);
    }

    $bodyForSignature = $method === 'GET' && $body === '' ? '{}' : $body;
    if (! hash_equals(hash('sha256', $bodyForSignature), $bodyHash)) {
        jsonResponse(401, ['ok' => false, 'error' => 'body_hash_mismatch']);
    }

    $expectedSignature = hash_hmac('sha256', $method."\n".$path."\n".$timestamp."\n".$nonce."\n".$bodyHash, $secret);
    if (! hash_equals($expectedSignature, $signature)) {
        jsonResponse(401, ['ok' => false, 'error' => 'signature_invalid']);
    }

    return [
        'event' => $event,
        'idempotency_key' => $idempotencyKey,
    ];
}

function articleFiles(array $config): array
{
    $files = glob(storageDir($config).'/*.json');

    return is_array($files) ? $files : [];
}

function loadArticles(array $config): array
{
    $articles = [];
    foreach (articleFiles($config) as $file) {
        $record = json_decode((string) file_get_contents($file), true);
        if (! is_array($record) || ! is_array($record['article'] ?? null)) {
            continue;
        }
        $record['article']['_file'] = $file;
        $articles[] = $record['article'];
    }

    usort($articles, fn (array $a, array $b): int => strcmp((string) ($b['published_at'] ?? $b['updated_at'] ?? ''), (string) ($a['published_at'] ?? $a['updated_at'] ?? '')));

    return $articles;
}

function findArticle(array $config, string $slug): ?array
{
    foreach (loadArticles($config) as $article) {
        if ((string) ($article['slug'] ?? '') === $slug) {
            return $article;
        }
    }

    return null;
}

function pageHeader(array $config, string $title): void
{
    $settings = siteSettings($config);
    $siteName = (string) $settings['site_name'];
    $themeClass = themeClass($settings);
    $description = renderTemplateString((string) $settings['seo_description_template'], [
        'description' => (string) $settings['site_description'],
        'site_name' => $siteName,
        'keywords' => (string) $settings['site_keywords'],
    ]);
    $pageTitle = renderTemplateString((string) $settings['seo_title_template'], [
        'title' => $title,
        'site_name' => $siteName,
        'category' => '',
    ]);
    $homeUrl = frontSitePath($config, '/');
    echo '<!DOCTYPE html><html lang="zh-CN"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">';
    echo '<title>'.h($pageTitle).'</title><meta name="description" content="'.h($description).'">';
    if ((string) $settings['site_keywords'] !== '') {
        echo '<meta name="keywords" content="'.h((string) $settings['site_keywords']).'">';
    }
    if ((string) $settings['site_favicon'] !== '') {
        echo '<link rel="icon" href="'.h((string) $settings['site_favicon']).'">';
    }
    echo '<link rel="stylesheet" href="'.h(frontAssetPath($config, '/assets/css/site.css')).'">';
    echo '<script defer src="'.h(frontAssetPath($config, '/assets/js/site.js')).'"></script>';
    echo '</head><body class="'.h($themeClass).'"><header><div class="wrap bar"><a class="brand" href="'.h($homeUrl).'">'.h($siteName).'</a><nav><a href="'.h($homeUrl).'">首页</a></nav></div></header><main class="wrap">';
}

function pageFooter(array $config): void
{
    $settings = siteSettings($config);
    echo '</main><footer><div class="wrap">'.h((string) $settings['copyright_info']).'</div></footer></body></html>';
}

function renderHomePage(array $config): void
{
    $settings = siteSettings($config);
    $siteName = (string) $settings['site_name'];
    $articles = array_slice(loadArticles($config), 0, (int) $settings['per_page']);
    pageHeader($config, '首页');
    echo jsonLdScript([
        "@context"=>"https://schema.org",
        "@type"=>"WebSite",
        "name"=>$siteName,
        "url"=>frontSiteUrl($config, '/'),
        "description"=>(string) $settings['site_description'],
    ]);
    echo '<section class="hero"><h1>'.h($siteName).'</h1><p>'.h((string) $settings['site_description']).'</p></section>';
    if ($articles === []) {
        echo '<div class="card empty">暂无文章。请先从 GEOFlow 发布一篇绑定此渠道的文章。</div>';
        pageFooter($config);
        return;
    }

    echo '<section class="list">';
    foreach ($articles as $article) {
        $slug = (string) ($article['slug'] ?? '');
        $title = (string) ($article['title'] ?? '未命名文章');
        $category = is_array($article['category'] ?? null) ? (string) ($article['category']['name'] ?? '默认分类') : '默认分类';
        $publishedAt = substr((string) ($article['published_at'] ?? $article['updated_at'] ?? ''), 0, 10);
        $summary = (string) ($article['excerpt'] ?? $article['meta_description'] ?? '');
        $articleUrl = frontSitePath($config, '/article/'.rawurlencode($slug));
        echo '<article class="card"><div class="meta"><span class="chip">'.h($category).'</span><span>'.h($publishedAt).'</span></div>';
        echo '<h2><a href="'.h($articleUrl).'">'.h($title).'</a></h2>';
        echo '<p class="summary">'.h($summary !== '' ? $summary : mb_substr(strip_tags((string) ($article['content'] ?? '')), 0, 160)).'</p>';
        echo '<a class="read" href="'.h($articleUrl).'">阅读全文</a></article>';
    }
    echo '</section>';
    pageFooter($config);
}

function renderArticlePage(array $config, string $slug): void
{
    $article = findArticle($config, $slug);
    if (! $article) {
        http_response_code(404);
        pageHeader($config, '文章不存在');
        echo '<a class="back" href="'.h(frontSitePath($config, '/')).'">返回首页</a><div class="card empty">文章不存在。</div>';
        pageFooter($config);
        return;
    }

    $title = (string) ($article['title'] ?? '未命名文章');
    $category = is_array($article['category'] ?? null) ? (string) ($article['category']['name'] ?? '默认分类') : '默认分类';
    $publishedAt = substr((string) ($article['published_at'] ?? $article['updated_at'] ?? ''), 0, 10);
    pageHeader($config, $title);
    echo jsonLdScript([
        "@context"=>"https://schema.org",
        "@type"=>"Article",
        "headline"=>$title,
        "description"=>(string) ($article['meta_description'] ?? $article['excerpt'] ?? ''),
        "datePublished"=>(string) ($article['published_at'] ?? ''),
        "dateModified"=>(string) ($article['updated_at'] ?? ''),
        "mainEntityOfPage"=>frontSiteUrl($config, '/article/'.rawurlencode($slug)),
        "author"=>[
            "@type"=>"Person",
            "name"=>is_array($article['author'] ?? null) ? (string) ($article['author']['name'] ?? 'GEOFlow') : 'GEOFlow',
        ],
        "publisher"=>[
            "@type"=>"Organization",
            "name"=>(string) siteSettings($config)['site_name'],
        ],
    ]);
    echo jsonLdScript([
        "@context"=>"https://schema.org",
        "@type"=>"BreadcrumbList",
        "itemListElement"=>[
            ["@type"=>"ListItem", "position"=>1, "name"=>"首页", "item"=>frontSiteUrl($config, '/')],
            ["@type"=>"ListItem", "position"=>2, "name"=>$title, "item"=>frontSiteUrl($config, '/article/'.rawurlencode($slug))],
        ],
    ]);
    echo '<a class="back" href="'.h(frontSitePath($config, '/')).'">返回首页</a><article class="card detail"><div class="meta"><span class="chip">'.h($category).'</span><span>'.h($publishedAt).'</span></div>';
    echo '<h1>'.h($title).'</h1>';
    $excerpt = (string) ($article['excerpt'] ?? '');
    if ($excerpt !== '') {
        echo '<p class="summary">'.h($excerpt).'</p>';
    }
    echo '<div class="content">'.articleContentHtml($article).'</div>';
    $tags = keywordTags((string) ($article['keywords'] ?? ''));
    if ($tags !== []) {
        echo '<div class="tags">';
        foreach ($tags as $tag) {
            echo '<span>'.h($tag).'</span>';
        }
        echo '</div>';
    }
    echo '</article>';
    pageFooter($config);
}

function textMapLine(string $value): string
{
    $value = trim(strip_tags($value));
    $value = preg_replace('/\s+/u', ' ', $value);

    return trim(is_string($value) ? $value : '');
}

function renderLlmsText(array $config): string
{
    $settings = siteSettings($config);
    $siteName = textMapLine((string) $settings['site_name']);
    $description = textMapLine((string) $settings['site_description']);
    $lines = [
        '# '.($siteName !== '' ? $siteName : 'GEOFlow Target Site'),
        '',
    ];
    if ($description !== '') {
        $lines[] = '> '.$description;
        $lines[] = '';
    }

    $lines[] = '## Site';
    $lines[] = '';
    $lines[] = '- Home: '.frontSiteUrl($config, '/');
    $lines[] = '- Sitemap: '.frontSiteUrl($config, '/sitemap.txt');
    $lines[] = '';
    $lines[] = '## Articles';
    $lines[] = '';

    $articles = loadArticles($config);
    if ($articles === []) {
        $lines[] = 'No articles have been published yet.';
    } else {
        foreach (array_slice($articles, 0, 200) as $article) {
            $slug = (string) ($article['slug'] ?? '');
            if ($slug === '') {
                continue;
            }
            $title = textMapLine((string) ($article['title'] ?? 'Untitled Article'));
            $summary = textMapLine((string) ($article['excerpt'] ?? $article['meta_description'] ?? ''));
            if ($summary === '') {
                $summary = textMapLine(mb_substr(strip_tags((string) ($article['content'] ?? '')), 0, 180));
            }
            $line = '- '.($title !== '' ? $title : $slug).' - '.frontSiteUrl($config, '/article/'.rawurlencode($slug));
            if ($summary !== '') {
                $line .= ' - '.$summary;
            }
            $lines[] = $line;
        }
    }

    return rtrim(implode("\n", $lines))."\n";
}

function renderSitemapText(array $config): string
{
    $urls = [frontSiteUrl($config, '/')];
    foreach (loadArticles($config) as $article) {
        $slug = (string) ($article['slug'] ?? '');
        if ($slug !== '') {
            $urls[] = frontSiteUrl($config, '/article/'.rawurlencode($slug));
        }
    }

    return implode("\n", array_values(array_unique($urls)))."\n";
}

function handleHealth(array $config, string $method, string $path, string $body): void
{
    $verified = verifySignedRequest($config, $method, $path, $body);
    jsonResponse(200, [
        'ok' => true,
        'service' => 'geoflow-target-site',
        'event' => $verified['event'],
        'time' => gmdate('c'),
    ]);
}

function handleArticlePublish(array $config, string $method, string $path, string $body): void
{
    $verified = verifySignedRequest($config, $method, $path, $body);
    if ($verified['event'] !== 'article.publish') {
        jsonResponse(422, ['ok' => false, 'error' => 'unsupported_event']);
    }

    $payload = json_decode($body, true);
    if (! is_array($payload) || ! is_array($payload['article'] ?? null)) {
        jsonResponse(422, ['ok' => false, 'error' => 'invalid_article_payload']);
    }

    ensureStorage($config);
    $article = localizeArticleAssets($config, $payload['article'], is_array($payload['assets'] ?? null) ? $payload['assets'] : []);
    $slug = is_scalar($article['slug'] ?? null) && (string) $article['slug'] !== '' ? (string) $article['slug'] : 'article-'.(string) ($article['id'] ?? hash('sha256', (string) $verified['idempotency_key']));
    $file = storageDir($config).'/'.safeFileName($slug).'.json';
    $response = [
        'ok' => true,
        'remote_id' => 'geoflow-'.$slug,
        'remote_url' => frontSiteUrl($config, '/article/'.rawurlencode($slug)),
    ];

    writeJsonFile($file, [
        'received_at' => gmdate('c'),
        'idempotency_key' => $verified['idempotency_key'],
        'article' => $article,
        'response' => $response,
    ], 'article_storage_not_writable');

    $response['static'] = rebuildStaticSite($config);

    jsonResponse(200, $response);
}

function handleArticleUpdate(array $config, string $method, string $path, string $body, string $pathSlug): void
{
    $verified = verifySignedRequest($config, $method, $path, $body);
    if ($verified['event'] !== 'article.update') {
        jsonResponse(422, ['ok' => false, 'error' => 'unsupported_event']);
    }

    $payload = json_decode($body, true);
    if (! is_array($payload) || ! is_array($payload['article'] ?? null)) {
        jsonResponse(422, ['ok' => false, 'error' => 'invalid_article_payload']);
    }

    ensureStorage($config);
    $article = localizeArticleAssets($config, $payload['article'], is_array($payload['assets'] ?? null) ? $payload['assets'] : []);
    $slug = is_scalar($article['slug'] ?? null) && (string) $article['slug'] !== '' ? (string) $article['slug'] : $pathSlug;
    if ($slug === '') {
        jsonResponse(422, ['ok' => false, 'error' => 'missing_slug']);
    }

    $file = storageDir($config).'/'.safeFileName($slug).'.json';
    $response = [
        'ok' => true,
        'updated' => true,
        'remote_id' => 'geoflow-'.$slug,
        'remote_url' => frontSiteUrl($config, '/article/'.rawurlencode($slug)),
    ];

    writeJsonFile($file, [
        'received_at' => gmdate('c'),
        'idempotency_key' => $verified['idempotency_key'],
        'article' => $article,
        'response' => $response,
    ], 'article_storage_not_writable');

    $response['static'] = rebuildStaticSite($config);

    jsonResponse(200, $response);
}

function handleArticleDelete(array $config, string $method, string $path, string $body, string $pathSlug): void
{
    $verified = verifySignedRequest($config, $method, $path, $body);
    if ($verified['event'] !== 'article.delete') {
        jsonResponse(422, ['ok' => false, 'error' => 'unsupported_event']);
    }

    $payload = json_decode($body, true);
    $article = is_array($payload) && is_array($payload['article'] ?? null) ? $payload['article'] : [];
    $slug = is_scalar($article['slug'] ?? null) && (string) $article['slug'] !== '' ? (string) $article['slug'] : $pathSlug;
    if ($slug === '') {
        jsonResponse(422, ['ok' => false, 'error' => 'missing_slug']);
    }

    ensureStorage($config);
    $file = storageDir($config).'/'.safeFileName($slug).'.json';
    if (is_file($file)) {
        @unlink($file);
    }
    removeStaticArticle($config, $slug);
    $static = rebuildStaticSite($config);

    jsonResponse(200, [
        'ok' => true,
        'deleted' => true,
        'remote_id' => 'geoflow-'.$slug,
        'static' => $static,
    ]);
}

function handleSiteSettingsUpdate(array $config, string $method, string $path, string $body): void
{
    $verified = verifySignedRequest($config, $method, $path, $body);
    if ($verified['event'] !== 'site.settings.update') {
        jsonResponse(422, ['ok' => false, 'error' => 'unsupported_event']);
    }

    $payload = json_decode($body, true);
    if (! is_array($payload) || ! is_array($payload['settings'] ?? null)) {
        jsonResponse(422, ['ok' => false, 'error' => 'invalid_settings_payload']);
    }

    ensureStorage($config);
    $settings = normalizeSiteSettings($payload['settings'], $config);
    writeJsonFile(siteSettingsFile($config), $settings, 'site_settings_not_writable');
    $static = rebuildStaticSite($config);

    jsonResponse(200, [
        'ok' => true,
        'updated' => true,
        'site_name' => $settings['site_name'],
        'active_theme' => $settings['active_theme'],
        'static' => $static,
    ]);
}

$method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
$path = parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH);
$path = is_string($path) && $path !== '' ? rtrim($path, '/') : '/';
$path = normalizeRequestPath($config, $path === '' ? '/' : $path);
$body = file_get_contents('php://input');
$body = is_string($body) ? $body : '';

if ($method === 'GET' && $path === '/geoflow-agent/v1/health') {
    handleHealth($config, $method, $path, $body);
}
if ($method === 'POST' && $path === '/geoflow-agent/v1/articles') {
    handleArticlePublish($config, $method, $path, $body);
}
// POST /geoflow-agent/v1/articles/{slug}/update
if ($method === 'POST' && preg_match('#^/geoflow-agent/v1/articles/([^/]+)/update$#', $path, $m) === 1) {
    handleArticleUpdate($config, $method, $path, $body, rawurldecode((string) $m[1]));
}
// POST /geoflow-agent/v1/articles/{slug}/delete
if ($method === 'POST' && preg_match('#^/geoflow-agent/v1/articles/([^/]+)/delete$#', $path, $m) === 1) {
    handleArticleDelete($config, $method, $path, $body, rawurldecode((string) $m[1]));
}
if ($method === 'POST' && $path === '/geoflow-agent/v1/site-settings') {
    handleSiteSettingsUpdate($config, $method, $path, $body);
}
if ($method === 'GET' && $path === '/') {
    renderHomePage($config);
    exit;
}
if ($method === 'GET' && $path === '/llms.txt') {
    textResponse(renderLlmsText($config));
}
if ($method === 'GET' && $path === '/sitemap.txt') {
    textResponse(renderSitemapText($config));
}
if ($method === 'GET' && str_starts_with($path, '/article/')) {
    renderArticlePage($config, rawurldecode(substr($path, 9)));
    exit;
}

http_response_code(404);
renderHomePage($config);
PHP;
    }

    private function basePath(string $endpointUrl): string
    {
        return DistributionRewriteRuleGenerator::basePathForEndpoint($endpointUrl);
    }

    private function slug(string $value): string
    {
        $slug = preg_replace('/[^a-zA-Z0-9]+/', '-', strtolower($value));

        return trim(is_string($slug) ? $slug : '', '-') ?: 'channel';
    }
}
