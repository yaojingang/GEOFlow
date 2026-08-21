<?php

namespace App\Services\Site;

use App\Models\Article;
use App\Models\HostedSiteArticleAssignment;
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
        if (! $this->currentSite->isHosted()) {
            return $query->published();
        }

        $profileId = (int) $this->currentSite->profileId();

        return $query
            ->whereNull('articles.deleted_at')
            ->where('articles.review_status', 'approved')
            ->whereIn('articles.status', ['private', 'published'])
            ->whereHas('task', fn (Builder $task): Builder => $task->where('publish_scope', 'distribution_only'))
            ->whereHas('hostedSiteAssignment', function (Builder $assignment) use ($profileId): void {
                $assignment
                    ->where('hosted_site_profile_id', $profileId)
                    ->where('status', HostedSiteArticleAssignment::STATUS_PUBLISHED);
            });
    }
}
