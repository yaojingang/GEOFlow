<?php

namespace App\Services\GeoFlow;

use App\Ai\Agents\MarkdownContentWriterAgent;
use App\Models\AiModel;
use App\Models\EnterpriseKnowledgeProject;
use App\Models\KnowledgeBase;
use App\Support\GeoFlow\ApiKeyCrypto;
use App\Support\GeoFlow\OpenAiRuntimeProvider;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Throwable;

final class EnterpriseKnowledgeDraftService
{
    /**
     * @var list<string>
     */
    private const REQUIRED_SECTIONS = [
        '企业介绍',
        '业务信息摘要',
        '产品能力',
        '应用场景',
        '典型案例',
        'FAQ',
        '禁用表述',
        '风险与冲突',
        '待人工确认',
    ];

    /**
     * @var list<array{key:string,title:string,sections:list<string>}>
     */
    private const DRAFT_MODULES = [
        [
            'key' => 'profile',
            'title' => '企业介绍与业务信息摘要',
            'sections' => ['企业介绍', '业务信息摘要'],
        ],
        [
            'key' => 'capabilities',
            'title' => '产品能力',
            'sections' => ['产品能力'],
        ],
        [
            'key' => 'scenarios_cases',
            'title' => '应用场景与典型案例',
            'sections' => ['应用场景', '典型案例'],
        ],
        [
            'key' => 'faq_risk',
            'title' => 'FAQ、禁用表述、风险与待确认项',
            'sections' => ['FAQ', '禁用表述', '风险与冲突', '待人工确认'],
        ],
    ];

    private const MODULAR_DRAFT_MIN_CHARS = 12000;

    private const SINGLE_PROMPT_SOURCE_BUDGET = 36000;

    private const MODULE_RELEVANT_FACT_BUDGET = 22000;

    private const MODULE_CONTEXT_BUDGET = 12000;

    private const SOURCE_COVERAGE_APPEND_CHAR_LIMIT = 160000;

    public function __construct(
        private readonly ApiKeyCrypto $apiKeyCrypto,
        private readonly KnowledgeChunkSyncService $chunkSyncService,
    ) {}

    /**
     * @return array{content:string,source:string,model_id:?int,error:?string}
     */
    public function generateDraft(EnterpriseKnowledgeProject $project): array
    {
        $sourceBlocks = $this->sourceBlocks($project);
        $sourceText = $this->buildSourceText($project, $sourceBlocks);
        $sourceFacts = $this->sourceCoverageFacts($sourceText);
        $fallback = $this->buildFallbackDraft($project, $sourceText, $sourceBlocks);
        $fallback['content'] = $this->ensureSourceCoverage((string) $fallback['content'], $sourceFacts);

        foreach ($this->resolveAnalysisModels() as $model) {
            try {
                $runtime = $this->prepareAiRuntime($model);
                $content = $this->generateAiDraftContent($project, $sourceText, $sourceBlocks, $sourceFacts, $runtime);
                if ($content === '') {
                    throw new \RuntimeException(__('admin.enterprise_knowledge.error.ai_empty'));
                }
                $content = $this->ensureSourceCoverage($content, $sourceFacts);
                $missingSections = $this->missingRequiredSections($content);
                if ($missingSections !== []) {
                    throw new \RuntimeException(__('admin.enterprise_knowledge.error.ai_incomplete', [
                        'sections' => implode('、', $missingSections),
                    ]));
                }
                $noiseResidues = $this->draftNoiseResidues($content);
                if ($noiseResidues !== []) {
                    throw new \RuntimeException(__('admin.enterprise_knowledge.error.ai_noise', [
                        'items' => implode('、', $noiseResidues),
                    ]));
                }

                return [
                    'content' => $content,
                    'source' => 'ai',
                    'model_id' => (int) $model->id,
                    'error' => null,
                ];
            } catch (Throwable $exception) {
                $fallback['error'] = OpenAiRuntimeProvider::normalizeApiException($exception, (string) $model->api_url);
            }
        }

        return $fallback;
    }

    /**
     * @return list<array{level:string,message:string,section:string}>
     */
    public function validateDraft(string $content): array
    {
        $content = trim($content);
        $items = [];

        foreach (self::REQUIRED_SECTIONS as $section) {
            if (! preg_match('/^#{1,3}\s*'.preg_quote($section, '/').'/mu', $content)) {
                $items[] = [
                    'level' => 'warning',
                    'section' => $section,
                    'message' => __('admin.enterprise_knowledge.validation.missing_section', ['section' => $section]),
                ];
            }
        }

        if (mb_strlen($content, 'UTF-8') < 500) {
            $items[] = [
                'level' => 'warning',
                'section' => __('admin.enterprise_knowledge.validation.overall'),
                'message' => __('admin.enterprise_knowledge.validation.content_short'),
            ];
        }

        $riskyWords = ['唯一', '第一', '保证', '100%', '永久', '无风险', '最权威', '最佳', '最强', '最高'];
        foreach ($riskyWords as $word) {
            if (str_contains($content, $word)) {
                $items[] = [
                    'level' => 'danger',
                    'section' => __('admin.enterprise_knowledge.validation.risk'),
                    'message' => __('admin.enterprise_knowledge.validation.risky_word', ['word' => $word]),
                ];
            }
        }

        $todoCount = substr_count($content, '待补充');
        if ($todoCount > 0) {
            $items[] = [
                'level' => 'info',
                'section' => __('admin.enterprise_knowledge.validation.evidence'),
                'message' => __('admin.enterprise_knowledge.validation.todo_count', ['count' => $todoCount]),
            ];
        }

        if ($this->draftNoiseResidues($content) !== []) {
            $items[] = [
                'level' => 'warning',
                'section' => __('admin.enterprise_knowledge.validation.evidence'),
                'message' => __('admin.enterprise_knowledge.validation.generic_template_residue'),
            ];
        }

        return $items;
    }

