<?php

namespace App\Http\Requests\Admin;

use App\Models\Admin;
use App\Services\GeoFlow\ArticleMarkdownExportService;
use Illuminate\Foundation\Http\FormRequest;

class ExportArticlesMarkdownRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user('admin') instanceof Admin;
    }

    /** @return array<string, list<string>> */
    public function rules(): array
    {
        $rules = [
            'article_ids' => ['required', 'array', 'min:1', 'max:'.ArticleMarkdownExportService::MAX_ARTICLES],
        ];

        $submitted = $this->input('article_ids');
        if (! is_array($submitted) || count($submitted) <= ArticleMarkdownExportService::MAX_ARTICLES) {
            $rules['article_ids.*'] = ['required', 'integer', 'min:1', 'distinct:strict'];
        }

        return $rules;
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'article_ids.required' => __('admin.articles.export.errors.select_articles'),
            'article_ids.array' => __('admin.articles.export.errors.invalid_selection'),
            'article_ids.min' => __('admin.articles.export.errors.select_articles'),
            'article_ids.max' => __('admin.articles.export.errors.too_many', [
                'max' => ArticleMarkdownExportService::MAX_ARTICLES,
            ]),
            'article_ids.*.required' => __('admin.articles.export.errors.invalid_selection'),
            'article_ids.*.integer' => __('admin.articles.export.errors.invalid_selection'),
            'article_ids.*.min' => __('admin.articles.export.errors.invalid_selection'),
            'article_ids.*.distinct' => __('admin.articles.export.errors.duplicate_selection'),
        ];
    }

    /** @return list<int> */
    public function articleIds(): array
    {
        return array_map(
            static fn (mixed $articleId): int => (int) $articleId,
            array_values($this->validated('article_ids', [])),
        );
    }

    protected function prepareForValidation(): void
    {
        $submitted = $this->input('article_ids');
        $firstKey = is_array($submitted) ? array_key_first($submitted) : null;
        $lastKey = is_array($submitted) ? array_key_last($submitted) : null;

        $details = [
            'article_ids' => [
                'count' => is_array($submitted) ? count($submitted) : 0,
                'first' => $this->auditId($firstKey !== null ? $submitted[$firstKey] : null),
                'last' => $this->auditId($lastKey !== null ? $submitted[$lastKey] : null),
            ],
        ];

        $this->attributes->set('admin_activity_action', 'export_markdown');
        $this->attributes->set('admin_activity_details', $details);
        $this->request->remove('_token');
        request()->attributes->set('admin_activity_action', 'export_markdown');
        request()->attributes->set('admin_activity_details', $details);
        request()->request->remove('_token');
    }

    private function auditId(mixed $value): ?int
    {
        return is_numeric($value) && (int) $value > 0 ? (int) $value : null;
    }
}
