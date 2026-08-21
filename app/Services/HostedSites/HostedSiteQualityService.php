<?php

namespace App\Services\HostedSites;

use App\Models\DistributionChannel;
use App\Models\HostedSiteProfile;
use App\Models\LeadForm;
use App\Services\Site\HostedSiteResolver;
use App\Support\Site\SiteThemeCatalog;
use Illuminate\Support\Facades\DB;

final class HostedSiteQualityService
{
    public function __construct(
        private readonly HostedSiteResolver $resolver,
        private readonly SiteThemeCatalog $themes,
        private readonly HostedSiteTechnicalProbe $technicalProbe,
    ) {}

    /** @return array{passed:bool,checks:array<string,bool>} */
    public function preflight(
        DistributionChannel $channel,
        bool $persist = true,
        bool $requireOnline = false,
    ): array {
        $channel->load('hostedSiteProfile');
        $profile = $channel->hostedSiteProfile;
        $snapshot = [
            'channel_updated_at' => $channel->updated_at?->toISOString(),
            'profile_updated_at' => $profile?->updated_at?->toISOString(),
            'settings_version' => $profile?->settings_version,
        ];
        $result = $this->evaluate($channel, $requireOnline);

        if ($persist) {
            return DB::transaction(function () use ($channel, $snapshot, $result, $requireOnline): array {
                $lockedChannel = DistributionChannel::query()
                    ->whereKey((int) $channel->id)
                    ->lockForUpdate()
                    ->firstOrFail();
                $profile = HostedSiteProfile::query()
                    ->where('distribution_channel_id', (int) $lockedChannel->id)
                    ->lockForUpdate()
                    ->first();
                $structural = $this->evaluate($lockedChannel, $requireOnline, false);
                $checks = array_replace($result['checks'], $structural['checks'], [
                    'configuration_stable' => $lockedChannel->updated_at?->toISOString() === $snapshot['channel_updated_at']
                        && $profile?->updated_at?->toISOString() === $snapshot['profile_updated_at']
                        && $profile?->settings_version === $snapshot['settings_version'],
                ]);
                $passed = ! in_array(false, $checks, true);
                $failedChecks = array_keys(array_filter(
                    $checks,
                    static fn (bool $passed): bool => ! $passed
                ));
                $channelConfig = is_array($lockedChannel->channel_config)
                    ? $lockedChannel->channel_config
                    : [];
                $channelConfig['hosted_site_preflight'] = [
                    'passed' => $passed,
                    'checks' => $checks,
                    'failed_checks' => $failedChecks,
                    'checked_at' => now()->toISOString(),
                ];
                $lockedChannel->forceFill([
                    'last_health_status' => $passed ? 'ok' : 'failed',
                    'last_health_checked_at' => now(),
                    'last_error_message' => $passed
                        ? null
                        : 'Hosted site preflight failed: '.implode(', ', $failedChecks),
                    'channel_config' => $channelConfig,
                ])->save();
                $profile?->forceFill([
                    'quality_status' => $passed
                        ? HostedSiteProfile::QUALITY_PASSED
                        : HostedSiteProfile::QUALITY_BLOCKED,
                ])->save();

                return ['passed' => $passed, 'checks' => $checks];
            }, 3);
        }

        return $result;
    }

    /** @return array{passed:bool,checks:array<string,bool>} */
    private function evaluate(
        DistributionChannel $channel,
        bool $requireOnline = false,
        bool $includeTechnical = true,
    ): array {
        $channel->load('hostedSiteProfile');
        $profile = $channel->hostedSiteProfile;
        $settings = is_array($channel->site_settings) ? $channel->site_settings : [];
        $leadFormSlugs = collect((array) ($settings['lead_form_slugs'] ?? []))
            ->map(static fn (mixed $slug): string => trim((string) $slug))
            ->filter()
            ->unique()
            ->values()
            ->all();
        $activeLeadForms = $leadFormSlugs === []
            ? 0
            : LeadForm::query()
                ->whereIn('slug', $leadFormSlugs)
                ->where('status', LeadForm::STATUS_ACTIVE)
                ->count();
        $checks = [
            'channel_type' => $channel->isHostedSite(),
            'profile' => $profile instanceof HostedSiteProfile,
            'hostname' => $profile instanceof HostedSiteProfile
                && $this->resolver->rootDomainFor($profile->hostname) === $profile->root_domain
                && $profile->hostname === $channel->domain,
            'endpoint' => $profile instanceof HostedSiteProfile
                && $channel->endpoint_url === 'https://'.$profile->hostname,
            'topic' => $profile instanceof HostedSiteProfile && trim((string) $profile->topic) !== '',
            'identity' => trim((string) ($settings['site_name'] ?? '')) !== ''
                && trim((string) ($settings['site_description'] ?? '')) !== '',
            'about' => trim((string) ($settings['about_title'] ?? '')) !== ''
                && trim((string) ($settings['about_content'] ?? '')) !== '',
            'forms' => $activeLeadForms === count($leadFormSlugs),
            'theme' => in_array(
                (string) $channel->template_key,
                $this->themes->hostedCompatibleIds(),
                true
            ),
            'serving' => ! $requireOnline
                || $profile?->serving_status === HostedSiteProfile::SERVING_ONLINE,
        ];
        if ($includeTechnical && $profile instanceof HostedSiteProfile) {
            $checks = array_replace($checks, $this->technicalProbe->check($profile, $leadFormSlugs));
        }
        $passed = ! in_array(false, $checks, true);

        return ['passed' => $passed, 'checks' => $checks];
    }
}
