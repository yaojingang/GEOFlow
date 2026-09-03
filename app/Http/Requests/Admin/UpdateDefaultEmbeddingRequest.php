<?php

namespace App\Http\Requests\Admin;

use App\Models\Admin;
use Illuminate\Foundation\Http\FormRequest;

final class UpdateDefaultEmbeddingRequest extends FormRequest
{
    public function authorize(): bool
    {
        $actor = $this->user('admin');

        return $actor instanceof Admin
            && (string) $actor->status === 'active'
            && $actor->isSuperAdmin();
    }

    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return [
            'default_embedding_model_id' => ['nullable', 'integer', 'min:0'],
        ];
    }

    public function modelId(): int
    {
        return max(0, (int) ($this->validated('default_embedding_model_id') ?? 0));
    }
}
