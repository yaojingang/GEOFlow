<?php

namespace App\Services\AiWorkspace;

use App\Ai\Workspace\AiCapabilityRegistry;
use App\Ai\Workspace\AiCapabilityResult;
use App\Ai\Workspace\AiOutcomeUnknownException;
use App\Ai\Workspace\AiPayloadDigest;
use App\Ai\Workspace\AiWorkspaceChannelRevision;
use App\Ai\Workspace\AiWorkspaceErrorSanitizer;
use App\Models\Admin;
use App\Models\AiVisibilityRun;
use App\Models\AiVisibilitySource;
use App\Models\AiWorkspaceExternalOperation;
use App\Models\AiWorkspaceStep;
use App\Models\Article;
use App\Models\ArticleDistribution;
use App\Models\DistributionChannel;
use App\Models\DistributionChannelSecret;
use App\Models\DistributionLog;
use App\Models\HostedSiteProfile;
use App\Models\Keyword;
use App\Models\LeadSubmission;
use App\Models\Task;
use App\Models\UrlImportJob;
use App\Models\UrlImportJobLog;
use App\Services\AiWorkspace\Capabilities\AiCapabilityHandler;
use App\Services\AiWorkspace\Capabilities\AiWorkspaceCapabilityDriver;
use App\Services\GeoFlow\DistributionChannelOperationLeaseService;
use App\Services\GeoFlow\DistributionOrchestrator;
use App\Services\GeoFlow\DistributionPublisherManager;
use App\Services\GeoFlow\FrontendExperienceInspector;
use App\Services\GeoFlow\TaskLifecycleService;
use App\Services\GeoFlow\UrlImportAiExecutionGuard;
use App\Services\GeoFlow\UrlImportProcessingService;
use App\Services\HostedSites\HostedSiteQualityService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

