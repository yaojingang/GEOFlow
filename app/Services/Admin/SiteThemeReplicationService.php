<?php

namespace App\Services\Admin;

use App\Models\AiModel;
use App\Models\SiteThemeReplication;
use App\Models\SiteThemeReplicationLog;
use App\Models\SiteThemeReplicationVersion;
use App\Services\Admin\SiteThemeReplication\ThemeComplianceGuard;
use App\Services\Admin\SiteThemeReplication\ThemeReplicationStorageGuard;
use App\Services\Admin\SiteThemeReplication\ThemeReplicationStorageLock;
use App\Services\Admin\SiteThemeReplication\ThemeScaffoldWriter;
use App\Services\Outbound\OutboundRequestBlockedException;
use App\Services\Outbound\OutboundRequestFailedException;
use App\Services\Outbound\SafeOutboundHttpClient;
use App\Support\Site\SiteThemeCatalog;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use InvalidArgumentException;
use RuntimeException;

class SiteThemeReplicationService
{
    public function __construct(
        private readonly SiteThemeCatalog $themeCatalog,
        private readonly ThemeScaffoldWriter $writer,
        private readonly ThemeComplianceGuard $guard,
        private readonly ThemeReplicationStorageGuard $storageGuard,
        private readonly ThemeReplicationStorageLock $storageLock,
        private readonly SafeOutboundHttpClient $safeHttp,
    ) {}

    /**
     * @param  array{name:string,theme_id:string,base_theme_id:?string,ai_model_id:int,home_url:string,category_url:string,article_url:string,style_preference:string,created_by_admin_id:?int}  $payload
     */
    public function create(array $payload): SiteThemeReplication
    {
        if (! $this->isSchemaReady()) {
            throw new RuntimeException(__('admin.theme_replication.message.migration_required'));
        }

        $normalizedUrls = $this->normalizeReferenceUrls([
            'home_url' => (string) $payload['home_url'],
            'category_url' => (string) $payload['category_url'],
            'article_url' => (string) $payload['article_url'],
        ]);

        $themeId = $this->normalizeThemeId((string) $payload['theme_id']);
        $baseThemeId = trim((string) ($payload['base_theme_id'] ?? ''));

        $replication = SiteThemeReplication::query()->create([
            'name' => trim((string) $payload['name']),
            'theme_id' => $themeId,
            'base_theme_id' => $baseThemeId !== '' ? $baseThemeId : null,
            'ai_model_id' => (int) $payload['ai_model_id'],
            'status' => SiteThemeReplication::STATUS_QUEUED,
            'home_url' => $normalizedUrls['home_url'],
            'category_url' => $normalizedUrls['category_url'],
            'article_url' => $normalizedUrls['article_url'],
            'style_preference' => $this->normalizeStylePreference((string) ($payload['style_preference'] ?? 'content_site')),
            'source_fingerprints' => $this->buildSourceFingerprints($normalizedUrls),
            'compliance_status' => 'pending',
            'created_by_admin_id' => $payload['created_by_admin_id'] ?? null,
        ]);

        $this->log($replication, 'info', 'created', __('admin.theme_replication.log.created'), [
            'home_url' => $replication->home_url,
            'category_url' => $replication->category_url,
            'article_url' => $replication->article_url,
        ]);

        $this->log($replication, 'info', 'queued', __('admin.theme_replication.log.queued'));

        return $replication->fresh(['logs', 'aiModel']) ?? $replication;
    }

    public function retry(SiteThemeReplication $replication): SiteThemeReplication
    {
        if ((string) $replication->status !== SiteThemeReplication::STATUS_FAILED) {
            return $replication;
        }

        $replication->forceFill([
            'status' => SiteThemeReplication::STATUS_QUEUED,
            'error_message' => null,
        ])->save();

        $this->log($replication, 'info', 'retry', __('admin.theme_replication.log.retry'));

        return $replication->fresh(['logs', 'aiModel']) ?? $replication;
    }

