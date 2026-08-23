<?php

namespace App\Http\Requests\Admin\AiWorkspace;

final class StoreConversationRequest extends AiWorkspaceRequest
{
    public function authorize(): bool
    {
        return auth('admin')->check();
    }

    /** @return array<string,list<string>> */
    public function rules(): array
    {
        return ['title' => ['nullable', 'string', 'max:80']];
    }
}
