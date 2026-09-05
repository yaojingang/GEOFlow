<?php

namespace App\Services\Admin\Analytics;

use App\Models\AiVisibilityCompetitor;
use App\Models\AiVisibilityRun;
use Illuminate\Support\Collection;

class AiVisibilityCompetitorReportService
{
    /**
     * 统计近 N 天采样中各竞品的出现频率与关联关键词。
     *
     * 采样次数以「一次采集」为单位：同一关键词同一天的多个提供商记录合并为一次采样。
     *
     * @return array{
     *     days: int,
     *     total_samples: int,
     *     keywords_sampled: int,
     *     competitors: list<array{
     *         id: int,
     *         name: string,
     *         aliases: list<string>,
     *         mentions: int,
     *         samples_mentioned: int,
     *         mention_rate: float,
     *         keywords: list<string>,
     *     }>,
     * }
     */
    public function stats(int $days = 30): array
    {
        $competitors = AiVisibilityCompetitor::query()
            ->where('is_active', true)
            ->orderBy('id')
            ->get();

        $samples = $this->samples(now()->subDays($days));

        $totalSamples = $samples->count();
        $keywordsSampled = $samples->pluck('keyword')->filter()->unique()->count();

        $rows = $competitors->map(function (AiVisibilityCompetitor $competitor) use ($samples, $totalSamples): array {
            $terms = $competitor->matchTerms();
            $mentions = 0;
            $samplesMentioned = 0;
            $keywords = [];

            foreach ($samples as $sample) {
                $hits = 0;
                foreach ($terms as $term) {
                    $hits += mb_substr_count($sample['text'], $term);
                }
                if ($hits > 0) {
                    $mentions += $hits;
                    $samplesMentioned++;
                    if ($sample['keyword'] !== '') {
                        $keywords[$sample['keyword']] = true;
                    }
                }
            }

            return [
                'id' => $competitor->id,
                'name' => $competitor->name,
                'aliases' => array_slice($terms, 1),
                'mentions' => $mentions,
                'samples_mentioned' => $samplesMentioned,
                'mention_rate' => $totalSamples > 0 ? round($samplesMentioned / $totalSamples * 100, 1) : 0.0,
                'keywords' => array_keys($keywords),
            ];
        })->sortByDesc('mentions')->values()->all();

        return [
            'days' => $days,
            'total_samples' => $totalSamples,
            'keywords_sampled' => $keywordsSampled,
            'competitors' => $rows,
        ];
    }

    /**
     * @return Collection<int, array{keyword: string, text: string, key: string}>
     */
    private function samples($since): Collection
    {
        return AiVisibilityRun::query()
            ->where('status', 'completed')
            ->whereIn('provider_type', [
                AiVisibilityRun::PROVIDER_DEEPSEEK_ANALYSIS,
                AiVisibilityRun::PROVIDER_DOUBAO_ARK_RESPONSES,
            ])
            ->whereNotNull('keyword')
            ->where('keyword', '!=', '')
            ->where('updated_at', '>=', $since)
            ->orderBy('id')
            ->get()
            ->map(static fn (AiVisibilityRun $run): array => [
                // 一次采集 = 关键词 + 日期（同关键词同日多提供商合并计数）。
                'key' => $run->keyword.'|'.$run->updated_at->toDateString(),
                'keyword' => (string) $run->keyword,
                'text' => (string) $run->answer_text,
            ])
            ->unique('key')
            ->values();
    }
}
