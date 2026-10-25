<?php

namespace App\Services\Api;

use App\Models\Admin;

final class ThemeManagementPolicy
{
    // These scopes only expose the implemented workspace endpoints. Code execution also requires ThemeWorkspaceAuthorization.
    public const SCOPES = ['themes:read', 'themes:write', 'themes:code'];

    public static function allows(Admin $admin, string $scope): bool
    {
        return in_array($scope, self::SCOPES, true) && $admin->status === 'active' && $admin->canManageProtectedWorkflows();
    }
}
