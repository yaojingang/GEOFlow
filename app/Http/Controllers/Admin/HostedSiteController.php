<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\ArchiveHostedSiteRequest;
use App\Http\Requests\Admin\AssignHostedSiteArticleRequest;
use App\Http\Requests\Admin\HostedSiteActionRequest;
use App\Http\Requests\Admin\HostedSiteIndexingRequest;
use App\Http\Requests\Admin\StoreHostedSiteRequest;
use App\Http\Requests\Admin\UpdateHostedSiteRequest;
use App\Models\Article;
use App\Models\DistributionChannel;
use App\Models\HostedSiteAllocationRequest;
use App\Models\HostedSiteArticleAssignment;
use App\Models\HostedSiteProfile;
use App\Models\LeadSubmission;
use App\Services\HostedSites\HostedSiteAllocationRequestService;
use App\Services\HostedSites\HostedSiteAllocator;
use App\Services\HostedSites\HostedSiteLifecycleService;
use App\Services\HostedSites\HostedSiteQualityService;
use App\Support\AdminWeb;
use App\Support\Site\SiteThemeCatalog;
use DomainException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\View\View;

class HostedSiteController extends Controller
{
    public function __construct(
        private readonly HostedSiteLifecycleService $lifecycle,
        private readonly HostedSiteQualityService $quality,
        private readonly SiteThemeCatalog $themeCatalog,
        private readonly HostedSiteAllocationRequestService $allocationRequests,
        private readonly HostedSiteAllocator $allocator,
    ) {}

    public function index(): View
    {
        $profiles = HostedSiteProfile::query()
            ->with('channel')
            ->withCount([
                'assignments as published_count' => fn ($query) => $query->where('status', 'published'),
                'assignments as reserved_count' => fn ($query) => $query->where('status', 'reserved'),
            ])
            ->orderByDesc('id')
            ->paginate(20);
        $profiles->getCollection()
            ->groupBy(fn (HostedSiteProfile $profile): string => now($profile->timezone)->toDateString())
            ->each(function ($dateProfiles, string $capacityDate): void {
                $counts = HostedSiteArticleAssignment::query()
                    ->whereIn('hosted_site_profile_id', $dateProfiles->pluck('id'))
                    ->whereDate('capacity_date', $capacityDate)
                    ->whereIn('status', [
                        HostedSiteArticleAssignment::STATUS_RESERVED,
                        HostedSiteArticleAssignment::STATUS_PUBLISHED,
                    ])
                    ->selectRaw('hosted_site_profile_id, COUNT(*) AS aggregate')
                    ->groupBy('hosted_site_profile_id')
                    ->pluck('aggregate', 'hosted_site_profile_id');
                foreach ($dateProfiles as $profile) {
                    $profile->setAttribute('today_used_count', (int) ($counts[$profile->id] ?? 0));
                }
            });

        return view('admin.distribution.hosted-sites.index', [
            'pageTitle' => '托管渠道站点',
            'activeMenu' => 'distribution',
            'adminSiteName' => AdminWeb::siteName(),
            'profiles' => $profiles,
            'pendingAllocationCount' => HostedSiteAllocationRequest::query()
                ->where('status', HostedSiteAllocationRequest::STATUS_PENDING)
                ->count(),
        ]);
    }

    public function create(): View
    {
        return view('admin.distribution.hosted-sites.form', [
            'pageTitle' => '创建托管站点',
            'activeMenu' => 'distribution',
            'adminSiteName' => AdminWeb::siteName(),
            'channel' => null,
            'profile' => null,
            'availableThemes' => $this->themeCatalog->hostedCompatible(),
        ]);
    }

    public function store(StoreHostedSiteRequest $request): RedirectResponse
    {
        $this->setAuditTarget($request);
        try {
            $channel = $this->lifecycle->create($request->validated(), auth('admin')->id());
            $this->setAuditTarget($request, $channel);
            $this->setAuditDetails($request, [
                'success' => true,
                'hostname' => $channel->domain,
                'before' => null,
                'after' => $this->lifecycleState($channel),
            ]);
        } catch (DomainException $exception) {
            $this->setAuditDetails($request, [
                'success' => false,
                'hostname' => (string) $request->validated('hostname'),
                'error' => $exception->getMessage(),
            ]);

            return back()->withInput()->withErrors($exception->getMessage());
        }

        return redirect()
            ->route('admin.distribution.hosted-sites.show', $channel)
            ->with('message', '托管站点已创建，当前处于维护和禁止索引状态。');
    }

