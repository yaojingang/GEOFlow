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
            'article_text_ads' => method_exists($channel, 'effectiveArticleTextAds') ? $channel->effectiveArticleTextAds() : [],
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
            ."    'article_text_ads' => ".var_export($config['article_text_ads'], true).",\n"
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
        $assetVersion = $this->targetAssetVersion($channel);

        return '<!DOCTYPE html><html lang="zh-CN"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">'
            .'<title>'.htmlspecialchars('首页 - '.$siteName, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8').'</title>'
            .'<meta name="description" content="'.htmlspecialchars($description, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8').'">'
            .'<link rel="stylesheet" href="assets/css/site.css?v='.$assetVersion.'"><script defer src="assets/js/site.js?v='.$assetVersion.'"></script>'
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

        if (str_contains($theme, 'apparel-sourcing-intelligence')) {
            return 'target-theme-apparel';
        }

        if (str_contains($theme, 'fashion-insight')) {
            return 'target-theme-fashion';
        }

        if (str_contains($theme, 'boutiquesourcingpro')) {
            return 'target-theme-boutique';
        }

        return 'target-theme-default';
    }

    private function targetAssetVersion(DistributionChannel $channel): string
    {
        return substr(hash('sha256', implode('|', [
            (string) ($channel->template_key ?? ''),
            (string) ($channel->updated_at ?? ''),
            (string) config('geoflow.app_version', ''),
            hash('sha256', $this->targetSiteCss()),
            hash('sha256', $this->targetSiteJs()),
        ])), 0, 12);
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
.article-text-ads{display:grid;gap:10px;margin:18px 0;padding:12px 0;border-top:1px solid rgba(148,163,184,.26);border-bottom:1px solid rgba(148,163,184,.26);background:transparent;font:inherit}.article-text-ads--content-top{margin-top:0;margin-bottom:22px}.article-text-ads--content-bottom{margin-top:26px;margin-bottom:0}.article-text-ad-module{display:flex;flex-direction:column;gap:6px;background:transparent}.article-text-ad-link{display:inline-flex;align-items:center;width:max-content;max-width:100%;color:var(--article-text-ad-color,#2563eb);font:inherit;font-weight:700;line-height:1.65;text-decoration:none}.article-text-ad-link:hover{text-decoration:underline;text-underline-offset:4px}.article-text-ad-text{overflow-wrap:anywhere}
.tags{display:flex;flex-wrap:wrap;gap:8px;margin-top:28px;padding-top:22px;border-top:1px solid #e5e7eb}.tags span{display:inline-flex;border:1px solid #e5e7eb;background:#f9fafb;color:#4b5563;border-radius:999px;padding:5px 10px;font-size:13px}
.empty{padding:52px;text-align:center;color:#6b7280}.back{display:inline-block;margin:28px 0 18px;color:#4b5563;text-decoration:none}
footer{border-top:1px solid #e5e7eb;color:#6b7280;font-size:13px;padding:24px 0 36px}
body.target-theme-toutiao{background:#fffafa}.target-theme-toutiao header{border-bottom-color:#fecaca}.target-theme-toutiao .brand,.target-theme-toutiao h2 a:hover,.target-theme-toutiao .read{color:#dc2626}.target-theme-toutiao .chip{border-color:#fecaca;background:#fef2f2;color:#b91c1c}.target-theme-toutiao .card{border-color:#fee2e2}
body.target-theme-netease{background:#f7f7f7}.target-theme-netease header{border-top:3px solid #d7000f}.target-theme-netease .brand,.target-theme-netease h2 a:hover,.target-theme-netease .read{color:#b91c1c}.target-theme-netease .chip{border-color:#fee2e2;background:#fff1f2;color:#991b1b}.target-theme-netease .hero h1{font-weight:900}.target-theme-netease .card{box-shadow:none}
body.target-theme-tdwh{background:#f8fbff}.target-theme-tdwh header{border-bottom-color:#bfdbfe}.target-theme-tdwh .brand,.target-theme-tdwh h2 a:hover,.target-theme-tdwh .read{color:#1d4ed8}.target-theme-tdwh .chip{border-color:#bfdbfe;background:#eff6ff;color:#1e40af}.target-theme-tdwh .card{border-color:#dbeafe}
body.target-theme-apparel{background:#f7f4ee;color:#1d2527;font-family:Georgia,"Times New Roman",serif}.target-theme-apparel header{background:#fffdf8;border-top:4px solid #24483f;border-bottom-color:#d9d3c7}.target-theme-apparel .brand,.target-theme-apparel h2 a:hover,.target-theme-apparel .read{color:#24483f}.target-theme-apparel .hero h1,.target-theme-apparel .detail h1{font-family:Georgia,"Times New Roman",serif;letter-spacing:0}.target-theme-apparel .chip{border-color:#d9d3c7;background:#fffdf8;color:#a87628}.target-theme-apparel .card{border-color:#d9d3c7;background:#fffdf8;box-shadow:0 18px 50px rgba(29,37,39,.11)}
.target-theme-apparel{--asi-ink:#1d2527;--asi-muted:#657173;--asi-soft:#f7f4ee;--asi-paper:#fffdf8;--asi-line:#d9d3c7;--asi-green:#24483f;--asi-sage:#6f8379;--asi-brass:#a87628;--asi-red:#8f352c;--asi-shadow:0 18px 50px rgba(29,37,39,.11);background:var(--asi-soft);color:var(--asi-ink);font-family:Georgia,"Times New Roman",serif}.target-theme-apparel a{color:inherit;text-decoration:none}.target-theme-apparel .wrap{max-width:none;padding:0}.target-theme-apparel .asi-shell{width:min(1180px,calc(100vw - 48px));margin:0 auto}.target-theme-apparel header{position:static;border:0;background:transparent}.target-theme-apparel main.wrap{max-width:none;padding:0}.target-theme-apparel .asi-topline{background:var(--asi-green);color:#fbf6ea;font-family:"Segoe UI",Tahoma,sans-serif;font-size:12px;letter-spacing:.08em;text-transform:uppercase}.target-theme-apparel .asi-topline-row{display:flex;justify-content:space-between;gap:24px;padding:9px 0}.target-theme-apparel .asi-masthead{border-bottom:1px solid var(--asi-line);background:rgba(255,253,248,.97)}.target-theme-apparel .asi-masthead-row{display:grid;grid-template-columns:minmax(0,1fr) auto;align-items:end;gap:22px;padding:24px 0 22px}.target-theme-apparel .asi-brand{display:grid;gap:8px;min-width:0}.target-theme-apparel .asi-brand-kicker{color:var(--asi-muted);font-family:"Segoe UI",Tahoma,sans-serif;font-size:13px;letter-spacing:.09em;line-height:1.4;text-transform:uppercase}.target-theme-apparel .asi-brand-name{max-width:820px;font-size:clamp(34px,5vw,62px);font-weight:700;letter-spacing:0;line-height:.95;overflow-wrap:anywhere}.target-theme-apparel .asi-search{display:grid;grid-template-columns:minmax(120px,1fr) auto;align-items:center;width:min(340px,36vw);border:1px solid var(--asi-line);border-radius:999px;background:#fff;padding:5px}.target-theme-apparel .asi-search input{min-width:0;border:0;background:transparent;color:var(--asi-ink);font-family:"Segoe UI",Tahoma,sans-serif;font-size:14px;outline:none;padding:8px 12px}.target-theme-apparel .asi-search button{border:0;border-radius:999px;background:var(--asi-green);color:#fffdf8;font-family:"Segoe UI",Tahoma,sans-serif;font-size:13px;font-weight:700;padding:8px 14px}.target-theme-apparel .asi-nav{display:flex;justify-content:center;gap:24px;border-top:1px solid var(--asi-line);padding:12px 0;color:#394548;font-family:"Segoe UI",Tahoma,sans-serif;font-size:14px;overflow-x:auto}.target-theme-apparel .asi-nav a{border-bottom:2px solid transparent;padding:2px 0;white-space:nowrap}.target-theme-apparel .asi-nav a:hover,.target-theme-apparel .asi-nav .is-active{border-color:var(--asi-brass);color:var(--asi-green)}.target-theme-apparel .asi-page{padding:34px 0 54px}.target-theme-apparel .asi-hero{display:grid;grid-template-columns:minmax(0,1.2fr) minmax(320px,.8fr);gap:28px;align-items:stretch}.target-theme-apparel .asi-lead{display:grid;grid-template-rows:minmax(290px,1fr) auto;min-height:510px;border-bottom:1px solid var(--asi-ink)}.target-theme-apparel .asi-visual{position:relative;display:block;overflow:hidden;border-radius:6px;background:#e7dfd2;min-height:110px}.target-theme-apparel .asi-visual img{position:absolute;inset:0;width:100%;height:100%;object-fit:cover;transform:scale(1.01);transition:transform .35s ease}.target-theme-apparel .asi-visual:hover img{transform:scale(1.05)}.target-theme-apparel .asi-visual-pattern{position:absolute;inset:0;display:grid;place-items:center;background:linear-gradient(135deg,rgba(36,72,63,.18),rgba(168,118,40,.2)),repeating-linear-gradient(90deg,rgba(29,37,39,.08) 0 1px,transparent 1px 32px),repeating-linear-gradient(0deg,rgba(29,37,39,.06) 0 1px,transparent 1px 26px),#efe8dc}.target-theme-apparel .asi-visual-pattern span{display:grid;place-items:center;width:74px;height:74px;border:1px solid rgba(36,72,63,.22);border-radius:50%;background:rgba(255,253,248,.82);color:var(--asi-green);font-family:"Segoe UI",Tahoma,sans-serif;font-size:32px;font-weight:800}.target-theme-apparel .asi-lead-visual{min-height:320px;box-shadow:var(--asi-shadow)}.target-theme-apparel .asi-visual-badge{position:absolute;left:18px;bottom:18px;max-width:calc(100% - 36px);border:1px solid rgba(217,211,199,.88);border-radius:6px;background:rgba(255,253,248,.94);font-family:"Segoe UI",Tahoma,sans-serif;font-size:12px;letter-spacing:.08em;line-height:1.4;overflow-wrap:anywhere;padding:10px 12px;text-transform:uppercase}.target-theme-apparel .asi-lead-copy{padding-top:22px}.target-theme-apparel .asi-kicker,.target-theme-apparel .asi-panel-kicker,.target-theme-apparel .asi-article-section{color:var(--asi-red);font-family:"Segoe UI",Tahoma,sans-serif;font-size:12px;font-weight:800;letter-spacing:.1em;line-height:1.5;text-transform:uppercase}.target-theme-apparel .asi-lead h1,.target-theme-apparel .asi-article-head h1{margin:0;font-size:clamp(36px,4vw,58px);font-weight:700;letter-spacing:0;line-height:.99;overflow-wrap:anywhere}.target-theme-apparel .asi-lead h1{margin-top:12px;max-width:720px}.target-theme-apparel .asi-lead p,.target-theme-apparel .asi-article-head p{max-width:680px;margin:16px 0 0;color:#465153;font-family:"Segoe UI",Tahoma,sans-serif;font-size:17px;line-height:1.58}.target-theme-apparel .asi-hero-rail{display:grid;grid-template-rows:auto 1fr;gap:18px}.target-theme-apparel .asi-briefing,.target-theme-apparel .asi-briefing-panel{background:var(--asi-green);color:#fffaf0}.target-theme-apparel .asi-briefing{display:grid;align-content:space-between;min-height:172px;border-radius:6px;padding:22px}.target-theme-apparel .asi-briefing span,.target-theme-apparel .asi-briefing small{font-family:"Segoe UI",Tahoma,sans-serif;font-size:12px;letter-spacing:.08em;text-transform:uppercase}.target-theme-apparel .asi-briefing strong{display:block;margin-top:18px;font-size:28px;line-height:1.05}.target-theme-apparel .asi-briefing div{display:grid;grid-template-columns:1fr auto;gap:18px;border-top:1px solid rgba(255,255,255,.24);margin-top:20px;padding-top:16px}.target-theme-apparel .asi-headline-stack,.target-theme-apparel .asi-feed-section,.target-theme-apparel .asi-panel{border:1px solid var(--asi-line);border-radius:6px;background:var(--asi-paper)}.target-theme-apparel .asi-headline-stack{overflow:hidden}.target-theme-apparel .asi-mini-story{display:grid;grid-template-columns:112px minmax(0,1fr);gap:16px;min-height:128px;border-bottom:1px solid var(--asi-line);padding:18px}.target-theme-apparel .asi-mini-story:last-child{border-bottom:0}.target-theme-apparel .asi-mini-visual{min-height:92px}.target-theme-apparel .asi-mini-story h2{margin:0;font-size:20px;letter-spacing:0;line-height:1.12;overflow-wrap:anywhere}.target-theme-apparel .asi-meta{display:flex;flex-wrap:wrap;gap:8px;margin-top:10px;color:var(--asi-muted);font-family:"Segoe UI",Tahoma,sans-serif;font-size:12px;letter-spacing:.04em;line-height:1.5;text-transform:uppercase}.target-theme-apparel .asi-meta a,.target-theme-apparel .asi-meta span:first-child{color:var(--asi-red);font-weight:800}.target-theme-apparel .asi-content-grid{display:grid;grid-template-columns:minmax(0,1fr) 340px;gap:34px;align-items:start;margin-top:38px}.target-theme-apparel .asi-feed-section{border-top:3px solid var(--asi-ink);overflow:hidden}.target-theme-apparel .asi-section-head,.target-theme-apparel .asi-panel-head{display:flex;align-items:center;justify-content:space-between;gap:16px;border-bottom:1px solid var(--asi-line);margin:0 18px;padding:18px 0}.target-theme-apparel .asi-section-head span,.target-theme-apparel .asi-panel-head h2{margin:0;color:var(--asi-ink);font-family:"Segoe UI",Tahoma,sans-serif;font-size:18px;font-weight:800;letter-spacing:.08em;line-height:1.25;text-transform:uppercase}.target-theme-apparel .asi-feed-list{display:grid}.target-theme-apparel .asi-card{display:grid;grid-template-columns:150px minmax(0,1fr);gap:20px;align-items:start;border-bottom:1px solid var(--asi-line);padding:20px 18px}.target-theme-apparel .asi-card:last-child{border-bottom:0}.target-theme-apparel .asi-card-visual{aspect-ratio:4/3;min-height:112px}.target-theme-apparel .asi-card h2{margin:6px 0 0;font-size:25px;font-weight:700;letter-spacing:0;line-height:1.14;overflow-wrap:anywhere}.target-theme-apparel .asi-card p{margin:9px 0 0;color:#4d595b;font-family:"Segoe UI",Tahoma,sans-serif;font-size:14px;line-height:1.6}.target-theme-apparel .asi-sidebar{display:grid;gap:22px}.target-theme-apparel .asi-panel{padding-bottom:18px}.target-theme-apparel .asi-briefing-panel{border-color:var(--asi-green);padding:22px}.target-theme-apparel .asi-briefing-panel h2{margin:14px 0 0;font-size:28px;line-height:1.08}.target-theme-apparel .asi-briefing-panel p{color:rgba(255,250,240,.78);font-family:"Segoe UI",Tahoma,sans-serif;line-height:1.6}.target-theme-apparel .asi-rank-list{display:grid}.target-theme-apparel .asi-rank-item{display:grid;grid-template-columns:34px minmax(0,1fr);gap:12px;border-bottom:1px solid var(--asi-line);padding:14px 18px}.target-theme-apparel .asi-rank-item:last-child{border-bottom:0}.target-theme-apparel .asi-rank-item span{color:var(--asi-brass);font-size:24px;font-weight:700}.target-theme-apparel .asi-rank-item strong{font-size:17px;line-height:1.22}.target-theme-apparel .asi-article-layout{display:grid;grid-template-columns:minmax(0,1fr) 320px;gap:34px;padding:34px 0 56px}.target-theme-apparel .asi-breadcrumb{display:flex;gap:8px;margin-bottom:18px;color:var(--asi-muted);font-family:"Segoe UI",Tahoma,sans-serif;font-size:13px;text-transform:uppercase}.target-theme-apparel .asi-article{border-top:3px solid var(--asi-ink);background:var(--asi-paper);border:1px solid var(--asi-line);border-radius:6px;padding:30px}.target-theme-apparel .asi-article-head{border-bottom:1px solid var(--asi-line);padding-bottom:24px}.target-theme-apparel .asi-post-info{display:flex;flex-wrap:wrap;gap:10px;margin-top:16px;color:var(--asi-muted);font-family:"Segoe UI",Tahoma,sans-serif;font-size:13px;text-transform:uppercase}.target-theme-apparel .asi-article-visual{aspect-ratio:16/9;margin:28px 0;min-height:280px}.target-theme-apparel .content,.target-theme-apparel .asi-prose{font-size:18px;line-height:1.92;color:#263033}.target-theme-apparel .content h2,.target-theme-apparel .asi-prose h2{font-size:28px;line-height:1.18;margin:1.6em 0 .65em}.target-theme-apparel .tags,.target-theme-apparel .asi-tag-list{display:flex;flex-wrap:wrap;gap:8px;margin-top:28px;padding-top:22px;border-top:1px solid var(--asi-line)}.target-theme-apparel .tags span,.target-theme-apparel .asi-tag-list span{display:inline-flex;border:1px solid var(--asi-line);border-radius:999px;background:#fff;color:#394548;font-family:"Segoe UI",Tahoma,sans-serif;font-size:13px;font-weight:700;padding:7px 11px}.target-theme-apparel footer{border-top:1px solid var(--asi-line);background:var(--asi-paper);color:var(--asi-muted);font-family:"Segoe UI",Tahoma,sans-serif;padding:28px 0}@media(max-width:900px){.target-theme-apparel .asi-shell{width:min(100% - 32px,1180px)}.target-theme-apparel .asi-topline-row,.target-theme-apparel .asi-masthead-row{display:grid;gap:10px}.target-theme-apparel .asi-search{width:100%}.target-theme-apparel .asi-hero,.target-theme-apparel .asi-content-grid,.target-theme-apparel .asi-article-layout{grid-template-columns:1fr}.target-theme-apparel .asi-card{grid-template-columns:1fr}.target-theme-apparel .asi-card-visual{min-height:200px}.target-theme-apparel .asi-mini-story{grid-template-columns:96px minmax(0,1fr)}.target-theme-apparel .asi-article{padding:22px}}
body.target-theme-fashion{font-family:"Segoe UI",Tahoma,sans-serif;background:#faf6f0;color:#1f1a17}.target-theme-fashion .wrap{max-width:1280px;padding:0 32px}.target-theme-fashion header{position:fixed;left:0;right:0;top:0;background:rgba(250,246,240,.82);border-bottom:1px solid rgba(239,235,228,.78);backdrop-filter:blur(14px);z-index:20}.target-theme-fashion header .bar{height:76px}.target-theme-fashion .brand{font-family:Georgia,"Times New Roman",serif;font-size:25px;font-weight:600;letter-spacing:.08em}.target-theme-fashion nav a{color:rgba(31,26,23,.62);font-size:13px}.target-theme-fashion main.wrap{padding-top:76px}.target-theme-fashion .fashion-hero{position:relative;text-align:center;overflow:hidden;padding:88px 0 112px}.target-theme-fashion .fashion-wordmark{position:absolute;left:50%;top:4px;transform:translateX(-50%);font-family:Georgia,"Times New Roman",serif;font-size:clamp(96px,16vw,220px);font-weight:800;letter-spacing:.08em;line-height:.85;color:rgba(239,235,228,.68);pointer-events:none;user-select:none}.target-theme-fashion .fashion-hero-inner{position:relative;z-index:1;max-width:820px;margin:0 auto}.target-theme-fashion .fashion-kicker{display:inline-flex;padding-bottom:9px;border-bottom:1px solid rgba(197,168,128,.34);color:#c5a880;text-transform:uppercase;letter-spacing:.25em;font-size:10px;font-weight:700}.target-theme-fashion .fashion-hero h1{margin:36px 0 22px;font-family:Georgia,"Times New Roman",serif;font-size:clamp(44px,7vw,88px);line-height:1.03;font-weight:800;letter-spacing:0}.target-theme-fashion .fashion-hero p{margin:0 auto;max-width:720px;color:rgba(31,26,23,.62);text-transform:uppercase;letter-spacing:.24em;font-size:13px;line-height:1.85;font-weight:600}.target-theme-fashion .fashion-search{display:flex;gap:14px;max-width:700px;margin:38px auto 0}.target-theme-fashion .fashion-search input{flex:1;min-width:0;border:1px solid #efebe4;border-radius:18px;background:rgba(255,255,255,.66);padding:16px 22px;font-size:13px;color:#1f1a17;outline:none}.target-theme-fashion .fashion-search button{border:0;border-radius:18px;background:#1f1a17;color:#fff;padding:0 38px;text-transform:uppercase;letter-spacing:.2em;font-size:12px;font-weight:800;box-shadow:0 14px 28px rgba(31,26,23,.18)}.target-theme-fashion .fashion-section{margin-bottom:84px}.target-theme-fashion .fashion-section-head{display:flex;align-items:center;justify-content:space-between;border-bottom:1px solid rgba(239,235,228,.82);padding-bottom:18px;margin-bottom:40px}.target-theme-fashion .fashion-section-head h2{margin:0;font-family:Georgia,"Times New Roman",serif;font-size:28px;letter-spacing:0}.target-theme-fashion .fashion-section-head span{font-size:10px;text-transform:uppercase;letter-spacing:.25em;color:rgba(31,26,23,.5);font-weight:700}.target-theme-fashion .fashion-feature-grid{display:grid;grid-template-columns:minmax(0,7fr) minmax(320px,5fr);gap:32px;align-items:stretch}.target-theme-fashion .fashion-feature-card{position:relative;min-height:540px;overflow:hidden;border:1px solid rgba(239,235,228,.72);border-radius:26px;background:linear-gradient(135deg,#1f1a17,#c5a88066);box-shadow:0 20px 52px rgba(31,26,23,.08)}.target-theme-fashion .fashion-feature-card img{position:absolute;inset:0;width:100%;height:100%;object-fit:cover}.target-theme-fashion .fashion-feature-overlay{position:absolute;inset:0;background:linear-gradient(to top,rgba(31,26,23,.94),rgba(31,26,23,.55),rgba(31,26,23,.08))}.target-theme-fashion .fashion-feature-content{position:absolute;left:44px;right:44px;bottom:38px;color:#fff}.target-theme-fashion .fashion-feature-content>span{display:inline-flex;border-radius:999px;background:#c5a880;color:#1f1a17;padding:6px 12px;font-size:10px;text-transform:uppercase;letter-spacing:.18em;font-weight:800}.target-theme-fashion .fashion-feature-content h3{font-family:Georgia,"Times New Roman",serif;font-size:clamp(30px,4vw,48px);line-height:1.06;margin:20px 0 14px}.target-theme-fashion .fashion-feature-content a{color:inherit;text-decoration:none}.target-theme-fashion .fashion-feature-content p{max-width:680px;color:rgba(255,255,255,.82);font-size:14px;line-height:1.8}.target-theme-fashion .fashion-feature-content div{display:flex;align-items:center;justify-content:space-between;border-top:1px solid rgba(255,255,255,.2);padding-top:18px;margin-top:22px}.target-theme-fashion .fashion-feature-content time{font-size:11px;letter-spacing:.14em;color:rgba(255,255,255,.55)}.target-theme-fashion .fashion-feature-content div a,.target-theme-fashion .fashion-feature-side article>a{font-size:10px;text-transform:uppercase;letter-spacing:.22em;font-weight:800}.target-theme-fashion .fashion-feature-side{display:flex;flex-direction:column;gap:24px}.target-theme-fashion .fashion-feature-side article{flex:1;border:1px solid rgba(239,235,228,.72);border-radius:24px;background:rgba(255,255,255,.42);padding:28px;display:flex;flex-direction:column;justify-content:space-between;transition:background .25s,box-shadow .25s}.target-theme-fashion .fashion-feature-side article:hover,.target-theme-fashion .fashion-card:hover{background:rgba(255,255,255,.78);box-shadow:0 18px 42px rgba(31,26,23,.08)}.target-theme-fashion .fashion-feature-side article div,.target-theme-fashion .fashion-card-meta{display:flex;justify-content:space-between;gap:16px;color:rgba(31,26,23,.54);font-size:10px;text-transform:uppercase;letter-spacing:.18em;font-weight:700}.target-theme-fashion .fashion-feature-side span,.target-theme-fashion .fashion-card-meta span{color:#c5a880}.target-theme-fashion .fashion-feature-side h3,.target-theme-fashion .fashion-card h3{font-family:Georgia,"Times New Roman",serif;line-height:1.15;letter-spacing:0}.target-theme-fashion .fashion-feature-side h3{font-size:24px;margin:18px 0 12px}.target-theme-fashion .fashion-feature-side a,.target-theme-fashion .fashion-card a{color:#1f1a17;text-decoration:none}.target-theme-fashion .fashion-feature-side a:hover,.target-theme-fashion .fashion-card a:hover{color:#c5a880}.target-theme-fashion .fashion-feature-side p,.target-theme-fashion .fashion-card p{color:rgba(31,26,23,.68);font-size:13px;line-height:1.75}.target-theme-fashion .fashion-feature-placeholder{align-items:center;text-align:center;color:rgba(31,26,23,.42);text-transform:uppercase;letter-spacing:.24em;font-size:12px}.target-theme-fashion .fashion-card-grid{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:32px}.target-theme-fashion .fashion-card{border:1px solid rgba(239,235,228,.72);border-radius:24px;background:rgba(255,255,255,.42);padding:22px;display:flex;flex-direction:column;transition:background .25s,box-shadow .25s}.target-theme-fashion .fashion-card-media{display:block;aspect-ratio:4/3;border-radius:18px;overflow:hidden;background:linear-gradient(135deg,#faf6f0,#efebe4);margin-bottom:20px}.target-theme-fashion .fashion-card-media img{width:100%;height:100%;object-fit:cover;transition:transform .45s}.target-theme-fashion .fashion-card:hover img{transform:scale(1.04)}.target-theme-fashion .fashion-card h3{font-size:25px;margin:16px 0 10px}.target-theme-fashion .fashion-card-foot{margin-top:auto;border-top:1px solid rgba(239,235,228,.75);padding-top:18px;text-align:right}.target-theme-fashion .fashion-card-foot a{font-size:10px;text-transform:uppercase;letter-spacing:.22em;font-weight:800}.target-theme-fashion .fashion-empty{border:1px solid #efebe4;border-radius:26px;background:rgba(255,255,255,.34);padding:64px;text-align:center;max-width:760px;margin:0 auto 80px}.target-theme-fashion .fashion-empty h2{font-family:Georgia,"Times New Roman",serif;font-size:28px}.target-theme-fashion .detail{max-width:900px;margin:28px auto 76px;border:1px solid rgba(239,235,228,.8);border-radius:26px;background:rgba(255,255,255,.72);padding:48px;box-shadow:0 20px 52px rgba(31,26,23,.06)}.target-theme-fashion .detail h1{font-size:clamp(38px,5vw,64px);line-height:1.08;letter-spacing:0;margin:16px 0 18px}.target-theme-fashion .fashion-article-kicker{display:flex;gap:14px;color:#c5a880;text-transform:uppercase;letter-spacing:.2em;font-size:11px;font-weight:800}.target-theme-fashion .summary{font-size:16px;line-height:1.85;color:rgba(31,26,23,.6);margin-bottom:30px}.target-theme-fashion .content{font-family:Georgia,"Times New Roman",serif;font-size:18px;line-height:1.95}.target-theme-fashion .back{margin-top:32px}.target-theme-fashion footer{border-top-color:#efebe4;padding:44px 0 52px}@media(max-width:900px){.target-theme-fashion .wrap{padding:0 20px}.target-theme-fashion .fashion-search{flex-direction:column}.target-theme-fashion .fashion-search button{padding:15px 28px}.target-theme-fashion .fashion-feature-grid,.target-theme-fashion .fashion-card-grid{grid-template-columns:1fr}.target-theme-fashion .fashion-feature-card{min-height:420px}.target-theme-fashion .fashion-feature-content{left:26px;right:26px}.target-theme-fashion .fashion-section-head{align-items:flex-start;gap:12px;flex-direction:column}.target-theme-fashion .detail{padding:30px 22px}}
body.target-theme-boutique{background:#f8f1e6;color:#221b14;font-family:"Segoe UI",Tahoma,sans-serif}.target-theme-boutique header{background:rgba(255,252,246,.96);border-bottom-color:#d6b879}.target-theme-boutique .brand,.target-theme-boutique h2 a:hover,.target-theme-boutique .read{color:#8a6326}.target-theme-boutique .hero h1,.target-theme-boutique .detail h1{font-family:Georgia,"Times New Roman",serif}.target-theme-boutique .chip{border-color:#d6b879;background:#fff7e8;color:#7c5520}.target-theme-boutique .card{border-color:#ead8b7;background:#fffaf2;box-shadow:0 18px 45px rgba(75,48,18,.1)}
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

function normalizeArticleTextAdUrl(string $url): string
{
    $normalized = trim($url);
    if ($normalized === '' || str_starts_with($normalized, '//')) {
        return '';
    }

    if (str_starts_with($normalized, '/')) {
        return $normalized;
    }

    if (preg_match('#^https?://#i', $normalized) === 1) {
        return $normalized;
    }

    if (preg_match('#^[a-z][a-z0-9+.-]*:#i', $normalized) === 1) {
        return '';
    }

    return '/'.ltrim($normalized, '/');
}

function normalizeArticleTextAdColor(string $color): string
{
    $color = trim($color);
    if (preg_match('/^#(?:[0-9a-fA-F]{3}|[0-9a-fA-F]{6})$/', $color) !== 1) {
        return '#2563eb';
    }

    $hex = ltrim(strtolower($color), '#');
    if (strlen($hex) === 3) {
        $hex = $hex[0].$hex[0].$hex[1].$hex[1].$hex[2].$hex[2];
    }

    return '#'.$hex;
}

function normalizeArticleTextAdTrackingParam(string $trackingParam): string
{
    $trackingParam = ltrim(trim($trackingParam), "? \t\n\r\0\x0B");
    if (
        $trackingParam === ''
        || strlen($trackingParam) > 250
        || str_contains($trackingParam, '://')
        || str_starts_with($trackingParam, '/')
        || preg_match('/^[A-Za-z0-9._~%=&+;,:@-]+$/', $trackingParam) !== 1
    ) {
        return '';
    }

    return $trackingParam;
}

function normalizeArticleTextAdLinks(mixed $links, bool $enabledOnly = false, int $maxLinks = 10): array
{
    if (! is_array($links)) {
        return [];
    }

    $normalized = [];
    foreach ($links as $link) {
        if (! is_array($link)) {
            continue;
        }

        $text = trim((string) ($link['text'] ?? ''));
        $url = normalizeArticleTextAdUrl((string) ($link['url'] ?? ''));
        if ($text === '' || $url === '') {
            continue;
        }

        $enabled = ! empty($link['enabled']);
        if ($enabledOnly && ! $enabled) {
            continue;
        }

        $normalized[] = [
            'id' => trim((string) ($link['id'] ?? '')),
            'text' => $text,
            'url' => $url,
            'text_color' => normalizeArticleTextAdColor((string) ($link['text_color'] ?? '#2563eb')),
            'open_new_tab' => ! empty($link['open_new_tab']),
            'tracking_enabled' => ! empty($link['tracking_enabled']),
            'tracking_param' => normalizeArticleTextAdTrackingParam((string) ($link['tracking_param'] ?? '')),
            'enabled' => $enabled,
            'sort_order' => (int) ($link['sort_order'] ?? count($normalized) * 10),
        ];
    }

    usort($normalized, static fn (array $a, array $b): int => ((int) $a['sort_order']) <=> ((int) $b['sort_order']));

    return array_slice(array_values($normalized), 0, max(0, $maxLinks));
}

function legacyArticleTextAdToLink(array $ad): array
{
    return [
        'id' => trim((string) ($ad['id'] ?? '')),
        'text' => trim((string) ($ad['text'] ?? '')),
        'url' => (string) ($ad['url'] ?? ''),
        'text_color' => (string) ($ad['text_color'] ?? '#2563eb'),
        'open_new_tab' => ! empty($ad['open_new_tab']),
        'tracking_enabled' => ! empty($ad['tracking_enabled']),
        'tracking_param' => (string) ($ad['tracking_param'] ?? ''),
        'enabled' => ! empty($ad['enabled']),
        'sort_order' => (int) ($ad['sort_order'] ?? 0),
    ];
}

function normalizeArticleTextAds(mixed $ads, bool $enabledOnly = false, int $maxModules = 30): array
{
    if (! is_array($ads)) {
        return [];
    }

    $normalized = [];
    foreach ($ads as $ad) {
        if (! is_array($ad)) {
            continue;
        }

        $placement = (string) ($ad['placement'] ?? 'content_top');
        if (! in_array($placement, ['content_top', 'content_bottom'], true)) {
            continue;
        }

        $enabled = ! empty($ad['enabled']);
        if ($enabledOnly && ! $enabled) {
            continue;
        }

        $links = normalizeArticleTextAdLinks(
            is_array($ad['links'] ?? null) ? $ad['links'] : [legacyArticleTextAdToLink($ad)],
            $enabledOnly
        );
        if ($links === []) {
            continue;
        }

        $id = trim((string) ($ad['id'] ?? ''));
        $name = trim((string) ($ad['name'] ?? ''));
        $sortOrder = (int) ($ad['sort_order'] ?? count($normalized) * 10);

        $normalized[] = [
            'schema_version' => 2,
            'id' => $id !== '' ? $id : 'article_text_module_'.md5($placement.'|'.$name.'|'.$sortOrder.'|'.json_encode($links)),
            'name' => $name !== '' ? $name : (string) ($links[0]['text'] ?? 'Text Ad Module'),
            'placement' => $placement,
            'enabled' => $enabled,
            'sort_order' => $sortOrder,
            'links' => $links,
        ];
    }

    usort($normalized, static function (array $a, array $b): int {
        $order = ((int) $a['sort_order']) <=> ((int) $b['sort_order']);

        return $order !== 0 ? $order : strcmp((string) $a['name'], (string) $b['name']);
    });

    return array_slice(array_values($normalized), 0, max(0, $maxModules));
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
        'article_text_ads' => normalizeArticleTextAds($settings['article_text_ads'] ?? $config['article_text_ads'] ?? []),
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

    if (str_contains($theme, 'apparel-sourcing-intelligence')) {
        return 'target-theme-apparel';
    }

    if (str_contains($theme, 'fashion-insight')) {
        return 'target-theme-fashion';
    }

    if (str_contains($theme, 'boutiquesourcingpro')) {
        return 'target-theme-boutique';
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

function articleTextAdUrlWithTracking(string $url, bool $trackingEnabled, string $trackingParam): string
{
    if (! $trackingEnabled || $trackingParam === '') {
        return $url;
    }

    $fragment = '';
    $baseUrl = $url;
    $hashPosition = strpos($url, '#');
    if ($hashPosition !== false) {
        $fragment = substr($url, $hashPosition);
        $baseUrl = substr($url, 0, $hashPosition);
    }

    $separator = str_contains($baseUrl, '?')
        ? (str_ends_with($baseUrl, '?') || str_ends_with($baseUrl, '&') ? '' : '&')
        : '?';

    return $baseUrl.$separator.$trackingParam.$fragment;
}

function renderArticleTextAds(array $settings, string $placement, int $limit = 2): string
{
    if (! in_array($placement, ['content_top', 'content_bottom'], true)) {
        return '';
    }

    $ads = normalizeArticleTextAds($settings['article_text_ads'] ?? [], true);
    $matched = array_values(array_filter(
        $ads,
        static fn (array $module): bool => ($module['placement'] ?? '') === $placement && ($module['links'] ?? []) !== []
    ));

    if ($matched === []) {
        return '';
    }

    $placementClass = str_replace('_', '-', $placement);
    $html = '<div class="article-text-ads article-text-ads--'.h($placementClass).'" data-placement="'.h($placement).'">';
    foreach (array_slice($matched, 0, max(1, $limit)) as $module) {
        $html .= '<div class="article-text-ad-module" data-module-id="'.h((string) $module['id']).'">';
        foreach ((array) ($module['links'] ?? []) as $link) {
            if (! is_array($link) || empty($link['enabled'])) {
                continue;
            }

            $url = articleTextAdUrlWithTracking((string) $link['url'], (bool) $link['tracking_enabled'], (string) $link['tracking_param']);
            $target = ! empty($link['open_new_tab']) ? ' target="_blank"' : '';
            $style = '--article-text-ad-color: '.h((string) $link['text_color']).';';

            $html .= '<a class="article-text-ad-link" href="'.h($url).'" rel="noopener sponsored nofollow"'.$target.' style="'.$style.'">';
            $html .= '<span class="article-text-ad-text">'.h((string) $link['text']).'</span>';
            $html .= '</a>';
        }
        $html .= '</div>';
    }
    $html .= '</div>';

    return $html;
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

function articleImageUrl(array $article): string
{
    $heroImageUrl = safeContentUrl((string) ($article['hero_image_url'] ?? ''));
    if ($heroImageUrl !== '') {
        return $heroImageUrl;
    }

    $html = is_string($article['content_html'] ?? null) ? (string) $article['content_html'] : '';
    if ($html !== '' && preg_match('/<img\b[^>]*\bsrc=(["\'])(.*?)\1/iu', $html, $matches) === 1) {
        return safeContentUrl((string) ($matches[2] ?? ''));
    }

    $markdown = is_string($article['content'] ?? null) ? (string) $article['content'] : '';
    if ($markdown !== '' && preg_match('/!\[[^\]]*\]\(([^)\s]+)(?:\s+["\'][^"\']*["\'])?\)/u', $markdown, $matches) === 1) {
        return safeContentUrl((string) ($matches[1] ?? ''));
    }

    return '';
}

function articleSummary(array $article, int $limit = 160): string
{
    $summary = trim((string) ($article['excerpt'] ?? $article['meta_description'] ?? ''));
    if ($summary !== '') {
        return $summary;
    }

    return mb_substr(trim(strip_tags((string) ($article['content'] ?? ''))), 0, $limit);
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

function frontVersionedAssetPath(array $config, string $path): string
{
    $assetPath = frontAssetPath($config, $path);
    $filePath = staticRoot($config).'/'.ltrim($path, '/');
    $settings = siteSettings($config);
    $versionSeed = implode('|', [
        (string) ($settings['active_theme'] ?? ''),
        is_file($filePath) ? (string) filemtime($filePath) : '',
    ]);
    $separator = str_contains($assetPath, '?') ? '&' : '?';

    return $assetPath.$separator.'v='.substr(hash('sha256', $versionSeed), 0, 12);
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

    foreach (['content', 'content_html', 'hero_image_url'] as $field) {
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

function articleCategoryName(array $article): string
{
    return is_array($article['category'] ?? null) ? (string) ($article['category']['name'] ?? 'Insight') : 'Insight';
}

function articleCategorySlug(array $article): string
{
    return is_array($article['category'] ?? null) ? (string) ($article['category']['slug'] ?? '') : '';
}

function articleDate(array $article, string $format = 'Y-m-d'): string
{
    $date = substr((string) ($article['published_at'] ?? $article['updated_at'] ?? ''), 0, 10);
    if ($date === '') {
        return '';
    }
    $timestamp = strtotime($date);

    return $timestamp ? date($format, $timestamp) : $date;
}

function pageHeader(array $config, string $title): void
{
    $settings = siteSettings($config);
    $siteName = (string) $settings['site_name'];
    $themeClass = themeClass($settings);
    if ($themeClass === 'target-theme-apparel') {
        apparelPageHeader($config, $settings, $title);

        return;
    }
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
    echo '<link rel="stylesheet" href="'.h(frontVersionedAssetPath($config, '/assets/css/site.css')).'">';
    echo '<script defer src="'.h(frontVersionedAssetPath($config, '/assets/js/site.js')).'"></script>';
    echo '</head><body class="'.h($themeClass).'"><header><div class="wrap bar"><a class="brand" href="'.h($homeUrl).'">'.h($siteName).'</a><nav><a href="'.h($homeUrl).'">首页</a></nav></div></header><main class="wrap">';
}

function pageFooter(array $config): void
{
    $settings = siteSettings($config);
    if (themeClass($settings) === 'target-theme-apparel') {
        echo '</main><footer><div class="asi-shell">'.h((string) $settings['copyright_info']).'</div></footer></body></html>';

        return;
    }

    echo '</main><footer><div class="wrap">'.h((string) $settings['copyright_info']).'</div></footer></body></html>';
}

function apparelPageHeader(array $config, array $settings, string $title): void
{
    $siteName = (string) $settings['site_name'];
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
    echo '<!DOCTYPE html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">';
    echo '<title>'.h($pageTitle).'</title><meta name="description" content="'.h($description).'">';
    if ((string) $settings['site_keywords'] !== '') {
        echo '<meta name="keywords" content="'.h((string) $settings['site_keywords']).'">';
    }
    echo '<link rel="stylesheet" href="'.h(frontVersionedAssetPath($config, '/assets/css/site.css')).'">';
    echo '<script defer src="'.h(frontVersionedAssetPath($config, '/assets/js/site.js')).'"></script>';
    echo '</head><body class="target-theme-apparel"><header><div class="asi-topline"><div class="asi-shell asi-topline-row"><span>Global apparel sourcing, trade policy and supplier intelligence</span><span>'.h(date('l, F j, Y')).'</span></div></div>';
    echo '<div class="asi-masthead"><div class="asi-shell asi-masthead-row"><a class="asi-brand" href="'.h($homeUrl).'"><span class="asi-brand-kicker">Independent Market Briefing</span><span class="asi-brand-name">'.h($siteName).'</span></a>';
    echo '<form class="asi-search" action="'.h($homeUrl).'" method="get"><input type="search" name="search" placeholder="Search intelligence"><button type="submit">Search</button></form></div>';
    echo '<nav class="asi-nav asi-shell" aria-label="Primary"><a class="is-active" href="'.h($homeUrl).'">Latest</a>';
    echo '</nav></div></header><main class="wrap">';
}

function renderHomePage(array $config): void
{
    $settings = siteSettings($config);
    if (themeClass($settings) === 'target-theme-apparel') {
        renderApparelHomePage($config, $settings);

        return;
    }

    if (themeClass($settings) === 'target-theme-fashion') {
        renderFashionHomePage($config, $settings);

        return;
    }

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

function renderApparelHomePage(array $config, array $settings): void
{
    $siteName = (string) $settings['site_name'];
    $articles = array_slice(loadArticles($config), 0, (int) $settings['per_page']);
    $lead = $articles[0] ?? null;
    $headlines = array_slice($articles, 1, 3);
    $latest = $lead ? array_slice($articles, 1) : $articles;

    pageHeader($config, 'Latest Intelligence');
    echo jsonLdScript([
        "@context"=>"https://schema.org",
        "@type"=>"CollectionPage",
        "name"=>"Latest Intelligence - ".$siteName,
        "url"=>frontSiteUrl($config, '/'),
        "description"=>(string) $settings['site_description'],
    ]);
    echo '<div class="asi-shell asi-page">';

    if ($lead) {
        $leadUrl = frontSitePath($config, '/article/'.rawurlencode((string) ($lead['slug'] ?? '')));
        echo '<section class="asi-hero"><article class="asi-lead">';
        renderApparelVisual($config, $lead, 'asi-lead-visual', articleCategoryName($lead));
        echo '<div class="asi-lead-copy"><div class="asi-kicker">Lead Analysis</div><h1><a href="'.h($leadUrl).'">'.h((string) ($lead['title'] ?? 'Untitled Article')).'</a></h1>';
        echo '<p>'.h(articleSummary($lead, 240) ?: (string) $settings['site_description']).'</p></div></article>';
        echo '<aside class="asi-hero-rail"><section class="asi-briefing"><span>Today\'s Briefing</span><strong>'.h((string) ($settings['site_subtitle'] ?: 'Buyers are rebalancing sourcing maps as cost, speed and compliance collide.')).'</strong><div><small>Daily market note</small><small>'.h(date('H:i T')).'</small></div></section>';
        echo '<section class="asi-headline-stack">';
        foreach ($headlines as $headline) {
            $url = frontSitePath($config, '/article/'.rawurlencode((string) ($headline['slug'] ?? '')));
            echo '<article class="asi-mini-story">';
            renderApparelVisual($config, $headline, 'asi-mini-visual');
            echo '<div><h2><a href="'.h($url).'">'.h((string) ($headline['title'] ?? 'Untitled Article')).'</a></h2><div class="asi-meta"><span>'.h(articleCategoryName($headline)).'</span><time>'.h(articleDate($headline, 'M j')).'</time></div></div></article>';
        }
        if ($headlines === []) {
            echo '<div class="empty">No featured stories yet.</div>';
        }
        echo '</section></aside></section>';
    }

    echo '<div class="asi-content-grid"><section class="asi-feed-section"><div class="asi-section-head"><span>Latest Intelligence</span><small>Updated continuously</small></div><div class="asi-feed-list">';
    if ($latest === []) {
        echo '<div class="empty">No articles yet.</div>';
    }
    foreach ($latest as $article) {
        renderApparelArticleCard($config, $article);
    }
    echo '</div></section>';
    renderApparelSidebar($config, $settings, $articles);
    echo '</div></div>';
    pageFooter($config);
}

function renderApparelVisual(array $config, array $article, string $class, string $badge = ''): void
{
    $url = frontSitePath($config, '/article/'.rawurlencode((string) ($article['slug'] ?? '')));
    $title = (string) ($article['title'] ?? 'Untitled Article');
    $image = articleImageUrl($article);
    $initial = mb_strtoupper(mb_substr(articleCategoryName($article), 0, 1));
    echo '<a class="asi-visual '.h($class).'" href="'.h($url).'" aria-label="'.h($title).'">';
    if ($image !== '') {
        echo '<img src="'.h($image).'" alt="'.h($title).'" loading="lazy" decoding="async">';
    } else {
        echo '<span class="asi-visual-pattern"><span>'.h($initial).'</span></span>';
    }
    if ($badge !== '') {
        echo '<span class="asi-visual-badge">'.h($badge).'</span>';
    }
    echo '</a>';
}

function renderApparelArticleCard(array $config, array $article): void
{
    $url = frontSitePath($config, '/article/'.rawurlencode((string) ($article['slug'] ?? '')));
    echo '<article class="asi-card">';
    renderApparelVisual($config, $article, 'asi-card-visual');
    echo '<div class="asi-card-copy"><div class="asi-meta"><span>'.h(articleCategoryName($article)).'</span><time>'.h(articleDate($article, 'M j, Y')).'</time></div>';
    echo '<h2><a href="'.h($url).'">'.h((string) ($article['title'] ?? 'Untitled Article')).'</a></h2>';
    $summary = articleSummary($article, 180);
    if ($summary !== '') {
        echo '<p>'.h($summary).'</p>';
    }
    echo '</div></article>';
}

function renderApparelSidebar(array $config, array $settings, array $articles): void
{
    echo '<aside class="asi-sidebar"><section class="asi-panel asi-briefing-panel"><span class="asi-panel-kicker">Daily Briefing</span><h2>'.h((string) ($settings['site_subtitle'] ?: 'Compliance costs are now a sourcing decision.')).'</h2>';
    if ((string) $settings['site_description'] !== '') {
        echo '<p>'.h((string) $settings['site_description']).'</p>';
    }
    echo '</section><section class="asi-panel"><div class="asi-panel-head"><h2>Editor Picks</h2></div><div class="asi-rank-list">';
    foreach (array_slice($articles, 0, 6) as $index => $article) {
        $url = frontSitePath($config, '/article/'.rawurlencode((string) ($article['slug'] ?? '')));
        echo '<a class="asi-rank-item" href="'.h($url).'"><span>'.($index + 1).'</span><strong>'.h((string) ($article['title'] ?? 'Untitled Article')).'</strong></a>';
    }
    echo '</div></section></aside>';
}

function renderFashionHomePage(array $config, array $settings): void
{
    $siteName = (string) $settings['site_name'];
    $description = (string) $settings['site_description'];
    $subtitle = trim((string) ($settings['site_subtitle'] ?? ''));
    $articles = array_slice(loadArticles($config), 0, (int) $settings['per_page']);
    $featured = array_slice($articles, 0, min(3, count($articles)));
    $latest = array_slice($articles, 0);

    pageHeader($config, '首页');
    echo jsonLdScript([
        "@context"=>"https://schema.org",
        "@type"=>"WebSite",
        "name"=>$siteName,
        "url"=>frontSiteUrl($config, '/'),
        "description"=>$description,
    ]);
    echo '<section class="fashion-hero"><div class="fashion-wordmark">TREND</div><div class="fashion-hero-inner">';
    echo '<span class="fashion-kicker">Apparel &amp; Textile Intelligence</span>';
    echo '<h1>'.h($siteName).'</h1>';
    echo '<p>'.h($subtitle !== '' ? $subtitle : ($description !== '' ? $description : 'Global sourcing updates, supply chain dynamics, and forward-looking fashion market analytics.')).'</p>';
    echo '<form class="fashion-search" action="'.h(frontSitePath($config, '/')).'" method="get"><input type="search" name="search" placeholder="Search trends, fabrics, materials..."><button type="submit">Search</button></form>';
    echo '</div></section>';

    if ($articles === []) {
        echo '<section class="fashion-empty"><h2>No Articles Yet</h2><p>Stay tuned! Premium sourcing and textile research reports are coming soon.</p></section>';
        pageFooter($config);

        return;
    }

    if ($featured !== []) {
        echo '<section class="fashion-section"><div class="fashion-section-head"><h2>Vanguard Choice</h2><span>Curated Highlights</span></div>';
        $first = $featured[0];
        $firstUrl = frontSitePath($config, '/article/'.rawurlencode((string) ($first['slug'] ?? '')));
        $firstImage = articleImageUrl($first);
        echo '<div class="fashion-feature-grid"><article class="fashion-feature-card">';
        if ($firstImage !== '') {
            echo '<img src="'.h($firstImage).'" alt="'.h((string) ($first['title'] ?? '')).'" loading="lazy" decoding="async">';
        }
        echo '<div class="fashion-feature-overlay"></div><div class="fashion-feature-content"><span>Featured Report</span>';
        echo '<h3><a href="'.h($firstUrl).'">'.h((string) ($first['title'] ?? 'Untitled Article')).'</a></h3>';
        echo '<p>'.h(articleSummary($first, 220)).'</p><div><time>'.h(substr((string) ($first['published_at'] ?? $first['updated_at'] ?? ''), 0, 10)).'</time><a href="'.h($firstUrl).'">Read Analysis</a></div></div></article>';
        echo '<div class="fashion-feature-side">';
        foreach (array_slice($featured, 1, 2) as $item) {
            $url = frontSitePath($config, '/article/'.rawurlencode((string) ($item['slug'] ?? '')));
            $category = is_array($item['category'] ?? null) ? (string) ($item['category']['name'] ?? 'Insight') : 'Insight';
            echo '<article><div><span>'.h($category).'</span><time>'.h(substr((string) ($item['published_at'] ?? $item['updated_at'] ?? ''), 0, 10)).'</time></div>';
            echo '<h3><a href="'.h($url).'">'.h((string) ($item['title'] ?? 'Untitled Article')).'</a></h3><p>'.h(articleSummary($item, 120)).'</p><a href="'.h($url).'">Read Report</a></article>';
        }
        if (count($featured) === 1) {
            echo '<article class="fashion-feature-placeholder"><span>Tailored Trends for Apparel Sourcing</span></article>';
        }
        echo '</div></div></section>';
    }

    echo '<section class="fashion-section"><div class="fashion-section-head"><h2>Latest Intelligence</h2><span>Apparel &amp; Materials Research</span></div><div class="fashion-card-grid">';
    foreach ($latest as $article) {
        renderFashionArticleCard($config, $article);
    }
    echo '</div></section>';
    pageFooter($config);
}

function renderFashionArticleCard(array $config, array $article): void
{
    $slug = (string) ($article['slug'] ?? '');
    $url = frontSitePath($config, '/article/'.rawurlencode($slug));
    $title = (string) ($article['title'] ?? 'Untitled Article');
    $image = articleImageUrl($article);
    $category = is_array($article['category'] ?? null) ? (string) ($article['category']['name'] ?? 'Insight') : 'Insight';
    $date = substr((string) ($article['published_at'] ?? $article['updated_at'] ?? ''), 0, 10);

    echo '<article class="fashion-card">';
    echo '<a class="fashion-card-media" href="'.h($url).'">';
    if ($image !== '') {
        echo '<img src="'.h($image).'" alt="'.h($title).'" loading="lazy" decoding="async">';
    }
    echo '</a><div class="fashion-card-meta"><span>'.h($category).'</span><time>'.h($date).'</time></div>';
    echo '<h3><a href="'.h($url).'">'.h($title).'</a></h3>';
    $summary = articleSummary($article, 140);
    if ($summary !== '') {
        echo '<p>'.h($summary).'</p>';
    }
    echo '<div class="fashion-card-foot"><a href="'.h($url).'">Read Report</a></div></article>';
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
    $settings = siteSettings($config);
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
            "name"=>(string) $settings['site_name'],
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
    $themeClass = themeClass($settings);
    $isFashion = $themeClass === 'target-theme-fashion';
    $isApparel = $themeClass === 'target-theme-apparel';
    echo $isApparel ? '<div class="asi-shell asi-article-layout"><main class="asi-article-column"><nav class="asi-breadcrumb"><a href="'.h(frontSitePath($config, '/')).'">Latest</a><span>/</span><span>'.h($category).'</span></nav>' : '';
    echo '<a class="back" href="'.h(frontSitePath($config, '/')).'">'.($isFashion || $isApparel ? 'Back to Reports' : '返回首页').'</a><article class="'.($isApparel ? 'asi-article' : 'card detail').'">';
    if ($isFashion) {
        echo '<div class="fashion-article-kicker"><span>'.h($category).'</span><time>'.h($publishedAt).'</time></div>';
    } elseif ($isApparel) {
        echo '<header class="asi-article-head"><a class="asi-article-section" href="'.h(frontSitePath($config, '/')).'">'.h($category).'</a>';
    } else {
        echo '<div class="meta"><span class="chip">'.h($category).'</span><span>'.h($publishedAt).'</span></div>';
    }
    echo '<h1>'.h($title).'</h1>';
    if ($isApparel) {
        echo '<div class="asi-post-info"><time>'.h($publishedAt).'</time>';
        if (is_array($article['author'] ?? null)) {
            echo '<span>'.h((string) ($article['author']['name'] ?? '')).'</span>';
        }
        echo '</div>';
    }
    $excerpt = (string) ($article['excerpt'] ?? '');
    if ($excerpt !== '') {
        echo '<p class="summary">'.h($excerpt).'</p>';
    }
    if ($isApparel) {
        echo '</header>';
        renderApparelVisual($config, $article, 'asi-article-visual');
    }
    echo '<div class="'.($isApparel ? 'asi-prose content' : 'content').'">'.renderArticleTextAds($settings, 'content_top').articleContentHtml($article).renderArticleTextAds($settings, 'content_bottom').'</div>';
    $tags = keywordTags((string) ($article['keywords'] ?? ''));
    if ($tags !== []) {
        echo '<div class="tags">';
        foreach ($tags as $tag) {
            echo '<span>'.h($tag).'</span>';
        }
        echo '</div>';
    }
    echo '</article>';
    if ($isApparel) {
        echo '</main>';
        renderApparelSidebar($config, $settings, loadArticles($config));
        echo '</div>';
    }
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
