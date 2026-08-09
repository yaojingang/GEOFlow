<?php

namespace App\Policies;

use App\Models\Admin;
use App\Models\ManualPublication;

class ManualPublicationPolicy
{
    public function viewAny(Admin $admin): bool
    {
        return $admin->status === 'active';
    }

    public function view(Admin $admin, ManualPublication $manualPublication): bool
    {
        return $admin->isSuperAdmin()
            || (int) $manualPublication->assigned_admin_id === (int) $admin->getKey();
    }

    public function create(Admin $admin): bool
    {
        return $admin->isSuperAdmin();
    }

    public function update(Admin $admin, ManualPublication $manualPublication): bool
    {
        return $admin->isSuperAdmin() && $manualPublication->status !== ManualPublication::STATUS_COMPLETED;
    }

    public function transition(Admin $admin, ManualPublication $manualPublication): bool
    {
        return $admin->isSuperAdmin()
            || (int) $manualPublication->assigned_admin_id === (int) $admin->getKey();
    }

    public function reopen(Admin $admin, ManualPublication $manualPublication): bool
    {
        return $admin->isSuperAdmin()
            && in_array((string) $manualPublication->status, ManualPublication::REOPENABLE_STATUSES, true);
    }

    public function exportAny(Admin $admin): bool
    {
        return $admin->status === 'active';
    }
}
