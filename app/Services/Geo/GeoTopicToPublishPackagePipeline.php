<?php

namespace App\Services\Geo;

use App\Models\Admin;
use App\Models\BrandProfile;
use App\Models\GeoAiSearchAnswer;
use App\Models\GeoAiSearchRun;
use App\Models\GeoArticleDraft;
use App\Models\GeoCitationOccurrence;
use App\Models\GeoCitationSource;
use App\Models\GeoKeywordOpportunity;
use App\Models\GeoReferenceContentAnalysis;
use App\Models\GeoReferenceContentScore;
use App\Models\GeoWritingTask;
use App\Models\Organization;
use App\Support\Site\ArticleHtmlPresenter;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

class GeoTopicToPublishPackagePipeline
{
    public function __construct(
        private readonly GeoSearchBatchRunner $searchRunner,
        private readonly GeoReferencePageCrawler $crawler,
        private readonly GeoReferenceContentQualityScorer $scorer,
        private readonly GeoReferenceContentAnalyzer $analyzer,
        private readonly GeoArticleVisualPublishPackBuilder $visualPackBuilder,
        private readonly GeoArticleVisualImageInserter $imageInserter,
        private readonly GeoArticlePublishPackageExporter $packageExporter,
    ) {}

    /**
     * @param  list<string>  $platformCodes
     */
    public function run(
        Admin $admin,
        Organization $organization,
        BrandProfile $brandProfile,
        string $topic,
        array $platformCodes,
        int $maxReferences = 3
    ): GeoArticleDraft {
        $topic = trim($topic);
        if ($topic === '') {
            throw new InvalidArgumentException('选题不能为空');
        }
        if (count($platformCodes) < 2) {
            throw new InvalidArgumentException('至少选择两个 AI 平台，才能做交叉对比');
        }

        $maxReferences = max(1, min(5, $maxReferences));
        $question = $this->questionForTopic($topic, $brandProfile);
        [$opportunity, $run] = $this->createSearchRun($admin, $organization, $brandProfile, $topic, $question, $platformCodes);
        $run = $this->searchRunner->run($run);
        $run->load(['answers.question', 'answers.opportunity']);

        $answers = $run->answers()
            ->where('status', 'succeeded')
            ->orderBy('id')
            ->get();
        if ($answers->count() < 2) {
            throw new RuntimeException('多平台搜索未收集到至少 2 条有效回答，不能进入仿写和发布包');
        }

        $sources = $this->sourcesForRun($organization, $run);
        if ($sources->isEmpty()) {
            throw new RuntimeException('多平台回答没有采集到引用文章链接，已停止生成草稿');
        }

        $candidates = $this->crawlScoreAnalyzeReferences($organization, $brandProfile, $sources, $topic)
            ->sortByDesc(static fn (array $candidate): int => (int) $candidate['score']->total_score)
            ->take($maxReferences)
            ->values();
        if ($candidates->isEmpty()) {
            throw new RuntimeException('引用文章没有可用的成功快照或质量评分，已停止生成草稿');
        }

        $draft = $this->createDraftFromReferences(
            $organization,
            $brandProfile,
            $topic,
            $question,
            $opportunity,
            $run,
            $answers,
            $candidates,
            $platformCodes
        );

        $draft = $this->visualPackBuilder->build($draft);
        $draft = $this->imageInserter->insert($draft);
        $draft = $this->packageExporter->export($draft);

        return $this->markPipelineComplete($draft);
    }

