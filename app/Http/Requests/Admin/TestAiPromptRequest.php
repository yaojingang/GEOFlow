<?php

namespace App\Http\Requests\Admin;

use App\Models\Admin;
use App\Support\GeoFlow\PromptTestAnchors;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class TestAiPromptRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user('admin') instanceof Admin;
    }

    protected function prepareForValidation(): void
    {
        // Preserve the editor's leading whitespace for exact source positions.
        $raw = $this->isJson() ? json_decode($this->getContent(), true) : null;
        if (is_array($raw) && is_string($raw['prompt_content'] ?? null)) {
            $this->merge(['prompt_content' => PromptTestAnchors::normalize($raw['prompt_content'])]);
        }
    }

    public function rules(): array
    {
        return [
            'request_id' => ['required', 'uuid'],
            'prompt_content' => ['required', 'string', 'max:50000'],
            'knowledge_base_ids' => ['required', 'array', 'min:1', 'max:5'],
            'knowledge_base_ids.*' => ['required', 'integer', 'min:1', 'distinct', 'exists:knowledge_bases,id'],
            'title_source' => ['required', Rule::in(['manual', 'library'])],
            'title_id' => ['required_if:title_source,library', 'nullable', 'integer', 'exists:titles,id'],
            'title' => ['required_if:title_source,manual', 'nullable', 'string', 'max:500'],
            'keyword' => ['nullable', 'string', 'max:200'],
            'ai_model_id' => ['required', 'integer', 'min:1'],
            'diagnostic_model_id' => ['nullable', 'integer', 'min:1'],
        ];
    }

    public function attributes(): array
    {
        return [
            'prompt_content' => __('prompt_test.prompt'), 'knowledge_base_ids' => __('prompt_test.knowledge'),
            'title' => __('prompt_test.title'), 'title_id' => __('prompt_test.title'),
            'ai_model_id' => __('prompt_test.generation_model'), 'keyword' => __('prompt_test.keyword'),
        ];
    }
}
