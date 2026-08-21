<?php

namespace App\Http\Requests\Admin;

use App\Models\Admin;
use App\Models\ManualPublicationAccount;
use App\Models\ManualPublicationPersona;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SaveManualPublicationAccountRequest extends FormRequest
{
    public function authorize(): bool
    {
        $admin = $this->user('admin');

        return $admin instanceof Admin && $admin->isSuperAdmin();
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'persona_id' => [
                'required',
                'integer',
                Rule::exists((new ManualPublicationPersona)->getTable(), 'id'),
            ],
            'platform' => ['required', Rule::in(ManualPublicationAccount::PLATFORMS)],
            'custom_platform' => [
                'nullable',
                Rule::requiredIf(fn (): bool => $this->input('platform') === ManualPublicationAccount::PLATFORM_CUSTOM),
                'string',
                'max:120',
            ],
            'account_name' => ['required', 'string', 'max:160'],
            'profile_url' => ['nullable', 'url:http,https', 'max:1000'],
            'notes' => ['nullable', 'string', 'max:5000'],
            'is_active' => ['nullable', 'boolean'],
        ];
    }
}
