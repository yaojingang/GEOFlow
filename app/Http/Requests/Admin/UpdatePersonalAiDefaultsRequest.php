<?php

namespace App\Http\Requests\Admin;

use App\Models\Admin;
use App\Models\AiModel;
use Illuminate\Foundation\Http\FormRequest;

final class UpdatePersonalAiDefaultsRequest extends FormRequest
{
    public function authorize(): bool
    {
        $actor = $this->user('admin');

        return $actor instanceof Admin && (string) $actor->status === 'active';
    }

    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return [
            'default_chat_model_id' => ['nullable', 'integer', 'min:0'],
            'default_embedding_model_id' => ['nullable', 'integer', 'min:0'],
        ];
    }

    public function modelReference(string $field): ?AiModel
    {
        $id = max(0, (int) ($this->validated($field) ?? 0));
        if ($id === 0) {
            return null;
        }

        $model = new AiModel;
        $model->setAttribute($model->getKeyName(), $id);
        $model->exists = true;

        return $model;
    }
}
