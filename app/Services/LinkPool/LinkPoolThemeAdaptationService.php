<?php

namespace App\Services\LinkPool;

use App\Exceptions\ApiException;
use App\Exceptions\DistributionChannelDeletionBlocked;
use App\Jobs\AdaptLinkPoolTheme;
use App\Models\Admin;
use App\Models\HostedSiteProfile;
use App\Models\LinkPoolThemeAdaptation;
use App\Models\SiteSetting;
use App\Models\SiteThemeBinding;
use App\Models\ThemeRelease;
use App\Models\ThemeRevision;
use App\Models\ThemeWorkspace;
use App\Services\Api\LinkPoolThemeBindingRelease;
use App\Services\Api\ManagementInstance;
use App\Services\Api\ThemeReleaseService;
use App\Services\Api\ThemeRevisionStorage;
use App\Services\Api\ThemeWorkspaceService;
use App\Services\Deployment\UpgradeJournal;
use App\Services\GeoFlow\DistributionChannelOperationLeaseService;
use App\Services\Site\LinkPoolSiteService;
use App\Support\Site\SiteThemeCatalog;
use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

final class LinkPoolThemeAdaptationService
{
    public function __construct(
        private readonly LinkPoolSiteService $sites,
        private readonly SiteThemeCatalog $catalog,
        private readonly ThemeWorkspaceService $workspaces,
        private readonly ThemeRevisionStorage $revisions,
        private readonly LinkPoolThemePatchPlanner $planner,
        private readonly LinkPoolThemeValidator $validator,
        private readonly LinkPoolThemeReadback $readback,
        private readonly ThemeReleaseService $releases,
        private readonly ManagementInstance $instance,
        private readonly UpgradeJournal $upgrades,
        private readonly DistributionChannelOperationLeaseService $leases,
    ) {}

    public function start(string $siteKey, Admin $admin): LinkPoolThemeAdaptation
    {
        $this->assertActor($admin);
        $this->assertSite($siteKey);
        $existing = LinkPoolThemeAdaptation::query()->where('active_site_key', $siteKey)->first();
        if ($existing !== null) {
            return $existing;
        }
        if (! ThemeReleaseService::linkPoolAdaptationAvailability()['available']) {
            throw new ApiException('theme_adaptation_unavailable', '当前部署尚未完成主题发布运行检查', 503);
        }
        $theme = $this->theme($siteKey);
        if ($this->sites->themeSupportsRecommendations($siteKey)) {
            throw new ApiException('theme_already_adapted', '当前主题已经接入推荐区，可直接开启推荐', 409);
        }
        if ($this->sites->emergencyDisabled()) {
            throw new ApiException('link_pool_emergency_disabled', '全部站点推荐已紧急停用', 409);
        }
        if (! $this->sites->canPublish($siteKey)) {
            throw new ApiException('site_unavailable', '站点当前无法公开投放', 409);
        }
        try {
            return DB::transaction(function () use ($siteKey, $theme, $admin): LinkPoolThemeAdaptation {
                $task = LinkPoolThemeAdaptation::query()->create([
                    'id' => (string) Str::uuid(), 'site_key' => $siteKey, 'active_site_key' => $siteKey,
                    'theme_id' => $theme['id'], 'admin_id' => $admin->id,
                    'status' => 'queued', 'phase' => 'queued',
                ]);
                DB::afterCommit(function () use ($task): void {
                    try {
                        app(Dispatcher::class)->dispatch(
                            (new AdaptLinkPoolTheme($task->id))->onQueue('theme-replication'),
                        );
                    } catch (Throwable $exception) {
                        LinkPoolThemeAdaptation::query()->whereKey($task->id)->where('status', 'queued')->update([
                            'status' => 'failed', 'phase' => 'failed', 'active_site_key' => null,
                            'error_code' => 'theme_queue_unavailable',
                            'error_message' => '后台处理服务暂时不可用，请稍后点击重试。当前主题和推荐开关保持原值。',
                            'finished_at' => now(),
                        ]);
                        report($exception);
                    }
                    $task->refresh();
                });

                return $task;
            });
        } catch (QueryException $exception) {
            $existing = LinkPoolThemeAdaptation::query()->where('active_site_key', $siteKey)->first();
            if ($existing !== null) {
                return $existing;
            }
            throw $exception;
        }
    }

