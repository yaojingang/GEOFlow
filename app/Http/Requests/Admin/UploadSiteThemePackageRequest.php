<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

class UploadSiteThemePackageRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user('admin')?->canManageProtectedWorkflows();
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'package_file' => ['required', 'file', 'mimes:zip', 'extensions:zip', 'max:'.(int) ceil(config('geoflow.theme_packages.max_archive_bytes', 10485760) / 1024)],
        ];
    }
}
