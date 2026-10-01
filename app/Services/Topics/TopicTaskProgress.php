<?php

namespace App\Services\Topics;

use App\Models\Topic;
use App\Models\TopicBuildRun;
use Illuminate\Support\Facades\Schema;

final class TopicTaskProgress
{
    /** @param list<int> $taskIds @return array<int,array<string,int>> */
    public function forTasks(array $taskIds): array
    {
        if (! Schema::hasTable('topics')) {
            return [];
        }
        $counts = [];
        $completed = TopicBuildRun::query()->whereIn('task_id', $taskIds)->whereNotNull('title_id')->whereNotNull('topic_id')->whereIn('status', ['completed', 'publication_failed'])->get(['task_id', 'topic_id'])->groupBy('task_id');
        $rows = Topic::withTrashed()->whereIn('task_id', $taskIds)->with('publicRevision.articles')->get();
        $views = [];
        $publicViews = [];
        foreach ($rows->whereNull('deleted_at')->groupBy('site_key') as $site => $siteTopics) {
            foreach ($siteTopics->chunk(100) as $batch) {
                app(TopicViewBuilder::class)->withReadBatch($site, $batch, function () use ($batch, &$views, &$publicViews): void {
                    foreach ($batch as $topic) {
                        $views[$topic->id] = app(TopicService::class)->previewView($topic);
                        $publicViews[$topic->id] = app(TopicService::class)->publicView($topic);
                    }
                });
            }
        }
        $topics = $rows->groupBy('task_id');
        foreach ($taskIds as $id) {
            $generatedIds = ($completed[$id] ?? collect())->pluck('topic_id')->all();
            $published = $drafts = $waiting = $review = 0;
            foreach ($topics[$id] ?? [] as $topic) {
                if ($topic->result_completed_at !== null) {
                    $generatedIds[] = $topic->id;
                }
                if ($topic->trashed()) {
                    continue;
                }
                $payload = $topic->draft_payload ?? [];
                $view = $views[$topic->id];
                $complete = trim((string) $view['intro']) !== '' && $view['article_count'] >= 2;
                if ($topic->pending_revision_id !== null) {
                    $review++;
                }
                if (($publicViews[$topic->id] ?? null) !== null) {
                    $published++;
                } elseif ($topic->pending_revision_id !== null) {
                    continue;
                } elseif ($complete) {
                    $drafts++;
                } else {
                    $waiting++;
                }
            }
            $counts[$id] = ['created_topics' => count(array_unique($generatedIds)), 'published_topics' => $published, 'draft_topics' => $drafts, 'waiting_topics' => $waiting, 'review_topics' => $review];
        }

        return $counts;
    }
}
