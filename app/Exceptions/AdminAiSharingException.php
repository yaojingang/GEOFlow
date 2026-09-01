<?php

namespace App\Exceptions;

use Illuminate\Contracts\Debug\ShouldntReport;
use RuntimeException;

final class AdminAiSharingException extends RuntimeException implements ShouldntReport
{
    public const ACCESS_CONFLICT = 'admin_ai_config_access_conflict';

    public const TARGET_INVALID = 'admin_ai_config_target_invalid';

    public const PROVIDER_INVALID = 'admin_ai_config_provider_invalid';

    public const DELETE_BLOCKED = 'admin_ai_config_delete_blocked';

    /** @param array<string, int|string> $safeContext */
    private function __construct(
        private readonly string $errorCode,
        private readonly array $safeContext = [],
    ) {
        parent::__construct($errorCode);
    }

    public static function accessConflict(int $adminId): self
    {
        return new self(self::ACCESS_CONFLICT, ['admin_id' => $adminId]);
    }

    public static function targetInvalid(int $adminId): self
    {
        return new self(self::TARGET_INVALID, ['admin_id' => $adminId]);
    }

    public static function providerInvalid(int $adminId): self
    {
        return new self(self::PROVIDER_INVALID, ['admin_id' => $adminId]);
    }

    /** @param array<string, int> $counts */
    public static function deleteBlocked(int $adminId, array $counts): self
    {
        return new self(self::DELETE_BLOCKED, ['admin_id' => $adminId, ...$counts]);
    }

    public function getErrorCode(): string
    {
        return $this->errorCode;
    }

    /** @return array<string, int|string> */
    public function context(): array
    {
        return ['error_code' => $this->errorCode, ...$this->safeContext];
    }
}
