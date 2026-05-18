<?php

namespace App\Services\Geo;

use App\Models\GeoArticleAudit;
use App\Models\GeoArticleDraft;
use App\Models\GeoPublishRetest;
use App\Models\GeoTask;
use Illuminate\Support\Str;

class GeoPostPublishRetestRunner
{
    public function run(GeoTask $task, GeoArticleDraft $draft): GeoPublishRetest
    {
        $task->loadMissing('brandProfile');
        $draft->loadMissing(['article', 'writingTask']);

        $article = $draft->article;
        abort_unless($article !== null, 404);

        $latestAudit = $draft->audits()->latest()->first();
        $question = $this->question($draft);
        $beforeScore = (int) $task->total_score;
        $afterScore = $this->afterScore($task, $draft, $latestAudit);
        $articleUrl = route('site.article', ['slug' => $article->slug]);

        return GeoPublishRetest::query()->create([
            'organization_id' => $draft->organization_id,
            'article_id' => $article->id,
            'geo_article_draft_id' => $draft->id,
            'before_score' => $beforeScore,
            'after_score' => $afterScore,
            'status' => 'completed',
            'article_url' => $articleUrl,
            'summary' => '复测问题：'.$question.'。发布前得分 '.$beforeScore.'，复测得分 '.$afterScore.'。当前为本地确定性复测，后续可接真实 AI 搜索批次。',
            'metadata' => [
                'mode' => 'deterministic_mock',
                'article_id' => $article->id,
                'geo_task_id' => $task->id,
                'geo_article_draft_id' => $draft->id,
                'latest_audit_id' => $latestAudit?->id,
                'question' => $question,
            ],
            'tested_at' => now(),
        ]);
    }

    private function afterScore(GeoTask $task, GeoArticleDraft $draft, ?GeoArticleAudit $latestAudit): int
    {
        $content = trim((string) ($draft->article?->title."\n".$draft->article?->excerpt."\n".$draft->article?->content));
        $brief = (array) ($draft->writingTask?->brief ?? []);
        $question = $this->question($draft);
        $score = max((int) ($latestAudit?->score ?? 0), (int) $task->total_score);

        if ($question !== '' && Str::contains($content, $question)) {
            $score += 4;
        }

        $serviceArea = trim((string) ($task->brandProfile?->service_area ?? ''));
        if ($serviceArea !== '' && Str::contains($content, $serviceArea)) {
            $score += 4;
        }

        if (! empty($brief['references'])) {
            $score += 4;
        }

        return min(100, max(0, $score));
    }

    private function question(GeoArticleDraft $draft): string
    {
        $brief = (array) ($draft->writingTask?->brief ?? []);
        $question = trim((string) ($brief['question'] ?? ''));

        return $question !== '' ? $question : trim((string) $draft->seo_title);
    }
}
