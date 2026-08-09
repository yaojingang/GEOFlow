<?php

namespace App\Http\Requests\Admin;

use App\Models\Admin;
use App\Models\ManualPublication;
use Illuminate\Support\Facades\Gate;

class UpdateManualPublicationRequest extends ManualPublicationFormRequest
{
    public function authorize(): bool
    {
        $admin = $this->user('admin');
        $manualPublication = ManualPublication::query()->find((int) $this->route('manualPublicationId'));

        return $admin instanceof Admin
            && $manualPublication instanceof ManualPublication
            && Gate::forUser($admin)->allows('update', $manualPublication);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return $this->publicationRules(false) + [
            'revision' => ['required', 'integer', 'min:1'],
        ];
    }
}
