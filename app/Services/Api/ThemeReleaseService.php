<?php

namespace App\Services\Api;

final class ThemeReleaseService
{
    /** Publication stays closed until the complete release transaction and recovery gates exist. */
    public static function availability(): array
    {
        return [
            'available' => false,
            'reason' => 'release_rollback_upgrade_integration_pending',
            'missing' => ['publish_plan', 'atomic_binding_and_receipt', 'field_rollback', 'readback', 'upgrade_interlock', 'backup_restore_validation'],
        ];
    }
}
