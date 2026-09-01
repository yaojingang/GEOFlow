<?php

namespace App\Http\Requests\Admin;

use App\Models\Admin;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class StoreAdminUserRequest extends FormRequest
{
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
        return [
            'username' => [
                'required',
                'string',
                'regex:/^[A-Za-z0-9_.-]{3,50}$/',
                Rule::unique((new Admin)->getTable(), 'username'),
            ],
            'display_name' => ['nullable', 'string', 'max:100'],
            'email' => ['nullable', 'email', 'max:191'],
            'password' => ['required', 'string', 'min:8', 'same:confirm_password'],
            'confirm_password' => ['required', 'string', 'min:8'],
            'ai_config_mode' => ['required', Rule::in(['independent', 'shared_current_super'])],
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
            'password.required' => __('admin.admin_users.error.password_required'),
            'confirm_password.required' => __('admin.admin_users.error.password_required'),
            'password.same' => __('admin.admin_users.error.password_mismatch'),
            'password.min' => __('admin.admin_users.error.password_too_short'),
            'confirm_password.min' => __('admin.admin_users.error.password_too_short'),
            'ai_config_mode.required' => __('admin.admin_users.error.ai_config_mode_invalid'),
            'ai_config_mode.in' => __('admin.admin_users.error.ai_config_mode_invalid'),
            'shared_ai_config_owner_id.prohibited' => __('admin.admin_users.error.ai_config_provider_forged'),
            'ai_config_owner_id.prohibited' => __('admin.admin_users.error.ai_config_provider_forged'),
            'provider_admin_id.prohibited' => __('admin.admin_users.error.ai_config_provider_forged'),
        ];
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

        if (! $this->exists('ai_config_mode')) {
            $normalized['ai_config_mode'] = 'independent';
        }

        $this->merge($normalized);
    }
}
