<?php

namespace App\Services\Topics;

use App\Http\Controllers\Site\ArticleController;
use App\Http\Controllers\Site\CategoryController;
use App\Http\Controllers\Site\HomeController;
use App\Http\Controllers\Site\TopicController;
use App\Models\Admin;
use App\Models\DistributionChannel;
use App\Models\HostedSiteProfile;
use App\Models\SiteSetting;
use App\Models\SiteThemeBinding;
use App\Models\ThemeRelease;
use App\Models\ThemeRevision;
use App\Models\ThemeWorkspace;
use App\Models\Topic;
use App\Services\Api\ManagementInstance;
use App\Services\Api\ThemeRevisionStorage;
use App\Services\Api\ThemeWorkspaceService;
use App\Services\GeoFlow\DistributionChannelOperationLeaseService;
use App\Services\Site\ArticlePermalinkService;
use App\Services\Site\SiteScopedArticleQuery;
use App\Support\Site\CurrentSite;
use App\Support\Site\InstalledSiteThemeRepository;
use App\Support\Site\SiteSettingsBag;
use App\Support\Site\SiteThemePreviewContext;
use App\Support\Site\ThemeRevisionContext;
use Illuminate\Http\Request;
use Illuminate\Session\ArraySessionHandler;
use Illuminate\Session\Store;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Session;
use Illuminate\Support\Facades\View;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/** Trusted core upgrade. Preserve customer templates and all prior immutable revisions. */
final class TopicThemeCompatibility
{
    /** Only untouched core templates qualify for a visual upgrade. */
    private const PREVIOUS_CORE_HASHES = [
        'resources/views/site/topics/show.blade.php' => ['3e659640c93e33a02dbb5ebbff190a170324d8de84e8e263012aa433687990c8', 'a0ca0fe80f04ea444dbd3f52a884ea3353b25d61721aa824e67dd6404e299af3'],
        'resources/views/site/topics/index.blade.php' => 'ab0458fc1cf8917f5965fb85e70d934bb2fdd91421fa6d7182e980baa58eacd6',
        'resources/views/site/partials/topic-source.blade.php' => '9277f5ee01026e02be43936970fba3ebd79b762ed88dd10c737397bcc519aacf',
        'topics.css' => ['eb101ce1e02e16e8b962a1a9568bd2b42ce3be902b5301eede05ecd28206662f', '3e1e5ada9edc44b375c47fd52d9f6d07dcb61991adaf755efe796a5f275f00f9', '118e87d1d08e92882e41150d014cd7fb5ef0513554a25e331e7f398da93f92e9', 'd9202ed90ce3ce251e895aa533a103d30ac56903e99dd0e828aab214641db995', '625f8bca4f3f4628d4e0914d5b8201bab2ee7192636872656467a476e1b07063'],
        'resources/views/site/topics/templates/default.blade.php' => '37bbe91145ccb1bb3702e2615412cca3bd18b05cca5432f2e37efd586725cc8c',
        'resources/views/site/topics/templates/guide.blade.php' => '559a3b5e3c6cc7cf41b1adab6bdfa773f7882ff2a2ce74bc4c0474e03225ca1f',
        'resources/views/site/topics/templates/roundup.blade.php' => '7b0aa7f8aeb2808cf59efd9e4b68d1a042b9120865d3ea7984326b8935e64f05',
        'topics.js' => ['b515ba5303dac48765eee556290aa55b9d6100a5ff95a986b3e7ab7f91123333', 'c8ffcf09b742a5d2057f2c9f87aef730059efdef5b0c4c99dbef0c2c03d2a191', 'b023e26b9e6bbf30e8bb528abfa5de1aa6956c5958fddb670cb09ac9fbc02e89'],
    ];

    public function __construct(private readonly ThemeRevisionStorage $storage, private readonly ThemeWorkspaceService $workspaces) {}

