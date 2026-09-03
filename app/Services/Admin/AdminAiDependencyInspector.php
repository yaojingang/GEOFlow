<?php

namespace App\Services\Admin;

use App\Data\Admin\AdminAiDependencySummary;
use App\Data\Admin\AdminAiSharingImpact;
use App\Models\Admin;
use App\Models\AdminAiSetting;
use App\Models\AiModel;
use App\Models\AiWorkspaceRun;
use App\Models\ArticleAiOptimizationRun;
use App\Models\EnterpriseKnowledgeProject;
use App\Models\KnowledgeFactGenerationRun;
use App\Models\Task;
use App\Models\TaskRun;
use App\Models\TitleGenerationRun;
use App\Models\UrlImportJob;
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
            executionTaskCount: $this->executionTaskCount($admin),
            executionTaskRunCount: $this->executionTaskRunCount($admin),
            executionUrlImportJobCount: $this->executionUrlImportJobCount($admin),
            executionEnterpriseKnowledgeProjectCount: $this->executionEnterpriseKnowledgeProjectCount($admin),
            executionTitleGenerationRunCount: $this->executionTitleGenerationRunCount($admin),
            executionKnowledgeFactGenerationRunCount: $this->pendingKnowledgeFactGenerationRunCount($admin),
            executionAiWorkspaceRunCount: $this->executionAiWorkspaceRunCount($admin),
        );
    }

    /**
     * @return array{
     *   title_generation_runs: int,
     *   article_ai_optimization_runs: int,
     *   knowledge_fact_generation_runs: int,
     *   ai_workspace_runs: int,
     *   url_import_jobs: int,
     *   enterprise_knowledge_projects: int,
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
            'url_import_jobs' => $this->pendingUrlImportJobCount($admin),
            'enterprise_knowledge_projects' => $this->pendingEnterpriseKnowledgeProjectCount($admin),
        ];

        return [...$counts, 'total' => array_sum($counts)];
    }

    private function pendingTitleGenerationRunCount(Admin $admin): int
    {
        $table = (new TitleGenerationRun)->getTable();
        if (! $this->hasRequiredColumns($table, [
            'created_by_admin_id',
            'status',
        ])) {
            return 0;
        }
        $availableColumns = array_fill_keys(Schema::getColumnListing($table), true);
        $hasModelAccessAdminId = isset($availableColumns['model_access_admin_id']);
        $hasAiModelId = isset($availableColumns['ai_model_id']);
        $hasFailureCode = isset($availableColumns['failure_code']);
        $hasManualRetryCount = isset($availableColumns['manual_retry_count']);

        $query = TitleGenerationRun::query();
        if ($hasModelAccessAdminId) {
            $query->where(function ($identity) use ($admin): void {
                $identity->where('model_access_admin_id', $admin->getKey())
                    ->orWhere(function ($legacy) use ($admin): void {
                        $legacy->whereNull('model_access_admin_id')
                            ->where('created_by_admin_id', $admin->getKey());
                    });
            });
        } else {
            $query->where('created_by_admin_id', $admin->getKey());
        }

        return $query
            ->where(function ($query) use ($hasAiModelId, $hasFailureCode, $hasManualRetryCount): void {
                $query->whereIn('status', [
                    TitleGenerationRun::STATUS_QUEUED,
                    TitleGenerationRun::STATUS_RUNNING,
                ]);
                $query->orWhere(function ($retryable) use ($hasAiModelId, $hasFailureCode, $hasManualRetryCount): void {
                    $retryable->whereIn('status', [
                        TitleGenerationRun::STATUS_PARTIAL,
                        TitleGenerationRun::STATUS_FAILED,
                        TitleGenerationRun::STATUS_CANCELLED,
                    ]);
                    if ($hasAiModelId) {
                        $retryable->whereNotNull('ai_model_id');
                    }
                    if ($hasFailureCode) {
                        $retryable->where(function ($failure): void {
                            $failure
                                ->whereNull('failure_code')
                                ->orWhere('failure_code', '!=', 'request_budget_exhausted');
                        });
                    }
                    if ($hasManualRetryCount) {
                        $retryable->where('manual_retry_count', '<', (int) config('geoflow.title_ai_max_manual_retries', 3));
                    }
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
        $table = (new KnowledgeFactGenerationRun)->getTable();
        if (! $this->hasRequiredColumns($table, [
            'created_by_admin_id',
            'status',
        ])) {
            return 0;
        }
        $hasModelAccessAdminId = Schema::hasColumn($table, 'model_access_admin_id');
        $hasRetryableFailure = Schema::hasColumn($table, 'retryable_failure');

        $query = KnowledgeFactGenerationRun::query();
        if ($hasModelAccessAdminId) {
            $query->where(function ($identity) use ($admin): void {
                $identity->where('model_access_admin_id', $admin->getKey())
                    ->orWhere(function ($legacy) use ($admin): void {
                        $legacy->whereNull('model_access_admin_id')
                            ->where('created_by_admin_id', $admin->getKey());
                    });
            });
        } else {
            $query->where('created_by_admin_id', $admin->getKey());
        }

        return $query
            ->where(function ($state) use ($hasRetryableFailure): void {
                $state->whereIn('status', KnowledgeFactGenerationRun::ACTIVE_STATUSES);
                if ($hasRetryableFailure) {
                    $state->orWhere(function ($retryable): void {
                        $retryable->whereIn('status', [
                            KnowledgeFactGenerationRun::STATUS_FAILED,
                            'partial',
                        ])->where('retryable_failure', true);
                    });
                }
            })
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

    private function pendingUrlImportJobCount(Admin $admin): int
    {
        if (! $this->hasRequiredColumns((new UrlImportJob)->getTable(), [
            'model_access_admin_id',
            'status',
            'retryable_failure',
        ])) {
            return 0;
        }

        return UrlImportJob::query()
            ->where('model_access_admin_id', $admin->getKey())
            ->where(function ($query): void {
                $query->whereIn('status', ['queued', 'running'])
                    ->orWhere(function ($retryable): void {
                        $retryable->where('status', 'failed')
                            ->where('retryable_failure', true);
                    });
            })
            ->count();
    }

    private function pendingEnterpriseKnowledgeProjectCount(Admin $admin): int
    {
        if (! $this->hasRequiredColumns((new EnterpriseKnowledgeProject)->getTable(), [
            'model_access_admin_id',
            'status',
            'retryable_failure',
        ])) {
            return 0;
        }

        return EnterpriseKnowledgeProject::query()
            ->where('model_access_admin_id', $admin->getKey())
            ->where(function ($query): void {
                $query->whereIn('status', ['queued', 'processing'])
                    ->orWhere(function ($retryable): void {
                        $retryable->where('status', 'failed')
                            ->where('retryable_failure', true);
                    });
            })
            ->count();
    }

    private function executionTaskCount(Admin $admin): int
    {
        if (! $this->hasRequiredColumns((new Task)->getTable(), ['model_access_admin_id'])) {
            return 0;
        }

        return Task::withTrashed()
            ->where('model_access_admin_id', $admin->getKey())
            ->count();
    }

    private function executionTaskRunCount(Admin $admin): int
    {
        if (! $this->hasRequiredColumns((new TaskRun)->getTable(), ['model_access_admin_id'])) {
            return 0;
        }

        return TaskRun::query()
            ->where('model_access_admin_id', $admin->getKey())
            ->count();
    }

    private function executionUrlImportJobCount(Admin $admin): int
    {
        if (! $this->hasRequiredColumns((new UrlImportJob)->getTable(), ['model_access_admin_id'])) {
            return 0;
        }

        return UrlImportJob::query()
            ->where('model_access_admin_id', $admin->getKey())
            ->count();
    }

    private function executionEnterpriseKnowledgeProjectCount(Admin $admin): int
    {
        if (! $this->hasRequiredColumns((new EnterpriseKnowledgeProject)->getTable(), ['model_access_admin_id'])) {
            return 0;
        }

        return EnterpriseKnowledgeProject::query()
            ->where('model_access_admin_id', $admin->getKey())
            ->count();
    }

    private function executionTitleGenerationRunCount(Admin $admin): int
    {
        if (! $this->hasRequiredColumns((new TitleGenerationRun)->getTable(), ['model_access_admin_id'])) {
            return 0;
        }

        return TitleGenerationRun::query()
            ->where('model_access_admin_id', $admin->getKey())
            ->count();
    }

    private function executionAiWorkspaceRunCount(Admin $admin): int
    {
        if (! $this->hasRequiredColumns((new AiWorkspaceRun)->getTable(), ['model_access_admin_id'])) {
            return 0;
        }

        return AiWorkspaceRun::query()
            ->where('model_access_admin_id', $admin->getKey())
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
