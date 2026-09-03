<?php

namespace App\Http\Requests\Admin;

use App\Http\Requests\Admin\Concerns\AuthorizesActiveSuperAdmin;
use Illuminate\Foundation\Http\FormRequest;

final class TestAiSourceProviderRequest extends FormRequest
{
    use AuthorizesActiveSuperAdmin;

    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return ['query' => ['nullable', 'string', 'max:200']];
    }
}
