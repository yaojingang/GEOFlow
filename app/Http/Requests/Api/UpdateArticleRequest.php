<?php

namespace App\Http\Requests\Api;

use App\Exceptions\ApiException;
use App\Support\GeoFlow\AiQualityRetrievalMode;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateArticleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string,list<mixed>> */
    public function rules(): array
    {
        return [
            'config_version' => ['sometimes', 'integer', 'min:1'],
            'task_id' => ['sometimes', 'nullable', 'integer', 'min:1', 'exists:tasks,id'],
            'ai_quality_retrieval_mode_override' => [
                'sometimes',
                'nullable',
                'string',
                Rule::in(AiQualityRetrievalMode::values()),
            ],
            'ai_quality_knowledge_base_ids' => ['sometimes', 'array', 'max:5'],
            'ai_quality_knowledge_base_ids.*' => [
                'integer',
                'min:1',
                'distinct',
                'exists:knowledge_bases,id',
            ],
        ];
    }

    protected function failedValidation(Validator $validator): never
    {
        throw new ApiException('validation_failed', '参数校验失败', 422, [
            'field_errors' => collect($validator->errors()->messages())
                ->map(static fn (array $messages): string => (string) ($messages[0] ?? 'Invalid value.'))
                ->all(),
        ]);
    }
}
