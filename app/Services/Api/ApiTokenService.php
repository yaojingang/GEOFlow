<?php

namespace App\Services\Api;

use App\Exceptions\ApiException;
use App\Models\Admin;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Laravel\Sanctum\PersonalAccessToken;

class ApiTokenService
{
    /**
     * 拉取 Token 列表（按创建时间倒序）。
     *
     * @return list<array<string,mixed>>
     */
    public function listTokens(): array
    {
        /** @var Collection<int, PersonalAccessToken> $rows */
        $rows = PersonalAccessToken::query()
            ->where('tokenable_type', Admin::class)
            ->with('tokenable:id,username')
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->get();

        return $rows
            ->map(function (PersonalAccessToken $row): array {
                $data = $this->hydrate($row);
                $data['created_by_username'] = (string) ($row->tokenable?->username ?? '');

                return $data;
            })
            ->all();
    }

    /**
     * 撤销指定 Token（Sanctum 语义为物理删除）。
     */
    public function revokeToken(int $tokenId): void
    {
        $affected = PersonalAccessToken::query()
            ->where('tokenable_type', Admin::class)
            ->whereKey($tokenId)
            ->delete();

        if ($affected !== 1) {
            throw new ApiException('token_not_found', 'Token 不存在', 404);
        }
    }

    /**
     * @return array<string, mixed>|null
     */
    public function getActiveTokenByPlaintext(string $plainToken): ?array
    {
        $row = PersonalAccessToken::findToken($plainToken);

        if (! $row) {
            return null;
        }

        if ($row->tokenable_type !== Admin::class) {
            return null;
        }

        if ($row->expires_at && $row->expires_at->isPast()) {
            return null;
        }

        return $this->hydrate($row);
    }

    public function touchToken(int $tokenId): void
    {
        PersonalAccessToken::query()
            ->where('tokenable_type', Admin::class)
            ->whereKey($tokenId)
            ->update([
                'last_used_at' => now(),
                'updated_at' => now(),
            ]);
    }

    /**
     * @param  array<string, mixed>  $token
     */
    public function tokenHasScope(array $token, string $scope): bool
    {
        $scopes = $token['scopes'] ?? [];

        return in_array('*', $scopes, true) || in_array($scope, $scopes, true);
    }

    public function resolveAuditAdminId(?int $preferredAdminId): int
    {
        if ($preferredAdminId !== null && $preferredAdminId > 0) {
            $exists = Admin::query()->whereKey($preferredAdminId)->exists();
            if ($exists) {
                return $preferredAdminId;
            }
        }

        $fallback = (int) Admin::query()->orderBy('id')->value('id');
        if ($fallback <= 0) {
            throw new ApiException('admin_not_found', '系统中不存在可用的管理员账号', 500);
        }

        return $fallback;
    }

    /**
     * @param  list<string>  $scopes
     * @return array{token: string, record: array<string, mixed>}
     */
    public function createToken(string $name, array $scopes, ?int $adminId, ?string $expiresAt = null): array
    {
        $name = trim($name);
        if ($name === '') {
            throw new ApiException('validation_failed', 'Token 名称不能为空', 422, [
                'field_errors' => ['name' => 'Token 名称不能为空'],
            ]);
        }

        $scopes = $this->normalizeScopes($scopes);
        if ($scopes === []) {
            throw new ApiException('validation_failed', '至少选择一个 scope', 422, [
                'field_errors' => ['scopes' => '至少选择一个 scope'],
            ]);
        }

        $expires = $this->normalizeExpiresAt($expiresAt);
        $creatorId = $this->normalizeCreatorAdminId($adminId) ?? $this->resolveAuditAdminId($adminId);
        $admin = Admin::query()->whereKey($creatorId)->first();
        if (! $admin) {
            throw new ApiException('admin_not_found', '系统中不存在可用的管理员账号', 500);
        }

        $tokenResult = $admin->createToken(
            $name,
            array_values($scopes),
            $expires !== null ? Carbon::parse($expires) : null
        );
        $model = $tokenResult->accessToken->fresh();
        if (! $model instanceof PersonalAccessToken) {
            throw new ApiException('token_create_failed', 'Token 创建失败', 500);
        }

        $record = $this->hydrate($model);

        return [
            'token' => $tokenResult->plainTextToken,
            'record' => $record,
        ];
    }

    /**
     * @return list<string>
     */
    public function getAvailableScopes(): array
    {
        return [
            'catalog:read',
            'tasks:read',
            'tasks:write',
            'jobs:read',
            'articles:read',
            'articles:write',
            'articles:publish',
            'materials:read',
            'materials:write',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function hydrate(PersonalAccessToken $row): array
    {
        $scopes = $row->abilities;
        if (! is_array($scopes)) {
            $scopes = [];
        }

        return [
            'id' => (int) $row->id,
            'name' => $row->name,
            'token_hash' => '',
            'scopes' => $scopes,
            'status' => 'active',
            'created_by_admin_id' => $row->tokenable_id !== null ? (int) $row->tokenable_id : null,
            'last_used_at' => $row->last_used_at?->format('Y-m-d H:i:s'),
            'expires_at' => $row->expires_at?->format('Y-m-d H:i:s'),
            'created_at' => $row->created_at?->format('Y-m-d H:i:s'),
            'updated_at' => $row->updated_at?->format('Y-m-d H:i:s'),
        ];
    }

    /**
     * @return list<string>
     */
    private function normalizeScopes(array $scopes): array
    {
        $allowed = $this->getAvailableScopes();
        $normalized = [];
        foreach ($scopes as $scope) {
            $scope = trim((string) $scope);
            if ($scope !== '' && in_array($scope, $allowed, true)) {
                $normalized[] = $scope;
            }
        }

        return array_values(array_unique($normalized));
    }

    private function normalizeExpiresAt(?string $expiresAt): ?string
    {
        $expiresAt = $expiresAt !== null ? trim($expiresAt) : null;
        if ($expiresAt === null || $expiresAt === '') {
            return null;
        }

        $timestamp = strtotime($expiresAt);
        if ($timestamp === false) {
            throw new ApiException('validation_failed', '过期时间格式无效', 422, [
                'field_errors' => ['expires_at' => '过期时间格式无效'],
            ]);
        }

        return date('Y-m-d H:i:s', $timestamp);
    }

    private function normalizeCreatorAdminId(?int $adminId): ?int
    {
        if ($adminId === null || $adminId <= 0) {
            return null;
        }

        return Admin::query()->whereKey($adminId)->exists() ? $adminId : null;
    }
}
