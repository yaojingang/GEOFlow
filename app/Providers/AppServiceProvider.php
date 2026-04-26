<?php

namespace App\Providers;

use App\Models\Admin;
use App\Services\Admin\AdminUpdateMetadataService;
use App\Services\Admin\AdminWelcomeModalService;
use App\Services\GeoFlow\ArticleGeoFlowService;
use App\Services\GeoFlow\HorizonMetricsAdapter;
use App\Services\GeoFlow\JobQueueService;
use App\Services\GeoFlow\TaskLifecycleService;
use App\Services\GeoFlow\TaskMonitoringQueryService;
use App\View\Composers\SiteLayoutComposer;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Facades\View;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(JobQueueService::class);
        $this->app->singleton(HorizonMetricsAdapter::class);
        $this->app->singleton(TaskMonitoringQueryService::class);
        $this->app->singleton(TaskLifecycleService::class);
        $this->app->singleton(ArticleGeoFlowService::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->configureUrlGeneration();

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
        });
    }

    /**
     * Keep generated routes/assets aligned with the public deployment URL.
     *
     * This is required when GEOFlow is deployed behind a reverse proxy under a
     * subdirectory such as https://example.com/wiki. Without forcing the public
     * root URL, route() and asset() can fall back to /geo_admin/... and break
     * login redirects or form submissions.
     */
    public function configureUrlGeneration(): void
    {
        $appUrl = rtrim((string) config('app.url'), '/');

        if (! preg_match('#^https?://#i', $appUrl)) {
            return;
        }

        URL::forceRootUrl($appUrl);

        $scheme = parse_url($appUrl, PHP_URL_SCHEME);
        if ($scheme === 'https') {
            URL::forceScheme('https');
        }
    }
}
