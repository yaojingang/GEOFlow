<?php

namespace App\Services\Api;

use App\Exceptions\ApiException;
use App\Exceptions\DistributionChannelDeletionBlocked;
use App\Models\Admin;
use App\Models\DistributionChannel;
use App\Models\HostedSiteProfile;
use App\Models\SiteSetting;
use App\Models\SiteThemeBinding;
use App\Models\ThemeRelease;
use App\Models\ThemeRevision;
use App\Models\ThemeWorkspace;
use App\Services\GeoFlow\DistributionChannelOperationLeaseService;
use App\Services\Site\LinkPoolSiteService;
use App\Support\Site\InstalledSiteThemeRepository;
use App\Support\Site\SiteSettingsBag;
use App\Support\Site\SiteThemeCatalog;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/** Atomic binding, receipt and field-level rollback for a validated link-pool theme revision. */
final class LinkPoolThemeBindingRelease
{
    public function __construct(
        private readonly ThemeRevisionStorage $revisions,
        private readonly DistributionChannelOperationLeaseService $leases,
    ) {}

    /**
     * The caller must supply the successful render proof for this exact revision. No public route calls this service.
     *
     * @param  array{site_key:string,revision_id:string,content_sha256:string,base_sha256:string,binding_version:int,settings_revision:int,article_html_sha256:string,empty_html_sha256:string,validated_at:string}  $proof
     */
    public function publish(ThemeRevision $candidate, Admin $admin, array $proof): ThemeRelease
    {
        $this->assertOuterTransactionIsClosed();
        $candidate = ThemeRevision::query()->findOrFail($candidate->id);
        $workspace = ThemeWorkspace::query()->findOrFail($candidate->workspace_id);
        $base = ThemeRevision::query()->findOrFail($candidate->parent_id);
        $this->assertActor($admin, $workspace);
        $this->assertProof($candidate, $base, $workspace, $proof);
        $this->assertNarrowPatch($base, $candidate);
        $this->assertCoreUnchanged($candidate);
        $this->assertEmergencyIsOff();

        try {
            if ($workspace->site_key === 'primary') {
                $release = $this->publishPrimary($workspace, $base, $candidate, $admin, $proof);
            } else {
                $release = $this->withHostedLease($workspace->site_key, function (DistributionChannel $channel, HostedSiteProfile $profile) use ($workspace, $base, $candidate, $admin, $proof): ThemeRelease {
                    return $this->publishHosted($channel, $profile, $workspace, $base, $candidate, $admin, $proof);
                });
            }
        } catch (DistributionChannelDeletionBlocked) {
            throw new ApiException('site_deleting', '托管站渠道正在删除，主题版本未切换', 409);
        }

        try {
            $this->assertReadback($release);
        } catch (\Throwable $failure) {
            try {
                $this->rollback($release, $admin);
            } catch (\Throwable $rollbackFailure) {
                try {
                    $this->suspendFailedRevision($release);
                } catch (\Throwable $suspendFailure) {
                    report($suspendFailure);
                }
                Log::critical('Link pool theme release readback and compensation failed', [
                    'release_id' => $release->id,
                    'site_key' => $release->site_key,
                    'readback_error' => $failure::class,
                    'rollback_error' => $rollbackFailure::class,
                ]);
                throw new ApiException('theme_release_compensation_failed', '主题回读与自动撤回均失败，请人工核对站点状态', 503);
            }

            if ($failure instanceof ApiException) {
                throw $failure;
            }

            throw new ApiException('theme_release_readback_failed', '主题发布回读失败，已自动撤回', 503);
        }

        return $release;
    }

