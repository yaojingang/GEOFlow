<?php

namespace App\Data\Admin;

final readonly class AdminAiDependencySummary
{
    /** @param array{title_generation_runs: int, article_ai_optimization_runs: int, knowledge_fact_generation_runs: int, ai_workspace_runs: int, url_import_jobs: int, enterprise_knowledge_projects: int, total: int} $pendingTaskCounts */
    public function __construct(
        public int $ownedModelCount,
        public int $dependentAdminCount,
        public int $aiSettingCount,
        public array $pendingTaskCounts,
        public int $executionTaskCount = 0,
        public int $executionTaskRunCount = 0,
        public int $executionUrlImportJobCount = 0,
        public int $executionEnterpriseKnowledgeProjectCount = 0,
        public int $executionTitleGenerationRunCount = 0,
        public int $executionKnowledgeFactGenerationRunCount = 0,
        public int $executionAiWorkspaceRunCount = 0,
    ) {}

    public function blocksDeletion(): bool
    {
        return $this->ownedModelCount > 0
            || $this->dependentAdminCount > 0
            || $this->pendingTaskCounts['total'] > 0
            || $this->executionTaskCount > 0
            || $this->executionTaskRunCount > 0
            || $this->executionUrlImportJobCount > 0
            || $this->executionEnterpriseKnowledgeProjectCount > 0
            || $this->executionTitleGenerationRunCount > 0
            || $this->executionKnowledgeFactGenerationRunCount > 0
            || $this->executionAiWorkspaceRunCount > 0;
    }

    /** @return array<string, int> */
    public function counts(): array
    {
        return [
            'owned_model_count' => $this->ownedModelCount,
            'dependent_admin_count' => $this->dependentAdminCount,
            'ai_setting_count' => $this->aiSettingCount,
            'pending_task_count' => $this->pendingTaskCounts['total']
                + $this->executionTaskCount
                + $this->executionTaskRunCount
                + $this->executionUrlImportJobCount
                + $this->executionEnterpriseKnowledgeProjectCount
                + $this->executionTitleGenerationRunCount
                + $this->executionAiWorkspaceRunCount,
            'execution_task_count' => $this->executionTaskCount,
            'execution_task_run_count' => $this->executionTaskRunCount,
            'execution_url_import_job_count' => $this->executionUrlImportJobCount,
            'execution_enterprise_knowledge_project_count' => $this->executionEnterpriseKnowledgeProjectCount,
            'execution_title_generation_run_count' => $this->executionTitleGenerationRunCount,
            'execution_knowledge_fact_generation_run_count' => $this->executionKnowledgeFactGenerationRunCount,
            'execution_ai_workspace_run_count' => $this->executionAiWorkspaceRunCount,
        ];
    }
}
