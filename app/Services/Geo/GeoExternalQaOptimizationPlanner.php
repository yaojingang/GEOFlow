<?php

namespace App\Services\Geo;

use App\Models\BrandProfile;
use App\Models\GeoAiSearchAnswer;
use App\Models\GeoAiSearchRun;
use App\Models\Organization;
use Illuminate\Support\Collection;

class GeoExternalQaOptimizationPlanner
{
    /**
     * @param  list<string>  $questions
     * @return list<array{title:string,body:string,keywords:list<string>,questions:list<string>}>
     */
    public function directions(BrandProfile $brandProfile, string $inspectionName, array $questions, int $targetKeywordHitRate): array
    {
        $displayName = trim($inspectionName) !== '' ? trim($inspectionName) : '外部问答检视';
        $brandName = trim((string) $brandProfile->brand_name) ?: '品牌';
        $serviceArea = trim((string) $brandProfile->service_area) ?: '本地';
        $products = trim((string) $brandProfile->products) ?: '核心产品/服务';
        $keywords = $this->expectedKeywords($brandProfile, $displayName, $questions);
        $priorityQuestions = array_slice($questions, 0, 4);

        return [
            [
                'title' => '围绕检视名称做回答型文章',
                'body' => '以「'.$displayName.'」为创作文章主线，开头直接回答用户最关心的问题，并把目标关键词命中率拉到 '.$targetKeywordHitRate.'% 以上。',
                'keywords' => array_slice($keywords, 0, 8),
                'questions' => $priorityQuestions,
            ],
            [
                'title' => '把问题矩阵拆成文章小节',
                'body' => '把问题矩阵里的推荐、价格、避坑、对比和口碑意图拆成小标题，每节都写清 '.$brandName.' 的服务事实、判断标准和下一步咨询入口。',
                'keywords' => $this->intentKeywords($questions),
                'questions' => $priorityQuestions,
            ],
            [
                'title' => '补齐可被 AI 引用的企业证据',
                'body' => '正文中稳定出现 '.$serviceArea.'、'.$products.'、真实案例、报价边界、材料标准、售后流程和联系方式，让复测时更容易命中品牌词与业务词。',
                'keywords' => array_slice($this->brandFactKeywords($brandProfile), 0, 8),
                'questions' => [],
            ],
        ];
    }

    /**
     * @return array{keyword_hit_rate:int, keyword_hit_count:int, keyword_check_count:int, expected_keywords:list<string>}
     */
    public function summarizeRun(GeoAiSearchRun $run): array
    {
        $run->loadMissing(['brandProfile', 'questions', 'answers']);
        $brandProfile = $run->brandProfile;
        $questions = $run->questions
            ->pluck('question')
            ->map(static fn (mixed $question): string => trim((string) $question))
            ->filter(static fn (string $question): bool => $question !== '')
            ->values()
            ->all();

        $expectedKeywords = $brandProfile instanceof BrandProfile
            ? $this->expectedKeywords($brandProfile, (string) $run->name, $questions)
            : [];

        $answers = $this->succeededAnswers($run);
        $checkCount = $answers->count();
        if ($checkCount === 0 || $expectedKeywords === []) {
            return [
                'keyword_hit_rate' => 0,
                'keyword_hit_count' => 0,
                'keyword_check_count' => $checkCount,
                'expected_keywords' => $expectedKeywords,
            ];
        }

        $hitCount = $answers
            ->filter(fn (GeoAiSearchAnswer $answer): bool => $this->containsAny((string) $answer->raw_answer, $expectedKeywords))
            ->count();

        return [
            'keyword_hit_rate' => (int) round($hitCount / $checkCount * 100),
            'keyword_hit_count' => $hitCount,
            'keyword_check_count' => $checkCount,
            'expected_keywords' => $expectedKeywords,
        ];
    }

