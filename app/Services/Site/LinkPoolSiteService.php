<?php

namespace App\Services\Site;

use App\Models\Article;
use App\Models\ArticleSlugHistory;
use App\Models\DistributionChannel;
use App\Models\HostedSiteProfile;
use App\Models\SiteSetting;
use App\Models\SiteThemeBinding;
use App\Models\ThemeRevision;
use App\Services\Api\ThemeRevisionStorage;
use App\Services\HostedSites\HostedSiteUrlGenerator;
use App\Support\Site\ArticlePermalinkPattern;
use App\Support\Site\ArticlePermalinkPolicy;
use App\Support\Site\CurrentSite;
use App\Support\Site\InstalledSiteThemeRepository;
use Illuminate\Database\Eloquent\Builder;

final class LinkPoolSiteService
{
    public function __construct(
        private readonly CurrentSite $currentSite,
        private readonly SiteScopedArticleQuery $articles,
        private readonly ArticlePermalinkService $permalinks,
        private readonly HostedSiteUrlGenerator $hostedUrls,
    ) {}

    public function currentKey(): string
    {
        return $this->currentSite->isHosted()
            ? 'hosted:'.$this->currentSite->profileId()
            : 'primary';
    }

    public function query(string $siteKey): Builder
    {
        return $this->articles->queryForSiteKey($siteKey)
            ->where(function (Builder $query): void {
                $query->whereNull('articles.published_at')->orWhere('articles.published_at', '<=', now());
            });
    }

    public function article(string $siteKey, int $articleId): ?Article
    {
        return $this->query($siteKey)->with('category')->whereKey($articleId)->first();
    }

    public function profile(string $siteKey): ?HostedSiteProfile
    {
        if (! preg_match('/^hosted:([1-9][0-9]*)$/D', $siteKey, $matches)) {
            return null;
        }

        if (app()->runningInConsole()) {
            return HostedSiteProfile::query()->with('channel')->find((int) $matches[1]);
        }

        $cache = request()->attributes->get('link_pool.profiles', []);
        if (! array_key_exists($siteKey, $cache)) {
            $cache[$siteKey] = HostedSiteProfile::query()->with('channel')->find((int) $matches[1]);
            request()->attributes->set('link_pool.profiles', $cache);
        }

        return $cache[$siteKey];
    }

    public function canPublish(string $siteKey): bool
    {
        if ($siteKey === 'primary') {
            return true;
        }

        $profile = $this->profile($siteKey);

        return $profile !== null
            && $profile->serving_status === HostedSiteProfile::SERVING_ONLINE
            && $profile->indexing_status === HostedSiteProfile::INDEXING_INDEX
            && $profile->channel !== null
            && $profile->channel->status !== DistributionChannel::STATUS_DELETING;
    }

    public function emergencyDisabled(): bool
    {
        return SiteSetting::query()
            ->where('setting_key', 'link_pool_emergency_disabled')
            ->value('setting_value') === '1';
    }

    public function themeSupportsRecommendations(string $siteKey): bool
    {
        return $this->recommendationCapability($siteKey)['ready'];
    }

