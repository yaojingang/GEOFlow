<?php

namespace App\Http\Requests\Admin;

use App\Http\Requests\Admin\Concerns\AuthorizesActiveSuperAdmin;
use Illuminate\Foundation\Http\FormRequest;

final class UpdateAiWorkspaceRuntimeRequest extends FormRequest
{
    use AuthorizesActiveSuperAdmin;

    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return [
            'enabled' => ['required', 'boolean'],
        ];
    }

    public function enabled(): bool
    {
        return $this->boolean('enabled');
    }
}