    /**
     * @return list<string>
     */
    private function missingRequiredSections(string $content): array
    {
        return collect(self::REQUIRED_SECTIONS)
            ->filter(static fn (string $section): bool => ! preg_match('/^#{1,3}\s*'.preg_quote($section, '/').'/mu', $content))
            ->values()
            ->all();
    }

    /**
     * @return list<string>
     */
    private function draftNoiseResidues(string $content): array
    {
        $patterns = [
            '资料覆盖清单' => '/^#{1,3}\s*资料覆盖清单\s*$/mu',
            '证据与来源' => '/^#{1,3}\s*证据与来源\s*$/mu',
            '自动补全与纠错记录' => '/^#{1,3}\s*自动补全与纠错记录\s*$/mu',
            '原始资料摘录' => '/以下为原始资料摘录/u',
            '来源数量' => '/来源数量|资料总字数|文件字数|字符数|主要来源/u',
            '来源编号' => '/(^|[\s（(：:，,。；;])\[\d{1,3}\](?=\s|\S)/u',
            '来源文件字数' => '/[（(][^）)]{0,60}(?:word|markdown|txt|docx|md)[^）)]{0,30}\d+\s*字[^）)]*[）)]/iu',
            '来源片段标题' => '/^#{1,6}\s*来源：|资料片段：/mu',
            '回退模板说明' => '/这个知识库适合回答哪些问题|当前为稳定回退草稿/u',
        ];

        return collect($patterns)
            ->filter(static fn (string $pattern): bool => preg_match($pattern, $content) === 1)
            ->keys()
            ->values()
            ->all();
    }

    /**
     * @return array{knowledge_base:KnowledgeBase,chunk_count:int,chunk_error:?string}
     */
    public function publishToKnowledgeBase(EnterpriseKnowledgeProject $project, string $content): array
    {
        $metadata = [
            'source_name' => (string) $project->name,
            'source_type' => 'business',
            'business_line' => trim((string) $project->description),
            'risk_level' => 'medium',
            'review_status' => 'reviewed',
        ];

        $metadata = array_filter(
            $metadata,
            static fn (mixed $value, string $column): bool => $value !== '' && Schema::hasColumn('knowledge_bases', $column),
            ARRAY_FILTER_USE_BOTH
        );

        $payload = [
            'name' => (string) $project->name,
            'description' => (string) $project->description,
            'content' => $content,
            'character_count' => mb_strlen($content, 'UTF-8'),
            'used_task_count' => 0,
            'file_type' => 'markdown',
            'file_path' => '',
            'word_count' => mb_strlen(strip_tags($content), 'UTF-8'),
            'usage_count' => 0,
        ] + $metadata;

        $project->loadMissing('publishedKnowledgeBase');
        $knowledgeBase = $project->publishedKnowledgeBase;
        if ($knowledgeBase) {
            $knowledgeBase->forceFill($payload)->save();
        } else {
            $knowledgeBase = KnowledgeBase::query()->create($payload);
        }

        $chunkCount = 0;
        $chunkError = null;
        try {
            $chunkCount = $this->chunkSyncService->sync((int) $knowledgeBase->id, $content);
        } catch (Throwable $exception) {
            $chunkError = $exception->getMessage();
        }

        return [
            'knowledge_base' => $knowledgeBase,
            'chunk_count' => $chunkCount,
            'chunk_error' => $chunkError,
        ];
    }

    private function buildSystemPrompt(): string
    {
        return <<<'PROMPT'
你是 GEOFlow 企业知识库整理助手。你的任务是把企业资料整理成可审核、可切片、可引用的 Markdown 标准知识稿。
规则：
1. 本阶段是“初稿整理”，依赖聊天模型的语义理解能力，不做向量化，也不要求 Embedding 模型。
2. 先穷举盘点输入资料中的事实、能力、场景、案例、FAQ、限制条件、流程、参数、限制和风险表述，再整理成结构化知识稿，不能遗漏高价值信息。
3. 只能基于输入资料，不要编造事实、客户、案例、价格、资质、效果数据或承诺。
4. 可以做轻量清洗和小错误纠正，例如错别字、标点、重复空白、明显格式错误；不确定、需要补传或需要人工确认的信息，统一放入“待人工确认”。
5. 每个知识点尽量整理成“知识原子”：明确主张、适用对象/业务线/时间边界、可核验依据线索、风险或待确认状态。
6. 输出必须个性化贴合资料内容，不要套用固定模板、通用行业话术或空泛营销表达。
7. 资料不足时写“资料中未发现明确信息，建议人工补充或补传资料。”，不要把缺失内容扩写成事实。
8. 正文要像正式企业知识库内容，不输出来源清单、来源编号、角标引用、文件名列表、资料数量、字数、字符数或处理过程说明。
9. 禁用表述要结合资料中的风险词和绝对化表达，列出不应在内容生成中使用的说法。
10. 输出必须包含：企业介绍、业务信息摘要、产品能力、应用场景、典型案例、FAQ、禁用表述、风险与冲突、待人工确认。
11. 宁可用更多条目保留原资料细节，也不要把多个不同事实压缩成一句泛泛总结；产品功能、服务流程、适用对象、交付方式、边界条件、FAQ 问答、案例线索都要尽量逐条保留。
PROMPT;
    }

