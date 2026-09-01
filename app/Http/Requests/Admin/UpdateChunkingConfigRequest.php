<?php

namespace App\Http\Requests\Admin;

use App\Models\Admin;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class UpdateChunkingConfigRequest extends FormRequest
{
    public function authorize(): bool
    {
        $actor = $this->user('admin');

        return $actor instanceof Admin
            && (string) $actor->status === 'active'
            && $actor->isSuperAdmin();
    }

    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [
            'knowledge_chunk_strategy' => ['required', Rule::in(['rule', 'auto', 'semantic_llm'])],
            'knowledge_chunking_model_id' => ['nullable', 'integer', 'min:0'],
        ];
    }

    public function strategy(): string
    {
        return (string) $this->validated('knowledge_chunk_strategy');
    }

    public function modelId(): int
    {
        return max(0, (int) ($this->validated('knowledge_chunking_model_id') ?? 0));
    }
}
