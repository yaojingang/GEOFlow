<?php

namespace App\Services\Site;

use App\Data\Site\ArticlePermalinkResolution;
use App\Models\Article;
use App\Models\ArticleSlugHistory;
use App\Models\SiteSetting;
use App\Services\GeoFlow\ArticleSlugRegistry;
use App\Support\Site\ArticlePermalinkPattern;
use App\Support\Site\ArticlePermalinkPolicy;
use App\Support\Site\CurrentSite;
use App\Support\Site\SiteSettingsBag;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

class ArticlePermalinkService
{
    private ?ArticlePermalinkPolicy $resolvedPolicy = null;

    private ?string $resolvedPolicyIdentity = null;

    public function __construct(
        private readonly SiteScopedArticleQuery $siteArticles,
        private readonly ArticleSlugRegistry $slugRegistry,
        private readonly CurrentSite $currentSite,
    ) {}

    public function policy(): ArticlePermalinkPolicy
    {
        $identity = $this->policyIdentity();
        if ($this->resolvedPolicy instanceof ArticlePermalinkPolicy
            && $this->resolvedPolicyIdentity === $identity) {
            return $this->resolvedPolicy;
        }

        $this->resolvedPolicyIdentity = $identity;

        return $this->resolvedPolicy = ArticlePermalinkPolicy::fromRaw(
            SiteSettingsBag::get(ArticlePermalinkPolicy::SETTING_KEY)
        );
    }

    public function path(Article $article, ?ArticlePermalinkPolicy $policy = null): string
    {
        $policy ??= $this->policy();
        $compiled = ArticlePermalinkPattern::compile($policy->currentPattern);

        return $compiled->render($this->values($article, tokens: $compiled->tokens()));
    }

    public function pathUsingSlug(
        Article $article,
        string $slug,
        ArticlePermalinkPolicy $policy,
    ): string {
        $this->slugRegistry->assertValid($slug);
        $compiled = ArticlePermalinkPattern::compile($policy->currentPattern);

        return $compiled->render($this->values($article, $slug, $compiled->tokens()));
    }

    public function currentPatternUses(string $token): bool
    {
        return in_array(
            $token,
            ArticlePermalinkPattern::compile($this->policy()->currentPattern)->tokens(),
            true,
        );
    }

    public function matchesKnownPattern(string $encodedPath): bool
    {
        $candidate = $this->matchingPath($encodedPath);
        foreach ($this->policy()->patterns() as $pattern) {
            if (ArticlePermalinkPattern::compile($pattern)->match($candidate) !== null) {
                return true;
            }
        }

        return false;
    }

    public function assertMigrationReady(Article $article): void
    {
        foreach ($this->knownSlugs($article) as $slug) {
            $this->slugRegistry->assertValid($slug);
        }
    }

    public function resolve(string $encodedPath): ?ArticlePermalinkResolution
    {
        if (! $this->isSafeRequestPath($encodedPath)) {
            return null;
        }

        $policy = $this->policy();
        $matchingPath = $this->matchingPath($encodedPath);
        $candidates = [];
        $sources = [];
        $matchedValues = [];

        foreach ($policy->patterns() as $position => $pattern) {
            $values = ArticlePermalinkPattern::compile($pattern)->match($matchingPath);
            if ($values === null) {
                continue;
            }

            $article = $this->findArticle($values);
            if (! $article instanceof Article) {
                continue;
            }

            $articleId = (int) $article->id;
            $candidates[$articleId] = $article;
            $matchedValues[$articleId] ??= $values;
            $sources[$articleId] ??= $position === 0
                ? 'current_permalink'
                : ($pattern === ArticlePermalinkPolicy::DEFAULT_PATTERN ? 'legacy_pattern' : 'retired_pattern');
        }

        if (count($candidates) !== 1) {
            if (count($candidates) > 1) {
                Log::warning('Ambiguous article permalink request.', [
                    'reason' => 'ambiguous_permalink',
                    'path' => $encodedPath,
                    'article_ids' => array_keys($candidates),
                ]);
            }

            return null;
        }

        $articleId = (int) array_key_first($candidates);
        $article = $candidates[$articleId];
        $canonicalPath = $this->path($article, $policy);
        $isCanonical = hash_equals($canonicalPath, $encodedPath);
        $source = $sources[$articleId] ?? 'current_permalink';

        return new ArticlePermalinkResolution(
            article: $article,
            canonicalPath: $canonicalPath,
            isCanonical: $isCanonical,
            source: $source,
            reason: $isCanonical
                ? 'current_permalink'
                : $this->redirectReason($encodedPath, $matchingPath, $source, $matchedValues[$articleId] ?? [], $article),
        );
    }

