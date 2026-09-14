<?php

namespace App\Services\Site;

use App\Jobs\CheckUrlChange;
use App\Jobs\RefreshUrlChange;
use App\Models\Admin;
use App\Models\Article;
use App\Models\ArticleSlugHistory;
use App\Models\Category;
use App\Models\CategorySlugHistory;
use App\Models\DistributionChannel;
use App\Models\HostedSiteProfile;
use App\Models\SiteSetting;
use App\Models\UrlChangeRequest;
use App\Services\GeoFlow\CategorySlugRegistry;
use App\Support\Site\ArticlePermalinkPattern;
use App\Support\Site\ArticlePermalinkPolicy;
use App\Support\Site\SiteSettingsBag;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

final class UrlChangeService
{
    public function __construct(
        private readonly UrlChangeVersions $versions,
        private readonly UrlChangeInspector $inspector,
        private readonly UrlChangeReportStore $reports,
        private readonly ArticlePermalinkService $permalinks,
        private readonly CategorySlugRegistry $categories,
        private readonly UrlChangeSiteCatalog $siteCatalog,
        private readonly UrlChangeSettingsInspector $settingsInspector,
    ) {}

    public function authorize(Admin $admin): void
    {
        abort_unless($admin->status === 'active' && $admin->isSuperAdmin(), 403);
    }

    public function start(Admin $admin, string $operation, ?int $targetId, string $value): UrlChangeRequest
    {
        $this->authorize($admin);
        if (! in_array($operation, ['primary', 'hosted', 'category', 'article_category'], true)) {
            abort(422);
        }
        $sites = $this->sites($operation, $targetId);
        $old = match ($operation) {
            'category' => (string) Category::query()->findOrFail($targetId)->slug,
            'article_category' => (string) Article::query()->findOrFail($targetId)->category_id,
            default => (string) $sites[0]['policy']['current_pattern'],
        };
        $value = match ($operation) {
            'category' => $this->categories->normalize($value),
            'article_category' => (string) Category::query()->findOrFail((int) $value)->id,
            default => ArticlePermalinkPattern::compile($value)->pattern(),
        };
        if ($old === $value) {
            throw ValidationException::withMessages(['pattern' => __('url_change.errors.unchanged')]);
        }
        if ($operation === 'category') {
            $this->categories->assertAvailable($value, $targetId);
        }
        if (in_array(config('queue.connections.'.config('queue.default').'.driver'), ['sync', 'null'], true)) {
            throw ValidationException::withMessages(['pattern' => __('url_change.errors.queue_required')]);
        }
        foreach ($sites as &$site) {
            $policy = ArticlePermalinkPolicy::fromRaw($site['policy']);
            $site['next_policy'] = in_array($operation, ['primary', 'hosted'], true) ? $policy->activate($value)->toArray() : $policy->toArray();
        }
        unset($site);
        $scopes = array_values(array_unique(array_merge(array_column($sites, 'key'), $operation === 'category' ? ['category:'.$targetId] : [])));
        $change = DB::transaction(function () use ($admin, $operation, $targetId, $old, $value, $sites, $scopes): UrlChangeRequest {
            $snapshot = $this->versions->snapshot($scopes, true);
            $activeIds = DB::table('url_change_scope_states')->whereIn('scope_key', array_column($sites, 'key'))->whereNotNull('active_request_id')->distinct()->pluck('active_request_id');
            foreach ($activeIds as $active) {
                $existing = UrlChangeRequest::query()->find($active);
                if ($existing?->status === 'ready' && $existing->expires_at?->isPast()) {
                    $this->finish($existing, 'stale', __('url_change.errors.expired'));
                } elseif ($existing?->admin_id === $admin->id && $existing->operation === $operation && $existing->target_id === $targetId && $existing->new_value === $value) {
                    return $existing;
                } else {
                    throw ValidationException::withMessages(['pattern' => __('url_change.errors.busy')]);
                }
            }
            $change = UrlChangeRequest::query()->create([
                'admin_id' => $admin->id, 'auth_version' => $admin->auth_version,
                'operation' => $operation, 'locale' => app()->getLocale(), 'target_id' => $targetId, 'old_value' => $old, 'new_value' => $value,
                'status' => 'checking', 'versions' => $snapshot, 'sites' => $sites,
                'progress' => ['site' => 0, 'phase' => in_array($operation, ['primary', 'hosted'], true) ? 'categories' : 'articles', 'after' => 0, 'segments' => 0, 'bytes' => 0],
                'summary' => ['associated' => 0, 'public_articles' => 0, 'public_urls' => 0, 'changed_urls' => 0, 'changed_articles' => 0, 'potential_urls' => 0, 'trashed' => 0, 'non_public' => 0, 'examples' => [], 'sites' => [], 'synced_records' => 0, 'channels' => [], 'category_pages' => [], 'structured_settings' => 0],
            ]);
            DB::table('url_change_scope_states')->whereIn('scope_key', array_column($sites, 'key'))->update(['active_request_id' => $change->id]);

            return $change;
        }, 3);
        CheckUrlChange::dispatch($change->id)->afterCommit();

        return $change;
    }

