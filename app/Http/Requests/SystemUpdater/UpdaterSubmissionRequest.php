<?php

namespace App\Http\Requests\SystemUpdater;

use App\Services\SystemUpdater\RemoteUpdaterService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdaterSubmissionRequest extends FormRequest
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
            'client_request_id' => ['required', 'string', 'regex:/\A[A-Za-z0-9][A-Za-z0-9._-]{7,127}\z/'],
            'plan_id' => ['required', 'string', 'regex:/\A[a-f0-9]{32}\z/'],
            'plan_sha256' => ['required', 'string', 'regex:/\A[a-f0-9]{64}\z/'],
            'expected_epoch' => ['required', 'string', 'regex:/\A[a-f0-9]{32}\z/'],
            'allow_maintenance' => ['required', 'boolean'],
            'confirm_host_access' => ['required', 'boolean'],
            'password' => ['sometimes', 'nullable', 'string', 'max:4096'],
            'authorization_code' => ['sometimes', 'nullable', 'string', 'max:64'],
        ];
    }

    public function businessInput(): array
    {
        $input = $this->validated();
        $input['allow_maintenance'] = $this->boolean('allow_maintenance');
        $input['confirm_host_access'] = $this->boolean('confirm_host_access');

        return $input;
    }
}
