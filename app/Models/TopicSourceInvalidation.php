<?php

namespace App\Models;

use App\Services\Topics\TopicReadModel;
use App\Services\Topics\TopicViewBuilder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Schema;

class TopicSourceInvalidation extends Model
{
    public $timestamps = false;

    protected $fillable = ['topic_id', 'topic_revision_id', 'basis_key', 'article_id', 'reason', 'invalidated_at'];

    protected function casts(): array
    {
        return ['topic_id' => 'integer', 'topic_revision_id' => 'integer', 'article_id' => 'integer', 'invalidated_at' => 'datetime'];
    }

    public function topic(): BelongsTo
    {
        return $this->belongsTo(Topic::class)->withTrashed();
    }

    public static function registerSourceObservers(): void
    {
        if (app()->bound('topics.source_observers_registered')) {
            return;
        }
        app()->instance('topics.source_observers_registered', true);
        $invalidate = static function (int $articleId): void {
            if (app()->resolved(TopicReadModel::class)) {
                app(TopicReadModel::class)->reset();
            }
            if (Schema::hasTable('topic_source_invalidations')) {
                app(TopicViewBuilder::class)->invalidateArticle($articleId);
            }
        };
        Article::updated(static fn (Article $article) => $invalidate((int) $article->id));
        Article::deleted(static fn (Article $article) => $invalidate((int) $article->id));
        HostedSiteArticleAssignment::updated(static fn (HostedSiteArticleAssignment $assignment) => $invalidate((int) $assignment->article_id));
        HostedSiteArticleAssignment::deleted(static fn (HostedSiteArticleAssignment $assignment) => $invalidate((int) $assignment->article_id));
        ArticleReview::created(static fn (ArticleReview $review) => $invalidate((int) $review->article_id));
        ArticleRiskScan::created(static fn (ArticleRiskScan $scan) => $invalidate((int) $scan->article_id));
        $invalidateTask = static function (Task $task) use ($invalidate): void {
            foreach (Article::query()->useWritePdo()->where('task_id', $task->id)->pluck('id') as $articleId) {
                $invalidate((int) $articleId);
            }
        };
        Task::updated(static function (Task $task) use ($invalidateTask): void {
            if ($task->wasChanged(['publish_scope', 'need_review'])) {
                $invalidateTask($task);
            }
        });
        Task::deleted($invalidateTask);
    }

    public static function record(int $topicId, string $basisKey, int $articleId, string $reason, ?int $revisionId = null): void
    {
        static::query()->insertOrIgnore([
            'topic_id' => $topicId, 'topic_revision_id' => $revisionId, 'basis_key' => $basisKey, 'article_id' => $articleId,
            'reason' => $reason, 'invalidated_at' => now(),
        ]);
    }
}