    public function ensure(string $siteKey, Admin $actor): ?ThemeRevision
    {
        $actor = $actor->fresh();
        abort_unless($actor && $actor->status === 'active' && ($siteKey === 'primary' || $actor->canManageProtectedWorkflows()), 403);
        app(TopicService::class)->assertValidSite($siteKey);
        $profile = $siteKey === 'primary' ? null : HostedSiteProfile::query()->with('channel')->findOrFail((int) substr($siteKey, 7));
        $theme = $this->selected($profile);
        if ($theme === '') {
            return null;
        }
        $binding = SiteThemeBinding::query()->find($siteKey);
        $base = $binding && $binding->theme_id === $theme && $binding->revision_id ? ThemeRevision::query()->findOrFail($binding->revision_id) : null;
        $installed = app(InstalledSiteThemeRepository::class)->find($theme);
        if (! $base && ! $installed) {
            return null;
        }
        $contents = $base ? $this->storage->contents($base) : $this->workspaces->sourceContents($theme, 'installed');
        $currentRelease = $base ? ThemeRelease::query()->where('site_key', $siteKey)->where('revision_id', $base->id)->latest('created_at')->first() : null;
        $refreshDesign = $currentRelease?->kind !== 'topic_compatible_rollback' && ($currentRelease?->changes['refresh_design'] ?? true) !== false;
        $upgraded = $this->upgrade($contents, $theme, $refreshDesign);
        if ($contents === $upgraded) {
            return $base;
        }
        [$workspace, $candidate, $proof] = $this->prepareCandidate($siteKey, $theme, $actor, $base ? 'topic_compatibility' : 'installed', $upgraded, $base?->settings ?? ($binding?->settings ?? []), $base?->id);
        $changed = array_keys(array_filter($upgraded, fn ($bytes, $path) => ($contents[$path] ?? null) !== $bytes, ARRAY_FILTER_USE_BOTH));
        $commit = function () use ($profile, $siteKey, $theme, $binding, $base, $candidate, $workspace, $actor, $proof, $changed, $contents, $refreshDesign): void {
            DB::transaction(function () use ($profile, $siteKey, $theme, $binding, $base, $candidate, $workspace, $actor, $proof, $changed, $contents, $refreshDesign): void {
                if ($profile) {
                    $channel = DistributionChannel::query()->whereKey($profile->distribution_channel_id)->lockForUpdate()->firstOrFail();
                    $currentProfile = HostedSiteProfile::query()->lockForUpdate()->findOrFail($profile->id);
                    if ($channel->status === DistributionChannel::STATUS_DELETING || $currentProfile->settings_version !== $profile->settings_version) {
                        $this->conflict();
                    }
                    $currentProfile->setRelation('channel', $channel);
                    if ($this->selected($currentProfile) !== $theme) {
                        $this->conflict();
                    }
                } else {
                    SiteSetting::query()->where('setting_key', 'active_theme')->lockForUpdate()->first();
                    if ($this->selected(null) !== $theme) {
                        $this->conflict();
                    }
                }
                SiteThemeBinding::query()->insertOrIgnore(['site_key' => $siteKey, 'theme_id' => $theme, 'settings' => json_encode($binding?->settings ?? []), 'lock_version' => 0, 'created_at' => now(), 'updated_at' => now()]);
                $current = SiteThemeBinding::query()->whereKey($siteKey)->lockForUpdate()->firstOrFail();
                if ($current->theme_id !== $theme || $current->revision_id !== $base?->id || (int) $current->lock_version !== (int) ($binding?->lock_version ?? 0)) {
                    $this->conflict();
                }
                // Re-read bytes before CAS; a live installation may have changed during rendering.
                if (! $base && $this->workspaces->sourceContents($theme, 'installed') !== $contents) {
                    $this->conflict();
                }
                $current->update(['revision_id' => $candidate->id, 'lock_version' => $current->lock_version + 1]);
                $workspace->update(['revision_id' => $candidate->id, 'state' => 'released', 'plan' => ['kind' => 'topic_compatibility', 'proof' => $proof]]);
                ThemeRelease::query()->create(['id' => (string) Str::uuid(), 'site_key' => $siteKey, 'workspace_id' => $workspace->id, 'revision_id' => $candidate->id, 'previous_revision_id' => $base?->id, 'admin_id' => $actor->id, 'binding_version' => $current->lock_version, 'kind' => 'topic_compatibility', 'changes' => ['paths' => $changed, 'proof' => $proof, 'refresh_design' => $refreshDesign], 'plan_sha256' => hash('sha256', json_encode($proof, JSON_THROW_ON_ERROR))]);
            }, 3);
        };
        try {
            if ($profile) {
                app(DistributionChannelOperationLeaseService::class)->run($profile->channel, 'topic_theme_compatibility', fn () => $commit());
            } else {
                $commit();
            }
        } catch (\Throwable $failure) {
            $workspace->update(['state' => 'failed']);
            throw $failure;
        }
        try {
            $current = SiteThemeBinding::query()->useWritePdo()->find($siteKey);
            if ($current?->revision_id !== $candidate->id || $current->theme_id !== $theme || (int) $current->lock_version !== ((int) ($binding?->lock_version ?? 0) + 1)) {
                $this->conflict();
            }
            $readback = $this->renderProof($candidate, $siteKey, true);
            ThemeWorkspace::query()->whereKey($workspace->id)->update(['plan' => ['kind' => 'topic_compatibility', 'proof' => $proof, 'readback' => $readback]]);
        } catch (\Throwable $failure) {
            $restored = DB::transaction(function () use ($siteKey, $candidate, $binding, $base, $workspace): bool {
                $current = SiteThemeBinding::query()->whereKey($siteKey)->lockForUpdate()->first();
                if ($current?->revision_id !== $candidate->id || (int) $current->lock_version !== ((int) ($binding?->lock_version ?? 0) + 1)) {
                    return false;
                }
                $current->update(['revision_id' => $base?->id, 'lock_version' => $current->lock_version + 1]);
                $workspace->update(['state' => 'failed', 'plan' => array_replace($workspace->fresh()->plan ?? [], ['compensation' => ['restored_revision_id' => $base?->id, 'at' => now()->toIso8601String()]])]);

                return true;
            }, 3);
            report($failure);
            throw ValidationException::withMessages(['template_key' => $restored ? '专题模板回读失败，已恢复原模板；工作草稿已保留，可以重试发布或更换模板。' : '专题模板回读失败，模板又有新的变更；工作草稿已保留，请重新打开模板设置核对当前版本。']);
        }
        app(ThemeRevisionContext::class)->reset();
        SiteSettingsBag::forget();

        return $candidate;
    }

