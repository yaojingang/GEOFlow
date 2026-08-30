<?php

/**
 * Web 路由：前台与 Blade 管理后台（路径见 config/geoflow.admin_base_path，默认 geo_admin）。
 */

use App\Http\Controllers\Admin\AdminAccountController;
use App\Http\Controllers\Admin\AdminActivityLogController;
use App\Http\Controllers\Admin\AdminAuthController;
use App\Http\Controllers\Admin\AdminRecentActivityController;
use App\Http\Controllers\Admin\AdminUserController;
use App\Http\Controllers\Admin\AdminWelcomeController;
use App\Http\Controllers\Admin\AiModelController;
use App\Http\Controllers\Admin\AiPromptController;
use App\Http\Controllers\Admin\AiSourceProviderController;
use App\Http\Controllers\Admin\AiSpecialPromptController;
use App\Http\Controllers\Admin\AiVisibilityAnalyticsController;
use App\Http\Controllers\Admin\AiWorkspaceApiController;
use App\Http\Controllers\Admin\AiWorkspaceController;
use App\Http\Controllers\Admin\AiWorkspaceKnowledgeMediaController;
use App\Http\Controllers\Admin\AnalyticsController;
use App\Http\Controllers\Admin\ApiTokenController;
use App\Http\Controllers\Admin\ArticleAiOptimizationController;
use App\Http\Controllers\Admin\ArticleController;
use App\Http\Controllers\Admin\ArticleEditorAssetController;
use App\Http\Controllers\Admin\ArticleEditorAssistantController;
use App\Http\Controllers\Admin\AuthorController;
use App\Http\Controllers\Admin\BrowserClientController;
use App\Http\Controllers\Admin\BrowserConnectionApprovalController;
use App\Http\Controllers\Admin\CategoryController;
use App\Http\Controllers\Admin\ContentAnalyticsController;
use App\Http\Controllers\Admin\DashboardController;
use App\Http\Controllers\Admin\DistributionAnalyticsController;
use App\Http\Controllers\Admin\DistributionController;
use App\Http\Controllers\Admin\EnterpriseKnowledgeController;
use App\Http\Controllers\Admin\HostedSiteController;
use App\Http\Controllers\Admin\ImageLibraryController;
use App\Http\Controllers\Admin\KeywordLibraryController;
use App\Http\Controllers\Admin\KnowledgeBaseController;
use App\Http\Controllers\Admin\KnowledgeBaseMediaController;
use App\Http\Controllers\Admin\KnowledgeFactController;
use App\Http\Controllers\Admin\KnowledgeFactGenerationController;
use App\Http\Controllers\Admin\LeadAnalyticsController;
use App\Http\Controllers\Admin\LeadController;
use App\Http\Controllers\Admin\LeadFormController;
use App\Http\Controllers\Admin\LegacyController;
use App\Http\Controllers\Admin\LegacySystemUpdateHistoryController;
use App\Http\Controllers\Admin\ManualPublicationController;
use App\Http\Controllers\Admin\ManualPublicationSettingsController;
use App\Http\Controllers\Admin\MaterialsController;
use App\Http\Controllers\Admin\SecuritySettingsController;
use App\Http\Controllers\Admin\SiteSettingsController;
use App\Http\Controllers\Admin\SiteThemeReplicationController;
use App\Http\Controllers\Admin\SystemUpdateController;
use App\Http\Controllers\Admin\SystemUpdaterOperationController;
use App\Http\Controllers\Admin\TaskController;
use App\Http\Controllers\Admin\TitleLibraryController;
use App\Http\Controllers\Admin\TrafficAnalyticsController;
use App\Http\Controllers\Admin\UrlImportController;
use App\Http\Controllers\Site\AboutController;
use App\Http\Controllers\Site\ArchiveController;
use App\Http\Controllers\Site\ArticleController as SiteArticleController;
use App\Http\Controllers\Site\CategoryController as SiteCategoryController;
use App\Http\Controllers\Site\HomeController;
use App\Http\Controllers\Site\HostedAssetController;
use App\Http\Controllers\Site\LeadFormController as SiteLeadFormController;
use App\Http\Controllers\Site\SiteDiscoveryController;
use App\Support\AdminUiRegistry;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;

Route::get('/favicon.ico', HostedAssetController::class)->name('site.asset.favicon');
Route::get('/{assetPath}', HostedAssetController::class)
    ->where('assetPath', '(?:(?:assets|js|storage|themes)/[a-zA-Z0-9._/-]+|build/assets/[a-zA-Z0-9._-]+)')
    ->name('site.asset');

Route::get('/app', function () {
    return Auth::guard('admin')->check()
        ? redirect()->route('admin.dashboard')
        : redirect()->route('admin.login');
})->name('pwa.launch');

Route::middleware(['site.locale', 'site.view_log'])->group(function (): void {
    Route::get('/', [HomeController::class, 'index'])->name('site.home');
    Route::get('/about', [AboutController::class, 'index'])->name('site.about');
    Route::get('/robots.txt', [SiteDiscoveryController::class, 'robots'])->name('site.robots');
    Route::get('/sitemap.xml', [SiteDiscoveryController::class, 'sitemap'])->name('site.sitemap');
    Route::get('/sitemaps/pages-{page}.xml', [SiteDiscoveryController::class, 'sitemapShard'])
        ->whereNumber('page')
        ->name('site.sitemap.shard');
    Route::get('/archive', [ArchiveController::class, 'index'])->name('site.archive');
    Route::get('/archive/{year}/{month}', [ArchiveController::class, 'month'])
        ->name('site.archive.month')
        ->where(['year' => '[0-9]{4}', 'month' => '[0-9]{2}']);
    Route::get('/category/{slug}', [SiteCategoryController::class, 'show'])->name('site.category');
    Route::get('/article/{slug}', [SiteArticleController::class, 'show'])->name('site.article');
    Route::get('/forms/{slug}', [SiteLeadFormController::class, 'show'])->name('site.lead-forms.show');
    Route::post('/forms/{slug}/submissions', [SiteLeadFormController::class, 'submit'])
        ->middleware('throttle:site-lead-submission')
        ->name('site.lead-forms.submit');
});

$adminPrefix = trim((string) config('geoflow.admin_base_path', '/geo_admin'), '/');

