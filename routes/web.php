<?php

/**
 * Web 路由：前台与 Blade 管理后台（路径见 config/geoflow.admin_base_path，默认 geo_admin）。
 */

use App\Http\Controllers\Admin\AdminActivityLogController;
use App\Http\Controllers\Admin\AdminAuthController;
use App\Http\Controllers\Admin\AdminUserController;
use App\Http\Controllers\Admin\AdminWelcomeController;
use App\Http\Controllers\Admin\AiModelController;
use App\Http\Controllers\Admin\AiPromptController;
use App\Http\Controllers\Admin\AiSpecialPromptController;
use App\Http\Controllers\Admin\AnalyticsController;
use App\Http\Controllers\Admin\ApiTokenController;
use App\Http\Controllers\Admin\ArticleController;
use App\Http\Controllers\Admin\ArticleEditorAssetController;
use App\Http\Controllers\Admin\AuthorController;
use App\Http\Controllers\Admin\CategoryController;
use App\Http\Controllers\Admin\DashboardController;
use App\Http\Controllers\Admin\DistributionController;
use App\Http\Controllers\Admin\EnterpriseKnowledgeController;
use App\Http\Controllers\Admin\ImageLibraryController;
use App\Http\Controllers\Admin\KeywordLibraryController;
use App\Http\Controllers\Admin\KnowledgeBaseController;
use App\Http\Controllers\Admin\LeadController;
use App\Http\Controllers\Admin\LeadFormController;
use App\Http\Controllers\Admin\LegacyController;
use App\Http\Controllers\Admin\MaterialsController;
use App\Http\Controllers\Admin\SecuritySettingsController;
use App\Http\Controllers\Admin\SiteSettingsController;
use App\Http\Controllers\Admin\SiteThemeEditorController;
use App\Http\Controllers\Admin\SiteThemeReplicationController;
use App\Http\Controllers\Admin\SystemUpdateController;
use App\Http\Controllers\Admin\TaskController;
use App\Http\Controllers\Admin\TitleLibraryController;
use App\Http\Controllers\Admin\UrlImportController;
use App\Http\Controllers\Site\ArchiveController;
use App\Http\Controllers\Site\ArticleController as SiteArticleController;
use App\Http\Controllers\Site\CategoryController as SiteCategoryController;
use App\Http\Controllers\Site\HomeController;
use App\Http\Controllers\Site\LeadFormController as SiteLeadFormController;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;

