<?php

namespace App\Http\Requests\Admin;

use App\Models\Admin;
use App\Services\GeoFlow\ArticleAiOptimizationPolicy;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StartArticleAiOptimizationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user('admin') instanceof Admin;
    }

    /** @return array<string,list<mixed>> */
    public function rules(): array
    {
        return [
            'request_key' => ['required', 'uuid'],
            'strategy' => ['required', 'string', Rule::in(ArticleAiOptimizationPolicy::strategies())],
            'optimization_model_id' => ['nullable', 'integer', 'min:1', 'exists:ai_models,id'],
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->attributes->set('admin_activity_action', 'start_ai_quality_optimization');
        $this->attributes->set('admin_activity_details', [
            'article_id' => (int) $this->route('articleId'),
            'strategy' => (string) $this->input('strategy', ''),
            'request_key' => (string) $this->input('request_key', ''),
        ]);
    }
}
