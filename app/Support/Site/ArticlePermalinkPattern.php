<?php

namespace App\Support\Site;

use InvalidArgumentException;

class ArticlePermalinkPattern
{
    public const TOKENS = ['slug', 'id', 'category', 'year', 'month', 'day'];

    /** @var array<string,self> */
    private static array $compiled = [];

    /** @param list<string> $tokens */
    private function __construct(
        private readonly string $template,
        private readonly string $regex,
        private readonly array $tokens,
        private readonly string $adminBasePath,
    ) {}

    public static function compile(string $template, ?string $adminBasePath = null): self
    {
        $template = self::normalize($template);
        $adminBasePath ??= function_exists('app') && app()->bound('config')
            ? (string) config('geoflow.admin_base_path', '/geo_admin')
            : '/geo_admin';
        $cacheKey = trim($adminBasePath, '/')."\0".$template;
        if (isset(self::$compiled[$cacheKey])) {
            return self::$compiled[$cacheKey];
        }
        self::assertValid($template, $adminBasePath);

        $parts = preg_split('/(\{[a-z]+\})/', $template, -1, PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY) ?: [];
        $tokens = [];
        $regex = '';
        foreach ($parts as $part) {
            if (preg_match('/^\{([a-z]+)\}$/', $part, $matches) === 1) {
                $token = $matches[1];
                $tokens[] = $token;
                $regex .= '(?P<'.$token.'>'.self::tokenRegex($token).')';

                continue;
            }

            $regex .= preg_quote($part, '~');
        }

        if (count(self::$compiled) >= 128) {
            array_shift(self::$compiled);
        }

        return self::$compiled[$cacheKey] = new self($template, '~\A'.$regex.'\z~Du', $tokens, $adminBasePath);
    }

    public static function normalize(string $template): string
    {
        $template = trim($template);
        if ($template !== '/') {
            $template = rtrim($template, '/');
        }

        return $template;
    }

    public function pattern(): string
    {
        return $this->template;
    }

    /** @return list<string> */
    public function tokens(): array
    {
        return $this->tokens;
    }

    /** The matching locator is provably unchanged when its complete segment is identical. */
    public function preservesLocatorOf(self $rendering): bool
    {
        $locator = in_array('id', $this->tokens, true) ? 'id' : 'slug';
        $segments = explode('/', $this->template);
        $other = explode('/', $rendering->template);
        foreach ($segments as $index => $segment) {
            if (str_contains($segment, '{'.$locator.'}')) {
                return ($other[$index] ?? null) === $segment
                    && substr_count($segment, '{') === 1;
            }
        }

        return false;
    }

    /** @return list<string> */
    public static function reservedFirstSegments(?string $adminBasePath = null): array
    {
        $reserved = [
            '_boost', '_debugbar', 'about', 'admin', 'api', 'app', 'archive', 'assets', 'broadcasting', 'build',
            'category', 'config.php', 'css', 'favicon.ico', 'forms', 'geoflow-agent', 'horizon',
            'images', 'index.php', 'js', 'livewire', 'llms.txt', 'robots.txt', 'sanctum',
            'sitemap.txt', 'sitemap.xml', 'sitemaps', 'storage',
            'theme-assets', 'themes', 'up', 'vendor',
        ];
        $configuredAdminBasePath = function_exists('app') && app()->bound('config')
            ? (string) config('geoflow.admin_base_path', '/geo_admin')
            : '/geo_admin';
        $adminBasePath = trim((string) ($adminBasePath ?? $configuredAdminBasePath), '/');
        if ($adminBasePath !== '') {
            $reserved[] = explode('/', $adminBasePath, 2)[0];
        }

        return array_values(array_unique($reserved));
    }

    public static function isReservedFirstSegment(string $segment, ?string $adminBasePath = null): bool
    {
        $segment = mb_strtolower(trim($segment, '/'), 'UTF-8');

        return $segment !== ''
            && in_array($segment, self::reservedFirstSegments($adminBasePath), true);
    }

    public function usesRootCategorySegment(): bool
    {
        return explode('/', ltrim($this->template, '/'), 2)[0] === '{category}';
    }

    /** @param array<string,int|string> $values */
    public function render(array $values): string
    {
        $path = $this->template;
        foreach ($this->tokens as $token) {
            if (! array_key_exists($token, $values) || trim((string) $values[$token]) === '') {
                throw new InvalidArgumentException(self::message('missing_value', ['token' => $token]));
            }

            if ($token === 'category'
                && $this->usesRootCategorySegment()
                && self::isReservedFirstSegment((string) $values[$token], $this->adminBasePath)) {
                throw new InvalidArgumentException(self::message('category_reserved_path', [
                    'slug' => (string) $values[$token],
                    'path' => mb_strtolower((string) $values[$token], 'UTF-8'),
                ]));
            }

            $path = str_replace('{'.$token.'}', rawurlencode((string) $values[$token]), $path);
        }

        return $path;
    }

    /** @return array<string,string>|null */
    public function match(string $encodedPath): ?array
    {
        if (preg_match('/%(?![0-9A-Fa-f]{2})/', $encodedPath) === 1
            || preg_match($this->regex, $encodedPath, $matches) !== 1) {
            return null;
        }

        $values = [];
        foreach ($this->tokens as $token) {
            $decoded = rawurldecode((string) ($matches[$token] ?? ''));
            if (! self::isValidSegment($decoded)) {
                return null;
            }
            if ($token === 'category'
                && $this->usesRootCategorySegment()
                && self::isReservedFirstSegment($decoded, $this->adminBasePath)) {
                return null;
            }
            $values[$token] = $decoded;
        }

        return $values;
    }