Route::middleware(['site.locale', 'site.view_log'])->group(function (): void {
    Route::get('/', [HomeController::class, 'index'])->name('site.home');
    Route::get('/archive', [ArchiveController::class, 'index'])->name('site.archive');
    Route::get('/archive/{year}/{month}', [ArchiveController::class, 'month'])
        ->name('site.archive.month')
        ->where(['year' => '[0-9]{4}', 'month' => '[0-9]{2}']);
    Route::get('/category/{slug}', [SiteCategoryController::class, 'show'])->name('site.category');
    Route::get('/article/{slug}', [SiteArticleController::class, 'show'])->name('site.article');
    Route::get('/forms/{slug}', [SiteLeadFormController::class, 'show'])->name('site.lead-forms.show');
    Route::post('/forms/{slug}/submissions', [SiteLeadFormController::class, 'submit'])
        ->middleware('throttle:10,1')
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
        Route::post('login', [AdminAuthController::class, 'login'])->name('login.attempt');
    });

    // 后台受保护路由
    Route::middleware(['admin.auth', 'admin.activity'])->group(function () {
        // 会话与首页
        Route::post('logout', [AdminAuthController::class, 'logout'])->name('logout');
        Route::post('welcome/dismiss', [AdminWelcomeController::class, 'dismiss'])->name('welcome.dismiss');
        Route::get('dashboard', [DashboardController::class, 'index'])->name('dashboard');
        Route::get('analytics', [AnalyticsController::class, 'index'])->name('analytics');

        Route::prefix('system-updates')->name('system-updates.')->group(function () {
            Route::get('/', [SystemUpdateController::class, 'index'])->name('index');
            Route::post('check', [SystemUpdateController::class, 'check'])->name('check');
            Route::get('runs/status', [SystemUpdateController::class, 'runsStatus'])->name('runs.status');
            Route::get('runs/{runUuid}', [SystemUpdateController::class, 'runShow'])->name('runs.show');
            Route::post('runs/{runUuid}/retry', [SystemUpdateController::class, 'retryRun'])->name('runs.retry');
            Route::post('runs/{runUuid}/mark-failed', [SystemUpdateController::class, 'markRunFailed'])->name('runs.mark-failed');
            Route::post('plan', [SystemUpdateController::class, 'plan'])->name('plan');
            Route::post('backup', [SystemUpdateController::class, 'backup'])->name('backup');
            Route::post('apply', [SystemUpdateController::class, 'apply'])->name('apply');
            Route::post('plans/{runUuid}/commands/{commandIndex}/executed', [SystemUpdateController::class, 'markCommandExecuted'])
                ->whereNumber('commandIndex')
                ->name('commands.executed');
            Route::get('backups/{backupUuid}', [SystemUpdateController::class, 'backupShow'])->name('backups.show');
            Route::post('backups/{backupUuid}/files/rollback', [SystemUpdateController::class, 'rollbackFile'])->name('rollback-file');
            Route::post('backups/{backupUuid}/rollback', [SystemUpdateController::class, 'rollback'])->name('rollback');
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
            Route::post('{taskId}/toggle-status', [TaskController::class, 'toggleStatus'])->name('toggle-status');
            Route::post('{taskId}/delete', [TaskController::class, 'destroyTask'])->name('delete');
            Route::get('create', [TaskController::class, 'create'])->name('create');
            Route::post('create', [TaskController::class, 'store'])->name('store');
            Route::get('{taskId}/edit', [TaskController::class, 'edit'])->name('edit');
            Route::put('{taskId}', [TaskController::class, 'update'])->name('update');
            Route::get('health-check', [TaskController::class, 'healthCheck'])->name('health');
            Route::post('batch/start', [TaskController::class, 'batchAction'])->name('batch');
        });

        // 分发管理：集中管理外部站点 Agent 与文章分发队列
        Route::prefix('distribution')->name('distribution.')->group(function () {
            Route::get('/', [DistributionController::class, 'index'])->name('index');
            Route::get('create', [DistributionController::class, 'create'])->name('create');
            Route::post('create', [DistributionController::class, 'store'])->name('store');
            Route::get('jobs', [DistributionController::class, 'jobs'])->name('jobs');
            Route::get('sync-settings-all/preview', [DistributionController::class, 'previewSyncSettingsAll'])->name('sync-settings-all.preview');
            Route::post('sync-settings-all', [DistributionController::class, 'syncSettingsAll'])->name('sync-settings-all');
            Route::post('sync-settings-selected/preview', [DistributionController::class, 'previewSyncSettingsSelected'])->name('sync-settings-selected.preview');
            Route::post('sync-settings-selected', [DistributionController::class, 'syncSettingsSelected'])->name('sync-settings-selected');
            Route::get('jobs/{distributionId}/edit', [DistributionController::class, 'editArticle'])->name('article.edit')->whereNumber('distributionId');
            Route::put('jobs/{distributionId}', [DistributionController::class, 'updateArticle'])->name('article.update')->whereNumber('distributionId');
            Route::post('jobs/{distributionId}/delete', [DistributionController::class, 'deleteArticle'])->name('article.delete')->whereNumber('distributionId');
            Route::post('jobs/{distributionId}/retry', [DistributionController::class, 'retry'])->name('retry')->whereNumber('distributionId');
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
            Route::post('batch/restore', [ArticleController::class, 'batchRestore'])->name('batch.restore');
            Route::post('batch/force-delete', [ArticleController::class, 'batchForceDelete'])->name('batch.force-delete');
            Route::post('trash/empty', [ArticleController::class, 'emptyTrash'])->name('trash.empty');
            Route::post('editor/wechat-html', [ArticleEditorAssetController::class, 'exportWeChatHtml'])->name('editor.wechat-html');
            Route::get('create', [ArticleController::class, 'create'])->name('create');
            Route::post('create', [ArticleController::class, 'store'])->name('store');
            Route::post('{articleId}/restore', [ArticleController::class, 'restore'])->name('restore')->whereNumber('articleId');
            Route::post('{articleId}/force-delete', [ArticleController::class, 'forceDelete'])->name('force-delete')->whereNumber('articleId');
            Route::get('{articleId}/edit', [ArticleController::class, 'edit'])->name('edit');
            Route::post('{articleId}/editor/images/upload', [ArticleEditorAssetController::class, 'uploadImage'])->name('editor.images.upload')->whereNumber('articleId');
            Route::put('{articleId}', [ArticleController::class, 'update'])->name('update');
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
        Route::prefix('authors')->name('authors.')->group(function () {
            Route::get('/', [AuthorController::class, 'index'])->name('index');
            Route::get('create', [AuthorController::class, 'create'])->name('create');
            Route::post('create', [AuthorController::class, 'store'])->name('store');
            Route::get('{authorId}/edit', [AuthorController::class, 'edit'])->name('edit');
            Route::get('{authorId}/detail', [AuthorController::class, 'detail'])->name('detail');
            Route::put('{authorId}', [AuthorController::class, 'update'])->name('update');
            Route::post('{authorId}/delete', [AuthorController::class, 'destroy'])->name('delete');
        });

        // 素材管理：关键词库管理
        Route::prefix('keyword-libraries')->name('keyword-libraries.')->group(function () {
            Route::get('/', [KeywordLibraryController::class, 'index'])->name('index');
            Route::get('create', [KeywordLibraryController::class, 'create'])->name('create');
            Route::post('create', [KeywordLibraryController::class, 'store'])->name('store');
            Route::get('{libraryId}/edit', [KeywordLibraryController::class, 'edit'])->name('edit');
            Route::get('{libraryId}/detail', [KeywordLibraryController::class, 'detail'])->name('detail');
            Route::post('{libraryId}/keywords', [KeywordLibraryController::class, 'storeKeyword'])->name('keywords.store');
            Route::post('{libraryId}/keywords/delete', [KeywordLibraryController::class, 'destroyKeywords'])->name('keywords.delete');
            Route::post('{libraryId}/import', [KeywordLibraryController::class, 'importKeywords'])->name('import');
            Route::put('{libraryId}/detail', [KeywordLibraryController::class, 'updateFromDetail'])->name('detail.update');
            Route::put('{libraryId}', [KeywordLibraryController::class, 'update'])->name('update');
            Route::post('{libraryId}/delete', [KeywordLibraryController::class, 'destroy'])->name('delete');
        });

        // 素材管理：标题库管理
        Route::prefix('title-libraries')->name('title-libraries.')->group(function () {
            Route::get('/', [TitleLibraryController::class, 'index'])->name('index');
            Route::get('create', [TitleLibraryController::class, 'create'])->name('create');
            Route::post('create', [TitleLibraryController::class, 'store'])->name('store');
            Route::get('{libraryId}/edit', [TitleLibraryController::class, 'edit'])->name('edit');
            Route::get('{libraryId}/detail', [TitleLibraryController::class, 'detail'])->name('detail');
            Route::post('{libraryId}/titles', [TitleLibraryController::class, 'storeTitle'])->name('titles.store');
            Route::post('{libraryId}/titles/delete', [TitleLibraryController::class, 'destroyTitles'])->name('titles.delete');
            Route::post('{libraryId}/import', [TitleLibraryController::class, 'importTitles'])->name('import');
            Route::get('{libraryId}/ai-generate', [TitleLibraryController::class, 'aiGenerate'])->name('ai-generate');
            Route::post('{libraryId}/ai-generate', [TitleLibraryController::class, 'generateWithAi'])->name('ai-generate.submit');
            Route::put('{libraryId}', [TitleLibraryController::class, 'update'])->name('update');
            Route::post('{libraryId}/delete', [TitleLibraryController::class, 'destroy'])->name('delete');
        });

        // 素材管理：图片库管理
        Route::prefix('image-libraries')->name('image-libraries.')->group(function () {
            Route::get('/', [ImageLibraryController::class, 'index'])->name('index');
            Route::get('create', [ImageLibraryController::class, 'create'])->name('create');
            Route::post('create', [ImageLibraryController::class, 'store'])->name('store');
            Route::get('{libraryId}/edit', [ImageLibraryController::class, 'edit'])->name('edit');
            Route::get('{libraryId}/detail', [ImageLibraryController::class, 'detail'])->name('detail');
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

        // AI 配置模块（配置器 / 模型 / 提示词）
        Route::group([], function () {
            Route::get('ai-configurator', [LegacyController::class, 'aiConfigurator'])->name('ai.configurator');
            Route::prefix('ai-models')->name('ai-models.')->group(function () {
                Route::get('/', [AiModelController::class, 'index'])->name('index');
                Route::post('create', [AiModelController::class, 'store'])->name('store');
                Route::put('{modelId}', [AiModelController::class, 'update'])->name('update');
                Route::post('{modelId}/test', [AiModelController::class, 'testConnection'])->name('test');
                Route::post('{modelId}/delete', [AiModelController::class, 'destroy'])->name('delete');
                Route::post('default-embedding', [AiModelController::class, 'updateDefaultEmbedding'])->name('default-embedding');
                Route::post('chunking-config', [AiModelController::class, 'updateChunkingConfig'])->name('chunking-config');
            });
            Route::get('ai-prompts', [AiPromptController::class, 'index'])->name('ai-prompts');
            Route::post('ai-prompts/create', [AiPromptController::class, 'store'])->name('ai-prompts.store');
            Route::put('ai-prompts/{promptId}', [AiPromptController::class, 'update'])->name('ai-prompts.update');
            Route::post('ai-prompts/{promptId}/delete', [AiPromptController::class, 'destroy'])->name('ai-prompts.delete');
            Route::get('ai-special-prompts', [AiSpecialPromptController::class, 'index'])->name('ai-special-prompts');
            Route::post('ai-special-prompts/keyword', [AiSpecialPromptController::class, 'updateKeyword'])->name('ai-special-prompts.keyword');
            Route::post('ai-special-prompts/description', [AiSpecialPromptController::class, 'updateDescription'])->name('ai-special-prompts.description');
        });

        Route::prefix('site-settings')->name('site-settings.')->group(function () {
            Route::get('/', [SiteSettingsController::class, 'index'])->name('index');
            Route::post('/', [SiteSettingsController::class, 'update'])->name('update');
            Route::post('theme', [SiteSettingsController::class, 'updateTheme'])->name('theme');
            Route::post('homepage-modules', [SiteSettingsController::class, 'updateHomepageModules'])->name('homepage-modules');
            Route::post('homepage-modules/preset', [SiteSettingsController::class, 'applyHomepageModulePreset'])->name('homepage-modules.preset');
            Route::post('homepage-modules/import', [SiteSettingsController::class, 'importHomepageModuleDesign'])->name('homepage-modules.import');
            Route::get('theme-editor/{themeId}/{page}', [SiteThemeEditorController::class, 'edit'])
                ->name('theme-editor.edit')
                ->where('themeId', '[A-Za-z0-9_-]+')
                ->whereIn('page', ['home', 'category', 'article']);
            Route::get('theme-editor/{themeId}/{page}/preview', [SiteThemeEditorController::class, 'preview'])
                ->name('theme-editor.preview')
                ->where('themeId', '[A-Za-z0-9_-]+')
                ->whereIn('page', ['home', 'category', 'article']);
            Route::post('theme-editor/{themeId}/{page}/draft', [SiteThemeEditorController::class, 'draft'])
                ->name('theme-editor.draft')
                ->where('themeId', '[A-Za-z0-9_-]+')
                ->whereIn('page', ['home', 'category', 'article']);
            Route::post('theme-editor/{themeId}/{page}/publish', [SiteThemeEditorController::class, 'publish'])
                ->name('theme-editor.publish')
                ->where('themeId', '[A-Za-z0-9_-]+')
                ->whereIn('page', ['home', 'category', 'article']);
            Route::post('theme-editor/{themeId}/{page}/discard', [SiteThemeEditorController::class, 'discard'])
                ->name('theme-editor.discard')
                ->where('themeId', '[A-Za-z0-9_-]+')
                ->whereIn('page', ['home', 'category', 'article']);
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
            Route::get('theme-replications/{replicationId}/assets/{assetPath}', [SiteThemeReplicationController::class, 'asset'])
                ->name('theme-replications.assets')
                ->whereNumber('replicationId')
                ->where('assetPath', '.*');
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
            Route::post('article-detail-ads', [SiteSettingsController::class, 'updateArticleDetailAds'])->name('ads');
            Route::post('article-detail-text-ads', [SiteSettingsController::class, 'updateArticleDetailTextAds'])->name('text-ads');
            Route::get('sensitive-words', [SecuritySettingsController::class, 'index'])->name('sensitive-words');
            Route::post('sensitive-words', [SecuritySettingsController::class, 'storeSensitiveWords'])->name('sensitive-words.store');
            Route::post('sensitive-words/{wordId}/delete', [SecuritySettingsController::class, 'destroySensitiveWord'])
                ->name('sensitive-words.delete')
                ->whereNumber('wordId');
        });
        Route::prefix('security-settings')->name('security-settings.')->group(function () {
            Route::get('/', fn () => redirect()->route('admin.site-settings.sensitive-words'))->name('index');
            Route::post('sensitive-words', [SecuritySettingsController::class, 'storeSensitiveWords'])->name('words.store');
            Route::post('sensitive-words/{wordId}/delete', [SecuritySettingsController::class, 'destroySensitiveWord'])->name('words.delete');
            Route::post('password', [SecuritySettingsController::class, 'updatePassword'])->name('password.update');
        });

        // 超级管理员功能
        Route::middleware('admin.super')->group(function () {
            Route::prefix('admin-users')->name('admin-users.')->group(function () {
                Route::get('/', [AdminUserController::class, 'index'])->name('index');
                Route::post('create', [AdminUserController::class, 'store'])->name('store');
                Route::post('{adminId}/update', [AdminUserController::class, 'update'])->name('update');
                Route::post('{adminId}/toggle-status', [AdminUserController::class, 'toggleStatus'])->name('toggle-status');
                Route::post('{adminId}/delete', [AdminUserController::class, 'destroy'])->name('delete');
            });
            Route::get('admin-activity-logs', [AdminActivityLogController::class, 'index'])->name('admin-activity-logs');
            Route::prefix('api-tokens')->name('api-tokens.')->group(function () {
                Route::get('/', [ApiTokenController::class, 'index'])->name('index');
                Route::post('/', [ApiTokenController::class, 'store'])->name('store');
                Route::post('{tokenId}/revoke', [ApiTokenController::class, 'revoke'])->name('revoke');
            });
        });
    });
});
