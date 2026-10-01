<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\DB;

class Topic extends Model
{
    use HasFactory;
    use SoftDeletes {
        restore as private restoreSoftDeletedModel;
    }

    protected $fillable = [
        'path_generation', 'site_key', 'display_order', 'slug', 'title', 'normalized_title_key', 'owner_admin_id', 'task_id',
        'draft_payload', 'draft_source_hashes', 'draft_score_binding', 'publication_requests', 'withdrawal_requests', 'review_records', 'published_by_admin_id', 'withdrawn_by_admin_id', 'draft_version', 'public_revision_id', 'pending_revision_id',
        'draft_content_composed_at', 'freshness_revision_id', 'freshness_status', 'freshness_checked_at', 'freshness_next_check_at',
        'result_completed_at', 'control_version', 'approved_revision_id', 'approved_by_admin_id', 'approved_at', 'manual_edit_version', 'maintenance_task_id', 'maintenance_paused_at', 'maintenance_settings', 'last_maintenance_signature', 'next_maintenance_at',
        'submitted_at', 'first_published_at', 'published_at', 'withdrawn_at',
    ];

    protected $attributes = ['path_generation' => 1, 'draft_version' => 1, 'manual_edit_version' => 0, 'control_version' => 0];

    protected static function booted(): void
    {
        TopicSourceInvalidation::registerSourceObservers();
        static::updating(function (Topic $topic): void {
            if ($topic->isDirty(['maintenance_paused_at', 'withdrawn_at'])) {
                $topic->control_version = (int) $topic->getOriginal('control_version') + 1;
            }
        });
        static::deleting(function (Topic $topic): void {
            if (! $topic->isForceDeleting()) {
                $topic->forceFill(['maintenance_paused_at' => $topic->maintenance_paused_at ?? now()])->save();
            }
        });
    }

    protected function casts(): array
    {
        return [
            'path_generation' => 'integer', 'display_order' => 'integer', 'owner_admin_id' => 'integer', 'task_id' => 'integer', 'draft_payload' => 'array', 'draft_source_hashes' => 'array', 'draft_score_binding' => 'array', 'publication_requests' => 'array', 'withdrawal_requests' => 'array', 'review_records' => 'array',
            'published_by_admin_id' => 'integer', 'withdrawn_by_admin_id' => 'integer',
            'draft_version' => 'integer', 'public_revision_id' => 'integer', 'approved_revision_id' => 'integer', 'approved_by_admin_id' => 'integer', 'approved_at' => 'datetime', 'pending_revision_id' => 'integer',
            'draft_content_composed_at' => 'datetime', 'freshness_revision_id' => 'integer', 'freshness_checked_at' => 'datetime', 'freshness_next_check_at' => 'datetime',
            'result_completed_at' => 'datetime', 'control_version' => 'integer', 'manual_edit_version' => 'integer', 'maintenance_task_id' => 'integer', 'maintenance_paused_at' => 'datetime',
            'maintenance_settings' => 'array', 'next_maintenance_at' => 'datetime',
            'submitted_at' => 'datetime', 'first_published_at' => 'datetime',
            'published_at' => 'datetime', 'withdrawn_at' => 'datetime',
        ];
    }

    public function restore(): bool
    {
        return DB::transaction(function (): bool {
            $locked = static::withTrashed()->whereKey($this->getKey())->lockForUpdate()->first();
            if ($locked === null || ! $locked->trashed()) {
                return false;
            }
            $this->setRawAttributes($locked->getAttributes(), true);
            $this->setRelations([]);
            $this->forceFill([
                'withdrawn_at' => null, 'public_revision_id' => null, 'approved_revision_id' => null, 'approved_by_admin_id' => null, 'approved_at' => null, 'pending_revision_id' => null, 'submitted_at' => null,
                'draft_version' => $this->draft_version + 1, 'manual_edit_version' => $this->manual_edit_version + 1,
                'maintenance_paused_at' => $this->maintenance_paused_at ?? now(),
            ]);
            if (! $this->restoreSoftDeletedModel()) {
                return false;
            }
            foreach ($this->draft_source_hashes ?? [] as $articleId => $hash) {
                TopicSourceInvalidation::record((int) $this->id, 'draft:'.$this->id.':'.$hash, (int) $articleId, 'topic_restored');
            }

            return true;
        }, attempts: 3);
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(Admin::class, 'owner_admin_id');
    }

    public function task(): BelongsTo
    {
        return $this->belongsTo(Task::class);
    }

    public function revisions(): HasMany
    {
        return $this->hasMany(TopicRevision::class)->orderByDesc('number');
    }

    public function publicRevision(): BelongsTo
    {
        return $this->belongsTo(TopicRevision::class, 'public_revision_id')->useWritePdo();
    }

    public function pendingRevision(): BelongsTo
    {
        return $this->belongsTo(TopicRevision::class, 'pending_revision_id');
    }
}