Route::prefix($adminPrefix)->name('admin.')->middleware(['admin.locale'])->group(function () {
    // 通用入口与语言切换
    Route::get('locale/{locale}', [AdminAuthController::class, 'switchLocale'])->name('locale.switch');

    Route::get('/', function () {
        return Auth::guard('admin')->check()
            ? redirect()->route('admin.dashboard')
            : redirect()->route('admin.login');
    })->name('entry');

    // 访客认证路由
    Route::middleware('guest:admin')->group(function () {
        Route::get('login', [AdminAuthController::class, 'showLoginForm'])->name('login');
        Route::post('login', [AdminAuthController::class, 'login'])
            ->middleware('throttle:admin-login')
            ->name('login.attempt');
    });

    // 后台受保护路由
    Route::middleware(['admin.auth', 'admin.activity', 'admin.recent'])->group(function () {
        // 会话与首页
        Route::post('logout', [AdminAuthController::class, 'logout'])->name('logout');
        Route::post('welcome/dismiss', [AdminWelcomeController::class, 'dismiss'])->name('welcome.dismiss');
        Route::middleware('admin.ui-v3')->group(function (): void {
            Route::get('recent', AdminRecentActivityController::class)
                ->middleware('throttle:admin-recent-read')
                ->name('recent.index');
            Route::get('ai-workspace', AiWorkspaceController::class)->name('ai-workspace');
            Route::prefix('ai-workspace')->name('ai-workspace.')->group(function (): void {
                Route::middleware('throttle:ai-workspace-read')->group(function (): void {
                    Route::get('conversations', [AiWorkspaceApiController::class, 'conversations'])->name('conversations.index');
                    Route::get('conversations/{conversation}', [AiWorkspaceApiController::class, 'showConversation'])->name('conversations.show');
                    Route::get('media/{mediaAsset}', AiWorkspaceKnowledgeMediaController::class)
                        ->whereNumber('mediaAsset')
                        ->name('media.show');
                });
                Route::post('conversations/{conversation}/archive', [AiWorkspaceApiController::class, 'archiveConversation'])->middleware('throttle:ai-workspace')->name('conversations.archive');
                Route::patch('conversations/{conversation}', [AiWorkspaceApiController::class, 'renameConversation'])->middleware('throttle:ai-workspace')->name('conversations.update');
                Route::post('conversations', [AiWorkspaceApiController::class, 'storeConversation'])->middleware('throttle:ai-workspace')->name('conversations.store');
                Route::post('conversations/{conversation}/messages', [AiWorkspaceApiController::class, 'sendMessage'])->middleware('throttle:ai-workspace-messages')->name('messages.store');
            });
            Route::prefix('account')->name('account.')->group(function (): void {
                Route::get('/', [AdminAccountController::class, 'show'])->name('show');
                Route::put('profile', [AdminAccountController::class, 'updateProfile'])->name('profile.update');
                Route::put('password', [AdminAccountController::class, 'updatePassword'])
                    ->middleware('throttle:admin-sensitive')
                    ->name('password.update');
            });
        });
        Route::get('dashboard', [DashboardController::class, 'index'])->name('dashboard');
        Route::prefix('account/browser-clients')->name('account.browser-clients.')->group(function (): void {
            Route::get('/', [BrowserClientController::class, 'index'])->name('index');
            Route::delete('{tokenId}', [BrowserClientController::class, 'destroy'])
                ->middleware('throttle:admin-sensitive')
                ->whereNumber('tokenId')
                ->name('destroy');
        });
        Route::get('analytics', [AnalyticsController::class, 'index'])->name('analytics');
        Route::prefix('analytics')->name('analytics.')->group(function (): void {
            Route::get('content', ContentAnalyticsController::class)->name('content');
            Route::get('traffic', TrafficAnalyticsController::class)->name('traffic');
            Route::get('ai-visibility', AiVisibilityAnalyticsController::class)->name('ai-visibility');
            Route::get('leads', LeadAnalyticsController::class)->name('leads');
            Route::get('distribution', DistributionAnalyticsController::class)
                ->middleware('admin.super')
                ->name('distribution');
        });

        Route::prefix('system-updates')->name('system-updates.')->middleware('admin.super')->group(function () {
            Route::get('/', [SystemUpdateController::class, 'index'])->name('index');
            Route::post('updater/prepare', [SystemUpdaterOperationController::class, 'prepare'])
                ->middleware('throttle:admin-sensitive')
                ->name('updater.prepare');
            Route::get('updater/download', [SystemUpdaterOperationController::class, 'download'])
                ->middleware('throttle:admin-sensitive')
                ->name('updater.download');
            Route::post('updater/update', [SystemUpdaterOperationController::class, 'update'])
                ->middleware('throttle:admin-sensitive')
                ->name('updater.update');
            Route::post('updater/backup', [SystemUpdaterOperationController::class, 'backup'])
                ->middleware('throttle:admin-sensitive')
                ->name('updater.backup');
            Route::post('updater/rollback', [SystemUpdaterOperationController::class, 'rollback'])
                ->middleware('throttle:admin-sensitive')
                ->name('updater.rollback');
            Route::post('updater/verify', [SystemUpdaterOperationController::class, 'verify'])
                ->middleware('throttle:admin-sensitive')
                ->name('updater.verify');
            Route::post('check', [SystemUpdateController::class, 'check'])
                ->middleware('throttle:admin-sensitive')
                ->name('check');
            Route::get('runs/{runUuid}', [LegacySystemUpdateHistoryController::class, 'run'])->name('runs.show');
            Route::get('backups/{backupUuid}', [LegacySystemUpdateHistoryController::class, 'backup'])->name('backups.show');
        });

        Route::prefix('lead-forms')->name('lead-forms.')->group(function () {
            Route::get('/', [LeadFormController::class, 'index'])->name('index');
            Route::get('create', [LeadFormController::class, 'create'])->name('create');
            Route::post('/', [LeadFormController::class, 'store'])->name('store');
            Route::get('{formId}/edit', [LeadFormController::class, 'edit'])->name('edit')->whereNumber('formId');
            Route::put('{formId}', [LeadFormController::class, 'update'])->name('update')->whereNumber('formId');
            Route::post('{formId}/toggle-status', [LeadFormController::class, 'toggleStatus'])->name('toggle-status')->whereNumber('formId');
            Route::post('{formId}/delete', [LeadFormController::class, 'destroy'])->name('delete')->whereNumber('formId');
        });
        Route::prefix('leads')->name('leads.')->group(function () {
            Route::get('/', [LeadController::class, 'index'])->name('index');
            Route::get('export', [LeadController::class, 'export'])->name('export');
            Route::get('{submissionId}', [LeadController::class, 'show'])->name('show')->whereNumber('submissionId');
            Route::put('{submissionId}', [LeadController::class, 'update'])->name('update')->whereNumber('submissionId');
        });

        // 任务管理（Blade 新路径）
        Route::prefix('tasks')->name('tasks.')->group(function () {
            Route::get('/', [TaskController::class, 'index'])->name('index');
            Route::get('workers', [TaskController::class, 'workers'])->name('workers');
            Route::get('jobs', [TaskController::class, 'jobs'])->name('jobs');
            Route::post('title-readiness', [TaskController::class, 'titleReadiness'])->name('title-readiness');
            Route::post('{taskId}/toggle-status', [TaskController::class, 'toggleStatus'])->name('toggle-status');
            Route::post('{taskId}/delete', [TaskController::class, 'destroyTask'])->name('delete');
            Route::post('{taskId}/restore', [TaskController::class, 'restoreTask'])->whereNumber('taskId')->name('restore');
            Route::get('create', [TaskController::class, 'create'])->name('create');
            Route::post('create', [TaskController::class, 'store'])->name('store');
            Route::get('{taskId}/edit', [TaskController::class, 'edit'])->name('edit');
            Route::put('{taskId}', [TaskController::class, 'update'])->name('update');
            Route::get('health-check', [TaskController::class, 'healthCheck'])->name('health');
            Route::post('batch/start', [TaskController::class, 'batchAction'])->name('batch');
        });

        // 分发管理：集中管理外部站点 Agent 与文章分发队列
        Route::prefix('distribution')->name('distribution.')->middleware('admin.super')->group(function () {
            Route::get('/', [DistributionController::class, 'index'])->name('index');
            Route::get('create', [DistributionController::class, 'create'])->name('create');
            Route::post('create', [DistributionController::class, 'store'])->name('store');
            Route::prefix('hosted-sites')->name('hosted-sites.')->middleware('hosted-sites.enabled')->scopeBindings()->group(function (): void {
                Route::get('/', [HostedSiteController::class, 'index'])->name('index');
                Route::get('create', [HostedSiteController::class, 'create'])->name('create');
                Route::post('/', [HostedSiteController::class, 'store'])->name('store');
                Route::get('{hostedSite}', [HostedSiteController::class, 'show'])->name('show');
                Route::get('{hostedSite}/edit', [HostedSiteController::class, 'edit'])->name('edit');
                Route::put('{hostedSite}', [HostedSiteController::class, 'update'])->name('update');
                Route::post('{hostedSite}/preflight', [HostedSiteController::class, 'preflight'])->name('preflight');
                Route::post('{hostedSite}/activate', [HostedSiteController::class, 'activate'])->name('activate');
                Route::post('{hostedSite}/pause', [HostedSiteController::class, 'pause'])->name('pause');
                Route::post('{hostedSite}/maintenance', [HostedSiteController::class, 'maintenance'])->name('maintenance');
                Route::post('{hostedSite}/indexing', [HostedSiteController::class, 'indexing'])->name('indexing');
                Route::post('{hostedSite}/archive', [HostedSiteController::class, 'archive'])->name('archive');
                Route::post('{hostedSite}/articles', [HostedSiteController::class, 'assignArticle'])->name('articles.assign');
            })->whereNumber('hostedSite');
            Route::get('jobs', [DistributionController::class, 'jobs'])->name('jobs');
            Route::get('sync-settings-all/preview', [DistributionController::class, 'previewSyncSettingsAll'])->name('sync-settings-all.preview');
            Route::post('sync-settings-all', [DistributionController::class, 'syncSettingsAll'])->name('sync-settings-all');
            Route::post('sync-settings-selected/preview', [DistributionController::class, 'previewSyncSettingsSelected'])->name('sync-settings-selected.preview');
            Route::post('sync-settings-selected', [DistributionController::class, 'syncSettingsSelected'])->name('sync-settings-selected');
            Route::get('jobs/{distributionId}/edit', [DistributionController::class, 'editArticle'])->name('article.edit')->whereNumber('distributionId');
            Route::put('jobs/{distributionId}', [DistributionController::class, 'updateArticle'])->name('article.update')->whereNumber('distributionId');
            Route::post('jobs/{distributionId}/delete', [DistributionController::class, 'deleteArticle'])->name('article.delete')->whereNumber('distributionId');
            Route::post('jobs/{distributionId}/retry', [DistributionController::class, 'retry'])->name('retry')->whereNumber('distributionId');
            Route::get('{channelId}/delete', [DistributionController::class, 'deletePreview'])->middleware('admin.super')->name('delete')->whereNumber('channelId');
            Route::post('{channelId}/delete/prepare', [DistributionController::class, 'prepareDelete'])->middleware('admin.super')->name('delete.prepare')->whereNumber('channelId');
            Route::post('{channelId}/delete/cancel', [DistributionController::class, 'cancelDelete'])->middleware('admin.super')->name('delete.cancel')->whereNumber('channelId');
            Route::delete('{channelId}', [DistributionController::class, 'destroy'])->middleware(['admin.super', 'throttle:admin-sensitive'])->name('destroy')->whereNumber('channelId');
            Route::get('{channelId}/edit', [DistributionController::class, 'edit'])->name('edit')->whereNumber('channelId');
            Route::put('{channelId}', [DistributionController::class, 'update'])->name('update')->whereNumber('channelId');
            Route::post('{channelId}/pause', [DistributionController::class, 'pause'])->name('pause')->whereNumber('channelId');
            Route::post('{channelId}/activate', [DistributionController::class, 'activate'])->name('activate')->whereNumber('channelId');
            Route::post('{channelId}/rotate-secret', [DistributionController::class, 'rotateSecret'])->name('rotate-secret')->whereNumber('channelId');
            Route::post('{channelId}/reveal-secret', [DistributionController::class, 'revealSecret'])->name('reveal-secret')->whereNumber('channelId');
            Route::post('{channelId}/download-package', [DistributionController::class, 'downloadPackage'])->name('download-package')->whereNumber('channelId');
            Route::post('{channelId}/frontend-capabilities/refresh', [DistributionController::class, 'refreshFrontendCapabilities'])->name('frontend-capabilities.refresh')->whereNumber('channelId');
            Route::get('{channelId}/sync-settings/preview', [DistributionController::class, 'previewSyncSettings'])->name('sync-settings.preview')->whereNumber('channelId');
            Route::post('{channelId}/sync-settings', [DistributionController::class, 'syncSettings'])->name('sync-settings')->whereNumber('channelId');
            Route::get('{channelId}', [DistributionController::class, 'show'])->name('show')->whereNumber('channelId');
            Route::post('{channelId}/health', [DistributionController::class, 'health'])->name('health')->whereNumber('channelId');
        });

        // 文章管理（Blade 新路径）
        Route::prefix('articles')->name('articles.')->group(function () {
            Route::get('/', [ArticleController::class, 'index'])->name('index');
            Route::post('batch/update-status', [ArticleController::class, 'batchUpdateStatus'])->name('batch.update-status');
            Route::post('batch/update-review', [ArticleController::class, 'batchUpdateReview'])->name('batch.update-review');
            Route::post('batch/delete', [ArticleController::class, 'batchDelete'])->name('batch.delete');
            Route::post('batch/export-markdown/prepare', [ArticleController::class, 'prepareMarkdownExport'])
                ->middleware('throttle:article-markdown-export-prepare')
                ->name('batch.export-markdown.prepare');
            Route::get('batch/export-markdown/download/{exportToken}', [ArticleController::class, 'downloadMarkdownExport'])
                ->middleware(['signed:relative', 'throttle:article-markdown-export-download'])
                ->where('exportToken', '[A-Za-z0-9]{40}')
                ->name('batch.export-markdown.download');
            Route::post('batch/restore', [ArticleController::class, 'batchRestore'])->name('batch.restore');
            Route::post('batch/force-delete', [ArticleController::class, 'batchForceDelete'])
                ->middleware('throttle:admin-sensitive')
                ->name('batch.force-delete');
            Route::post('trash/empty', [ArticleController::class, 'emptyTrash'])
                ->middleware('throttle:admin-sensitive')
                ->name('trash.empty');
            Route::post('editor/wechat-html', [ArticleEditorAssetController::class, 'exportWeChatHtml'])->name('editor.wechat-html');
            Route::get('editor/titles', [ArticleEditorAssistantController::class, 'titles'])->name('editor.titles');
            Route::post('editor/generate', [ArticleEditorAssistantController::class, 'generate'])->middleware('throttle:10,1')->name('editor.generate');
            Route::get('create', [ArticleController::class, 'create'])->name('create');
            Route::post('create', [ArticleController::class, 'store'])->name('store');
            Route::post('{articleId}/restore', [ArticleController::class, 'restore'])->name('restore')->whereNumber('articleId');
            Route::post('{articleId}/force-delete', [ArticleController::class, 'forceDelete'])
                ->middleware('throttle:admin-sensitive')
                ->name('force-delete')
                ->whereNumber('articleId');
            Route::get('{articleId}/edit', [ArticleController::class, 'edit'])->name('edit');
            Route::post('{articleId}/risk-scan', [ArticleController::class, 'recheckRisk'])->name('risk-scan')->whereNumber('articleId');
            Route::post('{articleId}/ai-quality/recheck', [ArticleController::class, 'recheckAiQuality'])
                ->middleware('throttle:admin-sensitive')
                ->name('ai-quality.recheck')
                ->whereNumber('articleId');
            Route::get('{articleId}/ai-quality/status', [ArticleController::class, 'aiQualityStatus'])
                ->middleware('throttle:120,1')
                ->name('ai-quality.status')
                ->whereNumber('articleId');
            Route::post('{articleId}/ai-quality/optimization', [ArticleAiOptimizationController::class, 'store'])
                ->middleware('throttle:admin-sensitive')
                ->name('ai-quality.optimization.store')
                ->whereNumber('articleId');
            Route::get('{articleId}/ai-quality/optimization/{runId}/candidate', [ArticleAiOptimizationController::class, 'candidate'])
                ->middleware('throttle:120,1')
                ->name('ai-quality.optimization.candidate')
                ->whereNumber(['articleId', 'runId']);
            Route::post('{articleId}/ai-quality/optimization/{runId}/apply', [ArticleAiOptimizationController::class, 'apply'])
                ->middleware('throttle:admin-sensitive')
                ->name('ai-quality.optimization.apply')
                ->whereNumber(['articleId', 'runId']);
            Route::post('{articleId}/ai-quality/optimization/{runId}/cancel', [ArticleAiOptimizationController::class, 'cancel'])
                ->middleware('throttle:admin-sensitive')
                ->name('ai-quality.optimization.cancel')
                ->whereNumber(['articleId', 'runId']);
            Route::post('{articleId}/ai-quality/optimization/{runId}/rollback', [ArticleAiOptimizationController::class, 'rollback'])
                ->middleware('throttle:admin-sensitive')
                ->name('ai-quality.optimization.rollback')
                ->whereNumber(['articleId', 'runId']);
            Route::post('{articleId}/ai-quality/workflow-retry', [ArticleController::class, 'retryAiQualityWorkflow'])
                ->middleware('throttle:admin-sensitive')
                ->name('ai-quality.workflow-retry')
                ->whereNumber('articleId');
            Route::post('{articleId}/ai-quality/override', [ArticleController::class, 'overrideAiQuality'])->name('ai-quality.override')->whereNumber('articleId');
            Route::post('{articleId}/editor/images/upload', [ArticleEditorAssetController::class, 'uploadImage'])->name('editor.images.upload')->whereNumber('articleId');
            Route::put('{articleId}', [ArticleController::class, 'update'])->name('update');
        });

        Route::prefix('manual-publications')->name('manual-publications.')->group(function () {
            Route::get('browser-connect', [BrowserConnectionApprovalController::class, 'show'])->name('browser-connect.show');
            Route::post('browser-connect/decision', [BrowserConnectionApprovalController::class, 'decision'])
                ->middleware('throttle:admin-sensitive')
                ->name('browser-connect.decision');
            Route::get('/', [ManualPublicationController::class, 'index'])->name('index');
            Route::get('export', [ManualPublicationController::class, 'export'])->name('export');
            Route::get('create', [ManualPublicationController::class, 'create'])->name('create');
            Route::post('/', [ManualPublicationController::class, 'store'])->name('store');
            Route::middleware('admin.super')->prefix('settings')->name('settings.')->group(function () {
                Route::get('/', [ManualPublicationSettingsController::class, 'index'])->name('index');
                Route::post('personas', [ManualPublicationSettingsController::class, 'storePersona'])->name('personas.store');
                Route::put('personas/{personaId}', [ManualPublicationSettingsController::class, 'updatePersona'])->name('personas.update')->whereNumber('personaId');
                Route::post('accounts', [ManualPublicationSettingsController::class, 'storeAccount'])->name('accounts.store');
                Route::put('accounts/{accountId}', [ManualPublicationSettingsController::class, 'updateAccount'])->name('accounts.update')->whereNumber('accountId');
            });
            Route::get('{manualPublicationId}', [ManualPublicationController::class, 'show'])->name('show')->whereNumber('manualPublicationId');
            Route::get('{manualPublicationId}/edit', [ManualPublicationController::class, 'edit'])->name('edit')->whereNumber('manualPublicationId');
            Route::put('{manualPublicationId}', [ManualPublicationController::class, 'update'])->name('update')->whereNumber('manualPublicationId');
            Route::post('{manualPublicationId}/transition', [ManualPublicationController::class, 'transition'])->name('transition')->whereNumber('manualPublicationId');
        });

        // 栏目管理（保持 geo_admin/categories 路径语义）
        Route::prefix('categories')->name('categories.')->group(function () {
            Route::get('/', [CategoryController::class, 'index'])->name('index');
            Route::get('create', [CategoryController::class, 'create'])->name('create');
            Route::post('create', [CategoryController::class, 'store'])->name('store');
            Route::get('{categoryId}/edit', [CategoryController::class, 'edit'])->name('edit');
            Route::put('{categoryId}', [CategoryController::class, 'update'])->name('update');
            Route::post('{categoryId}/delete', [CategoryController::class, 'destroy'])->name('delete');
        });

        // 素材管理：作者管理
        Route::prefix('authors')
            ->name('authors.')
            ->where(['authorId' => '[1-9][0-9]{0,17}'])
            ->group(function () {
                Route::get('/', [AuthorController::class, 'index'])->name('index');
                Route::get('create', [AuthorController::class, 'create'])->name('create');
                Route::post('create', [AuthorController::class, 'store'])->name('store');
                Route::get('{authorId}/edit', [AuthorController::class, 'edit'])->name('edit');
                Route::get('{authorId}/detail', [AuthorController::class, 'detail'])->name('detail');
                Route::put('{authorId}', [AuthorController::class, 'update'])->name('update');
                Route::post('{authorId}/delete', [AuthorController::class, 'destroy'])->name('delete');
            });

        // 素材管理：关键词库管理
        Route::prefix('keyword-libraries')
            ->name('keyword-libraries.')
            ->where(['libraryId' => '[1-9][0-9]{0,17}'])
            ->group(function () {
                Route::get('/', [KeywordLibraryController::class, 'index'])->name('index');
                Route::get('create', [KeywordLibraryController::class, 'create'])->name('create');
                Route::post('create', [KeywordLibraryController::class, 'store'])->name('store');
                Route::get('{libraryId}/edit', [KeywordLibraryController::class, 'edit'])->name('edit');
                Route::get('{libraryId}/detail', [KeywordLibraryController::class, 'detail'])->name('detail');
                Route::get('{libraryId}/keywords/create', [KeywordLibraryController::class, 'createKeyword'])->name('keywords.create');
                Route::post('{libraryId}/keywords', [KeywordLibraryController::class, 'storeKeyword'])->name('keywords.store');
                Route::post('{libraryId}/keywords/delete', [KeywordLibraryController::class, 'destroyKeywords'])->name('keywords.delete');
                Route::get('{libraryId}/import', [KeywordLibraryController::class, 'createImport'])->name('import.create');
                Route::post('{libraryId}/import', [KeywordLibraryController::class, 'importKeywords'])->name('import');
                Route::put('{libraryId}/detail', [KeywordLibraryController::class, 'updateFromDetail'])->name('detail.update');
                Route::put('{libraryId}', [KeywordLibraryController::class, 'update'])->name('update');
                Route::post('{libraryId}/delete', [KeywordLibraryController::class, 'destroy'])->name('delete');
            });

        // 素材管理：标题库管理
        Route::prefix('title-libraries')
            ->name('title-libraries.')
            ->where([
                'libraryId' => '[1-9][0-9]{0,17}',
                'runId' => '[1-9][0-9]{0,17}',
            ])
            ->group(function () {
                Route::get('/', [TitleLibraryController::class, 'index'])->name('index');
                Route::get('create', [TitleLibraryController::class, 'create'])->name('create');
                Route::post('create', [TitleLibraryController::class, 'store'])->name('store');
                Route::get('{libraryId}/edit', [TitleLibraryController::class, 'edit'])->name('edit');
                Route::get('{libraryId}/detail', [TitleLibraryController::class, 'detail'])->name('detail');
                Route::get('{libraryId}/titles/create', [TitleLibraryController::class, 'createTitle'])->name('titles.create');
                Route::post('{libraryId}/titles', [TitleLibraryController::class, 'storeTitle'])->name('titles.store');
                Route::post('{libraryId}/titles/delete', [TitleLibraryController::class, 'destroyTitles'])->name('titles.delete');
                Route::get('{libraryId}/import', [TitleLibraryController::class, 'createImport'])->name('import.create');
                Route::post('{libraryId}/import', [TitleLibraryController::class, 'importTitles'])->name('import');
                Route::get('{libraryId}/ai-generate', [TitleLibraryController::class, 'aiGenerate'])->name('ai-generate');
                Route::post('{libraryId}/ai-generate', [TitleLibraryController::class, 'generateWithAi'])
                    ->middleware('throttle:title-generation-submissions')
                    ->name('ai-generate.submit');
                Route::get('{libraryId}/ai-generation-runs/{runId}/status', [TitleLibraryController::class, 'generationStatus'])
                    ->name('ai-generate.status');
                Route::post('{libraryId}/ai-generation-runs/{runId}/retry', [TitleLibraryController::class, 'retryGeneration'])
                    ->middleware('throttle:title-generation-submissions')
                    ->name('ai-generate.retry');
                Route::post('{libraryId}/ai-generation-runs/{runId}/cancel', [TitleLibraryController::class, 'cancelGeneration'])
                    ->name('ai-generate.cancel');
                Route::put('{libraryId}', [TitleLibraryController::class, 'update'])->name('update');
                Route::post('{libraryId}/delete', [TitleLibraryController::class, 'destroy'])->name('delete');
            });

        // 素材管理：图片库管理
        Route::prefix('image-libraries')
            ->name('image-libraries.')
            ->where(['libraryId' => '[1-9][0-9]{0,17}'])
            ->group(function () {
                Route::get('/', [ImageLibraryController::class, 'index'])->name('index');
                Route::get('create', [ImageLibraryController::class, 'create'])->name('create');
                Route::post('create', [ImageLibraryController::class, 'store'])->name('store');
                Route::get('{libraryId}/edit', [ImageLibraryController::class, 'edit'])->name('edit');
                Route::get('{libraryId}/detail', [ImageLibraryController::class, 'detail'])->name('detail');
                Route::get('{libraryId}/images/upload', [ImageLibraryController::class, 'createImageUpload'])->name('images.create');
                Route::post('{libraryId}/images/upload', [ImageLibraryController::class, 'uploadImages'])->name('images.upload');
                Route::post('{libraryId}/images/delete', [ImageLibraryController::class, 'destroyImages'])->name('images.delete');
                Route::put('{libraryId}/detail', [ImageLibraryController::class, 'updateFromDetail'])->name('detail.update');
                Route::put('{libraryId}', [ImageLibraryController::class, 'update'])->name('update');
                Route::post('{libraryId}/delete', [ImageLibraryController::class, 'destroy'])->name('delete');
            });

        // 素材管理：知识库管理
        Route::prefix('knowledge-bases')->name('knowledge-bases.')->group(function () {
            Route::get('/', [KnowledgeBaseController::class, 'index'])->name('index');
            Route::get('create', [KnowledgeBaseController::class, 'create'])->name('create');
            Route::post('create', [KnowledgeBaseController::class, 'store'])->name('store');
            Route::get('{knowledgeBaseId}/edit', [KnowledgeBaseController::class, 'edit'])->name('edit');
            Route::get('{knowledgeBaseId}/detail', [KnowledgeBaseController::class, 'detail'])->name('detail');
            Route::post('upload', [KnowledgeBaseController::class, 'uploadFile'])->name('upload');
            Route::post('{knowledgeBaseId}/chunks/refresh', [KnowledgeBaseController::class, 'refreshChunks'])->name('chunks.refresh');
            Route::post('{knowledgeBaseId}/revisions/{revisionId}/restore', [KnowledgeBaseController::class, 'restoreRevision'])
                ->name('revisions.restore')
                ->whereNumber(['knowledgeBaseId', 'revisionId']);
            Route::post('{knowledgeBaseId}/official/adopt', [KnowledgeBaseController::class, 'adoptOfficial'])
                ->name('official.adopt')
                ->whereNumber('knowledgeBaseId');
            Route::post('{knowledgeBaseId}/media', [KnowledgeBaseMediaController::class, 'store'])
                ->name('media.store')->whereNumber('knowledgeBaseId');
            Route::put('{knowledgeBaseId}/media/{mediaAsset}', [KnowledgeBaseMediaController::class, 'update'])
                ->name('media.update')->whereNumber(['knowledgeBaseId', 'mediaAsset']);
            Route::post('{knowledgeBaseId}/media/{mediaAsset}/replace', [KnowledgeBaseMediaController::class, 'replace'])
                ->name('media.replace')->whereNumber(['knowledgeBaseId', 'mediaAsset']);
            Route::post('{knowledgeBaseId}/media/{mediaAsset}/toggle', [KnowledgeBaseMediaController::class, 'toggle'])
                ->name('media.toggle')->whereNumber(['knowledgeBaseId', 'mediaAsset']);
            Route::post('{knowledgeBaseId}/facts', [KnowledgeFactController::class, 'store'])->name('facts.store')->whereNumber('knowledgeBaseId');
            Route::put('{knowledgeBaseId}/facts/{factId}', [KnowledgeFactController::class, 'update'])->name('facts.update')->whereNumber(['knowledgeBaseId', 'factId']);
            Route::post('{knowledgeBaseId}/facts/{factId}/review', [KnowledgeFactController::class, 'review'])->name('facts.review')->whereNumber(['knowledgeBaseId', 'factId']);
            Route::post('{knowledgeBaseId}/facts/{factId}/archive', [KnowledgeFactController::class, 'archive'])->name('facts.archive')->whereNumber(['knowledgeBaseId', 'factId']);
            Route::post('{knowledgeBaseId}/facts/{factId}/values', [KnowledgeFactController::class, 'storeValue'])->name('fact-values.store')->whereNumber(['knowledgeBaseId', 'factId']);
            Route::put('{knowledgeBaseId}/fact-values/{valueId}', [KnowledgeFactController::class, 'updateValue'])->name('fact-values.update')->whereNumber(['knowledgeBaseId', 'valueId']);
            Route::post('{knowledgeBaseId}/fact-values/{valueId}/archive', [KnowledgeFactController::class, 'archiveValue'])->name('fact-values.archive')->whereNumber(['knowledgeBaseId', 'valueId']);
            Route::post('{knowledgeBaseId}/fact-values/{valueId}/evidences', [KnowledgeFactController::class, 'storeEvidence'])->name('fact-evidences.store')->whereNumber(['knowledgeBaseId', 'valueId']);
            Route::post('{knowledgeBaseId}/facts/{factId}/merge', [KnowledgeFactController::class, 'merge'])->name('facts.merge')->whereNumber(['knowledgeBaseId', 'factId']);
            Route::post('{knowledgeBaseId}/facts/{factId}/split', [KnowledgeFactController::class, 'split'])->name('facts.split')->whereNumber(['knowledgeBaseId', 'factId']);
            Route::post('{knowledgeBaseId}/facts/publish', [KnowledgeFactController::class, 'publish'])->name('facts.publish')->whereNumber('knowledgeBaseId');
            Route::post('{knowledgeBaseId}/fact-revisions/{revisionId}/restore', [KnowledgeFactController::class, 'restore'])->name('fact-revisions.restore')->whereNumber(['knowledgeBaseId', 'revisionId']);
            Route::post('{knowledgeBaseId}/fact-generation', [KnowledgeFactGenerationController::class, 'store'])->name('fact-generation.store')->whereNumber('knowledgeBaseId')->middleware('throttle:admin-sensitive');
            Route::get('{knowledgeBaseId}/fact-generation/{runId}', [KnowledgeFactGenerationController::class, 'show'])->name('fact-generation.show')->whereNumber(['knowledgeBaseId', 'runId']);
            Route::post('{knowledgeBaseId}/fact-generation/{runId}/cancel', [KnowledgeFactGenerationController::class, 'cancel'])->name('fact-generation.cancel')->whereNumber(['knowledgeBaseId', 'runId'])->middleware('throttle:admin-sensitive');
            Route::post('{knowledgeBaseId}/fact-generation/{runId}/resolve', [KnowledgeFactGenerationController::class, 'resolve'])->name('fact-generation.resolve')->whereNumber(['knowledgeBaseId', 'runId'])->middleware('throttle:admin-sensitive');
            Route::put('{knowledgeBaseId}/detail', [KnowledgeBaseController::class, 'updateFromDetail'])->name('detail.update');
            Route::put('{knowledgeBaseId}', [KnowledgeBaseController::class, 'update'])->name('update');
            Route::post('{knowledgeBaseId}/delete', [KnowledgeBaseController::class, 'destroy'])->name('delete');
        });

        Route::prefix('enterprise-knowledge')->name('enterprise-knowledge.')->group(function () {
            Route::get('/', [EnterpriseKnowledgeController::class, 'index'])->name('index');
            Route::get('create', [EnterpriseKnowledgeController::class, 'create'])->name('create');
            Route::post('create', [EnterpriseKnowledgeController::class, 'store'])->name('store');
            Route::get('{projectId}/status', [EnterpriseKnowledgeController::class, 'status'])->name('status')->whereNumber('projectId');
            Route::post('{projectId}/editor/images/upload', [EnterpriseKnowledgeController::class, 'uploadImage'])
                ->name('editor.images.upload')
                ->whereNumber('projectId');
            Route::get('{projectId}', [EnterpriseKnowledgeController::class, 'show'])->name('show')->whereNumber('projectId');
            Route::post('{projectId}/autosave', [EnterpriseKnowledgeController::class, 'autosave'])->name('autosave')->whereNumber('projectId');
            Route::post('{projectId}/validate', [EnterpriseKnowledgeController::class, 'validateDraft'])->name('validate')->whereNumber('projectId');
            Route::post('{projectId}/revisions/{revisionId}/restore', [EnterpriseKnowledgeController::class, 'restoreRevision'])
                ->name('revisions.restore')
                ->whereNumber(['projectId', 'revisionId']);
            Route::post('{projectId}/publish', [EnterpriseKnowledgeController::class, 'publish'])->name('publish')->whereNumber('projectId');
            Route::post('{projectId}/delete', [EnterpriseKnowledgeController::class, 'destroy'])->name('delete')->whereNumber('projectId');
        });

        // 业务页面
        Route::get('materials', [MaterialsController::class, 'index'])->name('materials.index');
        Route::middleware('admin.super')->group(function () {
            Route::get('url-import', [UrlImportController::class, 'index'])->name('url-import');
            Route::post('url-import', [UrlImportController::class, 'store'])->name('url-import.store');
            Route::get('url-import/history', [UrlImportController::class, 'history'])->name('url-import.history');
            Route::post('url-import/{jobId}/run', [UrlImportController::class, 'run'])
                ->name('url-import.run')
                ->whereNumber('jobId');
            Route::get('url-import/{jobId}/status', [UrlImportController::class, 'status'])
                ->name('url-import.status')
                ->whereNumber('jobId');
            Route::post('url-import/{jobId}/commit', [UrlImportController::class, 'commit'])
                ->name('url-import.commit')
                ->whereNumber('jobId');
            Route::get('url-import/{jobId}', [UrlImportController::class, 'show'])
                ->name('url-import.show')
                ->whereNumber('jobId');
        });

        // AI 配置模块（配置器 / 模型 / 提示词）
        Route::group([], function () {
            Route::get('ai-configurator', [LegacyController::class, 'aiConfigurator'])->name('ai.configurator');
            Route::prefix('ai-models')->name('ai-models.')->group(function () {
                Route::get('/', [AiModelController::class, 'index'])->name('index');
                Route::get('create', [AiModelController::class, 'create'])->name('create');
                Route::post('create', [AiModelController::class, 'store'])->name('store');
                Route::get('{modelId}/edit', [AiModelController::class, 'edit'])->name('edit')->whereNumber('modelId');
                Route::put('{modelId}', [AiModelController::class, 'update'])->name('update')->whereNumber('modelId');
                Route::post('{modelId}/test', [AiModelController::class, 'testConnection'])
                    ->middleware('throttle:admin-sensitive')
                    ->name('test')
                    ->whereNumber('modelId');
                Route::post('{modelId}/delete', [AiModelController::class, 'destroy'])->name('delete')->whereNumber('modelId');
                Route::post('default-embedding', [AiModelController::class, 'updateDefaultEmbedding'])->name('default-embedding');
                Route::post('chunking-config', [AiModelController::class, 'updateChunkingConfig'])->name('chunking-config');
            });
            Route::prefix('ai-source-providers')
                ->name('ai-source-providers.')
                ->where(['providerId' => '[1-9][0-9]{0,17}'])
                ->group(function () {
                    Route::get('/', [AiSourceProviderController::class, 'index'])->name('index');
                    Route::get('create', [AiSourceProviderController::class, 'create'])->name('create');
                    Route::post('/', [AiSourceProviderController::class, 'store'])->name('store');
                    Route::get('{providerId}/edit', [AiSourceProviderController::class, 'edit'])->name('edit');
                    Route::put('{providerId}', [AiSourceProviderController::class, 'update'])->name('update');
                    Route::post('{providerId}/test', [AiSourceProviderController::class, 'testProvider'])
                        ->middleware('throttle:admin-sensitive')
                        ->name('test');
                    Route::post('{providerId}/delete', [AiSourceProviderController::class, 'destroy'])->name('delete');
                    Route::post('model-bindings', [AiSourceProviderController::class, 'updateModelBindings'])->name('model-bindings');
                    Route::post('model-bindings/upsert-api', [AiSourceProviderController::class, 'upsertModelApi'])->name('model-bindings.upsert-api');
                    Route::post('model-bindings/test', [AiSourceProviderController::class, 'testModelBinding'])
                        ->middleware('throttle:admin-sensitive')
                        ->name('model-bindings.test');
                });
            Route::get('ai-prompts', [AiPromptController::class, 'index'])->name('ai-prompts');
            Route::get('ai-prompts/create', [AiPromptController::class, 'create'])->name('ai-prompts.create');
            Route::post('ai-prompts/create', [AiPromptController::class, 'store'])->name('ai-prompts.store');
            Route::get('ai-prompts/{promptId}/edit', [AiPromptController::class, 'edit'])->name('ai-prompts.edit')->whereNumber('promptId');
            Route::post('ai-prompts/{promptId}/copy', [AiPromptController::class, 'copy'])->name('ai-prompts.copy')->whereNumber('promptId');
            Route::put('ai-prompts/{promptId}', [AiPromptController::class, 'update'])->name('ai-prompts.update')->whereNumber('promptId');
            Route::post('ai-prompts/{promptId}/delete', [AiPromptController::class, 'destroy'])->name('ai-prompts.delete')->whereNumber('promptId');
            Route::get('ai-special-prompts', [AiSpecialPromptController::class, 'index'])->name('ai-special-prompts');
            Route::post('ai-special-prompts/keyword', [AiSpecialPromptController::class, 'updateKeyword'])->name('ai-special-prompts.keyword');
            Route::post('ai-special-prompts/description', [AiSpecialPromptController::class, 'updateDescription'])->name('ai-special-prompts.description');
        });

        Route::prefix('site-settings')->name('site-settings.')->group(function () {
            Route::get('/', [SiteSettingsController::class, 'index'])->name('index');
            Route::post('/', [SiteSettingsController::class, 'update'])->name('update');
            Route::post('theme', [SiteSettingsController::class, 'updateTheme'])->name('theme');
            Route::get('homepage-modules', [SiteSettingsController::class, 'editHomepageModules'])->name('homepage-modules.edit');
            Route::post('homepage-modules', [SiteSettingsController::class, 'updateHomepageModules'])->name('homepage-modules');
            Route::post('homepage-modules/preset', [SiteSettingsController::class, 'applyHomepageModulePreset'])->name('homepage-modules.preset');
            Route::post('homepage-modules/import', [SiteSettingsController::class, 'importHomepageModuleDesign'])->name('homepage-modules.import');
            Route::middleware('admin.super')->group(function () {
                Route::get('theme-replications/create', [SiteThemeReplicationController::class, 'create'])->name('theme-replications.create');
                Route::post('theme-replications', [SiteThemeReplicationController::class, 'store'])->name('theme-replications.store');
                Route::get('theme-replications/{replicationId}', [SiteThemeReplicationController::class, 'show'])
                    ->name('theme-replications.show')
                    ->whereNumber('replicationId');
                Route::get('theme-replications/{replicationId}/status', [SiteThemeReplicationController::class, 'status'])
                    ->name('theme-replications.status')
                    ->whereNumber('replicationId');
                Route::get('theme-replications/{replicationId}/preview/{page}', [SiteThemeReplicationController::class, 'preview'])
                    ->name('theme-replications.preview')
                    ->whereNumber('replicationId')
                    ->whereIn('page', ['home', 'category', 'article']);
                Route::post('theme-replications/{replicationId}/retry', [SiteThemeReplicationController::class, 'retry'])
                    ->name('theme-replications.retry')
                    ->whereNumber('replicationId');
                Route::post('theme-replications/{replicationId}/iterate', [SiteThemeReplicationController::class, 'iterate'])
                    ->name('theme-replications.iterate')
                    ->whereNumber('replicationId');
                Route::post('theme-replications/{replicationId}/publish', [SiteThemeReplicationController::class, 'publish'])
                    ->name('theme-replications.publish')
                    ->whereNumber('replicationId');
                Route::post('theme-replications/{replicationId}/copy', [SiteThemeReplicationController::class, 'copy'])
                    ->name('theme-replications.copy')
                    ->whereNumber('replicationId');
                Route::post('theme-replications/{replicationId}/archive', [SiteThemeReplicationController::class, 'archive'])
                    ->name('theme-replications.archive')
                    ->whereNumber('replicationId');
                Route::post('theme-replications/{replicationId}/drafts/delete', [SiteThemeReplicationController::class, 'deleteDrafts'])
                    ->name('theme-replications.delete-drafts')
                    ->whereNumber('replicationId');
                Route::get('theme-replications/{replicationId}/package', [SiteThemeReplicationController::class, 'downloadPackage'])
                    ->name('theme-replications.package')
                    ->whereNumber('replicationId');
            });
            Route::post('article-detail-ads', [SiteSettingsController::class, 'updateArticleDetailAds'])->name('ads');
            Route::post('article-detail-text-ads', [SiteSettingsController::class, 'updateArticleDetailTextAds'])->name('text-ads');
            Route::get('sensitive-words', [SecuritySettingsController::class, 'index'])->name('sensitive-words');
            Route::post('sensitive-words', [SecuritySettingsController::class, 'storeSensitiveWords'])
                ->middleware('admin.super')
                ->name('sensitive-words.store');
            Route::put('sensitive-words/{wordId}', [SecuritySettingsController::class, 'updateSensitiveWord'])
                ->middleware('admin.super')
                ->name('sensitive-words.update')
                ->whereNumber('wordId');
            Route::post('sensitive-words/{wordId}/delete', [SecuritySettingsController::class, 'destroySensitiveWord'])
                ->middleware('admin.super')
                ->name('sensitive-words.delete')
                ->whereNumber('wordId');
        });
        Route::prefix('security-settings')->name('security-settings.')->group(function () {
            Route::get('/', fn () => redirect()->route('admin.site-settings.sensitive-words'))->name('index');
            Route::post('sensitive-words', [SecuritySettingsController::class, 'storeSensitiveWords'])
                ->middleware('admin.super')
                ->name('words.store');
            Route::post('sensitive-words/{wordId}/delete', [SecuritySettingsController::class, 'destroySensitiveWord'])
                ->middleware('admin.super')
                ->name('words.delete');
            Route::post('password', [SecuritySettingsController::class, 'updatePassword'])->name('password.update');
        });

        // 超级管理员功能
        Route::middleware('admin.super')->group(function () {
            Route::prefix('admin-users')
                ->name('admin-users.')
                ->where(['adminId' => '[1-9][0-9]{0,17}'])
                ->group(function () {
                    Route::get('/', [AdminUserController::class, 'index'])->name('index');
                    Route::get('create', [AdminUserController::class, 'create'])->name('create');
                    Route::post('create', [AdminUserController::class, 'store'])->name('store');
                    Route::get('{adminId}/edit', [AdminUserController::class, 'edit'])->name('edit');
                    Route::post('{adminId}/update', [AdminUserController::class, 'update'])->name('update');
                    Route::post('{adminId}/toggle-status', [AdminUserController::class, 'toggleStatus'])->name('toggle-status');
                    Route::post('{adminId}/delete', [AdminUserController::class, 'destroy'])->name('delete');
                });
            Route::get('admin-activity-logs', [AdminActivityLogController::class, 'index'])->name('admin-activity-logs');
            Route::prefix('api-tokens')->name('api-tokens.')->group(function () {
                Route::get('/', [ApiTokenController::class, 'index'])->name('index');
                Route::post('/', [ApiTokenController::class, 'store'])->middleware('throttle:admin-sensitive')->name('store');
                Route::post('{tokenId}/revoke', [ApiTokenController::class, 'revoke'])->name('revoke');
            });
        });
    });
});

$adminUiRegistry = app(AdminUiRegistry::class);
foreach (Route::getRoutes() as $adminRoute) {
    $routeName = $adminRoute->getName();
    if (is_string($routeName)
        && in_array('GET', $adminRoute->methods(), true)
        && $adminUiRegistry->shouldRememberRoute($routeName)) {
        $adminRoute->block(30, 30);
    }
}
