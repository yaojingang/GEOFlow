<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class DistributionChannel extends Model
{
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

        return in_array($type, ['geoflow_agent', 'wordpress_rest'], true) ? $type : 'geoflow_agent';
    }

    public function isGeoFlowAgent(): bool
    {
        return $this->channelType() === 'geoflow_agent';
    }

    public function isWordPressRest(): bool
    {
        return $this->channelType() === 'wordpress_rest';
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
