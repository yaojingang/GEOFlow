<?php

namespace App\Http\Requests\Admin;

use App\Http\Requests\Admin\Concerns\AuthorizesActiveSuperAdmin;
use Illuminate\Foundation\Http\FormRequest;

final class UpdateAiVisibilityModelBindingsRequest extends FormRequest
{
    use AuthorizesActiveSuperAdmin;

    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return [
            'ark_model_id' => ['nullable', 'integer', 'min:0'],
            'deepseek_model_id' => ['nullable', 'integer', 'min:0'],
        ];
    }
}
