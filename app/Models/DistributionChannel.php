<?php

namespace App\Models;

use App\Support\Site\ArticleTextAdPicker;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class DistributionChannel extends Model
{
    public const MAX_CUSTOM_TEXT_AD_MODULES_PER_PLACEMENT = 5;

    protected $fillable = [
        'name',
        'domain',
        'endpoint_url',
        'channel_type',
        'front_mode',
        'template_key',
        'site_settings',
        'channel_config',
        'status',
        'description',
        'last_health_status',
        'last_health_checked_at',
        'last_error_message',
        'created_by_admin_id',
    ];

    protected function casts(): array
    {
        return [
            'created_by_admin_id' => 'integer',
            'last_health_checked_at' => 'datetime',
            'site_settings' => 'array',
            'channel_config' => 'array',
        ];
    }

    /**
     * @return array{
     *   site_name:string,
     *   site_subtitle:string,
     *   site_description:string,
     *   site_keywords:string,
     *   copyright_info:string,
     *   site_logo:string,
     *   site_favicon:string,
     *   seo_title_template:string,
     *   seo_description_template:string,
     *   featured_limit:int,
     *   per_page:int
     * }
     */
    public function resolvedSiteSettings(): array
    {
        $stored = is_array($this->site_settings) ? $this->site_settings : [];
        $rawSiteName = $stored['site_name'] ?? $this->name ?? 'GEOFlow Target Site';
        $siteName = trim((string) $rawSiteName);

        return [
            'site_name' => $siteName !== '' ? $siteName : 'GEOFlow Target Site',
            'site_subtitle' => trim((string) ($stored['site_subtitle'] ?? '')),
            'site_description' => trim((string) ($stored['site_description'] ?? '由 GEOFlow 自动分发和管理的目标站点。')),
            'site_keywords' => trim((string) ($stored['site_keywords'] ?? '')),
            'copyright_info' => trim((string) ($stored['copyright_info'] ?? '© '.date('Y').' '.($siteName !== '' ? $siteName : 'GEOFlow Target Site'))),
            'site_logo' => trim((string) ($stored['site_logo'] ?? '')),
            'site_favicon' => trim((string) ($stored['site_favicon'] ?? '')),
            'seo_title_template' => trim((string) ($stored['seo_title_template'] ?? '{title} - {site_name}')),
            'seo_description_template' => trim((string) ($stored['seo_description_template'] ?? '{description}')),
            'featured_limit' => min(100, max(1, (int) ($stored['featured_limit'] ?? 6))),
            'per_page' => min(200, max(1, (int) ($stored['per_page'] ?? 12))),
        ];
    }

    /**
     * @return array<string,mixed>
     */
    public function targetSiteSettingsPayload(): array
    {
        return $this->resolvedSiteSettings() + [
            'active_theme' => (string) ($this->template_key ?? ''),
            'front_mode' => $this->frontMode(),
            'article_text_ads' => $this->effectiveArticleTextAds(),
        ];
    }

    public function frontMode(): string
    {
        $mode = (string) ($this->front_mode ?? 'static');

        return in_array($mode, ['static', 'rewrite'], true) ? $mode : 'static';
    }

    public function usesStaticFront(): bool
    {
        return $this->frontMode() === 'static';
    }

    public function channelType(): string
    {
        $type = (string) ($this->channel_type ?? 'geoflow_agent');

        return in_array($type, ['geoflow_agent', 'wordpress_rest', 'generic_http_api'], true) ? $type : 'geoflow_agent';
    }

    public function isGeoFlowAgent(): bool
    {
        return $this->channelType() === 'geoflow_agent';
    }

    public function isWordPressRest(): bool
    {
        return $this->channelType() === 'wordpress_rest';
    }

    public function isGenericHttpApi(): bool
    {
        return $this->channelType() === 'generic_http_api';
    }

    /**
     * @return array{
     *   wordpress_username:string,
     *   wordpress_post_status:string,
     *   wordpress_category_strategy:string,
     *   wordpress_fixed_category:string,
     *   wordpress_tag_strategy:string,
     *   wordpress_image_strategy:string,
     *   wordpress_content_format:string
     * }
     */
    public function resolvedChannelConfig(): array
    {
        $stored = is_array($this->channel_config) ? $this->channel_config : [];
        $postStatus = (string) ($stored['wordpress_post_status'] ?? 'publish');
        $categoryStrategy = (string) ($stored['wordpress_category_strategy'] ?? 'match_or_create');
        $tagStrategy = (string) ($stored['wordpress_tag_strategy'] ?? 'keywords_to_tags');
        $imageStrategy = (string) ($stored['wordpress_image_strategy'] ?? 'upload_to_media');

        return [
            'wordpress_username' => trim((string) ($stored['wordpress_username'] ?? '')),
            'wordpress_post_status' => in_array($postStatus, ['publish', 'draft', 'pending', 'private'], true) ? $postStatus : 'publish',
            'wordpress_category_strategy' => in_array($categoryStrategy, ['match_or_create', 'match_only', 'fixed'], true) ? $categoryStrategy : 'match_or_create',
            'wordpress_fixed_category' => trim((string) ($stored['wordpress_fixed_category'] ?? '')),
            'wordpress_tag_strategy' => in_array($tagStrategy, ['keywords_to_tags', 'disabled'], true) ? $tagStrategy : 'keywords_to_tags',
            'wordpress_image_strategy' => in_array($imageStrategy, ['upload_to_media', 'keep_original'], true) ? $imageStrategy : 'upload_to_media',
            'wordpress_content_format' => 'html',
        ];
    }

    /**
     * @return array{
     *   generic_auth_type:string,
     *   generic_basic_username:string,
     *   generic_header_name:string,
     *   generic_hmac_key_id_header:string,
     *   generic_hmac_signature_header:string,
     *   generic_hmac_timestamp_header:string,
     *   generic_hmac_nonce_header:string,
     *   generic_hmac_body_hash_header:string,
     *   generic_timeout_seconds:int,
     *   generic_success_statuses:list<int>,
     *   generic_health_method:string,
     *   generic_health_path:string,
     *   generic_publish_method:string,
     *   generic_publish_path:string,
     *   generic_update_method:string,
     *   generic_update_path:string,
     *   generic_delete_method:string,
     *   generic_delete_path:string,
     *   generic_settings_method:string,
     *   generic_settings_path:string,
     *   generic_remote_id_path:string,
     *   generic_remote_url_path:string,
     *   generic_payload_wrapper:string
     * }
     */
    public function resolvedGenericHttpConfig(): array
    {
        $stored = is_array($this->channel_config) ? $this->channel_config : [];
        $authType = (string) ($stored['generic_auth_type'] ?? 'bearer');
        $payloadWrapper = (string) ($stored['generic_payload_wrapper'] ?? 'none');

        return [
            'generic_auth_type' => in_array($authType, ['none', 'bearer', 'basic', 'header_key', 'hmac'], true) ? $authType : 'bearer',
            'generic_basic_username' => trim((string) ($stored['generic_basic_username'] ?? '')),
            'generic_header_name' => trim((string) ($stored['generic_header_name'] ?? 'X-API-Key')) ?: 'X-API-Key',
            'generic_hmac_key_id_header' => trim((string) ($stored['generic_hmac_key_id_header'] ?? 'X-GEOFlow-Key-Id')) ?: 'X-GEOFlow-Key-Id',
            'generic_hmac_signature_header' => trim((string) ($stored['generic_hmac_signature_header'] ?? 'X-GEOFlow-Signature')) ?: 'X-GEOFlow-Signature',
            'generic_hmac_timestamp_header' => trim((string) ($stored['generic_hmac_timestamp_header'] ?? 'X-GEOFlow-Timestamp')) ?: 'X-GEOFlow-Timestamp',
            'generic_hmac_nonce_header' => trim((string) ($stored['generic_hmac_nonce_header'] ?? 'X-GEOFlow-Nonce')) ?: 'X-GEOFlow-Nonce',
            'generic_hmac_body_hash_header' => trim((string) ($stored['generic_hmac_body_hash_header'] ?? 'X-GEOFlow-Body-SHA256')) ?: 'X-GEOFlow-Body-SHA256',
            'generic_timeout_seconds' => min(120, max(5, (int) ($stored['generic_timeout_seconds'] ?? 30))),
            'generic_success_statuses' => $this->genericSuccessStatuses($stored['generic_success_statuses'] ?? [200, 201, 202, 204]),
            'generic_health_method' => $this->genericHttpMethod($stored['generic_health_method'] ?? 'GET', ['GET', 'POST'], 'GET'),
            'generic_health_path' => $this->genericPath($stored['generic_health_path'] ?? '/health'),
            'generic_publish_method' => $this->genericHttpMethod($stored['generic_publish_method'] ?? 'POST', ['POST', 'PUT', 'PATCH'], 'POST'),
            'generic_publish_path' => $this->genericPath($stored['generic_publish_path'] ?? '/articles'),
            'generic_update_method' => $this->genericHttpMethod($stored['generic_update_method'] ?? 'POST', ['POST', 'PUT', 'PATCH'], 'POST'),
            'generic_update_path' => $this->genericPath($stored['generic_update_path'] ?? '/articles/{remote_id}'),
            'generic_delete_method' => $this->genericHttpMethod($stored['generic_delete_method'] ?? 'DELETE', ['DELETE', 'POST'], 'DELETE'),
            'generic_delete_path' => $this->genericPath($stored['generic_delete_path'] ?? '/articles/{remote_id}'),
            'generic_settings_method' => $this->genericHttpMethod($stored['generic_settings_method'] ?? 'POST', ['POST', 'PUT', 'PATCH'], 'POST'),
            'generic_settings_path' => $this->genericPath($stored['generic_settings_path'] ?? ''),
            'generic_remote_id_path' => trim((string) ($stored['generic_remote_id_path'] ?? 'id')),
            'generic_remote_url_path' => trim((string) ($stored['generic_remote_url_path'] ?? 'url')),
            'generic_payload_wrapper' => in_array($payloadWrapper, ['none', 'data'], true) ? $payloadWrapper : 'none',
        ];
    }

    /**
     * @return array{
     *   content_top:array{mode:string,module_ids:list<string>,ad_ids:list<string>,custom_modules:list<array<string,mixed>>},
     *   content_bottom:array{mode:string,module_ids:list<string>,ad_ids:list<string>,custom_modules:list<array<string,mixed>>}
     * }
     */
    public function resolvedArticleTextAdPolicy(): array
    {
        $stored = is_array($this->channel_config) ? $this->channel_config : [];

        return self::normalizeArticleTextAdPolicy($stored['article_text_ad_policy'] ?? null);
    }

    /**
     * @return array<int, array<string,mixed>>
     */
    public function effectiveArticleTextAds(): array
    {
        $policy = $this->resolvedArticleTextAdPolicy();
        $globalModules = ArticleTextAdPicker::all(true);
        $effectiveModules = [];

        foreach (ArticleTextAdPicker::PLACEMENTS as $placement) {
            $placementPolicy = $policy[$placement] ?? self::defaultArticleTextAdPolicyForPlacement();
            $mode = (string) ($placementPolicy['mode'] ?? 'inherit');
            if ($mode === 'disabled') {
                continue;
            }

            if ($mode === 'custom') {
                $effectiveModules = array_merge(
                    $effectiveModules,
                    ArticleTextAdPicker::normalizeModules(
                        $placementPolicy['custom_modules'] ?? [],
                        true,
                        self::MAX_CUSTOM_TEXT_AD_MODULES_PER_PLACEMENT
                    )
                );

                continue;
            }

            $modulesForPlacement = array_values(array_filter(
                $globalModules,
                static fn (array $module): bool => ($module['placement'] ?? '') === $placement
            ));

            if ($mode === 'selected') {
                $selectedModuleIds = $placementPolicy['module_ids'] ?? [];
                $legacyAdIds = $placementPolicy['ad_ids'] ?? [];
                $selectedIds = $selectedModuleIds !== [] ? $selectedModuleIds : $legacyAdIds;
                $modulesForPlacement = array_values(array_filter(
                    $modulesForPlacement,
                    static fn (array $module): bool => ArticleTextAdPicker::moduleOrLinkMatchesIds($module, $selectedIds)
                ));
            }

            $effectiveModules = array_merge($effectiveModules, $modulesForPlacement);
        }

        return array_values($effectiveModules);
    }

    /**
     * @return array{
     *   content_top:array{mode:string,module_ids:list<string>,ad_ids:list<string>,custom_modules:list<array<string,mixed>>},
     *   content_bottom:array{mode:string,module_ids:list<string>,ad_ids:list<string>,custom_modules:list<array<string,mixed>>}
     * }
     */
    public static function normalizeArticleTextAdPolicy(mixed $policy): array
    {
        $default = [
            'content_top' => self::defaultArticleTextAdPolicyForPlacement(),
            'content_bottom' => self::defaultArticleTextAdPolicyForPlacement(),
        ];

        if (is_string($policy)) {
            $mode = self::normalizeArticleTextAdMode($policy);

            return [
                'content_top' => self::defaultArticleTextAdPolicyForPlacement($mode),
                'content_bottom' => self::defaultArticleTextAdPolicyForPlacement($mode),
            ];
        }

        if (! is_array($policy)) {
            return $default;
        }

        if (array_key_exists('mode', $policy)) {
            $mode = self::normalizeArticleTextAdMode((string) ($policy['mode'] ?? 'inherit'));
            $moduleIds = self::normalizeArticleTextAdIds($policy['module_ids'] ?? []);
            $adIds = self::normalizeArticleTextAdIds($policy['ad_ids'] ?? []);
            $customModules = ArticleTextAdPicker::normalizeModules(
                $policy['custom_modules'] ?? [],
                false,
                self::MAX_CUSTOM_TEXT_AD_MODULES_PER_PLACEMENT
            );

            return [
                'content_top' => self::defaultArticleTextAdPolicyForPlacement($mode, $moduleIds, $adIds, $customModules),
                'content_bottom' => self::defaultArticleTextAdPolicyForPlacement($mode, $moduleIds, $adIds, $customModules),
            ];
        }

        foreach (ArticleTextAdPicker::PLACEMENTS as $placement) {
            $placementPolicy = is_array($policy[$placement] ?? null) ? $policy[$placement] : [];
            $default[$placement] = self::defaultArticleTextAdPolicyForPlacement(
                self::normalizeArticleTextAdMode((string) ($placementPolicy['mode'] ?? 'inherit')),
                self::normalizeArticleTextAdIds($placementPolicy['module_ids'] ?? []),
                self::normalizeArticleTextAdIds($placementPolicy['ad_ids'] ?? []),
                self::normalizeCustomArticleTextAdModules($placementPolicy['custom_modules'] ?? [], $placement)
            );
        }

        return $default;
    }

    /**
     * @param  list<string>  $moduleIds
     * @param  list<string>  $adIds
     * @param  list<array<string,mixed>>  $customModules
     * @return array{mode:string,module_ids:list<string>,ad_ids:list<string>,custom_modules:list<array<string,mixed>>}
     */
    private static function defaultArticleTextAdPolicyForPlacement(
        string $mode = 'inherit',
        array $moduleIds = [],
        array $adIds = [],
        array $customModules = []
    ): array {
        return [
            'mode' => self::normalizeArticleTextAdMode($mode),
            'module_ids' => array_values($moduleIds),
            'ad_ids' => array_values($adIds),
            'custom_modules' => array_values($customModules),
        ];
    }

    private static function normalizeArticleTextAdMode(string $mode): string
    {
        return in_array($mode, ['inherit', 'disabled', 'selected', 'custom'], true) ? $mode : 'inherit';
    }

    /**
     * @return list<array<string,mixed>>
     */
    private static function normalizeCustomArticleTextAdModules(mixed $modules, string $placement): array
    {
        $normalized = ArticleTextAdPicker::normalizeModules($modules, false, self::MAX_CUSTOM_TEXT_AD_MODULES_PER_PLACEMENT);

        return array_values(array_filter(
            $normalized,
            static fn (array $module): bool => ($module['placement'] ?? '') === $placement
        ));
    }

    /**
     * @return list<string>
     */
    private static function normalizeArticleTextAdIds(mixed $adIds): array
    {
        if (! is_array($adIds)) {
            return [];
        }

        $normalized = [];
        foreach ($adIds as $adId) {
            $value = trim((string) $adId);
            if ($value === '' || mb_strlen($value) > 120 || in_array($value, $normalized, true)) {
                continue;
            }
            $normalized[] = $value;
        }

        return $normalized;
    }

    /**
     * @param  mixed  $value
     * @return list<int>
     */
    private function genericSuccessStatuses(mixed $value): array
    {
        $items = is_array($value) ? $value : explode(',', (string) $value);
        $statuses = [];
        foreach ($items as $item) {
            $status = (int) $item;
            if ($status >= 100 && $status <= 599 && ! in_array($status, $statuses, true)) {
                $statuses[] = $status;
            }
        }

        return $statuses !== [] ? $statuses : [200, 201, 202, 204];
    }

    /**
     * @param  list<string>  $allowed
     */
    private function genericHttpMethod(mixed $method, array $allowed, string $default): string
    {
        $method = strtoupper(trim((string) $method));

        return in_array($method, $allowed, true) ? $method : $default;
    }

    private function genericPath(mixed $path): string
    {
        $path = trim((string) $path);
        if ($path === '') {
            return '';
        }

        return str_starts_with($path, '/') ? $path : '/'.$path;
    }

    public function wordpressRestBaseUrl(): string
    {
        $base = rtrim((string) $this->endpoint_url, '/');

        return str_ends_with($base, '/wp-json') ? $base : $base.'/wp-json';
    }

    public function secrets(): HasMany
    {
        return $this->hasMany(DistributionChannelSecret::class);
    }

    public function activeSecret(): HasOne
    {
        return $this->hasOne(DistributionChannelSecret::class)
            ->where('status', 'active')
            ->latestOfMany();
    }

    public function tasks(): BelongsToMany
    {
        return $this->belongsToMany(Task::class, 'task_distribution_channels')
            ->withPivot(['trigger', 'remote_status', 'failure_policy', 'max_attempts'])
            ->withTimestamps();
    }

    public function articleDistributions(): HasMany
    {
        return $this->hasMany(ArticleDistribution::class);
    }

    public function logs(): HasMany
    {
        return $this->hasMany(DistributionLog::class);
    }
}
