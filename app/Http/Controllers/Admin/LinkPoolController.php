<?php

namespace App\Http\Controllers\Admin;

use App\Exceptions\DistributionChannelDeletionBlocked;
use App\Http\Controllers\Controller;
use App\Models\Category;
use App\Models\DistributionChannel;
use App\Models\HostedSiteProfile;
use App\Models\LinkPoolThemeAdaptation;
use App\Models\SiteSetting;
use App\Models\SiteThemeBinding;
use App\Services\Api\ThemeReleaseService;
use App\Services\GeoFlow\DistributionChannelOperationLeaseService;
use App\Services\LinkPool\LinkCampaignService;
use App\Services\LinkPool\LinkPoolImportService;
use App\Services\LinkPool\LinkPoolTargetService;
use App\Services\LinkPool\LinkPoolUrlHealthService;
use App\Services\Site\LinkPoolRecommendationService;
use App\Services\Site\LinkPoolSiteService;
use App\Support\AdminWeb;
use App\Support\Analytics\TrafficClassifier;
use App\Support\Site\SiteThemeCatalog;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class LinkPoolController extends Controller
{
    public function __construct(private readonly LinkPoolSiteService $sites) {}

    public function overview(Request $request): View
    {
        $siteKey = $this->selectedSite($request);
        $campaigns = DB::table('link_campaigns')->when($siteKey, fn ($query) => $query->where('source_site_key', $siteKey));
        $edges = $this->edges($siteKey);
        $targetCount = $this->eligibleTargets()->when($siteKey, fn ($query) => $query->where(fn ($q) => $q->where('targets.target_site_key', $siteKey)->orWhere('targets.target_type', 'external')))->count();
        $edgeCount = (clone $edges)->count();
        $linked = DB::table('link_pool_targets as targets')
            ->joinSub($edges->select('targets.id as target_id')->groupBy('targets.id'), 'inbound', function ($join): void {
                $join->on('inbound.target_id', '=', 'targets.id');
            })
            ->where('targets.status', 'active')
            ->where(fn ($query) => $query->whereNull('targets.expires_at')->orWhere('targets.expires_at', '>', now()))
            ->when($siteKey, fn ($query) => $query->where(fn ($q) => $q->where('targets.target_site_key', $siteKey)->orWhere('targets.target_type', 'external')))
            ->count();
        $sourceCount = DB::query()->fromSub((clone $edges)->select('placements.source_site_key', 'placements.source_article_id')
            ->groupBy('placements.source_site_key', 'placements.source_article_id'), 'current_sources')->count();
        $data = [
            'target_count' => $targetCount, 'active_campaigns' => (clone $campaigns)->where('status', 'active')->count(),
            'draft_campaigns' => DB::table('link_campaign_drafts as drafts')->join('link_campaigns as campaigns', 'campaigns.id', '=', 'drafts.campaign_id')
                ->when($siteKey, fn ($query) => $query->where('campaigns.source_site_key', $siteKey))->count(),
            'edge_count' => $edgeCount, 'source_count' => $sourceCount,
            'zero_inbound' => max(0, $targetCount - $linked),
        ];

        return $this->view('admin.link-pool.overview', ['siteKey' => $siteKey, 'summary' => $data]);
    }

    public function targets(Request $request): View
    {
        $siteKey = $this->selectedSite($request);
        $targetSiteKey = trim((string) $request->query('target_site', ''));
        if ($targetSiteKey !== '') {
            $this->assertTargetSite($targetSiteKey);
        }
        $status = in_array($request->query('status'), ['active', 'paused'], true)
            ? (string) $request->query('status') : '';
        $search = mb_substr(trim((string) $request->query('q', '')), 0, 120);
        $targets = DB::table('link_pool_targets as targets')
            ->when($targetSiteKey, fn ($query) => $query->where('targets.target_site_key', $targetSiteKey))
            ->when($status, fn ($query) => $query->where('targets.status', $status))
            ->when($search, fn ($query) => $query->where(fn ($match) => $match
                ->where('targets.display_title', 'like', '%'.$search.'%')
                ->orWhere('targets.input_url', 'like', '%'.$search.'%')))
            ->orderByDesc('targets.updated_at')->paginate(30)->withQueryString();
        $pageRows = $targets->getCollection();
        $inbound = $pageRows->isEmpty() ? collect() : $this->edges()
            ->whereIn('targets.id', $pageRows->pluck('id')->all())
            ->selectRaw('targets.id as target_id, COUNT(*) as edge_count')->groupBy('targets.id')->get()->keyBy('target_id');
        $clicks = $pageRows->isEmpty() ? collect() : DB::table('link_metrics_daily as metrics')
            ->join('link_pool_targets as targets', function ($join): void {
                $join->on('metrics.target_id', '=', 'targets.id')->orOn(function ($legacy): void {
                    $legacy->whereNull('metrics.target_id')->on('metrics.target_site_key', '=', 'targets.target_site_key')
                        ->on('metrics.target_article_id', '=', 'targets.article_id');
                });
            })->where('metrics.date', '>=', now()->subDays(6)->toDateString())
            ->when($siteKey, fn ($query) => $query->where('metrics.source_site_key', $siteKey))
            ->whereIn('targets.id', $pageRows->pluck('id')->all())
            ->selectRaw('targets.id as target_id, SUM(metrics.human_clicks) as clicks')
            ->groupBy('targets.id')->get()->keyBy('target_id');
        $campaignCounts = $pageRows->isEmpty() ? collect() : DB::table('link_campaign_targets as relations')
            ->join('link_campaigns as campaigns', 'campaigns.id', '=', 'relations.campaign_id')
            ->where('campaigns.status', 'active')
            ->whereIn('relations.target_id', $pageRows->pluck('id')->all())
            ->when($siteKey, fn ($query) => $query->where('campaigns.source_site_key', $siteKey))
            ->selectRaw('relations.target_id, COUNT(*) as campaign_count')
            ->groupBy('relations.target_id')->get()->keyBy('target_id');

        return $this->view('admin.link-pool.targets', compact(
            'siteKey', 'targetSiteKey', 'targets', 'inbound', 'clicks', 'campaignCounts', 'status', 'search',
        ));
    }

    public function importForm(Request $request, LinkPoolImportService $imports): View
    {
        $input = (string) $request->old('links', '');
        $rows = null;
        if ($input !== '') {
            try {
                $rows = $imports->preflight($input, $this->normalizeImportDelimiter($request->old('delimiter')));
            } catch (ValidationException) {
                // The existing validation message remains visible alongside the editable input.
            }
        }

        return $this->view('admin.link-pool.import', ['rows' => $rows, 'input' => $input, 'counts' => null,
            'siteKey' => $this->selectedSite($request)]);
    }

    public function preflight(Request $request, LinkPoolImportService $imports): View|RedirectResponse
    {
        $siteKey = $this->selectedSite($request);
        try {
            $input = $this->importInput($request);
            $rows = $imports->preflight($input, $this->importDelimiter($request));
        } catch (ValidationException $exception) {
            return $this->importValidationResponse($request, $exception, $siteKey, $input ?? '');
        }

        return $this->view('admin.link-pool.import', compact('siteKey', 'rows', 'input') + ['counts' => null]);
    }

    public function import(Request $request, LinkPoolImportService $imports): View|RedirectResponse
    {
        $siteKey = $this->selectedSite($request);
        try {
            $input = $this->importInput($request);
            $rows = $imports->preflight($input, $this->importDelimiter($request));
            $counts = $imports->import($rows, (int) $request->user('admin')->id, $request->boolean('ack_titles'),
                array_map('intval', (array) $request->input('use_article_title_lines', [])));
        } catch (ValidationException $exception) {
            return $this->importValidationResponse($request, $exception, $siteKey, $input ?? '');
        }
        $request->attributes->set('admin_activity_details', ['link_pool_import' => $counts]);

        return $this->view('admin.link-pool.import', compact('siteKey', 'rows', 'input', 'counts'));
    }

    public function undoImport(Request $request, string $batchId, LinkPoolImportService $imports): RedirectResponse
    {
        $result = $imports->undo($batchId, (int) $request->user('admin')->id);
        $request->attributes->set('admin_activity_details', ['link_pool_import_batch' => $batchId, 'undo' => $result]);

        return redirect()->route('admin.link-pool.targets', ['site' => $this->selectedSite($request)])
            ->with('link_pool_message', "本批新建目标已撤销 {$result['removed']} 条；{$result['retained']} 条已有投放引用，已保留。");
    }

    public function importErrors(Request $request, LinkPoolImportService $imports): StreamedResponse
    {
        $rows = $imports->preflight($this->importInput($request), $this->importDelimiter($request));

        return response()->streamDownload(function () use ($rows): void {
            $output = fopen('php://output', 'w');
            fputcsv($output, ['line', 'url', 'title', 'reason']);
            foreach ($rows as $row) {
                if ($row['status'] !== 'error') {
                    continue;
                }
                fputcsv($output, array_map(static function ($value): string {
                    $text = (string) $value;

                    return preg_match('/^[=+\-@]/u', $text) ? "'".$text : $text;
                }, [$row['line'], $row['url'], $row['title'], $row['reason']]));
            }
            fclose($output);
        }, 'link-pool-import-errors.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    public function updateTarget(Request $request, int $targetId, DistributionChannelOperationLeaseService $leases): RedirectResponse
    {
        $data = $request->validate([
            'display_title' => ['required', 'string', 'max:120'], 'topic' => ['nullable', 'string', 'max:120'],
            'priority' => ['required', 'integer', 'between:1,10'], 'status' => ['required', 'in:active,paused'],
        ]);
        $target = DB::table('link_pool_targets')->find($targetId);
        abort_unless($target, 404);
        $external = LinkPoolTargetService::isExternal($target);
        if (! $external && $data['status'] === 'active' && ($this->sites->article((string) $target->target_site_key, (int) $target->article_id) === null
            || ! $this->sites->canPublish((string) $target->target_site_key))) {
            throw ValidationException::withMessages(['status' => '文章或目标站点当前不能公开，无法启用。']);
        }
        $save = static function () use ($targetId, $data): void {
            $current = DB::table('link_pool_targets')->where('id', $targetId)->lockForUpdate()->first();
            abort_unless($current, 404);
            $values = [
                'display_title' => trim($data['display_title']), 'topic' => trim((string) ($data['topic'] ?? '')),
                'priority' => $data['priority'], 'status' => $data['status'], 'updated_at' => now(),
            ];
            if (LinkPoolTargetService::operationalValuesChanged($current, $values)) {
                DB::table('link_pool_targets')->where('id', $targetId)->update($values);
                app(LinkPoolTargetService::class)->advanceLibraryRevision();
            }
        };
        if ($external || $target->target_site_key === 'primary') {
            DB::transaction($save, 3);
        } else {
            $profile = $this->sites->profile((string) $target->target_site_key);
            abort_unless($profile?->channel, 404);
            try {
                $leases->run($profile->channel, 'link_pool_target_update', fn () => DB::transaction($save, 3));
            } catch (DistributionChannelDeletionBlocked) {
                throw ValidationException::withMessages(['status' => '渠道正在删除，目标暂时无法修改。']);
            }
        }

        return back()->with('link_pool_message', '目标已更新；下一次文章请求会重新核验状态。');
    }

    public function checkTarget(int $targetId, LinkPoolUrlHealthService $health): RedirectResponse
    {
        $target = DB::table('link_pool_targets')->find($targetId);
        abort_unless($target && LinkPoolTargetService::isExternal($target), 404);
        $health->enqueue($targetId);

        $message = DB::table('link_pool_targets')->where('id', $targetId)->value('health_status') === 'unchecked'
            ? '访问检查暂时无法完成，请稍后重新检查。链接仍可用于投放。'
            : '已安排访问检查，稍后刷新查看结果。检查结果供参考，链接启用状态由管理员决定。';

        return back()->with('link_pool_message', $message);
    }

    public function campaigns(Request $request): View
    {
        $siteKey = $this->selectedSite($request);
        $status = in_array($request->query('status'), ['pending', 'active', 'paused', 'draft'], true)
            ? (string) $request->query('status') : '';
        $search = mb_substr(trim((string) $request->query('q', '')), 0, 120);
        $campaigns = DB::table('link_campaigns as campaigns')->leftJoin('link_campaign_drafts as drafts', 'drafts.campaign_id', '=', 'campaigns.id')
            ->when($siteKey, fn ($query) => $query->where('campaigns.source_site_key', $siteKey))
            ->when($status === 'pending', fn ($query) => $query->whereNotNull('drafts.updated_at'))
            ->when($status !== '' && $status !== 'pending', fn ($query) => $query->where('campaigns.status', $status))
            ->when($search, fn ($query) => $query->where('campaigns.name', 'like', '%'.$search.'%'))
            ->select('campaigns.*', 'drafts.updated_at as draft_updated_at')
            ->orderByRaw('CASE WHEN drafts.updated_at IS NULL THEN 1 ELSE 0 END')
            ->orderByDesc('campaigns.updated_at')->paginate(30)->withQueryString();

        return $this->view('admin.link-pool.campaigns', compact('siteKey', 'campaigns', 'status', 'search'));
    }

    public function createCampaign(Request $request): View
    {
        $siteKey = $this->selectedSite($request);
        if ($siteKey === null) {
            return $this->campaigns($request);
        }

        return $this->campaignEditor(null, $siteKey);
    }

    public function editCampaign(int $campaignId): View
    {
        $campaign = DB::table('link_campaigns')->find($campaignId);
        abort_unless($campaign, 404);

        return $this->campaignEditor($campaign, (string) $campaign->source_site_key);
    }

    public function saveCampaignDraft(Request $request, LinkCampaignService $campaigns): RedirectResponse
    {
        $siteKey = trim((string) $request->input('source_site_key', ''));
        $this->assertSite($siteKey);
        $campaignId = $request->filled('campaign_id') ? (int) $request->input('campaign_id') : null;
        $id = $campaigns->saveDraft($campaignId, $request->all(), (int) $request->user('admin')->id);
        $request->attributes->set('admin_activity_details', ['link_pool_campaign_id' => $id, 'action' => 'draft']);

        return redirect()->route('admin.link-pool.campaigns.edit', $id)->with('link_pool_message', '草稿已保存，当前公开版本保持运行。');
    }

    public function publishCampaign(Request $request, int $campaignId, LinkCampaignService $campaigns): RedirectResponse
    {
        $campaigns->publish($campaignId, (int) $request->user('admin')->id);
        $request->attributes->set('admin_activity_details', ['link_pool_campaign_id' => $campaignId, 'action' => 'publish']);

        return redirect()->route('admin.link-pool.campaigns.edit', $campaignId)->with('link_pool_message', '活动已发布，文章请求将刷新推荐批次。');
    }

    public function pauseCampaign(Request $request, int $campaignId, LinkCampaignService $campaigns): RedirectResponse
    {
        $campaigns->pause($campaignId);
        $request->attributes->set('admin_activity_details', ['link_pool_campaign_id' => $campaignId, 'action' => 'pause']);

        return back()->with('link_pool_message', '活动已暂停，新的文章请求不会输出旧批次。');
    }

    public function copyCampaign(Request $request, int $campaignId, LinkCampaignService $campaigns): RedirectResponse
    {
        $data = $request->validate(['site_key' => ['required', 'string', 'max:64']]);
        $this->assertSite($data['site_key']);
        $newId = $campaigns->copyToSite($campaignId, $data['site_key'], (int) $request->user('admin')->id);
        $request->attributes->set('admin_activity_details', ['link_pool_campaign_id' => $campaignId, 'copy_id' => $newId]);

        return redirect()->route('admin.link-pool.campaigns.edit', $newId)
            ->with('link_pool_message', '已复制为待发布草稿。请重新核验来源范围与跨站目标，并在目标站点预览。');
    }

    public function preview(Request $request, LinkPoolRecommendationService $recommendations): View
    {
        $siteKey = $this->selectedSite($request);
        $articleId = (int) $request->query('article_id', 0);
        $campaignId = (int) $request->query('campaign_id', 0);
        $campaignChoices = $this->campaignChoices($siteKey);
        $campaign = $campaignChoices->firstWhere('id', $campaignId);
        if ($campaignId && ! $campaign) {
            $campaignId = 0;
        }
        $version = $request->query('version') === 'draft' ? 'draft' : 'current';
        $draftAvailable = $campaign && DB::table('link_campaign_drafts')->where('campaign_id', $campaignId)->exists();
        $articleSearch = mb_substr(trim((string) $request->query('article_q', '')), 0, 120);
        if ($siteKey && ! $request->filled('article_id')) {
            $articleId = (int) ($recommendations->sampleSource($siteKey, $campaignId, $version === 'draft', $articleSearch)?->id ?? 0);
        }
        $article = $siteKey && $articleId ? $this->sites->article($siteKey, $articleId) : null;
        $articleChoices = $siteKey ? $this->sites->query($siteKey)
            ->when($articleSearch !== '', fn ($query) => $query->where('articles.title', 'like', '%'.$articleSearch.'%'))
            ->orderByDesc('published_at')->orderByDesc('articles.id')->limit(200)->get(['articles.id', 'articles.title']) : collect();
        if ($article && ! $articleChoices->contains('id', $article->id)) {
            $articleChoices->prepend($article);
        }
        $related = $article ? $this->sites->query($siteKey)->where('category_id', $article->category_id)
            ->whereKeyNot($article->id)->orderByDesc('published_at')->orderByDesc('articles.id')->limit(6)->get(['articles.id', 'articles.title']) : collect();
        $relatedIds = $related->pluck('id')->map(fn ($id) => (int) $id)->all();
        $block = ! $article || ($version === 'draft' && ! $draftAvailable) ? null : ($version === 'draft' && $campaignId
            ? $recommendations->previewDraft($siteKey, $article, $campaignId, $relatedIds)
            : $recommendations->preview($siteKey, $article, $relatedIds));
        if (($block['preview_status'] ?? null) === 'missing_draft') {
            $draftAvailable = false;
            $block = null;
        }
        $campaignIds = collect($block['items'] ?? [])->pluck('campaign_id')->filter()->unique()->all();
        $campaignNames = DB::table('link_campaigns')->whereIn('id', $campaignIds)->pluck('name', 'id');

        return $this->view('admin.link-pool.preview', compact(
            'siteKey', 'articleId', 'article', 'related', 'block', 'campaignId', 'campaignNames', 'campaignChoices', 'campaign', 'draftAvailable', 'version', 'articleChoices', 'articleSearch',
        ));
    }

    public function dashboard(Request $request): View
    {
        $siteKey = $this->selectedSite($request);
        $targetSiteKey = trim((string) $request->query('target_site', ''));
        if ($targetSiteKey !== '') {
            $this->assertTargetSite($targetSiteKey);
        }
        $campaignId = max(0, (int) $request->query('campaign_id', 0));
        $campaignChoices = $this->campaignChoices($siteKey);
        $days = in_array((int) $request->query('days', 7), [7, 30, 90], true) ? (int) $request->query('days', 7) : 7;
        $since = now()->subDays($days - 1)->toDateString();
        $metrics = DB::table('link_metrics_daily')->where('date', '>=', $since)
            ->when($siteKey, fn ($query) => $query->where('source_site_key', $siteKey))
            ->when($targetSiteKey, fn ($query) => $query->where('target_site_key', $targetSiteKey))
            ->when($campaignId, fn ($query) => $query->where('campaign_id', $campaignId));
        $totals = (clone $metrics)->selectRaw('SUM(html_served) as html_served, SUM(viewable) as viewable, SUM(human_clicks) as human_clicks')->first();
        $trend = (clone $metrics)->selectRaw('date, SUM(html_served) as html_served, SUM(viewable) as viewable, SUM(human_clicks) as human_clicks')
            ->groupBy('date')->orderBy('date')->get();
        $trendByDate = $trend->keyBy('date');
        $chartDays = collect(range(0, $days - 1))->map(static function (int $index) use ($since, $trendByDate): array {
            $date = Carbon::parse($since)->addDays($index)->toDateString();
            $row = $trendByDate->get($date);

            return [
                'date' => $date,
                'html_served' => (int) ($row->html_served ?? 0),
                'viewable' => (int) ($row->viewable ?? 0),
                'human_clicks' => (int) ($row->human_clicks ?? 0),
            ];
        });
        $chartMax = max(1, (int) $chartDays->flatMap(static fn (array $row): array => [
            $row['html_served'], $row['viewable'], $row['human_clicks'],
        ])->max());
        $chartSeries = [];
        foreach (['html_served', 'viewable', 'human_clicks'] as $metric) {
            $chartSeries[$metric] = $chartDays->map(static fn (array $row, int $index): string => sprintf(
                '%.1f,%.1f', 48 + $index * 640 / max(1, $days - 1), 160 - $row[$metric] * 120 / $chartMax,
            ))->implode(' ');
        }
        $perCampaign = (clone $metrics)->selectRaw('campaign_id, SUM(html_served) as html_served, SUM(viewable) as viewable, SUM(human_clicks) as human_clicks')
            ->groupBy('campaign_id')->orderByDesc('human_clicks')->limit(20)->get();
        $botRows = DB::table('view_logs')->where('created_at', '>=', $since.' 00:00:00')
            ->whereIn('route_name', ['site.article', 'site.article.resolve'])
            ->when($siteKey === 'primary', fn ($query) => $query->whereNull('hosted_site_profile_id'))
            ->when($siteKey && $siteKey !== 'primary', fn ($query) => $query->where('hosted_site_profile_id', (int) substr($siteKey, 7)))
            ->selectRaw('user_agent, status_code, COUNT(*) as visits')->groupBy('user_agent', 'status_code')->get();
        $bots = ['search_bot' => 0, 'ai_bot' => 0];
        $botStatus = ['search_bot' => [], 'ai_bot' => []];
        foreach ($botRows as $row) {
            $type = TrafficClassifier::classify($row->user_agent);
            if (isset($bots[$type])) {
                $bots[$type] += (int) $row->visits;
                $botStatus[$type][(int) $row->status_code] = ($botStatus[$type][(int) $row->status_code] ?? 0) + (int) $row->visits;
            }
        }
        $currentEdges = $this->edges($siteKey)
            ->when($targetSiteKey, fn ($query) => $query->where('placements.target_site_key', $targetSiteKey))
            ->when($campaignId, fn ($query) => $query->where('placements.campaign_id', $campaignId));
        $edgeCount = (clone $currentEdges)->count();
        $inbound = (clone $currentEdges)
            ->selectRaw('targets.id as target_id, COUNT(*) as edge_count')
            ->groupBy('targets.id')->get()->keyBy('target_id');
        $targetRows = $this->eligibleTargets($targetSiteKey ?: null)
            ->get(['targets.id', 'targets.target_site_key', 'targets.article_id']);
        $targetBots = null;
        if ($targetSiteKey !== '' && $targetSiteKey !== 'external') {
            $targetBotRows = DB::table('view_logs')->where('created_at', '>=', $since.' 00:00:00')
                ->whereIn('article_id', $targetRows->pluck('article_id')->all())
                ->when($targetSiteKey === 'primary', fn ($query) => $query->whereNull('hosted_site_profile_id'))
                ->when($targetSiteKey !== 'primary', fn ($query) => $query->where('hosted_site_profile_id', (int) substr($targetSiteKey, 7)))
                ->selectRaw('user_agent, status_code, COUNT(*) as visits')->groupBy('user_agent', 'status_code')->get();
            $targetBots = ['search_bot' => 0, 'ai_bot' => 0];
            foreach ($targetBotRows as $row) {
                $type = TrafficClassifier::classify($row->user_agent);
                if (isset($targetBots[$type])) {
                    $targetBots[$type] += (int) $row->visits;
                }
            }
        }
        $zeroInbound = $targetRows->filter(fn ($target): bool => ! isset($inbound[$target->id]))->count();
        $coveredSources = DB::query()->fromSub((clone $currentEdges)
            ->select('placements.source_site_key', 'placements.source_article_id')
            ->groupBy('placements.source_site_key', 'placements.source_article_id'), 'covered_sources')->count();
        $eligibleSources = $siteKey ? $this->sites->query($siteKey)->count() : null;
        $histogram = ['0' => $zeroInbound, '1' => 0, '2–5' => 0, '6+' => 0];
        foreach ($inbound as $edge) {
            $count = (int) $edge->edge_count;
            $histogram[$count === 1 ? '1' : ($count <= 5 ? '2–5' : '6+')]++;
        }

        return $this->view('admin.link-pool.dashboard', compact(
            'siteKey', 'targetSiteKey', 'campaignId', 'days', 'totals', 'trend', 'perCampaign', 'bots', 'botStatus', 'targetBots',
            'edgeCount', 'targetRows', 'zeroInbound', 'coveredSources', 'eligibleSources', 'histogram',
            'chartDays', 'chartMax', 'chartSeries', 'campaignChoices',
        ));
    }

    public function dashboardCsv(Request $request): StreamedResponse
    {
        $siteKey = $this->selectedSite($request);
        $targetSiteKey = trim((string) $request->query('target_site', ''));
        if ($targetSiteKey !== '') {
            $this->assertTargetSite($targetSiteKey);
        }
        $campaignId = max(0, (int) $request->query('campaign_id', 0));
        $days = in_array((int) $request->query('days', 7), [7, 30, 90], true) ? (int) $request->query('days', 7) : 7;
        $rows = DB::table('link_metrics_daily')->where('date', '>=', now()->subDays($days - 1)->toDateString())
            ->when($siteKey, fn ($query) => $query->where('source_site_key', $siteKey))
            ->when($targetSiteKey, fn ($query) => $query->where('target_site_key', $targetSiteKey))
            ->when($campaignId, fn ($query) => $query->where('campaign_id', $campaignId))
            ->selectRaw('date, SUM(html_served) as html_served, SUM(viewable) as viewable, SUM(human_clicks) as human_clicks')
            ->groupBy('date')->orderBy('date')->get();

        return response()->streamDownload(function () use ($rows): void {
            $stream = fopen('php://output', 'w');
            fputcsv($stream, ['date', 'html_served', 'viewable', 'human_clicks']);
            foreach ($rows as $row) {
                fputcsv($stream, [$row->date, $row->html_served, $row->viewable, $row->human_clicks]);
            }
            fclose($stream);
        }, 'link-pool-daily.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    public function settings(Request $request): View
    {
        $siteKey = $this->selectedSite($request);
        $settings = $siteKey ? $this->sites->settings($siteKey) : null;
        $adaptation = $siteKey ? LinkPoolThemeAdaptation::query()->where('site_key', $siteKey)->latest('created_at')->first() : null;
        $profile = $siteKey && $siteKey !== 'primary' ? $this->sites->profile($siteKey) : null;
        $siteName = $siteKey === 'primary' ? '主站' : ($profile?->channel?->name ?? $profile?->hostname);
        $siteDomain = $siteKey === 'primary' ? parse_url((string) config('geoflow.site_url', config('app.url')), PHP_URL_HOST) : $profile?->hostname;
        $themeId = $siteKey === 'primary'
            ? (string) SiteSetting::query()->where('setting_key', 'active_theme')->value('setting_value')
            : (string) ($profile?->channel?->site_settings['theme_id'] ?? $profile?->channel?->template_key ?? '');
        $themeReady = $siteKey ? $this->sites->themeSupportsRecommendations($siteKey) : false;
        $themeId = trim($themeId) ?: (string) config('geoflow.default_theme');
        $themeName = collect(app(SiteThemeCatalog::class)->all())->firstWhere('id', $themeId)['name'] ?? $themeId;
        if ($adaptation && ($adaptation->theme_id !== $themeId
            || ($adaptation->status === 'completed' && SiteThemeBinding::query()->whereKey($siteKey)->value('revision_id') !== $adaptation->candidate_revision_id))) {
            $adaptation = null;
        }
        $adaptationAvailable = ThemeReleaseService::linkPoolAdaptationAvailability();
        $campaign = $siteKey ? DB::table('link_campaigns')->where('source_site_key', $siteKey)
            ->where('id', (int) $request->query('campaign_id'))->first(['id', 'name']) : null;

        return $this->view('admin.link-pool.settings', compact('siteKey', 'settings', 'adaptation', 'siteName', 'siteDomain', 'themeId', 'themeName', 'themeReady', 'adaptationAvailable', 'campaign'));
    }

    public function updateSettings(Request $request, DistributionChannelOperationLeaseService $leases): RedirectResponse|JsonResponse
    {
        $data = $request->validate([
            'site_key' => ['required', 'string', 'max:64'], 'heading' => ['required', 'string', 'max:60'],
            'default_limit' => ['required', 'integer', 'between:1,6'],
            'rotation_hours' => ['required', 'integer', 'between:1,168'], 'enabled' => ['nullable', 'in:0,1'],
            'revision' => ['required', 'integer', 'min:0'],
        ]);
        $siteKey = $data['site_key'];
        $this->assertSite($siteKey);
        $settings = [
            'schema_version' => 1, 'event_retention_days' => 90,
            'enabled' => $request->boolean('enabled'), 'heading' => trim($data['heading']),
            'default_limit' => (int) $data['default_limit'], 'rotation_hours' => (int) $data['rotation_hours'],
        ];
        if ($settings['enabled'] && ! $this->sites->themeSupportsRecommendations($siteKey)) {
            throw ValidationException::withMessages(['enabled' => '当前文章主题尚未接入推荐内容模板，请完成主题适配后再开启。']);
        }
        if ($siteKey === 'primary') {
            DB::transaction(function () use ($data, $settings): void {
                DB::table('site_settings')->insertOrIgnore([
                    'setting_key' => 'article_recommendations', 'setting_value' => '{}',
                    'created_at' => now(), 'updated_at' => now(),
                ]);
                $row = DB::table('site_settings')->where('setting_key', 'article_recommendations')->lockForUpdate()->first();
                $current = json_decode((string) $row->setting_value, true) ?: [];
                if ((int) ($current['revision'] ?? 0) !== (int) $data['revision']) {
                    throw ValidationException::withMessages(['revision' => '站点设置已更新，请重新载入后保存。']);
                }
                DB::table('site_settings')->where('id', $row->id)->update([
                    'setting_value' => json_encode([...$settings, 'revision' => (int) $data['revision'] + 1], JSON_UNESCAPED_UNICODE),
                    'updated_at' => now(),
                ]);
            }, 3);
        } else {
            $profile = $this->sites->profile($siteKey);
            abort_unless($profile?->channel, 404);
            try {
                $leases->run($profile->channel, 'link_pool_settings', function (DistributionChannel $channel) use ($settings, $profile, $data): void {
                    DB::transaction(function () use ($channel, $settings, $profile, $data): void {
                        $locked = DistributionChannel::query()->whereKey($channel->id)->lockForUpdate()->firstOrFail();
                        $lockedProfile = HostedSiteProfile::query()->whereKey($profile->id)->lockForUpdate()->firstOrFail();
                        if ((int) $lockedProfile->settings_version !== (int) $data['revision']) {
                            throw ValidationException::withMessages(['revision' => '站点设置已更新，请重新载入后保存。']);
                        }
                        $locked->site_settings = array_replace($locked->site_settings ?? [], ['article_recommendations' => $settings]);
                        $locked->save();
                        $lockedProfile->increment('settings_version');
                    });
                });
            } catch (DistributionChannelDeletionBlocked) {
                throw ValidationException::withMessages(['site_key' => '渠道正在删除，站点设置无法修改。']);
            }
        }
        $request->attributes->set('admin_activity_details', ['link_pool_site' => $siteKey, 'enabled' => $settings['enabled']]);

        if ($request->expectsJson()) {
            return response()->json(['message' => '站点推荐设置已保存。', 'revision' => (int) $data['revision'] + 1]);
        }

        return redirect()->route('admin.link-pool.settings', ['site' => $siteKey, 'campaign_id' => $request->input('campaign_id')])
            ->with('link_pool_message', '站点推荐设置已保存。');
    }

    public function emergency(Request $request): RedirectResponse
    {
        $data = $request->validate(['disabled' => ['required', 'in:0,1']]);
        SiteSetting::query()->updateOrCreate(['setting_key' => 'link_pool_emergency_disabled'], ['setting_value' => $data['disabled']]);
        $request->attributes->set('admin_activity_details', ['link_pool_emergency_disabled' => $data['disabled']]);

        return back()->with('link_pool_message', $data['disabled'] === '1' ? '全部站点推荐已紧急停用。' : '全局停用已解除；各站开关仍分别生效。');
    }

    private function campaignEditor(?object $campaign, string $siteKey): View
    {
        $draft = $campaign ? DB::table('link_campaign_drafts')->where('campaign_id', $campaign->id)->first() : null;
        $payload = $draft ? json_decode((string) $draft->payload_json, true) : null;
        if ($campaign && ! $payload) {
            $rules = json_decode((string) $campaign->source_rules_json, true) ?: [];
            $payload = [
                'name' => $campaign->name, 'mode' => $campaign->mode, 'priority' => $campaign->priority,
                'limit' => $campaign->limit, 'scope' => $rules['scope'] ?? 'all',
                'category_id' => $rules['category_id'] ?? null, 'keyword' => $rules['keyword'] ?? '',
                'source_article_ids' => DB::table('link_campaign_sources')->where('campaign_id', $campaign->id)->pluck('source_article_id')->all(),
                'exclusion_ids' => json_decode((string) $campaign->exclusions_json, true) ?: [],
                'target_ids' => DB::table('link_campaign_targets')->where('campaign_id', $campaign->id)->pluck('target_id')->all(),
                'pinned_ids' => DB::table('link_campaign_targets')->where('campaign_id', $campaign->id)->whereNotNull('pin_order')->orderBy('pin_order')->pluck('target_id')->all(),
                'approved_cross_site_ids' => DB::table('link_campaign_targets')->where('campaign_id', $campaign->id)->where('cross_site_review_status', 'approved')->pluck('target_id')->all(),
                'review_reasons' => DB::table('link_campaign_targets')->where('campaign_id', $campaign->id)->pluck('editorial_reason', 'target_id')->all(),
                'starts_at' => $campaign->starts_at, 'ends_at' => $campaign->ends_at,
            ];
        }
        if ($campaign && $payload) {
            $payload['base_revision'] = (int) $campaign->revision;
        }
        $old = request()->session()->getOldInput();
        if (($old['source_site_key'] ?? null) === $siteKey
            && (int) ($old['campaign_id'] ?? 0) === (int) ($campaign?->id ?? 0)) {
            $payload = array_replace($payload ?? [], $old);
            foreach (['target_ids', 'pinned_ids', 'approved_cross_site_ids', 'review_reasons'] as $field) {
                $payload[$field] = (array) ($old[$field] ?? []);
            }
            foreach (['source_article_ids', 'exclusion_ids'] as $field) {
                $raw = $old[$field] ?? [];
                $payload[$field] = array_values(array_filter(array_map('intval', is_array($raw) ? $raw : preg_split('/[\s,，、]+/u', (string) $raw))));
            }
        }
        $targetSearch = mb_substr(trim((string) request()->query('target_q', '')), 0, 120);
        $targets = DB::table('link_pool_targets')->where('status', 'active')
            ->when($targetSearch !== '', function (Builder $query) use ($targetSearch): void {
                if (ctype_digit($targetSearch)) {
                    $query->where('id', (int) $targetSearch);

                    return;
                }
                $query->where(fn (Builder $match) => $match
                    ->where('display_title', 'like', '%'.$targetSearch.'%')
                    ->orWhere('input_url', 'like', '%'.$targetSearch.'%'));
            })
            ->orderByRaw('CASE WHEN target_site_key = ? THEN 0 ELSE 1 END', [$siteKey])
            ->orderByDesc('updated_at')->orderByDesc('id')->limit(2000)->get();
        $selectedIds = array_map('intval', (array) ($payload['target_ids'] ?? []));
        if ($selectedIds !== []) {
            $targets = DB::table('link_pool_targets')->whereIn('id', $selectedIds)->get()
                ->concat($targets)->unique('id')->values();
        }

        $sourceSearch = mb_substr(trim((string) request()->query('source_q', '')), 0, 120);
        $sourceChoices = $this->sites->query($siteKey)
            ->when($sourceSearch !== '', fn ($query) => $query->where('articles.title', 'like', '%'.$sourceSearch.'%'))
            ->orderByDesc('articles.published_at')->orderByDesc('articles.id')->limit(200)->get(['articles.id', 'articles.title']);
        $selectedSourceIds = array_values(array_unique(array_map('intval', array_merge(
            (array) ($payload['source_article_ids'] ?? []), (array) ($payload['exclusion_ids'] ?? []),
        ))));
        if ($selectedSourceIds !== []) {
            $sourceChoices = $this->sites->query($siteKey)->whereIn('articles.id', $selectedSourceIds)
                ->get(['articles.id', 'articles.title'])->concat($sourceChoices)->unique('id')->values();
            foreach (array_diff($selectedSourceIds, $sourceChoices->pluck('id')->all()) as $missingId) {
                $sourceChoices->prepend((object) ['id' => $missingId, 'title' => '已不可投放的文章 #'.$missingId]);
            }
        }
        $categories = Category::query()->whereIn('id', $this->sites->query($siteKey)->select('articles.category_id'))
            ->orderBy('sort_order')->orderBy('name')->get(['id', 'name']);
        $sourceReady = ! $this->sites->emergencyDisabled() && $this->sites->canPublish($siteKey)
            && $this->sites->settings($siteKey)['enabled'] && $this->sites->themeSupportsRecommendations($siteKey);

        return $this->view('admin.link-pool.campaign-form', compact(
            'campaign', 'draft', 'payload', 'siteKey', 'targets', 'targetSearch', 'sourceSearch', 'sourceChoices', 'categories', 'sourceReady',
        ));
    }

    private function campaignChoices(?string $siteKey): Collection
    {
        return DB::table('link_campaigns')->when($siteKey, fn ($query) => $query->where('source_site_key', $siteKey))
            ->orderByDesc('updated_at')->get(['id', 'name', 'source_site_key']);
    }

    private function importValidationResponse(Request $request, ValidationException $exception, ?string $siteKey, string $input): RedirectResponse
    {
        return redirect()->route('admin.link-pool.import.form', ['site' => $siteKey])
            ->withErrors($exception->errors())->withInput([
                'links' => mb_check_encoding($input, 'UTF-8') ? $input : '',
                ...$request->only(['ack_titles', 'use_article_title_lines']),
                'delimiter' => $this->normalizeImportDelimiter($request->input('delimiter')),
            ]);
    }

    private function importDelimiter(Request $request): ?string
    {
        return $request->validate(['delimiter' => ['nullable', 'string', 'in:csv,tsv']])['delimiter'] ?? null;
    }

    private function normalizeImportDelimiter(mixed $value): ?string
    {
        return in_array($value, ['csv', 'tsv'], true) ? $value : null;
    }

    private function importInput(Request $request): string
    {
        if ($request->hasFile('file')) {
            $request->validate(['file' => ['file', 'max:1024', 'mimes:csv,txt']]);

            return (string) file_get_contents($request->file('file')->getRealPath());
        }

        return (string) $request->validate(['links' => ['required', 'string', 'max:1048576']])['links'];
    }

    private function selectedSite(Request $request): ?string
    {
        $value = trim((string) $request->query('site', ''));
        if ($value !== '') {
            $this->assertSite($value);
        }

        return $value ?: null;
    }

    private function assertSite(string $siteKey): void
    {
        if ($siteKey !== 'primary' && $this->sites->profile($siteKey) === null) {
            throw ValidationException::withMessages(['site' => '来源站点不存在。']);
        }
    }

    private function assertTargetSite(string $siteKey): void
    {
        if ($siteKey !== 'external') {
            $this->assertSite($siteKey);
        }
    }

    private function edges(?string $siteKey = null): Builder
    {
        $sourceSites = $siteKey ? [$siteKey] : DB::table('link_campaigns')
            ->where('status', 'active')->distinct()->pluck('source_site_key')->all();
        $sourceSites = array_values(array_filter($sourceSites, fn (string $key): bool => ! $this->sites->emergencyDisabled() && $this->sites->canPublish($key)
            && $this->sites->settings($key)['enabled'] && $this->sites->themeSupportsRecommendations($key)));
        $placementIds = app(LinkPoolRecommendationService::class)->renderedPlacementIds($sourceSites);
        $query = DB::table('link_placements as placements')->join('link_selection_heads as heads', function ($join): void {
            $join->on('heads.batch_id', '=', 'placements.batch_id')
                ->on('heads.source_site_key', '=', 'placements.source_site_key')
                ->on('heads.source_article_id', '=', 'placements.source_article_id');
        })->join('link_pool_targets as targets', function ($join): void {
            $join->on('targets.id', '=', 'placements.target_id')->orOn(function ($legacy): void {
                $legacy->whereNull('placements.target_id')->on('targets.target_site_key', '=', 'placements.target_site_key')
                    ->on('targets.article_id', '=', 'placements.target_article_id');
            });
        })->join('link_campaigns as campaigns', 'campaigns.id', '=', 'placements.campaign_id')
            ->whereIntegerInRaw('placements.id', $placementIds)
            ->where('targets.status', 'active')
            ->where(fn ($query) => $query->whereNull('targets.expires_at')->orWhere('targets.expires_at', '>', now()))
            ->where('campaigns.status', 'active')
            ->where(fn ($query) => $query->whereNull('campaigns.starts_at')->orWhere('campaigns.starts_at', '<=', now()))
            ->where(fn ($query) => $query->whereNull('campaigns.ends_at')->orWhere('campaigns.ends_at', '>', now()))
            ->whereIn('placements.source_site_key', $sourceSites)
            ->where(function (Builder $query) use ($sourceSites): void {
                foreach ($sourceSites as $key) {
                    $query->orWhere(fn (Builder $siteQuery) => $siteQuery
                        ->where('placements.source_site_key', $key)
                        ->whereExists($this->sites->query($key)->selectRaw('1')
                            ->whereColumn('articles.id', 'placements.source_article_id')->toBase()));
                }
            })
            ->where(fn (Builder $query) => $query
                ->where('targets.target_type', 'external')
                ->orWhereColumn('placements.source_site_key', 'placements.target_site_key')
                ->orWhereExists(DB::table('link_campaign_targets as relations')->selectRaw('1')
                    ->whereColumn('relations.campaign_id', 'placements.campaign_id')
                    ->whereColumn('relations.target_id', 'targets.id')
                    ->where('relations.cross_site_review_status', 'approved')))
            ->when($siteKey, fn ($query) => $query->where('placements.source_site_key', $siteKey));

        return app(LinkPoolTargetService::class)->scopeEligible($query);
    }

    private function eligibleTargets(?string $siteKey = null): Builder
    {
        return app(LinkPoolTargetService::class)->scopeEligible(DB::table('link_pool_targets as targets'), 'targets', $siteKey);
    }

    /** @param array<string,mixed> $data */
    private function view(string $name, array $data): View
    {
        $profiles = HostedSiteProfile::query()->with('channel')->orderBy('hostname')->get();

        return view($name, $data + [
            'pageTitle' => '内链投放',
            'activeMenu' => auth('admin')->user()?->canManageProtectedWorkflows() ? 'distribution' : 'link_pool',
            'adminSiteName' => AdminWeb::siteName(),
            'importDelimiter' => $this->normalizeImportDelimiter(request()->old('delimiter', request()->input('delimiter'))),
            'siteLabels' => ['primary' => '主站', 'external' => '外部链接'] + $profiles->mapWithKeys(fn ($profile) => [
                'hosted:'.$profile->id => $profile->channel?->name ?? $profile->hostname,
            ])->all(),
            'profiles' => $profiles, 'emergencyDisabled' => $this->sites->emergencyDisabled(),
            'canWrite' => auth('admin')->user()?->isSuperAdmin() === true,
        ]);
    }
}