    public function latestCompletedKeywordHitRate(Organization $organization, BrandProfile $brandProfile, ?int $excludeRunId = null): ?int
    {
        $query = GeoAiSearchRun::query()
            ->where('organization_id', $organization->id)
            ->where('brand_profile_id', $brandProfile->id)
            ->whereIn('status', ['completed', 'partial_failed'])
            ->whereHas('questions.opportunity', fn ($opportunityQuery) => $opportunityQuery->where('generation_source', 'external_qa_inspection'))
            ->orderByDesc('finished_at')
            ->orderByDesc('id');

        if ($excludeRunId !== null) {
            $query->where('id', '!=', $excludeRunId);
        }

        $run = $query->first();
        if (! $run instanceof GeoAiSearchRun) {
            return null;
        }

        if ($run->keyword_hit_rate !== null) {
            return (int) $run->keyword_hit_rate;
        }

        return $this->summarizeRun($run)['keyword_hit_rate'];
    }

    /**
     * @param  list<string>  $questions
     * @return list<string>
     */
    private function expectedKeywords(BrandProfile $brandProfile, string $inspectionName, array $questions): array
    {
        return collect($this->brandFactKeywords($brandProfile))
            ->merge($this->tokenize($inspectionName))
            ->merge(collect($questions)->flatMap(fn (string $question): array => array_merge([$question], $this->tokenize($question))))
            ->map(static fn (mixed $keyword): string => trim((string) $keyword))
            ->filter(static fn (string $keyword): bool => mb_strlen($keyword) >= 2)
            ->unique(fn (string $keyword): string => mb_strtolower($keyword))
            ->take(24)
            ->values()
            ->all();
    }

    /**
     * @return list<string>
     */
    private function brandFactKeywords(BrandProfile $brandProfile): array
    {
        return collect([(string) $brandProfile->brand_name])
            ->merge((array) $brandProfile->aliases)
            ->merge($this->tokenize((string) $brandProfile->service_area))
            ->merge($this->tokenize((string) $brandProfile->products))
            ->merge($this->tokenize((string) $brandProfile->advantages))
            ->map(static fn (mixed $keyword): string => trim((string) $keyword))
            ->filter(static fn (string $keyword): bool => mb_strlen($keyword) >= 2)
            ->values()
            ->all();
    }

    /**
     * @param  list<string>  $questions
     * @return list<string>
     */
    private function intentKeywords(array $questions): array
    {
        $intents = [];
        $text = implode("\n", $questions);

        foreach ([
            '推荐' => '/推荐|哪家|哪个好|靠谱|口碑/u',
            '报价' => '/价格|报价|多少钱|费用/u',
            '避坑' => '/避坑|问题|售后|甲醛|环保/u',
            '对比' => '/对比|比较|区别|怎么选/u',
            '案例' => '/案例|效果|实景|客户/u',
        ] as $label => $pattern) {
            if (preg_match($pattern, $text) === 1) {
                $intents[] = $label;
            }
        }

        return $intents === [] ? ['品牌', '服务', '案例', '报价'] : $intents;
    }

    /**
     * @return list<string>
     */
    private function tokenize(string $value): array
    {
        $normalized = str_replace(['，', '、', ';', '；', '/', '\\', '|', '（', '）', '(', ')', '：', ':'], "\n", $value);
        $parts = preg_split('/\R|,|\s+/u', $normalized) ?: [];

        return collect($parts)
            ->map(static fn (string $part): string => trim($part))
            ->filter(static fn (string $part): bool => mb_strlen($part) >= 2)
            ->reject(static fn (string $part): bool => in_array($part, ['外部问答检视', '检视', '复测', '第一轮', '第二轮'], true))
            ->values()
            ->all();
    }

    /**
     * @return Collection<int, GeoAiSearchAnswer>
     */
    private function succeededAnswers(GeoAiSearchRun $run): Collection
    {
        $answers = $run->relationLoaded('answers')
            ? $run->answers
            : $run->answers()->get();

        return $answers
            ->filter(static fn (GeoAiSearchAnswer $answer): bool => $answer->status === 'succeeded')
            ->values();
    }

    /**
     * @param  list<string>  $keywords
     */
    private function containsAny(string $answer, array $keywords): bool
    {
        foreach ($keywords as $keyword) {
            if ($keyword !== '' && mb_stripos($answer, $keyword) !== false) {
                return true;
            }
        }

        return false;
    }
}
