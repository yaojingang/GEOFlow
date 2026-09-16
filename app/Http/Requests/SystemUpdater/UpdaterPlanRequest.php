<?php

namespace App\Http\Requests\SystemUpdater;

use App\Services\SystemUpdater\RemoteUpdaterService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdaterPlanRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'instance_id' => ['required', 'uuid'],
            'action' => ['required', Rule::in(array_keys(RemoteUpdaterService::ACTION_SCOPES))],
            'expected_epoch' => ['sometimes', 'string', 'regex:/\A[a-f0-9]{32}\z/'],
            'recovery_point_id' => ['nullable', 'required_if:action,restore', 'prohibited_unless:action,restore', 'regex:/\A[0-9]{8}T[0-9]{6}Z-[a-f0-9]{8}\z/'],
        ];
    }
}
