<?php

namespace App\Http\Requests\Admin;

use App\Models\Admin;
use App\Models\ManualPublication;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class TransitionManualPublicationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user('admin') instanceof Admin;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'target_status' => ['required', Rule::in(ManualPublication::STATUSES)],
            'revision' => ['required', 'integer', 'min:1'],
            'completion_url' => [
                'nullable',
                Rule::requiredIf(fn (): bool => $this->input('target_status') === ManualPublication::STATUS_COMPLETED),
                'url:http,https',
                'max:1000',
            ],
            'result_note' => ['nullable', 'string', 'max:5000'],
        ];
    }
}
