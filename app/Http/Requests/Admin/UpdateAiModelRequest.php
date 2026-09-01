<?php

namespace App\Http\Requests\Admin;

use App\Models\Admin;
use App\Models\AiModel;
use App\Services\Admin\AdminAiModelAccessResolver;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

final class UpdateAiModelRequest extends FormRequest
{
    private ?AiModel $authorizedModel = null;

    public function authorize(AdminAiModelAccessResolver $accessResolver): bool
    {
        $actor = $this->user('admin');
        if (! $actor instanceof Admin || (string) $actor->status !== 'active') {
            return false;
        }

        $modelId = (int) $this->route('modelId');
        $model = $accessResolver
            ->managementQuery($actor)
            ->whereKey($modelId)
            ->first();
        abort_unless($model instanceof AiModel, 404);
        $this->authorizedModel = $model;

        return Gate::forUser($actor)->allows('update', $model);
    }

    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:100'],
            'version' => ['nullable', 'string', 'max:50'],
            'api_key' => ['nullable', 'string', 'max:500'],
            'model_id' => ['required', 'string', 'max:100'],
            'model_type' => ['required', Rule::in(['chat', 'embedding'])],
            'api_url' => ['nullable', 'string', 'max:500'],
            'failover_priority' => ['nullable', 'integer', 'min:1'],
            'daily_limit' => ['nullable', 'integer', 'min:0'],
            'max_tokens' => ['nullable', 'integer', 'min:1', 'max:1000000'],
            'status' => ['nullable', Rule::in(['active', 'inactive'])],
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

    public function aiModel(): AiModel
    {
        return $this->authorizedModel ?? throw new \LogicException('AI model authorization has not completed.');
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
