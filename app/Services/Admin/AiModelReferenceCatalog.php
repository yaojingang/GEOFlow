<?php

namespace App\Services\Admin;

use App\Models\AdminAiSetting;
use App\Models\AiVisibilityRun;
use App\Models\AiWorkspaceRun;
use App\Models\Article;
use App\Models\ArticleAiOptimizationRun;
use App\Models\ArticleAiOptimizationStep;
use App\Models\ArticleAiQualityCheck;
use App\Models\EnterpriseKnowledgeProject;
use App\Models\KnowledgeFactGenerationRun;
use App\Models\SiteThemeReplication;
use App\Models\Task;
use App\Models\TaskRun;
use App\Models\TitleGenerationRun;
use App\Models\TitleLibrary;

final class AiModelReferenceCatalog
{
    public const SYSTEM_SETTING_KEYS = [
        'default_embedding_model_id',
        'knowledge_chunking_model_id',
        'ai_visibility_ark_model_id',
        'ai_visibility_deepseek_analysis_model_id',
    ];

    /** @var array<class-string, list<string>> */
    public const USER_CONTENT_REFERENCES = [
        AdminAiSetting::class => ['default_chat_model_id', 'default_embedding_model_id'],
        Task::class => ['ai_model_id', 'ai_quality_model_id'],
        TitleLibrary::class => ['ai_model_id'],
        EnterpriseKnowledgeProject::class => ['ai_model_id', 'requested_ai_model_id', 'resolved_ai_model_id'],
        SiteThemeReplication::class => ['ai_model_id'],
        TitleGenerationRun::class => ['ai_model_id', 'requested_ai_model_id', 'resolved_ai_model_id'],
        ArticleAiQualityCheck::class => ['ai_model_id'],
        ArticleAiOptimizationStep::class => ['ai_model_id'],
        KnowledgeFactGenerationRun::class => [
            'ai_model_id',
            'requested_ai_model_id',
            'resolved_ai_model_id',
        ],
        AiWorkspaceRun::class => ['requested_ai_model_id', 'resolved_ai_model_id'],
    ];

    /**
     * Persisted JSON paths containing ai_models.id values that can still select a
     * model for a future user-content call. Rows in an unknown status are treated
     * as active by the inspector so a newly introduced executable state cannot be
     * silently reclassified as system-only.
     *
     * @var list<array{
     *   model:class-string,
     *   json_column:string,
     *   paths:list<array{path:string,many:bool}>,
     *   status_column?:string,
     *   active_statuses?:list<string>,
     *   terminal_statuses?:list<string>,
     *   retryable_statuses?:list<string>,
     *   retryable_boolean_column?:string,
     *   active_boolean_column?:string,
     *   active_parent_guard?:array{
     *     model:class-string,
     *     foreign_key:string,
     *     parent_key:string,
     *     active_columns:array<string, scalar>,
     *     active_null_columns:list<string>
     *   }
     * }>
     */
    public const STRUCTURED_USER_CONTENT_REFERENCES = [
        [
            'model' => ArticleAiOptimizationRun::class,
            'json_column' => 'execution_meta',
            'paths' => [
                ['path' => 'optimization_model_id', 'many' => false],
                ['path' => 'optimization_model_ids', 'many' => true],
                ['path' => 'quality_model_candidate_ids', 'many' => true],
                ['path' => 'quality_policy_snapshot.model_id', 'many' => false],
            ],
            'status_column' => 'status',
            'active_statuses' => ArticleAiOptimizationRun::ACTIVE_STATUSES,
            'terminal_statuses' => ArticleAiOptimizationRun::TERMINAL_STATUSES,
        ],
        [
            'model' => ArticleAiQualityCheck::class,
            'json_column' => 'execution_meta',
            'paths' => [
                ['path' => 'model_candidate_ids', 'many' => true],
                ['path' => 'policy_snapshot.model_id', 'many' => false],
            ],
            'status_column' => 'status',
            'active_statuses' => ['queued', 'running', 'failed'],
            'terminal_statuses' => ['completed', 'stale', 'cancelled'],
        ],
        [
            'model' => ArticleAiQualityCheck::class,
            'json_column' => 'model_snapshot',
            'paths' => [
                ['path' => 'candidate_ids', 'many' => true],
            ],
            'status_column' => 'status',
            'active_statuses' => ['queued', 'running', 'failed'],
            'terminal_statuses' => ['completed', 'stale', 'cancelled'],
        ],
        [
            'model' => Article::class,
            'json_column' => 'ai_quality_policy_snapshot',
            'paths' => [
                ['path' => 'model_id', 'many' => false],
            ],
            'active_boolean_column' => 'ai_quality_required_at_creation',
            'active_parent_guard' => [
                'model' => Task::class,
                'foreign_key' => 'task_id',
                'parent_key' => 'id',
                'active_columns' => ['ai_quality_enabled' => true],
                'active_null_columns' => ['deleted_at'],
            ],
        ],
        [
            'model' => KnowledgeFactGenerationRun::class,
            'json_column' => 'batch_claims_json',
            'paths' => [
                ['path' => '*.resolved_ai_model_id', 'many' => true],
            ],
            'status_column' => 'status',
            'active_statuses' => KnowledgeFactGenerationRun::ACTIVE_STATUSES,
            'terminal_statuses' => [
                KnowledgeFactGenerationRun::STATUS_COMPLETED,
                KnowledgeFactGenerationRun::STATUS_CANCELLED,
                KnowledgeFactGenerationRun::STATUS_OBSOLETE,
            ],
            'retryable_statuses' => [KnowledgeFactGenerationRun::STATUS_FAILED, 'partial'],
            'retryable_boolean_column' => 'retryable_failure',
        ],
    ];

    /**
     * Audited model identifiers that are deliberately excluded from ai_models.id
     * matching. These paths persist provider-facing names or completed-call
     * telemetry and must never be parsed as database keys.
     *
     * @var array<class-string, list<string>>
     */
    public const NON_DATABASE_MODEL_IDENTIFIERS = [
        ArticleAiQualityCheck::class => ['model_snapshot.model_id'],
        AiVisibilityRun::class => [
            'analysis_json.model_id',
            'raw_request_json.model',
            'raw_response_json.model',
        ],
    ];

    /**
     * Audited database IDs retained only as completed-call history. Their owning
     * scalar columns or active candidate snapshots cover every future call.
     *
     * @var array<class-string, list<string>>
     */
    public const NON_BLOCKING_DATABASE_ID_HISTORY = [
        ArticleAiQualityCheck::class => [
            'model_snapshot.id',
            'execution_meta.model_attempts.*.model_id',
        ],
        ArticleAiOptimizationStep::class => [
            'execution_meta.model_attempts.*.model_id',
            'usage_meta.model_attempts.*.model_id',
        ],
        ArticleAiOptimizationRun::class => [
            'execution_meta.model_attempts.*.model_id',
            'usage_meta.model_attempts.*.model_id',
        ],
        TaskRun::class => [
            'meta.used_model_id',
            'meta.model_attempts.*.model_id',
        ],
        KnowledgeFactGenerationRun::class => [
            'ai_model_id',
            'requested_ai_model_id',
            'resolved_ai_model_id',
            'batch_claims_json.*.resolved_ai_model_id',
        ],
    ];
}