    /** @return array{ready:bool,reason:string,theme_id:string,revision_id:?string} */
    public function recommendationCapability(string $siteKey): array
    {
        $themeId = $siteKey === 'primary'
            ? (string) (SiteSetting::query()->where('setting_key', 'active_theme')->value('setting_value') ?? config('geoflow.default_theme', ''))
            : (string) ($this->profile($siteKey)?->channel?->site_settings['theme_id']
                ?? $this->profile($siteKey)?->channel?->template_key ?? '');
        $themeId = trim($themeId);
        $result = ['ready' => false, 'reason' => 'recommendation_template_missing', 'theme_id' => $themeId, 'revision_id' => null];
        if ($themeId !== '' && preg_match('/^[a-zA-Z0-9_-]+$/D', $themeId) !== 1) {
            return array_replace($result, ['reason' => 'theme_invalid']);
        }

        $articlePath = $themeId === '' ? resource_path('views/site/article.blade.php') : resource_path('views/theme/'.$themeId.'/article.blade.php');
        $binding = SiteThemeBinding::query()->find($siteKey);
        if ($binding?->revision_id !== null && $binding->theme_id === $themeId) {
            $revision = ThemeRevision::query()->find($binding->revision_id);
            $result['revision_id'] = $binding->revision_id;
            if ($revision === null || $revision->state !== 'ready') {
                return array_replace($result, ['reason' => 'revision_unavailable']);
            }
            $themeId = (string) $binding->theme_id;
            $articlePath = resource_path('views/theme/'.$themeId.'/article.blade.php');
            $articlePath = null;
            foreach (['resources/views/theme/'.$binding->theme_id.'/article.blade.php', 'resources/views/site/article.blade.php'] as $logicalPath) {
                if (array_key_exists($logicalPath, $revision->files ?? [])) {
                    try {
                        $articlePath = app(ThemeRevisionStorage::class)->path($revision, $logicalPath);
                        if (! is_file($articlePath) || ! hash_equals((string) $revision->files[$logicalPath]['sha256'], (string) hash_file('sha256', $articlePath))) {
                            return array_replace($result, ['reason' => 'revision_integrity_failed']);
                        }
                    } catch (\Throwable) {
                        return array_replace($result, ['reason' => 'revision_integrity_failed']);
                    }
                    break;
                }
            }
            if ($articlePath === null) {
                return array_replace($result, ['reason' => 'article_template_missing']);
            }
        }
        if (! is_file($articlePath)) {
            $installed = app(InstalledSiteThemeRepository::class)->find($themeId);
            $articlePath = $installed === null
                ? resource_path('views/site/article.blade.php')
                : $installed['views_path'].'/article.blade.php';
        }
        if (! is_file($articlePath)) {
            $articlePath = resource_path('views/site/article.blade.php');
        }
        $source = @file_get_contents($articlePath);
        if (! is_string($source)) {
            return array_replace($result, ['reason' => 'article_template_missing']);
        }
        $ready = self::templateSupportsRecommendations($source);

        return array_replace($result, ['ready' => $ready, 'reason' => $ready ? 'ready' : 'recommendation_template_missing']);
    }

    public static function templateSupportsRecommendations(string $source): bool
    {
        $source = preg_replace('/\{\{--[\s\S]*?--\}\}|<!--[\s\S]*?-->|@verbatim[\s\S]*?@endverbatim/', '', $source) ?? '';

        return preg_match('/(?<!@)@include\s*\(\s*[\'"]site\.partials\.link-pool[\'"]\s*\)/', $source) === 1
            || preg_match('/(?<!@)@(?:foreach|if)\s*\([^\n]*\$recommendationBlocks\b/', $source) === 1;
    }

    /** @return array{schema_version:int,revision:int,enabled:bool,heading:string,default_limit:int,rotation_hours:int,event_retention_days:int} */
    public function settings(string $siteKey): array
    {
        $raw = $siteKey === 'primary'
            ? SiteSetting::query()->where('setting_key', 'article_recommendations')->value('setting_value')
            : ($this->profile($siteKey)?->channel?->site_settings['article_recommendations'] ?? null);
        $stored = is_array($raw) ? $raw : json_decode((string) $raw, true);
        $stored = is_array($stored) ? $stored : [];

        return [
            'schema_version' => 1,
            'revision' => $siteKey === 'primary' ? max(0, (int) ($stored['revision'] ?? 0))
                : (int) ($this->profile($siteKey)?->settings_version ?? 0),
            'enabled' => ($stored['enabled'] ?? false) === true,
            'heading' => trim((string) ($stored['heading'] ?? '推荐内容')) ?: '推荐内容',
            'default_limit' => min(6, max(1, (int) ($stored['default_limit'] ?? 4))),
            'rotation_hours' => min(168, max(1, (int) ($stored['rotation_hours'] ?? 24))),
            'event_retention_days' => 90,
        ];
    }

