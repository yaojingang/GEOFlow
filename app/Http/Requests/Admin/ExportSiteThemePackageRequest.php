<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

class ExportSiteThemePackageRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        if (! $this->has('theme_id')) {
            $this->merge(['theme_id' => $this->input('active_theme')]);
        }
    }

    public function authorize(): bool
    {
        return (bool) $this->user('admin')?->canManageProtectedWorkflows();
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'theme_id' => ['required', 'string', 'max:80', 'regex:/^[A-Za-z0-9_-]+$/'],
        ];
    }
}
