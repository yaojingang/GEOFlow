<?php

namespace App\Services\GeoFlow;

use App\Models\DistributionChannel;
use App\Support\Site\HomepageModuleBuilder;
use App\Support\Site\SiteSettingsBag;
use App\Support\Site\SiteThemeCatalog;
use Illuminate\Support\Carbon;
use Throwable;

class FrontendExperienceInspector
{
    private const CACHE_STALE_HOURS = 24;

    public function __construct(
        private readonly SiteThemeCatalog $siteThemeCatalog,
        private readonly DistributionHttpClient $httpClient,
    ) {}

    /**
     * @return array<string,mixed>
     */
    public function inspect(?DistributionChannel $channel = null, bool $includeRemote = false, bool $liveRemote = false): array
    {
        $remoteTarget = $channel && $includeRemote
            ? $this->remoteTargetSurface($channel, $liveRemote)
            : null;

        return [
            'generated_at' => Carbon::now()->toISOString(),
            'remote_source' => $liveRemote ? 'live' : 'cache',
            'default_site' => $this->defaultSiteSurface(),
            'channel' => $channel ? $this->channelSurface($channel) : null,
            'target_package' => $this->targetPackageSurface(),
            'remote_target' => $remoteTarget,
            'differences' => $channel ? $this->differences($channel, $remoteTarget) : [],
            'sync_preview' => $channel ? $this->syncPreview($channel, $remoteTarget) : null,
        ];
    }

    /**
     * @return array<string,mixed>
     */
    public function refreshRemoteCapabilities(DistributionChannel $channel): array
    {
        $cache = $this->remoteTargetSurface($channel, true);
        $cache['source'] = 'cache';
        $cache['is_stale'] = false;

        $channel->fillFrontendCapabilitiesCache($cache)->save();

        $freshChannel = $channel->fresh();

        return $this->cachedRemoteTargetSurface($freshChannel instanceof DistributionChannel ? $freshChannel : $channel);
    }

    /**
     * @return array<string,mixed>
     */
    public function syncSummary(DistributionChannel $channel): array
    {
        $payload = $channel->targetSiteSettingsPayload();
        $style = is_array($payload['homepage_style'] ?? null) ? $payload['homepage_style'] : [];
        $modules = is_array($payload['homepage_modules'] ?? null) ? $payload['homepage_modules'] : [];
        $slides = is_array($payload['home_carousel_slides'] ?? null) ? $payload['home_carousel_slides'] : [];
        $articleTextAds = is_array($payload['article_text_ads'] ?? null) ? $payload['article_text_ads'] : [];

        return [
            'frontend_experience_mode' => (string) ($payload['frontend_experience_mode'] ?? $channel->frontendExperienceMode()),
            'active_theme' => (string) ($payload['active_theme'] ?? ''),
            'front_mode' => (string) ($payload['front_mode'] ?? $channel->frontMode()),
            'homepage_modules_count' => count($modules),
            'homepage_module_types' => array_values(array_unique(array_filter(array_map(
                static fn (mixed $module): string => is_array($module) ? (string) ($module['type'] ?? '') : '',
                $modules
            )))),
            'home_carousel_slides_count' => count($slides),
            'home_carousel_slide_titles' => array_values(array_filter(array_map(
                static fn (mixed $slide): string => is_array($slide) ? trim((string) ($slide['title'] ?? '')) : '',
                $slides
            ))),
            'homepage_style_keys' => array_keys($style),
            'article_text_ads_count' => count($articleTextAds),
            'payload_keys' => array_keys($payload),
        ];
    }

    /**
     * @return array<string,mixed>
     */
    public function syncPreview(DistributionChannel $channel, ?array $remoteTarget = null): array
    {
        $payload = $channel->targetSiteSettingsPayload();
        $remoteTarget ??= $this->remoteTargetSurface($channel, false);
        $summary = $this->syncSummary($channel);
        $warnings = $this->syncRiskWarnings($channel, $remoteTarget, $payload);
        $requiresConfirmation = collect($warnings)->contains(
            static fn (array $warning): bool => (bool) ($warning['requires_confirmation'] ?? false)
        );

        return [
            'channel' => [
                'id' => (int) $channel->id,
                'name' => (string) $channel->name,
                'domain' => (string) $channel->domain,
                'type' => $channel->channelType(),
                'endpoint_url' => (string) $channel->endpoint_url,
            ],
            'summary' => $summary,
            'remote_target' => $remoteTarget,
            'warnings' => $warnings,
            'requires_confirmation' => $requiresConfirmation,
            'settings_payload' => $payload,
            'settings_payload_json' => (string) json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        ];
    }

