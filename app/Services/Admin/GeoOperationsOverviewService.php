<?php

namespace App\Services\Admin;

use App\Models\AiModel;
use App\Models\Article;
use App\Models\Author;
use App\Models\GeoAiSearchRun;
use App\Models\GeoArticleDraft;
use App\Models\GeoCitationSource;
use App\Models\GeoPublishRecord;
use App\Models\Image;
use App\Models\ImageLibrary;
use App\Models\Keyword;
use App\Models\KeywordLibrary;
use App\Models\KnowledgeBase;
use App\Models\Prompt;
use App\Models\SensitiveWord;
use App\Models\SiteSetting;
use App\Models\Task;
use App\Models\TaskRun;
use App\Models\Title;
use App\Models\TitleLibrary;
use Illuminate\Support\Facades\Schema;

class GeoOperationsOverviewService
{
    /**
     * @return array<string, mixed>
     */
    public function forModule(string $module): array
    {
        $module = $this->normalizeModule($module);

        return [
            'module' => $module,
            'title' => 'GEO 运营就绪',
            'module_label' => $this->moduleLabel($module),
            'description' => $this->moduleDescription($module),
            'cards' => $this->cardsFor($module),
            'quick_links' => $this->quickLinksFor($module),
        ];
    }

    private function normalizeModule(string $module): string
    {
        return in_array($module, ['tasks', 'articles', 'materials', 'ai_config', 'site_settings'], true)
            ? $module
            : 'tasks';
    }

    private function moduleLabel(string $module): string
    {
        return match ($module) {
            'articles' => 'GEO 内容链路',
            'materials' => 'GEO 素材就绪度',
            'ai_config' => 'GEO AI 配置就绪',
            'site_settings' => 'GEO 站点发布设置',
            default => '内容生产任务',
        };
    }

    private function moduleDescription(string $module): string
    {
        return match ($module) {
            'articles' => '把 GEO 草稿、正式文章、审核和公众号分发状态串起来，方便从文章管理继续回看来源。',
            'materials' => '检查关键词、标题、图片、知识库和作者素材是否足够支撑下一轮 GEO 生产。',
            'ai_config' => '确认搜索问答、正文生成和向量检索模型是否完整，避免任务执行到一半才发现模型缺口。',
            'site_settings' => '检查前台展示、SEO、安全词和公众号分发基础配置，保证内容发布后能被正常承接。',
            default => '把普通内容任务和 GEO 搜索、采集、仿写、发布链路放到同一个执行视角里。',
        };
    }

    /**
     * @return list<array{label:string,value:string,detail:string,tone:string,icon:string,url:string}>
     */
    private function cardsFor(string $module): array
    {
        return match ($module) {
            'articles' => $this->articleCards(),
            'materials' => $this->materialCards(),
            'ai_config' => $this->aiCards(),
            'site_settings' => $this->siteCards(),
            default => $this->taskCards(),
        };
    }

    /**
     * @return list<array{label:string,value:string,detail:string,tone:string,icon:string,url:string}>
     */
    private function taskCards(): array
    {
        $activeTasks = $this->count(Task::class, static fn ($query) => $query->where('status', 'active'));
        $runningRuns = $this->count(TaskRun::class, static fn ($query) => $query->where('status', 'running'));
        $searchRuns = $this->count(GeoAiSearchRun::class);

        return [
            [
                'label' => '内容生产任务',
                'value' => $activeTasks.' 个启用',
                'detail' => '普通任务负责持续产文；GEO 工作台负责搜索诊断与选题来源。',
                'tone' => $activeTasks > 0 ? 'green' : 'amber',
                'icon' => 'list-checks',
                'url' => route('admin.tasks.create'),
            ],
            [
                'label' => '执行队列',
                'value' => $runningRuns.' 个运行中',
                'detail' => '用于观察后台任务是否正在生产、等待或失败。',
                'tone' => $runningRuns > 0 ? 'blue' : 'slate',
                'icon' => 'activity',
                'url' => route('admin.tasks.index'),
            ],
            [
                'label' => 'GEO 搜索批次',
                'value' => $searchRuns.' 次',
                'detail' => '从真实 AI 搜索结果进入引用采集和仿写。',
                'tone' => $searchRuns > 0 ? 'green' : 'amber',
                'icon' => 'search',
                'url' => route('admin.geo.workspace').'#ai-platforms',
            ],
        ];
    }