    /**
     * @param  list<array{name:string,type:string,content:string,characters:int}>  $sourceBlocks
     * @param  list<array{text:string,section:string}>  $sourceFacts
     * @param  array{provider:string,model_id:string,base_url:string}  $runtime
     */
    private function generateAiDraftContent(
        EnterpriseKnowledgeProject $project,
        string $sourceText,
        array $sourceBlocks,
        array $sourceFacts,
        array $runtime
    ): string {
        if ($this->shouldUseModularDraft($sourceText, $sourceBlocks)) {
            try {
                return $this->generateModularAiDraft($project, $sourceBlocks, $sourceFacts, $runtime);
            } catch (Throwable) {
                // Fall back to the original single-pass request for providers that do
                // not follow section-scoped instructions reliably.
            }
        }

        return $this->generateSingleAiDraft($project, $sourceText, $sourceBlocks, $runtime);
    }

    /**
     * @param  list<array{name:string,type:string,content:string,characters:int}>  $sourceBlocks
     * @param  array{provider:string,model_id:string,base_url:string}  $runtime
     */
    private function generateSingleAiDraft(
        EnterpriseKnowledgeProject $project,
        string $sourceText,
        array $sourceBlocks,
        array $runtime
    ): string {
        $agent = new MarkdownContentWriterAgent($this->buildSystemPrompt(), [], [], 6000);
        $response = $agent->prompt(
            $this->buildUserPrompt($project, $sourceText, $sourceBlocks),
            [],
            (string) $runtime['provider'],
            (string) $runtime['model_id']
        );

        return trim(OpenAiRuntimeProvider::normalizeGeneratedText($this->responseText($response)));
    }

    /**
     * @param  list<array{name:string,type:string,content:string,characters:int}>  $sourceBlocks
     * @param  list<array{text:string,section:string}>  $sourceFacts
     * @param  array{provider:string,model_id:string,base_url:string}  $runtime
     */
    private function generateModularAiDraft(
        EnterpriseKnowledgeProject $project,
        array $sourceBlocks,
        array $sourceFacts,
        array $runtime
    ): string {
        $sections = [];
        foreach (self::DRAFT_MODULES as $module) {
            $agent = new MarkdownContentWriterAgent($this->buildSystemPrompt(), [], [], 4200);
            $response = $agent->prompt(
                $this->buildModuleUserPrompt($project, $sourceBlocks, $sourceFacts, $module),
                [],
                (string) $runtime['provider'],
                (string) $runtime['model_id']
            );
            $moduleContent = trim(OpenAiRuntimeProvider::normalizeGeneratedText($this->responseText($response)));
            if ($moduleContent === '') {
                throw new \RuntimeException(__('admin.enterprise_knowledge.error.ai_empty'));
            }
            $noiseResidues = $this->draftNoiseResidues($moduleContent);
            if ($noiseResidues !== []) {
                throw new \RuntimeException(__('admin.enterprise_knowledge.error.ai_noise', [
                    'items' => implode('、', $noiseResidues),
                ]));
            }

            foreach ($module['sections'] as $section) {
                $body = $this->extractMarkdownSectionBody($moduleContent, $section);
                if ($body === '') {
                    throw new \RuntimeException(__('admin.enterprise_knowledge.error.ai_incomplete', [
                        'sections' => $section,
                    ]));
                }
                $sections[$section] = $body;
            }
        }

        $parts = ['# '.$project->name.' 企业标准知识稿'];
        foreach (self::REQUIRED_SECTIONS as $section) {
            $body = trim((string) ($sections[$section] ?? ''));
            if ($body === '') {
                throw new \RuntimeException(__('admin.enterprise_knowledge.error.ai_incomplete', [
                    'sections' => $section,
                ]));
            }
            $parts[] = '## '.$section."\n".$body;
        }

        return trim(implode("\n\n", $parts));
    }

    private function responseText(mixed $response): string
    {
        if (is_string($response)) {
            return $response;
        }

        if (! is_object($response)) {
            return '';
        }

        foreach (['text', 'content'] as $property) {
            $value = $response->{$property} ?? null;
            if (is_scalar($value)) {
                return (string) $value;
            }
        }

        foreach (['text', 'content'] as $method) {
            if (! method_exists($response, $method)) {
                continue;
            }

            try {
                $value = $response->{$method}();
            } catch (Throwable) {
                continue;
            }

            if (is_scalar($value)) {
                return (string) $value;
            }
        }

        if (method_exists($response, '__toString')) {
            try {
                return (string) $response;
            } catch (Throwable) {
                return '';
            }
        }

        return '';
    }

    /**
     * @param  list<array{name:string,type:string,content:string,characters:int}>  $sourceBlocks
     */
    private function buildUserPrompt(EnterpriseKnowledgeProject $project, string $sourceText, array $sourceBlocks): string
    {
        return sprintf(
            "项目名称：%s\n项目说明：%s\n\n资料概览：\n%s\n\n请使用聊天模型的语义理解能力，先完整盘点资料事实，再把以下资料整理为标准企业知识稿。要求：不要遗漏资料中的产品、场景、案例、FAQ、限制条件、流程细节、参数和风险信息；允许纠正错别字、标点和明显格式问题；输出必须尽可能详细丰富，宁可分条保留原资料细节，也不要摘要化成泛泛表述；输出必须是直接可发布的正式文本，不要输出来源清单、来源编号、角标引用、文件字数、资料数量或处理过程说明；不确定的内容进入“待人工确认”。\n\n%s",
            (string) $project->name,
            (string) $project->description,
            $this->buildSourceDigest($sourceBlocks, $sourceText),
            $this->buildPromptSourceExcerpt($sourceBlocks, self::SINGLE_PROMPT_SOURCE_BUDGET)
        );
    }