    /** Latest compatibility change can return to the previous design with topic support retained. */
    public function rollbackState(string $siteKey): ?array
    {
        $binding = SiteThemeBinding::query()->useWritePdo()->find($siteKey);
        if (! $binding) {
            return null;
        }
        $release = ThemeRelease::query()->where('site_key', $siteKey)->where('revision_id', $binding->revision_id)->where('kind', 'topic_compatibility')->latest('created_at')->first();
        if (! $release || (int) $release->binding_version !== (int) $binding->lock_version) {
            return null;
        }

        return ['release_id' => $release->id, 'binding_version' => (int) $binding->lock_version, 'previous_revision_id' => $release->previous_revision_id];
    }

    public function rollback(string $siteKey, Admin $actor, int $expectedVersion): ThemeRevision
    {
        $actor = $actor->fresh();
        abort_unless($actor && $actor->status === 'active' && $actor->canManageProtectedWorkflows(), 403);
        app(TopicService::class)->assertValidSite($siteKey);
        $state = $this->rollbackState($siteKey);
        if (! $state || $state['binding_version'] !== $expectedVersion) {
            $this->conflict();
        }
        $binding = SiteThemeBinding::query()->useWritePdo()->findOrFail($siteKey);
        $current = ThemeRevision::query()->findOrFail($binding->revision_id);
        $previous = $state['previous_revision_id'] ? ThemeRevision::query()->findOrFail($state['previous_revision_id']) : null;
        $contents = $previous ? $this->storage->contents($previous) : $this->workspaces->sourceContents($binding->theme_id, 'installed');
        $contents = $this->upgrade($contents, $binding->theme_id, false);
        [$workspace, $candidate, $proof] = $this->prepareCandidate($siteKey, $binding->theme_id, $actor, 'topic_compatible_rollback', $contents, $previous?->settings ?? $binding->settings, $previous?->id);
        $profile = $siteKey === 'primary' ? null : HostedSiteProfile::query()->with('channel')->findOrFail((int) substr($siteKey, 7));
        $commit = function () use ($siteKey, $binding, $expectedVersion, $profile, $candidate, $workspace, $actor, $proof, $current): void {
            DB::transaction(function () use ($siteKey, $binding, $expectedVersion, $profile, $candidate, $workspace, $actor, $proof, $current): void {
                if ($profile) {
                    $channel = DistributionChannel::query()->whereKey($profile->distribution_channel_id)->lockForUpdate()->firstOrFail();
                    $lockedProfile = HostedSiteProfile::query()->lockForUpdate()->findOrFail($profile->id);
                    $lockedProfile->setRelation('channel', $channel);
                    if ($channel->status === DistributionChannel::STATUS_DELETING || $lockedProfile->settings_version !== $profile->settings_version || $this->selected($lockedProfile) !== $binding->theme_id) {
                        $this->conflict();
                    }
                } else {
                    SiteSetting::query()->where('setting_key', 'active_theme')->lockForUpdate()->first();
                    if ($this->selected(null) !== $binding->theme_id) {
                        $this->conflict();
                    }
                }
                $locked = SiteThemeBinding::query()->whereKey($siteKey)->lockForUpdate()->firstOrFail();
                if ($locked->revision_id !== $binding->revision_id || (int) $locked->lock_version !== $expectedVersion || $locked->theme_id !== $binding->theme_id) {
                    $this->conflict();
                }
                $locked->update(['revision_id' => $candidate->id, 'settings' => $candidate->settings, 'lock_version' => $expectedVersion + 1]);
                $workspace->update(['revision_id' => $candidate->id, 'state' => 'released', 'plan' => ['kind' => 'topic_compatible_rollback', 'proof' => $proof]]);
                ThemeRelease::query()->create(['id' => (string) Str::uuid(), 'site_key' => $siteKey, 'workspace_id' => $workspace->id, 'revision_id' => $candidate->id, 'previous_revision_id' => $current->id, 'admin_id' => $actor->id, 'binding_version' => $expectedVersion + 1, 'kind' => 'topic_compatible_rollback', 'changes' => ['proof' => $proof], 'plan_sha256' => hash('sha256', json_encode($proof, JSON_THROW_ON_ERROR))]);
            }, 3);
        };
        try {
            if ($profile) {
                app(DistributionChannelOperationLeaseService::class)->run($profile->channel, 'topic_compatible_rollback', fn () => $commit());
            } else {
                $commit();
            }
        } catch (\Throwable $failure) {
            $workspace->update(['state' => 'failed']);
            throw $failure;
        }
        try {
            $readback = SiteThemeBinding::query()->useWritePdo()->findOrFail($siteKey);
            if ($readback->revision_id !== $candidate->id || (int) $readback->lock_version !== $expectedVersion + 1) {
                $this->conflict();
            }
            $proof = $this->renderProof($candidate, $siteKey, true);
            $workspace->update(['plan' => array_replace($workspace->fresh()->plan ?? [], ['readback' => $proof])]);
        } catch (\Throwable $failure) {
            $restored = DB::transaction(function () use ($siteKey, $candidate, $binding, $expectedVersion, $workspace): bool {
                $locked = SiteThemeBinding::query()->whereKey($siteKey)->lockForUpdate()->firstOrFail();
                if ($locked->revision_id !== $candidate->id || (int) $locked->lock_version !== $expectedVersion + 1) {
                    return false;
                }
                $locked->update(['revision_id' => $binding->revision_id, 'settings' => $binding->settings, 'lock_version' => $expectedVersion + 2]);
                ThemeRelease::query()->where('site_key', $siteKey)->where('revision_id', $binding->revision_id)->where('kind', 'topic_compatibility')->where('binding_version', $expectedVersion)->update(['binding_version' => $expectedVersion + 2]);
                $workspace->update(['state' => 'failed']);

                return true;
            }, 3);
            report($failure);
            throw ValidationException::withMessages(['template_key' => $restored ? '模板回退回读失败，已恢复操作前的兼容模板。' : '模板又有新的变更，请重新核对当前模板。']);
        }
        app(ThemeRevisionContext::class)->reset();
        SiteSettingsBag::forget();

        return $candidate;
    }

