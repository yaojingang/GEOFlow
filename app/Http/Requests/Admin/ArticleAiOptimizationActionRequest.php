<?php

namespace App\Http\Requests\Admin;

use App\Models\Admin;
use Illuminate\Foundation\Http\FormRequest;

class ArticleAiOptimizationActionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user('admin') instanceof Admin;
    }

    /** @return array<string,list<string>> */
    public function rules(): array
    {
        return [
            'candidate_hash' => [
                $this->routeIs('admin.articles.ai-quality.optimization.apply') ? 'required' : 'nullable',
                'string',
                'size:64',
                'regex:/\A[a-f0-9]{64}\z/D',
            ],
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->attributes->set('admin_activity_action', 'ai_quality_optimization_action');
        $this->attributes->set('admin_activity_details', [
            'article_id' => (int) $this->route('articleId'),
            'run_id' => (int) $this->route('runId'),
            'route' => (string) $this->route()?->getName(),
        ]);
    }
}
