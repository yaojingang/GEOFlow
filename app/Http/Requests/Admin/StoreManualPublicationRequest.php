<?php

namespace App\Http\Requests\Admin;

use App\Models\Admin;
use App\Models\ManualPublication;
use Illuminate\Support\Facades\Gate;

class StoreManualPublicationRequest extends ManualPublicationFormRequest
{
    public function authorize(): bool
    {
        $admin = $this->user('admin');

        return $admin instanceof Admin
            && Gate::forUser($admin)->allows('create', ManualPublication::class);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return $this->publicationRules(true);
    }
}
