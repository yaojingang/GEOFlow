<?php

namespace App\Http\Requests\Api;

use App\Exceptions\ApiException;
use App\Services\GeoFlow\ArticleAiOptimizationPolicy;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StartArticleAiOptimizationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string,list<mixed>> */
    public function rules(): array
    {
        return [
            'strategy' => ['required', 'string', Rule::in(ArticleAiOptimizationPolicy::strategies())],
            'optimization_model_id' => ['nullable', 'integer', 'min:1', 'exists:ai_models,id'],
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
