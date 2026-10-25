<?php

namespace App\Services\Site;

use App\Exceptions\ApiException;
use App\Models\SiteSetting;
use App\Models\SiteThemeBinding;
use App\Services\Api\ManagementSiteQuery;
use App\Support\Site\SiteSettingsBag;
use Illuminate\Support\Facades\DB;

final class SiteAppearanceService
{
    public function settings(): array
    {
        return SiteSetting::query()->useWritePdo()->whereIn('setting_key', array_merge(ManagementSiteQuery::PUBLIC_FIELDS, ['site_title']))
            ->orderBy('setting_key')->pluck('setting_value', 'setting_key')->all();
    }

    public function hash(array $settings): string
    {
        ksort($settings);

        return hash('sha256', json_encode($settings, JSON_THROW_ON_ERROR));
    }

    public function binding(): SiteThemeBinding
    {
        $settings = $this->settings();

        return SiteThemeBinding::query()->firstOrCreate(['site_key' => 'primary'], [
            'theme_id' => $settings['active_theme'] ?? (string) config('geoflow.default_theme'),
            'settings' => $settings,
        ])->refresh();
    }

    /** All Web and management writes use the same binding lock before changing appearance fields. */
    public function transaction(callable $write, ?string $expectedHash = null): mixed
    {
        $this->binding();

        return DB::transaction(function () use ($write, $expectedHash): mixed {
            $binding = SiteThemeBinding::query()->whereKey('primary')->lockForUpdate()->firstOrFail();
            if ($expectedHash !== null && ! hash_equals($expectedHash, $this->hash($this->settings()))) {
                throw new ApiException('appearance_conflict', '外观配置已更新，请重新读取并生成计划', 409);
            }
            $result = $write($binding);
            $binding->settings = $this->settings();
            $binding->lock_version++;
            $binding->save();
            DB::afterCommit(static fn () => SiteSettingsBag::forget());

            return $result;
        });
    }

    /** Call after the shared domain validators normalize the supplied values. */
    public function saveValidated(array $settings, ?string $expectedHash = null): void
    {
        $this->transaction(function (SiteThemeBinding $binding) use ($settings): void {
            foreach ($settings as $key => $value) {
                SiteSetting::query()->updateOrCreate(['setting_key' => $key], ['setting_value' => $value]);
            }
            if (array_key_exists('active_theme', $settings)) {
                $binding->theme_id = $settings['active_theme'];
                $binding->revision_id = null;
            }
        }, $expectedHash);
    }
}
