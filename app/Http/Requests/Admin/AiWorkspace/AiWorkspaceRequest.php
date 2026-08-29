<?php

namespace App\Http\Requests\Admin\AiWorkspace;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;

abstract class AiWorkspaceRequest extends FormRequest
{
    protected function failedValidation(Validator $validator): void
    {
        throw new HttpResponseException(response()->json([
            'message' => __('admin.ai_workspace.invalid_submission'),
            'code' => 'validation_failed',
            'errors' => $validator->errors(),
        ], 422));
    }
}
