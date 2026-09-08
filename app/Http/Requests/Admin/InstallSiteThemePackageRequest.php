<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

class InstallSiteThemePackageRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user('admin')?->canManageProtectedWorkflows();
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'trusted_source' => ['required', 'accepted'],
        ];
    }
}
