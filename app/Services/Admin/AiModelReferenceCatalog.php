<?php

namespace App\Services\Admin;

use App\Models\AdminAiSetting;
use App\Models\ArticleAiOptimizationStep;
use App\Models\ArticleAiQualityCheck;
use App\Models\EnterpriseKnowledgeProject;
use App\Models\KnowledgeFactGenerationRun;
use App\Models\SiteThemeReplication;
use App\Models\Task;
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
        EnterpriseKnowledgeProject::class => ['ai_model_id'],
        SiteThemeReplication::class => ['ai_model_id'],
        TitleGenerationRun::class => ['ai_model_id'],
        ArticleAiQualityCheck::class => ['ai_model_id'],
        ArticleAiOptimizationStep::class => ['ai_model_id'],
        KnowledgeFactGenerationRun::class => ['ai_model_id'],
    ];
}
