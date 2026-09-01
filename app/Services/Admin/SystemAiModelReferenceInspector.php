<?php

namespace App\Services\Admin;

use App\Models\AiModel;
use App\Models\ArticleAiOptimizationStep;
use App\Models\ArticleAiQualityCheck;
use App\Models\EnterpriseKnowledgeProject;
use App\Models\KnowledgeFactGenerationRun;
use App\Models\SiteSetting;
use App\Models\SiteThemeReplication;
use App\Models\Task;
use App\Models\TitleGenerationRun;
use App\Models\TitleLibrary;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

final class SystemAiModelReferenceInspector
{
    private const SYSTEM_SETTING_KEYS = [
        'default_embedding_model_id',
        'knowledge_chunking_model_id',
        'ai_visibility_ark_model_id',
        'ai_visibility_deepseek_analysis_model_id',
    ];

    private const USER_CONTENT_REFERENCES = [
        Task::class => ['ai_model_id', 'ai_quality_model_id'],
        TitleLibrary::class => ['ai_model_id'],
        EnterpriseKnowledgeProject::class => ['ai_model_id'],
        SiteThemeReplication::class => ['ai_model_id'],
        TitleGenerationRun::class => ['ai_model_id'],
        ArticleAiQualityCheck::class => ['ai_model_id'],
        ArticleAiOptimizationStep::class => ['ai_model_id'],
        KnowledgeFactGenerationRun::class => ['ai_model_id'],
    ];

    /**
     * @return array{
     *   system_model_ids:list<int>,
     *   system_only_model_ids:list<int>,
     *   conflict_model_ids:list<int>,
     *   invalid_bindings:list<array{setting_key:string,reason:string}>
     * }
     */
    public function inspect(int $legacyOwnerId): array
    {
        [$systemModelIds, $validBindings, $invalidBindings] = $this->systemModelBindings();
        $models = AiModel::query()
            ->whereIn('id', $systemModelIds)
            ->get(['id', 'owner_admin_id'])
            ->keyBy('id');
        $existingIds = $models->keys()->map(static fn (mixed $id): int => (int) $id)->all();

        foreach ($validBindings as $binding) {
            if (! in_array($binding['model_id'], $existingIds, true)) {
                $invalidBindings[] = [
                    'setting_key' => $binding['setting_key'],
                    'reason' => 'model_not_found',
                ];
            }
        }

        $userContentIds = $this->userContentModelIds($existingIds);
        $conflicts = [];
        $systemOnly = [];
        foreach ($existingIds as $modelId) {
            $model = $models->get($modelId);
            $hasForeignOwner = $model?->owner_admin_id !== null
                && (int) $model->owner_admin_id !== $legacyOwnerId;
            if ($hasForeignOwner || in_array($modelId, $userContentIds, true)) {
                $conflicts[] = $modelId;
            } else {
                $systemOnly[] = $modelId;
            }
        }

        sort($systemModelIds);
        sort($systemOnly);
        sort($conflicts);
        usort($invalidBindings, static fn (array $left, array $right): int => [
            $left['setting_key'],
            $left['reason'],
        ] <=> [
            $right['setting_key'],
            $right['reason'],
        ]);

        return [
            'system_model_ids' => array_values(array_unique($systemModelIds)),
            'system_only_model_ids' => array_values(array_unique($systemOnly)),
            'conflict_model_ids' => array_values(array_unique($conflicts)),
            'invalid_bindings' => $invalidBindings,
        ];
    }

    /**
     * @return array{
     *   list<int>,
     *   list<array{setting_key:string,model_id:int}>,
     *   list<array{setting_key:string,reason:string}>
     * }
     */
    private function systemModelBindings(): array
    {
        $ids = [];
        $valid = [];
        $invalid = [];
        $settings = SiteSetting::query()
            ->whereIn('setting_key', self::SYSTEM_SETTING_KEYS)
            ->orderBy('setting_key')
            ->get(['setting_key', 'setting_value']);

        foreach ($settings as $setting) {
            $value = trim((string) $setting->setting_value);
            $modelId = filter_var($value, FILTER_VALIDATE_INT, [
                'options' => ['min_range' => 1, 'max_range' => PHP_INT_MAX],
            ]);
            if ($modelId === false || (string) $modelId !== $value) {
                $invalid[] = [
                    'setting_key' => (string) $setting->setting_key,
                    'reason' => 'invalid_model_id',
                ];

                continue;
            }

            $ids[] = $modelId;
            $valid[] = [
                'setting_key' => (string) $setting->setting_key,
                'model_id' => $modelId,
            ];
        }

        return [array_values(array_unique($ids)), $valid, $invalid];
    }

    /** @param list<int> $candidateIds
     * @return list<int>
     */
    private function userContentModelIds(array $candidateIds): array
    {
        if ($candidateIds === []) {
            return [];
        }

        $ids = [];
        foreach (self::USER_CONTENT_REFERENCES as $modelClass => $columns) {
            /** @var Model $model */
            $model = new $modelClass;
            $table = $model->getTable();
            if (! Schema::hasTable($table)) {
                continue;
            }

            foreach ($columns as $column) {
                if (! Schema::hasColumn($table, $column)) {
                    continue;
                }

                $ids = [
                    ...$ids,
                    ...DB::table($table)
                        ->whereIn($column, $candidateIds)
                        ->distinct()
                        ->pluck($column)
                        ->map(static fn (mixed $id): int => (int) $id)
                        ->all(),
                ];
            }
        }

        sort($ids);

        return array_values(array_unique($ids));
    }
}
