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
            'message' => '提交的参数未通过校验。',
            'code' => 'validation_failed',
            'errors' => $validator->errors(),
        ], 422));
    }
}