    /**
     * @param  list<string>  $platformCodes
     * @return array{0: GeoKeywordOpportunity, 1: GeoAiSearchRun}
     */
    private function createSearchRun(
        Admin $admin,
        Organization $organization,
        BrandProfile $brandProfile,
        string $topic,
        string $question,
        array $platformCodes
    ): array {
        return DB::transaction(function () use ($admin, $organization, $brandProfile, $topic, $question, $platformCodes): array {
            $opportunity = GeoKeywordOpportunity::query()->updateOrCreate(
                [
                    'organization_id' => $organization->id,
                    'keyword' => $topic,
                ],
                [
                    'brand_profile_id' => $brandProfile->id,
                    'created_by_admin_id' => $admin->id,
                    'intent' => 'topic_pipeline',
                    'cluster_name' => 'GEO 选题完整链路',
                    'status' => 'active',
                    'business_value' => 90,
                    'visibility_gap' => 80,
                    'source_availability' => 70,
                    'local_relevance' => 90,
                    'opportunity_score' => 86,
                    'generation_source' => 'topic_pipeline',
                    'rationale' => '从用户选题进入多平台搜索、引用采集、参考筛选、仿写、配图和发布包。',
                    'metadata' => [
                        'pipeline' => 'topic_to_publish_package',
                        'question' => $question,
                        'platform_codes' => $platformCodes,
                    ],
                ]
            );

            $run = GeoAiSearchRun::query()->create([
                'organization_id' => $organization->id,
                'brand_profile_id' => $brandProfile->id,
                'created_by_admin_id' => $admin->id,
                'name' => '选题完整链路 - '.$this->limit($topic, 80).' - '.now()->format('m-d H:i'),
                'status' => 'pending',
                'platform_codes' => $platformCodes,
                'points_cost' => count($platformCodes),
                'total_questions' => 1,
            ]);

            $run->questions()->create([
                'geo_keyword_opportunity_id' => $opportunity->id,
                'question' => $question,
                'intent' => 'topic_pipeline',
                'status' => 'pending',
            ]);

            return [$opportunity, $run];
        });
    }

    /**
     * @return Collection<int, GeoCitationSource>
     */
    private function sourcesForRun(Organization $organization, GeoAiSearchRun $run): Collection
    {
        $occurrenceSources = GeoCitationOccurrence::query()
            ->where('organization_id', $organization->id)
            ->where('geo_ai_search_run_id', $run->id)
            ->with([
                'source.latestPageSnapshot',
                'source.latestReferenceAnalysis',
                'searchAnswer.question',
                'searchAnswer.opportunity',
                'searchAnswer.searchRun.brandProfile',
            ])
            ->orderBy('id')
            ->get()
            ->map(function (GeoCitationOccurrence $occurrence): ?GeoCitationSource {
                $source = $occurrence->source;
                if (! $source instanceof GeoCitationSource) {
                    return null;
                }

                $source->setRelation('searchAnswer', $occurrence->searchAnswer);

                return $source;
            })
            ->filter()
            ->unique('id')
            ->sortByDesc(static fn (GeoCitationSource $source): int => (int) $source->citation_count)
            ->values();

        if ($occurrenceSources->isNotEmpty()) {
            return $occurrenceSources;
        }

        return GeoCitationSource::query()
            ->where('organization_id', $organization->id)
            ->whereHas('searchAnswer', fn ($query) => $query->where('geo_ai_search_run_id', $run->id))
            ->with(['searchAnswer.question', 'searchAnswer.opportunity', 'searchAnswer.searchRun.brandProfile'])
            ->orderByDesc('citation_count')
            ->orderBy('id')
            ->get();
    }

    /**
     * @param  Collection<int, GeoCitationSource>  $sources
     * @return Collection<int, array{source: GeoCitationSource, score: GeoReferenceContentScore, analysis: GeoReferenceContentAnalysis|null}>
     */
    private function crawlScoreAnalyzeReferences(Organization $organization, BrandProfile $brandProfile, Collection $sources, string $topic): Collection
    {
        return $sources
            ->map(function (GeoCitationSource $source) use ($organization, $brandProfile, $topic): ?array {
                $snapshot = $this->crawler->crawl($source);
                $source->forceFill([
                    'title' => $snapshot->title ?: $source->title,
                    'status' => $snapshot->crawl_status === 'succeeded' ? 'crawled' : 'crawl_failed',
                    'metadata' => array_merge((array) $source->metadata, [
                        'topic_pipeline' => true,
                        'last_crawl_status' => $snapshot->crawl_status,
                        'last_crawl_snapshot_id' => $snapshot->id,
                        'last_crawl_error' => $snapshot->error_message,
                    ]),
                ])->save();

                if ($snapshot->crawl_status !== 'succeeded') {
                    return null;
                }

                $score = $this->scorer->scoreSnapshot($snapshot, $this->scoringContext($source, $brandProfile, $topic));
                $analysis = null;
                try {
                    $analysis = $this->analyzer->analyze($organization, $source);
                } catch (Throwable) {
                    $analysis = null;
                }

                return [
                    'source' => $source->refresh(),
                    'score' => $score,
                    'analysis' => $analysis,
                ];
            })
            ->filter()
            ->values();
    }

