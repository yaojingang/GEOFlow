<?php

namespace App\Http\Requests\Admin\Concerns;

use App\Models\Admin;

trait AuthorizesActiveSuperAdmin
{
    public function authorize(): bool
    {
        return $this->authorizeActiveSuperAdmin();
    }

    protected function authorizeActiveSuperAdmin(): bool
    {
        $actor = $this->user('admin');

        return $actor instanceof Admin
            && (string) $actor->status === 'active'
            && $actor->isSuperAdmin();
    }
}
