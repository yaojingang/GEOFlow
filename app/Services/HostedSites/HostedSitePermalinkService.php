<?php

namespace App\Services\HostedSites;

use App\Jobs\RefreshHostedSitePermalinkUrls;
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
use Illuminate\Support\Facades\DB;
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
        $articles = $this->articles($channel)->lazyById(500);
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
        [$policy, $hostname] = DB::transaction(function () use ($channel, $pattern, $expectedRevision): array {
            $lockedChannel = DistributionChannel::query()->whereKey($channel->id)->lockForUpdate()->firstOrFail();
            $lockedChannel = $this->hosted($lockedChannel);
            $profile = HostedSiteProfile::query()
                ->where('distribution_channel_id', $lockedChannel->id)
                ->lockForUpdate()
                ->firstOrFail();
            if ($profile->serving_status === HostedSiteProfile::SERVING_ARCHIVED) {
                throw new DomainException(__('article_permalink.errors.hosted_archived'));
            }
            $currentPolicy = $this->policy($lockedChannel);
            if ($currentPolicy->revision !== $expectedRevision) {
                throw ValidationException::withMessages(['pattern' => __('article_permalink.errors.hosted_revision_conflict')]);
            }
            $inspection = $this->inspect($lockedChannel, $pattern);
            if ($inspection['conflicts'] !== []) {
                throw ValidationException::withMessages(['pattern' => $inspection['conflicts']]);
            }
            $nextPolicy = $currentPolicy->activate($inspection['pattern']);
            if ($nextPolicy->revision === $currentPolicy->revision) {
                return [$currentPolicy, (string) $profile->hostname];
            }

            $settings = is_array($lockedChannel->site_settings) ? $lockedChannel->site_settings : [];
            $settings[ArticlePermalinkPolicy::SETTING_KEY] = $nextPolicy->toArray();
            $lockedChannel->forceFill(['site_settings' => $settings])->save();
            $profile->forceFill(['settings_version' => (int) $profile->settings_version + 1])->save();

            return [$nextPolicy, (string) $profile->hostname];
        }, 3);

        $this->resolver->invalidate($hostname);
        RefreshHostedSitePermalinkUrls::dispatch((int) $channel->id, $policy->revision);

        return $policy;
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
            ->with(['category', 'slugHistories']);
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
