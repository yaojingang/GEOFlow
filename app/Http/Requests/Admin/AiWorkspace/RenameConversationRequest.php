<?php

namespace App\Http\Requests\Admin\AiWorkspace;

final class RenameConversationRequest extends AiWorkspaceRequest
{
    public function authorize(): bool
    {
        return auth('admin')->check();
    }

    /** @return array<string,list<string>> */
    public function rules(): array
    {
        return ['title' => ['required', 'string', 'max:120']];
    }
}
