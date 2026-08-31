<?php

namespace App\Http\Requests\Api;

use App\Exceptions\ApiException;
use App\Services\GeoFlow\TaskDistributionChannelSelector;
use App\Support\GeoFlow\AiQualityRetrievalMode;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;

class UpdateTaskRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string,list<mixed>> */
    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'string', 'max:200'],
            'title_library_id' => ['sometimes', 'integer', 'min:1'],
            'image_library_id' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'image_count' => ['sometimes', 'integer', 'min:0', 'max:5'],
            'prompt_id' => ['sometimes', 'integer', 'min:1'],
            'ai_model_id' => ['sometimes', 'integer', 'min:1'],
            'author_id' => ['sometimes', 'nullable', 'integer', 'min:0'],
            'knowledge_base_id' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'knowledge_base_ids' => ['sometimes', 'array', 'max:5'],
            'knowledge_base_ids.*' => ['integer', 'min:1', 'distinct'],
            'fixed_category_id' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'status' => ['sometimes', 'string', 'in:active,paused'],
            'article_limit' => ['sometimes', 'integer', 'min:1', 'max:99999'],
            'draft_limit' => ['sometimes', 'integer', 'min:1', 'max:9999'],
            'publish_interval' => ['sometimes', 'integer', 'min:60'],
            'category_mode' => ['sometimes', 'string', 'in:smart,fixed'],
            'model_selection_mode' => ['sometimes', 'string', 'in:fixed,smart_failover'],
            'publish_scope' => ['sometimes', 'string', 'in:local_and_distribution,distribution_only,local_only'],
            'distribution_strategy' => ['sometimes', 'string', 'in:'.implode(',', TaskDistributionChannelSelector::strategies())],
            'need_review' => ['sometimes', 'boolean'],
            'auto_keywords' => ['sometimes', 'boolean'],
            'auto_description' => ['sometimes', 'boolean'],
            'is_loop' => ['sometimes', 'boolean'],
            'ai_quality_enabled' => ['sometimes', 'boolean'],
            'ai_quality_retrieval_mode' => ['sometimes', 'string', 'in:'.implode(',', AiQualityRetrievalMode::values())],
            'ai_quality_timeout_sampling_enabled' => ['sometimes', 'boolean'],
            'ai_quality_auto_optimize_enabled' => ['sometimes', 'boolean'],
            'ai_quality_optimization_level' => ['sometimes', 'string', 'in:pass,excellent_80,excellent_90'],
            'ai_quality_prompt_id' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'ai_quality_model_id' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'ai_quality_pass_score' => ['sometimes', 'integer', 'min:1', 'max:100'],
            'ai_quality_manual_override_min_score' => ['sometimes', 'integer', 'min:0', 'max:99'],
            'config_version' => ['sometimes', 'integer', 'min:1'],
        ];
    }

    protected function failedValidation(Validator $validator): never
    {
        $fieldErrors = collect($validator->errors()->messages())
            ->map(fn (array $messages): string => (string) ($messages[0] ?? 'Invalid value.'))
            ->all();

        throw new ApiException('validation_failed', '参数校验失败', 422, [
            'field_errors' => $fieldErrors,
        ]);
    }
}
