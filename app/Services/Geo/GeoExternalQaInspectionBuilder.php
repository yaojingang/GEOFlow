<?php

namespace App\Services\Geo;

use App\Models\Admin;
use App\Models\BrandProfile;
use App\Models\GeoAiSearchRun;
use App\Models\GeoKeywordOpportunity;
use App\Models\Organization;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class GeoExternalQaInspectionBuilder
{
    public function __construct(
        private readonly GeoExternalQaOptimizationPlanner $optimizationPlanner
    ) {}

    /**
     * @param  list<string>  $platformCodes
     */
    public function create(
        Admin $admin,
        Organization $organization,
        BrandProfile $brandProfile,
        string $name,
        string $questionsText,
        array $platformCodes,
        int $targetKeywordHitRate = 70
    ): GeoAiSearchRun {
        $questions = $this->parseQuestions($questionsText);
        if ($questions === []) {
            throw new InvalidArgumentException('请填写至少一个外部问答检视问题');
        }

        if ($platformCodes === []) {
            throw new InvalidArgumentException('请先选择至少一个 AI 平台');
        }

        $runName = $this->runName($brandProfile, $name);
        $targetKeywordHitRate = max(0, min(100, $targetKeywordHitRate));
        $existingRun = $this->findRecentPendingDuplicate($organization, $brandProfile, $runName, $questions, $platformCodes, $targetKeywordHitRate);
        if ($existingRun instanceof GeoAiSearchRun) {
            return $existingRun;
        }

        $previousKeywordHitRate = $this->optimizationPlanner->latestCompletedKeywordHitRate($organization, $brandProfile);
        $optimizationDirections = $this->optimizationPlanner->directions($brandProfile, $runName, $questions, $targetKeywordHitRate);

        return DB::transaction(function () use ($admin, $organization, $brandProfile, $runName, $questions, $platformCodes, $targetKeywordHitRate, $previousKeywordHitRate, $optimizationDirections): GeoAiSearchRun {
            $run = GeoAiSearchRun::query()->create([
                'organization_id' => $organization->id,
                'brand_profile_id' => $brandProfile->id,
                'created_by_admin_id' => $admin->id,
                'name' => mb_substr($runName, 0, 180),
                'status' => 'pending',
                'platform_codes' => $platformCodes,
                'points_cost' => count($questions) * count($platformCodes),
                'total_questions' => count($questions),
                'target_keyword_hit_rate' => $targetKeywordHitRate,
                'previous_keyword_hit_rate' => $previousKeywordHitRate,
                'baseline_keyword_hit_rate' => $previousKeywordHitRate,
                'optimization_directions' => $optimizationDirections,
            ]);

            foreach ($questions as $question) {
                $opportunity = $this->upsertInspectionOpportunity($admin, $organization, $brandProfile, $question);
                $run->questions()->create([
                    'geo_keyword_opportunity_id' => $opportunity->id,
                    'question' => $question,
                    'intent' => $opportunity->intent,
                    'status' => 'pending',
                ]);
            }

            return $run;
        });
    }

    private function runName(BrandProfile $brandProfile, string $name): string
    {
        $runName = trim($name);
        if ($runName !== '') {
            return $runName;
        }

        return '外部问答检视 - '.$brandProfile->brand_name.' - '.now()->format('m-d H:i');
    }

    /**
     * @param  list<string>  $questions
     * @param  list<string>  $platformCodes
     */
    private function findRecentPendingDuplicate(
        Organization $organization,
        BrandProfile $brandProfile,
        string $runName,
        array $questions,
        array $platformCodes,
        int $targetKeywordHitRate
    ): ?GeoAiSearchRun {
        $expectedQuestions = $this->comparableList($questions);
        $expectedPlatforms = $this->comparableList($platformCodes);

        return GeoAiSearchRun::query()
            ->where('organization_id', $organization->id)
            ->where('brand_profile_id', $brandProfile->id)
            ->where('name', mb_substr($runName, 0, 180))
            ->where('target_keyword_hit_rate', $targetKeywordHitRate)
            ->whereIn('status', ['pending', 'running'])
            ->latest('id')
            ->get()
            ->first(function (GeoAiSearchRun $run) use ($expectedQuestions, $expectedPlatforms): bool {
                $runQuestions = $run->questions()
                    ->orderBy('id')
                    ->pluck('question')
                    ->all();

                return $this->comparableList((array) $run->platform_codes) === $expectedPlatforms
                    && $this->comparableList($runQuestions) === $expectedQuestions;
            });
    }

    /**
     * @param  list<string>  $values
     * @return list<string>
     */
    private function comparableList(array $values): array
    {
        return collect($values)
            ->map(static fn (string $value): string => trim($value))
            ->filter(static fn (string $value): bool => $value !== '')
            ->sort()
            ->values()
            ->all();
    }

    /**
     * @return list<string>
     */
    private function parseQuestions(string $questionsText): array
    {
        $normalized = str_replace(['，', '、', ';', '；'], "\n", $questionsText);
        $questions = preg_split('/\R|,/u', $normalized) ?: [];

        return collect($questions)
            ->map(static fn (string $question): string => trim($question))
            ->filter(static fn (string $question): bool => $question !== '')
            ->map(static fn (string $question): string => mb_substr($question, 0, 255))
            ->unique()
            ->take(50)
            ->values()
            ->all();
    }

    private function upsertInspectionOpportunity(
        Admin $admin,
        Organization $organization,
        BrandProfile $brandProfile,
        string $question
    ): GeoKeywordOpportunity {
        $intent = $this->inferIntent($question);

        return GeoKeywordOpportunity::query()->updateOrCreate(
            [
                'organization_id' => $organization->id,
                'keyword' => $question,
            ],
            [
                'brand_profile_id' => $brandProfile->id,
                'created_by_admin_id' => $admin->id,
                'intent' => $intent,
                'cluster_name' => '外部问答检视',
                'status' => 'active',
                'business_value' => 85,
                'visibility_gap' => 85,
                'source_availability' => 65,
                'local_relevance' => $this->localRelevance($brandProfile, $question),
                'opportunity_score' => 84,
                'generation_source' => 'external_qa_inspection',
                'rationale' => '来自外部问答检视问题矩阵，用于检测 AI 回答中的品牌命中、推荐、竞品和引用证据。',
                'metadata' => [
                    'inspection_type' => 'external_qa',
                    'question_intent' => $intent,
                    'created_from' => 'external_qa_inspection_ui',
                ],
            ]
        );
    }

    private function inferIntent(string $question): string
    {
        if (preg_match('/推荐|哪家|哪个好|靠谱|口碑/u', $question) === 1) {
            return 'recommendation';
        }

        if (preg_match('/对比|比较|区别|怎么选/u', $question) === 1) {
            return 'comparison';
        }

        if (preg_match('/价格|报价|多少钱|费用/u', $question) === 1) {
            return 'price';
        }

        if (preg_match('/避坑|问题|售后|甲醛|环保/u', $question) === 1) {
            return 'pain_point';
        }

        return 'external_question';
    }

    private function localRelevance(BrandProfile $brandProfile, string $question): int
    {
        $serviceArea = trim((string) $brandProfile->service_area);
        if ($serviceArea !== '' && mb_stripos($question, $serviceArea) !== false) {
            return 95;
        }

        return preg_match('/本地|附近|同城|重庆|涪陵/u', $question) === 1 ? 90 : 70;
    }
}