    /**
     * @return list<array{label:string,value:string,detail:string,tone:string,icon:string,url:string}>
     */
    private function articleCards(): array
    {
        $geoDrafts = $this->count(GeoArticleDraft::class);
        $convertedDrafts = $this->count(GeoArticleDraft::class, static fn ($query) => $query->where('status', 'converted'));
        $geoArticles = $this->count(Article::class, static fn ($query) => $query->where('is_ai_generated', 1)->whereNotNull('metadata'));
        $pendingReview = $this->count(Article::class, static fn ($query) => $query->where('review_status', 'pending'));
        $publishRecords = $this->count(GeoPublishRecord::class);

        return [
            [
                'label' => 'GEO 内容链路',
                'value' => $convertedDrafts.' / '.$geoDrafts,
                'detail' => '已转换草稿会进入文章管理继续审核、排版和发布。',
                'tone' => $convertedDrafts > 0 ? 'green' : 'amber',
                'icon' => 'file-check-2',
                'url' => route('admin.geo.workspace').'#content',
            ],
            [
                'label' => 'GEO 文章',
                'value' => $geoArticles.' 篇',
                'detail' => '列表中会标记来源问题，并提供回到 GEO 草稿的入口。',
                'tone' => $geoArticles > 0 ? 'green' : 'slate',
                'icon' => 'newspaper',
                'url' => route('admin.articles.index'),
            ],
            [
                'label' => '待审核与分发',
                'value' => $pendingReview.' / '.$publishRecords,
                'detail' => '前者是待审核文章，后者是 GEO 发布记录。',
                'tone' => $pendingReview > 0 ? 'amber' : 'green',
                'icon' => 'send',
                'url' => route('admin.articles.index', ['review_status' => 'pending']),
            ],
        ];
    }

    /**
     * @return list<array{label:string,value:string,detail:string,tone:string,icon:string,url:string}>
     */
    private function materialCards(): array
    {
        $keywords = $this->count(Keyword::class);
        $keywordLibraries = $this->count(KeywordLibrary::class);
        $titles = $this->count(Title::class);
        $titleLibraries = $this->count(TitleLibrary::class);
        $images = $this->count(Image::class);
        $imageLibraries = $this->count(ImageLibrary::class);
        $knowledgeBases = $this->count(KnowledgeBase::class);
        $authors = $this->count(Author::class);

        return [
            [
                'label' => 'GEO 素材就绪度',
                'value' => $keywords.' 词 / '.$titles.' 题',
                'detail' => $keywordLibraries.' 个关键词库，'.$titleLibraries.' 个标题库，可继续支撑选题扩展。',
                'tone' => ($keywords > 0 && $titles > 0) ? 'green' : 'amber',
                'icon' => 'database',
                'url' => route('admin.keyword-libraries.index'),
            ],
            [
                'label' => '图片与知识库',
                'value' => $images.' 图 / '.$knowledgeBases.' 库',
                'detail' => $imageLibraries.' 个图库；知识库用于文章事实和品牌资料补强。',
                'tone' => ($images > 0 || $knowledgeBases > 0) ? 'green' : 'amber',
                'icon' => 'image',
                'url' => route('admin.knowledge-bases.index'),
            ],
            [
                'label' => '作者与栏目',
                'value' => $authors.' 位作者',
                'detail' => '文章转正式内容时需要作者与栏目承接。',
                'tone' => $authors > 0 ? 'green' : 'amber',
                'icon' => 'users',
                'url' => route('admin.categories.index'),
            ],
        ];
    }

    /**
     * @return list<array{label:string,value:string,detail:string,tone:string,icon:string,url:string}>
     */
    private function aiCards(): array
    {
        $chatModels = $this->count(AiModel::class, static function ($query): void {
            $query->where('status', 'active')
                ->where(static function ($subQuery): void {
                    $subQuery->whereNull('model_type')
                        ->orWhere('model_type', '')
                        ->orWhere('model_type', 'chat');
                });
        });
        $embeddingModels = $this->count(AiModel::class, static fn ($query) => $query->where('status', 'active')->where('model_type', 'embedding'));
        $prompts = $this->count(Prompt::class);
        $defaultEmbeddingId = (int) (SiteSetting::query()
            ->where('setting_key', 'default_embedding_model_id')
            ->value('setting_value') ?? 0);

        return [
            [
                'label' => 'GEO AI 配置就绪',
                'value' => $chatModels.' 个问答模型',
                'detail' => '搜索、诊断和正文生成会优先使用启用的聊天模型。',
                'tone' => $chatModels > 0 ? 'green' : 'red',
                'icon' => 'bot',
                'url' => route('admin.ai-models.index'),
            ],
            [
                'label' => 'Embedding 模型',
                'value' => $embeddingModels.' 个可用',
                'detail' => $defaultEmbeddingId > 0 ? '已指定默认向量模型。' : '未指定默认模型时将自动选择可用 embedding。',
                'tone' => $embeddingModels > 0 ? 'green' : 'amber',
                'icon' => 'braces',
                'url' => route('admin.ai-models.index'),
            ],
            [
                'label' => '提示词资产',
                'value' => $prompts.' 条',
                'detail' => '正文、标题、特殊提示词共同影响 GEO 输出质量。',
                'tone' => $prompts > 0 ? 'green' : 'amber',
                'icon' => 'message-square',
                'url' => route('admin.ai-prompts'),
            ],
        ];
    }

