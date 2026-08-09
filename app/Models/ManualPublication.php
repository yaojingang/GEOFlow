<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ManualPublication extends Model
{
    public const TYPE_POST = 'post';

    public const TYPE_COMMENT = 'comment';

    public const TYPES = [self::TYPE_POST, self::TYPE_COMMENT];

    public const STATUS_DRAFT = 'draft';

    public const STATUS_READY = 'ready';

    public const STATUS_IN_PROGRESS = 'in_progress';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_FAILED = 'failed';

    public const STATUS_SKIPPED = 'skipped';

    public const STATUS_CANCELLED = 'cancelled';

    public const STATUSES = [
        self::STATUS_DRAFT,
        self::STATUS_READY,
        self::STATUS_IN_PROGRESS,
        self::STATUS_COMPLETED,
        self::STATUS_FAILED,
        self::STATUS_SKIPPED,
        self::STATUS_CANCELLED,
    ];

    public const REOPENABLE_STATUSES = [
        self::STATUS_FAILED,
        self::STATUS_SKIPPED,
        self::STATUS_CANCELLED,
    ];

    public const MAX_CONTENT_CHARACTERS = 2000;

    protected $attributes = [
        'type' => self::TYPE_POST,
        'status' => self::STATUS_DRAFT,
        'risk_status' => 'clean',
        'duplicate_warning_count' => 0,
        'revision' => 1,
    ];

    protected $fillable = [
        'type',
        'article_id',
        'persona_id',
        'account_id',
        'assigned_admin_id',
        'created_by_admin_id',
        'platform',
        'custom_platform',
        'target_url',
        'target_url_hash',
        'target_context',
        'content',
        'content_fingerprint',
        'source_snapshot',
        'identity_snapshot',
        'disclosure_snapshot',
        'risk_status',
        'risk_result',
        'duplicate_warning_count',
        'scheduled_at',
        'status',
        'status_changed_at',
        'completion_url',
        'result_note',
        'completed_at',
        'revision',
    ];

    protected function casts(): array
    {
        return [
            'article_id' => 'integer',
            'persona_id' => 'integer',
            'account_id' => 'integer',
            'assigned_admin_id' => 'integer',
            'created_by_admin_id' => 'integer',
            'source_snapshot' => 'array',
            'identity_snapshot' => 'array',
            'risk_result' => 'array',
            'duplicate_warning_count' => 'integer',
            'scheduled_at' => 'datetime',
            'status_changed_at' => 'datetime',
            'completed_at' => 'datetime',
            'revision' => 'integer',
        ];
    }

    public function article(): BelongsTo
    {
        return $this->belongsTo(Article::class, 'article_id')->withTrashed();
    }

    public function persona(): BelongsTo
    {
        return $this->belongsTo(ManualPublicationPersona::class, 'persona_id');
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(ManualPublicationAccount::class, 'account_id');
    }

    public function assignee(): BelongsTo
    {
        return $this->belongsTo(Admin::class, 'assigned_admin_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(Admin::class, 'created_by_admin_id');
    }

    public function transitions(): HasMany
    {
        return $this->hasMany(ManualPublicationTransition::class);
    }

    public function personaDisplayName(): ?string
    {
        $snapshotName = trim((string) data_get($this->identity_snapshot, 'persona.name'));

        return $snapshotName !== '' ? $snapshotName : $this->persona?->name;
    }

    public function accountDisplayName(): ?string
    {
        $snapshotName = trim((string) data_get($this->identity_snapshot, 'account.account_name'));

        return $snapshotName !== '' ? $snapshotName : $this->account?->account_name;
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeVisibleTo(Builder $query, Admin $admin): Builder
    {
        if ($admin->isSuperAdmin()) {
            return $query;
        }

        return $query->where('assigned_admin_id', $admin->getKey());
    }

    /** @return list<string> */
    public static function allowedNextStatuses(string $status): array
    {
        return match ($status) {
            self::STATUS_DRAFT => [self::STATUS_READY, self::STATUS_CANCELLED],
            self::STATUS_READY => [self::STATUS_IN_PROGRESS, self::STATUS_CANCELLED],
            self::STATUS_IN_PROGRESS => [self::STATUS_COMPLETED, self::STATUS_FAILED, self::STATUS_SKIPPED, self::STATUS_CANCELLED],
            self::STATUS_FAILED, self::STATUS_SKIPPED, self::STATUS_CANCELLED => [self::STATUS_READY],
            default => [],
        };
    }

    public function canTransitionTo(string $status): bool
    {
        return in_array($status, self::allowedNextStatuses((string) $this->status), true);
    }

    public function isReopenTransition(string $status): bool
    {
        return in_array((string) $this->status, self::REOPENABLE_STATUSES, true)
            && $status === self::STATUS_READY;
    }

    public function platformDisplayName(): string
    {
        return $this->platform === ManualPublicationAccount::PLATFORM_CUSTOM
            ? (string) ($this->custom_platform ?: __('admin.manual_publications.platform.custom'))
            : (string) __('admin.manual_publications.platform.'.$this->platform);
    }
}