    /** @return array{ThemeWorkspace, ThemeRevision, array} */
    private function prepareCandidate(string $siteKey, string $theme, Admin $actor, string $source, array $contents, array $settings, ?string $parentId): array
    {
        if (! app(TopicSiteSettings::class)->get($siteKey)['enabled']) {
            throw ValidationException::withMessages(['template_key' => '专题频道已关闭，请在专题设置中开启后再适配或回退模板。']);
        }
        $workspace = ThemeWorkspace::query()->create([
            'id' => (string) Str::uuid(), 'instance_id' => app(ManagementInstance::class)->id(), 'admin_id' => $actor->id,
            'site_key' => $siteKey, 'theme_id' => $theme, 'source' => $source, 'state' => 'draft',
        ]);
        try {
            $candidate = $this->storage->create($workspace->id, $theme, $contents, $settings, $parentId);
            $workspace->update(['revision_id' => $candidate->id]);
            $proof = $this->renderProof($candidate, $siteKey);
        } catch (\Throwable $failure) {
            $workspace->update(['state' => 'failed']);
            throw $failure;
        }

        return [$workspace, $candidate, $proof];
    }

    /** @param array<string,string> $contents @return array<string,string> */
    private function upgrade(array $contents, string $theme, bool $refreshDesign = true): array
    {
        $refreshDesign = $refreshDesign && $this->canRefreshDesign($contents, $theme);
        foreach (['topics/index', 'topics/show', 'topics/templates/default', 'topics/templates/guide', 'topics/templates/roundup', 'partials/topic-source', 'partials/topic-assets', 'partials/topic-home', 'partials/related-topics', 'partials/topic-navigation'] as $view) {
            $path = 'resources/views/site/'.$view.'.blade.php';
            if (! isset($contents[$path]) || ($refreshDesign && $this->isPreviousCoreFile($contents[$path], $path))) {
                $contents[$path] = file_get_contents(base_path($path));
            }
        }
        foreach (['css', 'js'] as $ext) {
            $path = 'public/themes/'.$theme.'/topics.'.$ext;
            if (! isset($contents[$path]) || ($refreshDesign && $this->isPreviousCoreFile($contents[$path], 'topics.'.$ext))) {
                $contents[$path] = file_get_contents(public_path('assets/'.$ext.'/topics.'.$ext));
            }
        }
        foreach ($contents as $path => $bytes) {
            if (! str_ends_with($path, '.blade.php')) {
                continue;
            }
            if (str_ends_with($path, '/partials/seo-head.blade.php') && ! str_contains($bytes, 'site.partials.topic-assets')) {
                $bytes = "@include('site.partials.topic-assets')\n".$bytes."\n@if(!empty(\$topicStructuredData))<x-json-ld :data=\"\$topicStructuredData\" />@endif\n";
                $bytes = str_replace('!empty($search)', '(!empty($search) || !empty($pageNoindex))', $bytes);
            }
            if (str_ends_with($path, '/home.blade.php') && ! str_contains($bytes, 'site.partials.homepage-modules') && ! str_contains($bytes, 'site.partials.topic-home')) {
                $bytes = $this->insertContent($bytes, 'topic-home');
            }
            if (str_ends_with($path, '/partials/homepage-modules.blade.php') && ! str_contains($bytes, 'site.partials.topic-home')) {
                $bytes = "@include('site.partials.topic-home')\n".$bytes;
            }
            if (str_ends_with($path, '/article.blade.php') && ! str_contains($bytes, 'site.partials.related-topics')) {
                $bytes = $this->insertContent($bytes, 'related-topics');
            }
            if (str_ends_with($path, '/partials/header.blade.php') && ! str_contains($bytes, 'site.partials.topic-navigation')) {
                $bytes = preg_replace('~</nav>~', "@include('site.partials.topic-navigation')\n</nav>", $bytes) ?? $bytes;
            }
            $contents[$path] = $bytes;
        }

        return $contents;
    }

