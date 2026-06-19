<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Jobs\ProcessArticleDistributionJob;
use App\Models\Admin;
use App\Models\ArticleDistribution;
use App\Models\DistributionChannel;
use App\Models\DistributionChannelSecret;
use App\Models\DistributionLog;
use App\Services\GeoFlow\DistributionOrchestrator;
use App\Services\GeoFlow\DistributionPublisherManager;
use App\Services\GeoFlow\DistributionTargetSitePackageBuilder;
use App\Support\AdminWeb;
use App\Support\GeoFlow\ApiKeyCrypto;
use App\Support\Site\ArticleTextAdPicker;
use App\Support\Site\SiteThemeCatalog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

class DistributionController extends Controller
{
    public function __construct(
        private readonly DistributionOrchestrator $distributionOrchestrator,
        private readonly DistributionPublisherManager $publisherManager,
        private readonly ApiKeyCrypto $apiKeyCrypto,
        private readonly DistributionTargetSitePackageBuilder $targetSitePackageBuilder,
        private readonly SiteThemeCatalog $siteThemeCatalog,
    ) {}

    public function index(Request $request): View
    {
        $channels = DistributionChannel::query()
            ->with('activeSecret')
            ->withCount([
                'articleDistributions as pending_count' => fn ($query) => $query->whereIn('status', ['queued', 'sending']),
                'articleDistributions as failed_count' => fn ($query) => $query->where('status', 'failed'),
            ])
            ->orderByDesc('id')
            ->get();

        $stats = [
            'total' => DistributionChannel::query()->count(),
            'active' => DistributionChannel::query()->where('status', 'active')->count(),
            'pending' => ArticleDistribution::query()->whereIn('status', ['queued', 'sending'])->count(),
            'failed' => ArticleDistribution::query()->where('status', 'failed')->count(),
        ];

        $logsQuery = DistributionLog::query()
            ->with('channel:id,name')
            ->with('article:id,title,slug')
            ->orderByDesc('id');
        $logsPerPage = 10;
        $logsTotal = (clone $logsQuery)->count();
        $logsLastPage = max(1, (int) ceil($logsTotal / $logsPerPage));
        $logsPage = min(
            max(1, (int) $request->query('logs_page', 1)),
            $logsLastPage
        );
        $logs = $logsQuery
            ->paginate($logsPerPage, ['*'], 'logs_page', $logsPage)
            ->withQueryString();

        return view('admin.distribution.index', [
            'pageTitle' => __('admin.distribution.page_title'),
            'activeMenu' => 'distribution',
            'adminSiteName' => AdminWeb::siteName(),
            'channels' => $channels,
            'stats' => $stats,
            'logs' => $logs,
        ]);
    }