    /**
     * @param  iterable<DistributionChannel>  $channels
     * @return array<string,mixed>
     */
    public function syncPreviewForChannels(iterable $channels): array
    {
        $previews = [];
        foreach ($channels as $channel) {
            $previews[] = $this->syncPreview($channel);
        }

        $requiresConfirmation = collect($previews)->contains(
            static fn (array $preview): bool => (bool) ($preview['requires_confirmation'] ?? false)
        );

        return [
            'generated_at' => Carbon::now()->toISOString(),
            'channels' => $previews,
            'totals' => [
                'channels' => count($previews),
                'requires_confirmation' => collect($previews)
                    ->filter(static fn (array $preview): bool => (bool) ($preview['requires_confirmation'] ?? false))
                    ->count(),
                'warnings' => collect($previews)
                    ->flatMap(static fn (array $preview): array => is_array($preview['warnings'] ?? null) ? $preview['warnings'] : [])
                    ->count(),
            ],
            'requires_confirmation' => $requiresConfirmation,
        ];
    }

    public function requiresSyncConfirmation(DistributionChannel $channel): bool
    {
        if (! $channel->isGeoFlowAgent()) {
            return false;
        }

        return (bool) $this->syncPreview($channel)['requires_confirmation'];
    }

    /**
     * @return array<string,mixed>
     */
    private function defaultSiteSurface(): array
    {
        $settings = SiteSettingsBag::all();
        $modules = HomepageModuleBuilder::fromRaw((string) ($settings['homepage_modules'] ?? '[]'), false);
        $style = HomepageModuleBuilder::styleFromRaw((string) ($settings['homepage_style'] ?? '{}'));
        $slides = DistributionChannel::normalizeHomeCarouselSlides((string) ($settings['home_carousel_slides'] ?? '[]'));

        return [
            'surface' => 'default_site',
            'supports_first_party_frontend' => true,
            'theme_count' => count($this->siteThemeCatalog->all()),
            'active_theme' => trim((string) ($settings['active_theme'] ?? '')),
            'supported_modules' => HomepageModuleBuilder::TYPES,
            'homepage_modules_count' => count($modules),
            'homepage_style_keys' => array_keys($style),
            'home_carousel_slides_count' => count($slides),
            'supports_json_import' => true,
            'supports_static_generation' => true,
            'supports_article_text_ads' => true,
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function channelSurface(DistributionChannel $channel): array
    {
        $settings = $channel->resolvedSiteSettings();
        $syncSummary = $this->syncSummary($channel);

        return [
            'surface' => 'channel_site',
            'id' => (int) $channel->id,
            'name' => (string) $channel->name,
            'type' => $channel->channelType(),
            'endpoint_url' => (string) $channel->endpoint_url,
            'template_key' => (string) ($channel->template_key ?? ''),
            'front_mode' => $channel->frontMode(),
            'frontend_experience_mode' => $channel->frontendExperienceMode(),
            'supports_first_party_frontend' => $channel->isGeoFlowAgent(),
            'supported_modules' => $channel->isGeoFlowAgent() ? HomepageModuleBuilder::TYPES : [],
            'homepage_modules_count' => count(is_array($settings['homepage_modules'] ?? null) ? $settings['homepage_modules'] : []),
            'homepage_style_keys' => array_keys(is_array($settings['homepage_style'] ?? null) ? $settings['homepage_style'] : []),
            'home_carousel_slides_count' => count(is_array($settings['home_carousel_slides'] ?? null) ? $settings['home_carousel_slides'] : []),
            'settings_payload_keys' => array_keys($channel->targetSiteSettingsPayload()),
            'sync_summary' => $syncSummary,
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function targetPackageSurface(): array
    {
        return [
            'surface' => 'geoflow_agent_target_package',
            'capability_version' => '1.2',
            'supports_first_party_frontend' => true,
            'supported_modules' => HomepageModuleBuilder::TYPES,
            'supported_routes' => [
                '/',
                '/article/{slug}',
                '/llms.txt',
                '/sitemap.txt',
                '/geoflow-agent/v1/health',
                '/geoflow-agent/v1/site-settings',
                '/geoflow-agent/v1/frontend-capabilities',
            ],
            'supports_homepage_style' => true,
            'supports_home_carousel_slides' => true,
            'supports_article_text_ads' => true,
            'supports_static_generation' => true,
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function remoteTargetSurface(DistributionChannel $channel, bool $liveRemote): array
    {
        if (! $liveRemote) {
            return $this->cachedRemoteTargetSurface($channel);
        }

        return $this->liveRemoteTargetSurface($channel);
    }

    /**
     * @return array<string,mixed>
     */
    private function cachedRemoteTargetSurface(DistributionChannel $channel): array
    {
        $cache = $channel->frontendCapabilitiesCache();
        $cache['source'] = 'cache';
        $cache['is_stale'] = $this->isCacheStale($cache);
        $cache['stale_after_hours'] = self::CACHE_STALE_HOURS;

        return $cache;
    }

    /**
     * @return array<string,mixed>
     */
    private function liveRemoteTargetSurface(DistributionChannel $channel): array
    {
        if (! $channel->isGeoFlowAgent()) {
            return $this->remoteFailure(
                'not_applicable',
                'WordPress REST 和 Generic API 不作为 GEOFlow 前台渲染目标读取远端能力。'
            );
        }

        $channel->loadMissing('activeSecret');
        if (! $channel->activeSecret) {
            return $this->remoteFailure('missing_secret', '渠道尚未配置有效密钥，无法读取远端前台能力。');
        }

        try {
            return $this->normalizeRemoteCapabilities($this->httpClient->frontendCapabilities($channel));
        } catch (DistributionHttpException $e) {
            $status = $e->status() === 404 ? 'unsupported_or_not_found' : 'unavailable';

            return $this->remoteFailure(
                $status,
                $status === 'unsupported_or_not_found'
                    ? '远端目标包未暴露 frontend-capabilities 接口，可能仍是旧版本目标包。请重新下载并覆盖目标站点包。'
                    : $e->getMessage()
            );
        } catch (Throwable $e) {
            return $this->remoteFailure('unavailable', $e->getMessage());
        }
    }

    /**
     * @return array<string,mixed>
     */
    private function remoteFailure(string $status, string $message): array
    {
        $cache = DistributionChannel::normalizeFrontendCapabilitiesCache([
            'status' => $status,
            'checked_at' => Carbon::now()->toISOString(),
            'message' => $message,
            'reachable' => false,
        ]);
        $cache['source'] = 'live';
        $cache['is_stale'] = false;
        $cache['stale_after_hours'] = self::CACHE_STALE_HOURS;

        return $cache;
    }

    /**
     * @param  array<string,mixed>  $capabilities
     * @return array<string,mixed>
     */
    private function normalizeRemoteCapabilities(array $capabilities): array
    {
        $settings = is_array($capabilities['current_settings'] ?? null) ? $capabilities['current_settings'] : [];
        $cache = DistributionChannel::normalizeFrontendCapabilitiesCache([
            'status' => 'ok',
            'checked_at' => Carbon::now()->toISOString(),
            'message' => '远端前台能力读取成功。',
            'reachable' => true,
            'capability_version' => (string) ($capabilities['capability_version'] ?? ''),
            'package_version' => (string) ($capabilities['package_version'] ?? ''),
            'active_theme' => (string) ($capabilities['active_theme'] ?? ($settings['active_theme'] ?? '')),
            'front_mode' => (string) ($capabilities['front_mode'] ?? ($settings['front_mode'] ?? '')),
            'frontend_experience_mode' => (string) ($capabilities['frontend_experience_mode'] ?? ($settings['frontend_experience_mode'] ?? '')),
            'supported_modules' => is_array($capabilities['supported_modules'] ?? null) ? $capabilities['supported_modules'] : [],
            'supported_routes' => is_array($capabilities['supported_routes'] ?? null) ? $capabilities['supported_routes'] : [],
            'supports_homepage_style' => (bool) ($capabilities['supports_homepage_style'] ?? false),
            'supports_home_carousel_slides' => (bool) ($capabilities['supports_home_carousel_slides'] ?? false),
            'supports_article_text_ads' => (bool) ($capabilities['supports_article_text_ads'] ?? false),
            'supports_static_generation' => (bool) ($capabilities['supports_static_generation'] ?? false),
            'agent_base_url' => (string) ($capabilities['agent_base_url'] ?? ''),
        ]);
        $cache['source'] = 'live';
        $cache['is_stale'] = false;
        $cache['stale_after_hours'] = self::CACHE_STALE_HOURS;
        $cache['raw_keys'] = array_keys($capabilities);

        return $cache;
    }

    /**
     * @param  array<string,mixed>  $cache
     */
    private function isCacheStale(array $cache): bool
    {
        $checkedAt = trim((string) ($cache['checked_at'] ?? ''));
        if ($checkedAt === '') {
            return false;
        }

        try {
            return Carbon::parse($checkedAt)->lt(Carbon::now()->subHours(self::CACHE_STALE_HOURS));
        } catch (Throwable) {
            return true;
        }
    }

    /**
     * @param  array<string,mixed>  $remoteTarget
     * @param  array<string,mixed>  $payload
     * @return list<array{code:string,area:string,severity:string,message:string,requires_confirmation:bool}>
     */
    private function syncRiskWarnings(DistributionChannel $channel, array $remoteTarget, array $payload): array
    {
        $warnings = [];

        if (! $channel->isGeoFlowAgent()) {
            $warnings[] = [
                'code' => 'not_applicable',
                'area' => 'frontend_rendering',
                'severity' => 'info',
                'message' => 'WordPress REST 和 Generic API 不作为一等前台渲染目标，设置同步只做字段透传。',
                'requires_confirmation' => false,
            ];

            return $warnings;
        }

        $status = (string) ($remoteTarget['status'] ?? 'not_checked');
        if ($status !== 'ok') {
            $warnings[] = [
                'code' => $status,
                'area' => 'remote_capabilities',
                'severity' => in_array($status, ['not_checked', 'missing_secret'], true) ? 'notice' : 'warning',
                'message' => $this->remoteStatusMessage($status, (string) ($remoteTarget['message'] ?? '远端前台能力暂不可用。')),
                'requires_confirmation' => true,
            ];

            return $warnings;
        }

        if ((bool) ($remoteTarget['is_stale'] ?? false)) {
            $warnings[] = [
                'code' => 'cache_stale',
                'area' => 'remote_capabilities',
                'severity' => 'notice',
                'message' => '远端能力缓存已超过 '.self::CACHE_STALE_HOURS.' 小时，建议同步前手动刷新。',
                'requires_confirmation' => false,
            ];
        }

        $missingModules = array_values(array_diff(
            HomepageModuleBuilder::TYPES,
            is_array($remoteTarget['supported_modules'] ?? null) ? $remoteTarget['supported_modules'] : []
        ));
        if ($missingModules !== []) {
            $warnings[] = [
                'code' => 'missing_modules',
                'area' => 'remote_modules',
                'severity' => 'warning',
                'message' => '远端目标包未声明支持全部 '.count(HomepageModuleBuilder::TYPES).' 类首页模块：'.implode('、', $missingModules).'。请重新下载并覆盖目标站点包，或确认后继续同步。',
                'requires_confirmation' => true,
            ];
        }

        foreach ([
            'supports_homepage_style' => '首页样式',
            'supports_home_carousel_slides' => '首页轮播',
            'supports_article_text_ads' => '文章文字广告',
            'supports_static_generation' => '静态化',
        ] as $flag => $label) {
            if (! (bool) ($remoteTarget[$flag] ?? false)) {
                $warnings[] = [
                    'code' => $flag,
                    'area' => 'remote_support',
                    'severity' => 'warning',
                    'message' => '远端目标包未声明支持'.$label.'能力。',
                    'requires_confirmation' => true,
                ];
            }
        }

        $remoteFrontMode = (string) ($remoteTarget['front_mode'] ?? '');
        $payloadFrontMode = (string) ($payload['front_mode'] ?? '');
        if ($remoteFrontMode !== '' && $payloadFrontMode !== '' && $remoteFrontMode !== $payloadFrontMode) {
            $warnings[] = [
                'code' => 'front_mode_mismatch',
                'area' => 'front_mode',
                'severity' => 'warning',
                'message' => '远端 front_mode 为 '.$remoteFrontMode.'，本次 payload 将同步 '.$payloadFrontMode.'。',
                'requires_confirmation' => true,
            ];
        }

        $remoteTheme = (string) ($remoteTarget['active_theme'] ?? '');
        $payloadTheme = (string) ($payload['active_theme'] ?? '');
        if ($remoteTheme !== '' && $payloadTheme !== '' && $remoteTheme !== $payloadTheme) {
            $warnings[] = [
                'code' => 'theme_mismatch',
                'area' => 'theme',
                'severity' => 'notice',
                'message' => '远端当前主题为 '.$remoteTheme.'，本次 payload 将同步 '.$payloadTheme.'。',
                'requires_confirmation' => true,
            ];
        }

        return $warnings;
    }

    private function remoteStatusMessage(string $status, string $fallback): string
    {
        return match ($status) {
            'not_checked' => '尚未检查远端前台能力。请先刷新能力；如需继续，也可以在预览页确认同步。',
            'missing_secret' => '渠道缺少有效密钥，无法检查或同步 GeoFlow Agent 目标站。',
            'unsupported_or_not_found' => '远端目标包未暴露 frontend-capabilities 接口，可能是旧包。请重新下载并覆盖目标站点包，或确认后继续同步。',
            'unavailable' => $fallback !== '' ? $fallback : '远端站点不可达。',
            default => $fallback,
        };
    }

    /**
     * @return list<array<string,string>>
     */
    private function differences(DistributionChannel $channel, ?array $remoteTarget = null): array
    {
        $differences = [];

        if (! $channel->isGeoFlowAgent()) {
            $differences[] = [
                'area' => 'frontend_rendering',
                'severity' => 'info',
                'message' => 'WordPress REST 和 Generic API 作为外部分发渠道处理，不保证渲染 GEOFlow 首页模块。',
            ];

            return $differences;
        }

        if ($channel->frontendExperienceMode() === DistributionChannel::FRONTEND_EXPERIENCE_INHERIT_DEFAULT) {
            $differences[] = [
                'area' => 'homepage_experience',
                'severity' => 'ok',
                'message' => '渠道前台体验跟随默认站，保存时无需复制模块配置。',
            ];
        }

        if ($channel->frontendExperienceMode() === DistributionChannel::FRONTEND_EXPERIENCE_SNAPSHOT_DEFAULT) {
            $differences[] = [
                'area' => 'homepage_experience',
                'severity' => 'notice',
                'message' => '渠道使用默认站快照，后续默认站调整不会自动覆盖该渠道。',
            ];
        }

        if ($channel->frontendExperienceMode() === DistributionChannel::FRONTEND_EXPERIENCE_CUSTOM) {
            $differences[] = [
                'area' => 'homepage_experience',
                'severity' => 'notice',
                'message' => '渠道使用自定义前台体验，需要单独维护首页模块、样式和轮播。',
            ];
        }

        if ($remoteTarget !== null) {
            $remoteStatus = (string) ($remoteTarget['status'] ?? 'unknown');
            if ($remoteStatus !== 'ok') {
                $differences[] = [
                    'area' => 'remote_capabilities',
                    'severity' => $remoteStatus === 'missing_secret' ? 'notice' : 'warning',
                    'message' => (string) ($remoteTarget['message'] ?? '远端前台能力暂不可用。'),
                ];

                return $differences;
            }

            $warnings = $this->syncRiskWarnings($channel, $remoteTarget, $channel->targetSiteSettingsPayload());
            foreach ($warnings as $warning) {
                $differences[] = [
                    'area' => $warning['area'],
                    'severity' => $warning['severity'],
                    'message' => $warning['message'],
                ];
            }
        }

        return $differences;
    }
}
