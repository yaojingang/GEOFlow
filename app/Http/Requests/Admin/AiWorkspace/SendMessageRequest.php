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
        ];
    }
}