    /** @return list<array<string,mixed>> */
    public function sites(string $operation, ?int $targetId, bool $requireAvailable = true): array
    {
        return $this->siteCatalog->sites($operation, $targetId, $requireAvailable);
    }

    public function isCurrent(UrlChangeRequest $change): bool
    {
        if ($this->versions->snapshot(array_keys($change->versions)) !== $change->versions) {
            return false;
        }
        try {
            $sites = $this->sites($change->operation, $change->target_id);
        } catch (HttpExceptionInterface) {
            return false;
        }
        foreach ($sites as &$site) {
            unset($site['next_policy'], $site['label']);
        }
        unset($site);
        $expected = array_map(function (array $site): array {
            unset($site['next_policy'], $site['label']);

            return $site;
        }, $change->sites);

        return $sites === $expected;
    }

    public function checkSegment(UrlChangeRequest $change): void
    {
        if ($change->status !== 'checking') {
            return;
        }
        if (! $this->isCurrent($change)) {
            $this->finish($change, 'stale', __('url_change.errors.stale'));

            return;
        }
        $progress = $change->progress;
        $summary = $change->summary;
        $site = $change->sites[$progress['site']] ?? null;
        if ($site === null) {
            $summary['structured_settings'] = $this->settingsInspector->count($change);
            $change->summary = $summary;
            $change->forceFill(['status' => 'ready', 'expires_at' => now()->addMinutes(15), 'nonce_hash' => hash('sha256', Str::random(64))])->save();

            return;
        }
        $rows = [];
        if ($progress['phase'] === 'categories') {
            $query = Category::query()->where('id', '>', $progress['after'])->orderBy('id')->limit(500);
            if ($site['key'] !== 'primary') {
                $query->whereIn('id', $this->inspector->articles($site)->select('articles.category_id'));
            }
            $categories = $query->get(['id', 'slug']);
            $root = collect(ArticlePermalinkPolicy::fromRaw($site['next_policy'])->patterns())->contains(fn ($pattern) => ArticlePermalinkPattern::compile($pattern)->usesRootCategorySegment());
            foreach ($categories as $category) {
                if ($root && ArticlePermalinkPattern::isReservedFirstSegment($category->slug)) {
                    $this->finish($change, 'failed', __('article_permalink.errors.category_reserved_path', ['slug' => $category->slug, 'path' => mb_strtolower($category->slug)]));

                    return;
                }
                $progress['after'] = (int) $category->id;
            }
            if (! $root || $categories->count() < 500) {
                $progress['after'] = 0;
                $progress['phase'] = 'articles';
            }
            $change->forceFill(['progress' => $progress])->save();

            return;
        }
        if ($progress['phase'] === 'articles') {
            $articles = $this->inspector->targets($change, $site)->where('articles.id', '>', $progress['after'])
                ->with('category:id,slug')->orderBy('articles.id')->limit(500)
                ->get(['articles.id', 'title', 'slug', 'category_id', 'created_at', 'deleted_at', 'status']);
            $public = $this->inspector->articles($site, true)->whereIn('articles.id', $articles->pluck('id'))->pluck('articles.id')->flip();
            $publicBySite = [];
            $policiesBySite = [];
            if ($progress['site'] === 0) {
                foreach ($change->sites as $candidateSite) {
                    $publicBySite[$candidateSite['key']] = $this->inspector->articles($candidateSite, true)->whereIn('articles.id', $articles->pluck('id'))->pluck('articles.id')->flip();
                    $policiesBySite[$candidateSite['key']] = [ArticlePermalinkPolicy::fromRaw($candidateSite['policy']), ArticlePermalinkPolicy::fromRaw($candidateSite['next_policy'])];
                }
            }
            $items = [];
            $currentPolicy = ArticlePermalinkPolicy::fromRaw($site['policy']);
            $nextPolicy = ArticlePermalinkPolicy::fromRaw($site['next_policy']);
            foreach ($articles as $article) {
                try {
                    $old = $this->permalinks->path($article, $currentPolicy);
                    $new = $this->permalinks->path($this->inspector->changedArticle($article, $change), $nextPolicy);
                } catch (\InvalidArgumentException|ValidationException $exception) {
                    $this->finish($change, 'failed', '#'.$article->id.': '.$exception->getMessage());

                    return;
                }
                $isPublic = $public->has($article->id);
                if ($progress['site'] === 0) {
                    $publicSomewhere = collect($publicBySite)->contains(fn ($ids) => $ids->has($article->id));
                    $summary['associated']++;
                    $summary['public_articles'] += $publicSomewhere ? 1 : 0;
                    $summary['trashed'] += $article->trashed() ? 1 : 0;
                    $summary['non_public'] += ! $publicSomewhere && ! $article->trashed() ? 1 : 0;
                    foreach ($change->sites as $candidateSite) {
                        if ($publicBySite[$candidateSite['key']]->has($article->id)
                            && $this->permalinks->path($article, $policiesBySite[$candidateSite['key']][0]) !== $this->permalinks->path($this->inspector->changedArticle($article, $change), $policiesBySite[$candidateSite['key']][1])) {
                            $summary['changed_articles']++;
                            break;
                        }
                    }
                }
                $summary['public_urls'] += $isPublic ? 1 : 0;
                $summary['sites'][$site['key']] ??= ['label' => $site['label'], 'public' => 0, 'changed' => 0];
                $summary['sites'][$site['key']]['public'] += $isPublic ? 1 : 0;
                if ($old !== $new) {
                    $summary['changed_urls'] += $isPublic ? 1 : 0;
                    $summary['potential_urls'] += $isPublic ? 0 : 1;
                    $summary['sites'][$site['key']]['changed'] += $isPublic ? 1 : 0;
                    $row = ['id' => (int) $article->id, 'title' => $article->title, 'site' => $site['key'], 'label' => $site['label'], 'old_url' => $site['base_url'].$old, 'new_url' => $site['base_url'].$new, 'public' => $isPublic];
                    $rows[] = $row;
                    if (count($summary['examples']) < 3) {
                        $summary['examples'][] = $row;
                    }
                }
                $items[] = ['article' => $article, 'slug' => $article->slug];
                $progress['after'] = (int) $article->id;
            }
            if ($progress['site'] === 0 && $articles->isNotEmpty()) {
                $channels = DB::table('article_distributions as d')->join('distribution_channels as c', 'c.id', '=', 'd.distribution_channel_id')->whereIn('d.article_id', $articles->pluck('id'))->where('d.status', 'synced')->where('d.action', '!=', 'delete')->groupBy('c.id', 'c.name', 'c.channel_type')->selectRaw('c.id, c.name, c.channel_type, count(*) as total')->get();
                foreach ($channels as $channel) {
                    $summary['synced_records'] += (int) $channel->total;
                    $summary['channels'][$channel->id] ??= ['name' => $channel->name, 'type' => $channel->channel_type, 'count' => 0];
                    $summary['channels'][$channel->id]['count'] += (int) $channel->total;
                }
            }
            if ($articles->count() < 500) {
                if ($change->operation === 'category') {
                    $summary['category_pages'][$site['key']] = ['label' => $site['label'], 'old_url' => $site['base_url'].'/category/'.rawurlencode($change->old_value), 'new_url' => $site['base_url'].'/category/'.rawurlencode($change->new_value), 'public' => ($summary['sites'][$site['key']]['public'] ?? 0) > 0];
                }
                $progress['phase'] = 'histories';
                $progress['after'] = 0;
            }
        } elseif (in_array($progress['phase'], ['category_histories', 'category_history_slugs'], true)) {
            $usesCategory = collect(ArticlePermalinkPolicy::fromRaw($site['next_policy'])->patterns())->contains(fn ($pattern) => str_contains($pattern, '{category}'));
            $history = $usesCategory ? CategorySlugHistory::query()->where('id', '>', $progress['category_history_id'] ?? 0)
                ->whereIn('category_id', $this->inspector->targets($change, $site)->select('articles.category_id'))->orderBy('id')->first() : null;
            $items = [];
            if ($history === null) {
                $progress['site']++;
                $progress['phase'] = 'articles';
                $progress['after'] = 0;
                $progress['category_history_id'] = 0;
            } else {
                $targets = $this->inspector->targets($change, $site)->where('articles.category_id', $history->category_id);
                if ($progress['phase'] === 'category_histories') {
                    $articles = $targets->where('articles.id', '>', $progress['after'])->orderBy('articles.id')->limit(500)->get(['articles.id', 'slug', 'category_id', 'created_at']);
                    foreach ($articles as $article) {
                        $article->setRelation('category', new Category(['slug' => $history->slug]));
                        $items[] = ['article' => $article, 'slug' => $article->slug];
                        $progress['after'] = (int) $article->id;
                    }
                    if ($articles->count() < 500) {
                        $progress['phase'] = 'category_history_slugs';
                        $progress['after'] = 0;
                    }
                } else {
                    $slugs = ArticleSlugHistory::query()->where('id', '>', $progress['after'])->whereIn('article_id', $targets->select('articles.id'))->orderBy('id')->limit(100)->get(['id', 'article_id', 'slug']);
                    $articles = Article::withTrashed()->whereIn('id', $slugs->pluck('article_id'))->get(['id', 'slug', 'category_id', 'created_at'])->keyBy('id');
                    foreach ($slugs as $slug) {
                        $article = $articles[$slug->article_id];
                        $article->setRelation('category', new Category(['slug' => $history->slug]));
                        $items[] = ['article' => $article, 'slug' => $slug->slug];
                        $progress['after'] = (int) $slug->id;
                    }
                    if ($slugs->count() < 100) {
                        $progress['category_history_id'] = (int) $history->id;
                        $progress['phase'] = 'category_histories';
                        $progress['after'] = 0;
                    }
                }
            }
        } else {
            $items = $this->inspector->historyBatch($change, $site, $progress['after']);
            if ($items !== []) {
                $progress['after'] = end($items)['history_id'];
            }
            if (count($items) < 100) {
                $progress['phase'] = 'category_histories';
                $progress['after'] = 0;
            }
        }
        $conflicts = $this->inspector->conflicts($change, $site, $items);
        if ($conflicts !== []) {
            $this->finish($change, 'failed', implode("\n", $conflicts));

            return;
        }
        if (! $this->isCurrent($change)) {
            $this->finish($change, 'stale', __('url_change.errors.stale'));

            return;
        }
        if ($rows !== []) {
            $progress['bytes'] += $this->reports->write($change, $progress['segments'], $rows);
            $progress['site_segments'][$site['key']]['start'] ??= $progress['segments'];
            $progress['site_segments'][$site['key']]['end'] = $progress['segments'] + 1;
            $rowCount = (int) ($progress['rows'] ?? 0);
            foreach ($rows as $offset => $row) {
                if (($rowCount + $offset) % 100000 === 0) {
                    $progress['export_index'][(int) (($rowCount + $offset) / 100000)] = ['segment' => $progress['segments'], 'offset' => $offset];
                }
            }
            $progress['rows'] = $rowCount + count($rows);
            $progress['segments']++;
        }
        $change->forceFill(['progress' => $progress, 'summary' => $summary])->save();
    }

