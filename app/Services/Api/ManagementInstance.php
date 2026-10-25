<?php

namespace App\Services\Api;

use App\Models\SiteSetting;
use App\Services\SystemUpdater\RecoveryState;
use Illuminate\Support\Str;

final class ManagementInstance
{
    public function id(): string
    {
        return (string) SiteSetting::query()->firstOrCreate(
            ['setting_key' => 'management_instance_id'],
            ['setting_value' => (string) Str::uuid()],
        )->setting_value;
    }

    /** @return array<string, mixed> */
    public function describe(): array
    {
        $version = json_decode((string) file_get_contents(base_path('version.json')), true, flags: JSON_THROW_ON_ERROR);
        $recovery = app(RecoveryState::class)->snapshot();

        return [
            'instance_id' => $this->id(),
            'name' => (string) config('app.name', 'GEOFlow'),
            'core_version' => $version['version'] ?? null,
            'protocol_version' => '1.0',
            'api_version' => 'v1',
            'recovery' => $recovery === null ? ['supported' => false] : [
                'supported' => true, 'epoch' => $recovery['epoch'], 'host_id' => $recovery['host_id'],
                'phase' => $recovery['phase'], 'background_ready' => $recovery['phase'] === 'ready',
            ],
        ];
    }
}
