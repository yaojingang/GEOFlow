<?php

namespace App\Http\Controllers\Admin;

use App\Data\Ai\SystemAiIdentity;
use App\Http\Controllers\Controller;
use App\Models\AiVisibilityRun;
use App\Models\AiVisibilityTopic;
use App\Models\AiVisibilityTopicKeyword;
use App\Services\Admin\Analytics\AiVisibilityAnalyticsFilter;
use App\Services\Admin\Analytics\AiVisibilityAnalyticsService;
use App\Services\GeoFlow\AiVisibility\AiVisibilityConfigurationResolver;
use App\Services\GeoFlow\AiVisibility\AiVisibilityKeywordNormalizer;
use App\Services\GeoFlow\AiVisibility\AiVisibilityService;
use App\Services\GeoFlow\AiVisibility\AiVisibilitySourceData;
use App\Support\AdminWeb;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class AiVisibilityAnalyticsController extends Controller
{
    public function __construct(private readonly AiVisibilityAnalyticsService $analytics, private readonly AiVisibilityConfigurationResolver $configuration, private readonly AiVisibilityService $visibility) {}

    public function search(Request $request): RedirectResponse
    {
        $payload = $request->validate([
            'query' => ['required', 'string', 'min:1', 'max:100'], 'mode' => ['nullable', 'in:global,custom'],
            'count' => ['nullable', 'integer', 'min:1', 'max:50'], 'need_summary' => ['nullable', 'boolean'],
            'query_rewrite' => ['nullable', 'boolean'], 'time_range' => ['nullable', 'string', 'max:40'],
            'industry' => ['nullable', 'in:finance,game,health,gov'], 'auth_info_level' => ['nullable', 'in:0,1'],
            'summary_length' => ['nullable', 'integer', 'min:100', 'max:4000'], 'image_count' => ['nullable', 'integer', 'min:1', 'max:10'],
            'competitor_analysis' => ['nullable', 'boolean'],
            'sites' => ['nullable', 'string', 'max:1000'], 'block_hosts' => ['nullable', 'string', 'max:500'],
            'topic_id' => ['nullable', 'integer', 'exists:ai_visibility_topics,id'],
        ]);
        $provider = $this->configuration->searchProvider(SystemAiIdentity::forVisibilityCollection());
        if ($provider === null) {
            throw ValidationException::withMessages(['query' => '豆包搜索供应商尚未配置。']);
        }
        $options = array_replace($payload, [
            'sites' => preg_split('/[|,\r\n]+/', (string) ($payload['sites'] ?? ''), -1, PREG_SPLIT_NO_EMPTY),
            'block_hosts' => preg_split('/[|,\r\n]+/', (string) ($payload['block_hosts'] ?? ''), -1, PREG_SPLIT_NO_EMPTY),
        ]);
        $topic = $payload['topic_id'] ? AiVisibilityTopic::find((int) $payload['topic_id']) : null;
        $run = $this->visibility->runDoubaoSearchCustom(
            $provider,
            (string) $payload['query'],
            $options,
            $topic,
        );
        if ((bool) ($payload['competitor_analysis'] ?? false)) {
            $model = $this->configuration->deepSeekModel(SystemAiIdentity::forVisibilityCollection());
            if ($model !== null) {
                $run->load('sources');
                $sources = $run->sources->map(static fn ($source): AiVisibilitySourceData => new AiVisibilitySourceData(
                    sourceType: (string) $source->source_type, citationKey: $source->citation_key, title: $source->title,
                    url: $source->url, domain: $source->domain, siteName: $source->site_name, snippet: $source->snippet,
                    summary: $source->summary, contentExcerpt: $source->content_excerpt, publishedAt: $source->published_at,
                    rank: $source->rank, rankScore: $source->rank_score, authorityLevel: $source->authority_level,
                    metadata: is_array($source->metadata_json) ? $source->metadata_json : [],
                ))->values()->all();
                try {
                    $this->visibility->runDeepSeekAnalysis(SystemAiIdentity::forVisibilityCollection(), $model, (string) $payload['query'], '识别搜索结果中提到的全部竞品同行（完整列出，不要只挑一个），并按约定 JSON 输出证据；找不到信源 URL 的竞品也请列出。', $sources, topic: $topic);
                } catch (\Throwable) {
                    // Search results remain available even if optional analysis is unavailable.
                }
            }
        }

        return redirect()->route('admin.analytics.ai-visibility', ['ai_run' => $run->id])->with('message', '搜索完成');
    }

    public function assignTopic(Request $request): RedirectResponse
    {
        $payload = $request->validate([
            'run_id' => ['required', 'integer', 'exists:ai_visibility_runs,id'],
            'topic_id' => ['required', 'integer', 'exists:ai_visibility_topics,id'],
        ]);
        $run = AiVisibilityRun::query()->findOrFail((int) $payload['run_id']);
        $run->update(['ai_visibility_topic_id' => (int) $payload['topic_id']]);
        if ($run->keyword_hash !== null && $run->keyword_hash !== AiVisibilityKeywordNormalizer::hash('')) {
            AiVisibilityTopicKeyword::query()->updateOrCreate(
                ['keyword_hash' => $run->keyword_hash],
                ['ai_visibility_topic_id' => (int) $payload['topic_id'], 'keyword' => $run->keyword],
            );
        }

        return redirect()->route('admin.analytics.ai-visibility', ['ai_run' => $run->id])->with('message', '已归类');
    }

    public function __invoke(Request $request): View
    {
        $filter = AiVisibilityAnalyticsFilter::fromRequest($request->query());

        return view('admin.analytics.ai-visibility', [
            'pageTitle' => __('admin.analytics.pages.ai_visibility.title'),
            'activeMenu' => 'analytics',
            'analyticsPage' => 'ai-visibility',
            'adminSiteName' => AdminWeb::siteName(),
            'filters' => $filter,
            'filterOptions' => [
                'keywords' => Schema::hasTable('ai_visibility_runs')
                    ? AiVisibilityRun::query()->whereNotNull('keyword')->where('keyword', '!=', '')->distinct()->orderBy('keyword')->pluck('keyword')
                    : collect(),
                'providers' => [
                    AiVisibilityRun::PROVIDER_DOUBAO_ARK_RESPONSES,
                    AiVisibilityRun::PROVIDER_DOUBAO_SEARCH_CUSTOM,
                    AiVisibilityRun::PROVIDER_DEEPSEEK_ANALYSIS,
                ],
                'visibilityTopics' => Schema::hasTable('ai_visibility_topics')
                    ? AiVisibilityTopic::query()->orderBy('name')->get(['id', 'name'])
                    : collect(),
            ],
            'aiVisibilityOverview' => $this->analytics->overview($filter),
            'selectedRun' => $this->selectedRun($request),
        ]);
    }

    private function selectedRun(Request $request): ?AiVisibilityRun
    {
        $id = (int) $request->query('ai_run', 0);
        if ($id <= 0) {
            return null;
        }

        return AiVisibilityRun::query()->with('sources')->whereKey($id)->first();
    }
}
