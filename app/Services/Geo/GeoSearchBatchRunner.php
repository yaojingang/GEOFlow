<?php

namespace App\Services\Geo;

use App\Models\BrandProfile;
use App\Models\GeoAiSearchAnswer;
use App\Models\GeoAiSearchQuestion;
use App\Models\GeoAiSearchRun;
use App\Models\GeoCitationOccurrence;
use App\Models\GeoCitationSource;
use App\Models\PointLog;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Throwable;

class GeoSearchBatchRunner
{
    public function __construct(
        private readonly GeoAIPlatformClient $client,
        private readonly GeoAIAnswerAnalyzer $analyzer,
        private readonly GeoExternalQaOptimizationPlanner $optimizationPlanner
    ) {}

    public function run(GeoAiSearchRun $run): GeoAiSearchRun
    {
        $run->loadMissing(['organization', 'brandProfile', 'questions.opportunity']);
        if ($run->status === 'completed') {
            return $run;
        }

        $this->assertEnoughPoints($run);

        $run->forceFill([
            'status' => 'running',
            'started_at' => $run->started_at ?? now(),
            'finished_at' => null,
            'error_message' => null,
        ])->save();

        $platformCodes = $run->platform_codes ?: ['deepseek_mock'];
        foreach ($run->questions as $question) {
            $this->runQuestion($run, $question, $run->brandProfile, $platformCodes);
            $this->updateRunProgress($run, false);
        }

        $this->updateRunProgress($run, true);
        $this->recordPointCost($run->refresh());

        return $run->fresh(['questions', 'answers']);
    }

    public function assertEnoughPoints(GeoAiSearchRun $run): void
    {
        $run->loadMissing('organization');
        $pointsCost = max(0, (int) $run->points_cost);
        if ($pointsCost <= 0 || $this->hasPointCharge($run)) {
            return;
        }

        $availablePoints = (int) ($run->organization?->points ?? 0);
        if ($availablePoints < $pointsCost) {
            throw new InvalidArgumentException('组织剩余点数不足：当前 '.$availablePoints.' 点，本次需要 '.$pointsCost.' 点');
        }
    }

    private function updateRunProgress(GeoAiSearchRun $run, bool $isFinished): void
    {
        $completedQuestions = (int) $run->questions()->where('status', 'completed')->count();
        $failedQuestions = (int) $run->questions()->where('status', 'failed')->count();
        $averageScore = (int) round((float) $run->answers()->where('status', 'succeeded')->avg('visibility_score'));

        $status = 'running';
        if ($isFinished) {
            $status = match (true) {
                $failedQuestions === 0 => 'completed',
                $completedQuestions > 0 => 'partial_failed',
                default => 'failed',
            };
        }

        $payload = [
            'status' => $status,
            'completed_questions' => $completedQuestions,
            'failed_questions' => $failedQuestions,
            'average_score' => $averageScore,
        ];

        if ($isFinished) {
            $payload['finished_at'] = now();
            $keywordMetrics = $this->optimizationPlanner->summarizeRun($run);
            $previousKeywordHitRate = $run->previous_keyword_hit_rate;

            if ($previousKeywordHitRate === null && $this->isExternalInspectionRun($run)) {
                $run->loadMissing(['organization', 'brandProfile']);
                if ($run->organization !== null && $run->brandProfile !== null) {
                    $previousKeywordHitRate = $this->optimizationPlanner->latestCompletedKeywordHitRate(
                        $run->organization,
                        $run->brandProfile,
                        (int) $run->id
                    );
                }
            }

            $payload['previous_keyword_hit_rate'] = $previousKeywordHitRate;
            $payload['baseline_keyword_hit_rate'] = $run->baseline_keyword_hit_rate ?? $previousKeywordHitRate;
            $payload['keyword_hit_rate'] = $keywordMetrics['keyword_hit_rate'];
            $payload['keyword_hit_count'] = $keywordMetrics['keyword_hit_count'];
            $payload['keyword_check_count'] = $keywordMetrics['keyword_check_count'];
            $payload['keyword_hit_rate_delta'] = $previousKeywordHitRate === null
                ? null
                : $keywordMetrics['keyword_hit_rate'] - (int) $previousKeywordHitRate;
        }

        $run->forceFill($payload)->save();
    }

    private function isExternalInspectionRun(GeoAiSearchRun $run): bool
    {
        return $run->questions()
            ->whereHas('opportunity', fn ($query) => $query->where('generation_source', 'external_qa_inspection'))
            ->exists();
    }

    private function recordPointCost(GeoAiSearchRun $run): void
    {
        $pointsCost = max(0, (int) $run->points_cost);
        if ($pointsCost <= 0 || $this->hasPointCharge($run) || ! in_array($run->status, ['completed', 'partial_failed'], true)) {
            return;
        }

        DB::transaction(function () use ($run, $pointsCost): void {
            $run = GeoAiSearchRun::query()
                ->whereKey($run->id)
                ->lockForUpdate()
                ->with('organization')
                ->firstOrFail();

            if ($this->hasPointCharge($run)) {
                return;
            }

            $run->organization()->decrement('points', $pointsCost);
            PointLog::query()->create([
                'organization_id' => $run->organization_id,
                'admin_id' => $run->created_by_admin_id,
                'action' => 'geo_search_run',
                'points_delta' => -$pointsCost,
                'ref_type' => GeoAiSearchRun::class,
                'ref_id' => $run->id,
                'remark' => 'GEO AI 搜索批次消耗',
            ]);
        });
    }

    private function hasPointCharge(GeoAiSearchRun $run): bool
    {
        if (! $run->exists) {
            return false;
        }

        return PointLog::query()
            ->where('action', 'geo_search_run')
            ->where('ref_type', GeoAiSearchRun::class)
            ->where('ref_id', $run->id)
            ->exists();
    }

