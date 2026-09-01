<?php

namespace App\Services\Admin;

use App\Data\Admin\AdminAiDependencySummary;
use App\Data\Admin\AdminAiSharingImpact;
use App\Models\Admin;
use App\Models\AdminAiSetting;
use App\Models\AiModel;
use App\Models\AiWorkspaceRun;
use App\Models\ArticleAiOptimizationRun;
use App\Models\KnowledgeFactGenerationRun;
use App\Models\TitleGenerationRun;
use Illuminate\Support\Facades\Schema;

final class AdminAiDependencyInspector
{
    public function sharingImpact(Admin $admin, ?int $providerAdminId = null): AdminAiSharingImpact
    {
        $ownerId = $providerAdminId ?? $admin->shared_ai_config_owner_id;
        if ($ownerId === null) {
            return new AdminAiSharingImpact([], $this->pendingTaskCounts($admin));
        }

        $setting = AdminAiSetting::query()
            ->where('admin_id', $admin->getKey())
            ->first(['default_chat_model_id', 'default_embedding_model_id']);
        $defaultIds = array_values(array_unique(array_filter([
            $setting?->default_chat_model_id,
            $setting?->default_embedding_model_id,
        ], static fn (mixed $id): bool => $id !== null)));
        sort($defaultIds);

        $sharedDefaultIds = AiModel::query()
            ->whereIn('id', $defaultIds)
            ->where('owner_admin_id', $ownerId)
            ->orderBy('id')
            ->pluck('id')
            ->map(static fn (mixed $id): int => (int) $id)
            ->all();

        return new AdminAiSharingImpact($sharedDefaultIds, $this->pendingTaskCounts($admin));
    }

    public function deletionDependencies(Admin $admin): AdminAiDependencySummary
    {
        return new AdminAiDependencySummary(
            ownedModelCount: AiModel::query()->where('owner_admin_id', $admin->getKey())->count(),
            dependentAdminCount: Admin::query()
                ->where('shared_ai_config_owner_id', $admin->getKey())
                ->count(),
            aiSettingCount: AdminAiSetting::query()->where('admin_id', $admin->getKey())->count(),
            pendingTaskCounts: $this->pendingTaskCounts($admin),
        );
    }

    /**
     * @return array{
     *   title_generation_runs: int,
     *   article_ai_optimization_runs: int,
     *   knowledge_fact_generation_runs: int,
     *   ai_workspace_runs: int,
     *   total: int
     * }
     */
    public function pendingTaskCounts(Admin $admin): array
    {
        $counts = [
            'title_generation_runs' => $this->pendingTitleGenerationRunCount($admin),
            'article_ai_optimization_runs' => $this->pendingArticleAiOptimizationRunCount($admin),
            'knowledge_fact_generation_runs' => $this->pendingKnowledgeFactGenerationRunCount($admin),
            'ai_workspace_runs' => $this->pendingAiWorkspaceRunCount($admin),
        ];

        return [...$counts, 'total' => array_sum($counts)];
    }

    private function pendingTitleGenerationRunCount(Admin $admin): int
    {
        if (! $this->hasRequiredColumns((new TitleGenerationRun)->getTable(), [
            'created_by_admin_id',
            'status',
            'ai_model_id',
            'failure_code',
            'manual_retry_count',
        ])) {
            return 0;
        }

        return TitleGenerationRun::query()
            ->where('created_by_admin_id', $admin->getKey())
            ->where(function ($query): void {
                $query->whereIn('status', [
                    TitleGenerationRun::STATUS_QUEUED,
                    TitleGenerationRun::STATUS_RUNNING,
                ])->orWhere(function ($retryable): void {
                    $retryable
                        ->whereIn('status', [
                            TitleGenerationRun::STATUS_PARTIAL,
                            TitleGenerationRun::STATUS_FAILED,
                            TitleGenerationRun::STATUS_CANCELLED,
                        ])
                        ->whereNotNull('ai_model_id')
                        ->where(function ($failure): void {
                            $failure
                                ->whereNull('failure_code')
                                ->orWhere('failure_code', '!=', 'request_budget_exhausted');
                        })
                        ->where('manual_retry_count', '<', (int) config('geoflow.title_ai_max_manual_retries', 3));
                });
            })
            ->count();
    }

    private function pendingArticleAiOptimizationRunCount(Admin $admin): int
    {
        if (! $this->hasRequiredColumns((new ArticleAiOptimizationRun)->getTable(), [
            'requested_by_admin_id',
            'status',
        ])) {
            return 0;
        }

        return ArticleAiOptimizationRun::query()
            ->where('requested_by_admin_id', $admin->getKey())
            ->whereIn('status', ArticleAiOptimizationRun::ACTIVE_STATUSES)
            ->count();
    }

    private function pendingKnowledgeFactGenerationRunCount(Admin $admin): int
    {
        if (! $this->hasRequiredColumns((new KnowledgeFactGenerationRun)->getTable(), [
            'created_by_admin_id',
            'status',
        ])) {
            return 0;
        }

        return KnowledgeFactGenerationRun::query()
            ->where('created_by_admin_id', $admin->getKey())
            ->whereIn('status', KnowledgeFactGenerationRun::ACTIVE_STATUSES)
            ->count();
    }

    private function pendingAiWorkspaceRunCount(Admin $admin): int
    {
        if (! $this->hasRequiredColumns((new AiWorkspaceRun)->getTable(), [
            'admin_id',
            'state',
        ])) {
            return 0;
        }

        return AiWorkspaceRun::query()
            ->where('admin_id', $admin->getKey())
            ->whereNotIn('state', AiWorkspaceRun::TERMINAL_STATES)
            ->count();
    }

    /**
     * @param  list<string>  $columns
     */
    private function hasRequiredColumns(string $table, array $columns): bool
    {
        return Schema::hasTable($table) && Schema::hasColumns($table, $columns);
    }
}
