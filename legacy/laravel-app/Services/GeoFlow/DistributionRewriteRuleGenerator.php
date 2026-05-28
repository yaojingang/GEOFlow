<?php

namespace App\Services\GeoFlow;

use App\Models\DistributionChannel;

class DistributionRewriteRuleGenerator
{
    public static function basePathForEndpoint(string $endpointUrl): string
    {
        $path = parse_url($endpointUrl, PHP_URL_PATH);
        $path = is_string($path) ? trim($path, '/') : '';
        if ($path === '') {
            return '';
        }

        if ($path === 'index.php') {
            return '';
        }

        if (str_ends_with($path, '/index.php')) {
            $path = substr($path, 0, -strlen('/index.php'));
        }

        $path = trim((string) $path, '/');

        return $path === '' ? '' : '/'.$path;
    }

    public static function apacheHtaccess(): string
    {
        return <<<'HTACCESS'
<FilesMatch "^(config\.php|nginx\.example\.conf|nginx\.rewrite\.conf|bt\.rewrite\.conf)$">
    Require all denied
</FilesMatch>

<IfModule mod_rewrite.c>
    RewriteEngine On
    RewriteRule ^(?:config\.php|nginx\.example\.conf|nginx\.rewrite\.conf|bt\.rewrite\.conf)$ - [F,L]
    RewriteRule ^storage/ - [F,L]
    RewriteRule ^public/ - [L]
    RewriteCond %{REQUEST_FILENAME} !-f
    RewriteCond %{REQUEST_FILENAME} !-d
    RewriteRule ^ index.php [L]
</IfModule>
HTACCESS;
    }

    public static function nginxRewrite(DistributionChannel $channel): string
    {
        $basePath = self::basePathForEndpoint((string) $channel->endpoint_url);
        $location = $basePath === '' ? '/' : $basePath.'/';
        $index = $basePath === '' ? '/index.php' : $basePath.'/index.php';
        $protected = $basePath === ''
            ? '^/(config\\.php|nginx\\.example\\.conf|nginx\\.rewrite\\.conf|bt\\.rewrite\\.conf|storage/)'
            : '^'.self::nginxPathPattern($basePath).'/(config\\.php|nginx\\.example\\.conf|nginx\\.rewrite\\.conf|bt\\.rewrite\\.conf|storage/)';
        $slashRedirect = $basePath === '' ? '' : <<<NGINX

location = {$basePath} {
    return 301 {$location};
}

NGINX;

        return <<<NGINX
location ~ {$protected} {
    deny all;
}

{$slashRedirect}location = {$location} {
    rewrite ^ {$index} last;
}

location {$location} {
    try_files \$uri {$index}?\$query_string;
}
NGINX;
    }

    public static function baotaRewriteOnly(DistributionChannel $channel): string
    {
        $basePath = self::basePathForEndpoint((string) $channel->endpoint_url);
        $index = $basePath === '' ? '/index.php' : $basePath.'/index.php';
        $prefixPattern = $basePath === '' ? '/' : self::nginxPathPattern($basePath).'/';
        $homePattern = $basePath === '' ? '^/?$' : '^'.self::nginxPathPattern($basePath).'/?$';

        return <<<NGINX
# 适用于宝塔“伪静态”面板里 location 规则不生效的场景。
# 敏感文件保护请优先使用 nginx.rewrite.conf 里的 location 规则，或放到站点“配置文件”server 块内。
rewrite {$homePattern} {$index} last;
rewrite ^{$prefixPattern}(geoflow-agent/.*)$ {$index}/\$1 last;
rewrite ^{$prefixPattern}(article/.*)$ {$index}/\$1 last;
NGINX;
    }

    private static function nginxPathPattern(string $path): string
    {
        return str_replace('\\-', '-', preg_quote($path, '#'));
    }
}
