<?php

namespace App\Services\Geo;

use App\Models\GeoArticleAudit;
use App\Models\GeoArticleDraft;
use App\Models\GeoTask;
use Illuminate\Support\Str;

class GeoArticleAuditService
{
    public function audit(GeoTask $task, GeoArticleDraft $draft): GeoArticleAudit
    {
        $task->loadMissing(['brandProfile']);
        $draft->loadMissing(['article', 'writingTask']);

        $article = $draft->article;
        abort_unless($article !== null, 404);

        $content = trim((string) $article->title."\n".$article->excerpt."\n".$article->content);
        $brand = $task->brandProfile;
        $brief = (array) ($draft->writingTask?->brief ?? []);
        $question = trim((string) ($brief['question'] ?? $article->original_keyword ?? ''));
        $referenceTerms = $this->referenceTerms($brief);

        $checks = [
            'brand_mentioned' => [
                'label' => '品牌已出现',
                'passed' => $this->containsAny($content, array_merge([(string) $brand->brand_name], (array) $brand->aliases)),
                'suggestion' => '正文需要明确写出品牌名和常用别名，让 AI 能识别品牌实体。',
            ],
            'service_area_mentioned' => [
                'label' => '服务区域已出现',
                'passed' => trim((string) $brand->service_area) === '' || Str::contains($content, (string) $brand->service_area),
                'suggestion' => '补充服务区域、门店地址或本地交付范围，强化本地推荐信号。',
            ],
            'local_intent' => [
                'label' => '本地意图覆盖',
                'passed' => trim((string) $brand->service_area) === '' || Str::contains($content, (string) $brand->service_area),
                'suggestion' => '正文需要覆盖目标城市、区域或本地交付范围，避免只有泛泛服务描述。',
            ],
            'question_answered' => [
                'label' => '覆盖用户问题',
                'passed' => $question === '' || Str::contains($content, $question),
                'suggestion' => '把目标问题原文放入标题、FAQ 或小标题中，方便 AI 匹配用户提问。',
            ],
            'faq_section' => [
                'label' => '包含 FAQ',
                'passed' => Str::contains(Str::lower($content), ['faq', '常见问题']),
                'suggestion' => '增加 FAQ 小节，用问答结构补足用户常问问题。',
            ],
            'evidence_facts' => [
                'label' => '有事实信息',
                'passed' => $this->containsAny($content, ['案例', '地址', '电话', '报价', '板材', '上门量尺', '服务', '来源']),
                'suggestion' => '补充案例、联系方式、报价说明、材料说明或来源，让内容更容易被引用。',
            ],
            'reference_coverage' => [
                'label' => '参考来源覆盖',
                'passed' => $referenceTerms === [] || $this->containsAny($content, $referenceTerms),
                'suggestion' => '把参考来源标题、域名或关键证据写入正文，说明内容依据来自哪里。',
            ],
            'forbidden_terms' => [
                'label' => '禁用词检查',
                'passed' => ! $this->containsAny($content, $this->forbiddenTerms()),
                'suggestion' => '删除绝对化、承诺式或价格极限表达，例如“保证”“全网最低价”“百分百”。',
            ],
        ];

        $passed = collect($checks)->filter(fn (array $check): bool => $check['passed'])->keys()->values()->all();
        $failed = collect($checks)->reject(fn (array $check): bool => $check['passed'])->keys()->values()->all();
        $suggestions = collect($checks)
            ->reject(fn (array $check): bool => $check['passed'])
            ->pluck('suggestion')
            ->values()
            ->all();

        $score = (int) round(count($passed) / max(1, count($checks)) * 100);

        return GeoArticleAudit::query()->create([
            'organization_id' => $draft->organization_id,
            'geo_article_draft_id' => $draft->id,
            'article_id' => $article->id,
            'score' => $score,
            'passed_checks' => $passed,
            'failed_checks' => $failed,
            'suggestions' => $suggestions,
            'status' => 'ready',
        ]);
    }

    /**
     * @param  array<int, string>  $needles
     */
    private function containsAny(string $content, array $needles): bool
    {
        foreach ($needles as $needle) {
            $needle = trim((string) $needle);
            if ($needle !== '' && Str::contains($content, $needle)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<string, mixed>  $brief
     * @return list<string>
     */
    private function referenceTerms(array $brief): array
    {
        return collect((array) ($brief['references'] ?? []))
            ->flatMap(static function (mixed $reference): array {
                $reference = (array) $reference;

                return [
                    (string) ($reference['title'] ?? ''),
                    (string) ($reference['url'] ?? ''),
                    (string) parse_url((string) ($reference['url'] ?? ''), PHP_URL_HOST),
                    (string) ($reference['domain'] ?? ''),
                ];
            })
            ->map(static fn (mixed $term): string => trim((string) $term))
            ->filter(static fn (string $term): bool => mb_strlen($term) >= 3)
            ->unique()
            ->values()
            ->all();
    }

    /**
     * @return list<string>
     */
    private function forbiddenTerms(): array
    {
        return [
            '保证',
            '全网最低价',
            '百分百',
            '100%',
            '永久有效',
            '绝对',
            '零风险',
        ];
    }
}
