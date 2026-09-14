<?php

namespace App\Services\Site;

use App\Models\Article;
use App\Models\ArticleSlugHistory;
use App\Models\Category;
use App\Models\HostedSiteArticleAssignment;
use App\Models\UrlChangeRequest;
use App\Support\GeoFlow\ArticleWorkflow;
use App\Support\Site\ArticlePermalinkPattern;
use App\Support\Site\ArticlePermalinkPolicy;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Validation\ValidationException;

final class UrlChangeInspector
{
    public function __construct(
        private readonly ArticlePermalinkService $permalinks,
        private readonly UrlChangeSiteCatalog $siteCatalog,
        private readonly ArticleUrlOwnershipGuard $ownership,
    ) {}

    public function assertArticleCompatible(Article $article): void
    {
        $article->loadMissing('category:id,slug');
        foreach ($this->siteCatalog->sites('article_category', (int) $article->id) as $site) {
            $site['next_policy'] = $site['policy'];
            $change = new UrlChangeRequest(['operation' => 'primary']);
            $errors = $this->conflicts($change, $site, [['article' => $article, 'slug' => $article->slug]]);
            if ($errors !== []) {
                throw ValidationException::withMessages(['slug' => $errors]);
            }
            $this->ownership->assertAvailable($article, $site, $this->articles($site));
        }
    }

    /** @param array<string,mixed> $site @return Builder<Article> */
    public function articles(array $site, bool $public = false): Builder
    {
        $query = $public ? Article::query() : Article::withTrashed();
        if ($site['key'] === 'primary') {
            return $public ? $query->published() : $query;
        }
        $query->whereHas('hostedSiteAssignment', function (Builder $assignment) use ($site, $public): void {
            $assignment->where('hosted_site_profile_id', $site['profile_id']);
            if ($public) {
                $assignment->where('status', HostedSiteArticleAssignment::STATUS_PUBLISHED);
            }
        });
        if ($public) {
            $query->whereIn('articles.status', ['private', 'published'])
                ->whereIn('articles.review_status', ArticleWorkflow::PUBLISHABLE_REVIEW_STATUSES)
                ->whereHas('task', fn (Builder $task) => $task->where('publish_scope', 'distribution_only'));
        }

        return $query;
    }

    /** @param array<string,mixed> $site @return Builder<Article> */
    public function targets(UrlChangeRequest $change, array $site): Builder
    {
        $query = $this->articles($site);
        if ($change->operation === 'category') {
            $query->where('articles.category_id', $change->target_id);
        } elseif ($change->operation === 'article_category') {
            $query->whereKey($change->target_id);
        }

        return $query;
    }

    public function changedArticle(Article $article, UrlChangeRequest $change): Article
    {
        $copy = clone $article;
        if ($change->operation === 'category') {
            $category = clone $article->category;
            $category->slug = $change->new_value;
            $copy->setRelation('category', $category);
        } elseif ($change->operation === 'article_category') {
            $copy->category_id = (int) $change->new_value;
            $copy->setRelation('category', Category::query()->findOrFail($change->new_value));
        }

        return $copy;
    }

