<?php

namespace App\Http\Requests\Admin;

use App\Http\Requests\Admin\Concerns\AuthorizesActiveSuperAdmin;
use Illuminate\Foundation\Http\FormRequest;

final class TestAiVisibilityModelBindingRequest extends FormRequest
{
    use AuthorizesActiveSuperAdmin;

    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return [
            'binding_type' => ['required', 'in:ark,deepseek'],
            'model_id' => ['required', 'integer', 'min:1'],
            'query' => ['nullable', 'string', 'max:200'],
        ];
    }
}