    public function rollback(ThemeRelease $release, Admin $admin): ThemeRelease
    {
        $this->assertOuterTransactionIsClosed();
        $release = ThemeRelease::query()->findOrFail($release->id);
        $this->assertRollbackActor($admin, $release);
        $base = ThemeRevision::query()->findOrFail($release->previous_revision_id);
        $this->revisions->contents($base);

        try {
            if ($release->site_key === 'primary') {
                $rollback = $this->rollbackPrimary($release, $admin);
            } else {
                $rollback = $this->withHostedLease($release->site_key, function (DistributionChannel $channel, HostedSiteProfile $profile) use ($release, $admin): ThemeRelease {
                    return $this->rollbackHosted($channel, $profile, $release, $admin);
                });
            }
        } catch (DistributionChannelDeletionBlocked) {
            throw new ApiException('site_deleting', '托管站渠道正在删除，无法撤回主题版本', 409);
        }

        return $rollback;
    }

    /** Stop only the failed revision; preserve settings and versions created by later work. */
    public function suspendFailedRevision(ThemeRelease $release): void
    {
        if ($release->site_key === 'primary') {
            DB::transaction(function () use ($release): void {
                $binding = SiteThemeBinding::query()->whereKey('primary')->lockForUpdate()->first();
                if ($binding?->revision_id !== $release->revision_id) {
                    return;
                }
                $setting = SiteSetting::query()->where('setting_key', 'article_recommendations')->lockForUpdate()->first();
                $settings = json_decode((string) $setting?->setting_value, true) ?: [];
                if ($setting && ($settings['enabled'] ?? false) === true) {
                    $settings['enabled'] = false;
                    $settings['revision'] = (int) ($settings['revision'] ?? 0) + 1;
                    $setting->update(['setting_value' => json_encode($settings, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE)]);
                    DB::afterCommit(static fn () => SiteSettingsBag::forget());
                }
            }, 3);

            return;
        }
        // Deleting channels already suppress public output and reject new operation leases.
        $profile = HostedSiteProfile::query()->with('channel')->find((int) substr($release->site_key, 7));
        if (! $profile?->channel || $profile->channel->status === DistributionChannel::STATUS_DELETING) {
            return;
        }
        $this->withHostedLease($release->site_key, function (DistributionChannel $channel, HostedSiteProfile $profile) use ($release): void {
            DB::transaction(function () use ($channel, $profile, $release): void {
                $channel = DistributionChannel::query()->whereKey($channel->id)->lockForUpdate()->firstOrFail();
                $profile = HostedSiteProfile::query()->whereKey($profile->id)->lockForUpdate()->firstOrFail();
                $binding = SiteThemeBinding::query()->whereKey($release->site_key)->lockForUpdate()->first();
                if ($channel->status === DistributionChannel::STATUS_DELETING || $binding?->revision_id !== $release->revision_id) {
                    return;
                }
                $settings = $channel->site_settings ?? [];
                if (($settings['article_recommendations']['enabled'] ?? false) === true) {
                    $settings['article_recommendations']['enabled'] = false;
                    $channel->update(['site_settings' => $settings]);
                    $profile->increment('settings_version');
                }
            }, 3);
        });
    }

    private function assertActor(Admin $admin, ThemeWorkspace $workspace): void
    {
        $current = $admin->fresh();
        if (! $current || $current->status !== 'active' || ! $current->canManageProtectedWorkflows()
            || (int) $workspace->admin_id !== (int) $current->id || $workspace->state !== 'draft') {
            throw new ApiException('forbidden', '当前账号无权发布此主题适配版本', 403);
        }
    }

    private function assertRollbackActor(Admin $admin, ThemeRelease $release): void
    {
        $current = $admin->fresh();
        if (! $current || $current->status !== 'active' || ! $current->canManageProtectedWorkflows()
            || $release->kind !== 'link_pool_adaptation') {
            throw new ApiException('forbidden', '当前账号或发布记录不允许撤回', 403);
        }
    }

    private function assertOuterTransactionIsClosed(): void
    {
        if (DB::transactionLevel() !== 0) {
            throw new ApiException('theme_release_transaction_conflict', '主题发布须由发布服务独立提交后回读', 409);
        }
    }

