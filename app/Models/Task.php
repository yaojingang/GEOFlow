<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;

class Task extends Model
{
    use SoftDeletes {
        restore as private restoreSoftDeletedModel;
    }

    public const TRASH_RETENTION_DAYS = 90;

    protected $dateFormat = 'Y-m-d H:i:s.u';

    protected $table = 'tasks';

    public function delete()
    {
        return DB::transaction(function (): bool {
            $locked = static::withTrashed()
                ->whereKey($this->getKey())
                ->lockForUpdate()
                ->first();
            if (! $locked || ($locked->trashed() && ! $this->isForceDeleting())) {
                return false;
            }

            $this->setRawAttributes($locked->getAttributes(), true);

            return (bool) parent::delete();
        });
    }

    public function restore()
    {
        return DB::transaction(fn () => $this->restoreWithLock());
    }

    private function restoreWithLock(): bool
    {
        $locked = static::withTrashed()
            ->whereKey($this->getKey())
            ->lockForUpdate()
            ->first();
        if (! $locked || ! $locked->trashed()) {
            return false;
        }

        $this->setRawAttributes($locked->getAttributes(), true);

        return (bool) $this->restoreSoftDeletedModel();
    }

    protected static function booted(): void
    {
        static::deleting(function (Task $task): void {
            if ($task->isForceDeleting()) {
                return;
            }

            if (! Schema::hasTable('task_trash_entries') || ! Schema::hasTable('task_trash_state')) {
                throw new RuntimeException('Task trash schema is not ready. Run database migrations before deleting tasks.');
            }

            $state = DB::table('task_trash_state')
                ->where('id', 1)
                ->lockForUpdate()
                ->first(['last_sequence']);
            if (! $state) {
                throw new RuntimeException('Task trash sequence state is missing.');
            }

            $sequence = (int) $state->last_sequence + 1;
            DB::table('task_trash_state')->where('id', 1)->update([
                'last_sequence' => $sequence,
            ]);

            DB::table('task_trash_entries')->insert([
                'task_id' => (int) $task->id,
                'sequence' => $sequence,
                'deleted_at' => now()->format('Y-m-d H:i:s.u'),
            ]);
        });

        static::restoring(function (Task $task): void {
            if (Schema::hasTable('task_trash_entries')) {
                DB::table('task_trash_entries')->where('task_id', $task->id)->delete();
            }
        });
    }

    protected $fillable = [
        'name',
        'title_library_id',
        'image_library_id',
        'image_count',
        'prompt_id',
        'ai_model_id',
        'author_id',
        'need_review',
        'publish_interval',
        'author_type',
        'custom_author_id',
        'auto_keywords',
        'auto_description',
        'draft_limit',
        'article_limit',
        'is_loop',
        'model_selection_mode',
        'status',
        'publish_scope',
        'distribution_strategy',
        'distribution_cursor',
        'created_count',
        'published_count',
        'loop_count',
        'knowledge_base_id',
        'category_mode',
        'fixed_category_id',
        'last_run_at',
        'next_run_at',
        'next_publish_at',
        'last_success_at',
        'last_error_at',
        'last_error_message',
        'schedule_enabled',
        'max_retry_count',
        'ai_quality_enabled',
        'ai_quality_retrieval_mode',
        'ai_quality_policy_version',
        'ai_quality_config_version',
        'ai_quality_timeout_sampling_enabled',
        'ai_quality_auto_optimize_enabled',
        'ai_quality_optimization_level',
        'ai_quality_prompt_id',
        'ai_quality_model_id',
        'ai_quality_pass_score',
        'ai_quality_manual_override_min_score',
    ];

    protected $attributes = [
        'ai_quality_enabled' => false,
        'ai_quality_policy_version' => 1,
        'ai_quality_config_version' => 1,
        'ai_quality_timeout_sampling_enabled' => false,
        'ai_quality_auto_optimize_enabled' => false,
        'ai_quality_optimization_level' => 'excellent_80',
        'ai_quality_pass_score' => 85,
        'ai_quality_manual_override_min_score' => 70,
    ];

