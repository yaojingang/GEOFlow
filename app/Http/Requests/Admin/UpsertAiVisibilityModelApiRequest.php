<?php

namespace App\Http\Requests\Admin;

use App\Http\Requests\Admin\Concerns\AuthorizesActiveSuperAdmin;
use Illuminate\Foundation\Http\FormRequest;

final class UpsertAiVisibilityModelApiRequest extends FormRequest
{
    use AuthorizesActiveSuperAdmin;

    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return [
            'binding_type' => ['required', 'in:ark,deepseek'],
            'name' => ['required', 'string', 'max:100'],
            'model_id' => ['required', 'string', 'max:100'],
            'api_url' => ['required', 'url', 'max:500'],
            'api_key' => ['nullable', 'string', 'max:500'],
            'daily_limit' => ['nullable', 'integer', 'min:0'],
            'max_tokens' => ['nullable', 'integer', 'min:1', 'max:1000000'],
        ];
    }
}