    /**
     * @param  list<string>  $platformCodes
     */
    private function runQuestion(GeoAiSearchRun $run, GeoAiSearchQuestion $question, BrandProfile $brandProfile, array $platformCodes): void
    {
        $question->forceFill(['status' => 'running'])->save();
        $succeeded = 0;
        $lastError = null;

        foreach ($platformCodes as $platformCode) {
            $prompt = $this->buildPrompt($brandProfile, $question);

            try {
                $rawAnswer = $this->client->askPrompt((string) $platformCode, $brandProfile, $prompt);
                $analysis = $this->analyzer->analyze($brandProfile, (int) $run->organization_id, $rawAnswer);
                $answer = GeoAiSearchAnswer::query()->updateOrCreate(
                    [
                        'geo_ai_search_question_id' => $question->id,
                        'platform_code' => (string) $platformCode,
                    ],
                    [
                        'geo_ai_search_run_id' => $run->id,
                        'geo_keyword_opportunity_id' => $question->geo_keyword_opportunity_id,
                        'prompt' => $prompt,
                        'raw_answer' => $rawAnswer,
                        'status' => 'succeeded',
                        'error_message' => null,
                        'brand_mentioned' => $analysis['brand_mentioned'],
                        'competitors_mentioned' => $analysis['competitors_mentioned'],
                        'citations' => $analysis['citations'],
                        'source_urls' => $analysis['source_urls'],
                        'visibility_score' => $analysis['visibility_score'],
                        'analysis_json' => $analysis['analysis_json'],
                        'answered_at' => now(),
                    ]
                );

                $this->recordCitationSources($run, $answer, $analysis['source_urls']);
                $succeeded++;
            } catch (Throwable $exception) {
                $lastError = mb_substr($exception->getMessage(), 0, 1000);
                GeoAiSearchAnswer::query()->updateOrCreate(
                    [
                        'geo_ai_search_question_id' => $question->id,
                        'platform_code' => (string) $platformCode,
                    ],
                    [
                        'geo_ai_search_run_id' => $run->id,
                        'geo_keyword_opportunity_id' => $question->geo_keyword_opportunity_id,
                        'prompt' => $prompt,
                        'raw_answer' => null,
                        'status' => 'failed',
                        'error_message' => $lastError,
                        'brand_mentioned' => false,
                        'competitors_mentioned' => [],
                        'citations' => [],
                        'source_urls' => [],
                        'visibility_score' => 0,
                        'analysis_json' => ['error' => $lastError],
                        'answered_at' => now(),
                    ]
                );
            }
        }

        $question->forceFill([
            'status' => $succeeded > 0 ? 'completed' : 'failed',
        ])->save();

        if ($succeeded === 0 && $lastError !== null) {
            $run->forceFill(['error_message' => $lastError])->save();
        }
    }

    private function buildPrompt(BrandProfile $brandProfile, GeoAiSearchQuestion $question): string
    {
        return implode("\n", array_merge([
            '请像真实用户在 AI 搜索里提问一样回答，不要说明你在执行测试。',
            '搜索问题：'.$question->question,
            '目标品牌：'.$brandProfile->brand_name,
            '品牌别名：'.implode('、', (array) $brandProfile->aliases),
            '产品/服务：'.(string) $brandProfile->products,
            '核心优势：'.(string) $brandProfile->advantages,
            '服务区域：'.(string) $brandProfile->service_area,
            '补充事实：'.(string) $brandProfile->extra_facts,
        ], BrandProfileContextFormatter::promptLines($brandProfile), [
            '请尽量给出你会参考的网站、文章、帖子或资料来源链接；如果没有链接，也要说明判断依据。',
        ]));
    }

    /**
     * @param  list<string>  $sourceUrls
     */
    private function recordCitationSources(GeoAiSearchRun $run, GeoAiSearchAnswer $answer, array $sourceUrls): void
    {
        foreach ($sourceUrls as $sourceUrl) {
            $domain = (string) parse_url($sourceUrl, PHP_URL_HOST);
            $domain = mb_strtolower($domain);

            DB::transaction(function () use ($run, $answer, $sourceUrl, $domain): void {
                $source = GeoCitationSource::query()->firstOrNew([
                    'organization_id' => $run->organization_id,
                    'url' => $sourceUrl,
                ]);

                $source->fill([
                    'geo_ai_search_answer_id' => $answer->id,
                    'domain' => $domain,
                    'status' => $source->exists ? $source->status : 'pending_crawl',
                    'citation_count' => $source->exists ? (int) $source->citation_count + 1 : 1,
                    'first_seen_at' => $source->first_seen_at ?? now(),
                    'last_seen_at' => now(),
                    'metadata' => [
                        'search_run_id' => $run->id,
                        'platform_code' => $answer->platform_code,
                    ],
                ]);
                $source->save();

                GeoCitationOccurrence::query()->updateOrCreate(
                    [
                        'geo_ai_search_answer_id' => $answer->id,
                        'geo_citation_source_id' => $source->id,
                    ],
                    [
                        'organization_id' => $run->organization_id,
                        'geo_ai_search_run_id' => $run->id,
                        'geo_ai_search_question_id' => $answer->geo_ai_search_question_id,
                        'geo_keyword_opportunity_id' => $answer->geo_keyword_opportunity_id,
                        'platform_code' => (string) $answer->platform_code,
                        'url' => $sourceUrl,
                        'domain' => $domain,
                        'cited_at' => $answer->answered_at ?? now(),
                        'metadata' => [
                            'visibility_score' => (int) $answer->visibility_score,
                        ],
                    ]
                );
            });
        }
    }
}
