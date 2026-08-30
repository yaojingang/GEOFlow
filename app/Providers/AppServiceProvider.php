<?php

namespace App\Providers;

use App\Contracts\AiWorkspace\AdminHelpResponder;
use App\Contracts\ArticleAiOptimizationRefiner;
use App\Contracts\ArticleAiQualityReviewer;
use App\Contracts\Outbound\HostResolver;
use App\Contracts\Outbound\OutboundTransport;
use App\Contracts\SystemUpdater\AgentClient;
use App\Http\ApiAuthContext;
use App\Jobs\ProcessTitleGenerationBatchJob;
use App\Models\Admin;
use App\Services\Admin\AdminUpdateMetadataService;
use App\Services\Admin\AdminWelcomeModalService;
use App\Services\AiWorkspace\AiWorkspaceModelRuntime;
use App\Services\GeoFlow\AnonymousUsageTelemetry;
use App\Services\GeoFlow\ArticleAiQualityWorkerLiveness;
use App\Services\GeoFlow\ArticleGeoFlowService;
use App\Services\GeoFlow\HorizonMetricsAdapter;
use App\Services\GeoFlow\JobQueueService;
use App\Services\GeoFlow\LaravelArticleAiOptimizationRefiner;
use App\Services\GeoFlow\LaravelArticleAiQualityReviewer;
use App\Services\GeoFlow\TaskLifecycleService;
use App\Services\GeoFlow\TaskMonitoringQueryService;
use App\Services\Outbound\FinalOutboundSecurityPolicy;
use App\Services\Outbound\LaravelPinnedOutboundTransport;
use App\Services\Outbound\SafeOutboundHttpClient;
use App\Services\Outbound\SecureHttpFactory;
use App\Services\Outbound\SystemHostResolver;
use App\Services\Site\HostedSiteResolver;
use App\Services\SystemUpdater\UnixSocketAgentClient;
use App\Support\AdminUiRegistry;
use App\Support\Site\CurrentSite;
use App\View\Composers\SiteLayoutComposer;
use Closure;
use GuzzleHttp\Utils;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Request;
use Illuminate\Queue\Events\Looping;
use Illuminate\Queue\Events\WorkerStarting;
use Illuminate\Queue\Events\WorkerStopping;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\View;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $fixedContextCapability = new \stdClass;
        $trustedTerminal = Closure::fromCallable(Utils::chooseHandler());

        $this->app->bind(HostResolver::class, SystemHostResolver::class);
        $this->app->bind(ArticleAiQualityReviewer::class, LaravelArticleAiQualityReviewer::class);
        $this->app->bind(ArticleAiOptimizationRefiner::class, LaravelArticleAiOptimizationRefiner::class);
        $this->app->bind(AgentClient::class, UnixSocketAgentClient::class);
        $this->app->singleton(FinalOutboundSecurityPolicy::class);
        $this->app->bind(OutboundTransport::class, function () use ($fixedContextCapability): LaravelPinnedOutboundTransport {
            return new LaravelPinnedOutboundTransport($fixedContextCapability);
        });
        $this->app->singleton(HttpFactory::class, function ($app) use ($fixedContextCapability, $trustedTerminal): SecureHttpFactory {
            $resolver = Closure::fromCallable(
                fn (string $url) => $app->make(SafeOutboundHttpClient::class)->resolveTarget($url)
            );

            return new SecureHttpFactory(
                $app->make('events'),
                $app->make(FinalOutboundSecurityPolicy::class),
                $resolver,
                $trustedTerminal,
                $fixedContextCapability,
            );
        });
        $this->app->singleton(JobQueueService::class);
        $this->app->singleton(HorizonMetricsAdapter::class);
        $this->app->singleton(TaskMonitoringQueryService::class);
        $this->app->singleton(TaskLifecycleService::class);
        $this->app->singleton(ArticleGeoFlowService::class);
        $this->app->scoped(CurrentSite::class);
        $this->app->singleton(HostedSiteResolver::class);
        $this->app->singleton(AiWorkspaceModelRuntime::class);
        $this->app->alias(AiWorkspaceModelRuntime::class, AdminHelpResponder::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->assertHostedSiteConfiguration();
        Event::listen(WorkerStarting::class, function (WorkerStarting $event): void {
            app(ArticleAiQualityWorkerLiveness::class)->record((string) $event->connectionName, (string) $event->queue);
        });
        Event::listen(Looping::class, function (Looping $event): void {
            app(ArticleAiQualityWorkerLiveness::class)->record((string) $event->connectionName, (string) $event->queue);
        });
        Event::listen(WorkerStopping::class, function (): void {
            app(ArticleAiQualityWorkerLiveness::class)->removeCurrentProcess();
        });
        RateLimiter::for('admin-login', function (Request $request): Limit {
            return Limit::perMinute(30)->by('admin-login-ip:'.$request->ip());
        });
        RateLimiter::for('admin-sensitive', function (Request $request): array {
            $adminId = (int) ($request->user('admin')?->getAuthIdentifier() ?? 0);

            return [
                Limit::perMinute(5)->by('admin-sensitive:admin:'.$adminId),
                Limit::perMinute(5)->by('admin-sensitive:admin-ip:'.$adminId.'|'.$request->ip()),
            ];
        });
        RateLimiter::for('api-ai-quality-manual', function (Request $request): array {
            $auth = $request->attributes->get('api_auth');
            $tokenId = $auth instanceof ApiAuthContext ? (int) ($auth->token['id'] ?? 0) : 0;
            $articleId = (int) $request->route('article');

            return [
                Limit::perMinute(5)->by('api-ai-quality:token:'.$tokenId),
                Limit::perHour(20)->by('api-ai-quality:token-hour:'.$tokenId),
                Limit::perHour(6)->by('api-ai-quality:article:'.$tokenId.'|'.$articleId),
                Limit::perHour(12)->by('api-ai-quality:article-global:'.$articleId),
                Limit::perMinute(10)->by('api-ai-quality:ip:'.$request->ip()),
            ];
        });
        RateLimiter::for('article-markdown-export-prepare', function (Request $request): array {
            $adminId = (int) ($request->user('admin')?->getAuthIdentifier() ?? 0);

            return [
                Limit::perHour(12)->by('article-export-prepare:admin:'.$adminId),
                Limit::perHour(120)->by('article-export-prepare:ip:'.$request->ip()),
            ];
        });
        RateLimiter::for('article-markdown-export-download', function (Request $request): array {
            $adminId = (int) ($request->user('admin')?->getAuthIdentifier() ?? 0);
            $tokenHash = hash('sha256', (string) $request->route('exportToken'));

            return [
                Limit::perMinutes(10, 4)->by('article-export-download:token:'.$adminId.'|'.$tokenHash),
                Limit::perMinutes(10, 12)->by('article-export-download:admin:'.$adminId),
                Limit::perMinutes(10, 30)->by('article-export-download:ip:'.$request->ip()),
            ];
        });
        RateLimiter::for('ai-workspace', function (Request $request): array {
            $adminId = (int) ($request->user('admin')?->getAuthIdentifier() ?? 0);

            return [
                Limit::perMinute(30)->by('ai-workspace:admin:'.$adminId),
                Limit::perMinute(60)->by('ai-workspace:ip:'.$request->ip()),
            ];
        });
        RateLimiter::for('ai-workspace-read', function (Request $request): array {
            $adminId = (int) ($request->user('admin')?->getAuthIdentifier() ?? 0);

            return [
                Limit::perMinute(120)->by('ai-workspace-read:admin:'.$adminId),
                Limit::perMinute(240)->by('ai-workspace-read:ip:'.$request->ip()),
            ];
        });
        RateLimiter::for('admin-recent-read', function (Request $request): array {
            $adminId = (int) ($request->user('admin')?->getAuthIdentifier() ?? 0);

            return [
                Limit::perMinute(60)->by('admin-recent-read:admin:'.$adminId),
                Limit::perMinute(120)->by('admin-recent-read:ip:'.$request->ip()),
            ];
        });
        RateLimiter::for('ai-workspace-messages', function (Request $request): array {
            $adminId = (int) ($request->user('admin')?->getAuthIdentifier() ?? 0);

            return [
                Limit::perMinute(6)->by('ai-workspace-messages:admin:'.$adminId),
                Limit::perMinute(12)->by('ai-workspace-messages:ip:'.$request->ip()),
            ];
        });
        RateLimiter::for('site-lead-submission', function (Request $request): Limit {
            $siteId = app(CurrentSite::class)->profileId() ?? 0;

            return Limit::perMinute(10)->by('site-lead:'.$siteId.'|'.$request->ip());
        });
        RateLimiter::for('title-generation', function (ProcessTitleGenerationBatchJob $job): Limit {
            return Limit::perMinute((int) config('geoflow.title_ai_rate_per_minute', 30))
                ->by('title-generation:model:'.$job->aiModelId);
        });
        RateLimiter::for('title-generation-submissions', function (Request $request): array {
            $adminId = (int) ($request->user('admin')?->getAuthIdentifier() ?? 0);

            return [
                Limit::perMinute((int) config('geoflow.title_ai_submit_rate_per_minute', 6))
                    ->by('title-generation-submit:admin:'.$adminId),
                Limit::perMinute((int) config('geoflow.title_ai_submit_ip_rate_per_minute', 12))
                    ->by('title-generation-submit:ip:'.$request->ip()),
            ];
        });

        $adminGuard = Auth::guard('admin');
        if (method_exists($adminGuard, 'setRememberDuration')) {
            $adminGuard->setRememberDuration(
                max(1, (int) config('geoflow.admin_remember_minutes', 43200))
            );
        }
        View::composer(['site.layout', 'theme.*.layout'], SiteLayoutComposer::class);

        View::composer('admin.layouts.app', function ($view): void {
            $admin = auth('admin')->user();
            $view->with(
                'adminWelcomeModalPayload',
                $admin instanceof Admin ? app(AdminWelcomeModalService::class)->buildModalPayload($admin) : null
            );
            $view->with(
                'adminUpdateNotificationPayload',
                $admin instanceof Admin ? app(AdminUpdateMetadataService::class)->buildNotificationPayload() : null
            );
            $view->with(
                'anonymousUsageTelemetryPayload',
                $admin instanceof Admin ? app(AnonymousUsageTelemetry::class)->payload($admin) : null
            );
            if ((bool) config('geoflow.admin_ui_v3_enabled', false) && $admin instanceof Admin) {
                $registry = app(AdminUiRegistry::class);
                $viewData = $view->getData();
                $routeName = request()->route()?->getName();
                $view->with('adminUiV3', [
                    'navigation' => $registry->navigation($admin),
                    'current' => $registry->currentPage(
                        $admin,
                        $routeName,
                        (string) ($viewData['activeMenu'] ?? '')
                    ),
                    'page_identity' => $registry->pageIdentity($routeName),
                    'settings_navigation' => $registry->settingsNavigation($admin, $routeName),
                    'show_settings_navigation' => $registry->activeKey($routeName) === 'site_settings'
                        && ! request()->routeIs('admin.account.*'),
                    'ai_configurator_navigation' => $registry->aiConfiguratorNavigation($routeName),
                    'show_ai_configurator_navigation' => $registry->activeKey($routeName) === 'ai_config',
                    'site_url' => (string) config('geoflow.site_url', config('app.url')),
                ]);
            }
        });
    }

    private function assertHostedSiteConfiguration(): void
    {
        if (! $this->app->environment('production')
            || ! config('geoflow.hosted_sites.enabled', false)) {
            return;
        }

        $errors = array_values(array_map(
            'strval',
            (array) config('geoflow.hosted_sites.configuration_errors', [])
        ));
        $primaryHosts = (array) config('geoflow.hosted_sites.primary_hosts', []);
        $rootDomains = (array) config('geoflow.hosted_sites.root_domains', []);
        if ($primaryHosts === []) {
            $errors[] = 'At least one exact primary host is required.';
        }
        if (count($rootDomains) !== 1) {
            $errors[] = 'Phase one requires exactly one hosted root domain.';
        }
        if (! in_array(config('session.domain'), [null, ''], true)) {
            $errors[] = 'SESSION_DOMAIN must be null so sessions remain Host-only.';
        }

        $appUrl = (string) config('app.url');
        $appHost = strtolower((string) parse_url($appUrl, PHP_URL_HOST));
        $appScheme = strtolower((string) parse_url($appUrl, PHP_URL_SCHEME));
        $appPort = (int) (parse_url($appUrl, PHP_URL_PORT) ?: ($appScheme === 'https' ? 443 : 80));
        if ($appScheme !== 'https' || $appPort !== 443) {
            $errors[] = 'Phase one hosted sites require APP_URL to use HTTPS on port 443.';
        }
        if (! in_array($appHost, $primaryHosts, true)) {
            $errors[] = 'APP_URL host must be present in GEOFLOW_PRIMARY_HOSTS.';
        }
        if ((string) config('geoflow.hosted_sites.nginx_primary_host') !== $appHost) {
            $errors[] = 'GEOFLOW_NGINX_PRIMARY_HOST must match the APP_URL host.';
        }
        if ((string) config('geoflow.hosted_sites.nginx_root_domain') !== (string) ($rootDomains[0] ?? '')) {
            $errors[] = 'GEOFLOW_NGINX_HOSTED_ROOT_DOMAIN must match the hosted root domain.';
        }
        if ((string) config('geoflow.hosted_sites.nginx_public_scheme') !== $appScheme
            || (int) config('geoflow.hosted_sites.nginx_public_port') !== $appPort) {
            $errors[] = 'Nginx public scheme and port must match APP_URL.';
        }
        if (! config('geoflow.hosted_sites.network_preflight_enabled', false)) {
            $errors[] = 'Hosted site network preflight must be enabled in production.';
        }
        if (blank(config('trustedproxy.proxies'))) {
            $errors[] = 'TRUSTED_PROXIES must trust the immediate Nginx proxy.';
        }

        $reverbApp = (array) config('reverb.apps.apps.0', []);
        $reverbOptions = (array) ($reverbApp['options'] ?? []);
        $allowedOrigins = array_values(array_map('strval', (array) ($reverbApp['allowed_origins'] ?? [])));
        if ($allowedOrigins === []
            || in_array('*', $allowedOrigins, true)
            || ! in_array($appHost, $allowedOrigins, true)
            || array_diff($allowedOrigins, $primaryHosts) !== []
            || array_diff($primaryHosts, $allowedOrigins) !== []) {
            $errors[] = 'Reverb allowed origins must exactly match all primary hostnames.';
        }
        if (strtolower((string) ($reverbOptions['host'] ?? '')) !== $appHost
            || strtolower((string) ($reverbOptions['scheme'] ?? '')) !== $appScheme
            || (int) ($reverbOptions['port'] ?? 0) !== $appPort) {
            $errors[] = 'Reverb public host, scheme and port must match APP_URL.';
        }
        if ((int) config('reverb.servers.reverb.port') !== 18080
            || '/'.trim((string) config('reverb.servers.reverb.path'), '/') !== '/reverb') {
            $errors[] = 'Bundled Nginx requires Reverb server port 18080 and path /reverb.';
        }

        if ($errors !== []) {
            throw new LogicException('Invalid hosted site configuration: '.implode(' ', array_unique($errors)));
        }
    }
}