    public function url(string $siteKey, Article $article): string
    {
        if ($siteKey !== 'primary') {
            $profile = $this->profile($siteKey);
            if ($profile === null) {
                throw new \InvalidArgumentException('Unknown target site.');
            }

            return $this->hostedUrls->article($profile, $article);
        }

        $base = rtrim((string) config('geoflow.site_url', config('app.url')), '/');
        $policy = ArticlePermalinkPolicy::fromRaw(
            SiteSetting::query()->where('setting_key', ArticlePermalinkPolicy::SETTING_KEY)->value('setting_value')
        );

        return $base.$this->permalinks->path($article, $policy);
    }

    /** @return array{site_key:string,article:Article}|null */
    public function resolveImportedUrl(string $inputUrl, bool $requirePublic = true): ?array
    {
        $parts = parse_url(trim($inputUrl));
        if (! is_array($parts) || ! in_array(strtolower((string) ($parts['scheme'] ?? '')), ['http', 'https'], true)
            || isset($parts['user']) || isset($parts['pass'])
            || isset($parts['port'])) {
            return null;
        }

        $host = strtolower(rtrim((string) ($parts['host'] ?? ''), '.'));
        $path = (string) ($parts['path'] ?? '');
        if ($host === '' || $path === '' || ! $this->permalinks->isSafeRequestPath($path) || preg_match('/[\x00-\x1f\x7f]/', $path)) {
            return null;
        }

        $profile = HostedSiteProfile::query()->with('channel')->where('hostname', $host)->first();
        $primaryHosts = array_map('strtolower', (array) config('geoflow.hosted_sites.primary_hosts', []));
        $primaryHosts[] = strtolower((string) parse_url((string) config('geoflow.site_url', config('app.url')), PHP_URL_HOST));
        $isPrimary = in_array($host, $primaryHosts, true);
        if (($profile !== null && $isPrimary) || ($profile === null && ! $isPrimary)) {
            return null;
        }

        $siteKey = $profile === null ? 'primary' : 'hosted:'.$profile->id;
        if ($requirePublic && ! $this->canPublish($siteKey)) {
            return null;
        }
        $rawPolicy = $profile?->channel?->site_settings[ArticlePermalinkPolicy::SETTING_KEY]
            ?? SiteSetting::query()->where('setting_key', ArticlePermalinkPolicy::SETTING_KEY)->value('setting_value');
        $policy = ArticlePermalinkPolicy::fromRaw($rawPolicy);
        $matches = [];
        $matchingPath = $path === '/' ? $path : rtrim($path, '/');

        foreach ($policy->patterns() as $pattern) {
            $values = ArticlePermalinkPattern::compileStored($pattern)->match($matchingPath);
            if ($values === null) {
                continue;
            }
            $query = ($requirePublic ? $this->query($siteKey) : Article::query())->with('category');
            if (isset($values['id'])) {
                $query->whereKey((int) $values['id']);
            } elseif (isset($values['slug'])) {
                $query->where('slug', $values['slug']);
            } else {
                continue;
            }
            $articles = $query->limit(2)->get();
            if ($articles->isEmpty() && isset($values['slug']) && ! isset($values['id'])) {
                $historicalId = ArticleSlugHistory::query()->where('slug', $values['slug'])->value('article_id');
                if ($historicalId !== null) {
                    $articles = ($requirePublic ? $this->query($siteKey) : Article::query())
                        ->with('category')->whereKey((int) $historicalId)->get();
                }
            }
            foreach ($articles as $article) {
                $matches[(int) $article->id] = $article;
            }
        }

        if (count($matches) !== 1) {
            return null;
        }

        return ['site_key' => $siteKey, 'article' => reset($matches)];
    }
}
