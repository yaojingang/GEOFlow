<?php

namespace App\Http\Requests\Admin;

use App\Models\Admin;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class UpdateAdminUserRequest extends FormRequest
{
    private ?Admin $resolvedTargetAdmin = null;

    public function authorize(): bool
    {
        $actor = $this->user('admin');

        return $actor instanceof Admin
            && $actor->isSuperAdmin()
            && (string) $actor->status === 'active';
    }

    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        $target = $this->targetAdmin();
        $isSuperAdmin = $target?->isSuperAdmin() ?? false;

        return [
            'username' => [
                'required',
                'string',
                'regex:/^[A-Za-z0-9_.-]{3,50}$/',
                Rule::unique((new Admin)->getTable(), 'username')->ignore($target?->getKey()),
            ],
            'display_name' => ['nullable', 'string', 'max:100'],
            'email' => ['nullable', 'email', 'max:191'],
            'status' => ['required', Rule::in(['active', 'inactive'])],
            'password' => ['nullable', 'string', 'min:8', 'same:confirm_password'],
            'confirm_password' => ['nullable', 'string', 'min:8'],
            'ai_config_mode' => $isSuperAdmin
                ? ['prohibited']
                : ['required', Rule::in(['independent', 'shared_current_super'])],
            'expected_ai_config_access_version' => $isSuperAdmin
                ? ['prohibited']
                : ['required', 'integer', 'min:1'],
            'expected_shared_ai_config_owner_id' => $isSuperAdmin
                ? ['prohibited']
                : ['present', 'nullable', 'integer', 'min:1'],
            'switch_shared_provider' => $isSuperAdmin
                ? ['prohibited']
                : ['sometimes', 'boolean'],
            'shared_ai_config_owner_id' => ['prohibited'],
            'ai_config_owner_id' => ['prohibited'],
            'provider_admin_id' => ['prohibited'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'username.required' => __('admin.admin_users.error.username_required'),
            'username.regex' => __('admin.admin_users.error.username_invalid'),
            'username.unique' => __('admin.admin_users.error.username_exists'),
            'status.required' => __('admin.admin_users.error.status_invalid'),
            'status.in' => __('admin.admin_users.error.status_invalid'),
            'password.same' => __('admin.admin_users.error.password_mismatch'),
            'password.min' => __('admin.admin_users.error.password_too_short'),
            'confirm_password.min' => __('admin.admin_users.error.password_too_short'),
            'ai_config_mode.required' => __('admin.admin_users.error.ai_config_mode_invalid'),
            'ai_config_mode.in' => __('admin.admin_users.error.ai_config_mode_invalid'),
            'ai_config_mode.prohibited' => __('admin.admin_users.error.super_admin_ai_config_forbidden'),
            'expected_ai_config_access_version.required' => __('admin.admin_users.error.ai_config_access_version_required'),
            'expected_ai_config_access_version.integer' => __('admin.admin_users.error.ai_config_access_version_required'),
            'expected_ai_config_access_version.min' => __('admin.admin_users.error.ai_config_access_version_required'),
            'expected_ai_config_access_version.prohibited' => __('admin.admin_users.error.super_admin_ai_config_forbidden'),
            'expected_shared_ai_config_owner_id.present' => __('admin.admin_users.error.ai_config_access_version_required'),
            'expected_shared_ai_config_owner_id.integer' => __('admin.admin_users.error.ai_config_access_version_required'),
            'expected_shared_ai_config_owner_id.min' => __('admin.admin_users.error.ai_config_access_version_required'),
            'expected_shared_ai_config_owner_id.prohibited' => __('admin.admin_users.error.super_admin_ai_config_forbidden'),
            'switch_shared_provider.boolean' => __('admin.admin_users.error.ai_config_provider_forged'),
            'switch_shared_provider.prohibited' => __('admin.admin_users.error.super_admin_ai_config_forbidden'),
            'shared_ai_config_owner_id.prohibited' => __('admin.admin_users.error.ai_config_provider_forged'),
            'ai_config_owner_id.prohibited' => __('admin.admin_users.error.ai_config_provider_forged'),
            'provider_admin_id.prohibited' => __('admin.admin_users.error.ai_config_provider_forged'),
        ];
    }

    public function targetAdmin(): ?Admin
    {
        if ($this->resolvedTargetAdmin instanceof Admin) {
            return $this->resolvedTargetAdmin;
        }

        $adminId = filter_var($this->route('adminId'), FILTER_VALIDATE_INT);
        if ($adminId === false || $adminId <= 0) {
            return null;
        }

        return $this->resolvedTargetAdmin = Admin::query()->whereKey($adminId)->firstOrFail();
    }

    /** @return array<string, mixed> */
    public function safeInput(): array
    {
        return $this->safe()->except(['password', 'confirm_password']);
    }

    protected function prepareForValidation(): void
    {
        $normalized = [];
        foreach (['username', 'display_name', 'email'] as $field) {
            $value = $this->input($field);
            if (is_string($value)) {
                $normalized[$field] = trim($value);
            }
        }

        $this->merge($normalized);
    }
}