    public function show(DistributionChannel $hostedSite): View
    {
        $channel = $this->hosted($hostedSite);
        $profile = $channel->hostedSiteProfile;

        return view('admin.distribution.hosted-sites.show', [
            'pageTitle' => (string) $channel->name,
            'activeMenu' => 'distribution',
            'adminSiteName' => AdminWeb::siteName(),
            'channel' => $channel,
            'profile' => $profile,
            'assignments' => $profile?->assignments()
                ->with('article:id,title,slug,status')
                ->orderByDesc('id')
                ->limit(20)
                ->get() ?? collect(),
            'allocationRequests' => HostedSiteAllocationRequest::query()
                ->with('article:id,title,slug')
                ->where(function ($query) use ($profile): void {
                    $query->where('hosted_site_profile_id', $profile?->id)
                        ->orWhereHas('assignment', fn ($assignment) => $assignment->where('hosted_site_profile_id', $profile?->id))
                        ->orWhereHas('task.distributionChannels', fn ($channels) => $channels->whereKey($profile?->distribution_channel_id));
                })
                ->orderByDesc('id')
                ->limit(20)
                ->get(),
            'boundTasks' => $channel->tasks()
                ->select(['tasks.id', 'tasks.name', 'tasks.status', 'tasks.publish_scope'])
                ->orderBy('tasks.id')
                ->get(),
            'todayUsedCount' => $profile?->assignments()
                ->whereDate('capacity_date', now($profile->timezone)->toDateString())
                ->whereIn('status', [
                    HostedSiteArticleAssignment::STATUS_RESERVED,
                    HostedSiteArticleAssignment::STATUS_PUBLISHED,
                ])
                ->count() ?? 0,
            'viewCount' => Schema::hasColumn('view_logs', 'hosted_site_profile_id')
                ? DB::table('view_logs')->where('hosted_site_profile_id', $profile?->id)->count()
                : 0,
            'leadCount' => LeadSubmission::query()->where('hosted_site_profile_id', $profile?->id)->count(),
        ]);
    }

    public function edit(DistributionChannel $hostedSite): View
    {
        $channel = $this->hosted($hostedSite);

        return view('admin.distribution.hosted-sites.form', [
            'pageTitle' => '编辑托管站点',
            'activeMenu' => 'distribution',
            'adminSiteName' => AdminWeb::siteName(),
            'channel' => $channel,
            'profile' => $channel->hostedSiteProfile,
            'availableThemes' => $this->themeCatalog->hostedCompatible(),
        ]);
    }

    public function update(
        UpdateHostedSiteRequest $request,
        DistributionChannel $hostedSite,
    ): RedirectResponse {
        $channel = $this->hosted($hostedSite);
        $before = $this->lifecycleState($channel);
        try {
            $channel = $this->lifecycle->update($channel, $request->validated());
            $this->setAuditDetails($request, [
                'success' => true,
                'hostname' => $channel->domain,
                'before' => $before,
                'after' => $this->lifecycleState($channel),
            ]);
        } catch (DomainException $exception) {
            $this->setAuditDetails($request, [
                'success' => false,
                'hostname' => $channel->domain,
                'before' => $before,
                'error' => $exception->getMessage(),
            ]);

            return back()->withInput()->withErrors($exception->getMessage());
        }

        return redirect()
            ->route('admin.distribution.hosted-sites.show', $channel)
            ->with('message', '托管站点设置已更新，请重新执行预检。');
    }

    public function preflight(HostedSiteActionRequest $request, DistributionChannel $hostedSite): RedirectResponse
    {
        $channel = $this->hosted($hostedSite);
        $result = $this->quality->preflight($channel);
        $failedChecks = array_keys(array_filter(
            $result['checks'],
            static fn (bool $passed): bool => ! $passed
        ));
        $this->setAuditDetails($request, [
            'success' => $result['passed'],
            'hostname' => $channel->domain,
            'preflight_passed' => $result['passed'],
            'failed_checks' => $failedChecks,
        ]);

        return $result['passed']
            ? back()->with('message', '预检通过，可以激活站点。')
            : back()->withErrors('预检未通过：'.implode('、', $failedChecks));
    }

    public function activate(HostedSiteActionRequest $request, DistributionChannel $hostedSite): RedirectResponse
    {
        return $this->runAction($request, $hostedSite, fn ($channel) => $this->lifecycle->activate($channel), '站点已上线并开始接收新文章。');
    }

    public function pause(HostedSiteActionRequest $request, DistributionChannel $hostedSite): RedirectResponse
    {
        return $this->runAction($request, $hostedSite, fn ($channel) => $this->lifecycle->pause($channel), '站点已暂停接收新文章，现有页面继续在线。');
    }

    public function maintenance(HostedSiteActionRequest $request, DistributionChannel $hostedSite): RedirectResponse
    {
        return $this->runAction($request, $hostedSite, fn ($channel) => $this->lifecycle->maintenance($channel), '站点已进入维护模式并禁止索引。');
    }

