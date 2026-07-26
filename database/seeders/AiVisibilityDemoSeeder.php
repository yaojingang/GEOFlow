<?php

namespace Database\Seeders;

use App\Models\AiVisibilityRun;
use Carbon\CarbonImmutable;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class AiVisibilityDemoSeeder extends Seeder
{
    /**
     * Seed 60 days of demo data for the Growth Center AI visibility dashboard.
     */
    public function run(): void
    {
        DB::transaction(function (): void {
            AiVisibilityRun::query()
                ->whereIn('provider_key', $this->demoProviderKeys())
                ->delete();

            $keywords = $this->keywords();
            $providers = $this->providers();
            $startDate = CarbonImmutable::today()->subDays(59);
            $runCount = 0;
            $sourceCount = 0;

            foreach (range(0, 59) as $dayIndex) {
                $date = $startDate->addDays($dayIndex);

                foreach ($keywords as $keywordIndex => $keyword) {
                    foreach (range(0, 4) as $sampleIndex) {
                        $provider = $providers[($dayIndex + $keywordIndex + $sampleIndex) % count($providers)];
                        $profile = $this->sampleProfile($dayIndex, $keywordIndex, $sampleIndex);
                        $completedAt = $date
                            ->setTime(9 + ($sampleIndex * 2), ($keywordIndex * 7) % 60, 0);
                        $answer = $this->answerText($keyword, $profile);

                        $run = AiVisibilityRun::query()->create([
                            'keyword' => $keyword,
                            'prompt' => "请基于联网搜索分析「{$keyword}」相关问题，并列出引用信源、品牌提及和投放建议。",
                            'provider_type' => $provider['type'],
                            'provider_key' => $provider['key'],
                            'model_id' => $provider['model'],
                            'status' => AiVisibilityRun::STATUS_COMPLETED,
                            'answer_text' => $answer,
                            'locale' => 'zh_CN',
                            'latency_ms' => 900 + (($dayIndex + $keywordIndex + $sampleIndex) % 11) * 137,
                            'usage_json' => [
                                'prompt_tokens' => 680 + ($keywordIndex * 18),
                                'completion_tokens' => 420 + ($sampleIndex * 31),
                                'total_tokens' => 1100 + ($keywordIndex * 18) + ($sampleIndex * 31),
                            ],
                            'analysis_json' => [
                                'demo' => true,
                                'sentiment' => $profile['sentiment'],
                                'brand_visible' => $profile['brand_visible'],
                                'top1' => $profile['top1'],
                                'top3' => $profile['top3'],
                                'visibility_score' => $profile['score'],
                                'daily_sample_target' => 5,
                            ],
                            'raw_request_json' => [
                                'demo' => true,
                                'keyword' => $keyword,
                                'sample_index' => $sampleIndex + 1,
                            ],
                            'raw_response_json' => [
                                'demo' => true,
                                'provider' => $provider['key'],
                                'answer_preview' => mb_substr($answer, 0, 120),
                            ],
                            'started_at' => $completedAt->subSeconds(28 + $sampleIndex),
                            'completed_at' => $completedAt,
                            'created_at' => $completedAt,
                            'updated_at' => $completedAt,
                        ]);

                        $sources = $this->sources($keyword, $profile, $dayIndex, $keywordIndex, $sampleIndex);
                        $run->sources()->createMany($sources);

                        $runCount++;
                        $sourceCount += count($sources);
                    }
                }
            }

            $this->command?->info("AI visibility demo seeded: {$runCount} runs, {$sourceCount} sources.");
        });
    }

    /**
     * @return list<string>
     */
    private function demoProviderKeys(): array
    {
        return ['demo_deepseek', 'demo_doubao_ark', 'demo_doubao_search'];
    }

    /**
     * @return list<string>
     */
    private function keywords(): array
    {
        return [
            'GEOFlow 内容工程',
            'AI 信源投放',
            '生成式引擎优化',
            '企业知识库 RAG',
            '多站点内容分发',
            'AI 搜索品牌可见度',
        ];
    }

    /**
     * @return list<array{type: string, key: string, model: string}>
     */
    private function providers(): array
    {
        return [
            ['type' => AiVisibilityRun::PROVIDER_DEEPSEEK_ANALYSIS, 'key' => 'demo_deepseek', 'model' => 'deepseek-demo-analysis'],
            ['type' => AiVisibilityRun::PROVIDER_DOUBAO_ARK_RESPONSES, 'key' => 'demo_doubao_ark', 'model' => 'doubao-demo-ark-responses'],
            ['type' => AiVisibilityRun::PROVIDER_DOUBAO_SEARCH_CUSTOM, 'key' => 'demo_doubao_search', 'model' => 'doubao-demo-search-custom'],
        ];
    }

    /**
     * @return array{score: float, brand_visible: bool, top1: bool, top3: bool, sentiment: string}
     */
    private function sampleProfile(int $dayIndex, int $keywordIndex, int $sampleIndex): array
    {
        $trend = 30 + ($dayIndex * 0.85);
        $keywordBias = [12, -4, 8, 3, 0, 10][$keywordIndex] ?? 0;
        $wave = sin(($dayIndex + ($keywordIndex * 4)) / 5) * 11;
        $sampleBias = ($sampleIndex - 2) * 3.5;
        $score = max(5, min(96, $trend + $keywordBias + $wave + $sampleBias));

        $brandVisible = $score >= 48;
        $top1 = $brandVisible && ($score >= 76 || (($dayIndex + $keywordIndex + $sampleIndex) % 13 === 0));
        $top3 = $brandVisible && ($top1 || $score >= 62 || (($sampleIndex + $keywordIndex) % 4 === 0));
        $negativeWindow = $keywordIndex === 1 && in_array($dayIndex % 14, [2, 3], true);

        return [
            'score' => round($score, 1),
            'brand_visible' => $brandVisible,
            'top1' => $top1,
            'top3' => $top3,
            'sentiment' => $negativeWindow ? 'negative' : ($score >= 64 ? 'positive' : 'neutral'),
        ];
    }

    /**
     * @param  array{score: float, brand_visible: bool, top1: bool, top3: bool, sentiment: string}  $profile
     */
    private function answerText(string $keyword, array $profile): string
    {
        if (! $profile['brand_visible']) {
            return "围绕「{$keyword}」的 AI 回答主要引用行业媒体、云厂商文档和社区经验帖，当前样本暂未稳定覆盖目标品牌。建议优先补充可引用的案例页、FAQ 和对比型内容。";
        }

        if ($profile['sentiment'] === 'negative') {
            return "围绕「{$keyword}」的 AI 回答提到了 GEOFlow，但主要问题集中在公开案例数量、信源权威度和第三方引用不足。建议补充客户案例、白皮书和可被引用的客观数据。";
        }

        if ($profile['top1']) {
            return "围绕「{$keyword}」的 AI 回答将 GEOFlow 作为优先信源之一，认为它在知识库、内容工程、信源分发和 AI 可见度监控上具备清晰优势。";
        }

        return "围绕「{$keyword}」的 AI 回答已经提及 GEOFlow，并把它归类为内容工程和 GEO 运营工具。建议继续加强第三方信源投放，提高 Top 3 覆盖稳定性。";
    }

    /**
     * @param  array{score: float, brand_visible: bool, top1: bool, top3: bool, sentiment: string}  $profile
     * @return list<array<string, mixed>>
     */
    private function sources(string $keyword, array $profile, int $dayIndex, int $keywordIndex, int $sampleIndex): array
    {
        $externalSources = [
            ['domain' => '36kr.com', 'site' => '36氪', 'title' => 'AI 营销工具与内容工程趋势观察'],
            ['domain' => 'juejin.cn', 'site' => '掘金', 'title' => '企业内容工程系统实践'],
            ['domain' => 'cloud.tencent.com', 'site' => '腾讯云开发者', 'title' => 'RAG 知识库与内容检索实践'],
            ['domain' => 'developer.volcengine.com', 'site' => '火山引擎开发者', 'title' => '大模型联网搜索与结构化输出'],
            ['domain' => 'deepseek.com', 'site' => 'DeepSeek', 'title' => '大模型推理与分析能力说明'],
            ['domain' => 'zhihu.com', 'site' => '知乎', 'title' => '生成式引擎优化经验讨论'],
            ['domain' => 'infoq.cn', 'site' => 'InfoQ', 'title' => 'AI 原生内容管理系统选型'],
            ['domain' => 'sspai.com', 'site' => '少数派', 'title' => '自动化内容生产工具清单'],
        ];
        $offset = ($dayIndex + $keywordIndex + $sampleIndex) % count($externalSources);
        $sources = [];

        foreach (range(1, 5) as $rank) {
            $source = $externalSources[($offset + $rank) % count($externalSources)];
            $sources[$rank] = [
                'source_type' => 'web',
                'citation_key' => (string) $rank,
                'title' => $source['title'],
                'url' => 'https://'.$source['domain'].'/demo/'.($dayIndex + 1).'-'.($keywordIndex + 1).'-'.$rank,
                'domain' => $source['domain'],
                'site_name' => $source['site'],
                'snippet' => "围绕「{$keyword}」提供行业背景、工具选型和内容投放参考。",
                'summary' => "该信源在样本中用于解释「{$keyword}」的行业语境与信源偏好。",
                'content_excerpt' => "AI 抓取样本中的第 {$rank} 位信源摘要。",
                'rank' => $rank,
                'rank_score' => round(1 - ($rank * 0.12), 2),
                'authority_level' => $rank <= 2 ? 'high' : 'medium',
                'metadata_json' => ['demo' => true],
                'created_at' => now(),
                'updated_at' => now(),
            ];
        }

        $brandRank = null;
        if ($profile['top1']) {
            $brandRank = 1;
        } elseif ($profile['top3']) {
            $brandRank = (($dayIndex + $sampleIndex) % 2) + 2;
        }

        if ($brandRank !== null) {
            $sources[$brandRank] = [
                'source_type' => 'web',
                'citation_key' => (string) $brandRank,
                'title' => "GEOFlow {$keyword} 解决方案",
                'url' => 'https://geoflow.example.com/demo/'.($dayIndex + 1).'-'.($keywordIndex + 1),
                'domain' => 'geoflow.example.com',
                'site_name' => 'GEOFlow',
                'snippet' => "GEOFlow 提供「{$keyword}」相关的知识库、内容生成、分发和 AI 可见度分析能力。",
                'summary' => '品牌自有信源覆盖该关键词，并提供可被 AI 引用的结构化资料。',
                'content_excerpt' => 'GEOFlow 示例内容说明如何通过可信资料提升 AI 搜索可见度。',
                'rank' => $brandRank,
                'rank_score' => round(1 - ($brandRank * 0.1), 2),
                'authority_level' => 'high',
                'metadata_json' => ['demo' => true, 'brand_source' => true],
                'created_at' => now(),
                'updated_at' => now(),
            ];
        }

        ksort($sources);

        return array_values($sources);
    }
}