    private function assertProof(ThemeRevision $candidate, ThemeRevision $base, ThemeWorkspace $workspace, array $proof): void
    {
        $activeBase = SiteThemeBinding::query()->whereKey($workspace->site_key)->value('revision_id');
        if ($workspace->revision_id !== $candidate->id || $candidate->parent_id !== $base->id
            || ($base->workspace_id !== $workspace->id && $activeBase !== $base->id) || $candidate->theme_id !== $workspace->theme_id
            || $base->theme_id !== $workspace->theme_id || $candidate->state !== 'ready' || $base->state !== 'ready'
            || ($proof['site_key'] ?? null) !== $workspace->site_key
            || ($proof['revision_id'] ?? null) !== $candidate->id
            || ($proof['content_sha256'] ?? null) !== $candidate->content_sha256
            || ($proof['base_sha256'] ?? null) !== $base->content_sha256) {
            throw new ApiException('theme_adaptation_plan_conflict', '主题适配计划与版本不一致，请重新验证', 409);
        }
        $plan = $workspace->plan ?? [];
        $proofHash = hash('sha256', json_encode($proof, JSON_THROW_ON_ERROR));
        if (($plan['kind'] ?? null) !== 'link_pool_adaptation'
            || ($plan['revision_id'] ?? null) !== $candidate->id
            || ! hash_equals((string) ($plan['proof_sha256'] ?? ''), $proofHash)) {
            throw new ApiException('theme_adaptation_validation_required', '适配验证计划尚未生成或已变更', 409);
        }
        $validatedAt = isset($proof['validated_at']) ? strtotime((string) $proof['validated_at']) : false;
        if (! is_int($validatedAt) || $validatedAt > time() || $validatedAt < time() - 600
            || ! is_int($proof['binding_version'] ?? null) || ! is_int($proof['settings_revision'] ?? null)
            || ! preg_match('/^[a-f0-9]{64}$/D', (string) ($proof['article_html_sha256'] ?? ''))
            || ! preg_match('/^[a-f0-9]{64}$/D', (string) ($proof['empty_html_sha256'] ?? ''))) {
            throw new ApiException('theme_adaptation_validation_required', '文章页验证记录缺失或已过期', 409);
        }
    }

    private function assertNarrowPatch(ThemeRevision $base, ThemeRevision $candidate): void
    {
        $before = $this->revisions->contents($base);
        $after = $this->revisions->contents($candidate);
        if (array_keys($before) !== array_keys($after)) {
            throw new ApiException('theme_adaptation_patch_unsafe', '主题适配超出了允许的模板文件范围', 422);
        }
        $changed = array_keys(array_filter($after, static fn (string $bytes, string $path): bool => $bytes !== $before[$path], ARRAY_FILTER_USE_BOTH));
        $article = 'resources/views/theme/'.$candidate->theme_id.'/article.blade.php';
        if (! isset($before[$article])) {
            $article = 'resources/views/site/article.blade.php';
        }
        $include = "\n    @include('site.partials.link-pool')";
        $singleInsertion = false;
        $patched = $after[$article] ?? '';
        for ($offset = strpos($patched, $include); $offset !== false; $offset = strpos($patched, $include, $offset + strlen($include))) {
            if (substr_replace($patched, '', $offset, strlen($include)) === ($before[$article] ?? null)) {
                $singleInsertion = true;
                break;
            }
        }
        if (! isset($before[$article]) || LinkPoolSiteService::templateSupportsRecommendations($before[$article])
            || $changed !== [$article] || substr_count($after[$article], $include) !== substr_count($before[$article], $include) + 1
            || ! $singleInsertion) {
            throw new ApiException('theme_adaptation_patch_unsafe', '主题适配仅允许插入统一推荐模板', 422);
        }
    }

    private function assertCoreUnchanged(ThemeRevision $candidate): void
    {
        if (app()->isDownForMaintenance()
            || ! hash_equals((string) ($candidate->dependencies['core_build'] ?? ''), (string) ($this->revisions->dependencies()['core_build'] ?? ''))) {
            throw new ApiException('theme_upgrade_conflict', '应用代码或主题依赖已更新，请重新生成适配版本', 409);
        }
    }

    private function assertEmergencyIsOff(): void
    {
        if (SiteSetting::query()->where('setting_key', 'link_pool_emergency_disabled')->value('setting_value') === '1') {
            throw new ApiException('link_pool_emergency_disabled', '全部站点推荐已紧急停用，主题版本未切换', 409);
        }
    }

