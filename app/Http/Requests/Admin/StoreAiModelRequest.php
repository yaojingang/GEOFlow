<?php

namespace App\Http\Requests\Admin;

use App\Models\Admin;
use App\Models\AiModel;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

final class StoreAiModelRequest extends FormRequest
{
    public function authorize(): bool
    {
        $actor = $this->user('admin');

        return $actor instanceof Admin
            && (string) $actor->status === 'active'
            && Gate::forUser($actor)->allows('create', AiModel::class);
    }

    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:100'],
            'version' => ['nullable', 'string', 'max:50'],
            'api_key' => ['required', 'string', 'max:500'],
            'model_id' => ['required', 'string', 'max:100'],
            'model_type' => ['required', Rule::in(['chat', 'embedding'])],
            'api_url' => ['nullable', 'string', 'max:500'],
            'failover_priority' => ['nullable', 'integer', 'min:1'],
            'daily_limit' => ['nullable', 'integer', 'min:0'],
            'max_tokens' => ['nullable', 'integer', 'min:1', 'max:1000000'],
            'access_scope' => ['nullable', Rule::in([
                AiModel::ACCESS_SCOPE_USER_CONTENT,
                AiModel::ACCESS_SCOPE_SYSTEM_ONLY,
            ])],
            'owner_admin_id' => ['prohibited'],
        ];
    }

    /** @return list<callable(Validator): void> */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                $actor = $this->user('admin');
                if ($actor instanceof Admin
                    && ! $actor->isSuperAdmin()
                    && $this->input('access_scope') === AiModel::ACCESS_SCOPE_SYSTEM_ONLY) {
                    $validator->errors()->add('access_scope', __('admin.ai_models.error.system_scope_super_admin_only'));
                }
            },
        ];
    }

    protected function prepareForValidation(): void
    {
        $normalized = [];
        foreach (['name', 'version', 'api_key', 'model_id', 'api_url'] as $field) {
            $value = $this->input($field);
            if (is_string($value)) {
                $normalized[$field] = trim($value);
            }
        }

        $this->merge($normalized);
    }
}