    /**
     * @param  Collection<int, GeoAiSearchAnswer>  $answers
     * @param  Collection<int, array{source: GeoCitationSource, score: GeoReferenceContentScore, analysis: GeoReferenceContentAnalysis|null}>  $candidates
     * @param  list<string>  $platformCodes
     */
    private function createDraftFromReferences(
        Organization $organization,
        BrandProfile $brandProfile,
        string $topic,
        string $question,
        GeoKeywordOpportunity $opportunity,
        GeoAiSearchRun $run,
        Collection $answers,
        Collection $candidates,
        array $platformCodes
    ): GeoArticleDraft {
        $references = $candidates
            ->map(fn (array $candidate): array => $this->referencePayload($candidate))
            ->values()
            ->all();
        $title = $this->titleFromTopic($topic);
        $summary = $this->summaryForTopic($topic);
        $comparison = $this->searchComparison($answers);
        $researchNotes = $this->researchNotes($brandProfile, $topic, $question, $comparison, $references, $candidates);
        $markdown = $this->markdown($title, $summary, $brandProfile, $topic, $references, $candidates);

        return DB::transaction(function () use ($organization, $topic, $question, $opportunity, $run, $platformCodes, $comparison, $references, $candidates, $title, $summary, $markdown, $researchNotes): GeoArticleDraft {
            $writingTask = GeoWritingTask::query()->create([
                'organization_id' => $organization->id,
                'geo_report_id' => null,
                'geo_keyword_id' => null,
                'title' => '选题完整链路仿写 - '.$this->limit($topic, 160),
                'status' => 'completed',
                'brief' => [
                    'source' => 'topic_pipeline_reference_imitation',
                    'generated_at' => now()->toDateTimeString(),
                    'topic' => $topic,
                    'question' => $question,
                    'opportunity_id' => (int) $opportunity->id,
                    'search_run_id' => (int) $run->id,
                    'platform_codes' => $platformCodes,
                    'article_output_rule' => '正文只保留可直接发布的公众号文章；结论、多平台回答交叉对比、参考文章筛选、写作依据、本地案例补充和发布前检查进入发布包说明文件。',
                    'search_comparison' => $comparison,
                    'references' => $references,
                    'research_notes' => $researchNotes,
                    'selected_reference_ids' => collect($references)->pluck('source_id')->values()->all(),
                    'reference_analysis_ids' => $candidates
                        ->map(static fn (array $candidate): ?int => $candidate['analysis'] ? (int) $candidate['analysis']->id : null)
                        ->filter()
                        ->values()
                        ->all(),
                    'article_sections' => $this->articleSections($topic),
                    'citation_reasons' => $this->citationReasons($references),
                    'writing_patterns' => $this->writingPatterns($candidates),
                    'reuse_notes' => [
                        '先交叉对比 AI 回答，再筛参考文章，不允许跳过引用层直接写正文。',
                        '复用参考文章结构和选题角度，不照抄原文表达。',
                        '正文中不要出现“多平台回答交叉对比”“参考文章筛选结果”“写作依据”等内部调研段落。',
                        '正文中不要出现“本地案例怎么补”“发布前检查”等运营提示段落。',
                        '品牌事实必须来自已保存资料，案例和效果不要夸大。',
                    ],
                    'pipeline_stages' => $this->stageStatus([
                        'topic_adjustment',
                        'multi_platform_search',
                        'answer_collection',
                        'cross_comparison',
                        'citation_collection',
                        'reference_selection',
                        'imitation_draft',
                    ]),
                ],
            ]);

            return GeoArticleDraft::query()->create([
                'organization_id' => $organization->id,
                'geo_writing_task_id' => $writingTask->id,
                'title' => $title,
                'summary' => $summary,
                'content_markdown' => $markdown,
                'content_html' => ArticleHtmlPresenter::markdownToHtml($markdown),
                'seo_title' => $title,
                'seo_description' => $summary,
                'status' => 'draft',
            ]);
        });
    }

    private function markPipelineComplete(GeoArticleDraft $draft): GeoArticleDraft
    {
        $draft->loadMissing('writingTask');
        $writingTask = $draft->writingTask;
        if (! $writingTask) {
            return $draft;
        }

        $brief = (array) $writingTask->brief;
        $stages = (array) ($brief['pipeline_stages'] ?? []);
        foreach (['visual_pack', 'image_insertion', 'publish_package'] as $stage) {
            $stages[$stage] = [
                'status' => 'completed',
                'completed_at' => now()->toDateTimeString(),
            ];
        }

        $writingTask->forceFill([
            'brief' => array_merge($brief, ['pipeline_stages' => $stages]),
        ])->save();

        return $draft->refresh();
    }