    protected function casts(): array
    {
        return [
            'title_library_id' => 'integer',
            'image_library_id' => 'integer',
            'image_count' => 'integer',
            'prompt_id' => 'integer',
            'ai_model_id' => 'integer',
            'author_id' => 'integer',
            'need_review' => 'integer',
            'publish_interval' => 'integer',
            'custom_author_id' => 'integer',
            'auto_keywords' => 'integer',
            'auto_description' => 'integer',
            'draft_limit' => 'integer',
            'article_limit' => 'integer',
            'is_loop' => 'integer',
            'distribution_cursor' => 'integer',
            'created_count' => 'integer',
            'published_count' => 'integer',
            'loop_count' => 'integer',
            'knowledge_base_id' => 'integer',
            'fixed_category_id' => 'integer',
            'last_run_at' => 'datetime',
            'next_run_at' => 'datetime',
            'next_publish_at' => 'datetime',
            'last_success_at' => 'datetime',
            'last_error_at' => 'datetime',
            'schedule_enabled' => 'integer',
            'max_retry_count' => 'integer',
            'ai_quality_enabled' => 'boolean',
            'ai_quality_policy_version' => 'integer',
            'ai_quality_config_version' => 'integer',
            'ai_quality_timeout_sampling_enabled' => 'boolean',
            'ai_quality_auto_optimize_enabled' => 'boolean',
            'ai_quality_prompt_id' => 'integer',
            'ai_quality_model_id' => 'integer',
            'ai_quality_pass_score' => 'integer',
            'ai_quality_manual_override_min_score' => 'integer',
        ];
    }

    public function titleLibrary(): BelongsTo
    {
        return $this->belongsTo(TitleLibrary::class, 'title_library_id');
    }

    public function imageLibrary(): BelongsTo
    {
        return $this->belongsTo(ImageLibrary::class, 'image_library_id');
    }

    public function prompt(): BelongsTo
    {
        return $this->belongsTo(Prompt::class, 'prompt_id');
    }

    public function aiModel(): BelongsTo
    {
        return $this->belongsTo(AiModel::class, 'ai_model_id');
    }

    public function qualityPrompt(): BelongsTo
    {
        return $this->belongsTo(Prompt::class, 'ai_quality_prompt_id');
    }

    public function qualityModel(): BelongsTo
    {
        return $this->belongsTo(AiModel::class, 'ai_quality_model_id');
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(Author::class, 'author_id');
    }

    public function customAuthor(): BelongsTo
    {
        return $this->belongsTo(Author::class, 'custom_author_id');
    }

    public function knowledgeBase(): BelongsTo
    {
        return $this->belongsTo(KnowledgeBase::class, 'knowledge_base_id');
    }

    public function knowledgeBases(): BelongsToMany
    {
        return $this->belongsToMany(KnowledgeBase::class, 'task_knowledge_bases')
            ->withPivot(['sort_order'])
            ->withTimestamps()
            ->orderByPivot('sort_order')
            ->orderBy('knowledge_bases.id');
    }

    public function fixedCategory(): BelongsTo
    {
        return $this->belongsTo(Category::class, 'fixed_category_id');
    }

    public function articles(): HasMany
    {
        return $this->hasMany(Article::class, 'task_id');
    }

    public function taskSchedules(): HasMany
    {
        return $this->hasMany(TaskSchedule::class, 'task_id');
    }

    public function taskRuns(): HasMany
    {
        return $this->hasMany(TaskRun::class, 'task_id');
    }

    public function aiQualityChecks(): HasMany
    {
        return $this->hasMany(ArticleAiQualityCheck::class);
    }

    public function aiOptimizationRuns(): HasMany
    {
        return $this->hasMany(ArticleAiOptimizationRun::class);
    }

    public function distributionChannels(): BelongsToMany
    {
        return $this->belongsToMany(DistributionChannel::class, 'task_distribution_channels')
            ->withPivot(['trigger', 'remote_status', 'failure_policy', 'max_attempts', 'sort_order'])
            ->withTimestamps()
            ->orderByPivot('sort_order')
            ->orderBy('distribution_channels.id');
    }
}
