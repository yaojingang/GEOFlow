<?php

namespace App\Http\Requests\Admin\AiWorkspace;

final class ClientMetricRequest extends AiWorkspaceRequest
{
    public function authorize(): bool
    {
        return auth('admin')->check();
    }

    /** @return array<string,list<string>> */
    public function rules(): array
    {
        return [
            'surface' => ['required', 'string', 'in:native'],
            'first_render_ms' => ['nullable', 'integer', 'min:0', 'max:600000'],
            'reconnect_count' => ['nullable', 'integer', 'min:0', 'max:1000'],
            'run_id' => ['nullable', 'uuid'],
        ];
    }
}