    public function create(): View
    {
        return view('admin.distribution.create', [
            'pageTitle' => __('admin.distribution.create_title'),
            'activeMenu' => 'distribution',
            'adminSiteName' => AdminWeb::siteName(),
            'availableThemes' => $this->siteThemeCatalog->all(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $payload = $this->validateChannel($request);

        $channel = DistributionChannel::query()->create([
            'name' => (string) $payload['name'],
            'domain' => $this->normalizeDomain((string) $payload['domain']),
            'endpoint_url' => (string) $payload['endpoint_url'],
            'channel_type' => (string) $payload['channel_type'],
            'front_mode' => (string) ($payload['front_mode'] ?? 'static'),
            'template_key' => filled($payload['template_key'] ?? null) ? (string) $payload['template_key'] : null,
            'site_settings' => $this->normalizeChannelSiteSettings($payload),
            'channel_config' => $this->normalizeChannelConfig($payload),
            'status' => (string) $payload['status'],
            'description' => filled($payload['description'] ?? null) ? (string) $payload['description'] : null,
            'created_by_admin_id' => auth('admin')->id(),
        ]);

        if ($channel->isWordPressRest()) {
            $this->createWordPressSecret($channel, (string) $payload['wordpress_application_password']);

            return redirect()
                ->route('admin.distribution.index')
                ->with('message', __('admin.distribution.message.created'));
        }

        if ($channel->isGenericHttpApi()) {
            if ($channel->resolvedGenericHttpConfig()['generic_auth_type'] !== 'none') {
                $this->createGenericHttpSecret($channel, (string) $payload['generic_secret']);
            }

            return redirect()
                ->route('admin.distribution.show', ['channelId' => (int) $channel->id])
                ->with('message', __('admin.distribution.message.created'));
        }

        $secret = $this->createChannelSecret($channel);

        return redirect()
            ->route('admin.distribution.index')
            ->with('message', __('admin.distribution.message.created'))
            ->with('distribution_secret', [
                'key_id' => $secret['key_id'],
                'secret' => $secret['secret'],
                'endpoint_url' => (string) $payload['endpoint_url'],
            ]);
    }

    public function edit(int $channelId): View|RedirectResponse
    {
        $channel = DistributionChannel::query()
            ->with('activeSecret')
            ->whereKey($channelId)
            ->first();
        if (! $channel) {
            return redirect()->route('admin.distribution.index')->withErrors(__('admin.distribution.message.not_found'));
        }

        return view('admin.distribution.edit', [
            'pageTitle' => __('admin.distribution.edit_title'),
            'activeMenu' => 'distribution',
            'adminSiteName' => AdminWeb::siteName(),
            'channel' => $channel,
            'remoteSiteSettings' => $channel->resolvedSiteSettings(),
            'availableThemes' => $this->siteThemeCatalog->all(),
            'articleDetailTextAds' => ArticleTextAdPicker::all(false),
            'articleTextAdPolicy' => $channel->resolvedArticleTextAdPolicy(),
        ]);
    }

    public function update(Request $request, int $channelId): RedirectResponse
    {
        $channel = DistributionChannel::query()->whereKey($channelId)->first();
        if (! $channel) {
            return redirect()->route('admin.distribution.index')->withErrors(__('admin.distribution.message.not_found'));
        }

        $payload = $this->validateChannel($request);
        $payload['channel_type'] = $channel->channelType();
        if (($payload['channel_type'] ?? 'geoflow_agent') === 'generic_http_api') {
            $genericAuthType = (string) ($payload['generic_auth_type'] ?? 'bearer');
            $hasActiveSecret = DistributionChannelSecret::query()
                ->where('distribution_channel_id', (int) $channel->id)
                ->where('status', 'active')
                ->exists();
            if ($genericAuthType !== 'none' && ! $hasActiveSecret && ! filled($payload['generic_secret'] ?? null)) {
                throw ValidationException::withMessages([
                    'generic_secret' => __('admin.distribution.validation.generic_secret'),
                ]);
            }
        }

        $channel->forceFill([
            'name' => (string) $payload['name'],
            'domain' => $this->normalizeDomain((string) $payload['domain']),
            'endpoint_url' => (string) $payload['endpoint_url'],
            'channel_type' => (string) $payload['channel_type'],
            'front_mode' => (string) ($payload['front_mode'] ?? 'static'),
            'template_key' => filled($payload['template_key'] ?? null) ? (string) $payload['template_key'] : null,
            'site_settings' => $this->normalizeChannelSiteSettings($payload, $channel),
            'channel_config' => $this->normalizeChannelConfig($payload, $channel),
            'status' => (string) $payload['status'],
            'description' => filled($payload['description'] ?? null) ? (string) $payload['description'] : null,
        ])->save();

        if ($channel->isWordPressRest() && filled($payload['wordpress_application_password'] ?? null)) {
            DistributionChannelSecret::query()
                ->where('distribution_channel_id', (int) $channel->id)
                ->where('status', 'active')
                ->update(['status' => 'revoked']);
            $this->createWordPressSecret($channel, (string) $payload['wordpress_application_password']);
        }
        if ($channel->isGenericHttpApi()) {
            $genericAuthType = $channel->resolvedGenericHttpConfig()['generic_auth_type'];
            if ($genericAuthType === 'none') {
                DistributionChannelSecret::query()
                    ->where('distribution_channel_id', (int) $channel->id)
                    ->where('status', 'active')
                    ->update(['status' => 'revoked']);
            } elseif (filled($payload['generic_secret'] ?? null)) {
                DistributionChannelSecret::query()
                    ->where('distribution_channel_id', (int) $channel->id)
                    ->where('status', 'active')
                    ->update(['status' => 'revoked']);
                $this->createGenericHttpSecret($channel, (string) $payload['generic_secret']);
            }
        }

        $message = __('admin.distribution.message.updated');
        $channel->load('activeSecret');
        if ($channel->activeSecret || ($channel->isGenericHttpApi() && $channel->resolvedGenericHttpConfig()['generic_auth_type'] === 'none')) {
            try {
                $this->syncChannelSiteSettings($channel);
                $message = __('admin.distribution.message.updated_and_settings_synced');
            } catch (Throwable $e) {
                return redirect()
                    ->route('admin.distribution.show', ['channelId' => (int) $channel->id])
                    ->with('message', $message)
                    ->withErrors(__('admin.distribution.message.settings_sync_failed', ['message' => $e->getMessage()]));
            }
        }

        return redirect()
            ->route('admin.distribution.show', ['channelId' => (int) $channel->id])
            ->with('message', $message);
    }

    public function show(int $channelId): View|RedirectResponse
    {
        $channel = DistributionChannel::query()
            ->with('activeSecret')
            ->whereKey($channelId)
            ->first();

        if (! $channel) {
            return redirect()->route('admin.distribution.index')->withErrors(__('admin.distribution.message.not_found'));
        }

        $jobs = ArticleDistribution::query()
            ->with('article:id,title,slug,status')
            ->where('distribution_channel_id', $channelId)
            ->orderByDesc('id')
            ->limit(20)
            ->get();

        $logs = DistributionLog::query()
            ->with('article:id,title,slug')
            ->where('distribution_channel_id', $channelId)
            ->orderByDesc('id')
            ->limit(20)
            ->get();

        return view('admin.distribution.show', [
            'pageTitle' => __('admin.distribution.detail_title'),
            'activeMenu' => 'distribution',
            'adminSiteName' => AdminWeb::siteName(),
            'channel' => $channel,
            'jobs' => $jobs,
            'logs' => $logs,
            'remoteSiteSettings' => $channel->resolvedSiteSettings(),
            'articleTextAdPolicy' => $channel->resolvedArticleTextAdPolicy(),
            'effectiveArticleTextAds' => $channel->effectiveArticleTextAds(),
        ]);
    }

    public function jobs(Request $request): View
    {
        $filters = [
            'status' => (string) $request->query('status', ''),
            'channel_id' => max(0, (int) $request->query('channel_id', 0)),
        ];
        if (! in_array($filters['status'], ['queued', 'sending', 'synced', 'failed'], true)) {
            $filters['status'] = '';
        }

        $query = ArticleDistribution::query()
            ->with(['article:id,title,slug,status', 'channel:id,name,domain']);

        if ($filters['status'] !== '') {
            $query->where('status', $filters['status']);
        }
        if ($filters['channel_id'] > 0) {
            $query->where('distribution_channel_id', $filters['channel_id']);
        }

        $jobs = $query->orderByDesc('id')->paginate(20)->withQueryString();
        $channels = DistributionChannel::query()
            ->select(['id', 'name', 'domain'])
            ->orderBy('name')
            ->get();

        return view('admin.distribution.jobs', [
            'pageTitle' => __('admin.distribution.jobs_title'),
            'activeMenu' => 'distribution',
            'adminSiteName' => AdminWeb::siteName(),
            'jobs' => $jobs,
            'channels' => $channels,
            'filters' => $filters,
        ]);
    }

    public function pause(int $channelId): RedirectResponse
    {
        return $this->setStatus($channelId, 'paused', __('admin.distribution.message.paused'));
    }

    public function activate(int $channelId): RedirectResponse
    {
        return $this->setStatus($channelId, 'active', __('admin.distribution.message.activated'));
    }

    public function rotateSecret(int $channelId): RedirectResponse
    {
        $channel = DistributionChannel::query()->whereKey($channelId)->first();
        if (! $channel) {
            return redirect()->route('admin.distribution.index')->withErrors(__('admin.distribution.message.not_found'));
        }
        if (! $channel->isGeoFlowAgent()) {
            return back()->withErrors(__('admin.distribution.message.secret_rotation_not_available'));
        }

        DistributionChannelSecret::query()
            ->where('distribution_channel_id', (int) $channel->id)
            ->where('status', 'active')
            ->update(['status' => 'revoked']);

        $secret = $this->createChannelSecret($channel);

        return redirect()
            ->route('admin.distribution.show', ['channelId' => (int) $channel->id])
            ->with('message', __('admin.distribution.message.secret_rotated'))
            ->with('distribution_secret', [
                'key_id' => $secret['key_id'],
                'secret' => $secret['secret'],
                'endpoint_url' => (string) $channel->endpoint_url,
            ]);
    }

    public function revealSecret(Request $request, int $channelId): RedirectResponse
    {
        $channel = DistributionChannel::query()
            ->with('activeSecret')
            ->whereKey($channelId)
            ->first();
        if (! $channel) {
            return redirect()->route('admin.distribution.index')->withErrors(__('admin.distribution.message.not_found'));
        }
        if ($channel->isWordPressRest()) {
            return back()->withErrors(__('admin.distribution.message.package_not_available_for_wordpress'));
        }

        /** @var Admin|null $admin */
        $admin = auth('admin')->user();
        if (! $admin instanceof Admin || ! $admin->isSuperAdmin()) {
            return back()->withErrors([
                'password' => __('admin.distribution.message.secret_reveal_forbidden'),
            ]);
        }

        $payload = $request->validate([
            'password' => ['required', 'string'],
        ]);

        if (! Hash::check((string) $payload['password'], (string) $admin->password)) {
            return back()->withErrors([
                'password' => __('admin.distribution.message.password_invalid'),
            ]);
        }

        $secret = $channel->activeSecret;
        if (! $secret) {
            return back()->withErrors([
                'password' => __('admin.distribution.message.active_secret_not_found'),
            ]);
        }

        $plainSecret = $this->apiKeyCrypto->decrypt((string) $secret->secret_ciphertext);
        if ($plainSecret === '') {
            return back()->withErrors([
                'password' => __('admin.distribution.message.secret_decrypt_failed'),
            ]);
        }

        return redirect()
            ->route('admin.distribution.show', ['channelId' => (int) $channel->id])
            ->with('message', __('admin.distribution.message.secret_revealed'))
            ->with('distribution_secret', [
                'key_id' => (string) $secret->key_id,
                'secret' => $plainSecret,
                'endpoint_url' => (string) $channel->endpoint_url,
            ]);
    }

    public function downloadPackage(Request $request, int $channelId): StreamedResponse|RedirectResponse
    {
        $channel = DistributionChannel::query()
            ->with('activeSecret')
            ->whereKey($channelId)
            ->first();
        if (! $channel) {
            return redirect()->route('admin.distribution.index')->withErrors(__('admin.distribution.message.not_found'));
        }
        if (! $channel->isGeoFlowAgent()) {
            return back()->withErrors(__('admin.distribution.message.package_not_available_for_channel_type'));
        }

        /** @var Admin|null $admin */
        $admin = auth('admin')->user();
        if (! $admin instanceof Admin || ! $admin->isSuperAdmin()) {
            return back()->withErrors([
                'package_password' => __('admin.distribution.message.package_download_forbidden'),
            ]);
        }

        $payload = $request->validate([
            'package_password' => ['required', 'string'],
        ]);

        if (! Hash::check((string) $payload['package_password'], (string) $admin->password)) {
            return back()->withErrors([
                'package_password' => __('admin.distribution.message.password_invalid'),
            ]);
        }

        $secret = $channel->activeSecret;
        if (! $secret) {
            return back()->withErrors([
                'package_password' => __('admin.distribution.message.active_secret_not_found'),
            ]);
        }

        $plainSecret = $this->apiKeyCrypto->decrypt((string) $secret->secret_ciphertext);
        if ($plainSecret === '') {
            return back()->withErrors([
                'package_password' => __('admin.distribution.message.secret_decrypt_failed'),
            ]);
        }

        $package = $this->targetSitePackageBuilder->build($channel, (string) $secret->key_id, $plainSecret);

        return response()->streamDownload(function () use ($package): void {
            echo file_get_contents($package['path']) ?: '';
            @unlink($package['path']);
        }, $package['filename'], [
            'Content-Type' => 'application/zip',
        ]);
    }

    public function retry(int $distributionId): RedirectResponse
    {
        $distribution = ArticleDistribution::query()->whereKey($distributionId)->first();
        if (! $distribution) {
            return back()->withErrors(__('admin.distribution.message.job_not_found'));
        }

        $distribution->forceFill([
            'status' => 'queued',
            'last_error_message' => null,
            'next_retry_at' => now(),
        ])->save();

        $this->distributionOrchestrator->log(
            'info',
            '分发任务已手动重新入队',
            $distribution->distribution_channel_id,
            $distribution->id,
            $distribution->article_id,
            ['event' => 'distribution.retry_queued']
        );

        ProcessArticleDistributionJob::dispatch((int) $distribution->id)
            ->onQueue('distribution')
            ->afterCommit();

        return back()->with('message', __('admin.distribution.message.retry_queued'));
    }

    public function editArticle(int $distributionId): View|RedirectResponse
    {
        $distribution = ArticleDistribution::query()
            ->with(['article', 'channel'])
            ->whereKey($distributionId)
            ->first();

        if (! $distribution || ! $distribution->article || ! $distribution->channel) {
            return back()->withErrors(__('admin.distribution.message.job_not_found'));
        }

        return view('admin.distribution.article-edit', [
            'pageTitle' => __('admin.distribution.remote_article.edit_title'),
            'activeMenu' => 'distribution',
            'adminSiteName' => AdminWeb::siteName(),
            'distribution' => $distribution,
            'article' => $distribution->article,
            'channel' => $distribution->channel,
        ]);
    }

    public function updateArticle(Request $request, int $distributionId): RedirectResponse
    {
        $distribution = ArticleDistribution::query()
            ->with(['article', 'channel'])
            ->whereKey($distributionId)
            ->first();

        if (! $distribution || ! $distribution->article || ! $distribution->channel) {
            return back()->withErrors(__('admin.distribution.message.job_not_found'));
        }

        $payload = $request->validate([
            'title' => ['required', 'string', 'max:500'],
            'excerpt' => ['nullable', 'string'],
            'content' => ['required', 'string'],
            'keywords' => ['nullable', 'string'],
            'meta_description' => ['nullable', 'string'],
        ]);

        $distribution->article->forceFill([
            'title' => (string) $payload['title'],
            'excerpt' => filled($payload['excerpt'] ?? null) ? (string) $payload['excerpt'] : null,
            'content' => (string) $payload['content'],
            'keywords' => filled($payload['keywords'] ?? null) ? (string) $payload['keywords'] : null,
            'meta_description' => filled($payload['meta_description'] ?? null) ? (string) $payload['meta_description'] : null,
        ])->save();

        try {
            $distribution->refresh();
            $this->distributionOrchestrator->updateRemoteArticle($distribution);
        } catch (Throwable $e) {
            return back()
                ->withInput()
                ->withErrors(__('admin.distribution.message.remote_article_update_failed', ['message' => $e->getMessage()]));
        }

        return redirect()
            ->route('admin.distribution.show', ['channelId' => (int) $distribution->distribution_channel_id])
            ->with('message', __('admin.distribution.message.remote_article_updated'));
    }

    public function deleteArticle(Request $request, int $distributionId): JsonResponse|RedirectResponse
    {
        $distribution = ArticleDistribution::query()
            ->with(['article', 'channel'])
            ->whereKey($distributionId)
            ->first();

        if (! $distribution || ! $distribution->article || ! $distribution->channel) {
            if ($request->expectsJson()) {
                return response()->json([
                    'ok' => false,
                    'message' => __('admin.distribution.message.job_not_found'),
                ], 404);
            }

            return back()->withErrors(__('admin.distribution.message.job_not_found'));
        }

        try {
            $this->distributionOrchestrator->deleteRemoteArticle($distribution);
        } catch (Throwable $e) {
            if ($request->expectsJson()) {
                return response()->json([
                    'ok' => false,
                    'message' => __('admin.distribution.message.remote_article_delete_failed', ['message' => $e->getMessage()]),
                ], 500);
            }

            return back()->withErrors(__('admin.distribution.message.remote_article_delete_failed', ['message' => $e->getMessage()]));
        }

        $distribution->refresh();
        if ($request->expectsJson()) {
            return response()->json([
                'ok' => true,
                'message' => __('admin.distribution.message.remote_article_deleted'),
                'job' => [
                    'id' => (int) $distribution->id,
                    'action' => (string) $distribution->action,
                    'status' => (string) $distribution->status,
                    'remote_url' => $distribution->remote_url,
                ],
            ]);
        }

        return redirect()
            ->route('admin.distribution.show', ['channelId' => (int) $distribution->distribution_channel_id])
            ->with('message', __('admin.distribution.message.remote_article_deleted'));
    }

    public function health(int $channelId): RedirectResponse
    {
        $channel = DistributionChannel::query()->whereKey($channelId)->first();
        if (! $channel) {
            return redirect()->route('admin.distribution.index')->withErrors(__('admin.distribution.message.not_found'));
        }

        try {
            $result = $this->distributionOrchestrator->healthCheck($channel);
            $resolvedEndpointUrl = is_string($result['agent_base_url'] ?? null)
                ? rtrim((string) $result['agent_base_url'], '/')
                : null;
            $channel->forceFill([
                'endpoint_url' => $resolvedEndpointUrl ?: $channel->endpoint_url,
                'last_health_status' => 'ok',
                'last_health_checked_at' => now(),
                'last_error_message' => null,
            ])->save();

            return back()->with('message', __('admin.distribution.message.health_ok').' '.json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        } catch (Throwable $e) {
            $channel->forceFill([
                'last_health_status' => 'failed',
                'last_health_checked_at' => now(),
                'last_error_message' => $e->getMessage(),
            ])->save();

            return back()->withErrors(__('admin.distribution.message.health_failed', ['message' => $e->getMessage()]));
        }
    }

    public function syncSettings(int $channelId): RedirectResponse
    {
        $channel = DistributionChannel::query()
            ->with('activeSecret')
            ->whereKey($channelId)
            ->first();
        if (! $channel) {
            return redirect()->route('admin.distribution.index')->withErrors(__('admin.distribution.message.not_found'));
        }

        try {
            $this->syncChannelSiteSettings($channel);
            $refreshCount = $this->distributionOrchestrator->enqueueChannelContentRefresh($channel);

            return redirect()
                ->route('admin.distribution.show', ['channelId' => (int) $channel->id])
                ->with('message', $refreshCount > 0
                    ? __('admin.distribution.message.settings_synced_with_content_refresh', ['count' => $refreshCount])
                    : __('admin.distribution.message.settings_synced'));
        } catch (Throwable $e) {
            return back()->withErrors(__('admin.distribution.message.settings_sync_failed', ['message' => $e->getMessage()]));
        }
    }

    /**
     * @return array<string,mixed>
     */
    private function syncChannelSiteSettings(DistributionChannel $channel): array
    {
        try {
            $result = $this->publisherManager->forChannel($channel)->syncSiteSettings($channel);
            $this->distributionOrchestrator->log(
                'info',
                '目标站点设置已同步',
                (int) $channel->id,
                null,
                null,
                [
                    'event' => 'site.settings.synced',
                    'remote_result' => $result,
                ]
            );

            return $result;
        } catch (Throwable $e) {
            $this->distributionOrchestrator->log(
                'error',
                '目标站点设置同步失败：'.$e->getMessage(),
                (int) $channel->id,
                null,
                null,
                ['event' => 'site.settings.sync_failed']
            );

            throw $e;
        }
    }

    private function normalizeDomain(string $domain): string
    {
        $value = trim($domain);
        if ($value === '') {
            return '';
        }

        if (! str_contains($value, '://')) {
            $value = 'https://'.$value;
        }

        $host = parse_url($value, PHP_URL_HOST);

        return is_string($host) && $host !== '' ? $host : trim($domain);
    }

    private function normalizeEndpointUrl(string $endpointUrl): string
    {
        $value = trim($endpointUrl);
        if ($value === '') {
            return '';
        }

        if (! str_contains($value, '://')) {
            $value = 'https://'.$value;
        }

        return rtrim($value, '/');
    }

    private function isValidHttpEndpoint(string $endpointUrl): bool
    {
        if ($endpointUrl === '' || preg_match('/\s/', $endpointUrl) === 1) {
            return false;
        }

        $parts = parse_url($endpointUrl);
        if (! is_array($parts)) {
            return false;
        }

        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        $host = (string) ($parts['host'] ?? '');

        return in_array($scheme, ['http', 'https'], true) && $host !== '';
    }

    /**
     * @return array<string,mixed>
     */
    private function validateChannel(Request $request): array
    {
        $payload = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'domain' => ['required', 'string', 'max:255'],
            'endpoint_url' => ['required', 'string', 'max:500'],
            'channel_type' => ['nullable', 'string', 'in:geoflow_agent,wordpress_rest,generic_http_api'],
            'front_mode' => ['nullable', 'string', 'in:static,rewrite'],
            'template_key' => ['nullable', 'string', 'max:120'],
            'status' => ['required', 'string', 'in:active,paused'],
            'description' => ['nullable', 'string', 'max:1000'],
            'wordpress_username' => ['nullable', 'string', 'max:120'],
            'wordpress_application_password' => ['nullable', 'string', 'max:255'],
            'wordpress_post_status' => ['nullable', 'string', 'in:publish,draft,pending,private'],
            'wordpress_category_strategy' => ['nullable', 'string', 'in:match_or_create,match_only,fixed'],
            'wordpress_fixed_category' => ['nullable', 'string', 'max:120'],
            'wordpress_tag_strategy' => ['nullable', 'string', 'in:keywords_to_tags,disabled'],
            'wordpress_image_strategy' => ['nullable', 'string', 'in:upload_to_media,keep_original'],
            'generic_auth_type' => ['nullable', 'string', 'in:none,bearer,basic,header_key,hmac'],
            'generic_basic_username' => ['nullable', 'string', 'max:120'],
            'generic_secret' => ['nullable', 'string', 'max:1000'],
            'generic_header_name' => ['nullable', 'string', 'max:120'],
            'generic_hmac_key_id_header' => ['nullable', 'string', 'max:120'],
            'generic_hmac_signature_header' => ['nullable', 'string', 'max:120'],
            'generic_hmac_timestamp_header' => ['nullable', 'string', 'max:120'],
            'generic_hmac_nonce_header' => ['nullable', 'string', 'max:120'],
            'generic_hmac_body_hash_header' => ['nullable', 'string', 'max:120'],
            'generic_timeout_seconds' => ['nullable', 'integer', 'min:5', 'max:120'],
            'generic_success_statuses' => ['nullable', 'string', 'max:120'],
            'generic_health_method' => ['nullable', 'string', 'in:GET,POST,PUT,PATCH,DELETE'],
            'generic_health_path' => ['nullable', 'string', 'max:255'],
            'generic_publish_method' => ['nullable', 'string', 'in:GET,POST,PUT,PATCH,DELETE'],
            'generic_publish_path' => ['nullable', 'string', 'max:255'],
            'generic_update_method' => ['nullable', 'string', 'in:GET,POST,PUT,PATCH,DELETE'],
            'generic_update_path' => ['nullable', 'string', 'max:255'],
            'generic_delete_method' => ['nullable', 'string', 'in:GET,POST,PUT,PATCH,DELETE'],
            'generic_delete_path' => ['nullable', 'string', 'max:255'],
            'generic_settings_method' => ['nullable', 'string', 'in:GET,POST,PUT,PATCH,DELETE'],
            'generic_settings_path' => ['nullable', 'string', 'max:255'],
            'generic_remote_id_path' => ['nullable', 'string', 'max:120'],
            'generic_remote_url_path' => ['nullable', 'string', 'max:120'],
            'generic_payload_wrapper' => ['nullable', 'string', 'in:none,data'],
            'site_name' => ['nullable', 'string', 'max:120'],
            'site_subtitle' => ['nullable', 'string', 'max:255'],
            'site_description' => ['nullable', 'string'],
            'site_keywords' => ['nullable', 'string', 'max:500'],
            'copyright_info' => ['nullable', 'string', 'max:500'],
            'site_logo' => ['nullable', 'url', 'max:500'],
            'site_favicon' => ['nullable', 'url', 'max:500'],
            'seo_title_template' => ['nullable', 'string', 'max:255'],
            'seo_description_template' => ['nullable', 'string', 'max:255'],
            'featured_limit' => ['nullable', 'integer', 'min:1', 'max:100'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:200'],
            'article_text_ad_policy' => ['nullable', 'array'],
            'article_text_ad_policy.content_top.mode' => ['nullable', 'string', 'in:inherit,disabled,selected,custom'],
            'article_text_ad_policy.content_top.module_ids' => ['nullable', 'array'],
            'article_text_ad_policy.content_top.module_ids.*' => ['nullable', 'string', 'max:120'],
            'article_text_ad_policy.content_top.ad_ids' => ['nullable', 'array'],
            'article_text_ad_policy.content_top.ad_ids.*' => ['nullable', 'string', 'max:120'],
            'article_text_ad_policy.content_top.custom_modules' => ['nullable', 'array'],
            'article_text_ad_policy.content_bottom.mode' => ['nullable', 'string', 'in:inherit,disabled,selected,custom'],
            'article_text_ad_policy.content_bottom.module_ids' => ['nullable', 'array'],
            'article_text_ad_policy.content_bottom.module_ids.*' => ['nullable', 'string', 'max:120'],
            'article_text_ad_policy.content_bottom.ad_ids' => ['nullable', 'array'],
            'article_text_ad_policy.content_bottom.ad_ids.*' => ['nullable', 'string', 'max:120'],
            'article_text_ad_policy.content_bottom.custom_modules' => ['nullable', 'array'],
        ]);

        $payload['endpoint_url'] = $this->normalizeEndpointUrl((string) $payload['endpoint_url']);
        $payload['channel_type'] = (string) ($payload['channel_type'] ?? 'geoflow_agent');
        $payload['front_mode'] = (string) ($payload['front_mode'] ?? 'static');
        if (! $this->isValidHttpEndpoint((string) $payload['endpoint_url'])) {
            throw ValidationException::withMessages([
                'endpoint_url' => __('admin.distribution.validation.endpoint_url'),
            ]);
        }
        if ($payload['channel_type'] === 'wordpress_rest') {
            if (! filled($payload['wordpress_username'] ?? null)) {
                throw ValidationException::withMessages([
                    'wordpress_username' => __('admin.distribution.validation.wordpress_username'),
                ]);
            }
            if ($request->isMethod('post') && ! filled($payload['wordpress_application_password'] ?? null)) {
                throw ValidationException::withMessages([
                    'wordpress_application_password' => __('admin.distribution.validation.wordpress_application_password'),
                ]);
            }
        }
        if ($payload['channel_type'] === 'generic_http_api') {
            $authType = (string) ($payload['generic_auth_type'] ?? 'bearer');
            if ($authType === 'basic' && ! filled($payload['generic_basic_username'] ?? null)) {
                throw ValidationException::withMessages([
                    'generic_basic_username' => __('admin.distribution.validation.generic_basic_username'),
                ]);
            }
            if ($request->isMethod('post') && $authType !== 'none' && ! filled($payload['generic_secret'] ?? null)) {
                throw ValidationException::withMessages([
                    'generic_secret' => __('admin.distribution.validation.generic_secret'),
                ]);
            }
            $successStatuses = $this->normalizeGenericSuccessStatuses($payload['generic_success_statuses'] ?? '200,201,202,204');
            if ($successStatuses === []) {
                throw ValidationException::withMessages([
                    'generic_success_statuses' => __('admin.distribution.validation.generic_success_statuses'),
                ]);
            }
            $payload['generic_success_statuses'] = implode(',', $successStatuses);
            foreach ($this->genericMethodRules() as $field => $rule) {
                [$allowedMethods, $defaultMethod] = $rule;
                $method = strtoupper(trim((string) ($payload[$field] ?? $defaultMethod)));
                if (! in_array($method, $allowedMethods, true)) {
                    throw ValidationException::withMessages([
                        $field => __('admin.distribution.validation.generic_method', ['methods' => implode(', ', $allowedMethods)]),
                    ]);
                }
                $payload[$field] = $method;
            }
            foreach ($this->genericHeaderNameFields() as $field) {
                $headerName = trim((string) ($payload[$field] ?? ''));
                if ($headerName !== '' && ! $this->isValidHttpHeaderName($headerName)) {
                    throw ValidationException::withMessages([
                        $field => __('admin.distribution.validation.generic_header_name'),
                    ]);
                }
            }
            foreach ($this->genericPathFields() as $field => $required) {
                $path = trim((string) ($payload[$field] ?? ''));
                if ($required && $path === '') {
                    throw ValidationException::withMessages([
                        $field => __('admin.distribution.validation.generic_path_required'),
                    ]);
                }
                if ($path !== '' && (! $this->isValidGenericPath($path))) {
                    throw ValidationException::withMessages([
                        $field => __('admin.distribution.validation.generic_path'),
                    ]);
                }
                $payload[$field] = $path;
            }
        }

        $this->validateArticleTextAdPolicyPayload($payload['article_text_ad_policy'] ?? null);

        return $payload;
    }

    private function validateArticleTextAdPolicyPayload(mixed $policy): void
    {
        if (! is_array($policy)) {
            return;
        }

        $placementPolicies = array_key_exists('mode', $policy)
            ? [
                ArticleTextAdPicker::PLACEMENT_TOP => $policy,
                ArticleTextAdPicker::PLACEMENT_BOTTOM => $policy,
            ]
            : [
                ArticleTextAdPicker::PLACEMENT_TOP => is_array($policy[ArticleTextAdPicker::PLACEMENT_TOP] ?? null) ? $policy[ArticleTextAdPicker::PLACEMENT_TOP] : [],
                ArticleTextAdPicker::PLACEMENT_BOTTOM => is_array($policy[ArticleTextAdPicker::PLACEMENT_BOTTOM] ?? null) ? $policy[ArticleTextAdPicker::PLACEMENT_BOTTOM] : [],
            ];

        foreach ($placementPolicies as $placement => $placementPolicy) {
            if ((string) ($placementPolicy['mode'] ?? 'inherit') !== 'custom') {
                continue;
            }

            $this->validateArticleTextAdCustomModules(
                $placementPolicy['custom_modules'] ?? [],
                (string) $placement
            );
        }
    }

    private function validateArticleTextAdCustomModules(mixed $modules, string $placement): void
    {
        if (! is_array($modules)) {
            return;
        }

        if (count($modules) > DistributionChannel::MAX_CUSTOM_TEXT_AD_MODULES_PER_PLACEMENT) {
            throw ValidationException::withMessages([
                'article_text_ad_policy' => __('admin.site_settings.ads.text_validation_max_modules', [
                    'max' => DistributionChannel::MAX_CUSTOM_TEXT_AD_MODULES_PER_PLACEMENT,
                ]),
            ]);
        }

        foreach (array_values($modules) as $moduleIndex => $module) {
            if (! is_array($module)) {
                continue;
            }

            $moduleNumber = $moduleIndex + 1;
            $modulePlacement = (string) ($module['placement'] ?? $placement);
            if ($modulePlacement !== $placement || ! in_array($modulePlacement, ArticleTextAdPicker::PLACEMENTS, true)) {
                throw ValidationException::withMessages([
                    'article_text_ad_policy' => __('admin.site_settings.ads.text_validation_position', ['index' => $moduleNumber]),
                ]);
            }

            $rawLinks = is_array($module['links'] ?? null) ? $module['links'] : [];
            if (count($rawLinks) > ArticleTextAdPicker::MAX_LINKS_PER_MODULE) {
                throw ValidationException::withMessages([
                    'article_text_ad_policy' => __('admin.site_settings.ads.text_validation_max_links', [
                        'index' => $moduleNumber,
                        'max' => ArticleTextAdPicker::MAX_LINKS_PER_MODULE,
                    ]),
                ]);
            }

            $validLinks = 0;
            foreach (array_values($rawLinks) as $linkIndex => $link) {
                if (! is_array($link)) {
                    continue;
                }

                $linkNumber = $moduleNumber.'.'.($linkIndex + 1);
                $text = trim((string) ($link['text'] ?? ''));
                $rawUrl = trim((string) ($link['url'] ?? ''));
                $trackingParam = ltrim(trim((string) ($link['tracking_param'] ?? '')), "? \t\n\r\0\x0B");
                $color = trim((string) ($link['text_color'] ?? ''));

                if ($text === '' && $rawUrl === '' && $trackingParam === '') {
                    continue;
                }

                $url = $this->normalizeArticleTextAdUrlForValidation($rawUrl);
                if ($rawUrl !== '' && $url === '') {
                    throw ValidationException::withMessages([
                        'article_text_ad_policy' => __('admin.site_settings.ads.text_validation_url', ['index' => $linkNumber]),
                    ]);
                }

                if ($text === '' || $url === '') {
                    throw ValidationException::withMessages([
                        'article_text_ad_policy' => __('admin.site_settings.ads.text_validation_required', ['index' => $linkNumber]),
                    ]);
                }

                if ($color !== '' && ! $this->isValidArticleTextAdHexColor($color)) {
                    throw ValidationException::withMessages([
                        'article_text_ad_policy' => __('admin.site_settings.ads.text_validation_color', ['index' => $linkNumber]),
                    ]);
                }

                if ($trackingParam !== '' && ! $this->isValidArticleTextAdTrackingParam($trackingParam)) {
                    throw ValidationException::withMessages([
                        'article_text_ad_policy' => __('admin.site_settings.ads.text_validation_tracking', ['index' => $linkNumber]),
                    ]);
                }

                $validLinks++;
            }

            if (
                $validLinks === 0
                && (
                    trim((string) ($module['id'] ?? '')) !== ''
                    || trim((string) ($module['name'] ?? '')) !== ''
                    || $this->hasArticleTextAdLinkData($rawLinks)
                )
            ) {
                throw ValidationException::withMessages([
                    'article_text_ad_policy' => __('admin.site_settings.ads.text_validation_module_required', ['index' => $moduleNumber]),
                ]);
            }
        }
    }

    private function hasArticleTextAdLinkData(array $rawLinks): bool
    {
        foreach ($rawLinks as $link) {
            if (! is_array($link)) {
                continue;
            }

            if (
                trim((string) ($link['text'] ?? '')) !== ''
                || trim((string) ($link['url'] ?? '')) !== ''
                || trim((string) ($link['tracking_param'] ?? '')) !== ''
            ) {
                return true;
            }
        }

        return false;
    }

    private function normalizeArticleTextAdUrlForValidation(string $url): string
    {
        $normalized = trim($url);
        if ($normalized === '' || str_starts_with($normalized, '//')) {
            return '';
        }

        if (str_starts_with($normalized, '/')) {
            return $normalized;
        }

        if (preg_match('#^https?://#i', $normalized) === 1) {
            return $normalized;
        }

        if (preg_match('#^[a-z][a-z0-9+.-]*:#i', $normalized) === 1) {
            return '';
        }

        return '/'.ltrim($normalized, '/');
    }

    private function isValidArticleTextAdHexColor(string $color): bool
    {
        return preg_match('/^#(?:[0-9a-fA-F]{3}|[0-9a-fA-F]{6})$/', trim($color)) === 1;
    }

    private function isValidArticleTextAdTrackingParam(string $trackingParam): bool
    {
        $trackingParam = trim($trackingParam);

        return $trackingParam !== ''
            && mb_strlen($trackingParam) <= 250
            && ! str_contains($trackingParam, '://')
            && ! str_starts_with($trackingParam, '/')
            && preg_match('/^[A-Za-z0-9._~%=&+;,:@-]+$/', $trackingParam) === 1;
    }

    /**
     * @param  array<string,mixed>  $payload
     * @return array<string,mixed>
     */
    private function normalizeChannelSiteSettings(array $payload, ?DistributionChannel $channel = null): array
    {
        $defaultName = trim((string) ($payload['name'] ?? 'GEOFlow Target Site'));
        $defaults = $channel?->resolvedSiteSettings() ?? [
            'site_name' => $defaultName !== '' ? $defaultName : 'GEOFlow Target Site',
            'site_subtitle' => '',
            'site_description' => '由 GEOFlow 自动分发和管理的目标站点。',
            'site_keywords' => '',
            'copyright_info' => '© '.date('Y').' '.($defaultName !== '' ? $defaultName : 'GEOFlow Target Site'),
            'site_logo' => '',
            'site_favicon' => '',
            'seo_title_template' => '{title} - {site_name}',
            'seo_description_template' => '{description}',
            'featured_limit' => 6,
            'per_page' => 12,
        ];

        return [
            'site_name' => trim((string) ($payload['site_name'] ?? $defaults['site_name'])),
            'site_subtitle' => trim((string) ($payload['site_subtitle'] ?? $defaults['site_subtitle'])),
            'site_description' => trim((string) ($payload['site_description'] ?? $defaults['site_description'])),
            'site_keywords' => trim((string) ($payload['site_keywords'] ?? $defaults['site_keywords'])),
            'copyright_info' => trim((string) ($payload['copyright_info'] ?? $defaults['copyright_info'])),
            'site_logo' => trim((string) ($payload['site_logo'] ?? $defaults['site_logo'])),
            'site_favicon' => trim((string) ($payload['site_favicon'] ?? $defaults['site_favicon'])),
            'seo_title_template' => trim((string) ($payload['seo_title_template'] ?? $defaults['seo_title_template'])),
            'seo_description_template' => trim((string) ($payload['seo_description_template'] ?? $defaults['seo_description_template'])),
            'featured_limit' => min(100, max(1, (int) ($payload['featured_limit'] ?? $defaults['featured_limit']))),
            'per_page' => min(200, max(1, (int) ($payload['per_page'] ?? $defaults['per_page']))),
        ];
    }

    /**
     * @param  array<string,mixed>  $payload
     * @return array<string,mixed>
     */
    private function normalizeChannelConfig(array $payload, ?DistributionChannel $channel = null): array
    {
        $channelType = (string) ($payload['channel_type'] ?? 'geoflow_agent');
        $articleTextAdPolicy = $this->normalizeArticleTextAdPolicy($payload['article_text_ad_policy'] ?? null, $channel);

        if ($channelType === 'generic_http_api') {
            $defaults = $channel?->resolvedGenericHttpConfig() ?? (new DistributionChannel)->resolvedGenericHttpConfig();

            return [
                'article_text_ad_policy' => $articleTextAdPolicy,
                'generic_auth_type' => (string) ($payload['generic_auth_type'] ?? $defaults['generic_auth_type']),
                'generic_basic_username' => trim((string) ($payload['generic_basic_username'] ?? $defaults['generic_basic_username'])),
                'generic_header_name' => trim((string) ($payload['generic_header_name'] ?? $defaults['generic_header_name'])),
                'generic_hmac_key_id_header' => trim((string) ($payload['generic_hmac_key_id_header'] ?? $defaults['generic_hmac_key_id_header'])),
                'generic_hmac_signature_header' => trim((string) ($payload['generic_hmac_signature_header'] ?? $defaults['generic_hmac_signature_header'])),
                'generic_hmac_timestamp_header' => trim((string) ($payload['generic_hmac_timestamp_header'] ?? $defaults['generic_hmac_timestamp_header'])),
                'generic_hmac_nonce_header' => trim((string) ($payload['generic_hmac_nonce_header'] ?? $defaults['generic_hmac_nonce_header'])),
                'generic_hmac_body_hash_header' => trim((string) ($payload['generic_hmac_body_hash_header'] ?? $defaults['generic_hmac_body_hash_header'])),
                'generic_timeout_seconds' => min(120, max(5, (int) ($payload['generic_timeout_seconds'] ?? $defaults['generic_timeout_seconds']))),
                'generic_success_statuses' => $this->normalizeGenericSuccessStatuses($payload['generic_success_statuses'] ?? $defaults['generic_success_statuses']),
                'generic_health_method' => strtoupper((string) ($payload['generic_health_method'] ?? $defaults['generic_health_method'])),
                'generic_health_path' => $this->normalizeGenericPath($payload['generic_health_path'] ?? $defaults['generic_health_path']),
                'generic_publish_method' => strtoupper((string) ($payload['generic_publish_method'] ?? $defaults['generic_publish_method'])),
                'generic_publish_path' => $this->normalizeGenericPath($payload['generic_publish_path'] ?? $defaults['generic_publish_path']),
                'generic_update_method' => strtoupper((string) ($payload['generic_update_method'] ?? $defaults['generic_update_method'])),
                'generic_update_path' => $this->normalizeGenericPath($payload['generic_update_path'] ?? $defaults['generic_update_path']),
                'generic_delete_method' => strtoupper((string) ($payload['generic_delete_method'] ?? $defaults['generic_delete_method'])),
                'generic_delete_path' => $this->normalizeGenericPath($payload['generic_delete_path'] ?? $defaults['generic_delete_path']),
                'generic_settings_method' => strtoupper((string) ($payload['generic_settings_method'] ?? $defaults['generic_settings_method'])),
                'generic_settings_path' => $this->normalizeGenericPath($payload['generic_settings_path'] ?? $defaults['generic_settings_path']),
                'generic_remote_id_path' => trim((string) ($payload['generic_remote_id_path'] ?? $defaults['generic_remote_id_path'])),
                'generic_remote_url_path' => trim((string) ($payload['generic_remote_url_path'] ?? $defaults['generic_remote_url_path'])),
                'generic_payload_wrapper' => (string) ($payload['generic_payload_wrapper'] ?? $defaults['generic_payload_wrapper']),
            ];
        }

        if ($channelType !== 'wordpress_rest') {
            return [
                'article_text_ad_policy' => $articleTextAdPolicy,
            ];
        }

        $defaults = $channel?->resolvedChannelConfig() ?? [
            'wordpress_username' => '',
            'wordpress_post_status' => 'publish',
            'wordpress_category_strategy' => 'match_or_create',
            'wordpress_fixed_category' => '',
            'wordpress_tag_strategy' => 'keywords_to_tags',
            'wordpress_image_strategy' => 'upload_to_media',
            'wordpress_content_format' => 'html',
        ];

        return [
            'article_text_ad_policy' => $articleTextAdPolicy,
            'wordpress_username' => trim((string) ($payload['wordpress_username'] ?? $defaults['wordpress_username'])),
            'wordpress_post_status' => (string) ($payload['wordpress_post_status'] ?? $defaults['wordpress_post_status']),
            'wordpress_category_strategy' => (string) ($payload['wordpress_category_strategy'] ?? $defaults['wordpress_category_strategy']),
            'wordpress_fixed_category' => trim((string) ($payload['wordpress_fixed_category'] ?? $defaults['wordpress_fixed_category'])),
            'wordpress_tag_strategy' => (string) ($payload['wordpress_tag_strategy'] ?? $defaults['wordpress_tag_strategy']),
            'wordpress_image_strategy' => (string) ($payload['wordpress_image_strategy'] ?? $defaults['wordpress_image_strategy']),
            'wordpress_content_format' => 'html',
        ];
    }

    /**
     * @return array{
     *   content_top:array{mode:string,ad_ids:list<string>},
     *   content_bottom:array{mode:string,ad_ids:list<string>}
     * }
     */
    private function normalizeArticleTextAdPolicy(mixed $policy, ?DistributionChannel $channel = null): array
    {
        if ($policy === null) {
            return $channel?->resolvedArticleTextAdPolicy()
                ?? DistributionChannel::normalizeArticleTextAdPolicy(null);
        }

        return DistributionChannel::normalizeArticleTextAdPolicy($policy);
    }

    /**
     * @return array{key_id:string,secret:string}
     */
    private function createChannelSecret(DistributionChannel $channel): array
    {
        $keyId = 'gfk_'.Str::lower(Str::random(18));
        $plainSecret = 'gfsec_'.Str::random(40);

        DistributionChannelSecret::query()->create([
            'distribution_channel_id' => (int) $channel->id,
            'key_id' => $keyId,
            'secret_ciphertext' => $this->apiKeyCrypto->encrypt($plainSecret),
            'status' => 'active',
            'scopes' => ['article.publish', 'article.update', 'article.delete', 'site.settings.update', 'health.check'],
        ]);

        return [
            'key_id' => $keyId,
            'secret' => $plainSecret,
        ];
    }

    private function createWordPressSecret(DistributionChannel $channel, string $applicationPassword): void
    {
        DistributionChannelSecret::query()->create([
            'distribution_channel_id' => (int) $channel->id,
            'key_id' => 'wp_'.Str::lower(Str::random(18)),
            'secret_ciphertext' => $this->apiKeyCrypto->encrypt($applicationPassword),
            'status' => 'active',
            'scopes' => ['wordpress.rest'],
        ]);
    }

    private function createGenericHttpSecret(DistributionChannel $channel, string $secret): void
    {
        DistributionChannelSecret::query()->create([
            'distribution_channel_id' => (int) $channel->id,
            'key_id' => 'gapi_'.Str::lower(Str::random(18)),
            'secret_ciphertext' => $this->apiKeyCrypto->encrypt($secret),
            'status' => 'active',
            'scopes' => ['generic.http'],
        ]);
    }

    /**
     * @param  mixed  $value
     * @return list<int>
     */
    private function normalizeGenericSuccessStatuses(mixed $value): array
    {
        $items = is_array($value) ? $value : explode(',', (string) $value);
        $statuses = [];
        foreach ($items as $item) {
            $status = (int) trim((string) $item);
            if ($status >= 100 && $status <= 599 && ! in_array($status, $statuses, true)) {
                $statuses[] = $status;
            }
        }

        return $statuses;
    }

    private function normalizeGenericPath(mixed $path): string
    {
        $path = trim((string) $path);
        if ($path === '') {
            return '';
        }

        return str_starts_with($path, '/') ? $path : '/'.$path;
    }

    /**
     * @return array<string,array{0:list<string>,1:string}>
     */
    private function genericMethodRules(): array
    {
        return [
            'generic_health_method' => [['GET', 'POST'], 'GET'],
            'generic_publish_method' => [['POST', 'PUT', 'PATCH'], 'POST'],
            'generic_update_method' => [['POST', 'PUT', 'PATCH'], 'POST'],
            'generic_delete_method' => [['DELETE', 'POST'], 'DELETE'],
            'generic_settings_method' => [['POST', 'PUT', 'PATCH'], 'POST'],
        ];
    }

    /**
     * @return list<string>
     */
    private function genericHeaderNameFields(): array
    {
        return [
            'generic_header_name',
            'generic_hmac_key_id_header',
            'generic_hmac_signature_header',
            'generic_hmac_timestamp_header',
            'generic_hmac_nonce_header',
            'generic_hmac_body_hash_header',
        ];
    }

    private function isValidHttpHeaderName(string $headerName): bool
    {
        return preg_match('/^[A-Za-z0-9!#$%&\'*+\-.^_`|~]+$/', $headerName) === 1;
    }

    /**
     * @return array<string,bool>
     */
    private function genericPathFields(): array
    {
        return [
            'generic_health_path' => true,
            'generic_publish_path' => true,
            'generic_update_path' => true,
            'generic_delete_path' => true,
            'generic_settings_path' => false,
        ];
    }

    private function isValidGenericPath(string $path): bool
    {
        return ! str_contains($path, '://') && preg_match('/\s/', $path) !== 1;
    }

    private function setStatus(int $channelId, string $status, string $message): RedirectResponse
    {
        $channel = DistributionChannel::query()->whereKey($channelId)->first();
        if (! $channel) {
            return redirect()->route('admin.distribution.index')->withErrors(__('admin.distribution.message.not_found'));
        }

        $channel->forceFill(['status' => $status])->save();

        return redirect()
            ->route('admin.distribution.show', ['channelId' => (int) $channel->id])
            ->with('message', $message);
    }
}
