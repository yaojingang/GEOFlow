<?php

namespace App\Console\Commands;

use App\Models\Article;
use App\Models\ArticleAiQualityRollout;
use App\Models\KnowledgeBase;
use App\Services\GeoFlow\AiQualityAuditService;
use App\Services\GeoFlow\ArticleAiQualityInvalidationService;
use App\Services\GeoFlow\ArticleAiQualityRolloutPolicy;
use App\Services\GeoFlow\ArticleAiQualityVersionPolicy;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Throwable;

class ManageArticleAiQualityRolloutCommand extends Command
{
    protected $signature = 'geoflow:ai-quality-rollout
        {action=status : status, promote, rollback, freeze, unfreeze, incident, sample-on, sample-off}
        {--track= : principles, execution, scoring, shadow, atomic-shadow, atomic-fact}
        {--to= : Target rollout stage}
        {--report= : End-to-end evaluation JSON report}
        {--incident= : Major-risk incident code}';

    protected $description = 'Manage guarded AI quality rollout stages and the sampled-release emergency switch';

    public function __construct(
        private readonly ArticleAiQualityRolloutPolicy $policy,
        private readonly ArticleAiQualityVersionPolicy $versionPolicy,
        private readonly ArticleAiQualityInvalidationService $invalidation,
        private readonly AiQualityAuditService $auditService,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $action = strtolower(trim((string) $this->argument('action')));
        if ($action === 'status') {
            $this->line(json_encode($this->policy->state(), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

            return self::SUCCESS;
        }

        $rollout = $this->policy->ensureState();
        $before = $this->auditState($rollout);
        $requestedTrack = strtolower(trim((string) $this->option('track')));
        if ($action === 'incident') {
            $incident = trim((string) $this->option('incident'));
            if ($incident === '') {
                $this->components->error('Provide --incident with a stable incident code.');

                return self::INVALID;
            }
            $rollout = $this->commitTransition($rollout, $before, [
                'frozen' => true,
                'sampled_auto_release_enabled' => false,
                'incident_code' => mb_substr($incident, 0, 120, 'UTF-8'),
            ], 'incident', 'AI 质检 rollout 已因重大风险事件冻结', incident: $incident);
            $this->policy->forget();

            return $this->finish('Rollout frozen and sampled auto-release disabled.');
        }
        if ($action === 'freeze') {
            if ($requestedTrack === 'atomic-fact') {
                $rollout = $this->commitTransition(
                    $rollout,
                    $before,
                    ['atomic_fact_frozen' => true],
                    'freeze',
                    '原子质检 rollout 已冻结',
                    'atomic-fact',
                );
                $this->policy->forget();

                return $this->finish('Atomic fact rollout frozen.');
            }
            $rollout = $this->commitTransition($rollout, $before, [
                'frozen' => true,
                'sampled_auto_release_enabled' => false,
            ], 'freeze', 'AI 质检 rollout 已冻结');
            $this->policy->forget();

            return $this->finish('Rollout frozen and sampled auto-release disabled.');
        }
        if ($action === 'unfreeze') {
            if ($requestedTrack === 'atomic-fact') {
                $rollout = $this->commitTransition(
                    $rollout,
                    $before,
                    ['atomic_fact_frozen' => false],
                    'unfreeze',
                    '原子质检 rollout 已解除冻结',
                    'atomic-fact',
                );
                $this->policy->forget();

                return $this->finish('Atomic fact rollout unfrozen.');
            }
            $transitionAttributes = [];
            if (trim((string) $rollout->incident_code) !== '') {
                $report = $this->verifiedReport();
                if ($report === null) {
                    return self::FAILURE;
                }
                $transitionAttributes += [
                    'latest_evaluation_path' => $this->portablePath((string) $this->option('report')),
                    'latest_evaluation_at' => $report['_generated_at'],
                    'incident_code' => null,
                ];
            }
            $rollout = $this->commitTransition(
                $rollout,
                $before,
                $transitionAttributes + ['frozen' => false],
                'unfreeze',
                'AI 质检 rollout 已解除冻结',
            );
            $this->policy->forget();

            return $this->finish('Rollout unfrozen.');
        }
        if (in_array($action, ['sample-on', 'sample-off'], true)) {
            if ($action === 'sample-on' && (bool) $rollout->frozen) {
                $this->components->error('A frozen rollout cannot enable sampled auto-release.');

                return self::FAILURE;
            }
            $rollout = $this->commitTransition(
                $rollout,
                $before,
                ['sampled_auto_release_enabled' => $action === 'sample-on'],
                $action,
                'AI 质检抽样自动放行策略已调整',
            );
            $this->policy->forget();

            return $this->finish('Sampled auto-release setting updated.');
        }
        if (! in_array($action, ['promote', 'rollback'], true)) {
            $this->components->error('Unsupported action.');

            return self::INVALID;
        }

        $track = strtolower(trim((string) $this->option('track')));
        $columns = [
            'principles' => 'principle_percent',
            'execution' => 'execution_percent',
            'scoring' => 'scoring_percent',
            'shadow' => 'shadow_percent',
            'atomic-shadow' => 'atomic_shadow_percent',
            'atomic-fact' => 'atomic_fact_percent',
        ];
        if (! isset($columns[$track])) {
            $this->components->error('Provide --track=principles|execution|scoring|shadow|atomic-shadow|atomic-fact.');

            return self::INVALID;
        }
        $target = filter_var($this->option('to'), FILTER_VALIDATE_INT);
        if ($target === false || ! $this->policy->validStage((int) $target)) {
            $this->components->error('The target must be one of 0, 10, 25, 50, or 100.');

            return self::INVALID;
        }
        $column = $columns[$track];
        $current = (int) $rollout->{$column};
        $target = (int) $target;
        if ($action === 'rollback') {
            if ($target >= $current) {
                $this->components->error('Rollback target must be below the current stage.');

                return self::INVALID;
            }
        } else {
            $currentIndex = array_search($current, ArticleAiQualityRolloutPolicy::STAGES, true);
            $targetIndex = array_search($target, ArticleAiQualityRolloutPolicy::STAGES, true);
            if ($currentIndex === false
                || $targetIndex === false
                || (bool) $rollout->frozen
                || $targetIndex !== $currentIndex + 1) {
                $this->components->error('Promotion requires an unfrozen rollout and the next predefined stage.');

                return self::FAILURE;
            }
            $report = $this->verifiedReport($track);
            if ($report === null) {
                return self::FAILURE;
            }
            $transitionAttributes = [
                'latest_evaluation_path' => $this->portablePath((string) $this->option('report')),
                'latest_evaluation_at' => $report['_generated_at'],
                'incident_code' => null,
            ];
        }

        $transitionAttributes ??= [];
        $rollout = $this->commitTransition(
            $rollout,
            $before,
            $transitionAttributes + [$column => $target],
            $action,
            "AI 质检 {$track} 灰度由 {$current}% 调整为 {$target}%",
            $track,
            $current,
            $target,
            report: trim((string) $this->option('report')),
        );
        $this->policy->forget();

        return $this->finish(ucfirst($track)." rollout moved from {$current}% to {$target}%.");
    }

    /** @return array<string,mixed>|null */
    private function verifiedReport(?string $track = null): ?array
    {
        $path = $this->absolutePath(trim((string) $this->option('report')));
        if ($path === '' || ! File::isFile($path)) {
            $this->components->error('A readable --report evaluation JSON file is required.');

            return null;
        }
        $report = json_decode((string) File::get($path), true);
        $generatedAt = null;
        if (is_array($report)) {
            try {
                $generatedAt = CarbonImmutable::parse((string) ($report['generated_at'] ?? ''));
            } catch (Throwable) {
                $generatedAt = null;
            }
        }
        $expectedIds = [449, 467, 471, 473, 486];
        $activeLibrary = KnowledgeBase::query()->with('factLibrary')->find(23)?->factLibrary;
        $localAtomicReport = app()->environment('local')
            && in_array($track, ['atomic-shadow', 'atomic-fact'], true)
            && ($report['mode'] ?? null) === 'live'
            && ($report['evaluation_scope'] ?? null) === 'local_atomic_comparison'
            && (int) ($report['schema_version'] ?? 0) >= 2
            && (int) ($report['knowledge_base_id'] ?? 0) === 23
            && (int) data_get($report, 'model.id', 0) === 3
            && data_get($report, 'case_set.version') === 'kb23-five-articles-v1'
            && data_get($report, 'case_set.article_ids') === $expectedIds
            && hash_equals(hash('sha256', 'kb23-five-articles-v1|'.implode(',', $expectedIds)), (string) data_get($report, 'case_set.sha256', ''))
            && (int) data_get($report, 'metrics.article_count', 0) === 5
            && (int) data_get($report, 'metrics.call_count', 0) === 30
            && (int) data_get($report, 'metrics.repeat', 0) === 3
            && $generatedAt?->gte(now()->subDay())
            && $activeLibrary !== null
            && (int) data_get($report, 'atomic_revision.id', 0) === (int) $activeLibrary->active_revision_id
            && hash_equals((string) $activeLibrary->active_hash, (string) data_get($report, 'atomic_revision.library_hash', ''))
            && hash_equals((string) $activeLibrary->source_hash, (string) data_get($report, 'atomic_revision.source_hash', ''))
            && ($track === 'atomic-shadow' || ((bool) ($report['local_atomic_gate_ready'] ?? false)
                && ! in_array(false, (array) data_get($report, 'metrics.gate_checks', []), true)));
        $valid = $localAtomicReport || (is_array($report)
            && ($report['mode'] ?? null) === 'live'
            && ($report['evaluation_scope'] ?? null) === 'production_components'
            && (bool) ($report['production_gate_ready'] ?? false)
            && (bool) data_get($report, 'gate_checks.end_to_end_latency', false)
            && (bool) data_get($report, 'gate_checks.repeat_stability', false)
            && (int) data_get($report, 'metrics.by_inspection_scope.fallback_sampled.case_count', 0) > 0
            && $generatedAt?->gte(now()->subDays(30)));
        if (! $valid) {
            $this->components->error('The report is not a recent passing end-to-end live evaluation.');

            return null;
        }

        $report['_generated_at'] = $generatedAt;

        return $report;
    }

    private function finish(string $message): int
    {
        $this->policy->forget();
        $this->components->info($message);

        return self::SUCCESS;
    }

    /** @param array<string,mixed> $before @param array<string,mixed> $attributes */
    private function commitTransition(
        ArticleAiQualityRollout $rollout,
        array $before,
        array $attributes,
        string $action,
        string $invalidationReason,
        ?string $track = null,
        ?int $from = null,
        ?int $to = null,
        ?string $incident = null,
        ?string $report = null,
    ): ArticleAiQualityRollout {
        return DB::transaction(function () use ($rollout, $before, $attributes, $action, $invalidationReason, $track, $from, $to, $incident, $report): ArticleAiQualityRollout {
            $locked = ArticleAiQualityRollout::query()->whereKey((int) $rollout->id)->lockForUpdate()->firstOrFail();
            if (max(1, (int) $locked->epoch) !== max(1, (int) $before['epoch'])) {
                throw new \RuntimeException('ai_quality_rollout_state_changed');
            }
            $locked->forceFill($attributes + [
                'epoch' => max(1, (int) $locked->epoch) + 1,
            ])->save();
            $this->recordTransition($action, $before, $locked, $track, $from, $to, $incident, $report);
            $this->invalidation->invalidateRolloutEpoch(
                (int) $before['epoch'],
                $invalidationReason,
                $track === 'atomic-fact',
            );

            return $locked;
        });
    }

    private function absolutePath(string $path): string
    {
        if ($path === '') {
            return '';
        }

        return str_starts_with($path, DIRECTORY_SEPARATOR) ? $path : base_path($path);
    }

    private function portablePath(string $path): string
    {
        $absolute = $this->absolutePath($path);
        $prefix = rtrim(base_path(), DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR;

        return str_starts_with($absolute, $prefix) ? substr($absolute, strlen($prefix)) : $absolute;
    }

    /** @return list<int> */
    private function affectedArticleIds(string $track, int $from, int $to): array
    {
        $minimum = min($from, $to);
        $maximum = max($from, $to);
        if ($minimum === $maximum) {
            return [];
        }

        $ids = [];
        Article::query()
            ->where(function ($query): void {
                $query->where('ai_quality_required_at_creation', true)
                    ->orWhereHas('task', fn ($task) => $task->where('ai_quality_enabled', true));
            })
            ->select('id')
            ->orderBy('id')
            ->lazyById(500)
            ->each(function (Article $article) use (&$ids, $track, $minimum, $maximum): void {
                $bucket = str_starts_with($track, 'atomic-')
                    ? $this->policy->atomicBucket((int) $article->id)
                    : $this->versionPolicy->bucketForTrack((int) $article->id, $track);
                if ($bucket >= $minimum && $bucket < $maximum) {
                    $ids[] = (int) $article->id;
                }
            });

        return $ids;
    }

    /** @return array<string,mixed> */
    private function auditState(ArticleAiQualityRollout $rollout): array
    {
        return [
            'epoch' => max(1, (int) $rollout->epoch),
            'principle_percent' => (int) $rollout->principle_percent,
            'execution_percent' => (int) $rollout->execution_percent,
            'scoring_percent' => (int) $rollout->scoring_percent,
            'shadow_percent' => (int) $rollout->shadow_percent,
            'atomic_shadow_percent' => (int) $rollout->atomic_shadow_percent,
            'atomic_fact_percent' => (int) $rollout->atomic_fact_percent,
            'atomic_fact_frozen' => (bool) $rollout->atomic_fact_frozen,
            'sampled_auto_release_enabled' => (bool) $rollout->sampled_auto_release_enabled,
            'frozen' => (bool) $rollout->frozen,
            'incident_code' => $rollout->incident_code,
        ];
    }

    private function recordTransition(
        string $action,
        array $before,
        ArticleAiQualityRollout $rollout,
        ?string $track = null,
        ?int $from = null,
        ?int $to = null,
        ?string $incident = null,
        ?string $report = null,
    ): void {
        DB::table('article_ai_quality_rollout_events')->insert([
            'action' => $action,
            'track' => $track,
            'from_percent' => $from,
            'to_percent' => $to,
            'incident_code' => $incident === null ? null : mb_substr($incident, 0, 120, 'UTF-8'),
            'evaluation_path' => $report === null || $report === '' ? null : $this->portablePath($report),
            'before_state' => json_encode($before, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
            'after_state' => json_encode($this->auditState($rollout), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
            'created_at' => now(),
        ]);
        $after = $this->auditState($rollout);
        $this->auditService->record('ai_quality_rollout_'.$action, [
            'policy_version' => (int) $rollout->epoch,
            'before_hash' => hash('sha256', json_encode($before, JSON_THROW_ON_ERROR)),
            'after_hash' => hash('sha256', json_encode($after, JSON_THROW_ON_ERROR)),
            'reason_code' => $incident ?: $track ?: $action,
            'metadata' => [
                'action' => $action,
                'track' => $track,
                'from_percent' => $from,
                'to_percent' => $to,
                'epoch' => (int) $rollout->epoch,
            ],
        ]);
    }
}