    private static function assertValid(string $template, ?string $adminBasePath): void
    {
        if ($template === '' || $template[0] !== '/' || $template === '/' || strlen($template) > 160) {
            throw new InvalidArgumentException(self::message('invalid_length'));
        }
        if (preg_match('/[\s\p{Z}]/u', $template) === 1) {
            throw new InvalidArgumentException(self::message('invalid_whitespace'));
        }
        if (preg_match('/[?#\\\\\x00-\x1F\x7F]/', $template) === 1
            || str_contains($template, '://')
            || str_contains($template, '//')) {
            throw new InvalidArgumentException(self::message('invalid_characters'));
        }

        $segments = explode('/', ltrim($template, '/'));
        if (count($segments) > 8 || in_array('', $segments, true) || in_array('.', $segments, true) || in_array('..', $segments, true)) {
            throw new InvalidArgumentException(self::message('invalid_segments'));
        }

        $tokens = [];
        $withoutTokens = preg_replace_callback('/\{([a-z]+)\}/', function (array $matches) use (&$tokens): string {
            $token = (string) $matches[1];
            if (! in_array($token, self::TOKENS, true)) {
                throw new InvalidArgumentException(self::message('unsupported_token', ['token' => $token]));
            }
            if (in_array($token, $tokens, true)) {
                throw new InvalidArgumentException(self::message('duplicate_token', ['token' => $token]));
            }
            $tokens[] = $token;

            return '';
        }, $template);

        if ($withoutTokens === null || str_contains($withoutTokens, '{') || str_contains($withoutTokens, '}')) {
            throw new InvalidArgumentException(self::message('invalid_token'));
        }
        if (preg_match('/[^a-z0-9._\/-]/', $withoutTokens) === 1) {
            throw new InvalidArgumentException(self::message('invalid_literal'));
        }
        if (! in_array('slug', $tokens, true) && ! in_array('id', $tokens, true)) {
            throw new InvalidArgumentException(self::message('locator_required'));
        }
        if (preg_match('/\}[\t ]*\{/', $template) === 1) {
            throw new InvalidArgumentException(self::message('token_separator_required'));
        }
        if (count($segments) === 1 && str_contains($segments[0], '{slug}') && ! str_ends_with($segments[0], '.html')) {
            throw new InvalidArgumentException(self::message('root_slug_suffix_required'));
        }

        if ($segments[0] !== '{category}') {
            $firstSegmentRegex = '';
            $firstSegmentParts = preg_split('/(\{[a-z]+\})/', $segments[0], -1, PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY) ?: [];
            foreach ($firstSegmentParts as $part) {
                if (preg_match('/^\{([a-z]+)\}$/', $part, $matches) === 1) {
                    $firstSegmentRegex .= '(?:'.self::tokenRegex((string) $matches[1]).')';
                } else {
                    $firstSegmentRegex .= preg_quote($part, '~');
                }
            }
            foreach (self::reservedFirstSegments($adminBasePath) as $reservedPath) {
                if (preg_match('~\A'.$firstSegmentRegex.'\z~D', $reservedPath) === 1) {
                    throw new InvalidArgumentException(self::message('reserved_path', ['path' => $reservedPath]));
                }
            }
        }
    }

    /** @param array<string,int|string> $replace */
    private static function message(string $key, array $replace = []): string
    {
        if (function_exists('app') && app()->bound('translator')) {
            return (string) trans('article_permalink.errors.'.$key, $replace);
        }

        $message = [
            'missing_value' => '固定链接缺少 :token 的值。',
            'invalid_length' => '固定链接模板必须以 / 开头，且长度不能超过 160 字节。',
            'invalid_characters' => '固定链接模板包含协议、查询参数、空路径段或非法字符。',
            'invalid_segments' => '固定链接模板最多包含 8 个有效路径段。',
            'unsupported_token' => '不支持固定链接令牌 {:token}。',
            'duplicate_token' => '固定链接令牌 {:token} 只能使用一次。',
            'invalid_token' => '固定链接模板包含无效令牌。',
            'invalid_whitespace' => '模板中包含空白字符（空格、制表符或换行）。请删除多余空白，路径层级直接用 / 分隔；分类与文章两层地址请填写 /{category}/{slug}。',
            'invalid_literal' => '模板中的固定文字包含不支持的字符。令牌以外只允许小写英文字母（a-z）、数字（0-9）、短横线（-）、下划线（_）、点（.）和路径分隔符（/）。',
            'locator_required' => '固定链接模板必须包含 {slug} 或 {id}。',
            'token_separator_required' => '相邻令牌之间需要固定分隔符。',
            'root_slug_suffix_required' => '根级 slug 模板需要使用 .html 固定后缀。',
            'reserved_path' => '固定链接模板与保留入口 /:path 冲突。',
            'category_reserved_path' => '分类 slug :slug 占用了保留入口 /:path，请先修改该分类 slug。',
        ][$key] ?? $key;

        foreach ($replace as $name => $value) {
            $message = str_replace(':'.$name, (string) $value, $message);
        }

        return $message;
    }

    private static function isValidSegment(string $value): bool
    {
        return $value !== ''
            && mb_check_encoding($value, 'UTF-8')
            && preg_match('/[\x00-\x1F\x7F\\\\\/?#]/u', $value) !== 1
            && ! in_array($value, ['.', '..'], true);
    }

    public static function tokenRegex(string $token): string
    {
        return match ($token) {
            'id' => '[1-9][0-9]*',
            'year' => '[0-9]{4}',
            'month' => '0[1-9]|1[0-2]',
            'day' => '0[1-9]|[12][0-9]|3[01]',
            'slug', 'category' => '[^/?#]+',
        };
    }
}
