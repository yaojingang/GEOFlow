<?php

namespace App\Http\Requests\Admin;

use App\Models\Admin;
use App\Models\KeywordLibrary;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class GenerateTitlesWithAiRequest extends FormRequest
{
    /** @var list<string> */
    private array $nonScalarInputFields = [];

    protected function prepareForValidation(): void
    {
        $this->nonScalarInputFields = [];

        $normalized = [];
        foreach (['title_count', 'custom_prompt'] as $field) {
            $value = $this->input($field);
            if ($value !== null && ! is_string($value) && ! is_int($value) && ! is_float($value)) {
                $this->nonScalarInputFields[] = $field;
                $value = null;
            }

            $normalized[$field] = $value;
        }

        $this->merge($normalized);
    }

    public function authorize(): bool
    {
        return $this->user('admin') instanceof Admin;
    }

    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        $requiresLargeRunConfirmation = (int) $this->input('title_count')
            > (int) config('geoflow.title_ai_confirmation_threshold', 1000);

        return [
            'keyword_library_id' => ['required', 'integer', 'exists:keyword_libraries,id'],
            'ai_model_id' => [
                'required',
                'integer',
                Rule::exists('ai_models', 'id')->where(static function ($query): void {
                    $query->where('status', 'active')
                        ->whereRaw("COALESCE(NULLIF(model_type, ''), 'chat') = 'chat'");
                }),
            ],
            'title_count' => ['required', 'integer', 'min:1', 'max:'.(int) config('geoflow.title_ai_max_count', 100_000)],
            'title_style' => ['required', Rule::in(['professional', 'attractive', 'seo', 'creative', 'question'])],
            'custom_prompt' => ['nullable', 'string', 'max:5000'],
            'confirmed_large_run' => $requiresLargeRunConfirmation
                ? ['required', 'accepted']
                : ['nullable'],
            'confirmed_keyword_reuse' => ['nullable', Rule::in([0, 1, '0', '1'])],
        ];
    }

    /** @return list<callable(Validator): void> */
    public function after(): array
    {
        return [function (Validator $validator): void {
            foreach ($this->nonScalarInputFields as $field) {
                $validator->errors()->add(
                    $field,
                    __('validation.string', ['attribute' => str_replace('_', ' ', $field)]),
                );
            }

            if ($validator->errors()->hasAny(['keyword_library_id', 'title_count'])) {
                return;
            }

            $keywordLibrary = KeywordLibrary::query()
                ->select('id')
                ->withCount('keywords')
                ->find((int) $this->input('keyword_library_id'));
            if ($keywordLibrary === null) {
                return;
            }

            $titleCount = (int) $this->input('title_count');
            $keywordCount = (int) $keywordLibrary->keywords_count;
            if ($keywordCount > 0
                && $titleCount > $keywordCount
                && ! $this->boolean('confirmed_keyword_reuse')) {
                $validator->errors()->add(
                    'confirmed_keyword_reuse',
                    __('admin.title_ai_generate.error.keyword_reuse_confirmation_required'),
                );
            }
        }];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'keyword_library_id.required' => __('admin.title_ai_generate.error.keyword_library_required'),
            'keyword_library_id.exists' => __('admin.title_ai_generate.error.keyword_library_missing'),
            'ai_model_id.required' => __('admin.title_ai_generate.error.ai_model_required'),
            'ai_model_id.exists' => __('admin.title_ai_generate.error.ai_model_missing'),
            'title_count.min' => __('admin.title_ai_generate.error.invalid_count', ['max' => (int) config('geoflow.title_ai_max_count', 100_000)]),
            'title_count.max' => __('admin.title_ai_generate.error.invalid_count', ['max' => (int) config('geoflow.title_ai_max_count', 100_000)]),
            'confirmed_large_run.required' => __('admin.title_ai_generate.error.large_run_confirmation_required'),
            'confirmed_large_run.accepted' => __('admin.title_ai_generate.error.large_run_confirmation_required'),
            'confirmed_keyword_reuse.in' => __('admin.title_ai_generate.error.keyword_reuse_confirmation_required'),
        ];
    }
}