    public function run(string $id): void
    {
        $task = LinkPoolThemeAdaptation::query()->findOrFail($id);
        if (LinkPoolThemeAdaptation::query()->whereKey($id)->where('status', 'queued')->update([
            'status' => 'running', 'phase' => 'checking', 'started_at' => now(),
        ]) !== 1) {
            return;
        }
        $task->refresh();
        try {
            if (! ThemeReleaseService::linkPoolAdaptationAvailability()['available']) {
                throw new ApiException('theme_adaptation_unavailable', '主题发布运行条件已变化，请完成部署检查后重试', 503);
            }
            $admin = Admin::query()->findOrFail($task->admin_id);
            $this->assertActor($admin);
            $this->assertSite($task->site_key);
            if ($this->theme($task->site_key)['id'] !== $task->theme_id) {
                throw new ApiException('theme_changed', '站点主题已变化，请重新发起适配', 409);
            }
            if ($this->sites->emergencyDisabled() || ! $this->sites->canPublish($task->site_key)) {
                throw new ApiException('site_unavailable', '站点当前无法公开投放', 409);
            }
            $binding = SiteThemeBinding::query()->find($task->site_key);
            $bindingVersion = (int) ($binding?->lock_version ?? 0);
            $settingsRevision = $this->sites->settings($task->site_key)['revision'];
            $theme = $this->theme($task->site_key);
            $workspaceId = (string) Str::uuid();
            $workspace = ThemeWorkspace::query()->create([
                'id' => $workspaceId, 'instance_id' => $this->instance->id(), 'admin_id' => $admin->id,
                'site_key' => $task->site_key, 'theme_id' => $task->theme_id, 'source' => $theme['source'],
                'state' => 'draft',
            ]);
            $task->update(['phase' => 'snapshot', 'workspace_id' => $workspaceId]);
            $base = $binding?->revision_id ? ThemeRevision::query()->findOrFail($binding->revision_id) : null;
            $files = $base
                ? $this->revisions->contents($base)
                : $this->workspaces->sourceContents($task->theme_id, $theme['source']);
            $base ??= $this->revisions->create($workspaceId, $task->theme_id, $files, $binding?->settings ?? []);
            $task->update(['base_sha256' => $base->content_sha256, 'phase' => 'locating']);
            $patch = $this->planner->patch($files, $task->theme_id, $admin);
            $candidate = $this->revisions->create($workspaceId, $task->theme_id, $patch['files'], $base->settings, $base->id);
            $workspace->update(['revision_id' => $candidate->id]);
            $task->update(['candidate_revision_id' => $candidate->id, 'model_id' => $patch['model_id'], 'phase' => 'validating']);
            $evidence = $this->validator->validate($candidate, $task->site_key);
            $proof = [
                'site_key' => $task->site_key, 'revision_id' => $candidate->id,
                'content_sha256' => $candidate->content_sha256, 'base_sha256' => $base->content_sha256,
                'binding_version' => $bindingVersion, 'settings_revision' => $settingsRevision,
                'article_html_sha256' => $evidence['article_html_sha256'],
                'empty_html_sha256' => $evidence['empty_html_sha256'],
                'validated_at' => now()->toIso8601String(),
            ];
            $workspace->update(['plan' => [
                'kind' => 'link_pool_adaptation', 'revision_id' => $candidate->id,
                'proof_sha256' => hash('sha256', json_encode($proof, JSON_THROW_ON_ERROR)),
            ]]);
            $task->update(['phase' => 'publishing', 'article_url' => $evidence['article_url']]);
            $release = $this->withSiteLease($task->site_key, function () use ($task, $candidate, $admin, $proof, $evidence): ThemeRelease {
                $upgradeLock = $this->acquireUpgradeLock();
                try {
                    $release = $this->releases->publishLinkPoolAdaptation($candidate, $admin, $proof);
                    $task->update(['release_id' => $release->id]);
                    try {
                        $this->readback->check($task->site_key, $candidate, $evidence['article_url']);
                    } catch (Throwable $readbackFailure) {
                        try {
                            $this->releases->rollbackLinkPoolAdaptation($release, $admin);
                        } catch (Throwable $rollbackFailure) {
                            report($rollbackFailure);
                            $this->suspendFailedRevision($release);
                            throw new ApiException('theme_release_compensation_failed', '文章页回读失败且自动撤回未完成，请人工核对站点状态', 503);
                        }
                        throw $readbackFailure;
                    }

                    return $release;
                } finally {
                    $this->upgrades->release($upgradeLock);
                }
            });
            $task->update([
                'status' => 'completed', 'phase' => 'completed', 'release_id' => $release->id,
                'active_site_key' => null, 'finished_at' => now(),
            ]);
        } catch (Throwable $exception) {
            $task->update([
                'status' => 'failed', 'phase' => 'failed', 'active_site_key' => null,
                'error_code' => $exception instanceof ApiException ? $exception->getErrorCode() : ($exception instanceof DistributionChannelDeletionBlocked ? 'site_deleting' : 'theme_adaptation_failed'),
                'error_message' => $exception instanceof ApiException ? $exception->getMessage() : ($exception instanceof DistributionChannelDeletionBlocked ? '托管站渠道正在删除，适配已停止。' : '主题适配未完成，请稍后重试；管理员可查看任务日志。'),
                'finished_at' => now(),
            ]);
            report($exception);
        }
    }

