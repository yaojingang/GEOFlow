<?php

namespace App\Services\Admin;

use App\Models\AdminAiAccessShadowEvent;
use App\Models\AiModel;
use App\Models\AiModelUsageEvent;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

final class AdminAiAccessShadowReportService
{
    /**
     * @var array<string,list<string>>
     */
    private const IDENTITY_TABLES = [
        'tasks' => ['model_access_admin_id', 'model_access_admin_role'],
        'task_runs' => ['model_access_admin_id', 'model_access_admin_role', 'ai_config_access_version', 'resolver_policy_version'],
        'url_import_jobs' => ['model_access_admin_id', 'model_access_admin_role', 'ai_config_access_version', 'resolver_policy_version'],
        'enterprise_knowledge_projects' => ['model_access_admin_id', 'model_access_admin_role', 'ai_config_access_version', 'resolver_policy_version'],
        'title_generation_runs' => ['model_access_admin_id', 'model_access_admin_role', 'ai_config_access_version', 'resolver_policy_version'],
        'ai_workspace_runs' => ['model_access_admin_id', 'model_access_admin_role', 'ai_config_access_version', 'resolver_policy_version'],
        'knowledge_fact_generation_runs' => ['model_access_admin_id', 'model_access_admin_role', 'ai_config_access_version', 'resolver_policy_version'],
    ];

    /** @return array<string,mixed> */
    public function report(int $hours): array
    {
        $hours = max(1, min(24 * 90, $hours));
        $since = CarbonImmutable::now()->subHours($hours);
        $shadow = AdminAiAccessShadowEvent::query()->where('created_at', '>=', $since);
        $resolutionCount = (clone $shadow)->count();
        $mismatchCount = (clone $shadow)->whereIn('comparison', [
            AdminAiAccessShadowEvent::COMPARISON_DIFFERENT,
            AdminAiAccessShadowEvent::COMPARISON_SAFE_MISSING,
            AdminAiAccessShadowEvent::COMPARISON_LEGACY_MISSING,
        ])->count();
        $sharedUsage = AiModelUsageEvent::query()
            ->where('created_at', '>=', $since)
            ->where('model_source', AiModelUsageEvent::MODEL_SOURCE_SHARED)
            ->where('status', AiModelUsageEvent::STATUS_SUCCEEDED);
        $identityGaps = $this->identityGaps();

        return [
            'window_hours' => $hours,
            'generated_at' => now()->toIso8601String(),
            'shadow' => [
                'enabled' => (bool) config('geoflow.admin_ai_access.shadow_enabled', true),
                'resolution_count' => $resolutionCount,
                'preferred_model_mismatch_count' => $mismatchCount,
                'preferred_model_mismatch_rate' => $resolutionCount > 0
                    ? round($mismatchCount / $resolutionCount, 6)
                    : 0.0,
                'safe_model_missing_count' => (clone $shadow)
                    ->where('comparison', AdminAiAccessShadowEvent::COMPARISON_SAFE_MISSING)
                    ->count(),
                'events_with_inaccessible_legacy_models' => (clone $shadow)
                    ->where('inaccessible_legacy_model_count', '>', 0)
                    ->count(),
                'inaccessible_legacy_model_observations' => (int) (clone $shadow)
                    ->sum('inaccessible_legacy_model_count'),
            ],
            'identity' => [
                'models_without_owner' => AiModel::query()->whereNull('owner_admin_id')->count(),
                'gap_count' => array_sum($identityGaps),
                'gaps_by_table' => $identityGaps,
            ],
            'shared_usage' => [
                'shared_success_count' => (clone $sharedUsage)->count(),
                'shared_total_tokens' => (int) (clone $sharedUsage)->sum('total_tokens'),
                'distinct_execution_admins' => (clone $sharedUsage)
                    ->whereNotNull('execution_admin_id')
                    ->distinct()
                    ->count('execution_admin_id'),
                'distinct_config_owners' => (clone $sharedUsage)
                    ->distinct()
                    ->count('config_owner_admin_id'),
            ],
        ];
    }

    /** @return array<string,int> */
    private function identityGaps(): array
    {
        $counts = [];
        foreach (self::IDENTITY_TABLES as $table => $columns) {
            if (! Schema::hasTable($table) || ! Schema::hasColumns($table, $columns)) {
                continue;
            }

            $counts[$table] = DB::table($table)
                ->where(function (Builder $query) use ($columns): void {
                    foreach ($columns as $index => $column) {
                        $method = $index === 0 ? 'whereNull' : 'orWhereNull';
                        $query->{$method}($column);
                    }
                })
                ->count();
        }

        return $counts;
    }
}