    /**
     * @return list<array{label:string,value:string,detail:string,tone:string,icon:string,url:string}>
     */
    private function siteCards(): array
    {
        $siteName = (string) (SiteSetting::query()
            ->where('setting_key', 'site_name')
            ->value('setting_value') ?: 'GEOAmplify');
        $seoTitleTemplate = trim((string) (SiteSetting::query()
            ->where('setting_key', 'seo_title_template')
            ->value('setting_value') ?? ''));
        $sensitiveWords = $this->count(SensitiveWord::class);
        $citationSources = $this->count(GeoCitationSource::class);
        $yixiaoerConfigured = trim((string) config('services.yixiaoer.api_key', '')) !== '';

        return [
            [
                'label' => 'GEO 站点发布设置',
                'value' => $siteName,
                'detail' => $seoTitleTemplate !== '' ? 'SEO 模板已配置，可承接文章页标题。' : '建议补齐 SEO 标题模板。',
                'tone' => $seoTitleTemplate !== '' ? 'green' : 'amber',
                'icon' => 'globe-2',
                'url' => route('admin.site-settings.index'),
            ],
            [
                'label' => '公众号分发',
                'value' => $yixiaoerConfigured ? '已配置' : '待配置',
                'detail' => 'GEO 草稿导出后会通过蚁小二提交公众号草稿，未登录时会提示用户先登录。',
                'tone' => $yixiaoerConfigured ? 'green' : 'amber',
                'icon' => 'rss',
                'url' => route('admin.geo.workspace').'#publish',
            ],
            [
                'label' => '安全与引用源',
                'value' => $sensitiveWords.' 词 / '.$citationSources.' 源',
                'detail' => '敏感词负责发布前约束，引用源负责复盘为什么会被 AI 引用。',
                'tone' => $citationSources > 0 ? 'green' : 'slate',
                'icon' => 'shield-check',
                'url' => route('admin.site-settings.sensitive-words'),
            ],
        ];
    }

    /**
     * @return list<array{label:string,url:string,icon:string,primary?:bool}>
     */
    private function quickLinksFor(string $module): array
    {
        $links = [
            [
                'label' => '进入 GEO 工作台',
                'url' => route('admin.geo.workspace'),
                'icon' => 'radar',
                'primary' => true,
            ],
            [
                'label' => 'AI 搜索平台',
                'url' => route('admin.geo.workspace').'#ai-platforms',
                'icon' => 'search',
            ],
            [
                'label' => '引用源采集',
                'url' => route('admin.geo.citation-sources.index'),
                'icon' => 'link',
            ],
        ];

        $links[] = match ($module) {
            'articles' => [
                'label' => '待审文章',
                'url' => route('admin.articles.index', ['review_status' => 'pending']),
                'icon' => 'eye',
            ],
            'materials' => [
                'label' => 'URL 采集入库',
                'url' => route('admin.url-import'),
                'icon' => 'download',
            ],
            'ai_config' => [
                'label' => '模型连通测试',
                'url' => route('admin.ai-models.index'),
                'icon' => 'plug-zap',
            ],
            'site_settings' => [
                'label' => '敏感词设置',
                'url' => route('admin.site-settings.sensitive-words'),
                'icon' => 'shield-alert',
            ],
            default => [
                'label' => '新建生产任务',
                'url' => route('admin.tasks.create'),
                'icon' => 'plus',
            ],
        };

        return $links;
    }

    /**
     * @template TModel of \Illuminate\Database\Eloquent\Model
     *
     * @param  class-string<TModel>  $modelClass
     */
    private function count(string $modelClass, ?callable $callback = null): int
    {
        $model = new $modelClass;
        if (! Schema::hasTable($model->getTable())) {
            return 0;
        }

        $query = $modelClass::query();
        if ($callback !== null) {
            $callback($query);
        }

        return (int) $query->count();
    }
}