    public function rollback(LinkPoolThemeAdaptation $task, Admin $admin): ThemeRelease
    {
        $this->assertActor($admin);
        if ($task->status !== 'completed' || $task->release_id === null) {
            throw new ApiException('theme_rollback_unavailable', '此任务没有可撤回的发布版本', 409);
        }
        $receipt = $this->withSiteLease($task->site_key, function () use ($task, $admin): ThemeRelease {
            $upgradeLock = $this->acquireUpgradeLock();
            try {
                $receipt = $this->releases->rollbackLinkPoolAdaptation(ThemeRelease::query()->findOrFail($task->release_id), $admin);
                $task->update(['status' => 'rolled_back', 'phase' => 'rolled_back']);
                $this->readback->check($task->site_key, ThemeRevision::query()->findOrFail($receipt->revision_id), $task->article_url);

                return $receipt;
            } finally {
                $this->upgrades->release($upgradeLock);
            }
        });
        $task->update(['status' => 'rolled_back', 'phase' => 'rolled_back']);

        return $receipt;
    }

    public function recoverFailedTask(string $id): void
    {
        $task = LinkPoolThemeAdaptation::query()->find($id);
        if ($task === null || ! in_array($task->status, ['queued', 'running'], true)) {
            return;
        }
        $error = '处理进程已中断，请重新发起适配。';
        $release = $task->workspace_id ? ThemeRelease::query()->where('workspace_id', $task->workspace_id)->where('kind', 'link_pool_adaptation')->first() : null;
        if ($release !== null) {
            try {
                $admin = Admin::query()->findOrFail($task->admin_id);
                $this->withSiteLease($task->site_key, function () use ($release, $admin): void {
                    $lock = $this->acquireUpgradeLock();
                    try {
                        $this->releases->rollbackLinkPoolAdaptation($release, $admin);
                    } finally {
                        $this->upgrades->release($lock);
                    }
                });
                $error = '处理进程已中断，本次发布已自动撤回。请重新发起适配。';
            } catch (Throwable $exception) {
                report($exception);
                $this->suspendFailedRevision($release);
                $error = '处理进程已中断，发布状态需要管理员核对。';
            }
        }
        $task->update(['status' => 'failed', 'phase' => 'failed', 'active_site_key' => null,
            'error_code' => 'theme_worker_interrupted', 'error_message' => $error,
            'release_id' => $release?->id, 'finished_at' => now()]);
    }

    private function withSiteLease(string $siteKey, callable $callback): mixed
    {
        if ($siteKey === 'primary') {
            return $callback();
        }
        $profile = HostedSiteProfile::query()->with('channel')->find((int) substr($siteKey, 7));
        if (! $profile?->channel) {
            throw new ApiException('site_not_found', '托管站不存在', 404);
        }

        return $this->leases->run($profile->channel, 'link_pool_theme_adaptation', fn () => $callback());
    }

    private function suspendFailedRevision(ThemeRelease $release): void
    {
        try {
            app(LinkPoolThemeBindingRelease::class)->suspendFailedRevision($release);
        } catch (Throwable $exception) {
            report($exception);
        }
        Log::critical('Link pool theme adaptation needs recovery review', [
            'release_id' => $release->id, 'site_key' => $release->site_key,
        ]);
    }

    private function acquireUpgradeLock(): mixed
    {
        try {
            return $this->upgrades->acquire();
        } catch (RuntimeException $exception) {
            if ($exception->getMessage() === 'upgrade_lock_held') {
                throw new ApiException('theme_upgrade_conflict', '系统正在升级或发布主题，请稍后重试。', 409);
            }
            throw $exception;
        }
    }

    private function assertActor(Admin $admin): void
    {
        $current = $admin->fresh();
        if (! $current || $current->status !== 'active' || ! $current->canManageProtectedWorkflows()) {
            throw new ApiException('forbidden', '当前账号无权执行主题适配', 403);
        }
    }

    private function assertSite(string $siteKey): void
    {
        if ($siteKey !== 'primary' && $this->sites->profile($siteKey) === null) {
            throw new ApiException('site_not_found', '来源站点不存在', 404);
        }
    }

    /** @return array{id:string,source:string} */
    private function theme(string $siteKey): array
    {
        $id = $siteKey === 'primary'
            ? (string) SiteSetting::query()->where('setting_key', 'active_theme')->value('setting_value')
            : (string) ($this->sites->profile($siteKey)?->channel?->site_settings['theme_id']
                ?? $this->sites->profile($siteKey)?->channel?->template_key ?? '');
        $id = trim($id) ?: (string) config('geoflow.default_theme');
        $theme = collect($this->catalog->all())->firstWhere('id', $id);
        if (! is_array($theme)) {
            throw new ApiException('theme_not_found', '当前文章主题不存在', 404);
        }
        if ($siteKey !== 'primary' && ! in_array($id, $this->catalog->hostedCompatibleIds(), true)) {
            throw new ApiException('theme_not_hosted_compatible', '当前主题尚未获得托管站认证', 409);
        }

        return ['id' => $id, 'source' => (string) ($theme['source'] ?? 'builtin')];
    }
}
