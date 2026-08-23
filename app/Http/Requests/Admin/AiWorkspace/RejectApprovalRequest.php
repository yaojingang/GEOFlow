<?php

namespace App\Http\Requests\Admin\AiWorkspace;

final class RejectApprovalRequest extends AiWorkspaceRequest
{
    public function authorize(): bool
    {
        return auth('admin')->check();
    }

    /** @return array<string,list<string>> */
    public function rules(): array
    {
        return ['reason' => ['nullable', 'string', 'max:500']];
    }
}