    /**
     * @param  list<array{name:string,type:string,content:string,characters:int}>  $sourceBlocks
     */
    private function shouldUseModularDraft(string $sourceText, array $sourceBlocks): bool
    {
        return mb_strlen($sourceText, 'UTF-8') >= self::MODULAR_DRAFT_MIN_CHARS
            || count($sourceBlocks) >= 3;
    }

    /**
     * @param  list<array{name:string,type:string,content:string,characters:int}>  $sourceBlocks
     * @param  list<array{text:string,section:string}>  $sourceFacts
     * @param  array{key:string,title:string,sections:list<string>}  $module
     */
    private function buildModuleUserPrompt(
        EnterpriseKnowledgeProject $project,
        array $sourceBlocks,
        array $sourceFacts,
        array $module
    ): string {
        $sections = implode('、', $module['sections']);
        $relevantFacts = $this->sourceFactsForSections($sourceFacts, $module['sections'], self::MODULE_RELEVANT_FACT_BUDGET);
        $context = $this->buildPromptSourceExcerpt($sourceBlocks, self::MODULE_CONTEXT_BUDGET);

        return sprintf(
            "项目名称：%s\n项目说明：%s\n\n你现在只负责生成这些章节：%s。\n要求：\n1. 只输出这些章节的 Markdown，不输出其他章节。\n2. 每个章节必须以二级标题 `## 章节名` 开始。\n3. 必须优先保留“本模块相关原文细节”中的每一条有效事实；不要摘要化，不要合并掉不同产品、流程、场景、限制或 FAQ。\n4. 只能基于资料，不要编造事实；无法确认的信息写入本模块对应的风险或待确认内容。\n5. 不要输出来源清单、来源编号、文件名、资料数量、字数、处理过程说明。\n\n本模块相关原文细节：\n%s\n\n资料上下文：\n%s",
            (string) $project->name,
            (string) $project->description,
            $sections,
            $relevantFacts !== '' ? $relevantFacts : '- 未提取到明显相关细节，请从资料上下文中整理。',
            $context
        );
    }

    /**
     * @param  list<array{name:string,type:string,content:string,characters:int}>|null  $sourceBlocks
     */
    private function buildSourceText(EnterpriseKnowledgeProject $project, ?array $sourceBlocks = null): string
    {
        $blocks = [];
        foreach ($sourceBlocks ?? $this->sourceBlocks($project) as $source) {
            $blocks[] = '# 来源：'.trim((string) $source['name'])."\n\n".trim((string) $source['content']);
        }

        return trim(implode("\n\n---\n\n", $blocks));
    }

    /**
     * @param  list<array{name:string,type:string,content:string,characters:int}>  $sourceBlocks
     * @return array{content:string,source:string,model_id:?int,error:?string}
     */
    private function buildFallbackDraft(EnterpriseKnowledgeProject $project, string $sourceText, array $sourceBlocks): array
    {
        $intro = $this->candidateLines($sourceText, ['公司', '企业', '品牌', '团队', '成立', '总部', '定位', '专注', '提供', '服务', '客户', '官网', '使命', '愿景'], 10);
        $capabilities = $this->candidateLines($sourceText, ['产品', '能力', '功能', '服务', '平台', '系统', '支持', '方案', '技术', '模型', '监测', '分析', '诊断', 'API', '设备', '告警', '维保', '工单', '传感器', '接入', '数据'], 12);
        $scenarios = $this->candidateLines($sourceText, ['场景', '适用', '用于', '面向', '帮助', '解决', '痛点', '需求', '业务', '运营', '营销', '内容'], 10);
        $cases = $this->candidateLines($sourceText, ['案例', '客户', '项目', '交付', '合作', '上线', '部署', '使用', '成效', '数据', '结果', '实践'], 8);
        $questions = $this->extractQuestions($sourceText, 8);
        $riskLines = $this->candidateLines($sourceText, ['唯一', '第一', '保证', '100%', '永久', '无风险', '最权威', '最佳', '最强', '最高', '价格', '资质', '承诺', '法律'], 8);
        $highSignalLines = $this->highSignalLines($sourceText, 18);
        $signalList = $this->bulletListOrEmpty($highSignalLines, '资料较少，暂未提取到更多高价值信息。');

        $emptyIntro = '资料中未发现明确企业介绍信息，建议人工补充公司定位、服务对象、业务边界和核心优势。';
        $emptyCapability = '资料中未发现明确产品能力信息，建议人工补充产品、服务、交付能力、技术能力和适用边界。';
        $emptyScenario = '资料中未发现明确应用场景信息，建议人工补充使用对象、业务目标、适用条件和限制。';
        $emptyCase = '资料中未发现可核验案例信息；如需案例，请补传客户、项目、交付结果或可公开证明材料。';
        $emptyFaq = '资料中未发现明确 FAQ；建议人工补充用户常问问题和可核验回答。';
        $introList = $this->bulletListOrEmpty($intro, $emptyIntro);
        $capabilityList = $this->bulletListOrEmpty($capabilities, $emptyCapability);
        $scenarioList = $this->bulletListOrEmpty($scenarios, $emptyScenario);
        $caseList = $this->bulletListOrEmpty($cases, $emptyCase);
        $faqList = $this->bulletListOrEmpty($questions, $emptyFaq);
        $riskList = $this->bulletListOrEmpty($riskLines, '不使用“唯一”“第一”“100%保证”“永久有效”“无风险”等绝对化表述，除非有明确证据。');

        $content = <<<MARKDOWN
# {$project->name} 企业标准知识稿

## 企业介绍
{$introList}

## 业务信息摘要
{$signalList}

## 产品能力
{$capabilityList}

## 应用场景
{$scenarioList}

## 典型案例
{$caseList}

## FAQ
{$faqList}

## 禁用表述
{$riskList}
- 不编造资料中没有出现的客户案例、合作品牌、认证资质、价格、排名或效果数据。

## 风险与冲突
- 当前草稿由系统基于上传资料自动整理；请人工复核事实、术语、案例、价格、资质、效果承诺和法律边界。
- 如果不同来源出现新旧信息冲突，优先以最新、最正式、最可核验的资料为准。

## 待人工确认
- 未在资料中找到明确证据的内容应保持空缺，不应在正式知识库中扩写为事实。
- 发布前建议补齐关键来源、确认可公开信息范围，并删除内部敏感内容。
MARKDOWN;

        return [
            'content' => $content,
            'source' => 'fallback',
            'model_id' => null,
            'error' => null,
        ];
    }