    /** Existing customer-owned topic files keep their matching design bundle. */
    private function canRefreshDesign(array $contents, string $theme): bool
    {
        foreach (array_keys(self::PREVIOUS_CORE_HASHES) as $key) {
            $asset = str_starts_with($key, 'topics.');
            $path = $asset ? 'public/themes/'.$theme.'/'.$key : $key;
            if (! isset($contents[$path])) {
                continue;
            }
            $corePath = $asset ? public_path('assets/'.pathinfo($key, PATHINFO_EXTENSION).'/'.$key) : base_path($key);
            if ($contents[$path] !== file_get_contents($corePath) && ! $this->isPreviousCoreFile($contents[$path], $key)) {
                return false;
            }
        }

        return true;
    }

    private function isPreviousCoreFile(string $bytes, string $path): bool
    {
        $fingerprint = self::PREVIOUS_CORE_HASHES[$path] ?? null;

        foreach ((array) $fingerprint as $knownHash) {
            if (hash_equals($knownHash, hash('sha256', $bytes))) {
                return true;
            }
        }

        return false;
    }

    private function insertContent(string $bytes, string $partial): string
    {
        $include = "\n@include('site.partials.".$partial."')\n";
        if (preg_match("~@section\\(['\"](?:content|theme_content)['\"]\\)~", $bytes, $match, PREG_OFFSET_CAPTURE)) {
            $start = $match[0][1] + strlen($match[0][0]);
            $end = strpos($bytes, '@endsection', $start);
            if ($end !== false) {
                return substr_replace($bytes, $include, $end, 0);
            }
        }
        if (str_contains($bytes, '</main>')) {
            return str_replace('</main>', $include.'</main>', $bytes);
        }
        throw ValidationException::withMessages(['template_key' => '当前模板需要指定专题模块的位置，草稿已保留，请在模板设置中完成适配。']);
    }

