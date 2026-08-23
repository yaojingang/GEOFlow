<?php

namespace App\Http\Requests\Admin\AiWorkspace;

final class SendMessageRequest extends AiWorkspaceRequest
{
    public function authorize(): bool
    {
        return auth('admin')->check();
    }

    /** @return array<string,list<string>> */
    public function rules(): array
    {
        return [
            'prompt' => ['required', 'string', 'max:4000'],
            'request_key' => ['nullable', 'string', 'max:120', 'regex:/\A[a-zA-Z0-9._:-]+\z/'],
        ];
    }
}
