<?php

namespace App\Services\HostedSites;

use App\Models\Article;
use App\Models\DistributionChannel;
use App\Models\HostedSiteArticleAssignment;
use App\Models\HostedSiteProfile;
use App\Services\Site\ArticlePermalinkService;
use App\Services\Site\HostedSiteResolver;
use App\Support\GeoFlow\ArticleWorkflow;
use App\Support\Site\ArticlePermalinkPattern;
use App\Support\Site\ArticlePermalinkPolicy;
use DomainException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Validation\ValidationException;

class HostedSitePermalinkService
{
    public function __construct(
        private readonly ArticlePermalinkService $articlePermalinks,
        private readonly HostedSiteResolver $resolver,
    ) {}

    public function policy(DistributionChannel $channel): ArticlePermalinkPolicy
    {
        $settings = is_array($channel->site_settings) ? $channel->site_settings : [];

        return ArticlePermalinkPolicy::fromRaw($settings[ArticlePermalinkPolicy::SETTING_KEY] ?? null);
    }

    /** @return array{pattern:string,current_pattern:string,revision:int,affected_articles:int,examples:list<array{id:int,title:string,current_path:string,preview_path:string}>,history:list<array{pattern:string,retired_at:string}>,conflicts:list<string>} */
    public function inspect(DistributionChannel $channel, string $pattern): array
    {
        $channel = $this->hosted($channel);
        $compiled = ArticlePermalinkPattern::compile($pattern);
        $currentPolicy = $this->policy($channel);
        $previewPolicy = $currentPolicy->activate($compiled->pattern());
        $articles = fn () => $this->articles($channel)->lazyById(500, 'articles.id', 'id');
        $analysis = $this->articlePermalinks->inspectArticles($articles, $currentPolicy, $previewPolicy);

        return [
            'pattern' => $compiled->pattern(),
            'current_pattern' => $currentPolicy->currentPattern,
            'revision' => $currentPolicy->revision,
            'affected_articles' => $analysis['affected_articles'],
            'examples' => $analysis['examples'],
            'history' => $currentPolicy->history,
            'conflicts' => $analysis['conflicts'],
        ];
    }

    public function activate(DistributionChannel $channel, string $pattern, int $expectedRevision): ArticlePermalinkPolicy
    {
        throw ValidationException::withMessages(['pattern' => __('url_change.errors.protected')]);
    }

    /** @return \Generator<int,array{article_id:int,title:string,old_path:string,new_path:string,change_reason:string},void,void> */
    public function migrationRows(DistributionChannel $channel, string $pattern): \Generator
    {
        $channel = $this->hosted($channel);
        $currentPolicy = $this->policy($channel);
        $previewPolicy = $currentPolicy->activate(ArticlePermalinkPattern::compile($pattern)->pattern());

        foreach ($this->articles($channel)->lazyById(200) as $article) {
            $newPath = $this->articlePermalinks->path($article, $previewPolicy);
            $seenPaths = [];
            foreach ($previewPolicy->patterns() as $knownPattern) {
                if ($knownPattern === $previewPolicy->currentPattern) {
                    continue;
                }
                $oldPolicy = ArticlePermalinkPolicy::fromRaw(['current_pattern' => $knownPattern]);
                $oldPath = $this->articlePermalinks->path($article, $oldPolicy);
                if ($oldPath === $newPath || isset($seenPaths[$oldPath])) {
                    continue;
                }
                $seenPaths[$oldPath] = true;
                yield [
                    'article_id' => (int) $article->id,
                    'title' => (string) $article->title,
                    'old_path' => $oldPath,
                    'new_path' => $newPath,
                    'change_reason' => $knownPattern === ArticlePermalinkPolicy::DEFAULT_PATTERN
                        ? 'legacy_pattern'
                        : 'retired_pattern',
                ];
            }
            foreach ($article->slugHistories as $history) {
                foreach ($previewPolicy->patterns() as $knownPattern) {
                    $compiled = ArticlePermalinkPattern::compile($knownPattern);
                    if (! in_array('slug', $compiled->tokens(), true)) {
                        continue;
                    }
                    $oldPath = $this->articlePermalinks->pathUsingSlug(
                        $article,
                        (string) $history->slug,
                        ArticlePermalinkPolicy::fromRaw(['current_pattern' => $knownPattern]),
                    );
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

    private function articles(DistributionChannel $channel): Builder
    {
        $profileId = (int) $channel->hostedSiteProfile?->id;

        return Article::query()
            ->whereNull('articles.deleted_at')
            ->whereIn('articles.review_status', ArticleWorkflow::PUBLISHABLE_REVIEW_STATUSES)
            ->whereIn('articles.status', ['private', 'published'])
            ->whereHas('task', fn (Builder $task): Builder => $task->where('publish_scope', 'distribution_only'))
            ->whereHas('hostedSiteAssignment', fn ($query) => $query
                ->where('hosted_site_profile_id', $profileId)
                ->where('status', HostedSiteArticleAssignment::STATUS_PUBLISHED))
            ->select(['articles.id', 'articles.title', 'articles.slug', 'articles.category_id', 'articles.task_id', 'articles.created_at'])
            ->with(['category:id,slug', 'slugHistories:id,article_id,slug']);
    }

    private function hosted(DistributionChannel $channel): DistributionChannel
    {
        $channel->loadMissing('hostedSiteProfile');
        if (! $channel->isHostedSite() || ! $channel->hostedSiteProfile instanceof HostedSiteProfile) {
            throw new DomainException(__('article_permalink.errors.hosted_invalid'));
        }

        return $channel;
    }
}