    private function renderProof(ThemeRevision $revision, string $siteKey, bool $serving = false): array
    {
        $prior = app(CurrentSite::class);
        $site = new CurrentSite;
        $priorSession = Session::getFacadeRoot();
        $session = new Store('topic_theme_check', new ArraySessionHandler(15));
        $session->start();
        Session::swap($session);
        $finder = View::getFinder();
        $siteKey === 'primary' ? $site->setPrimary((string) parse_url(config('app.url'), PHP_URL_HOST)) : $site->setHosted(HostedSiteProfile::query()->with('channel')->findOrFail((int) substr($siteKey, 7)));
        app()->instance(CurrentSite::class, $site);
        SiteSettingsBag::forget();
        app(TopicReadModel::class)->reset();
        try {
            $render = function () use ($revision, $site): array {
                foreach ($this->storage->contents($revision) as $path => $bytes) {
                    if (str_ends_with($path, '.blade.php')) {
                        Blade::compileString($bytes);
                    }
                }
                $request = Request::create($site->baseUrl().'/topics');
                $request->setRouteResolver(fn () => Route::getRoutes()->match($request));
                $request->setLaravelSession(Session::getFacadeRoot());
                $proof = ['revision_id' => $revision->id, 'content_sha256' => $revision->content_sha256];
                $proof['list'] = hash('sha256', app(TopicController::class)->index($request)->getContent());
                $empty = Request::create($site->baseUrl().'/topics?search='.rawurlencode('模板空列表检查 '.Str::uuid()));
                $empty->setRouteResolver(fn () => Route::getRoutes()->match($empty));
                $empty->setLaravelSession(Session::getFacadeRoot());
                $proof['empty'] = hash('sha256', app(TopicController::class)->index($empty)->getContent());
                $home = Request::create($site->baseUrl());
                $home->setRouteResolver(fn () => Route::getRoutes()->match($home));
                $home->setLaravelSession(Session::getFacadeRoot());
                $proof['home'] = hash('sha256', app(HomeController::class)->index($home)->render());
                $key = $site->isHosted() ? 'hosted:'.$site->profileId() : 'primary';
                $topic = Topic::query()->where('site_key', $key)->latest('id')->first();
                if ($topic) {
                    $proof['detail'] = hash('sha256', app(TopicController::class)->previewMarkup(app(TopicService::class)->previewView($topic)));
                }
                $article = app(SiteScopedArticleQuery::class)->queryForSiteKey($key)->first();
                if ($article) {
                    if ($article->category) {
                        $categoryRequest = Request::create($site->baseUrl().'/category/'.$article->category->slug);
                        $categoryRequest->setRouteResolver(fn () => Route::getRoutes()->match($categoryRequest));
                        $categoryRequest->setLaravelSession(Session::getFacadeRoot());
                        $categoryResponse = app(CategoryController::class)->show($categoryRequest, $article->category->slug);
                        $proof['category'] = hash('sha256', $categoryResponse instanceof \Illuminate\View\View ? $categoryResponse->render() : $categoryResponse->getContent());
                    }
                    $path = app(ArticlePermalinkService::class)->path($article);
                    $articleRequest = Request::create($site->baseUrl().$path);
                    $articleRequest->setRouteResolver(fn () => Route::getRoutes()->match($articleRequest));
                    $articleRequest->setLaravelSession(Session::getFacadeRoot());
                    $response = app(ArticleController::class)->show($articleRequest);
                    $proof['article'] = hash('sha256', $response instanceof \Illuminate\View\View ? $response->render() : $response->getContent());
                }

                return $proof;
            };
            if ($serving) {
                app(ThemeRevisionContext::class)->reset();

                return app(SiteThemePreviewContext::class)->run($revision->theme_id, $site->baseUrl(), $render);
            }

            return app(ThemeRevisionContext::class)->preview($revision, fn () => app(SiteThemePreviewContext::class)->run($revision->theme_id, $site->baseUrl(), $render));
        } finally {
            View::setFinder($finder);
            $finder->flush();
            app(ThemeRevisionContext::class)->reset();
            Session::swap($priorSession);
            app()->instance(CurrentSite::class, $prior);
            SiteSettingsBag::forget();
            app(TopicReadModel::class)->reset();
        }
    }

    private function selected(?HostedSiteProfile $profile): string
    {
        return (string) ($profile ? ($profile->channel?->site_settings['theme_id'] ?? $profile->channel?->template_key ?? '') : (SiteSetting::query()->where('setting_key', 'active_theme')->value('setting_value') ?? config('geoflow.default_theme', '')));
    }

    private function conflict(): never
    {
        throw ValidationException::withMessages(['template_key' => '模板或站点设置已更新，草稿已保留，请重新发布。']);
    }
}
