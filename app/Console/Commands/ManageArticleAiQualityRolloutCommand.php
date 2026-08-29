<?php

namespace App\Console\Commands;

use App\Models\Article;
use App\Models\ArticleAiQualityRollout;
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
        {--track= : principles, execution, scoring, shadow}
        {--to= : Target rollout stage}
        {--report= : End-to-end evaluation JSON report}
        {--incident= : Major-risk incident code}';

    protected $description = 'Manage guarded AI quality rollout stages and the sampled-release emergency switch';

    public function __construct(
        private readonly ArticleAiQualityRolloutPolicy $policy,
        private readonly ArticleAiQualityVersionPolicy $versionPolicy,
        private readonly ArticleAiQualityInvalidationService $invalidation,
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
        if ($action === 'incident') {
            $incident = trim((string) $this->option('incident'));
            if ($incident === '') {
                $this->components->error('Provide --incident with a stable incident code.');

                return self::INVALID;
            }
            DB::transaction(function () use ($rollout, $incident, $before): void {
                $rollout->forceFill([
                    'frozen' => true,
                    'sampled_auto_release_enabled' => false,
                    'incident_code' => mb_substr($incident, 0, 120, 'UTF-8'),
                ])->save();
                $this->recordTransition('incident', $before, $rollout, incident: $incident);
            });

            return $this->finish('Rollout frozen and sampled auto-release disabled.');
        }
        if ($action === 'freeze') {
            DB::transaction(function () use ($rollout, $before): void {
                $rollout->forceFill(['frozen' => true, 'sampled_auto_release_enabled' => false])->save();
                $this->recordTransition('freeze', $before, $rollout);
            });

            return $this->finish('Rollout frozen and sampled auto-release disabled.');
        }
        if ($action === 'unfreeze') {
            if (trim((string) $rollout->incident_code) !== '') {
                $report = $this->verifiedReport();
                if ($report === null) {
                    return self::FAILURE;
                }
                $rollout->forceFill([
                    'latest_evaluation_path' => $this->portablePath((string) $this->option('report')),
                    'latest_evaluation_at' => $report['_generated_at'],
                    'incident_code' => null,
                ]);
            }
            DB::transaction(function () use ($rollout, $before): void {
                $rollout->forceFill(['frozen' => false])->save();
                $this->recordTransition('unfreeze', $before, $rollout);
            });

            return $this->finish('Rollout unfrozen.');
        }
        if (in_array($action, ['sample-on', 'sample-off'], true)) {
            if ($action === 'sample-on' && (bool) $rollout->frozen) {
                $this->components->error('A frozen rollout cannot enable sampled auto-release.');

                return self::FAILURE;
            }
            DB::transaction(function () use ($rollout, $action, $before): void {
                $rollout->forceFill(['sampled_auto_release_enabled' => $action === 'sample-on'])->save();
                $this->recordTransition($action, $before, $rollout);
            });

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
        ];
        if (! isset($columns[$track])) {
            $this->components->error('Provide --track=principles|execution|scoring|shadow.');

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
            $report = $this->verifiedReport();
            if ($report === null) {
                return self::FAILURE;
            }
            $rollout->forceFill([
                'latest_evaluation_path' => $this->portablePath((string) $this->option('report')),
                'latest_evaluation_at' => $report['_generated_at'],
                'incident_code' => null,
            ]);
        }

        $affectedArticleIds = $this->affectedArticleIds($track, $current, $target);
        DB::transaction(function () use ($rollout, $column, $target, $action, $track, $current, $before, $affectedArticleIds): void {
            $rollout->forceFill([$column => $target])->save();
            $this->invalidation->invalidateArticles(
                $affectedArticleIds,
                "AI 质检 {$track} 灰度由 {$current}% 调整为 {$target}%",
            );
            $this->recordTransition(
                $action,
                $before,
                $rollout,
                $track,
                $current,
                $target,
                report: trim((string) $this->option('report')),
            );
        });

        return $this->finish(ucfirst($track)." rollout moved from {$current}% to {$target}%.");
    }

    /** @return array<string,mixed>|null */
    private function verifiedReport(): ?array
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
        $valid = is_array($report)
            && ($report['mode'] ?? null) === 'live'
            && ($report['evaluation_scope'] ?? null) === 'production_components'
            && (bool) ($report['production_gate_ready'] ?? false)
            && (bool) data_get($report, 'gate_checks.end_to_end_latency', false)
            && (bool) data_get($report, 'gate_checks.repeat_stability', false)
            && (int) data_get($report, 'metrics.by_inspection_scope.fallback_sampled.case_count', 0) > 0
            && $generatedAt?->gte(now()->subDays(30));
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
                $bucket = $this->versionPolicy->bucketForTrack((int) $article->id, $track);
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
            'principle_percent' => (int) $rollout->principle_percent,
            'execution_percent' => (int) $rollout->execution_percent,
            'scoring_percent' => (int) $rollout->scoring_percent,
            'shadow_percent' => (int) $rollout->shadow_percent,
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
    }
}