    /**
     * @param  array<string,mixed>  $site
     * @param  list<array{article:Article,slug:string}>  $items
     * @return list<string>
     */
    public function conflicts(UrlChangeRequest $change, array $site, array $items): array
    {
        $policy = ArticlePermalinkPolicy::fromRaw($site['next_policy']);
        $compiled = array_map(fn (string $pattern) => ArticlePermalinkPattern::compile($pattern), $policy->patterns());
        $tokens = array_values(array_unique(array_merge(...array_map(fn ($pattern) => $pattern->tokens(), $compiled))));
        $possibleMatchers = array_map(fn ($rendering) => array_filter($compiled, fn ($matcher) => substr_count($matcher->pattern(), '/') === substr_count($rendering->pattern(), '/') && ! $matcher->preservesLocatorOf($rendering)), $compiled);
        $reusableValidation = array_map(fn ($pattern) => ! array_any(explode('/', $pattern->pattern()), fn ($segment) => substr_count($segment, '{') > 1), $compiled);
        $validationGroups = array_map(function ($pattern): string {
            $tokens = $pattern->tokens();
            sort($tokens);

            return implode(',', $tokens).':'.($pattern->usesRootCategorySegment() ? 'root' : 'nested').':'.strlen($pattern->pattern());
        }, $compiled);
        $candidates = [];
        $errors = [];
        if ($this->needsHistoricalOwnershipCheck($compiled)) {
            $deadline = microtime(true) + 10;
            $nextSite = array_replace($site, ['policy' => $site['next_policy']]);
            foreach ($items as $item) {
                $owner = clone $item['article'];
                $owner->slug = $item['slug'];
                try {
                    $this->ownership->assertAvailable($owner, $nextSite, $this->articles($site));
                } catch (ValidationException $exception) {
                    return array_merge(...array_values($exception->errors()));
                }
                if (microtime(true) > $deadline) {
                    return [__('url_change.errors.ownership_budget')];
                }
            }
        }
        foreach ($items as $item) {
            $variants = in_array($change->operation, ['category', 'article_category'], true)
                ? [$item['article'], $this->changedArticle($item['article'], $change)] : [$item['article']];
            foreach ($variants as $article) {
                $validatedGroups = [];
                try {
                    $renderValues = $this->permalinks->valuesForPatterns($article, $item['slug'], $tokens);
                } catch (\InvalidArgumentException|ValidationException $exception) {
                    $errors[] = '#'.$article->id.': '.$exception->getMessage();

                    continue;
                }
                foreach ($compiled as $position => $pattern) {
                    if ($reusableValidation[$position] && $possibleMatchers[$position] === [] && isset($validatedGroups[$validationGroups[$position]])) {
                        continue;
                    }
                    try {
                        $path = $pattern->render($renderValues);
                        if (! $this->permalinks->isSafeRequestPath($path)) {
                            $errors[] = __('url_change.errors.request_path_invalid', ['article' => $article->id, 'length' => strlen($path)]);

                            continue;
                        }
                        $locator = in_array('id', $pattern->tokens(), true) ? 'id' : 'slug';
                        $resolvedValues = $pattern->match($path);
                        if (($resolvedValues[$locator] ?? null) !== (string) $renderValues[$locator]) {
                            $errors[] = __('url_change.errors.locator_mismatch', ['article' => $article->id, 'path' => $path]);

                            continue;
                        }
                        if ($reusableValidation[$position]) {
                            $validatedGroups[$validationGroups[$position]] = true;
                        }
                    } catch (\InvalidArgumentException|ValidationException $exception) {
                        $errors[] = '#'.$article->id.': '.$exception->getMessage();

                        continue;
                    }
                    foreach ($possibleMatchers[$position] as $matcher) {
                        $values = $matcher->match($path);
                        if ($values !== null) {
                            // The immutable ID or registered current/history slug already proves ownership.
                            if (isset($values['id']) ? (int) $values['id'] === (int) $article->id : ($values['slug'] ?? '') === $item['slug']) {
                                continue;
                            }
                            $candidates[] = ['expected' => (int) $article->id, 'path' => $path, 'values' => $values];
                        }
                        if (count($candidates) >= 500) {
                            $errors = array_merge($errors, $this->resolveCandidates($site, $candidates));
                            $candidates = [];
                        }
                    }
                    if (count($errors) >= 20) {
                        return array_slice(array_unique($errors), 0, 20);
                    }
                }
            }
        }

        return array_slice(array_unique(array_merge($errors, $this->resolveCandidates($site, $candidates))), 0, 20);
    }

    /** Historical display values require reverse proof only when they can enter another rule's locator. */
    private function needsHistoricalOwnershipCheck(array $patterns): bool
    {
        foreach ($patterns as $matcher) {
            $locator = in_array('id', $matcher->tokens(), true) ? 'id' : 'slug';
            $segments = explode('/', $matcher->pattern());
            $position = array_find_key($segments, fn ($segment) => str_contains($segment, '{'.$locator.'}'));
            foreach ($patterns as $renderer) {
                $other = explode('/', $renderer->pattern());
                if (count($segments) === count($other) && ! $matcher->preservesLocatorOf($renderer)
                    && preg_match('/\{(?:category|year|month|day)\}/', $other[$position]) === 1) {
                    return true;
                }
            }
        }

        return false;
    }

    /** @param array<string,mixed> $site @param list<array<string,mixed>> $candidates @return list<string> */
    private function resolveCandidates(array $site, array $candidates): array
    {
        if ($candidates === []) {
            return [];
        }
        $ids = [];
        $slugs = [];
        foreach ($candidates as $candidate) {
            if (isset($candidate['values']['id'])) {
                $ids[] = (int) $candidate['values']['id'];
            } else {
                $slugs[] = (string) ($candidate['values']['slug'] ?? '');
            }
        }
        $owners = Article::withTrashed()->whereIn('slug', array_unique($slugs))->pluck('id', 'slug')->all();
        foreach (ArticleSlugHistory::query()->whereIn('slug', array_unique($slugs))->pluck('article_id', 'slug') as $slug => $id) {
            $owners[$slug] ??= (int) $id;
        }
        $visible = $this->articles($site)->whereIn('articles.id', array_unique(array_merge($ids, array_values($owners))))->pluck('articles.id')->flip()->all();
        $errors = [];
        foreach ($candidates as $candidate) {
            $id = isset($candidate['values']['id']) ? (int) $candidate['values']['id'] : (int) ($owners[$candidate['values']['slug'] ?? ''] ?? 0);
            if (isset($visible[$id]) && $id !== $candidate['expected']) {
                $errors[] = __('article_permalink.errors.ambiguous_path', ['path' => $candidate['path'], 'articles' => '#'.$candidate['expected'].' / #'.$id]);
            }
        }

        return $errors;
    }

    /** @param array<string,mixed> $site @return list<array{article:Article,slug:string}> */
    public function historyBatch(UrlChangeRequest $change, array $site, int $after): array
    {
        $histories = ArticleSlugHistory::query()->where('id', '>', $after)
            ->whereIn('article_id', $this->targets($change, $site)->select('articles.id'))
            ->orderBy('id')->limit(100)->get(['id', 'article_id', 'slug']);
        $articles = Article::withTrashed()->whereIn('id', $histories->pluck('article_id'))
            ->with('category:id,slug')->get(['id', 'title', 'slug', 'category_id', 'created_at'])->keyBy('id');

        return $histories->map(fn ($history): array => ['article' => $articles[$history->article_id], 'slug' => $history->slug, 'history_id' => (int) $history->id])->all();
    }
}