    /**
     * @return array{
     *   pattern:string,current_pattern:string,revision:int,affected_articles:int,
     *   examples:list<array{id:int,title:string,current_path:string,preview_path:string}>,
     *   history:list<array{pattern:string,retired_at:string}>,structured_legacy_links:int,conflicts:list<string>
     * }
     */
    public function inspect(string $pattern, ?ArticlePermalinkPolicy $currentPolicy = null): array
    {
        $compiled = ArticlePermalinkPattern::compile($pattern);
        $currentPolicy ??= $this->policy();
        $previewPolicy = $currentPolicy->activate($compiled->pattern());
        $relations = ['category'];
        if (Schema::hasTable('article_slug_histories')) {
            $relations[] = 'slugHistories';
        }
        $articles = $this->siteArticles->query()
            ->with($relations)
            ->orderBy('id')
            ->lazyById(500);
        $analysis = $this->inspectArticles($articles, $currentPolicy, $previewPolicy);

        return [
            'pattern' => $compiled->pattern(),
            'current_pattern' => $currentPolicy->currentPattern,
            'revision' => $currentPolicy->revision,
            'affected_articles' => $analysis['affected_articles'],
            'examples' => $analysis['examples'],
            'history' => $currentPolicy->history,
            'structured_legacy_links' => $this->countStructuredLegacyLinks($currentPolicy),
            'conflicts' => $analysis['conflicts'],
        ];
    }

    /**
     * @param  iterable<int,Article>  $articles
     * @return array{
     *   affected_articles:int,
     *   examples:list<array{id:int,title:string,current_path:string,preview_path:string}>,
     *   conflicts:list<string>
     * }
     */
    public function inspectArticles(
        iterable $articles,
        ArticlePermalinkPolicy $currentPolicy,
        ArticlePermalinkPolicy $previewPolicy,
    ): array {
        $examples = [];
        $affectedArticles = 0;
        $generatedPaths = [];
        $articleIds = [];
        $slugOwners = [];
        $conflicts = [];

        foreach ($articles as $article) {
            try {
                $this->assertMigrationReady($article);
                $currentPath = $this->path($article, $currentPolicy);
                $previewPath = $this->path($article, $previewPolicy);
                $knownSlugs = $this->knownSlugs($article);
                $knownPaths = $this->inspectionPaths($article, $previewPolicy, $knownSlugs);
            } catch (\InvalidArgumentException|ValidationException $exception) {
                $message = $exception instanceof ValidationException
                    ? (string) ($exception->errors()['slug'][0] ?? $exception->getMessage())
                    : $exception->getMessage();
                $conflicts[] = __('article_permalink.errors.article_conflict', [
                    'article' => $article->id,
                    'message' => $message,
                ]);

                continue;
            }

            $articleId = (int) $article->id;
            $articleIds[$articleId] = true;
            foreach ($knownSlugs as $slug) {
                if (isset($slugOwners[$slug]) && $slugOwners[$slug] !== $articleId) {
                    $conflicts[] = __('article_permalink.errors.slug_conflict', [
                        'slug' => $slug,
                        'first' => $slugOwners[$slug],
                        'second' => $articleId,
                    ]);
                } else {
                    $slugOwners[$slug] = $articleId;
                }
            }

            if ($currentPath !== $previewPath) {
                $affectedArticles++;
            }
            foreach ($knownPaths as $knownPath) {
                if (isset($generatedPaths[$knownPath]) && $generatedPaths[$knownPath] !== $articleId) {
                    $conflicts[] = __('article_permalink.errors.path_conflict', [
                        'path' => $knownPath,
                        'first' => $generatedPaths[$knownPath],
                        'second' => $articleId,
                    ]);
                } else {
                    $generatedPaths[$knownPath] = $articleId;
                }
            }

            if (count($examples) < 3) {
                $examples[] = [
                    'id' => $articleId,
                    'title' => (string) $article->title,
                    'current_path' => $currentPath,
                    'preview_path' => $previewPath,
                ];
            }
            if (count($conflicts) >= 50) {
                break;
            }
        }

        foreach (array_keys($generatedPaths) as $knownPath) {
            $matchedArticleIds = [];
            foreach ($previewPolicy->patterns() as $pattern) {
                $values = ArticlePermalinkPattern::compile($pattern)->match($knownPath);
                if ($values === null) {
                    continue;
                }
                $articleId = isset($values['id'])
                    ? (int) $values['id']
                    : ($slugOwners[(string) ($values['slug'] ?? '')] ?? 0);
                if ($articleId > 0 && isset($articleIds[$articleId])) {
                    $matchedArticleIds[$articleId] = true;
                }
            }
            if (count($matchedArticleIds) > 1) {
                $ids = array_keys($matchedArticleIds);
                $conflicts[] = __('article_permalink.errors.ambiguous_path', [
                    'path' => $knownPath,
                    'articles' => '#'.implode(' / #', $ids),
                ]);
            }
            if (count($conflicts) >= 50) {
                break;
            }
        }

        return [
            'affected_articles' => $affectedArticles,
            'examples' => $examples,
            'conflicts' => array_slice(array_values(array_unique($conflicts)), 0, 50),
        ];
    }

