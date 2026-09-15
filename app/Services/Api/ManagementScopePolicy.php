<?php

namespace App\Services\Api;

use App\Exceptions\ApiException;
use App\Models\Admin;

final class ManagementScopePolicy
{
    public const SCOPES = ['sites:read', ...ThemeManagementPolicy::SCOPES];

    public static function allows(Admin $admin, string $scope): bool
    {
        if (in_array($scope, ThemeManagementPolicy::SCOPES, true)) {
            return ThemeManagementPolicy::allows($admin, $scope);
        }

        return $admin->status === 'active'
            && in_array(strtolower(trim($admin->role)), ['admin', 'super_admin', 'superadmin'], true);
    }

    /** @param list<string> $requested @param list<string> $available @return list<string> */
    public static function validate(Admin $admin, array $requested, array $available): array
    {
        if ($requested === [] || count($requested) > 100) {
            throw new ApiException('validation_failed', '请选择有效的权限集合', 422);
        }
        foreach ($requested as $scope) {
            if (! is_string($scope) || ! in_array($scope, $available, true)) {
                throw new ApiException('invalid_scope', '请求包含未知权限', 422);
            }
            if (! self::allows($admin, $scope)) {
                throw new ApiException('forbidden', '账号不能申请此权限', 403, ['required_scope' => $scope]);
            }
        }

        return array_values(array_unique($requested));
    }
}
