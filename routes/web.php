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
use App\Http\Controllers\Admin\CaseController;
use App\Http\Controllers\Admin\CategoryController;
use App\Http\Controllers\Admin\CollectionController;
use App\Http\Controllers\Admin\CrmCustomerController;
use App\Http\Controllers\Admin\CrmContactController;
use App\Http\Controllers\Admin\CrmDashboardController;
use App\Http\Controllers\Admin\CrmOpportunityController;
use App\Http\Controllers\Admin\CrmTaskController;
use App\Http\Controllers\Admin\CrmAfterSalesTicketController;
use App\Http\Controllers\Admin\CrmContentProposalController;
use App\Http\Controllers\Admin\CrmInquiryController;
use App\Http\Controllers\Admin\CrmQuoteController;
use App\Http\Controllers\Admin\CrmSalesOrderController;
use App\Http\Controllers\Admin\DashboardController;
use App\Http\Controllers\Admin\DistributionController;
use App\Http\Controllers\Admin\EntityController;
use App\Http\Controllers\Admin\ImageLibraryController;
use App\Http\Controllers\Admin\KeywordLibraryController;
use App\Http\Controllers\Admin\KnowledgeBaseController;
use App\Http\Controllers\Admin\LegacyController;
use App\Http\Controllers\Admin\MaterialsController;
use App\Http\Controllers\Admin\SecuritySettingsController;
use App\Http\Controllers\Admin\SiteSettingsController;
use App\Http\Controllers\Admin\SiteThemeReplicationController;
use App\Http\Controllers\Admin\TagController;
use App\Http\Controllers\Admin\TaskController;
use App\Http\Controllers\Admin\TitleLibraryController;
use App\Http\Controllers\Admin\UrlImportController;
use App\Http\Controllers\Site\ArchiveController;
use App\Http\Controllers\Site\ArticleController as SiteArticleController;
use App\Http\Controllers\Site\CategoryController as SiteCategoryController;
use App\Http\Controllers\Site\HomeController;
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
            Route::post('{articleId}/publish', [ArticleController::class, 'publish'])->name('publish')->whereNumber('articleId');
            Route::post('{articleId}/internal-links/refresh', [ArticleController::class, 'refreshInternalLinks'])->name('internal-links.refresh')->whereNumber('articleId');
            Route::post('{articleId}/internal-links/apply', [ArticleController::class, 'applyInternalLinks'])->name('internal-links.apply')->whereNumber('articleId');
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
        Route::prefix('collections')->name('collections.')->group(function () {
            Route::get('/', [CollectionController::class, 'index'])->name('index');
            Route::get('create', [CollectionController::class, 'create'])->name('create');
            Route::post('create', [CollectionController::class, 'store'])->name('store');
            Route::get('{collectionId}/edit', [CollectionController::class, 'edit'])->name('edit')->whereNumber('collectionId');
            Route::put('{collectionId}', [CollectionController::class, 'update'])->name('update')->whereNumber('collectionId');
            Route::post('{collectionId}/default', [CollectionController::class, 'setDefault'])->name('default')->whereNumber('collectionId');
            Route::post('{collectionId}/toggle', [CollectionController::class, 'toggle'])->name('toggle')->whereNumber('collectionId');
            Route::post('{collectionId}/delete', [CollectionController::class, 'destroy'])->name('delete')->whereNumber('collectionId');
        });

        // 轻量 CRM：客户、询盘与报价辅助
        Route::prefix('crm')->name('crm.')->group(function () {
            Route::get('/', [CrmDashboardController::class, 'index'])->name('dashboard');
            Route::prefix('tasks')->name('tasks.')->group(function () {
                Route::get('/', [CrmTaskController::class, 'index'])->name('index');
                Route::post('/', [CrmTaskController::class, 'store'])->name('store');
                Route::post('{taskId}/complete', [CrmTaskController::class, 'complete'])->name('complete')->whereNumber('taskId');
                Route::post('{taskId}/reopen', [CrmTaskController::class, 'reopen'])->name('reopen')->whereNumber('taskId');
                Route::post('{taskId}/delete', [CrmTaskController::class, 'destroy'])->name('delete')->whereNumber('taskId');
            });
            Route::prefix('opportunities')->name('opportunities.')->group(function () {
                Route::get('/', [CrmOpportunityController::class, 'index'])->name('index');
                Route::get('create', [CrmOpportunityController::class, 'create'])->name('create');
                Route::post('create', [CrmOpportunityController::class, 'store'])->name('store');
                Route::post('from-inquiry/{inquiryId}', [CrmOpportunityController::class, 'storeFromInquiry'])->name('from-inquiry')->whereNumber('inquiryId');
                Route::get('{opportunityId}/edit', [CrmOpportunityController::class, 'edit'])->name('edit')->whereNumber('opportunityId');
                Route::put('{opportunityId}', [CrmOpportunityController::class, 'update'])->name('update')->whereNumber('opportunityId');
                Route::post('{opportunityId}/delete', [CrmOpportunityController::class, 'destroy'])->name('delete')->whereNumber('opportunityId');
            });
            Route::prefix('customers')->name('customers.')->group(function () {
                Route::get('/', [CrmCustomerController::class, 'index'])->name('index');
                Route::get('create', [CrmCustomerController::class, 'create'])->name('create');
                Route::post('create', [CrmCustomerController::class, 'store'])->name('store');
                Route::get('{customerId}', [CrmCustomerController::class, 'show'])->name('show')->whereNumber('customerId');
                Route::get('{customerId}/edit', [CrmCustomerController::class, 'edit'])->name('edit')->whereNumber('customerId');
                Route::put('{customerId}', [CrmCustomerController::class, 'update'])->name('update')->whereNumber('customerId');
                Route::post('{customerId}/delete', [CrmCustomerController::class, 'destroy'])->name('delete')->whereNumber('customerId');
                Route::post('{customerId}/follow-ups', [CrmCustomerController::class, 'storeFollowUp'])->name('follow-ups.store')->whereNumber('customerId');
                Route::post('{customerId}/contacts', [CrmContactController::class, 'store'])->name('contacts.store')->whereNumber('customerId');
                Route::put('{customerId}/contacts/{contactId}', [CrmContactController::class, 'update'])->name('contacts.update')->whereNumber(['customerId','contactId']);
                Route::post('{customerId}/contacts/{contactId}/primary', [CrmContactController::class, 'primary'])->name('contacts.primary')->whereNumber(['customerId','contactId']);
                Route::post('{customerId}/contacts/{contactId}/delete', [CrmContactController::class, 'destroy'])->name('contacts.delete')->whereNumber(['customerId','contactId']);
            });

            Route::prefix('inquiries')->name('inquiries.')->group(function () {
                Route::get('/', [CrmInquiryController::class, 'index'])->name('index');
                Route::get('create', [CrmInquiryController::class, 'create'])->name('create');
                Route::post('create', [CrmInquiryController::class, 'store'])->name('store');
                Route::post('analyze', [CrmInquiryController::class, 'analyze'])->name('analyze');
                Route::get('{inquiryId}', [CrmInquiryController::class, 'show'])->name('show')->whereNumber('inquiryId');
                Route::get('{inquiryId}/edit', [CrmInquiryController::class, 'edit'])->name('edit')->whereNumber('inquiryId');
                Route::put('{inquiryId}', [CrmInquiryController::class, 'update'])->name('update')->whereNumber('inquiryId');
                Route::post('{inquiryId}/delete', [CrmInquiryController::class, 'destroy'])->name('delete')->whereNumber('inquiryId');
                Route::post('{inquiryId}/follow-ups', [CrmInquiryController::class, 'storeFollowUp'])->name('follow-ups.store')->whereNumber('inquiryId');
            });

            Route::post('follow-ups/{followUpId}/delete', [CrmInquiryController::class, 'destroyFollowUp'])->name('follow-ups.delete')->whereNumber('followUpId');

            Route::prefix('quotes')->name('quotes.')->group(function () {
                Route::get('/', [CrmQuoteController::class, 'index'])->name('index');
                Route::get('create', [CrmQuoteController::class, 'create'])->name('create');
                Route::post('create', [CrmQuoteController::class, 'store'])->name('store');
                Route::post('seller-profiles', [CrmQuoteController::class, 'storeSellerProfile'])->name('seller-profiles.store');
                Route::get('{quoteId}', [CrmQuoteController::class, 'show'])->name('show')->whereNumber('quoteId');
                Route::get('{quoteId}/edit', [CrmQuoteController::class, 'edit'])->name('edit')->whereNumber('quoteId');
                Route::put('{quoteId}', [CrmQuoteController::class, 'update'])->name('update')->whereNumber('quoteId');
                Route::get('{quoteId}/print', [CrmQuoteController::class, 'print'])->name('print')->whereNumber('quoteId');
                Route::get('{quoteId}/excel', [CrmQuoteController::class, 'downloadExcel'])->name('excel')->whereNumber('quoteId');
                Route::get('{quoteId}/pdf', [CrmQuoteController::class, 'downloadPdf'])->name('pdf')->whereNumber('quoteId');
                Route::post('{quoteId}/delete', [CrmQuoteController::class, 'destroy'])->name('delete')->whereNumber('quoteId');
                Route::post('{quoteId}/convert', [CrmQuoteController::class, 'convert'])->name('convert')->whereNumber('quoteId');
            });

            Route::prefix('orders')->name('orders.')->group(function () {
                Route::get('/', [CrmSalesOrderController::class, 'index'])->name('index');
                Route::post('from-quote/{quoteId}', [CrmSalesOrderController::class, 'fromQuote'])->name('from-quote')->whereNumber('quoteId');
                Route::get('{orderId}', [CrmSalesOrderController::class, 'show'])->name('show')->whereNumber('orderId');
                Route::get('{orderId}/edit', [CrmSalesOrderController::class, 'edit'])->name('edit')->whereNumber('orderId');
                Route::put('{orderId}', [CrmSalesOrderController::class, 'update'])->name('update')->whereNumber('orderId');
                Route::post('{orderId}/delete', [CrmSalesOrderController::class, 'destroy'])->name('delete')->whereNumber('orderId');
            });

            Route::prefix('tickets')->name('tickets.')->group(function () {
                Route::get('/', [CrmAfterSalesTicketController::class, 'index'])->name('index');
                Route::get('create', [CrmAfterSalesTicketController::class, 'create'])->name('create');
                Route::post('create', [CrmAfterSalesTicketController::class, 'store'])->name('store');
                Route::post('analyze', [CrmAfterSalesTicketController::class, 'analyze'])->name('analyze');
                Route::get('{ticketId}', [CrmAfterSalesTicketController::class, 'show'])->name('show')->whereNumber('ticketId');
                Route::get('{ticketId}/edit', [CrmAfterSalesTicketController::class, 'edit'])->name('edit')->whereNumber('ticketId');
                Route::put('{ticketId}', [CrmAfterSalesTicketController::class, 'update'])->name('update')->whereNumber('ticketId');
                Route::post('{ticketId}/delete', [CrmAfterSalesTicketController::class, 'destroy'])->name('delete')->whereNumber('ticketId');
            });

            Route::prefix('content-proposals')->name('proposals.')->group(function () {
                Route::get('/', [CrmContentProposalController::class, 'index'])->name('index');
                Route::post('from-inquiry/{inquiryId}', [CrmContentProposalController::class, 'createFromInquiry'])->name('from-inquiry')->whereNumber('inquiryId');
                Route::post('from-ticket/{ticketId}', [CrmContentProposalController::class, 'createFromTicket'])->name('from-ticket')->whereNumber('ticketId');
                Route::post('{proposalId}/apply', [CrmContentProposalController::class, 'apply'])->name('apply')->whereNumber('proposalId');
                Route::post('{proposalId}/reject', [CrmContentProposalController::class, 'reject'])->name('reject')->whereNumber('proposalId');
            });
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
            Route::post('{libraryId}/keywords/{keywordId}/tags', [KeywordLibraryController::class, 'updateKeywordTags'])->name('keywords.tags')->whereNumber('keywordId');
            Route::post('{libraryId}/keywords/delete', [KeywordLibraryController::class, 'destroyKeywords'])->name('keywords.delete');
            Route::post('{libraryId}/keywords/organize', [KeywordLibraryController::class, 'organizeKeywords'])->name('keywords.organize');
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
            Route::put('{libraryId}/titles/{titleId}', [TitleLibraryController::class, 'updateTitle'])->name('titles.update')->whereNumber('titleId');
            Route::post('{libraryId}/titles/delete', [TitleLibraryController::class, 'destroyTitles'])->name('titles.delete');
            Route::post('{libraryId}/titles/organize', [TitleLibraryController::class, 'organizeTitles'])->name('titles.organize');
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
            Route::post('{libraryId}/images/{imageId}/title', [ImageLibraryController::class, 'updateImageTitle'])->name('images.title')->whereNumber('imageId');
            Route::post('{libraryId}/images/{imageId}/tags', [ImageLibraryController::class, 'updateImageTags'])->name('images.tags')->whereNumber('imageId');
            Route::post('{libraryId}/images/{imageId}/entities', [ImageLibraryController::class, 'updateImageEntities'])->name('images.entities')->whereNumber('imageId');
            Route::post('{libraryId}/images/delete', [ImageLibraryController::class, 'destroyImages'])->name('images.delete');
            Route::post('{libraryId}/images/organize', [ImageLibraryController::class, 'organizeImages'])->name('images.organize');
            Route::put('{libraryId}/detail', [ImageLibraryController::class, 'updateFromDetail'])->name('detail.update');
            Route::put('{libraryId}', [ImageLibraryController::class, 'update'])->name('update');
            Route::post('{libraryId}/delete', [ImageLibraryController::class, 'destroy'])->name('delete');
        });

        // 素材管理：知识库管理
        Route::prefix('knowledge-bases')->name('knowledge-bases.')->group(function () {
            Route::get('/', [KnowledgeBaseController::class, 'index'])->name('index');
            Route::get('create', [KnowledgeBaseController::class, 'create'])->name('create');
            Route::post('create', [KnowledgeBaseController::class, 'store'])->name('store');
            Route::post('analyze', [KnowledgeBaseController::class, 'analyze'])->name('analyze');
            Route::post('bulk', [KnowledgeBaseController::class, 'bulkUpdate'])->name('bulk');
            Route::get('{knowledgeBaseId}/edit', [KnowledgeBaseController::class, 'edit'])->name('edit');
            Route::get('{knowledgeBaseId}/detail', [KnowledgeBaseController::class, 'detail'])->name('detail');
            Route::post('upload', [KnowledgeBaseController::class, 'uploadFile'])->name('upload');
            Route::post('{knowledgeBaseId}/chunks/refresh', [KnowledgeBaseController::class, 'refreshChunks'])->name('chunks.refresh');
            Route::post('{knowledgeBaseId}/tags', [KnowledgeBaseController::class, 'updateTags'])->name('tags')->whereNumber('knowledgeBaseId');
            Route::put('{knowledgeBaseId}/detail', [KnowledgeBaseController::class, 'updateFromDetail'])->name('detail.update');
            Route::put('{knowledgeBaseId}', [KnowledgeBaseController::class, 'update'])->name('update');
            Route::post('{knowledgeBaseId}/delete', [KnowledgeBaseController::class, 'destroy'])->name('delete');
        });

        // 素材管理：Entity DB
        Route::prefix('entities')->name('entities.')->group(function () {
            Route::get('/', [EntityController::class, 'index'])->name('index');
            Route::get('create', [EntityController::class, 'create'])->name('create');
            Route::post('create', [EntityController::class, 'store'])->name('store');
            Route::post('analyze', [EntityController::class, 'analyze'])->name('analyze');
            Route::get('{entityId}/edit', [EntityController::class, 'edit'])->name('edit')->whereNumber('entityId');
            Route::put('{entityId}', [EntityController::class, 'update'])->name('update')->whereNumber('entityId');
            Route::post('{entityId}/delete', [EntityController::class, 'destroy'])->name('delete')->whereNumber('entityId');
            Route::get('search', [EntityController::class, 'searchJson'])->name('search');
            Route::get('{entityId}/relations', [EntityController::class, 'relations'])->name('relations')->whereNumber('entityId');
        });

        // 素材管理：Case DB
        Route::prefix('cases')->name('cases.')->group(function () {
            Route::get('/', [CaseController::class, 'index'])->name('index');
            Route::get('create', [CaseController::class, 'create'])->name('create');
            Route::post('create', [CaseController::class, 'store'])->name('store');
            Route::post('analyze', [CaseController::class, 'analyze'])->name('analyze');
            Route::get('{caseId}/edit', [CaseController::class, 'edit'])->name('edit')->whereNumber('caseId');
            Route::put('{caseId}', [CaseController::class, 'update'])->name('update')->whereNumber('caseId');
            Route::post('{caseId}/delete', [CaseController::class, 'destroy'])->name('delete')->whereNumber('caseId');
        });

        // 业务页面
        Route::get('materials', [MaterialsController::class, 'index'])->name('materials.index');
        Route::get('material-tags', [TagController::class, 'index'])->name('material-tags.index');
        Route::get('material-tags/search', [TagController::class, 'search'])->name('material-tags.search');
        Route::get('material-tags/controlled-groups', [TagController::class, 'controlledGroups'])->name('material-tags.controlled-groups.index');
        Route::post('material-tags/controlled-groups', [TagController::class, 'storeControlledGroup'])->name('material-tags.controlled-groups.store');
        Route::put('material-tags/controlled-groups/{groupId}', [TagController::class, 'updateControlledGroup'])->name('material-tags.controlled-groups.update')->whereNumber('groupId');
        Route::post('material-tags/controlled-groups/{groupId}/delete', [TagController::class, 'deleteControlledGroup'])->name('material-tags.controlled-groups.delete')->whereNumber('groupId');
        Route::post('material-tags', [TagController::class, 'store'])->name('material-tags.store');
        Route::post('material-tags/bulk', [TagController::class, 'bulk'])->name('material-tags.bulk');
        Route::get('material-tags/{tagId}/references', [TagController::class, 'references'])->name('material-tags.references')->whereNumber('tagId');
        Route::put('material-tags/{tagId}', [TagController::class, 'update'])->name('material-tags.update')->whereNumber('tagId');
        Route::post('material-tags/{tagId}/delete', [TagController::class, 'destroy'])->name('material-tags.delete')->whereNumber('tagId');
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
            Route::get('theme-replications/create', [SiteThemeReplicationController::class, 'create'])->name('theme-replications.create');
            Route::post('theme-replications', [SiteThemeReplicationController::class, 'store'])->name('theme-replications.store');
            Route::get('theme-replications/{replicationId}', [SiteThemeReplicationController::class, 'show'])
                ->name('theme-replications.show')
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
