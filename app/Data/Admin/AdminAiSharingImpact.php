<?php

namespace App\Data\Admin;

final readonly class AdminAiSharingImpact
{
    /**
     * @param  list<int>  $sharedDefaultModelIds
     * @param  array{title_generation_runs: int, article_ai_optimization_runs: int, knowledge_fact_generation_runs: int, ai_workspace_runs: int, url_import_jobs: int, enterprise_knowledge_projects: int, total: int}  $pendingTaskCounts
     */
    public function __construct(
        public array $sharedDefaultModelIds,
        public array $pendingTaskCounts,
    ) {}

    public function sharedDefaultCount(): int
    {
        return count($this->sharedDefaultModelIds);
    }
}
