<?php

namespace App\Services\Site;

use App\Models\Article;
use App\Models\HostedSiteArticleAssignment;
use App\Support\GeoFlow\ArticleWorkflow;
use App\Support\Site\CurrentSite;
use Illuminate\Database\Eloquent\Builder;

final class SiteScopedArticleQuery
{
    public function __construct(private readonly CurrentSite $currentSite) {}

    /** @return Builder<Article> */
    public function query(): Builder
    {
        return $this->apply(Article::query());
    }

    /** @param Builder<Article> $query @return Builder<Article> */
    public function apply(Builder $query): Builder
    {
        return $this->applyForSiteKey($query, $this->currentSite->isHosted()
            ? 'hosted:'.$this->currentSite->profileId()
            : 'primary');
    }

    /** @return Builder<Article> */
    public function queryForSiteKey(string $siteKey): Builder
    {
        return $this->applyForSiteKey(Article::query(), $siteKey);
    }

    /** @param Builder<Article> $query @return Builder<Article> */
    public function applyForSiteKey(Builder $query, string $siteKey): Builder
    {
        if ($siteKey === 'primary') {
            return $query
                ->published()
                ->where(function (Builder $articles): void {
                    $articles
                        ->whereNull('articles.task_id')
                        ->orWhereHas('task', fn (Builder $task): Builder => $task
                            ->where('publish_scope', '!=', 'distribution_only'));
                });
        }

        if (! preg_match('/^hosted:([1-9][0-9]*)$/D', $siteKey, $matches)) {
            throw new \InvalidArgumentException('Invalid site key.');
        }

        $profileId = (int) $matches[1];

        return $query
            ->whereNull('articles.deleted_at')
            ->whereIn('articles.review_status', ArticleWorkflow::PUBLISHABLE_REVIEW_STATUSES)
            ->whereIn('articles.status', ['private', 'published'])
            ->whereHas('task', fn (Builder $task): Builder => $task->where('publish_scope', 'distribution_only'))
            ->whereHas('hostedSiteAssignment', function (Builder $assignment) use ($profileId): void {
                $assignment
                    ->where('hosted_site_profile_id', $profileId)
                    ->where('status', HostedSiteArticleAssignment::STATUS_PUBLISHED);
            });
    }
}