    public function indexing(
        HostedSiteIndexingRequest $request,
        DistributionChannel $hostedSite,
    ): RedirectResponse {
        return $this->runAction(
            $request,
            $hostedSite,
            fn ($channel) => $this->lifecycle->setIndexing(
                $channel,
                (string) $request->validated('indexing_status'),
                filter_var($request->validated('quality_confirmed'), FILTER_VALIDATE_BOOLEAN)
            ),
            '站点索引状态已更新。'
        );
    }

    public function archive(
        ArchiveHostedSiteRequest $request,
        DistributionChannel $hostedSite,
    ): RedirectResponse {
        $channel = $this->hosted($hostedSite);
        $before = $this->lifecycleState($channel);
        try {
            $this->lifecycle->archive(
                $channel,
                (string) $request->validated('hostname')
            );
            $this->setAuditDetails($request, [
                'success' => true,
                'hostname' => $channel->domain,
                'before' => $before,
                'after' => $this->lifecycleState($channel->fresh('hostedSiteProfile')),
            ]);
        } catch (DomainException $e) {
            $this->setAuditDetails($request, [
                'success' => false,
                'hostname' => $channel->domain,
                'before' => $before,
                'error' => $e->getMessage(),
            ]);

            return back()->withErrors($e->getMessage());
        }

        return redirect()
            ->route('admin.distribution.hosted-sites.index')
            ->with('message', '托管站点已归档。');
    }

    public function assignArticle(
        AssignHostedSiteArticleRequest $request,
        DistributionChannel $hostedSite,
    ): RedirectResponse {
        $channel = $this->hosted($hostedSite);
        $article = Article::query()
            ->with('task.distributionChannels')
            ->findOrFail((int) $request->validated('article_id'));
        $boundHostedChannelIds = $article->task?->distributionChannels
            ?->filter(static fn (DistributionChannel $candidate): bool => $candidate->isHostedSite())
            ->pluck('id')
            ->map(static fn ($id): int => (int) $id)
            ->sort()
            ->values()
            ->all() ?? [];

        try {
            if ($boundHostedChannelIds !== [(int) $channel->id]) {
                throw new DomainException('文章任务没有精确绑定当前托管站点。');
            }
            $allocationRequest = $this->allocationRequests->request($article);
            $assignment = $this->allocator->allocate($allocationRequest);
            if ($assignment === null
                || (int) $assignment->hosted_site_profile_id !== (int) $channel->hostedSiteProfile?->id) {
                throw new DomainException('文章当前无法分配到这个托管站点。');
            }
        } catch (DomainException $e) {
            return back()->withErrors($e->getMessage());
        }

        return back()->with('message', '文章已预留站点容量并进入发布队列。');
    }

    private function hosted(DistributionChannel $channel): DistributionChannel
    {
        abort_unless(
            $channel->isHostedSite() && $channel->hostedSiteProfile()->exists(),
            404
        );

        return $channel->loadMissing('hostedSiteProfile');
    }

    private function runAction(
        Request $request,
        DistributionChannel $channel,
        callable $action,
        string $message,
    ): RedirectResponse {
        $channel = $this->hosted($channel);
        $before = $this->lifecycleState($channel);
        try {
            $action($channel);
            $this->setAuditDetails($request, [
                'success' => true,
                'hostname' => $channel->domain,
                'before' => $before,
                'after' => $this->lifecycleState($channel->fresh('hostedSiteProfile')),
            ]);
        } catch (DomainException $e) {
            $this->setAuditDetails($request, [
                'success' => false,
                'hostname' => $channel->domain,
                'before' => $before,
                'error' => $e->getMessage(),
            ]);

            return back()->withErrors($e->getMessage());
        }

        return back()->with('message', $message);
    }

    /** @return array<string,mixed> */
    private function lifecycleState(DistributionChannel $channel): array
    {
        return [
            'channel_status' => $channel->status,
            'serving_status' => $channel->hostedSiteProfile?->serving_status,
            'quality_status' => $channel->hostedSiteProfile?->quality_status,
            'indexing_status' => $channel->hostedSiteProfile?->indexing_status,
        ];
    }

    /** @param array<string,mixed> $details */
    private function setAuditDetails(Request $request, array $details): void
    {
        $request->attributes->set('admin_activity_details', $details);
        request()->attributes->set('admin_activity_details', $details);
    }

    private function setAuditTarget(Request $request, ?DistributionChannel $channel = null): void
    {
        $request->attributes->set('admin_activity_target_type', 'hostedSite');
        $request->attributes->set('admin_activity_target_id', $channel?->id);
        request()->attributes->set('admin_activity_target_type', 'hostedSite');
        request()->attributes->set('admin_activity_target_id', $channel?->id);
    }
}
