<?php

namespace App\Services\Site;

use App\Data\Site\ArticlePermalinkResolution;
use App\Models\Article;
use App\Models\ArticleSlugHistory;
use App\Models\Category;
use App\Models\DistributionChannel;
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

    private ?bool $slugHistoriesTableExists = null;

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

    public function forgetPolicy(): void
    {
        $this->resolvedPolicy = null;
        $this->resolvedPolicyIdentity = null;
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

    /** @param list<string> $tokens @return array<string,int|string> */
    public function valuesForPatterns(Article $article, string $slug, array $tokens): array
    {
        $this->slugRegistry->assertValid($slug);

        return $this->values($article, $slug, $tokens);
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
        $this->assertSlugsValid($this->knownSlugs($article));
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
        $relations = ['category:id,slug'];
        if ($this->hasSlugHistoriesTable()) {
            $relations[] = 'slugHistories:id,article_id,slug';
        }
        $articles = fn () => $this->siteArticles->query()
            ->select(['articles.id', 'articles.title', 'articles.slug', 'articles.category_id', 'articles.created_at'])
            ->with($relations)
            ->lazyById(500, 'articles.id', 'id');
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

    public function assertAdminBasePathCompatible(string $adminBasePath): void
    {
        $usesRootCategory = $this->assertPolicyAdminBasePathCompatible($this->policy(), $adminBasePath);
        foreach (DistributionChannel::query()->whereHas('hostedSiteProfile')
            ->select(['id', 'site_settings'])->lazyById(100) as $channel) {
            $policy = ArticlePermalinkPolicy::fromRaw(($channel->site_settings ?? [])[ArticlePermalinkPolicy::SETTING_KEY] ?? null);
            $usesRootCategory = $this->assertPolicyAdminBasePathCompatible($policy, $adminBasePath) || $usesRootCategory;
        }

        if (! $usesRootCategory) {
            return;
        }

        $reservedRoots = ArticlePermalinkPattern::reservedFirstSegments($adminBasePath);
        foreach (['categories', 'category_slug_histories'] as $table) {
            if (Schema::hasTable($table) && DB::table($table)
                ->whereIn(DB::raw("LOWER(TRIM(slug, '/'))"), $reservedRoots)->exists()) {
                throw new \InvalidArgumentException(__('article_permalink.errors.admin_path_conflict'));
            }
        }
    }

    private function assertPolicyAdminBasePathCompatible(ArticlePermalinkPolicy $policy, string $adminBasePath): bool
    {
        $usesRootCategory = false;
        foreach ($policy->patterns() as $pattern) {
            $usesRootCategory = ArticlePermalinkPattern::compile($pattern, $adminBasePath)->usesRootCategorySegment() || $usesRootCategory;
        }

        return $usesRootCategory;
    }

    /**
     * @param  callable():iterable<int,Article>  $articles
     * @return array{
     *   affected_articles:int,
     *   examples:list<array{id:int,title:string,current_path:string,preview_path:string}>,
     *   conflicts:list<string>
     * }
     */
    public function inspectArticles(
        callable $articles,
        ArticlePermalinkPolicy $currentPolicy,
        ArticlePermalinkPolicy $previewPolicy,
    ): array {
        $examples = [];
        $affectedArticles = 0;
        $articleIds = [];
        $slugOwners = [];
        $conflicts = $this->reservedRootCategoryConflicts($previewPolicy);
        $patternIndex = $this->indexPatternsByFirstSegment($previewPolicy);

        foreach ($articles() as $article) {
            try {
                $knownSlugs = $this->knownSlugs($article);
                $this->assertSlugsValid($knownSlugs);
                $currentPath = $this->path($article, $currentPolicy);
                $previewPath = $this->path($article, $previewPolicy);
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

        if (count($conflicts) < 50) {
            foreach ($articles() as $article) {
                try {
                    $knownPaths = $this->inspectionPaths(
                        $article,
                        $previewPolicy,
                        $this->knownSlugs($article),
                    );
                } catch (\InvalidArgumentException|ValidationException) {
                    continue;
                }

                foreach ($knownPaths as $knownPath) {
                    $matchedArticleIds = $this->matchedArticleIds(
                        $knownPath,
                        $patternIndex,
                        $articleIds,
                        $slugOwners,
                    );
                    if (count($matchedArticleIds) > 1) {
                        $conflicts[] = __('article_permalink.errors.ambiguous_path', [
                            'path' => $knownPath,
                            'articles' => '#'.implode(' / #', $matchedArticleIds),
                        ]);
                    }
                    if (count($conflicts) >= 50) {
                        break 2;
                    }
                }
            }
        }

        return [
            'affected_articles' => $affectedArticles,
            'examples' => $examples,
            'conflicts' => array_slice(array_values(array_unique($conflicts)), 0, 50),
        ];
    }

    /** @return list<string> */
    private function reservedRootCategoryConflicts(
        ArticlePermalinkPolicy $policy,
        ?string $adminBasePath = null,
    ): array {
        $usesRootCategory = collect($policy->patterns())
            ->contains(static fn (string $pattern): bool => ArticlePermalinkPattern::compile($pattern, $adminBasePath)->usesRootCategorySegment());
        if (! $usesRootCategory || ! Schema::hasTable('categories')) {
            return [];
        }

        $conflicts = [];
        foreach (Category::query()->select(['id', 'slug'])->lazyById(500) as $category) {
            $slug = (string) $category->slug;
            if (! ArticlePermalinkPattern::isReservedFirstSegment($slug, $adminBasePath)) {
                continue;
            }

            $conflicts[] = __('article_permalink.errors.category_reserved_path', [
                'slug' => $slug,
                'path' => mb_strtolower($slug, 'UTF-8'),
            ]);
            if (count($conflicts) >= 50) {
                break;
            }
        }

        return $conflicts;
    }

    public function activatePrimary(string $pattern, int $expectedRevision): ArticlePermalinkPolicy
    {
        throw ValidationException::withMessages(['pattern' => __('url_change.errors.protected')]);
    }

    /** @return \Generator<int,array{article_id:int,title:string,old_path:string,new_path:string,change_reason:string},void,void> */
    public function migrationRows(?ArticlePermalinkPolicy $policy = null): \Generator
    {
        $policy ??= $this->policy();
        $oldPatterns = array_values(array_filter(
            $policy->patterns(),
            static fn (string $pattern): bool => $pattern !== $policy->currentPattern,
        ));
        $relations = ['category:id,slug'];
        if ($this->hasSlugHistoriesTable()) {
            $relations[] = 'slugHistories:id,article_id,slug';
        }
        foreach ($this->siteArticles->query()
            ->select(['articles.id', 'articles.title', 'articles.slug', 'articles.category_id', 'articles.created_at'])
            ->with($relations)
            ->lazyById(200, 'articles.id', 'id') as $article) {
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
        if ($article instanceof Article || ! $this->hasSlugHistoriesTable()) {
            return $article;
        }

        $articleId = ArticleSlugHistory::query()->where('slug', $slug)->value('article_id');
        if ($articleId === null) {
            return null;
        }

        return $query->whereKey((int) $articleId)->first();
    }

    public function isSafeRequestPath(string $path): bool
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
        if ($this->hasSlugHistoriesTable()) {
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

    /**
     * @param  array<int,true>  $articleIds
     * @param  array<string,int>  $slugOwners
     * @param  array{dynamic:list<string>,fixed:array<string,list<string>>}  $patternIndex
     * @return list<int>
     */
    private function matchedArticleIds(
        string $knownPath,
        array $patternIndex,
        array $articleIds,
        array $slugOwners,
    ): array {
        $matchedArticleIds = [];
        $firstSegment = explode('/', ltrim($knownPath, '/'), 2)[0];
        $patterns = array_merge(
            $patternIndex['dynamic'],
            $patternIndex['fixed'][$firstSegment] ?? [],
        );
        foreach ($patterns as $pattern) {
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

        return array_map('intval', array_keys($matchedArticleIds));
    }

    /** @return array{dynamic:list<string>,fixed:array<string,list<string>>} */
    private function indexPatternsByFirstSegment(ArticlePermalinkPolicy $policy): array
    {
        $index = ['dynamic' => [], 'fixed' => []];
        foreach ($policy->patterns() as $pattern) {
            $firstSegment = explode('/', ltrim($pattern, '/'), 2)[0];
            if (str_contains($firstSegment, '{')) {
                $index['dynamic'][] = $pattern;

                continue;
            }

            $index['fixed'][$firstSegment][] = $pattern;
        }

        return $index;
    }

    /** @param list<string> $slugs */
    private function assertSlugsValid(array $slugs): void
    {
        foreach ($slugs as $slug) {
            $this->slugRegistry->assertValid($slug);
        }
    }

    private function hasSlugHistoriesTable(): bool
    {
        return $this->slugHistoriesTableExists ??= Schema::hasTable('article_slug_histories');
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
