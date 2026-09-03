<?php

namespace App\Services\GeoFlow\AiVisibility;

final readonly class AiVisibilityModelExecutionSnapshot
{
    public function __construct(
        public int $runId,
        public int $modelId,
        public int $ownerAdminId,
        public string $bindingType,
        public string $settingKey,
        public string $configurationRevision,
    ) {}
}
