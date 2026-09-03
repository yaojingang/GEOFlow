<?php

namespace App\Http\Requests\Admin;

use App\Http\Requests\Admin\Concerns\AuthorizesActiveSuperAdmin;
use App\Models\AiSourceProvider;
use Illuminate\Foundation\Http\FormRequest;

final class UpdateAiSourceProviderRequest extends FormRequest
{
    use AuthorizesActiveSuperAdmin;

    public function authorize(): bool
    {
        if (! $this->authorizeActiveSuperAdmin()) {
            return false;
        }
        abort_unless(AiSourceProvider::query()->whereKey((int) $this->route('providerId'))->exists(), 404);

        return true;
    }

    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:120'],
            'endpoint_url' => ['nullable', 'url', 'max:500'],
            'api_key' => ['nullable', 'string', 'max:2000'],
            'daily_limit' => ['nullable', 'integer', 'min:0'],
            'count' => ['nullable', 'integer', 'min:1', 'max:20'],
            'search_type' => ['nullable', 'in:web'],
            'content_formats' => ['nullable', 'in:Markdown,Text'],
            'need_summary' => ['nullable', 'boolean'],
            'need_content' => ['nullable', 'boolean'],
            'need_url' => ['nullable', 'boolean'],
            'auth_info_level' => ['nullable', 'string', 'max:80'],
            'sites' => ['nullable', 'string', 'max:1000'],
            'block_hosts' => ['nullable', 'string', 'max:1000'],
            'status' => ['nullable', 'in:active,inactive'],
        ];
    }
}
