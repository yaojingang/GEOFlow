<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Task extends Model
{
    protected $table = 'tasks';

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
}