    /**
     * @param  array{name:string,theme_id:string,created_by_admin_id:?int}  $payload
     */
    public function duplicateAsNewTheme(SiteThemeReplication $source, array $payload): SiteThemeReplication
    {
        if (! $source->isPreviewReady()) {
            throw new RuntimeException(__('admin.theme_replication.error.preview_unavailable'));
        }

        $themeId = $this->normalizeThemeId((string) $payload['theme_id']);
        if ($this->themeIdExists($themeId)) {
            throw new RuntimeException(__('admin.theme_replication.validation.theme_id_exists'));
        }

        $version = $source->versions()->latest('version')->first();
        if (! $version instanceof SiteThemeReplicationVersion) {
            throw new RuntimeException(__('admin.theme_replication.error.no_draft_version'));
        }

        $blueprint = (array) $version->blueprint_json;
        $blueprint['theme'] = array_merge((array) ($blueprint['theme'] ?? []), [
            'name' => trim((string) $payload['name']),
            'id' => $themeId,
        ]);
        $blueprint['notes'] = array_values(array_filter(array_merge((array) ($blueprint['notes'] ?? []), [
            __('admin.theme_replication.copy.copied_from', ['theme' => (string) $source->theme_id]),
        ])));

        $copy = SiteThemeReplication::query()->create([
            'name' => trim((string) $payload['name']),
            'theme_id' => $themeId,
            'base_theme_id' => (string) $source->theme_id,
            'ai_model_id' => (int) $source->ai_model_id,
            'status' => SiteThemeReplication::STATUS_GENERATING,
            'home_url' => (string) $source->home_url,
            'category_url' => (string) $source->category_url,
            'article_url' => (string) $source->article_url,
            'style_preference' => (string) $source->style_preference,
            'source_fingerprints' => array_merge((array) $source->source_fingerprints, [
                'copied_from_replication_id' => (int) $source->id,
                'copied_from_theme_id' => (string) $source->theme_id,
                'copied_at' => now()->toIso8601String(),
            ]),
            'analysis_json' => $source->analysis_json,
            'compliance_status' => 'pending',
            'created_by_admin_id' => $payload['created_by_admin_id'] ?? null,
        ]);

        $files = $this->writer->write($copy, 1, $blueprint);
        $complianceReport = $this->guard->scan($files);
        if (empty($complianceReport['passed'])) {
            $copy->forceFill([
                'status' => SiteThemeReplication::STATUS_FAILED,
                'error_message' => __('admin.theme_replication.error.compliance_failed'),
                'compliance_status' => 'failed',
                'compliance_report_json' => $complianceReport,
            ])->save();
            $this->log($copy, 'error', 'failed', __('admin.theme_replication.error.compliance_failed'), $complianceReport);

            throw new RuntimeException(__('admin.theme_replication.error.compliance_failed'));
        }

        SiteThemeReplicationVersion::query()->create([
            'replication_id' => (int) $copy->id,
            'version' => 1,
            'prompt_hash' => hash('sha256', json_encode([
                'copied_from' => (int) $source->id,
                'blueprint' => $blueprint,
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: ''),
            'feedback' => __('admin.theme_replication.copy.feedback'),
            'blueprint_json' => $blueprint,
            'files_json' => $files,
            'compliance_report_json' => $complianceReport,
            'draft_views_path' => (string) ($files['views_path'] ?? ''),
            'draft_assets_path' => (string) ($files['assets_path'] ?? ''),
        ]);

        $copy->forceFill([
            'status' => SiteThemeReplication::STATUS_READY,
            'generated_files_json' => $files,
            'preview_snapshot_json' => [
                'version' => 1,
                'pages' => ['home', 'category', 'article'],
                'ready_at' => now()->toIso8601String(),
                'copied_from' => (int) $source->id,
            ],
            'current_version' => 1,
            'compliance_status' => 'passed',
            'compliance_report_json' => $complianceReport,
            'error_message' => null,
        ])->save();

        $this->log($copy, 'info', 'copied', __('admin.theme_replication.log.copied'), [
            'source_replication_id' => (int) $source->id,
            'source_theme_id' => (string) $source->theme_id,
        ]);

        return $copy->fresh(['logs', 'aiModel', 'versions']) ?? $copy;
    }

    public function archive(SiteThemeReplication $replication): SiteThemeReplication
    {
        if (! $replication->canBeArchived()) {
            throw new RuntimeException(__('admin.theme_replication.message.archive_unavailable'));
        }

        $replication->forceFill([
            'status' => SiteThemeReplication::STATUS_ARCHIVED,
            'error_message' => null,
        ])->save();

        $this->log($replication, 'info', 'archived', __('admin.theme_replication.log.archived'));

        return $replication->fresh(['logs', 'aiModel', 'versions']) ?? $replication;
    }

    public function deleteDrafts(SiteThemeReplication $replication): SiteThemeReplication
    {
        if (! $replication->canDeleteDrafts()) {
            throw new RuntimeException(__('admin.theme_replication.message.delete_drafts_unavailable'));
        }

        $replicationId = $this->storageGuard->positiveInteger($replication->id);

        return $this->storageLock->run($replicationId, function () use ($replication, $replicationId): SiteThemeReplication {
            $current = $replication->fresh();
            if (! $current instanceof SiteThemeReplication || ! $current->canDeleteDrafts()) {
                throw new RuntimeException(__('admin.theme_replication.message.delete_drafts_unavailable'));
            }

            $this->storageGuard->deleteStorageDirectory("geoflow-theme-replications/{$replicationId}/draft");
            $this->storageGuard->deleteStorageDirectory("geoflow-theme-replications-preview/{$replicationId}");
            $this->storageGuard->deleteFrameworkDirectory("geoflow-theme-replications-preview/{$replicationId}");

            $current->forceFill([
                'generated_files_json' => null,
                'preview_snapshot_json' => null,
            ])->save();

            $this->log($current, 'info', 'drafts_deleted', __('admin.theme_replication.log.drafts_deleted'));

            return $current->fresh(['logs', 'aiModel', 'versions']) ?? $current;
        });
    }

    /**
     * @return array{base:SiteThemeReplicationVersion|null,target:SiteThemeReplicationVersion|null,rows:array<int,array{path:string,status:string,old_size:int,new_size:int}>,counts:array{added:int,modified:int,removed:int,unchanged:int}}
     */
    public function versionDiff(SiteThemeReplication $replication): array
    {
        $versions = $replication->versions()->latest('version')->limit(2)->get();
        $target = $versions->first();
        $base = $versions->get(1);

        $oldFiles = $base instanceof SiteThemeReplicationVersion ? $this->fileMap((array) $base->files_json) : [];
        $newFiles = $target instanceof SiteThemeReplicationVersion ? $this->fileMap((array) $target->files_json) : [];
        $paths = array_values(array_unique(array_merge(array_keys($oldFiles), array_keys($newFiles))));
        sort($paths);

        $rows = [];
        $counts = ['added' => 0, 'modified' => 0, 'removed' => 0, 'unchanged' => 0];
        foreach ($paths as $path) {
            $old = $oldFiles[$path] ?? null;
            $new = $newFiles[$path] ?? null;
            $status = match (true) {
                $old === null => 'added',
                $new === null => 'removed',
                (string) ($old['checksum'] ?? '') !== (string) ($new['checksum'] ?? '') => 'modified',
                default => 'unchanged',
            };
            $counts[$status]++;
            $rows[] = [
                'path' => $path,
                'status' => $status,
                'old_size' => (int) ($old['bytes'] ?? 0),
                'new_size' => (int) ($new['bytes'] ?? 0),
            ];
        }

        return [
            'base' => $base instanceof SiteThemeReplicationVersion ? $base : null,
            'target' => $target instanceof SiteThemeReplicationVersion ? $target : null,
            'rows' => $rows,
            'counts' => $counts,
        ];
    }

    /**
     * @return array{type:string,title:string,description:string,actions:array<int,string>}|null
     */
    public function failureAdvice(SiteThemeReplication $replication): ?array
    {
        if ((string) $replication->status !== SiteThemeReplication::STATUS_FAILED) {
            return null;
        }

        $latestLog = $replication->logs()->where('level', 'error')->latest('id')->first();
        $message = mb_strtolower((string) ($replication->error_message ?: $latestLog?->message ?: ''));
        $context = (array) ($latestLog?->context_json ?? []);
        $hasViolations = isset($context['violations']) && is_array($context['violations']);

        $type = match (true) {
            $hasViolations || str_contains($message, 'compliance') || str_contains($message, '安全') || str_contains($message, 'scan') => 'scan',
            str_contains($message, 'ai') || str_contains($message, 'api') || str_contains($message, 'model') || str_contains($message, '模型') => 'ai',
            str_contains($message, '草稿') || str_contains($message, 'file') || str_contains($message, 'source') || str_contains($message, 'zip') => 'file',
            str_contains($message, 'http') || str_contains($message, 'url') || str_contains($message, '抓取') || str_contains($message, 'fetch') => 'fetch',
            default => 'unknown',
        };

        return [
            'type' => $type,
            'title' => __('admin.theme_replication.failure.'.$type.'_title'),
            'description' => __('admin.theme_replication.failure.'.$type.'_desc'),
            'actions' => (array) __('admin.theme_replication.failure.'.$type.'_actions'),
        ];
    }

    /**
     * @return Collection<int, AiModel>
     */
    public function activeChatModels(): Collection
    {
        return AiModel::query()
            ->where('status', 'active')
            ->whereRaw("COALESCE(NULLIF(model_type, ''), 'chat') = 'chat'")
            ->orderBy('name')
            ->orderByDesc('id')
            ->get(['id', 'name', 'model_id', 'model_type']);
    }

    /**
     * @return Collection<int, SiteThemeReplication>
     */
    public function recent(int $limit = 3): Collection
    {
        if (! Schema::hasTable('site_theme_replications')) {
            return new Collection;
        }

        return SiteThemeReplication::query()
            ->with('aiModel')
            ->latest('id')
            ->limit($limit)
            ->get();
    }

    /**
     * @param  array<string, string>  $urls
     * @return array<string, string>
     */
    public function normalizeReferenceUrls(array $urls): array
    {
        $normalized = [];
        foreach ($urls as $key => $url) {
            $normalized[$key] = $this->normalizeReferenceUrl($url);
        }

        return $normalized;
    }

    public function normalizeThemeId(string $value): string
    {
        $themeId = Str::slug(Str::lower(trim($value)), '-');
        if ($themeId === '' || ! preg_match('/^[a-z0-9][a-z0-9_-]{1,78}[a-z0-9]$/', $themeId)) {
            throw new InvalidArgumentException(__('admin.theme_replication.validation.theme_id_invalid'));
        }

        return $themeId;
    }

    public function themeIdExists(string $themeId): bool
    {
        if (in_array($themeId, $this->themeCatalog->ids(), true)) {
            return true;
        }

        if (! Schema::hasTable('site_theme_replications')) {
            return false;
        }

        return SiteThemeReplication::query()->where('theme_id', $themeId)->exists();
    }

    public function isSchemaReady(): bool
    {
        return Schema::hasTable('site_theme_replications')
            && Schema::hasTable('site_theme_replication_logs')
            && Schema::hasTable('site_theme_replication_versions');
    }

    public function isCatalogThemeId(string $themeId): bool
    {
        return in_array($themeId, $this->themeCatalog->ids(), true);
    }

    /**
     * @param  iterable<int, SiteThemeReplicationLog>|null  $logs
     * @return array<string, mixed>
     */
    public function progressSnapshot(SiteThemeReplication $replication, ?iterable $logs = null): array
    {
        $logItems = collect($logs ?? $replication->logs()->oldest('id')->limit(100)->get())->values();
        $loggedSteps = $logItems
            ->pluck('step')
            ->filter()
            ->map(static fn ($step): string => (string) $step)
            ->values()
            ->all();

        $isIteration = in_array((string) $replication->status, [SiteThemeReplication::STATUS_ITERATING], true)
            || in_array('iteration_queued', $loggedSteps, true)
            || in_array('iterating', $loggedSteps, true);
        $hasReferenceSteps = array_intersect(['fetching', 'extracting'], $loggedSteps) !== [];

        $stageKeys = match (true) {
            $isIteration && $hasReferenceSteps => ['created', 'queued', 'iterating', 'fetching', 'extracting', 'analyzing', 'generating', 'scanning', 'ready'],
            $isIteration => ['created', 'queued', 'iterating', 'analyzing', 'generating', 'scanning', 'ready'],
            default => ['created', 'queued', 'fetching', 'extracting', 'analyzing', 'generating', 'scanning', 'ready'],
        };

        $statusStep = $this->statusToProgressStep((string) $replication->status, $logItems);
        $currentIndex = array_search($statusStep, $stageKeys, true);
        if ($currentIndex === false) {
            $currentIndex = max(0, count($stageKeys) - 1);
        }

        $status = (string) $replication->status;
        $isFailed = $status === SiteThemeReplication::STATUS_FAILED;
        $isTerminal = in_array($status, [
            SiteThemeReplication::STATUS_READY,
            SiteThemeReplication::STATUS_PUBLISHED,
            SiteThemeReplication::STATUS_ARCHIVED,
            SiteThemeReplication::STATUS_FAILED,
        ], true);

        $stages = [];
        foreach ($stageKeys as $index => $stageKey) {
            $state = 'pending';
            if ($isFailed && $index === $currentIndex) {
                $state = 'failed';
            } elseif ($isTerminal && ! $isFailed) {
                $state = 'done';
            } elseif ($index < $currentIndex) {
                $state = 'done';
            } elseif ($index === $currentIndex) {
                $state = 'current';
            }

            $log = $this->firstLogForStep($logItems, $stageKey);
            $stages[] = [
                'key' => $stageKey,
                'label' => __('admin.theme_replication.progress.step.'.$stageKey),
                'description' => __('admin.theme_replication.progress.step_desc.'.$stageKey),
                'state' => $state,
                'time' => $log ? optional($log->created_at)->format('H:i:s') : null,
                'message' => $log ? (string) $log->message : null,
            ];
        }

        if ($isTerminal && ! $isFailed) {
            $progressPercent = 100;
        } else {
            $progressPercent = (int) round(($currentIndex / max(1, count($stageKeys) - 1)) * 100);
            if (! $isFailed) {
                $progressPercent = min(96, max(5, $progressPercent));
            }
        }

        $latestLog = $logItems->sortByDesc('id')->first();
        $lastUpdatedAt = $latestLog instanceof SiteThemeReplicationLog ? $latestLog->created_at : null;
        if ($replication->updated_at && (! $lastUpdatedAt || $replication->updated_at->gt($lastUpdatedAt))) {
            $lastUpdatedAt = $replication->updated_at;
        }

        return [
            'status' => $status,
            'status_label' => __('admin.theme_replication.status.'.$status),
            'current_step' => $statusStep,
            'current_step_label' => __('admin.theme_replication.progress.step.'.$statusStep),
            'progress_percent' => $progressPercent,
            'terminal' => $isTerminal,
            'failed' => $isFailed,
            'last_updated' => optional($lastUpdatedAt)->format('Y-m-d H:i:s'),
            'stages' => $stages,
            'logs' => $logItems
                ->sortByDesc('id')
                ->take(20)
                ->values()
                ->map(static fn (SiteThemeReplicationLog $log): array => [
                    'id' => (int) $log->id,
                    'level' => (string) $log->level,
                    'step' => (string) $log->step,
                    'message' => (string) $log->message,
                    'time' => optional($log->created_at)->format('Y-m-d H:i:s'),
                ])
                ->all(),
        ];
    }

    public function log(SiteThemeReplication $replication, string $level, string $step, string $message, array $context = []): SiteThemeReplicationLog
    {
        return SiteThemeReplicationLog::query()->create([
            'replication_id' => (int) $replication->id,
            'level' => $level,
            'step' => $step,
            'message' => $message,
            'context_json' => $context !== [] ? $context : null,
        ]);
    }

    private function statusToProgressStep(string $status, \Illuminate\Support\Collection $logs): string
    {
        if ($status === SiteThemeReplication::STATUS_FAILED) {
            $latestAction = $logs
                ->reverse()
                ->first(static fn (SiteThemeReplicationLog $log): bool => ! in_array((string) $log->step, ['', 'failed'], true));

            return $latestAction instanceof SiteThemeReplicationLog
                ? $this->normalizeProgressStep((string) $latestAction->step)
                : 'queued';
        }

        return match ($status) {
            SiteThemeReplication::STATUS_FETCHING => 'fetching',
            SiteThemeReplication::STATUS_EXTRACTING => 'extracting',
            SiteThemeReplication::STATUS_ANALYZING => 'analyzing',
            SiteThemeReplication::STATUS_GENERATING => 'generating',
            SiteThemeReplication::STATUS_SCANNING => 'scanning',
            SiteThemeReplication::STATUS_ITERATING => 'iterating',
            SiteThemeReplication::STATUS_READY,
            SiteThemeReplication::STATUS_PUBLISHED,
            SiteThemeReplication::STATUS_ARCHIVED => 'ready',
            default => 'queued',
        };
    }

    private function normalizeProgressStep(string $step): string
    {
        return match ($step) {
            'iteration_queued', 'iterating' => 'iterating',
            'ready', 'published', 'package_created', 'copied' => 'ready',
            'fetching', 'extracting', 'analyzing', 'generating', 'scanning', 'created', 'queued' => $step,
            default => 'queued',
        };
    }

    private function firstLogForStep(\Illuminate\Support\Collection $logs, string $step): ?SiteThemeReplicationLog
    {
        $aliases = match ($step) {
            'iterating' => ['iteration_queued', 'iterating'],
            'ready' => ['ready', 'published', 'package_created'],
            default => [$step],
        };

        $log = $logs->first(static fn (SiteThemeReplicationLog $item): bool => in_array((string) $item->step, $aliases, true));

        return $log instanceof SiteThemeReplicationLog ? $log : null;
    }

    private function normalizeReferenceUrl(string $url): string
    {
        $url = trim($url);
        if ($url === '') {
            throw new InvalidArgumentException(__('admin.theme_replication.validation.url_required'));
        }

        if (! filter_var($url, FILTER_VALIDATE_URL)) {
            throw new InvalidArgumentException(__('admin.theme_replication.validation.url_invalid'));
        }

        $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));
        if (! in_array($scheme, ['http', 'https'], true)) {
            throw new InvalidArgumentException(__('admin.theme_replication.validation.url_scheme'));
        }

        try {
            $this->safeHttp->resolveTarget($url);
        } catch (OutboundRequestBlockedException|OutboundRequestFailedException) {
            throw new InvalidArgumentException(__('admin.theme_replication.validation.url_private'));
        }

        return $url;
    }

    private function normalizeStylePreference(string $value): string
    {
        return in_array($value, ['content_site', 'brand_site', 'news_site'], true)
            ? $value
            : 'content_site';
    }

    /**
     * @param  array<string, string>  $urls
     * @return array<string, mixed>
     */
    private function buildSourceFingerprints(array $urls): array
    {
        return [
            'urls' => $urls,
            'domains' => array_values(array_unique(array_map(
                static fn (string $url): string => strtolower((string) parse_url($url, PHP_URL_HOST)),
                array_values($urls)
            ))),
            'created_at' => now()->toIso8601String(),
        ];
    }

    /**
     * @param  array<string, mixed>  $filesJson
     * @return array<string, array<string, mixed>>
     */
    private function fileMap(array $filesJson): array
    {
        $map = [];
        foreach ((array) ($filesJson['files'] ?? []) as $file) {
            $path = (string) ($file['path'] ?? '');
            if ($path !== '') {
                $map[$path] = (array) $file;
            }
        }

        return $map;
    }
}