    public function credential(UrlChangeRequest $change): string
    {
        return Crypt::encryptString(json_encode(['id' => $change->id, 'nonce' => $change->nonce_hash, 'digest' => $this->digest($change)], JSON_THROW_ON_ERROR));
    }

    public function confirm(Admin $admin, UrlChangeRequest $change, string $credential, string $confirmation): UrlChangeRequest
    {
        $this->authorize($admin);
        try {
            $token = json_decode(Crypt::decryptString($credential), true, 16, JSON_THROW_ON_ERROR);
        } catch (Throwable) {
            throw ValidationException::withMessages(['confirmation' => __('url_change.errors.credential')]);
        }
        if ($change->admin_id !== $admin->id || $change->auth_version !== $admin->auth_version
            || ($token['id'] ?? '') !== $change->id || ! hash_equals((string) $change->nonce_hash, (string) ($token['nonce'] ?? ''))
            || ! hash_equals($this->digest($change), (string) ($token['digest'] ?? ''))) {
            throw ValidationException::withMessages(['confirmation' => __('url_change.errors.credential')]);
        }
        $phrase = $this->phrase($change);
        if (trim($confirmation) !== $phrase) {
            throw ValidationException::withMessages(['confirmation' => __('url_change.errors.confirmation', ['phrase' => $phrase])]);
        }
        if ($change->applied_at !== null) {
            return $change;
        }
        if ($change->status !== 'ready' || $change->expires_at?->isPast() !== false) {
            throw ValidationException::withMessages(['confirmation' => __('url_change.errors.expired')]);
        }
        if (! $this->isCurrent($change)) {
            $this->finish($change, 'stale', __('url_change.errors.stale'));
            throw ValidationException::withMessages(['confirmation' => __('url_change.errors.stale')]);
        }

        DB::transaction(function () use ($change, $admin): void {
            $channelIds = array_column($change->sites, 'channel_id');
            DistributionChannel::query()->whereIn('id', $channelIds)->orderBy('id')->lockForUpdate()->get();
            $profiles = HostedSiteProfile::query()->whereIn('distribution_channel_id', $channelIds)->orderBy('id')->lockForUpdate()->get();
            $target = match ($change->operation) {
                'category' => Category::query()->whereKey($change->target_id)->lockForUpdate()->firstOrFail(),
                'article_category' => Article::query()->whereKey($change->target_id)->lockForUpdate()->firstOrFail(),
                default => null,
            };
            if ($target instanceof Category) {
                $this->categories->lockSlugNamespace([(string) $target->slug, $change->new_value]);
            }
            $lockScopes = array_keys($change->versions);
            if ($target instanceof Article) {
                Category::query()->whereKey([$target->category_id, (int) $change->new_value])
                    ->orderBy('id')->lockForUpdate()->get(['id']);
                $lockScopes[] = 'category:'.$target->category_id;
                $lockScopes[] = 'category:'.(int) $change->new_value;
            }
            $this->versions->snapshot($lockScopes, true);
            $locked = UrlChangeRequest::query()->whereKey($change->id)->lockForUpdate()->firstOrFail();
            if ($locked->applied_at !== null) {
                return;
            }
            $freshAdmin = Admin::query()->findOrFail($admin->id);
            $this->authorize($freshAdmin);
            if ($freshAdmin->auth_version !== $locked->auth_version || $locked->status !== 'ready'
                || $locked->expires_at?->isPast() !== false || ! $this->isCurrent($locked)) {
                throw ValidationException::withMessages(['confirmation' => __('url_change.errors.stale')]);
            }
            foreach ($locked->sites as $site) {
                foreach (array_merge([$site['next_policy']['current_pattern']], array_column($site['next_policy']['history'], 'pattern')) as $pattern) {
                    ArticlePermalinkPattern::compile($pattern);
                }
            }
            if ($target !== null) {
                $field = $locked->operation === 'category' ? 'slug' : 'category_id';
                if ((string) $target->{$field} !== $locked->old_value) {
                    throw ValidationException::withMessages(['confirmation' => __('url_change.errors.stale')]);
                }
                if ($locked->operation === 'category') {
                    $this->categories->change($target, $locked->new_value, $admin->id);
                } else {
                    $target->forceFill(['category_id' => (int) $locked->new_value])->save();
                }
            } else {
                $policy = $locked->sites[0]['next_policy'];
                if ($locked->operation === 'primary') {
                    SiteSetting::query()->updateOrCreate(['setting_key' => ArticlePermalinkPolicy::SETTING_KEY], ['setting_value' => json_encode($policy, JSON_THROW_ON_ERROR)]);
                } else {
                    $channel = DistributionChannel::query()->findOrFail($locked->target_id);
                    $settings = $channel->site_settings ?? [];
                    $settings[ArticlePermalinkPolicy::SETTING_KEY] = $policy;
                    $channel->forceFill(['site_settings' => $settings])->save();
                }
            }
            $this->versions->advance(array_keys($locked->versions));
            foreach ($profiles as $profile) {
                $profile->increment('settings_version');
                DB::afterCommit(fn () => app(HostedSiteResolver::class)->invalidate($profile->hostname));
            }
            $locked->forceFill(['status' => 'applied', 'applied_at' => now(), 'progress' => array_merge($locked->progress, ['refresh_site' => 0, 'refresh_after' => 0, 'refresh_versions' => $this->versions->snapshot(array_keys($locked->versions))])])->save();
        }, 3);
        SiteSettingsBag::forget();
        $this->permalinks->forgetPolicy();
        RefreshUrlChange::dispatch($change->id)->afterCommit();

        return $change->refresh();
    }