final readonly class AiCapabilityExecutor implements AiWorkspaceCapabilityDriver
{
    public function __construct(
        private AiCapabilityRegistry $registry,
        private TaskLifecycleService $taskLifecycle,
        private DistributionOrchestrator $distribution,
        private HostedSiteQualityService $hostedSiteQuality,
        private UrlImportProcessingService $urlImports,
        private UrlImportAiExecutionGuard $urlImportExecutionGuard,
        private DistributionChannelOperationLeaseService $channelOperations,
        private DistributionPublisherManager $publishers,
        private FrontendExperienceInspector $frontendInspector,
        private AiWorkspaceDispatchGuard $dispatchGuard,
    ) {}

    /** @param array<string,mixed> $parameters */
    public function execute(string $capabilityKey, array $parameters, Admin $admin, ?string $executionKey = null): AiCapabilityResult
    {
        $capability = $this->registry->get($capabilityKey);
        if (! $capability->allows($admin)) {
            throw new RuntimeException('当前管理员无权执行该能力。');
        }
        if (is_subclass_of($capability->handler, AiCapabilityHandler::class)) {
            return app($capability->handler)->execute($parameters, $admin, $executionKey);
        }

        throw new RuntimeException('该能力没有可执行处理器。');
    }

    /**
     * Execute the domain action selected by an independently registered handler.
     *
     * @param  array<string,mixed>  $parameters
     */
    public function executeRegisteredAction(
        string $capabilityKey,
        array $parameters,
        Admin $admin,
        ?string $executionKey = null,
    ): AiCapabilityResult {
        return match ($capabilityKey) {
            'system.capabilities.explain', 'content.catalog', 'site.operations' => $this->catalog($admin),
            'analytics.daily_report', 'analytics.weekly_report' => $this->operationalReport($capabilityKey, $parameters, $admin),
            'visibility.diagnose' => $this->visibilityDiagnosis($parameters),
            'content.opportunities' => $this->contentOpportunities($parameters),
            'url_import.preview' => $this->urlImportPreview($parameters, $admin, $executionKey),
            'url_import.commit' => $this->urlImportCommit($parameters, $admin),
            'distribution.preview' => $this->distributionPreview($parameters),
            'task.status.change' => $this->taskStatus($parameters, $admin),
            'distribution.publish' => $this->distributionPublish($parameters, $executionKey),
            'distribution.site_settings_sync' => $this->siteSettingsSync($parameters, $executionKey),
            'hosted_site.preflight' => $this->hostedSitePreflight($parameters),
            'admin.governance', 'managed.operations' => throw new RuntimeException('该能力只允许导航说明，不能执行。'),
            default => throw new RuntimeException('该能力没有已注册的领域动作。'),
        };
    }

    public function prepareExternalExecution(AiWorkspaceStep $step): void
    {
        if ($step->capability_key !== 'distribution.site_settings_sync') {
            return;
        }
        $channelIds = array_values(array_unique(array_map('intval', (array) data_get($step->parameters, 'channel_ids', []))));
        $channels = DistributionChannel::query()
            ->whereIn('id', $channelIds)
            ->where('status', DistributionChannel::STATUS_ACTIVE)
            ->get();
        if ($channels->count() !== count($channelIds)) {
            throw new RuntimeException('部分站点不支持设置同步。');
        }
        foreach ($channels as $channel) {
            $requestPayload = [
                'settings' => $channel->targetSiteSettingsPayload(),
                'channel_revision' => $this->channelRevision($channel),
            ];
            $operation = AiWorkspaceExternalOperation::query()->firstOrCreate(
                [
                    'execution_key' => (string) $step->idempotency_key,
                    'target_type' => 'distribution_channel',
                    'target_id' => (string) $channel->id,
                ],
                [
                    'id' => (string) Str::uuid7(),
                    'run_id' => (string) $step->run_id,
                    'step_id' => (string) $step->id,
                    'capability_key' => (string) $step->capability_key,
                    'status' => 'prepared',
                    'request_digest' => AiPayloadDigest::make($requestPayload),
                    'target_digest' => AiPayloadDigest::make((array) $step->target_summary),
                    'request_payload' => $requestPayload,
                ],
            );
            if (! hash_equals((string) $operation->request_digest, AiPayloadDigest::make($requestPayload))
                || ! hash_equals((string) $operation->target_digest, AiPayloadDigest::make((array) $step->target_summary))) {
                throw new RuntimeException('外部操作账本与已审批计划不一致。');
            }
        }
    }

    /** @param array<string,mixed> $parameters */
    public function reconcileExternal(string $capabilityKey, array $parameters, Admin $admin, string $executionKey): ?AiCapabilityResult
    {
        $capability = $this->registry->get($capabilityKey);
        if (! $capability->allows($admin) || $capability->executionScope !== 'external_write') {
            return null;
        }

        return $this->reconcileRecordedExternal($capabilityKey, $parameters, $executionKey);
    }

    /** @param array<string,mixed> $parameters */
    public function reconcileRecordedExternal(string $capabilityKey, array $parameters, string $executionKey): ?AiCapabilityResult
    {
        if ($capabilityKey !== 'distribution.site_settings_sync') {
            return null;
        }

        $channelIds = array_values(array_unique(array_map('intval', (array) ($parameters['channel_ids'] ?? []))));
        $operations = AiWorkspaceExternalOperation::query()
            ->where('execution_key', $executionKey)
            ->where('target_type', 'distribution_channel')
            ->whereIn('target_id', array_map('strval', $channelIds))
            ->where('status', 'confirmed')
            ->get()
            ->keyBy(static fn (AiWorkspaceExternalOperation $operation): int => (int) $operation->target_id);
        if (count($channelIds) === 0) {
            return null;
        }
        if ($operations->count() !== count($channelIds)) {
            $legacyLogs = DistributionLog::query()
                ->whereIn('distribution_channel_id', $channelIds)
                ->where('event', 'site.settings.synced')
                ->latest('created_at')
                ->limit(max(20, count($channelIds) * 5))
                ->get()
                ->filter(static fn (DistributionLog $log): bool => hash_equals(
                    $executionKey,
                    (string) data_get($log->context, 'ai_workspace_execution_key', ''),
                ))
                ->keyBy('distribution_channel_id');
            if ($legacyLogs->count() !== count($channelIds)) {
                return null;
            }

            return new AiCapabilityResult(
                summary: sprintf('已从历史操作日志确认 %d 个目标站点完成设置同步。', $legacyLogs->count()),
                payload: [
                    'results' => collect($channelIds)->map(static fn (int $channelId): array => [
                        'channel_id' => $channelId,
                        'refresh_count' => (int) data_get($legacyLogs->get($channelId)?->context, 'refresh_count', 0),
                        'remote_result' => (array) data_get($legacyLogs->get($channelId)?->context, 'remote_result', []),
                        'reconciled' => true,
                    ])->all(),
                    'reconciled' => true,
                ],
                artifactType: 'site_settings_sync',
                artifactName: '站点设置同步对账结果',
                sourceRoute: 'admin.distribution.index',
                sourceUrl: route('admin.distribution.index'),
            );
        }

        $results = collect($channelIds)->map(static function (int $channelId) use ($operations): array {
            $operation = $operations->get($channelId);

            return [
                'channel_id' => $channelId,
                'refresh_count' => (int) data_get($operation?->remote_result, 'refresh_count', 0),
                'remote_result' => (array) data_get($operation?->remote_result, 'remote_result', []),
                'reconciled' => true,
            ];
        })->all();

        return new AiCapabilityResult(
            summary: sprintf('已从外部操作账本确认 %d 个目标站点完成设置同步。', count($results)),
            payload: ['results' => $results, 'reconciled' => true],
            artifactType: 'site_settings_sync',
            artifactName: '站点设置同步对账结果',
            sourceRoute: 'admin.distribution.index',
            sourceUrl: route('admin.distribution.index'),
        );
    }

    private function catalog(Admin $admin): AiCapabilityResult
    {
        $items = $this->registry->visibleTo($admin)->map(
            static fn ($capability): array => [
                'key' => $capability->key,
                'name' => $capability->name,
                'description' => $capability->description,
                'maturity' => $capability->maturity,
                'risk' => $capability->risk,
                'routes' => $capability->routePatterns,
            ]
        )->values()->all();

        return new AiCapabilityResult(
            summary: sprintf('已登记 %d 项当前管理员可见能力。', count($items)),
            payload: ['capabilities' => $items],
            artifactType: 'capability_catalog',
            artifactName: 'GEOFlow 能力目录',
            sourceRoute: 'admin.ai-workspace',
            sourceUrl: route('admin.ai-workspace'),
        );
    }

    /** @param array<string,mixed> $parameters */
    private function operationalReport(string $key, array $parameters, Admin $admin): AiCapabilityResult
    {
        $days = $key === 'analytics.weekly_report' ? 7 : 1;
        $end = Carbon::parse((string) ($parameters['end_date'] ?? $parameters['date'] ?? now()->toDateString()))->endOfDay();
        $start = $end->copy()->subDays($days - 1)->startOfDay();
        $payload = [
            'period' => ['from' => $start->toDateString(), 'to' => $end->toDateString()],
            'articles_created' => Article::query()->whereBetween('created_at', [$start, $end])->count(),
            'articles_published' => Article::query()->where('status', 'published')->whereBetween('published_at', [$start, $end])->count(),
            'total_tasks' => Task::query()->count(),
            'active_tasks' => Task::query()->where('status', 'active')->count(),
            'new_leads' => LeadSubmission::query()->whereBetween('created_at', [$start, $end])->count(),
            'visibility_runs' => AiVisibilityRun::query()->whereBetween('created_at', [$start, $end])->count(),
        ];
        if ($admin->isSuperAdmin()) {
            $payload['successful_distributions'] = DB::table('article_distributions as ad')
                ->join('articles as a', 'ad.article_id', '=', 'a.id')
                ->whereNull('a.deleted_at')
                ->where('ad.status', 'synced')
                ->whereBetween('ad.created_at', [$start, $end])
                ->count();
        }

        return new AiCapabilityResult(
            summary: sprintf(
                '%s至%s：新增文章 %d 篇，发布 %d 篇，新增线索 %d 条。当前任务共 %d 个，其中运行中 %d 个。',
                $payload['period']['from'],
                $payload['period']['to'],
                $payload['articles_created'],
                $payload['articles_published'],
                $payload['new_leads'],
                $payload['total_tasks'],
                $payload['active_tasks'],
            ),
            payload: $payload,
            artifactType: 'operational_report',
            artifactName: $days === 7 ? 'GEOFlow 运营周报' : 'GEOFlow 运营日报',
            sourceRoute: 'admin.analytics',
            sourceUrl: route('admin.analytics'),
        );
    }

    /** @param array<string,mixed> $parameters */
    private function visibilityDiagnosis(array $parameters): AiCapabilityResult
    {
        $query = trim((string) $parameters['query']);
        $days = min(90, max(1, (int) ($parameters['days'] ?? 30)));
        $runs = AiVisibilityRun::query()
            ->where('keyword', 'like', '%'.$this->escapeLike($query).'%')
            ->where('created_at', '>=', now()->subDays($days))
            ->latest()
            ->limit((int) config('ai-workspace.read_budget_rows', 500))
            ->get();
        $runIds = $runs->pluck('id');
        $sources = $runIds->isEmpty()
            ? collect()
            : AiVisibilitySource::query()->whereIn('ai_visibility_run_id', $runIds)->orderBy('rank')->limit(100)->get();
        $domains = $sources->groupBy('domain')->map->count()->sortDesc()->take(10);
        $payload = [
            'query' => $query,
            'days' => $days,
            'run_count' => $runs->count(),
            'completed_count' => $runs->where('status', AiVisibilityRun::STATUS_COMPLETED)->count(),
            'source_count' => $sources->count(),
            'top_domains' => $domains->map(static fn (int $count, string $domain): array => ['domain' => $domain, 'mentions' => $count])->values()->all(),
            'latest_answers' => $runs->take(5)->map(static fn (AiVisibilityRun $run): array => [
                'id' => (int) $run->id,
                'keyword' => (string) $run->keyword,
                'status' => (string) $run->status,
                'completed_at' => $run->completed_at?->toISOString(),
            ])->all(),
        ];

        return new AiCapabilityResult(
            summary: $runs->isEmpty()
                ? '当前周期没有匹配的 AI 可见性采集记录。'
                : sprintf('找到 %d 次采集和 %d 条信源记录，已整理主要信源域名。', $runs->count(), $sources->count()),
            payload: $payload,
            artifactType: 'visibility_diagnosis',
            artifactName: $query.' AI 可见性诊断',
            sourceRoute: 'admin.analytics.ai-visibility',
            sourceUrl: route('admin.analytics.ai-visibility', ['keyword' => $query]),
        );
    }

    /** @param array<string,mixed> $parameters */
    private function contentOpportunities(array $parameters): AiCapabilityResult
    {
        $theme = trim((string) ($parameters['theme'] ?? ''));
        $keywords = Keyword::query()
            ->when($theme !== '', fn ($query) => $query->where('keyword', 'like', '%'.$this->escapeLike($theme).'%'))
            ->orderBy('used_count')
            ->orderByDesc('usage_count')
            ->limit(20)
            ->get(['id', 'keyword', 'used_count', 'usage_count']);
        $payload = [
            'theme' => $theme,
            'opportunities' => $keywords->map(static fn (Keyword $keyword): array => [
                'keyword_id' => (int) $keyword->id,
                'keyword' => (string) $keyword->keyword,
                'used_count' => (int) $keyword->used_count,
                'usage_count' => (int) $keyword->usage_count,
                'priority' => (int) $keyword->used_count === 0 ? 'high' : 'normal',
            ])->all(),
        ];

        return new AiCapabilityResult(
            summary: sprintf('已识别 %d 个低覆盖关键词，可继续生成任务草稿。', $keywords->count()),
            payload: $payload,
            artifactType: 'content_opportunities',
            artifactName: ($theme !== '' ? $theme.' ' : '').'内容机会',
            sourceRoute: 'admin.keyword-libraries.index',
            sourceUrl: route('admin.keyword-libraries.index'),
        );
    }

    /** @param array<string,mixed> $parameters */
    private function urlImportPreview(array $parameters, Admin $admin, ?string $executionKey): AiCapabilityResult
    {
        $normalized = $this->urlImports->normalizeInputUrl((string) $parameters['url']);
        $analysisModel = $this->urlImports->assertAnalysisModelReady($admin);
        $createdBy = 'GEOHub:'.hash('sha256', (string) ($executionKey ?: $normalized['url']));
        $job = DB::transaction(function () use ($admin, $analysisModel, $createdBy, $normalized, $parameters): UrlImportJob {
            $identity = $this->urlImportExecutionGuard->snapshotForCreation($admin, $analysisModel);

            return UrlImportJob::query()->firstOrCreate(
                [
                    'created_by' => $createdBy,
                    'model_access_admin_id' => $admin->getKey(),
                ],
                array_merge([
                    'url' => (string) $parameters['url'],
                    'normalized_url' => $normalized['url'],
                    'source_domain' => $normalized['host'],
                    'page_title' => '',
                    'status' => 'queued',
                    'current_step' => 'queued',
                    'progress_percent' => 0,
                    'options_json' => json_encode(['outputs' => ['knowledge', 'keywords', 'titles']], JSON_UNESCAPED_UNICODE),
                    'result_json' => '',
                    'error_message' => '',
                ], $identity),
            );
        });
        if ($job->wasRecentlyCreated) {
            UrlImportJobLog::query()->create([
                'job_id' => $job->id,
                'step' => 'queued',
                'level' => 'info',
                'message' => 'AI 工作台已创建 URL 导入预览任务。',
            ]);
        }
        if ($job->status !== 'completed') {
            $job = $this->urlImports->process($job);
        }
        if ($job->status !== 'completed') {
            throw new RuntimeException((string) ($job->error_message ?: 'URL 导入预览失败。'));
        }
        $result = $this->urlImports->decodeResult($job);
        $payload = [
            'job_id' => (int) $job->id,
            'url' => (string) $job->normalized_url,
            'host' => (string) $job->source_domain,
            'page_title' => (string) $job->page_title,
            'summary' => (string) data_get($result, 'analysis.summary', ''),
            'keyword_count' => count((array) data_get($result, 'analysis.keywords', [])),
            'title_count' => count((array) data_get($result, 'analysis.titles', [])),
        ];

        return new AiCapabilityResult(
            summary: 'URL 导入预览已生成，等待人工确认提交。',
            payload: $payload,
            artifactType: 'url_import_preview',
            artifactName: 'URL 导入预览',
            sourceRoute: 'admin.url-import.show',
            sourceUrl: route('admin.url-import.show', ['jobId' => $job->id]),
        );
    }

    /** @param array<string,mixed> $parameters */
    private function urlImportCommit(array $parameters, Admin $admin): AiCapabilityResult
    {
        $job = UrlImportJob::query()
            ->whereKey((int) $parameters['job_id'])
            ->where('model_access_admin_id', $admin->getKey())
            ->firstOrFail();
        $summary = $this->urlImports->commit($job);

        return new AiCapabilityResult(
            summary: sprintf('URL 导入任务 #%d 已提交，生成 %d 个关键词和 %d 个标题。', $job->id, $summary['keywords'], $summary['titles']),
            payload: ['job_id' => (int) $job->id, 'import_summary' => $summary],
            artifactType: 'url_import_commit',
            artifactName: 'URL 导入结果 #'.$job->id,
            sourceRoute: 'admin.url-import.show',
            sourceUrl: route('admin.url-import.show', ['jobId' => $job->id]),
        );
    }

    /** @param array<string,mixed> $parameters */
    private function distributionPreview(array $parameters): AiCapabilityResult
    {
        $articles = Article::query()->whereIn('id', $parameters['article_ids'])->get(['id', 'title', 'status']);
        $channels = DistributionChannel::query()->whereIn('id', $parameters['channel_ids'])->get(['id', 'name', 'domain', 'status']);
        $matrix = [];
        foreach ($articles as $article) {
            foreach ($channels as $channel) {
                $matrix[] = [
                    'article_id' => (int) $article->id,
                    'article_title' => (string) $article->title,
                    'channel_id' => (int) $channel->id,
                    'channel_name' => (string) $channel->name,
                    'eligible' => $article->status === 'published' && $channel->status === DistributionChannel::STATUS_ACTIVE,
                ];
            }
        }

        return new AiCapabilityResult(
            summary: sprintf('已生成 %d 个文章与站点目标组合。', count($matrix)),
            payload: ['matrix' => $matrix, 'system_operation_executed' => false],
            artifactType: 'distribution_matrix',
            artifactName: '多站分发目标矩阵',
            sourceRoute: 'admin.distribution.jobs',
            sourceUrl: route('admin.distribution.jobs'),
        );
    }

    /** @param array<string,mixed> $parameters */
    private function taskStatus(array $parameters, Admin $admin): AiCapabilityResult
    {
        $result = $parameters['action'] === 'stop'
            ? $this->taskLifecycle->stopTask((int) $parameters['task_id'], $admin->isSuperAdmin())
            : $this->taskLifecycle->startTask(
                (int) $parameters['task_id'],
                false,
                $admin->isSuperAdmin(),
                $admin,
            );

        return new AiCapabilityResult(
            summary: $parameters['action'] === 'stop' ? '任务已暂停。' : '任务已启用。',
            payload: $result,
            artifactType: 'task_state',
            artifactName: '任务状态变更',
            sourceRoute: 'admin.tasks.edit',
            sourceUrl: route('admin.tasks.edit', ['taskId' => $parameters['task_id']]),
        );
    }

    /** @param array<string,mixed> $parameters */
    private function distributionPublish(array $parameters, ?string $executionKey): AiCapabilityResult
    {
        if (! is_string($executionKey) || $executionKey === '') {
            throw new RuntimeException('分发操作缺少稳定执行键。');
        }
        $step = AiWorkspaceStep::query()->where('idempotency_key', $executionKey)->firstOrFail();
        $run = $step->run()->firstOrFail();
        $articles = Article::query()->with('task')->whereIn('id', $parameters['article_ids'])->get();
        if ($articles->count() !== count(array_unique($parameters['article_ids']))) {
            throw new RuntimeException('部分文章不存在。');
        }
        $channels = DistributionChannel::query()->whereIn('id', $parameters['channel_ids'])->where('status', DistributionChannel::STATUS_ACTIVE)->pluck('id')->map(fn ($id): int => (int) $id)->all();
        if (count($channels) !== count(array_unique($parameters['channel_ids']))) {
            throw new RuntimeException('部分目标站点不可用。');
        }
        $approvedChannelRevisions = collect((array) data_get($step->target_summary, 'channel_snapshots', []))
            ->filter(static fn (mixed $snapshot): bool => is_array($snapshot)
                && isset($snapshot['id'])
                && is_string($snapshot['revision'] ?? null)
                && $snapshot['revision'] !== '')
            ->mapWithKeys(static fn (array $snapshot): array => [(int) $snapshot['id'] => (string) $snapshot['revision']])
            ->all();
        if (count(array_intersect_key($approvedChannelRevisions, array_fill_keys($channels, true))) !== count($channels)) {
            throw new RuntimeException('分发计划缺少已审批的目标版本。');
        }

        $queuedTargets = 0;
        foreach ($articles as $article) {
            if (! $article->task instanceof Task) {
                throw new RuntimeException('文章 '.$article->id.' 没有关联任务，无法进入分发队列。');
            }
            $publishScope = (string) $article->task->publish_scope;
            if ($article->status !== 'published'
                && ! ($publishScope === 'distribution_only' && $article->status === 'private')) {
                throw new RuntimeException('文章 '.$article->id.' 当前状态不允许分发。');
            }
            if ($publishScope === 'local_only') {
                throw new RuntimeException('文章 '.$article->id.' 所属任务仅允许本地发布。');
            }
            $articleSnapshot = collect((array) data_get($step->target_summary, 'article_snapshots', []))
                ->firstWhere('id', (int) $article->id);
            $distributionIds = $this->distribution->enqueueForArticleTargets($article, $channels, [
                'run_id' => (string) $run->id,
                'step_id' => (string) $step->id,
                'admin_id' => (int) $run->admin_id,
                'admin_auth_version' => $run->admin_auth_version,
                'plan_digest' => (string) $run->plan_digest,
                'parameter_digest' => (string) $run->parameter_digest,
                'target_digest' => (string) $run->target_digest,
                'capability_version' => (string) $step->capability_version,
                'expected_payload_digest' => (string) data_get($articleSnapshot, 'outbound_payload_digest', ''),
                'approved_channel_revisions' => $approvedChannelRevisions,
            ]);
            $confirmed = ArticleDistribution::query()->whereIn('id', array_values(array_unique($distributionIds)))
                ->get()
                ->filter(function (ArticleDistribution $distribution) use ($article, $channels, $run, $step, $approvedChannelRevisions): bool {
                    $guard = data_get($distribution->remote_meta, 'ai_workspace_guard');

                    return (int) $distribution->article_id === (int) $article->id
                        && in_array((int) $distribution->distribution_channel_id, $channels, true)
                        && (string) $distribution->action === 'publish'
                        && (string) $distribution->status === 'queued'
                        && is_array($guard)
                        && hash_equals((string) $run->id, (string) ($guard['run_id'] ?? ''))
                        && hash_equals((string) $step->id, (string) ($guard['step_id'] ?? ''))
                        && hash_equals(
                            (string) ($approvedChannelRevisions[(int) $distribution->distribution_channel_id] ?? ''),
                            (string) ($guard['channel_revision'] ?? ''),
                        );
                })
                ->unique('distribution_channel_id')
                ->count();
            if ($confirmed !== count($channels)) {
                throw new RuntimeException('文章 '.$article->id.' 的部分分发目标未成功入队。');
            }
            $queuedTargets += $confirmed;
        }

        return new AiCapabilityResult(
            summary: sprintf('已将 %d 篇文章按 %d 个目标站点提交到分发队列。', $articles->count(), count($channels)),
            payload: ['article_ids' => $articles->pluck('id')->all(), 'channel_ids' => $channels, 'queued_targets' => $queuedTargets],
            artifactType: 'distribution_enqueue',
            artifactName: '多站分发入队结果',
            sourceRoute: 'admin.distribution.jobs',
            sourceUrl: route('admin.distribution.jobs'),
        );
    }

    /** @param array<string,mixed> $parameters */
    private function hostedSitePreflight(array $parameters): AiCapabilityResult
    {
        $profile = HostedSiteProfile::query()->with('channel')->findOrFail((int) $parameters['hosted_site_id']);
        if (! $profile->channel instanceof DistributionChannel) {
            throw new RuntimeException('托管站点没有关联渠道。');
        }
        $result = $this->hostedSiteQuality->preflight($profile->channel);

        return new AiCapabilityResult(
            summary: $result['passed'] ? '托管站点预检通过。' : '托管站点预检未通过。',
            payload: $result,
            artifactType: 'hosted_site_preflight',
            artifactName: $profile->hostname.' 预检',
            sourceRoute: 'admin.distribution.hosted-sites.show',
            sourceUrl: route('admin.distribution.hosted-sites.show', ['hostedSite' => $profile->distribution_channel_id]),
        );
    }

    /** @param array<string,mixed> $parameters */
    private function siteSettingsSync(array $parameters, ?string $executionKey): AiCapabilityResult
    {
        if (! is_string($executionKey) || $executionKey === '') {
            throw new RuntimeException('外部操作缺少稳定执行键。');
        }
        $step = AiWorkspaceStep::query()->where('idempotency_key', $executionKey)->firstOrFail();
        $channelIds = array_values(array_unique(array_map('intval', (array) $parameters['channel_ids'])));
        $channels = DistributionChannel::query()
            ->with('activeSecret')
            ->whereIn('id', $channelIds)
            ->where('status', DistributionChannel::STATUS_ACTIVE)
            ->where(function ($query): void {
                $query->whereNull('channel_type')->orWhere('channel_type', DistributionChannel::TYPE_GEOFLOW_AGENT);
            })
            ->get();
        if ($channels->count() !== count($channelIds)) {
            throw new RuntimeException('部分站点不支持设置同步。');
        }

        $results = [];
        foreach ($channels as $channel) {
            $preview = $this->frontendInspector->syncPreviewForChannels(collect([$channel]));
            $operation = AiWorkspaceExternalOperation::query()
                ->where('execution_key', $executionKey)
                ->where('target_type', 'distribution_channel')
                ->where('target_id', (string) $channel->id)
                ->firstOrFail();
            $requestPayload = (array) $operation->request_payload;
            $settings = data_get($requestPayload, 'settings');
            if (! is_array($settings)
                || ! hash_equals((string) $operation->request_digest, AiPayloadDigest::make($requestPayload))
                || ! hash_equals((string) $operation->target_digest, AiPayloadDigest::make((array) $step->target_summary))) {
                throw new RuntimeException('外部操作账本校验失败。');
            }
            $alreadyConfirmed = $operation->status === 'confirmed';
            if ($alreadyConfirmed) {
                $remote = (array) data_get($operation->remote_result, 'remote_result', []);
            } else {
                if ($operation->status === 'dispatched') {
                    throw new AiOutcomeUnknownException('站点设置请求已经发出，远程结果尚未确认。');
                }
                try {
                    $remoteKey = $executionKey.':channel:'.(int) $channel->id;
                    $remote = $this->channelOperations->run(
                        $channel,
                        'site_settings_sync',
                        function (DistributionChannel $locked) use ($operation, $remoteKey, $settings, $requestPayload, $step): array {
                            $dispatchChannel = DB::transaction(function () use ($locked, $operation, $requestPayload, $step): DistributionChannel {
                                $this->dispatchGuard->assertExternalStepDispatchAllowed($step);
                                $freshChannel = DistributionChannel::query()
                                    ->whereKey((int) $locked->id)
                                    ->lockForUpdate()
                                    ->firstOrFail();
                                $activeSecret = DistributionChannelSecret::query()
                                    ->where('distribution_channel_id', (int) $freshChannel->id)
                                    ->where('status', 'active')
                                    ->latest('id')
                                    ->lockForUpdate()
                                    ->first();
                                $freshChannel->setRelation('activeSecret', $activeSecret);
                                $lockedOperation = AiWorkspaceExternalOperation::query()
                                    ->whereKey($operation->id)
                                    ->lockForUpdate()
                                    ->firstOrFail();
                                if ($lockedOperation->status !== 'prepared') {
                                    throw new AiOutcomeUnknownException('站点设置请求状态已经变化，需要先完成对账。');
                                }
                                if (! hash_equals((string) ($requestPayload['channel_revision'] ?? ''), $this->channelRevision($freshChannel))) {
                                    throw new RuntimeException('站点连接或设置在审批后已变化。');
                                }
                                $lockedOperation->forceFill([
                                    'status' => 'dispatched',
                                    'dispatched_at' => now(),
                                    'error_message' => null,
                                ])->save();

                                return $freshChannel;
                            });

                            return $this->publishers->forChannel($dispatchChannel)->syncSiteSettings($dispatchChannel, $remoteKey, $settings);
                        },
                    );
                } catch (\Throwable $exception) {
                    $operation->forceFill(['error_message' => AiWorkspaceErrorSanitizer::clean($exception->getMessage())])->save();
                    throw $exception;
                }
            }
            $refreshCount = $alreadyConfirmed ? (int) data_get($operation->remote_result, 'refresh_count', 0) : 0;
            $refreshWarning = $alreadyConfirmed ? data_get($operation->remote_result, 'refresh_warning') : null;
            if (! $alreadyConfirmed) {
                try {
                    $refreshCount = $this->distribution->enqueueChannelContentRefresh($channel);
                } catch (\Throwable $exception) {
                    report($exception);
                    $refreshWarning = '远程设置已同步，内容刷新队列需要人工检查。';
                }
            }
            $operation->forceFill([
                'status' => 'confirmed',
                'remote_result' => [
                    'remote_result' => $remote,
                    'refresh_count' => $refreshCount,
                    'refresh_warning' => $refreshWarning,
                ],
                'confirmed_at' => $operation->confirmed_at ?? now(),
                'error_message' => null,
            ])->save();
            $this->distribution->log('info', '目标站点设置已由 AI 工作台同步', (int) $channel->id, null, null, [
                'event' => 'site.settings.synced',
                'ai_workspace_execution_key' => $executionKey,
                'remote_result' => $remote,
                'refresh_count' => $refreshCount,
                'refresh_warning' => $refreshWarning,
                'sync_summary' => $this->frontendInspector->syncSummary($channel),
            ]);
            $results[] = [
                'channel_id' => (int) $channel->id,
                'refresh_count' => $refreshCount,
                'preview_required_confirmation' => (bool) ($preview['requires_confirmation'] ?? false),
                'remote_result' => $remote,
                'warning' => $refreshWarning,
            ];
        }

        return new AiCapabilityResult(
            summary: sprintf('已同步 %d 个目标站点的设置并提交内容刷新。', count($results)),
            payload: ['results' => $results],
            artifactType: 'site_settings_sync',
            artifactName: '站点设置同步结果',
            sourceRoute: 'admin.distribution.index',
            sourceUrl: route('admin.distribution.index'),
        );
    }

    private function channelRevision(DistributionChannel $channel): string
    {
        return AiWorkspaceChannelRevision::make($channel);
    }

    private function escapeLike(string $value): string
    {
        return str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $value);
    }
}
