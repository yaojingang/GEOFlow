<?php

namespace App\Services\Geo;

use App\Models\GeoCitationSource;
use App\Models\GeoWritingTask;
use App\Models\Organization;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class GeoReferenceBriefBuilder
{
    /**
     * @param  Collection<int, GeoCitationSource>  $sources
     */
    public function build(Organization $organization, Collection $sources, ?string $title = null): GeoWritingTask
    {
        $references = $sources
            ->map(function (GeoCitationSource $source): ?array {
                $snapshot = $source->latestPageSnapshot;
                $score = $snapshot?->latestScore;
                if (! $snapshot || ! $score) {
                    return null;
                }

                return [
                    'source_id' => $source->id,
                    'url' => $source->url,
                    'domain' => $source->domain,
                    'title' => $snapshot->title ?: $source->title,
                    'summary' => $snapshot->content_summary,
                    'score' => (int) $score->total_score,
                    'suggested_usage' => $score->suggested_usage,
                    'score_reason' => $score->score_reason,
                    'content_excerpt' => Str::limit((string) $snapshot->content_text, 360, ''),
                ];
            })
            ->filter()
            ->sortByDesc('score')
            ->values();

        if ($references->isEmpty()) {
            throw new \InvalidArgumentException('没有可用于生成简报的已评分参考内容');
        }

        $briefTitle = trim((string) $title);
        if ($briefTitle === '') {
            $briefTitle = '参考内容简报 - '.now()->format('m-d H:i');
        }

        return DB::transaction(function () use ($organization, $briefTitle, $references): GeoWritingTask {
            return GeoWritingTask::query()->create([
                'organization_id' => $organization->id,
                'geo_report_id' => null,
                'geo_keyword_id' => null,
                'title' => $briefTitle,
                'status' => 'pending',
                'brief' => [
                    'source' => 'reference_content',
                    'generated_at' => now()->toDateTimeString(),
                    'references' => $references->all(),
                    'recommended_outline' => $this->recommendedOutline($references),
                    'angles' => $this->angles($references),
                    'evidence_points' => $this->evidencePoints($references),
                ],
            ]);
        });
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $references
     * @return list<string>
     */
    private function recommendedOutline(Collection $references): array
    {
        $topTitle = (string) ($references->first()['title'] ?? '核心参考内容');

        return [
            '先用一句话回答用户最关心的问题',
            '结合 '.$topTitle.' 提炼选择标准',
            '补充本地案例、报价、板材、流程、售后等可验证事实',
            '用 FAQ 收尾，覆盖用户搜索时会追问的问题',
        ];
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $references
     * @return list<string>
     */
    private function angles(Collection $references): array
    {
        return $references
            ->take(5)
            ->map(static fn (array $reference): string => '参考「'.(string) $reference['title'].'」的结构，围绕 '.(string) $reference['domain'].' 中的高频信息补充本地化事实')
            ->values()
            ->all();
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $references
     * @return list<string>
     */
    private function evidencePoints(Collection $references): array
    {
        return $references
            ->flatMap(static function (array $reference): array {
                $summary = trim((string) ($reference['summary'] ?? ''));

                return $summary === '' ? [] : [$summary];
            })
            ->take(6)
            ->values()
            ->all();
    }
}