    /**
     * @param  array{source: GeoCitationSource, score: GeoReferenceContentScore, analysis: GeoReferenceContentAnalysis|null}  $candidate
     * @return array<string, mixed>
     */
    private function referencePayload(array $candidate): array
    {
        $source = $candidate['source'];
        $score = $candidate['score'];
        $analysis = $candidate['analysis'];
        $snapshot = $source->latestPageSnapshot;

        return [
            'source_id' => (int) $source->id,
            'url' => (string) $source->url,
            'domain' => (string) $source->domain,
            'title' => (string) ($snapshot?->title ?: $source->title ?: $source->domain),
            'summary' => (string) ($snapshot?->content_summary ?? ''),
            'score' => (int) $score->total_score,
            'suggested_usage' => (string) $score->suggested_usage,
            'analysis_id' => $analysis ? (int) $analysis->id : null,
        ];
    }

    /**
     * @param  Collection<int, GeoAiSearchAnswer>  $answers
     * @return array<string, mixed>
     */
    private function searchComparison(Collection $answers): array
    {
        $answerRows = $answers
            ->map(static fn (GeoAiSearchAnswer $answer): array => [
                'platform_code' => (string) $answer->platform_code,
                'visibility_score' => (int) $answer->visibility_score,
                'brand_mentioned' => (bool) $answer->brand_mentioned,
                'source_urls' => (array) $answer->source_urls,
                'summary' => Str::limit(trim((string) $answer->raw_answer), 260, ''),
            ])
            ->values()
            ->all();

        $sharedSignals = collect($answerRows)
            ->flatMap(static fn (array $answer): array => preg_split('/[\s,，。；;、]+/u', (string) $answer['summary']) ?: [])
            ->map(fn (string $term): string => $this->trimBoundaryPunctuation($term))
            ->filter(static fn (string $term): bool => mb_strlen($term) >= 2)
            ->countBy()
            ->filter(static fn (int $count): bool => $count >= 2)
            ->keys()
            ->take(12)
            ->values()
            ->all();

        return [
            'answers' => $answerRows,
            'shared_signals' => $sharedSignals,
            'differences' => $this->differences($answerRows),
            'summary' => '共收集 '.$answers->count().' 个平台回答，优先保留共同出现的决策词和带链接来源。',
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $answers
     * @return list<string>
     */
    private function differences(array $answers): array
    {
        return collect($answers)
            ->map(static function (array $answer): string {
                $platform = (string) ($answer['platform_code'] ?? 'unknown');
                $links = count((array) ($answer['source_urls'] ?? []));

                return $platform.'：可见度 '.(int) ($answer['visibility_score'] ?? 0).'，引用链接 '.$links.' 条';
            })
            ->values()
            ->all();
    }

    /**
     * @param  list<array<string, mixed>>  $references
     * @param  Collection<int, array{source: GeoCitationSource, score: GeoReferenceContentScore, analysis: GeoReferenceContentAnalysis|null}>  $candidates
     * @return array<string, mixed>
     */
    private function researchNotes(BrandProfile $brandProfile, string $topic, string $question, array $comparison, array $references, Collection $candidates): array
    {
        return [
            'version' => 'topic_pipeline_research_notes_v1',
            'topic' => $topic,
            'question' => $question,
            'conclusion' => '文章正文只保留面向客户的发布内容；调研结论、平台回答差异、引用来源、参考文章筛选理由、本地案例补充和发布前检查单独进入发布包说明文件，便于内部复核。',
            'local_case_materials' => $this->brandMaterialNotes($brandProfile),
            'publication_checklist' => $this->publicationChecklist(),
            'platform_comparison' => $comparison,
            'reference_selection' => [
                'items' => $references,
                'citation_reasons' => $this->citationReasons($references),
                'writing_patterns' => $this->writingPatterns($candidates),
                'analysis_paths' => $this->analysisPathList($candidates),
            ],
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $references
     * @param  Collection<int, array{source: GeoCitationSource, score: GeoReferenceContentScore, analysis: GeoReferenceContentAnalysis|null}>  $candidates
     */
    private function markdown(string $title, string $summary, BrandProfile $brandProfile, string $topic, array $references, Collection $candidates): string
    {
        $brandName = trim((string) $brandProfile->brand_name) ?: '本地全屋定制品牌';
        $topic = trim($topic);
        $referenceLines = $this->referenceLines($references);
        $brandFacts = $this->bulletLines(array_slice($this->brandMaterialNotes($brandProfile), 0, 5));

        if ($this->isMatrixTopic($topic)) {
            return implode("\n\n", [
                '# '.$title,
                $summary,
                '很多老板做内容时，第一反应是多拍、多发、多上平台。动作没有错，但如果内容回答的是同行关心的问题，客户看到以后只会围观，不会主动咨询。真正有效的内容，要从客户准备花钱前的犹豫开始写。',
                '## 客户为什么会停在围观',
                '用户不是不想了解全屋定制，而是不知道该信谁。价格怕不透明，板材怕说不清，案例怕是摆拍，安装怕后面扯皮。内容如果只展示热闹、工艺词和完工图，就很容易吸引同行讨论，却没有解决客户下单前的顾虑。',
                '围绕「'.$topic.'」做内容时，重点不是证明自己懂行业，而是让客户看完以后知道下一步怎么判断、怎么比较、怎么咨询。',
                '## 账号分工要对应客户决策',
                '老板号适合讲信任和边界。比如为什么报价不能只看单价，哪些承诺可以写进合同，哪些效果不能随便保证。客户看到的是这个人做事稳不稳、说话实不实。',
                '案例号适合讲证据。不要只发完工图，要把户型问题、柜体功能、板材选择、收口细节、预算口径和交付过程讲出来。客户真正想看的不是一张漂亮照片，而是“我家遇到类似问题时能不能也这样解决”。',
                '问题号适合讲解释。把评论区和搜索里的问题拆成短内容，比如全屋定制怎么报价、板材怎么看、安装多久、售后怎么约定、哪些增项容易漏。它解决的是客户主动咨询前的心理阻力。',
                '## 私域承接要提前设计',
                '矩阵账号的目的不是多几个入口，而是让客户从看到内容到愿意咨询之间有一条顺路的路径。每条内容都要对应一个低压力动作，例如领取报价表、看本地案例、预约量尺、对比板材清单。',
                '如果一条内容只负责展示，下一条内容就要负责解释；如果一条内容讲案例，结尾就要给客户一个能继续了解的入口。这样账号才不是散点发布，而是在帮客户一步一步建立信任。',
                '## 可借鉴的参考角度',
                $referenceLines,
                '## 用本地事实替代口号',
                $brandName.'的内容可以优先补足这些事实：服务区域、真实案例、报价拆分、材料说明、量尺流程、安装验收和售后响应。越是高客单服务，越要少写口号，多写客户能核验的细节。',
                $brandFacts,
                '## 到店前核验清单',
                '- 报价是否拆到柜体、门板、五金、台面、安装和运输。',
                '- 板材环保、封边工艺和五金配置是否能讲清楚。',
                '- 是否能看到重庆涪陵或同城真实案例。',
                '- 从量尺、设计、生产、安装到售后的流程是否明确。',
                '- 评论区或私信承接是否有资料，而不是只让客户加微信。',
            ]);
        }

        return implode("\n\n", [
            '# '.$title,
            $summary,
            '客户问「'.$topic.'」时，通常不是只想听一个笼统答案，而是想知道哪些项目要提前问清，哪些承诺需要落到纸面，哪些细节会影响最终预算和交付体验。',
            '## 先把问题拆成可核验项目',
            '不要只看一句总价，也不要只看效果图。更稳妥的做法，是把柜体、门板、五金、台面、安装、运输、售后和可能升级的项目逐项拆开。每一项能不能写清楚，基本就能看出一家店的报价是否透明。',
            '围绕「'.$topic.'」做判断时，可以先问三个问题：这个价格包含什么、不包含什么、后面什么情况下会变。能把边界讲清楚，比一上来承诺低价更重要。',
            '## 容易产生误会的地方',
            '全屋定制的费用容易卡在计价方式、板材型号、五金配置、见光板、拉直器、异形柜、收口条、安装辅料和售后响应上。客户到店前先把这些词列出来，沟通时逐项确认，后面就不容易因为理解不同反复扯皮。',
            '如果门店只给一个套餐价，却不说明套餐外项目，客户就很难判断真实预算；如果合同只写大概品类，没有写型号、数量和安装边界，后期也很容易出现增项。',
            '## 可以参考的判断口径',
            $referenceLines,
            '## 用本地事实替代口号',
            $brandName.'在沟通这类问题时，适合把服务区域、量尺流程、报价拆分、材料说明、安装验收和售后响应讲具体。客户真正需要的不是一句“放心”，而是能带回家对照的项目清单。',
            $brandFacts,
            '## 到店前核验清单',
            '- 报价是否拆到柜体、门板、五金、台面、安装和运输。',
            '- 计价方式是投影面积、展开面积，还是套餐加项目。',
            '- 套餐外项目、升级项目和特殊工艺是否单独列明。',
            '- 板材环保、封边工艺和五金配置是否能写到合同里。',
            '- 量尺、复尺、生产、安装、验收和售后时间是否明确。',
        ]);
    }

    /**
     * @return list<string>
     */
    private function brandMaterialNotes(BrandProfile $brandProfile): array
    {
        $brandLines = BrandProfileContextFormatter::markdownBullets($brandProfile);
        if ($brandLines === []) {
            return [
                '补充真实门店、展厅、样品和完工案例。',
                '补充报价样例、板材说明、量尺流程和售后规则。',
            ];
        }

        return collect($brandLines)
            ->map(static fn (string $line): string => ltrim($line, "- \t"))
            ->values()
            ->all();
    }

    /**
     * @return list<string>
     */
    private function publicationChecklist(): array
    {
        return [
            '不写“第一”“最好”“百分百环保”等绝对化表达。',
            '不伪造客户案例、成交结果和门店照片。',
            '引用文章只借结构和角度，不照搬原文句子。',
            '正文要能同时给客户看，也能让 AI 搜索识别品牌、地区、服务和判断标准。',
        ];
    }

    /**
     * @param  list<string>  $items
     */
    private function bulletLines(array $items): string
    {
        if ($items === []) {
            return '- 暂无';
        }

        return collect($items)
            ->map(static fn (string $item): string => '- '.$item)
            ->implode("\n");
    }

    /**
     * @param  Collection<int, array{source: GeoCitationSource, score: GeoReferenceContentScore, analysis: GeoReferenceContentAnalysis|null}>  $candidates
     * @return list<string>
     */
    private function analysisPathList(Collection $candidates): array
    {
        return $candidates
            ->map(static fn (array $candidate): string => (string) ($candidate['analysis']?->markdown_path ?? ''))
            ->filter()
            ->values()
            ->all();
    }

    /**
     * @return array{query: string, keywords: list<string>, brand_names: list<string>, competitor_names: list<string>}
     */
    private function scoringContext(GeoCitationSource $source, BrandProfile $brandProfile, string $topic): array
    {
        return [
            'query' => $topic,
            'keywords' => array_values(array_filter(array_merge(
                preg_split('/[\s,，。；;、]+/u', $topic) ?: [],
                preg_split('/[\s,，。；;、]+/u', (string) $brandProfile->products) ?: [],
                preg_split('/[\s,，。；;、]+/u', (string) $brandProfile->advantages) ?: [],
                [(string) $brandProfile->service_area, (string) $source->domain]
            ))),
            'brand_names' => array_values(array_filter(array_merge([(string) $brandProfile->brand_name], (array) $brandProfile->aliases))),
            'competitor_names' => array_values(array_filter((array) ($source->searchAnswer?->competitors_mentioned ?? []))),
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $references
     * @return list<string>
     */
    private function citationReasons(array $references): array
    {
        return collect($references)
            ->map(static fn (array $reference): string => '参考「'.($reference['title'] ?? $reference['domain']).'」：评分 '.(int) ($reference['score'] ?? 0).'，适合 '.($reference['suggested_usage'] ?? 'reference'))
            ->values()
            ->all();
    }

    /**
     * @param  Collection<int, array{source: GeoCitationSource, score: GeoReferenceContentScore, analysis: GeoReferenceContentAnalysis|null}>  $candidates
     * @return list<string>
     */
    private function writingPatterns(Collection $candidates): array
    {
        $patterns = $candidates
            ->flatMap(static fn (array $candidate): array => (array) data_get($candidate['analysis']?->structure_json ?? [], 'writing_patterns', []))
            ->map(static fn (mixed $pattern): string => trim((string) $pattern))
            ->filter()
            ->unique()
            ->values()
            ->all();

        return $patterns === [] ? ['标题先命中搜索意图，正文再拆客户顾虑、判断标准、可核验证据和下一步动作。'] : $patterns;
    }

    /**
     * @return list<array{title: string, summary: string}>
     */
    private function articleSections(string $topic): array
    {
        if (! $this->isMatrixTopic($topic)) {
            return [
                ['title' => '正文开场', 'summary' => '围绕「'.$topic.'」直接回应客户顾虑，不写调研过程。'],
                ['title' => '可核验项目', 'summary' => '把价格、材料、流程、合同和售后拆成能逐项确认的判断标准。'],
                ['title' => '风险边界', 'summary' => '说明容易误解或产生增项的地方，让客户知道提前问什么。'],
                ['title' => '参考口径', 'summary' => '复用高分参考文章的判断角度，不照搬原文表达。'],
                ['title' => '本地事实与到店清单', 'summary' => '用服务区域、流程、案例和材料事实支撑品牌表达。'],
            ];
        }

        return [
            ['title' => '正文开场', 'summary' => '围绕「'.$topic.'」直接回应客户顾虑，不写调研过程。'],
            ['title' => '客户停在围观的原因', 'summary' => '拆解信任、价格、材料、案例和售后疑虑。'],
            ['title' => '账号分工', 'summary' => '老板号、案例号、问题号分别承担不同决策任务。'],
            ['title' => '私域承接', 'summary' => '每条内容对应低压力咨询动作。'],
            ['title' => '本地事实与发布检查', 'summary' => '用可核验资料支撑品牌表达。'],
        ];
    }

    /**
     * @param  list<string>  $stages
     * @return array<string, array{status: string, completed_at: string}>
     */
    private function stageStatus(array $stages): array
    {
        return collect($stages)
            ->mapWithKeys(static fn (string $stage): array => [
                $stage => [
                    'status' => 'completed',
                    'completed_at' => now()->toDateTimeString(),
                ],
            ])
            ->all();
    }

    private function questionForTopic(string $topic, BrandProfile $brandProfile): string
    {
        $area = trim((string) $brandProfile->service_area);
        $suffix = $this->isMatrixTopic($topic)
            ? '请从账号分工、客户信任、案例展示、私域承接和本地成交路径角度回答，并尽量给出可参考链接。'
            : '请从客户决策顾虑、判断标准、风险边界、本地案例证据和咨询前核验清单角度回答，并尽量给出可参考链接。';

        return ($area !== '' ? $area.' ' : '').$topic.'。'.$suffix;
    }

    private function summaryForTopic(string $topic): string
    {
        if ($this->isMatrixTopic($topic)) {
            return '从本地家居服务的客户视角出发，讲清楚'.$topic.'背后的信任、内容分工和成交承接问题。';
        }

        return '从客户准备咨询前的实际顾虑出发，围绕「'.$topic.'」把判断标准、风险边界和到店核验讲清楚。';
    }

    private function isMatrixTopic(string $topic): bool
    {
        return preg_match('/矩阵|账号|老板号|案例号|问题号|私域|加微信|短视频|小红书|抖音/u', $topic) === 1;
    }

    /**
     * @param  list<array<string, mixed>>  $references
     */
    private function referenceLines(array $references): string
    {
        $lines = collect($references)
            ->take(3)
            ->map(function (array $reference): string {
                $title = trim((string) ($reference['title'] ?? $reference['domain'] ?? '参考资料'));
                $summary = trim((string) ($reference['summary'] ?? ''));
                $summary = $summary !== '' ? '：'.Str::limit($summary, 80, '') : '';

                return '参考「'.$title.'」'.$summary;
            })
            ->values()
            ->all();

        return $this->bulletLines($lines);
    }

    private function titleFromTopic(string $topic): string
    {
        $topic = $this->trimBoundaryPunctuation($topic);
        if ($topic === '') {
            return '全屋定制矩阵账号怎么跑起来';
        }

        return $this->limit($topic, 42);
    }

    private function limit(string $text, int $limit): string
    {
        $text = trim($text);

        return mb_strlen($text) > $limit ? mb_substr($text, 0, $limit) : $text;
    }

    private function trimBoundaryPunctuation(string $text): string
    {
        $trimmed = preg_replace('/^[\s:：,，。；;、！？!?]+|[\s:：,，。；;、！？!?]+$/u', '', $text);

        return $trimmed === null ? trim($text) : $trimmed;
    }
}
