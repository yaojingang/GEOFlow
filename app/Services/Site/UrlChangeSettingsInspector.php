<?php

namespace App\Services\Site;

use App\Models\ArticleSlugHistory;
use App\Models\CategorySlugHistory;
use App\Models\DistributionChannel;
use App\Models\SiteSetting;
use App\Models\UrlChangeRequest;
use App\Support\Site\ArticlePermalinkPattern;
use App\Support\Site\ArticlePermalinkPolicy;

final class UrlChangeSettingsInspector
{
    private const KEYS = ['homepage_modules', 'article_detail_ads', 'article_detail_text_ads'];

    public function __construct(private readonly UrlChangeInspector $articles, private readonly ArticlePermalinkService $urls) {}

    /** Count configuration records containing a changed local URL, without loading article bodies. */
    public function count(UrlChangeRequest $change): int
    {
        $count = 0;
        foreach ($change->sites as $site) {
            $settings = $site['key'] === 'primary'
                ? SiteSetting::query()->whereIn('setting_key', self::KEYS)->pluck('setting_value', 'setting_key')->all()
                : array_intersect_key(DistributionChannel::query()->find($site['channel_id'])?->site_settings ?? [], array_flip(self::KEYS));
            foreach ($settings as $value) {
                $value = is_string($value) ? json_decode($value, true) : $value;
                if (is_array($value) && $this->containsChangedUrl($change, $site, $value)) {
                    $count++;
                }
            }
        }

        return $count;
    }

    private function containsChangedUrl(UrlChangeRequest $change, array $site, array $settings): bool
    {
        $oldPolicy = ArticlePermalinkPolicy::fromRaw($site['policy']);
        $newPolicy = ArticlePermalinkPolicy::fromRaw($site['next_policy']);
        $iterator = new \RecursiveIteratorIterator(new \RecursiveArrayIterator($settings));
        $seen = [];
        foreach ($iterator as $value) {
            if (! is_string($value) || ! preg_match('~\A(?:/(?!/)|https?://)~', $value) || isset($seen[$value])) {
                continue;
            }
            $seen[$value] = true;
            $parts = parse_url($value);
            $base = parse_url($site['base_url']);
            if ($parts === false || (isset($parts['host']) && (strtolower($parts['host']) !== strtolower($base['host'] ?? '') || ($parts['port'] ?? null) !== ($base['port'] ?? null)))) {
                continue;
            }
            $path = $parts['path'] ?? '';
            $basePath = rtrim($base['path'] ?? '', '/');
            if ($basePath !== '' && str_starts_with($path, $basePath.'/')) {
                $path = substr($path, strlen($basePath));
            }
            if ($change->operation === 'category' && preg_match('~\A/category/([^/]+)\z~', $path, $categoryPath) === 1) {
                $categorySlug = rawurldecode($categoryPath[1]);
                if ($categorySlug !== $change->new_value && ($categorySlug === $change->old_value
                    || CategorySlugHistory::query()->where('slug', $categorySlug)->where('category_id', $change->target_id)->exists())) {
                    return true;
                }
            }
            foreach ($oldPolicy->patterns() as $pattern) {
                $values = ArticlePermalinkPattern::compile($pattern)->match($path);
                if ($values === null) {
                    continue;
                }
                $query = $this->articles->targets($change, $site);
                if (isset($values['id'])) {
                    $query->whereKey((int) $values['id']);
                } else {
                    $slug = $values['slug'] ?? '';
                    $query->where(fn ($query) => $query->where('articles.slug', $slug)->orWhereIn('articles.id', ArticleSlugHistory::query()->where('slug', $slug)->select('article_id')));
                }
                $article = $query->with('category:id,slug')->first(['articles.id', 'slug', 'category_id', 'created_at']);
                if ($article !== null) {
                    $nextPath = $this->urls->path($this->articles->changedArticle($article, $change), $newPolicy);
                    if ($path !== $nextPath && $this->urls->path($article, $oldPolicy) !== $nextPath) {
                        return true;
                    }
                }
            }
        }

        return false;
    }
}
