<?php

namespace App\Services\Geo;

use App\Models\Article;
use App\Models\GeoArticleAudit;
use App\Models\GeoArticleDraft;
use App\Models\GeoPublishRecord;
use App\Models\GeoPublishTarget;
use App\Models\GeoTask;
use Illuminate\Support\Facades\DB;

class GeoYixiaoerHandoffService
{
    /**
     * @param  list<string>  $platformCodes
     */
    public function create(GeoTask $task, GeoArticleDraft $draft, array $platformCodes): GeoPublishRecord
    {
        $task->loadMissing('brandProfile');
        $draft->loadMissing(['article', 'writingTask', 'audits']);

        if (! $draft->article instanceof Article) {
            throw new \InvalidArgumentException('请先将草稿转为正式文章，再生成蚁小二交接');
        }

        $audit = $this->latestPassingAudit($draft);
        if (! $audit instanceof GeoArticleAudit) {
            throw new \InvalidArgumentException('请先完成并通过发布前 GEO 检查，再生成蚁小二交接');
        }

        $platformCodes = $this->normalizePlatformCodes($platformCodes);
        if ($platformCodes === []) {
            throw new \InvalidArgumentException('请至少选择一个蚁小二目标平台');
        }

        return DB::transaction(function () use ($task, $draft, $audit, $platformCodes): GeoPublishRecord {
            $target = GeoPublishTarget::query()->firstOrCreate(
                [
                    'organization_id' => $draft->organization_id,
                    'type' => 'yixiaoer',
                ],
                [
                    'name' => '蚁小二发布交接',
                    'endpoint' => 'yixiaoer://handoff',
                    'status' => 'active',
                ]
            );

            return GeoPublishRecord::query()->updateOrCreate(
                [
                    'geo_article_draft_id' => $draft->id,
                    'geo_publish_target_id' => $target->id,
                ],
                [
                    'platform_codes' => $platformCodes,
                    'handoff_payload' => $this->payload($task, $draft, $audit, $platformCodes),
                    'status' => 'ready_handoff',
                    'target_url' => '',
                    'error_message' => null,
                    'published_at' => null,
                ]
            );
        });
    }

    private function latestPassingAudit(GeoArticleDraft $draft): ?GeoArticleAudit
    {
        /** @var GeoArticleAudit|null $audit */
        $audit = $draft->audits()
            ->latest()
            ->first();

        if (! $audit instanceof GeoArticleAudit) {
            return null;
        }

        return (int) $audit->score >= 80 && ((array) $audit->failed_checks) === [] ? $audit : null;
    }

    /**
     * @param  list<string>  $platformCodes
     * @return list<string>
     */
    private function normalizePlatformCodes(array $platformCodes): array
    {
        $allowed = ['xiaohongshu', 'douyin', 'shipinhao', 'bilibili'];

        return collect($platformCodes)
            ->map(static fn (mixed $code): string => trim((string) $code))
            ->filter(static fn (string $code): bool => in_array($code, $allowed, true))
            ->unique()
            ->values()
            ->all();
    }

    /**
     * @param  list<string>  $platformCodes
     * @return array<string, mixed>
     */
    private function payload(GeoTask $task, GeoArticleDraft $draft, GeoArticleAudit $audit, array $platformCodes): array
    {
        $article = $draft->article;
        $brief = (array) ($draft->writingTask?->brief ?? []);

        return [
            'channel' => 'yixiaoer',
            'action' => 'draft_publish_handoff',
            'platform_codes' => $platformCodes,
            'article' => [
                'id' => $article?->id,
                'title' => (string) ($article?->title ?? $draft->title),
                'excerpt' => (string) ($article?->excerpt ?? $draft->summary),
                'content_markdown' => (string) ($article?->content ?? $draft->content_markdown),
                'keywords' => (string) ($article?->keywords ?? ''),
                'meta_description' => (string) ($article?->meta_description ?? $draft->seo_description),
                'is_ai_generated' => (bool) ($article?->is_ai_generated ?? true),
            ],
            'geo_audit' => [
                'score' => (int) $audit->score,
                'passed_checks' => (array) $audit->passed_checks,
                'failed_checks' => (array) $audit->failed_checks,
                'checked_at' => $audit->created_at?->toDateTimeString(),
            ],
            'provenance' => [
                'source' => (string) ($brief['source'] ?? 'geo_report'),
                'geo_task_id' => (int) $task->id,
                'geo_article_draft_id' => (int) $draft->id,
                'geo_writing_task_id' => (int) $draft->geo_writing_task_id,
                'brand_profile_id' => (int) $task->brand_profile_id,
                'reference_urls' => collect((array) ($brief['references'] ?? []))
                    ->map(static fn (mixed $reference): string => trim((string) (((array) $reference)['url'] ?? '')))
                    ->filter()
                    ->values()
                    ->all(),
            ],
            'handoff_note' => 'GEOAmplify 只生成发布交接 payload，实际素材上传和平台发布默认由蚁小二执行。',
            'generated_at' => now()->toDateTimeString(),
        ];
    }
}
