<?php

return [
    'runtime_enabled' => filter_var(env('GEOFLOW_AI_WORKSPACE_RUNTIME_ENABLED', false), FILTER_VALIDATE_BOOLEAN),
    'retention_days' => max(1, (int) env('GEOFLOW_AI_WORKSPACE_RETENTION_DAYS', 90)),
    'global_concurrency' => max(1, (int) env('GEOFLOW_AI_WORKSPACE_GLOBAL_CONCURRENCY', 10)),
    'concurrency_cache_store' => env('GEOFLOW_AI_WORKSPACE_CONCURRENCY_CACHE_STORE', 'redis'),
    'connection' => 'redis',
    'queue' => 'default',
    'interactive_connection' => 'redis',
    'interactive_queue' => 'default',
    'admin_daily_model_calls' => max(1, (int) env('GEOFLOW_AI_WORKSPACE_ADMIN_DAILY_MODEL_CALLS', 200)),
    'model_total_timeout_seconds' => min(90, max(15, (int) env('GEOFLOW_AI_WORKSPACE_MODEL_TOTAL_TIMEOUT', 90))),
    'model_attempt_timeout_seconds' => min(30, max(5, (int) env('GEOFLOW_AI_WORKSPACE_MODEL_ATTEMPT_TIMEOUT', 30))),
    'conversation_history_char_budget' => max(4000, (int) env('GEOFLOW_AI_WORKSPACE_HISTORY_CHAR_BUDGET', 10000)),
    'knowledge_evidence_char_budget' => 10000,
    'knowledge_evidence_limit' => 8,
    'knowledge_hybrid_min_score' => 0.18,
    'knowledge_hybrid_min_semantic_score' => 0.62,
    'turn_total_char_budget' => 24000,
    'conversation_generation_lease_seconds' => max(30, (int) env('GEOFLOW_AI_WORKSPACE_GENERATION_LEASE_SECONDS', 180)),
    'resolution_lease_minutes' => max(1, (int) env('GEOFLOW_AI_WORKSPACE_RESOLUTION_LEASE_MINUTES', 3)),
    'execution_lease_minutes' => max(1, (int) env('GEOFLOW_AI_WORKSPACE_EXECUTION_LEASE_MINUTES', 15)),
    'require_verified_model' => filter_var(env('GEOFLOW_AI_WORKSPACE_REQUIRE_VERIFIED_MODEL', true), FILTER_VALIDATE_BOOLEAN),
    'prompt_versions' => [
        'admin_help' => '2.0.0',
    ],
];