    public function activatePrimary(string $pattern, int $expectedRevision): ArticlePermalinkPolicy
    {
        $policy = DB::transaction(function () use ($pattern, $expectedRevision): ArticlePermalinkPolicy {
            if (DB::getDriverName() === 'pgsql') {
                DB::select("select pg_advisory_xact_lock(hashtext('site:primary:article_permalink_policy'))");
            }

            $setting = SiteSetting::query()
                ->where('setting_key', ArticlePermalinkPolicy::SETTING_KEY)
                ->lockForUpdate()
                ->first();
            $currentPolicy = ArticlePermalinkPolicy::fromRaw($setting?->setting_value);
            if ($currentPolicy->revision !== $expectedRevision) {
                throw ValidationException::withMessages([
                    'pattern' => __('article_permalink.errors.revision_conflict'),
                ]);
            }

            $inspection = $this->inspect($pattern, $currentPolicy);
            if ($inspection['conflicts'] !== []) {
                throw ValidationException::withMessages(['pattern' => $inspection['conflicts']]);
            }

            $nextPolicy = $currentPolicy->activate($inspection['pattern']);
            if ($nextPolicy->revision === $currentPolicy->revision) {
                return $currentPolicy;
            }
            SiteSetting::query()->updateOrCreate(
                ['setting_key' => ArticlePermalinkPolicy::SETTING_KEY],
                ['setting_value' => json_encode($nextPolicy->toArray(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)]
            );

            return $nextPolicy;
        });

        SiteSettingsBag::forget();
        $this->resolvedPolicy = $policy;
        $this->resolvedPolicyIdentity = $this->policyIdentity();

        return $policy;
    }

    /** @return \Generator<int,array{article_id:int,title:string,old_path:string,new_path:string,change_reason:string},void,void> */
    public function migrationRows(?ArticlePermalinkPolicy $policy = null): \Generator
    {
        $policy ??= $this->policy();
        $oldPatterns = array_values(array_filter(
            $policy->patterns(),
            static fn (string $pattern): bool => $pattern !== $policy->currentPattern,
        ));
        $relations = ['category'];
        if (Schema::hasTable('article_slug_histories')) {
            $relations[] = 'slugHistories';
        }
        foreach ($this->siteArticles->query()->with($relations)->orderBy('id')->lazyById(200) as $article) {
            $newPath = $this->path($article, $policy);
            $seenPaths = [];
            foreach ($oldPatterns as $oldPattern) {
                $oldPolicy = ArticlePermalinkPolicy::fromRaw(['current_pattern' => $oldPattern]);
                $oldPath = $this->path($article, $oldPolicy);
                if ($oldPath === $newPath) {
                    continue;
                }
                $seenPaths[$oldPath] = true;
                yield [
                    'article_id' => (int) $article->id,
                    'title' => (string) $article->title,
                    'old_path' => $oldPath,
                    'new_path' => $newPath,
                    'change_reason' => $oldPattern === ArticlePermalinkPolicy::DEFAULT_PATTERN
                        ? 'legacy_pattern'
                        : 'retired_pattern',
                ];
            }
            foreach (array_filter(
                $this->knownSlugs($article),
                static fn (string $slug): bool => $slug !== (string) $article->slug,
            ) as $historicalSlug) {
                foreach ($policy->patterns() as $knownPattern) {
                    $compiled = ArticlePermalinkPattern::compile($knownPattern);
                    if (! in_array('slug', $compiled->tokens(), true)) {
                        continue;
                    }
                    $oldPath = $compiled->render($this->values(
                        $article,
                        $historicalSlug,
                        $compiled->tokens(),
                    ));
                    if ($oldPath === $newPath || isset($seenPaths[$oldPath])) {
                        continue;
                    }
                    $seenPaths[$oldPath] = true;
                    yield [
                        'article_id' => (int) $article->id,
                        'title' => (string) $article->title,
                        'old_path' => $oldPath,
                        'new_path' => $newPath,
                        'change_reason' => 'stale_slug',
                    ];
                }
            }
        }

    }

    /** @param array<string,string> $values */
    private function findArticle(array $values): ?Article
    {
        $query = $this->siteArticles->query()->with(['category', 'author']);
        if (isset($values['id'])) {
            return $query->whereKey((int) $values['id'])->first();
        }

        $slug = (string) ($values['slug'] ?? '');
        $article = (clone $query)->where('slug', $slug)->first();
        if ($article instanceof Article || ! Schema::hasTable('article_slug_histories')) {
            return $article;
        }

        $articleId = ArticleSlugHistory::query()->where('slug', $slug)->value('article_id');
        if ($articleId === null) {
            return null;
        }

        return $query->whereKey((int) $articleId)->first();
    }

    private function isSafeRequestPath(string $path): bool
    {
        return str_starts_with($path, '/')
            && strlen($path) <= 2048
            && ! str_contains($path, '?')
            && ! str_contains($path, '#')
            && ! str_contains($path, '\\')
            && ! str_contains($path, '//')
            && preg_match('/%(?![0-9A-Fa-f]{2})/', $path) !== 1
            && preg_match('/%(?:2f|5c)/i', $path) !== 1;
    }

    private function matchingPath(string $path): string
    {
        return $path !== '/' ? rtrim($path, '/') : $path;
    }

    /** @return array<string,int|string> */
    /** @param null|list<string> $tokens */
    private function values(Article $article, ?string $slug = null, ?array $tokens = null): array
    {
        $tokens ??= ArticlePermalinkPattern::TOKENS;
        if (in_array('category', $tokens, true)) {
            $article->loadMissing('category');
        }
        $createdAt = array_intersect(['year', 'month', 'day'], $tokens) !== []
            ? $article->created_at?->copy()->timezone((string) config('app.timezone', 'UTC'))
            : null;

        return [
            'slug' => $slug ?? (string) $article->slug,
            'id' => (int) $article->id,
            'category' => in_array('category', $tokens, true)
                ? (string) ($article->category?->slug ?? '')
                : '',
            'year' => $createdAt?->format('Y') ?? '',
            'month' => $createdAt?->format('m') ?? '',
            'day' => $createdAt?->format('d') ?? '',
        ];
    }

    /** @return list<string> */
    private function knownSlugs(Article $article): array
    {
        $slugs = [(string) $article->slug];
        if (Schema::hasTable('article_slug_histories')) {
            $article->loadMissing('slugHistories');
            foreach ($article->slugHistories as $history) {
                $slugs[] = (string) $history->slug;
            }
        }

        return array_values(array_unique($slugs));
    }

    /**
     * @param  list<string>  $knownSlugs
     * @return list<string>
     */
    private function inspectionPaths(
        Article $article,
        ArticlePermalinkPolicy $policy,
        array $knownSlugs,
    ): array {
        $paths = [];
        foreach ($policy->patterns() as $pattern) {
            $compiled = ArticlePermalinkPattern::compile($pattern);
            $slugs = in_array('slug', $compiled->tokens(), true)
                ? $knownSlugs
                : [(string) $article->slug];
            foreach ($slugs as $slug) {
                $paths[] = $compiled->render($this->values($article, $slug, $compiled->tokens()));
            }
        }

        return array_values(array_unique($paths));
    }

    /** @param array<string,string> $values */
    private function redirectReason(string $requestPath, string $matchingPath, string $source, array $values, Article $article): string
    {
        if ($requestPath !== $matchingPath) {
            return 'trailing_slash';
        }
        if (isset($values['slug']) && $values['slug'] !== (string) $article->slug) {
            return 'stale_slug';
        }
        if (isset($values['category']) && $values['category'] !== (string) ($article->category?->slug ?? '')) {
            return 'stale_category';
        }
        if (array_intersect(['year', 'month', 'day'], array_keys($values)) !== []) {
            $createdAt = $article->created_at?->copy()->timezone((string) config('app.timezone', 'UTC'));
            foreach (['year' => 'Y', 'month' => 'm', 'day' => 'd'] as $token => $format) {
                if (isset($values[$token]) && $values[$token] !== ($createdAt?->format($format) ?? '')) {
                    return 'stale_date';
                }
            }
        }

        return $source;
    }

    private function countStructuredLegacyLinks(ArticlePermalinkPolicy $policy): int
    {
        if (! Schema::hasTable('site_settings') || $policy->currentPattern === ArticlePermalinkPolicy::DEFAULT_PATTERN) {
            return 0;
        }

        return SiteSetting::query()
            ->where('setting_key', '!=', ArticlePermalinkPolicy::SETTING_KEY)
            ->where('setting_value', 'like', '%/article/%')
            ->count();
    }

    private function policyIdentity(): string
    {
        if ($this->currentSite->isResolved() && $this->currentSite->isHosted()) {
            $profile = $this->currentSite->profile();

            return sprintf(
                'hosted:%d:%d',
                (int) $profile?->id,
                (int) $profile?->settings_version,
            );
        }

        return 'primary:'.SiteSettingsBag::localRevision();
    }
}
