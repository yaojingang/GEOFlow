<?php

namespace App\Services\Topics;

use App\Models\Admin;
use App\Models\HostedSiteProfile;
use App\Models\SiteSetting;
use App\Support\Site\CurrentSite;
use App\Support\Site\SiteSettingsBag;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Validator;

final class TopicSiteSettings
{
    public const DEFAULTS = ['enabled' => true, 'home_enabled' => true, 'home_limit' => 3, 'navigation_enabled' => true, 'require_review' => false, 'default_template' => 'default', 'channel_name' => '专题'];

    /** @return array<string,mixed> */
    public function get(string $siteKey): array
    {
        if (! Schema::hasTable('site_settings')) {
            return self::DEFAULTS;
        }
        if ($siteKey === 'primary') {
            $raw = SiteSetting::query()->useWritePdo()->where('setting_key', 'topics')->value('setting_value');
            $stored = json_decode((string) $raw, true);
        } else {
            $profile = $this->profile($siteKey);
            $stored = $profile->channel?->site_settings['topics'] ?? null;
        }

        return array_replace(self::DEFAULTS, is_array($stored) ? array_intersect_key($stored, self::DEFAULTS) : []) + ['topics_public_opened_at' => SiteSetting::query()->where('setting_key', 'topics_public_opened_at:'.$siteKey)->value('setting_value')];
    }

    public function currentKey(): string
    {
        $site = app(CurrentSite::class);

        return $site->isResolved() && $site->isHosted() ? 'hosted:'.$site->profileId() : 'primary';
    }

    /** @param array<string,mixed> $input @return array<string,mixed> */
    public function save(string $siteKey, array $input, Admin $actor): array
    {
        abort_unless($actor->canManageProtectedWorkflows(), 403);
        $data = Validator::make($input, ['enabled' => ['required', 'boolean'], 'home_enabled' => ['required', 'boolean'], 'home_limit' => ['required', 'integer', 'between:1,12'], 'navigation_enabled' => ['required', 'boolean'], 'require_review' => ['required', 'boolean'], 'default_template' => ['required', 'string', 'max:64'], 'channel_name' => ['sometimes', 'string', 'max:30']])->validate();
        $data['channel_name'] = trim($data['channel_name'] ?? $this->get($siteKey)['channel_name']) ?: '专题';
        app(TopicTemplateCatalog::class)->assertAvailableForSite($siteKey, $data['default_template'], 'default_template');
        foreach (['enabled', 'home_enabled', 'navigation_enabled', 'require_review'] as $flag) {
            $data[$flag] = (bool) $data[$flag];
        }
        $data['home_limit'] = (int) $data['home_limit'];
        if ($data['enabled']) {
            app(TopicNamespaceGuard::class)->assertAvailable($siteKey);
        }
        DB::transaction(function () use ($siteKey, $data): void {
            if ($siteKey === 'primary') {
                SiteSetting::query()->updateOrCreate(['setting_key' => 'topics'], ['setting_value' => json_encode($data, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)]);
            } else {
                $profile = $this->profile($siteKey);
                $channel = $profile->channel()->lockForUpdate()->firstOrFail();
                $profile = HostedSiteProfile::query()->lockForUpdate()->findOrFail($profile->id);
                $settings = $channel->site_settings ?? [];
                $settings['topics'] = $data;
                $channel->update(['site_settings' => $settings]);
                $profile->increment('settings_version');
            }
        });
        SiteSettingsBag::forget();

        return $data;
    }

    public function recordOpened(string $siteKey): void
    {
        SiteSetting::query()->insertOrIgnore(['setting_key' => 'topics_public_opened_at:'.$siteKey, 'setting_value' => now()->toIso8601String(), 'created_at' => now(), 'updated_at' => now()]);
    }

    private function profile(string $siteKey): HostedSiteProfile
    {
        abort_unless(preg_match('/^hosted:([1-9][0-9]*)$/', $siteKey, $match) === 1, 404);

        return HostedSiteProfile::query()->useWritePdo()->with(['channel' => fn ($query) => $query->useWritePdo()])->findOrFail((int) $match[1]);
    }
}