    private function assertSourceUnchanged(ThemeWorkspace $workspace, ThemeRevision $base): void
    {
        $contents = $this->revisions->contents($base);
        $themePrefix = 'resources/views/theme/'.$workspace->theme_id.'/';
        $assetPrefix = 'public/themes/'.$workspace->theme_id.'/';
        $sourcePaths = [];
        if ($workspace->source === 'installed') {
            $installed = app(InstalledSiteThemeRepository::class)->find($workspace->theme_id);
            if ($installed === null) {
                throw new ApiException('theme_source_changed', '原主题安装包已不可用', 409);
            }
            $sourcePaths = array_column($installed['package']['files'] ?? [], 'path');
        } elseif ($workspace->source === 'builtin') {
            foreach ([$themePrefix, $assetPrefix] as $prefix) {
                $directory = base_path(rtrim($prefix, '/'));
                if (! is_dir($directory)) {
                    continue;
                }
                foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS)) as $file) {
                    if ($file->isFile()) {
                        $sourcePaths[] = substr($file->getPathname(), strlen(base_path()) + 1);
                    }
                }
            }
        } else {
            throw new ApiException('theme_source_changed', '无法核验原主题来源', 409);
        }
        $snapshotPaths = array_values(array_filter(array_keys($contents), static fn (string $path): bool => str_starts_with($path, $themePrefix) || str_starts_with($path, $assetPrefix)));
        sort($snapshotPaths);
        sort($sourcePaths);
        if ($snapshotPaths !== $sourcePaths) {
            throw new ApiException('theme_source_changed', '原主题文件清单已变化，请重新适配', 409);
        }
        foreach ($contents as $path => $bytes) {
            $absolute = $workspace->source === 'installed' && ! str_starts_with($path, 'resources/views/site/')
                ? $this->revisions->storage->path('installed/'.$workspace->theme_id.'/'.$path)
                : base_path($path);
            if (! is_file($absolute) || ! hash_equals(hash('sha256', $bytes), (string) hash_file('sha256', $absolute))) {
                throw new ApiException('theme_source_changed', '原主题文件已变化，请重新适配', 409);
            }
        }
    }

    private function publishPrimary(ThemeWorkspace $workspace, ThemeRevision $base, ThemeRevision $candidate, Admin $admin, array $proof): ThemeRelease
    {
        return DB::transaction(function () use ($workspace, $base, $candidate, $admin, $proof): ThemeRelease {
            $lockedWorkspace = ThemeWorkspace::query()->whereKey($workspace->id)->lockForUpdate()->firstOrFail();
            $this->assertActor($admin, $lockedWorkspace);
            $this->assertProof($candidate, $base, $lockedWorkspace, $proof);
            SiteThemeBinding::query()->insertOrIgnore([
                'site_key' => 'primary', 'theme_id' => $candidate->theme_id, 'settings' => '{}', 'lock_version' => 0,
                'created_at' => now(), 'updated_at' => now(),
            ]);
            $binding = SiteThemeBinding::query()->whereKey('primary')->lockForUpdate()->firstOrFail();
            $this->assertBinding($binding, $candidate, $base, $proof);
            $this->assertCoreUnchanged($candidate);
            $this->assertEmergencyIsOff();
            if ($binding->revision_id === null) {
                $this->assertSourceUnchanged($workspace, $base);
            }
            $selected = SiteSetting::query()->where('setting_key', 'active_theme')->value('setting_value') ?? config('geoflow.default_theme');
            if ($selected !== $candidate->theme_id) {
                throw new ApiException('theme_changed', '站点主题已更新，请重新适配', 409);
            }
            SiteSetting::query()->insertOrIgnore(['setting_key' => 'article_recommendations', 'setting_value' => '{}', 'created_at' => now(), 'updated_at' => now()]);
            $setting = SiteSetting::query()->where('setting_key', 'article_recommendations')->lockForUpdate()->firstOrFail();
            $current = json_decode((string) $setting->setting_value, true) ?: [];
            $this->assertSettingsVersion((int) ($current['revision'] ?? 0), $proof);
            $beforeEnabled = ($current['enabled'] ?? false) === true;
            $current['enabled'] = true;
            $current['revision'] = (int) $proof['settings_revision'] + 1;
            $setting->setting_value = json_encode($current, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
            $setting->save();
            $binding->revision_id = $candidate->id;
            $binding->lock_version++;
            $binding->save();

            $release = $this->receipt($lockedWorkspace, $base, $candidate, $admin, $binding, $proof, $beforeEnabled);
            $lockedWorkspace->update(['state' => 'released']);
            DB::afterCommit(static fn () => SiteSettingsBag::forget());

            return $release;
        }, 3);
    }

    private function publishHosted(DistributionChannel $channel, HostedSiteProfile $profile, ThemeWorkspace $workspace, ThemeRevision $base, ThemeRevision $candidate, Admin $admin, array $proof): ThemeRelease
    {
        return DB::transaction(function () use ($channel, $profile, $workspace, $base, $candidate, $admin, $proof): ThemeRelease {
            $lockedChannel = DistributionChannel::query()->whereKey($channel->id)->lockForUpdate()->firstOrFail();
            $lockedProfile = HostedSiteProfile::query()->whereKey($profile->id)->lockForUpdate()->firstOrFail();
            $lockedWorkspace = ThemeWorkspace::query()->whereKey($workspace->id)->lockForUpdate()->firstOrFail();
            $this->assertActor($admin, $lockedWorkspace);
            $this->assertProof($candidate, $base, $lockedWorkspace, $proof);
            if ($lockedChannel->status === DistributionChannel::STATUS_DELETING
                || $lockedProfile->serving_status !== HostedSiteProfile::SERVING_ONLINE
                || $lockedProfile->indexing_status !== HostedSiteProfile::INDEXING_INDEX) {
                throw new ApiException('site_unavailable', '托管站当前无法公开投放', 409);
            }
            $settings = $lockedChannel->site_settings ?? [];
            $selected = (string) ($settings['theme_id'] ?? $lockedChannel->template_key ?? '');
            if ($selected !== $candidate->theme_id) {
                throw new ApiException('theme_changed', '托管站主题已更新，请重新适配', 409);
            }
            if (! in_array($candidate->theme_id, app(SiteThemeCatalog::class)->hostedCompatibleIds(), true)) {
                throw new ApiException('theme_not_hosted_compatible', '当前主题尚未获得托管站认证', 409);
            }
            SiteThemeBinding::query()->insertOrIgnore([
                'site_key' => $workspace->site_key, 'theme_id' => $candidate->theme_id, 'settings' => '{}', 'lock_version' => 0,
                'created_at' => now(), 'updated_at' => now(),
            ]);
            $binding = SiteThemeBinding::query()->whereKey($workspace->site_key)->lockForUpdate()->firstOrFail();
            $this->assertBinding($binding, $candidate, $base, $proof);
            $this->assertCoreUnchanged($candidate);
            $this->assertEmergencyIsOff();
            if ($binding->revision_id === null) {
                $this->assertSourceUnchanged($workspace, $base);
            }
            $this->assertSettingsVersion((int) $lockedProfile->settings_version, $proof);
            $recommendations = (array) ($settings['article_recommendations'] ?? []);
            $beforeEnabled = ($recommendations['enabled'] ?? false) === true;
            $recommendations['enabled'] = true;
            $settings['article_recommendations'] = $recommendations;
            $lockedChannel->site_settings = $settings;
            $lockedChannel->save();
            $lockedProfile->settings_version++;
            $lockedProfile->save();
            $binding->revision_id = $candidate->id;
            $binding->lock_version++;
            $binding->save();

            $release = $this->receipt($lockedWorkspace, $base, $candidate, $admin, $binding, $proof, $beforeEnabled);
            $lockedWorkspace->update(['state' => 'released']);

            return $release;
        }, 3);
    }

    private function assertBinding(SiteThemeBinding $binding, ThemeRevision $candidate, ThemeRevision $base, array $proof): void
    {
        if ($binding->theme_id !== $candidate->theme_id || (int) $binding->lock_version !== $proof['binding_version']
            || ($binding->revision_id !== null && $binding->revision_id !== $base->id)) {
            throw new ApiException('theme_binding_conflict', '主题绑定已变更，请重新验证', 409);
        }
    }

    private function assertSettingsVersion(int $actual, array $proof): void
    {
        if ($actual !== $proof['settings_revision']) {
            throw new ApiException('site_settings_conflict', '站点设置已更新，请重新验证', 409);
        }
    }

    private function receipt(ThemeWorkspace $workspace, ThemeRevision $base, ThemeRevision $candidate, Admin $admin, SiteThemeBinding $binding, array $proof, bool $beforeEnabled): ThemeRelease
    {
        return ThemeRelease::query()->create([
            'id' => (string) Str::uuid(), 'site_key' => $workspace->site_key, 'workspace_id' => $workspace->id,
            'revision_id' => $candidate->id, 'previous_revision_id' => $base->id, 'admin_id' => $admin->id,
            'binding_version' => $binding->lock_version, 'kind' => 'link_pool_adaptation',
            'changes' => [
                'before_enabled' => $beforeEnabled,
                'before_settings_revision' => $proof['settings_revision'],
                'after_settings_revision' => $proof['settings_revision'] + 1,
                'source_sha256' => $base->content_sha256,
                'article_html_sha256' => $proof['article_html_sha256'],
                'empty_html_sha256' => $proof['empty_html_sha256'],
            ],
            'plan_sha256' => hash('sha256', json_encode($proof, JSON_THROW_ON_ERROR)),
        ]);
    }

    private function rollbackPrimary(ThemeRelease $release, Admin $admin): ThemeRelease
    {
        return DB::transaction(function () use ($release, $admin): ThemeRelease {
            $this->assertRollbackActor($admin, $release);
            $binding = SiteThemeBinding::query()->whereKey('primary')->lockForUpdate()->firstOrFail();
            $selected = SiteSetting::query()->where('setting_key', 'active_theme')->value('setting_value') ?? config('geoflow.default_theme');
            if ($selected !== $binding->theme_id) {
                throw new ApiException('theme_rollback_conflict', '当前主题已变更，撤回需要重新核对', 409);
            }
            $setting = SiteSetting::query()->where('setting_key', 'article_recommendations')->lockForUpdate()->firstOrFail();
            $current = json_decode((string) $setting->setting_value, true) ?: [];
            $this->assertRollbackState($binding, (int) ($current['revision'] ?? 0), $release);
            $current['enabled'] = ($release->changes['before_enabled'] ?? false) === true;
            $current['revision']++;
            $setting->setting_value = json_encode($current, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
            $setting->save();
            $binding->revision_id = $release->previous_revision_id;
            $binding->lock_version++;
            $binding->save();
            DB::afterCommit(static fn () => SiteSettingsBag::forget());

            return $this->rollbackReceipt($release, $admin, $binding);
        }, 3);
    }

    private function rollbackHosted(DistributionChannel $channel, HostedSiteProfile $profile, ThemeRelease $release, Admin $admin): ThemeRelease
    {
        return DB::transaction(function () use ($channel, $profile, $release, $admin): ThemeRelease {
            $this->assertRollbackActor($admin, $release);
            $lockedChannel = DistributionChannel::query()->whereKey($channel->id)->lockForUpdate()->firstOrFail();
            $lockedProfile = HostedSiteProfile::query()->whereKey($profile->id)->lockForUpdate()->firstOrFail();
            if ($lockedChannel->status === DistributionChannel::STATUS_DELETING) {
                throw new ApiException('site_deleting', '托管站渠道正在删除，无法撤回', 409);
            }
            $binding = SiteThemeBinding::query()->whereKey($release->site_key)->lockForUpdate()->firstOrFail();
            $selected = (string) (($lockedChannel->site_settings ?? [])['theme_id'] ?? $lockedChannel->template_key ?? '');
            if ($selected !== $binding->theme_id) {
                throw new ApiException('theme_rollback_conflict', '托管站主题已变更，撤回需要重新核对', 409);
            }
            $this->assertRollbackState($binding, (int) $lockedProfile->settings_version, $release);
            $settings = $lockedChannel->site_settings ?? [];
            $recommendations = (array) ($settings['article_recommendations'] ?? []);
            $recommendations['enabled'] = ($release->changes['before_enabled'] ?? false) === true;
            $settings['article_recommendations'] = $recommendations;
            $lockedChannel->site_settings = $settings;
            $lockedChannel->save();
            $lockedProfile->settings_version++;
            $lockedProfile->save();
            $binding->revision_id = $release->previous_revision_id;
            $binding->lock_version++;
            $binding->save();

            return $this->rollbackReceipt($release, $admin, $binding);
        }, 3);
    }

    private function assertRollbackState(SiteThemeBinding $binding, int $settingsRevision, ThemeRelease $release): void
    {
        if ($binding->revision_id !== $release->revision_id || (int) $binding->lock_version !== (int) $release->binding_version
            || $settingsRevision !== (int) ($release->changes['after_settings_revision'] ?? -1)) {
            throw new ApiException('theme_rollback_conflict', '主题或站点设置已被修改，撤回需要重新核对', 409);
        }
    }

    private function rollbackReceipt(ThemeRelease $release, Admin $admin, SiteThemeBinding $binding): ThemeRelease
    {
        return ThemeRelease::query()->create([
            'id' => (string) Str::uuid(), 'site_key' => $release->site_key, 'workspace_id' => $release->workspace_id,
            'revision_id' => $release->previous_revision_id, 'previous_revision_id' => $release->revision_id,
            'admin_id' => $admin->id, 'binding_version' => $binding->lock_version, 'kind' => 'link_pool_rollback',
            'changes' => ['rolled_back_release_id' => $release->id, 'enabled' => $release->changes['before_enabled']],
            'plan_sha256' => $release->plan_sha256,
        ]);
    }

    private function assertReadback(ThemeRelease $release): void
    {
        $binding = SiteThemeBinding::query()->useWritePdo()->find($release->site_key);
        if ($binding?->revision_id !== $release->revision_id || (int) $binding?->lock_version !== (int) $release->binding_version) {
            throw new ApiException('theme_release_readback_failed', '发布回读失败，请检查站点主题绑定', 503);
        }
        if ($release->site_key === 'primary') {
            $stored = SiteSetting::query()->useWritePdo()->where('setting_key', 'article_recommendations')->value('setting_value');
            $current = json_decode((string) $stored, true) ?: [];
            $settingsRevision = (int) ($current['revision'] ?? 0);
            $enabled = ($current['enabled'] ?? false) === true;
        } else {
            $profileId = (int) substr($release->site_key, 7);
            $profile = HostedSiteProfile::query()->useWritePdo()->with('channel')->find($profileId);
            $settingsRevision = (int) ($profile?->settings_version ?? -1);
            $enabled = ($profile?->channel?->site_settings['article_recommendations']['enabled'] ?? false) === true;
        }
        if (! $enabled || $settingsRevision !== (int) ($release->changes['after_settings_revision'] ?? -1)) {
            throw new ApiException('theme_release_readback_failed', '发布回读失败，请检查站点推荐设置', 503);
        }
    }

    private function withHostedLease(string $siteKey, callable $callback): mixed
    {
        if (! preg_match('/^hosted:([1-9][0-9]*)$/D', $siteKey, $match)) {
            throw new ApiException('invalid_site', '无效的托管站身份', 422);
        }
        $profile = HostedSiteProfile::query()->with('channel')->find((int) $match[1]);
        if (! $profile?->channel) {
            throw new ApiException('site_not_found', '托管站不存在', 404);
        }

        return $this->leases->run($profile->channel, 'link_pool_theme_release', fn (DistributionChannel $channel) => $callback($channel, $profile));
    }
}