    /**
     * @return list<array{name:string,type:string,content:string,characters:int}>
     */
    private function sourceBlocks(EnterpriseKnowledgeProject $project): array
    {
        $project->loadMissing('sources');

        return $project->sources
            ->sortBy([
                ['sort_order', 'asc'],
                ['id', 'asc'],
            ])
            ->map(function ($source): array {
                $content = trim((string) $source->content);

                return [
                    'name' => trim((string) $source->original_name) ?: __('admin.enterprise_knowledge.manual_source_name'),
                    'type' => trim((string) $source->file_type) ?: 'text',
                    'content' => $content,
                    'characters' => mb_strlen($content, 'UTF-8'),
                ];
            })
            ->filter(static fn (array $source): bool => $source['content'] !== '')
            ->values()
            ->all();
    }

    /**
     * @param  list<array{name:string,type:string,content:string,characters:int}>  $sourceBlocks
     */
    private function buildSourceDigest(array $sourceBlocks, string $sourceText): string
    {
        $headings = $this->extractHeadings($sourceText, 10);
        $signals = $this->highSignalLines($sourceText, 12);

        return implode("\n", [
            '- 资料标题线索：'.($headings !== [] ? implode('；', $headings) : '未发现明确标题'),
            '- 重点事实线索：'.($signals !== [] ? implode('；', $signals) : '资料较少，需人工补充'),
            '- 正文覆盖范围：企业介绍、业务信息摘要、产品能力、应用场景、典型案例、FAQ、禁用表述、风险与冲突、待人工确认',
        ]);
    }

    /**
     * Keep every uploaded source visible to the AI. A single long file should not
     * push later sources out of the prompt.
     *
     * @param  list<array{name:string,type:string,content:string,characters:int}>  $sourceBlocks
     */
    private function buildPromptSourceExcerpt(array $sourceBlocks, int $budget): string
    {
        if ($sourceBlocks === []) {
            return '[未提供可解析来源]';
        }

        $perSourceBudget = max(1800, (int) floor($budget / max(count($sourceBlocks), 1)));

        return collect($sourceBlocks)
            ->map(function (array $source) use ($perSourceBudget): string {
                $content = trim((string) $source['content']);
                $excerpt = $this->distributedSourceExcerpt($content, $perSourceBudget);

                return sprintf("## 资料片段：%s\n\n%s", $source['name'], $excerpt);
            })
            ->implode("\n\n---\n\n");
    }

    private function distributedSourceExcerpt(string $content, int $budget): string
    {
        $content = trim($content);
        if ($content === '' || mb_strlen($content, 'UTF-8') <= $budget) {
            return $content;
        }

        $signalBudget = max(800, (int) floor($budget * 0.28));
        $sliceBudget = max(300, (int) floor(($budget - $signalBudget) / 3));
        $length = mb_strlen($content, 'UTF-8');
        $middleStart = max(0, (int) floor(($length - $sliceBudget) / 2));
        $tailStart = max(0, $length - $sliceBudget);
        $signals = $this->limitLinesForPrompt($this->highSignalLines($content, 28), $signalBudget);

        return trim(implode("\n\n", array_filter([
            "### 高信号细节\n".($signals !== '' ? $signals : '- 未提取到高信号细节'),
            "### 开头片段\n".trim(mb_substr($content, 0, $sliceBudget, 'UTF-8')),
            "### 中段片段\n".trim(mb_substr($content, $middleStart, $sliceBudget, 'UTF-8')),
            "### 结尾片段\n".trim(mb_substr($content, $tailStart, $sliceBudget, 'UTF-8')),
            '[该来源过长，已按高信号、开头、中段、结尾分布式截取]',
        ])));
    }

    /**
     * @param  list<array{text:string,section:string}>  $sourceFacts
     * @param  list<string>  $sections
     */
    private function sourceFactsForSections(array $sourceFacts, array $sections, int $budget): string
    {
        $sectionSet = array_fill_keys($sections, true);
        $lines = [];
        foreach ($sourceFacts as $fact) {
            if (! isset($sectionSet[(string) $fact['section']])) {
                continue;
            }
            $lines[] = (string) $fact['text'];
        }

        if ($lines === []) {
            foreach ($sourceFacts as $fact) {
                $lines[] = (string) $fact['text'];
                if (count($lines) >= 30) {
                    break;
                }
            }
        }

        return $this->limitLinesForPrompt($lines, $budget);
    }

