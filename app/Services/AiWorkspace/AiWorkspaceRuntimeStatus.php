<?php

namespace App\Services\AiWorkspace;

use App\Models\SiteSetting;
use Illuminate\Support\Facades\Schema;

final class AiWorkspaceRuntimeStatus
{
    public const SETTING_KEY = 'ai_workspace_runtime_enabled';

    /**
     * @return array{
     *   preferred_enabled:bool,
     *   effective_enabled:bool,
     *   forced_disabled:bool,
     *   source:'site_setting'|'environment_default'
     * }
     */
    public function snapshot(): array
    {
        $stored = $this->storedPreference();
        $preferredEnabled = $stored['exists']
            ? $stored['enabled']
            : (bool) config('ai-workspace.runtime_enabled', false);
        $forcedDisabled = $this->forcedDisabled();

        return [
            'preferred_enabled' => $preferredEnabled,
            'effective_enabled' => $preferredEnabled && ! $forcedDisabled,
            'forced_disabled' => $forcedDisabled,
            'source' => $stored['exists'] ? 'site_setting' : 'environment_default',
        ];
    }

    public function enabled(): bool
    {
        return $this->snapshot()['effective_enabled'];
    }

    public function forcedDisabled(): bool
    {
        return (bool) config('ai-workspace.force_disabled', false);
    }

    public function save(bool $enabled): void
    {
        SiteSetting::query()->upsert(
            [[
                'setting_key' => self::SETTING_KEY,
                'setting_value' => $enabled ? '1' : '0',
            ]],
            ['setting_key'],
            ['setting_value'],
        );
    }

    /** @return array{exists:bool,enabled:bool} */
    private function storedPreference(): array
    {
        if (! Schema::hasTable('site_settings')) {
            return ['exists' => false, 'enabled' => false];
        }

        $value = SiteSetting::query()
            ->where('setting_key', self::SETTING_KEY)
            ->value('setting_value');

        return [
            'exists' => $value !== null,
            'enabled' => filter_var((string) $value, FILTER_VALIDATE_BOOLEAN),
        ];
    }
}