    public function finish(UrlChangeRequest $change, string $status, ?string $error = null): void
    {
        DB::transaction(function () use ($change, $status, $error): void {
            $this->versions->snapshot(array_keys($change->versions), true);
            $current = UrlChangeRequest::query()->whereKey($change->id)->lockForUpdate()->firstOrFail();
            $allowed = $status === 'completed' ? ['applied', 'refreshing'] : ['checking', 'ready'];
            if (! in_array($current->status, $allowed, true)) {
                return;
            }
            $current->forceFill(['status' => $status, 'error' => $error, 'finished_at' => now()])->save();
            DB::table('url_change_scope_states')->where('active_request_id', $change->id)->update(['active_request_id' => null]);
        }, 3);
    }

    public function phrase(UrlChangeRequest $change): string
    {
        return trans($change->operation === 'category' ? 'url_change.category_phrase' : 'url_change.article_phrase', [], $change->locale);
    }

    private function digest(UrlChangeRequest $change): string
    {
        $summary = $change->summary;
        unset($summary['refresh_skipped']);

        return hash('sha256', json_encode([$change->admin_id, $change->auth_version, $change->operation, $change->target_id, $change->old_value, $change->new_value, $change->versions, $summary, $change->locale, $change->expires_at?->toIso8601String()], JSON_THROW_ON_ERROR));
    }
}
