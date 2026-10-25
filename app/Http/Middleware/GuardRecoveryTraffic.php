<?php

namespace App\Http\Middleware;

use App\Services\SystemUpdater\RecoveryState;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class GuardRecoveryTraffic
{
    private const READ_ROUTES = [
        'site.home', 'site.about', 'site.robots', 'site.sitemap', 'site.sitemap.shard',
        'site.archive', 'site.archive.month', 'site.category', 'site.article', 'site.article.resolve', 'site.lead-forms.show',
        'site.asset', 'site.asset.favicon', 'site.theme-revision.asset', 'pwa.launch',
        'admin.entry', 'admin.login', 'admin.dashboard', 'admin.system-updates.index',
        'admin.system-updates.runs.show', 'admin.system-updates.backups.show',
        'admin.system-updates.updater.console', 'admin.system-updates.updater.download',
        'api.v1.auth.session', 'api.v1.capabilities', 'api.v1.theme-preview', 'api.v1.theme-preview-asset',
        'api.v1.management.updater.status', 'api.v1.management.updater.recovery-points',
        'api.v1.management.updater.lookup', 'api.v1.management.updater.operation',
    ];

    /** Explicitly audited API reads; a new GET action is held until reviewed. */
    private const READ_ACTIONS = [
        'ManagementSiteController@index', 'ManagementSiteController@show',
        'ManagementOperationController@lookup', 'ManagementOperationController@show',
        'ThemeWorkspaceController@themes', 'ThemeWorkspaceController@contract',
        'ThemeWorkspaceController@show', 'ThemeWorkspaceController@file',
        'BrowserSessionController@show', 'TaskController@index', 'TaskController@show',
        'TaskController@jobs', 'JobController@show',
    ];

    private const CONTROL_ROUTES = [
        'admin.login.attempt', 'admin.logout', 'api.v1.auth.login', 'api.v1.auth.logout', 'api.v1.browser-session.logout',
        'admin.system-updates.updater.action-plan', 'admin.system-updates.updater.submit',
        'admin.system-updates.updater.plan', 'admin.system-updates.updater.switch-back',
        'admin.system-updates.updater.update', 'admin.system-updates.updater.backup', 'admin.system-updates.updater.rollback',
        'admin.system-updates.updater.prepare', 'admin.system-updates.updater.verify',
        'api.v1.management.updater.plans', 'api.v1.management.updater.submit',
    ];

    public function __construct(private readonly RecoveryState $state) {}

    public function handle(Request $request, Closure $next): Response
    {
        $state = $this->state->assertHttpReady();
        if ($state !== null && $state['phase'] === 'http_ready' && ! $this->allowedWhileHeld($request)) {
            // Read access and recovery controls remain available while restored work awaits reconciliation.
            $this->state->assertBackgroundReady();
        }

        return $next($request);
    }

    private function allowedWhileHeld(Request $request): bool
    {
        if (! $request->isMethodSafe()) {
            return $request->routeIs(...self::CONTROL_ROUTES);
        }
        if ($request->routeIs(...self::READ_ROUTES)) {
            return true;
        }

        return in_array($request->route()?->getActionName(), array_map(
            static fn (string $action): string => 'App\\Http\\Controllers\\Api\\V1\\'.$action,
            self::READ_ACTIONS,
        ), true);
    }
}
