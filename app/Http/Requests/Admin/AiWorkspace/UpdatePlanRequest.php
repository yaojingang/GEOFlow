<?php

namespace App\Http\Requests\Admin\AiWorkspace;

final class UpdatePlanRequest extends AiWorkspaceRequest
{
    public function authorize(): bool
    {
        return auth('admin')->check();
    }

    /** @return array<string,list<string>> */
    public function rules(): array
    {
        return [
            'plan_version' => ['required', 'integer', 'min:1'],
            'step_parameters' => ['required', 'array', 'min:1', 'max:'.(int) config('ai-workspace.max_plan_steps', 100)],
            'step_parameters.*' => ['required', 'array', 'max:50'],
        ];
    }
}