    /**
     * @param  list<string>  $lines
     */
    private function limitLinesForPrompt(array $lines, int $budget): string
    {
        $out = [];
        $used = 0;
        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }
            $bullet = '- '.$line;
            $used += mb_strlen($bullet, 'UTF-8') + 1;
            if ($used > $budget) {
                break;
            }
            $out[] = $bullet;
        }

        return implode("\n", $out);
    }

    private function extractMarkdownSectionBody(string $content, string $section): string
    {
        $lines = preg_split('/\R/u', $content) ?: [];
        $capturing = false;
        $baseLevel = 0;
        $body = [];

        foreach ($lines as $line) {
            $line = (string) $line;
            if (preg_match('/^(#{1,6})\s+(.+)$/u', trim($line), $match) === 1) {
                $level = strlen((string) $match[1]);
                $heading = trim((string) $match[2]);
                $heading = trim($heading, " \t\n\r\0\x0B#*_`：:");

                if ($capturing && $level <= $baseLevel) {
                    break;
                }

                if ($heading === $section) {
                    $capturing = true;
                    $baseLevel = $level;

                    continue;
                }
            }

            if ($capturing) {
                $body[] = $line;
            }
        }

        return trim(implode("\n", $body));
    }

    /**
     * @return list<string>
     */
    private function extractHeadings(string $text, int $limit = 12): array
    {
        preg_match_all('/^#{1,4}\s+(.+)$/mu', $text, $matches);

        return collect($matches[1] ?? [])
            ->map(fn (string $heading): string => $this->normalizeCandidateLine($heading))
            ->filter()
            ->unique()
            ->take($limit)
            ->values()
            ->all();
    }

    /**
     * @return list<string>
     */
    private function highSignalLines(string $text, int $limit = 20): array
    {
        $lines = preg_split('/\R/u', $text) ?: [];

        return collect($lines)
            ->map(fn (string $line): string => $this->normalizeCandidateLine($line))
            ->filter(fn (string $line): bool => mb_strlen($line, 'UTF-8') >= 16)
            ->filter(fn (string $line): bool => ! preg_match('/^#{1,6}\s*/u', $line))
            ->sortByDesc(fn (string $line): int => $this->signalScore($line))
            ->unique()
            ->take($limit)
            ->values()
            ->all();
    }

    /**
     * @param  list<string>  $keywords
     * @return list<string>
     */
    private function candidateLines(string $text, array $keywords, int $limit = 10): array
    {
        $lines = preg_split('/\R/u', $text) ?: [];

        return collect($lines)
            ->map(fn (string $line): string => $this->normalizeCandidateLine($line))
            ->filter(fn (string $line): bool => $line !== '')
            ->filter(fn (string $line): bool => $this->containsAny($line, $keywords))
            ->filter(fn (string $line): bool => mb_strlen($line, 'UTF-8') >= 3)
            ->sortByDesc(fn (string $line): int => $this->signalScore($line))
            ->unique()
            ->take($limit)
            ->values()
            ->all();
    }

    /**
     * @return list<string>
     */
    private function extractQuestions(string $text, int $limit = 8): array
    {
        $lines = preg_split('/\R/u', $text) ?: [];
        $questions = [];

        foreach ($lines as $index => $line) {
            $line = $this->normalizeCandidateLine($line);
            if ($line === '' || ! preg_match('/(？|\?|^Q[:：]|^问[:：]|FAQ)/iu', $line)) {
                continue;
            }

            $next = $this->normalizeCandidateLine((string) ($lines[$index + 1] ?? ''));
            if ($next !== '' && preg_match('/^(A[:：]|答[:：])/iu', $next)) {
                $line .= ' '.$next;
            }

            $questions[] = $line;
            if (count($questions) >= $limit) {
                break;
            }
        }

        return collect($questions)->unique()->values()->all();
    }

    /**
     * @return list<array{text:string,section:string}>
     */
    private function sourceCoverageFacts(string $sourceText): array
    {
        $lines = preg_split('/\R/u', $sourceText) ?: [];
        $facts = [];
        $seen = [];
        $currentSection = '业务信息摘要';
        $inFence = false;

        foreach ($lines as $rawLine) {
            $trimmed = trim((string) $rawLine);
            if ($trimmed === '') {
                continue;
            }

            if (preg_match('/^(```+|~~~+)/u', $trimmed) === 1) {
                $inFence = ! $inFence;

                continue;
            }

            if ($inFence || $trimmed === '---') {
                continue;
            }

            $candidate = '';
            if (preg_match('/^(#{1,6})\s+(.+)$/u', $trimmed, $headingMatch) === 1) {
                $heading = $this->normalizeCoverageLine((string) $headingMatch[2]);
                if ($this->isInternalSourceHeading($heading)) {
                    continue;
                }
                $currentSection = $this->inferCoverageSection($heading, $currentSection);
                if (! $this->isGenericCoverageHeading($heading)) {
                    $candidate = $heading;
                }
            } else {
                $candidate = $this->normalizeCoverageLine($trimmed);
            }

            if ($candidate === '' || $this->shouldSkipCoverageLine($candidate)) {
                continue;
            }

            $key = $this->coverageKey($candidate);
            if ($key === '' || isset($seen[$key])) {
                continue;
            }

            $seen[$key] = true;
            $facts[] = [
                'text' => $candidate,
                'section' => $this->inferCoverageSection($candidate, $currentSection),
            ];
        }

        return $facts;
    }

    /**
     * @param  list<array{text:string,section:string}>  $sourceFacts
     */
    private function ensureSourceCoverage(string $content, array $sourceFacts): string
    {
        $content = trim($content);
        if ($content === '' || $sourceFacts === []) {
            return $content;
        }

        $normalizedContent = $this->coverageKey($content);
        $missingBySection = [];
        $usedChars = 0;
        $overflowCount = 0;

        foreach ($sourceFacts as $fact) {
            $text = trim((string) $fact['text']);
            if ($text === '' || $this->contentContainsCoverageFact($normalizedContent, $text)) {
                continue;
            }

            $lineChars = mb_strlen($text, 'UTF-8') + 3;
            if ($usedChars + $lineChars > self::SOURCE_COVERAGE_APPEND_CHAR_LIMIT) {
                $overflowCount++;

                continue;
            }

            $usedChars += $lineChars;
            $section = in_array((string) $fact['section'], self::REQUIRED_SECTIONS, true)
                ? (string) $fact['section']
                : '业务信息摘要';
            $missingBySection[$section][] = $text;
            $normalizedContent .= $this->coverageKey($text);
        }

        foreach ($missingBySection as $section => $lines) {
            $content = $this->appendCoverageLinesToSection($content, (string) $section, $lines);
        }

        if ($overflowCount > 0) {
            $content = $this->appendCoverageLinesToSection($content, '待人工确认', [
                "仍有 {$overflowCount} 条来源细节超过当前草稿补录上限，发布前请回到资料来源中人工复核。",
            ]);
        }

        return trim($content);
    }

    /**
     * @param  list<string>  $lines
     */
    private function appendCoverageLinesToSection(string $content, string $section, array $lines): string
    {
        $lines = array_values(array_unique(array_filter(array_map(
            fn (string $line): string => $this->normalizeCoverageLine($line),
            $lines
        ))));
        if ($lines === []) {
            return $content;
        }

        $insertBlock = "\n\n### 补充细节\n".collect($lines)
            ->map(static fn (string $line): string => '- '.$line)
            ->implode("\n");
        $contentLines = preg_split('/\R/u', $content) ?: [];
        $sectionIndex = null;
        $sectionLevel = 0;

        foreach ($contentLines as $index => $line) {
            if (preg_match('/^(#{1,6})\s+(.+)$/u', trim((string) $line), $match) !== 1) {
                continue;
            }

            $heading = trim((string) $match[2], " \t\n\r\0\x0B#*_`：:");
            if ($heading === $section) {
                $sectionIndex = $index;
                $sectionLevel = strlen((string) $match[1]);
                break;
            }
        }

        if ($sectionIndex === null) {
            return trim($content)."\n\n## {$section}{$insertBlock}";
        }

        $insertIndex = count($contentLines);
        for ($index = $sectionIndex + 1; $index < count($contentLines); $index++) {
            if (preg_match('/^(#{1,6})\s+(.+)$/u', trim((string) $contentLines[$index]), $match) === 1
                && strlen((string) $match[1]) <= $sectionLevel) {
                $insertIndex = $index;
                break;
            }
        }

        array_splice($contentLines, $insertIndex, 0, explode("\n", trim($insertBlock)));

        return trim(implode("\n", $contentLines));
    }

    private function normalizeCoverageLine(string $line): string
    {
        $line = trim(strip_tags($line));
        $line = preg_replace('/!\[[^\]]*]\([^)]+\)/u', '', $line) ?? $line;
        $line = preg_replace('/\[[0-9]{1,3}]/u', '', $line) ?? $line;
        $line = preg_replace('/^#{1,6}\s*/u', '', $line) ?? $line;
        $line = preg_replace('/^>\s*/u', '', $line) ?? $line;
        $line = preg_replace('/^[-*+]\s+/u', '', $line) ?? $line;
        $line = preg_replace('/^\d+[.、]\s*/u', '', $line) ?? $line;
        $line = preg_replace('/\s+/u', ' ', $line) ?? $line;
        $line = trim($line, " \t\n\r\0\x0B|");

        return trim(Str::limit($line, 320, '...'));
    }

    private function shouldSkipCoverageLine(string $line): bool
    {
        if (mb_strlen($line, 'UTF-8') < 4) {
            return true;
        }

        if (preg_match('/^[\s\p{P}\p{S}]+$/u', $line) === 1) {
            return true;
        }

        if (preg_match('/^\|?\s*:?-{3,}:?\s*(\|\s*:?-{3,}:?\s*)+\|?$/u', $line) === 1) {
            return true;
        }

        return preg_match('/来源数量|资料总字数|文件字数|字符数|主要来源|处理过程|资料覆盖清单/u', $line) === 1;
    }

    private function isInternalSourceHeading(string $heading): bool
    {
        return preg_match('/^(来源|文件|手动输入内容)(：|:|$)/u', $heading) === 1;
    }

    private function isGenericCoverageHeading(string $heading): bool
    {
        return in_array($heading, ['企业介绍', '业务信息摘要', '产品能力', '应用场景', '典型案例', 'FAQ', '禁用表述', '风险与冲突', '待人工确认'], true);
    }

    private function inferCoverageSection(string $line, string $fallback = '业务信息摘要'): string
    {
        if (preg_match('/(FAQ|Q[:：]|问[:：]|问题|咨询|？|\?)/iu', $line) === 1) {
            return 'FAQ';
        }

        if ($this->containsAny($line, ['禁用', '不能', '不得', '风险', '合规', '承诺', '唯一', '第一', '保证', '100%', '永久', '无风险', '最权威', '最佳', '最强', '最高', '法律'])) {
            return '风险与冲突';
        }

        if ($this->containsAny($line, ['案例', '客户', '项目', '交付', '合作', '上线', '部署', '成效', '实践'])) {
            return '典型案例';
        }

        if ($this->containsAny($line, ['场景', '适用', '用于', '面向', '帮助', '解决', '痛点', '需求', '业务', '运营', '营销', '内容'])) {
            return '应用场景';
        }

        if ($this->containsAny($line, ['产品', '能力', '功能', '服务', '平台', '系统', '支持', '方案', '技术', '模型', '监测', '分析', '诊断', 'API', '设备', '告警', '维保', '工单', '传感器', '接入', '数据', '流程', '模块'])) {
            return '产品能力';
        }

        if ($this->containsAny($line, ['公司', '企业', '品牌', '团队', '成立', '总部', '定位', '专注', '官网', '使命', '愿景'])) {
            return '企业介绍';
        }

        return in_array($fallback, self::REQUIRED_SECTIONS, true) ? $fallback : '业务信息摘要';
    }

    private function contentContainsCoverageFact(string $normalizedContent, string $fact): bool
    {
        $key = $this->coverageKey($fact);
        $length = mb_strlen($key, 'UTF-8');
        if ($key === '') {
            return true;
        }

        if (str_contains($normalizedContent, $key)) {
            return true;
        }

        if ($length < 32) {
            return false;
        }

        $prefix = mb_substr($key, 0, min(36, $length), 'UTF-8');
        $suffix = mb_substr($key, max(0, $length - 28), null, 'UTF-8');

        return $prefix !== '' && $suffix !== ''
            && str_contains($normalizedContent, $prefix)
            && str_contains($normalizedContent, $suffix);
    }

    private function coverageKey(string $text): string
    {
        $text = mb_strtolower($text, 'UTF-8');
        $text = preg_replace('/[\s\p{P}\p{S}]+/u', '', $text) ?? $text;

        return trim($text);
    }

    /**
     * @param  list<string>  $lines
     */
    private function bulletListOrEmpty(array $lines, string $empty): string
    {
        if ($lines === []) {
            return '- '.$empty;
        }

        return collect($lines)
            ->map(static fn (string $line): string => '- '.$line)
            ->implode("\n");
    }

    private function normalizeCandidateLine(string $line): string
    {
        $line = preg_replace('/\s+/u', ' ', trim($line)) ?? '';
        $line = trim($line, "- \t\n\r\0\x0B");
        $line = preg_replace('/^\d+[.、]\s*/u', '', $line) ?? $line;

        return trim(Str::limit($line, 220, '...'));
    }

    /**
     * @param  list<string>  $needles
     */
    private function containsAny(string $haystack, array $needles): bool
    {
        foreach ($needles as $needle) {
            if ($needle !== '' && mb_stripos($haystack, $needle, 0, 'UTF-8') !== false) {
                return true;
            }
        }

        return false;
    }

    private function signalScore(string $line): int
    {
        $score = 0;
        foreach (['产品', '服务', '能力', '场景', '客户', '案例', '数据', '支持', '系统', '平台', '方案', 'FAQ', '问题', '风险'] as $keyword) {
            if (mb_stripos($line, $keyword, 0, 'UTF-8') !== false) {
                $score += 4;
            }
        }

        if (preg_match('/[：:]/u', $line)) {
            $score += 2;
        }

        $length = mb_strlen($line, 'UTF-8');
        if ($length >= 20 && $length <= 140) {
            $score += 3;
        }

        return $score;
    }

    /**
     * @return Collection<int, AiModel>
     */
    private function resolveAnalysisModels(): Collection
    {
        return AiModel::query()
            ->where('status', 'active')
            ->where(function ($query): void {
                $query->whereNull('model_type')
                    ->orWhere('model_type', '')
                    ->orWhere('model_type', 'chat');
            })
            ->where(function ($query): void {
                $query->whereNull('daily_limit')
                    ->orWhere('daily_limit', 0)
                    ->orWhereColumn('used_today', '<', 'daily_limit');
            })
            ->orderBy('failover_priority')
            ->orderBy('id')
            ->get();
    }

    /**
     * @return array{provider:string,model_id:string,base_url:string}
     */
    private function prepareAiRuntime(AiModel $model): array
    {
        $providerUrl = OpenAiRuntimeProvider::resolveChatBaseUrl((string) $model->api_url);
        if ($providerUrl === '') {
            throw new \RuntimeException(__('admin.url_import.error.ai_model_required'));
        }

        $apiKey = $this->apiKeyCrypto->decrypt((string) $model->api_key);
        if ($apiKey === '') {
            throw new \RuntimeException(__('admin.url_import.error.ai_model_required'));
        }

        $driver = OpenAiRuntimeProvider::resolveChatDriver($providerUrl, (string) $model->model_id);
        $provider = OpenAiRuntimeProvider::registerProvider('enterprise_knowledge', $driver, $providerUrl, $apiKey);

        Config::set('ai.default', $provider);

        return [
            'provider' => $provider,
            'model_id' => (string) $model->model_id,
            'base_url' => $providerUrl,
        ];
    }
}
