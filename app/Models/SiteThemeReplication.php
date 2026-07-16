<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SiteThemeReplication extends Model
{
    public const STATUS_QUEUED = 'queued';

    public const STATUS_FETCHING = 'fetching';

    public const STATUS_EXTRACTING = 'extracting';

    public const STATUS_ANALYZING = 'analyzing';

    public const STATUS_GENERATING = 'generating';

    public const STATUS_SCANNING = 'scanning';

    public const STATUS_ITERATING = 'iterating';

    public const STATUS_FAILED = 'failed';

    public const STATUS_READY = 'ready';

    public const STATUS_PUBLISHED = 'published';

    public const STATUS_ARCHIVED = 'archived';

    protected $fillable = [
        'name',
        'theme_id',
        'base_theme_id',
        'ai_model_id',
        'status',
        'home_url',
        'category_url',
        'article_url',
        'style_preference',
        'source_fingerprints',
        'analysis_json',
        'generated_files_json',
        'preview_snapshot_json',
        'current_version',
        'published_theme_path',
        'published_asset_path',
        'compliance_status',
        'compliance_report_json',
        'iteration_count',
        'error_message',
        'created_by_admin_id',
        'published_at',
    ];

    protected function casts(): array
    {
        return [
            'ai_model_id' => 'integer',
            'source_fingerprints' => 'array',
            'analysis_json' => 'array',
            'generated_files_json' => 'array',
            'preview_snapshot_json' => 'array',
            'current_version' => 'integer',
            'compliance_report_json' => 'array',
            'iteration_count' => 'integer',
            'created_by_admin_id' => 'integer',
            'published_at' => 'datetime',
        ];
    }

    public function logs(): HasMany
    {
        return $this->hasMany(SiteThemeReplicationLog::class, 'replication_id');
    }

    public function versions(): HasMany
    {
        return $this->hasMany(SiteThemeReplicationVersion::class, 'replication_id');
    }

    public function aiModel(): BelongsTo
    {
        return $this->belongsTo(AiModel::class, 'ai_model_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(Admin::class, 'created_by_admin_id');
    }

    public function isPreviewReady(): bool
    {
        return in_array((string) $this->status, [self::STATUS_READY, self::STATUS_PUBLISHED, self::STATUS_ARCHIVED], true)
            && ! empty($this->preview_snapshot_json);
    }

    public function canPublish(): bool
    {
        return (string) $this->status === self::STATUS_READY
            && (string) $this->compliance_status === 'passed';
    }

    public function canPackage(): bool
    {
        $manifest = $this->generated_files_json;
        $files = is_array($manifest) ? ($manifest['files'] ?? null) : null;
        $complianceReport = $this->compliance_report_json;

        return in_array((string) $this->status, [
            self::STATUS_READY,
            self::STATUS_PUBLISHED,
            self::STATUS_ARCHIVED,
        ], true)
            && (int) $this->current_version > 0
            && is_array($manifest)
            && $manifest !== []
            && is_array($files)
            && $files !== []
            && (string) $this->compliance_status === 'passed'
            && is_array($complianceReport)
            && ($complianceReport['passed'] ?? null) === true;
    }

    public function canBeArchived(): bool
    {
        return in_array((string) $this->status, [
            self::STATUS_READY,
            self::STATUS_PUBLISHED,
            self::STATUS_FAILED,
        ], true);
    }

    public function canDeleteDrafts(): bool
    {
        return in_array((string) $this->status, [
            self::STATUS_PUBLISHED,
            self::STATUS_ARCHIVED,
            self::STATUS_FAILED,
        ], true);
    }

    /**
     * @return array<int, string>
     */
    public function sourceDomains(): array
    {
        $domains = [];
        foreach (['home_url', 'category_url', 'article_url'] as $field) {
            $host = parse_url((string) $this->{$field}, PHP_URL_HOST);
            if (is_string($host) && $host !== '') {
                $domains[] = strtolower($host);
            }
        }

        return array_values(array_unique($domains));
    }
}
