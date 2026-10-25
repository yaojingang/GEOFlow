<?php

namespace App\Http\Requests\Api;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class ThemeWorkspaceRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return match ($this->route()?->getActionMethod()) {
            'store' => ['site' => ['required', 'in:primary'], 'theme' => ['required', 'string', 'max:80']],
            'authorizeCode' => ['password' => ['required', 'string', 'max:4096']],
            'discard' => ['expected_version' => ['required', 'integer', 'min:1']],
            'change' => ['expected_version' => ['required', 'integer', 'min:1'], 'changes' => ['required', 'array', 'min:1', 'max:100'], 'changes.*' => ['array:action,path,expected_sha256,content'], 'changes.*.path' => ['required', 'string', 'max:500'], 'changes.*.action' => ['required', 'in:put,delete'], 'changes.*.expected_sha256' => ['present', 'nullable', 'regex:/^[a-f0-9]{64}$/D'], 'changes.*.content' => ['sometimes', 'string', 'max:1048576']],
            'file' => ['path' => ['required', 'string', 'max:500'], 'offset' => ['sometimes', 'integer', 'min:0'], 'length' => ['sometimes', 'integer', 'min:1', 'max:262144']],
            default => [],
        };
    }
}
