<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Jobs\ProcessArticleDistributionJob;
use App\Models\Admin;
use App\Models\ArticleDistribution;
use App\Models\DistributionChannel;
use App\Models\DistributionChannelSecret;
use App\Models\DistributionLog;
use App\Services\GeoFlow\DistributionHttpClient;
use App\Services\GeoFlow\DistributionOrchestrator;
use App\Services\GeoFlow\DistributionTargetSitePackageBuilder;
use App\Support\AdminWeb;
use App\Support\GeoFlow\ApiKeyCrypto;
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
        private readonly DistributionHttpClient $distributionHttpClient,
        private readonly ApiKeyCrypto $apiKeyCrypto,
        private readonly DistributionTargetSitePackageBuilder $targetSitePackageBuilder,
        private readonly SiteThemeCatalog $siteThemeCatalog,
    ) {}

    public function index(): View
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

        $logs = DistributionLog::query()
            ->with('channel:id,name')
            ->with('article:id,title,slug')
            ->orderByDesc('id')
            ->limit(10)
            ->get();

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
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $payload = $this->validateChannel($request);

        $channel = DistributionChannel::query()->create([
            'name' => (string) $payload['name'],
            'domain' => $this->normalizeDomain((string) $payload['domain']),
            'endpoint_url' => (string) $payload['endpoint_url'],
            'channel_type' => 'geoflow_agent',
            'front_mode' => (string) ($payload['front_mode'] ?? 'static'),
            'template_key' => filled($payload['template_key'] ?? null) ? (string) $payload['template_key'] : null,
            'site_settings' => $this->normalizeChannelSiteSettings($payload),
            'status' => (string) $payload['status'],
            'description' => filled($payload['description'] ?? null) ? (string) $payload['description'] : null,
            'created_by_admin_id' => auth('admin')->id(),
        ]);

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
        ]);
    }

    public function update(Request $request, int $channelId): RedirectResponse
    {
        $channel = DistributionChannel::query()->whereKey($channelId)->first();
        if (! $channel) {
            return redirect()->route('admin.distribution.index')->withErrors(__('admin.distribution.message.not_found'));
        }

        $payload = $this->validateChannel($request);
        $channel->forceFill([
            'name' => (string) $payload['name'],
            'domain' => $this->normalizeDomain((string) $payload['domain']),
            'endpoint_url' => (string) $payload['endpoint_url'],
            'front_mode' => (string) ($payload['front_mode'] ?? 'static'),
            'template_key' => filled($payload['template_key'] ?? null) ? (string) $payload['template_key'] : null,
            'site_settings' => $this->normalizeChannelSiteSettings($payload, $channel),
            'status' => (string) $payload['status'],
            'description' => filled($payload['description'] ?? null) ? (string) $payload['description'] : null,
        ])->save();

        $message = __('admin.distribution.message.updated');
        $channel->load('activeSecret');
        if ($channel->activeSecret) {
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
            $result = $this->distributionHttpClient->syncSiteSettings($channel);
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
     * @return array{name:string,domain:string,endpoint_url:string,front_mode:string,template_key?:string|null,status:string,description?:string|null}
     */
    private function validateChannel(Request $request): array
    {
        $payload = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'domain' => ['required', 'string', 'max:255'],
            'endpoint_url' => ['required', 'string', 'max:500'],
            'front_mode' => ['nullable', 'string', 'in:static,rewrite'],
            'template_key' => ['nullable', 'string', 'max:120'],
            'status' => ['required', 'string', 'in:active,paused'],
            'description' => ['nullable', 'string', 'max:1000'],
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
        ]);

        $payload['endpoint_url'] = $this->normalizeEndpointUrl((string) $payload['endpoint_url']);
        $payload['front_mode'] = (string) ($payload['front_mode'] ?? 'static');
        if (! $this->isValidHttpEndpoint((string) $payload['endpoint_url'])) {
            throw ValidationException::withMessages([
                'endpoint_url' => __('admin.distribution.validation.endpoint_url'),
            ]);
        }

        return $payload;
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
