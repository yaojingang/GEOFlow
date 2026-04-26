<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Article;
use App\Models\Author;
use App\Models\Category;
use App\Models\Task;
use App\Support\AdminWeb;
use App\Support\GeoFlow\ArticleWorkflow;
use Illuminate\Database\QueryException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Carbon;
use Illuminate\View\View;
use Throwable;

/**
 * 文章管理页（按 bak/admin/articles.php 行为迁移）：
 * - GET 展示列表、筛选、统计与批量操作区
 * - POST 处理批量状态/审核更新与批量删除
 * - create/edit 共用同一 Blade 表单页
 */
class ArticleController extends Controller
{
    /**
     * 文章管理首页：渲染筛选与列表。
     */
    public function index(Request $request): View
    {
        $filters = $this->buildFilters($request);
        $articles = $this->queryArticles($filters);
        $isTrashView = (bool) ($filters['trashed'] ?? false);

        return view('admin.articles.index', [
            'pageTitle' => $isTrashView
                ? __('admin.articles.trash.title')
                : __('admin.articles.page_title'),
            'activeMenu' => 'articles',
            'adminSiteName' => AdminWeb::siteName(),
            'articles' => $articles,
            'stats' => $isTrashView ? $this->loadTrashStats() : $this->loadStats(),
            'filters' => $filters,
            'tasks' => $this->loadTaskOptions(),
            'authors' => $this->loadAuthorOptions(),
            'articlesI18n' => $this->articlesI18n(),
            'isTrashView' => $isTrashView,
            'trashI18n' => $this->trashI18n(),
            'articleBatchRoutes' => $this->articleBatchRoutes($isTrashView),
        ]);
    }

    /**
     * 批量更新发布状态。
     */
    public function batchUpdateStatus(Request $request): RedirectResponse
    {
        $articleIds = $this->extractArticleIds($request);
        if (empty($articleIds)) {
            return back()->withErrors(__('admin.articles.message.select_articles'));
        }

        try {
            return $this->handleBatchUpdateStatus($request, $articleIds);
        } catch (Throwable $e) {
            return back()->withErrors($e->getMessage());
        }
    }

    /**
     * 批量更新审核状态。
     */
    public function batchUpdateReview(Request $request): RedirectResponse
    {
        $articleIds = $this->extractArticleIds($request);
        if (empty($articleIds)) {
            return back()->withErrors(__('admin.articles.message.select_articles'));
        }

        try {
            return $this->handleBatchUpdateReview($request, $articleIds);
        } catch (Throwable $e) {
            return back()->withErrors($e->getMessage());
        }
    }

    /**
     * 批量删除文章。
     */
    public function batchDelete(Request $request): RedirectResponse
    {
        $articleIds = $this->extractArticleIds($request);
        if (empty($articleIds)) {
            return back()->withErrors(__('admin.articles.message.select_articles'));
        }

        try {
            return $this->handleBatchDelete($articleIds);
        } catch (Throwable $e) {
            return back()->withErrors($e->getMessage());
        }
    }

    /**
     * 批量恢复已软删除的文章。
     */
    public function batchRestore(Request $request): RedirectResponse
    {
        $articleIds = $this->extractArticleIds($request);
        if (empty($articleIds)) {
            return back()->withErrors(__('admin.articles.message.select_articles'));
        }

        try {
            $count = Article::onlyTrashed()->whereIn('id', $articleIds)->restore();

            return back()->with('message', __('admin.articles.trash.message.restore_success', ['count' => $count]));
        } catch (Throwable $e) {
            return back()->withErrors(__('admin.articles.trash.message.restore_failed'));
        }
    }

    /**
     * 批量永久删除（垃圾箱内）。
     */
    public function batchForceDelete(Request $request): RedirectResponse
    {
        $articleIds = $this->extractArticleIds($request);
        if (empty($articleIds)) {
            return back()->withErrors(__('admin.articles.message.select_articles'));
        }

        try {
            $models = Article::onlyTrashed()->whereIn('id', $articleIds)->get();
            $models->each(function (Article $article): void {
                $article->forceDelete();
            });

            return back()->with('message', __('admin.articles.trash.message.delete_success', ['count' => $models->count()]));
        } catch (Throwable $e) {
            return back()->withErrors(__('admin.articles.trash.message.delete_failed', ['message' => $e->getMessage()]));
        }
    }

    /**
     * 清空文章垃圾箱（全部永久删除）。
     */
    public function emptyTrash(): RedirectResponse
    {
        try {
            $models = Article::onlyTrashed()->get();
            if ($models->isEmpty()) {
                return back()->with('message', __('admin.articles.trash.message.empty_already'));
            }
            $total = $models->count();
            $models->each(function (Article $article): void {
                $article->forceDelete();
            });

            return back()->with('message', __('admin.articles.trash.message.empty_success', ['count' => $total]));
        } catch (Throwable $e) {
            return back()->withErrors(__('admin.articles.trash.message.empty_failed', ['message' => $e->getMessage()]));
        }
    }

    /**
     * 恢复单篇已删除文章。
     */
    public function restore(int $articleId): RedirectResponse
    {
        $article = Article::onlyTrashed()->whereKey($articleId)->firstOrFail();
        $article->restore();

        return back()->with('message', __('admin.articles.trash.message.restore_success', ['count' => 1]));
    }

    /**
     * 永久删除单篇已删除文章。
     */
    public function forceDelete(int $articleId): RedirectResponse
    {
        $article = Article::onlyTrashed()->whereKey($articleId)->firstOrFail();
        $article->forceDelete();

        return back()->with('message', __('admin.articles.trash.message.delete_success', ['count' => 1]));
    }

    /**
     * 文章创建页：与编辑页共用一个 Blade 模板。
     */
    public function create(): View
    {
        return view('admin.articles.form', [
            'pageTitle' => __('admin.article_create.page_title'),
            'activeMenu' => 'articles',
            'adminSiteName' => AdminWeb::siteName(),
            'isEdit' => false,
            'articleId' => null,
            'articleForm' => null,
            'formOptions' => $this->loadFormOptions(),
        ]);
    }

    /**
     * 创建文章：手动写入内容并按统一工作流校正状态。
     */
    public function store(Request $request): RedirectResponse
    {
        $payload = $this->validateArticleForm($request, false);
        $workflowState = ArticleWorkflow::normalizeState(
            $payload['status'],
            $payload['review_status']
        );

        try {
            $article = Article::query()->create([
                'title' => $payload['title'],
                'slug' => ArticleWorkflow::generateUniqueSlug($payload['title']),
                'content' => $payload['content'],
                'excerpt' => $payload['excerpt'] !== '' ? $payload['excerpt'] : mb_substr(strip_tags($payload['content']), 0, 200, 'UTF-8'),
                'keywords' => $payload['keywords'],
                'meta_description' => $payload['meta_description'],
                'category_id' => (int) $payload['category_id'],
                'author_id' => (int) $payload['author_id'],
                'status' => $workflowState['status'],
                'review_status' => $workflowState['review_status'],
                'published_at' => $workflowState['published_at'],
                'is_ai_generated' => 0,
                'is_hot' => (bool) ($payload['is_hot'] ?? false),
                'is_featured' => (bool) ($payload['is_featured'] ?? false),
            ]);
        } catch (Throwable $e) {
            return back()->withInput()->withErrors(__('admin.article_create.error.create_exception', ['message' => $e->getMessage()]));
        }

        return redirect()
            ->route('admin.articles.edit', ['articleId' => (int) $article->id])
            ->with('message', __('admin.button.create_article'));
    }

    /**
     * 文章编辑页：复用创建页模板并回填现有数据。
     */
    public function edit(int $articleId): View|RedirectResponse
    {
        $article = Article::query()
            ->with(['task:id,name', 'author:id,name', 'category:id,name'])
            ->whereKey($articleId)
            ->firstOrFail();

        return view('admin.articles.form', [
            'pageTitle' => __('admin.article_edit.page_title'),
            'activeMenu' => 'articles',
            'adminSiteName' => AdminWeb::siteName(),
            'isEdit' => true,
            'articleId' => $articleId,
            'articleForm' => [
                'title' => (string) $article->title,
                'excerpt' => (string) ($article->excerpt ?? ''),
                'content' => (string) $article->content,
                'keywords' => (string) ($article->keywords ?? ''),
                'meta_description' => (string) ($article->meta_description ?? ''),
                'status' => (string) $article->status,
                'review_status' => (string) $article->review_status,
                'category_id' => (string) $article->category_id,
                'author_id' => (string) $article->author_id,
                'slug' => (string) $article->slug,
                'published_at' => $article->published_at?->format('Y-m-d H:i:s'),
                'task_name' => (string) ($article->task->name ?? ''),
                'is_hot' => (bool) ($article->is_hot ?? false),
                'is_featured' => (bool) ($article->is_featured ?? false),
            ],
            'formOptions' => $this->loadFormOptions(),
        ]);
    }

    /**
     * 更新文章：保持创建/编辑一致的字段校验与状态归一化。
     */
    public function update(Request $request, int $articleId): RedirectResponse
    {
        $payload = $this->validateArticleForm($request, true);
        $article = Article::query()->whereKey($articleId)->firstOrFail();

        $workflowState = ArticleWorkflow::normalizeState(
            $payload['status'],
            $payload['review_status'],
            $article->published_at?->format('Y-m-d H:i:s')
        );

        try {
            $article->fill([
                'title' => $payload['title'],
                'slug' => $payload['title'] === $article->title
                    ? $article->slug
                    : ArticleWorkflow::generateUniqueSlug($payload['title'], (int) $article->id),
                'content' => $payload['content'],
                'excerpt' => $payload['excerpt'] !== '' ? $payload['excerpt'] : mb_substr(strip_tags($payload['content']), 0, 200, 'UTF-8'),
                'keywords' => $payload['keywords'],
                'meta_description' => $payload['meta_description'],
                'category_id' => (int) $payload['category_id'],
                'author_id' => (int) $payload['author_id'],
                'status' => $workflowState['status'],
                'review_status' => $workflowState['review_status'],
                'published_at' => $workflowState['published_at'],
                'is_hot' => (bool) ($payload['is_hot'] ?? false),
                'is_featured' => (bool) ($payload['is_featured'] ?? false),
            ])->save();
        } catch (Throwable $e) {
            return back()->withInput()->withErrors(__('admin.article_edit.error.update_exception', ['message' => $e->getMessage()]));
        }

        return redirect()
            ->route('admin.articles.edit', ['articleId' => $articleId])
            ->with('message', __('admin.article_edit.message.update_success'));
    }

    /**
     * @return array{
     *     task_id: int,
     *     status: string,
     *     review_status: string,
     *     author_id: int,
     *     date_from: string,
     *     date_to: string,
     *     search: string,
     *     per_page: int,
     *     trashed: bool
     * }
     */
    private function buildFilters(Request $request): array
    {
        $status = (string) $request->query('status', '');
        $reviewStatus = (string) $request->query('review_status', '');

        if (! in_array($status, ['draft', 'published', 'private'], true)) {
            $status = '';
        }

        if (! in_array($reviewStatus, ['pending', 'approved', 'rejected', 'auto_approved'], true)) {
            $reviewStatus = '';
        }

        return [
            'task_id' => max(0, (int) $request->query('task_id', 0)),
            'status' => $status,
            'review_status' => $reviewStatus,
            'author_id' => max(0, (int) $request->query('author_id', 0)),
            'date_from' => trim((string) $request->query('date_from', '')),
            'date_to' => trim((string) $request->query('date_to', '')),
            'search' => trim((string) $request->query('search', '')),
            'per_page' => min(100, max(10, (int) $request->query('per_page', 20) ?: 20)),
            'trashed' => $request->boolean('trashed'),
        ];
    }

    /**
     * @param  array{
     *     task_id: int,
     *     status: string,
     *     review_status: string,
     *     author_id: int,
     *     date_from: string,
     *     date_to: string,
     *     search: string,
     *     per_page: int,
     *     trashed: bool
     * }  $filters
     */
    private function queryArticles(array $filters): LengthAwarePaginator
    {
        $query = ($filters['trashed'] ?? false)
            ? Article::onlyTrashed()
            : Article::query();

        $query->with([
            'task:id,name,need_review',
            'author:id,name',
            'category:id,name',
        ]);

        if ($filters['trashed'] ?? false) {
            $query->orderByDesc('deleted_at');
        } else {
            $query->orderByDesc('created_at');
        }

        if ($filters['task_id'] > 0) {
            $query->where('task_id', $filters['task_id']);
        }

        if (($filters['trashed'] ?? false) === false && $filters['status'] !== '') {
            $query->where('status', $filters['status']);
        }

        if (($filters['trashed'] ?? false) === false && $filters['review_status'] !== '') {
            $query->where('review_status', $filters['review_status']);
        }

        if ($filters['author_id'] > 0) {
            $query->where('author_id', $filters['author_id']);
        }

        if ($filters['date_from'] !== '') {
            $query->whereDate('created_at', '>=', $filters['date_from']);
        }

        if ($filters['date_to'] !== '') {
            $query->whereDate('created_at', '<=', $filters['date_to']);
        }

        if ($filters['search'] !== '') {
            $query->where(function ($subQuery) use ($filters): void {
                $subQuery->where('title', 'like', '%'.$filters['search'].'%')
                    ->orWhere('content', 'like', '%'.$filters['search'].'%');
            });
        }

        return $query->paginate($filters['per_page'])->withQueryString();
    }

    /**
     * 测试环境缺少 articles 表时，返回空分页并保持页面可渲染。
     */
    private function emptyArticlesPaginator(int $perPage): LengthAwarePaginator
    {
        return new LengthAwarePaginator(
            items: collect(),
            total: 0,
            perPage: $perPage,
            currentPage: max(1, (int) request()->query('page', 1)),
            options: [
                'path' => request()->url(),
                'query' => request()->query(),
            ],
        );
    }

    /**
     * @return array{total: int, published: int, draft: int, pending_review: int, today: int}
     */
    private function loadStats(): array
    {
        $baseQuery = Article::query();

        return [
            'total' => (clone $baseQuery)->count(),
            'published' => (clone $baseQuery)->where('status', 'published')->count(),
            'draft' => (clone $baseQuery)->where('status', 'draft')->count(),
            'pending_review' => (clone $baseQuery)->where('review_status', 'pending')->count(),
            'today' => (clone $baseQuery)->whereDate('created_at', Carbon::today())->count(),
        ];
    }

    /**
     * @return array{trashed_total: int}
     */
    private function loadTrashStats(): array
    {
        return [
            'trashed_total' => Article::onlyTrashed()->count(),
        ];
    }

    /**
     * @return array<int, array{id: int, name: string}>
     */
    private function loadTaskOptions(): array
    {
        try {
            return Task::query()
                ->select(['id', 'name'])
                ->orderBy('name')
                ->get()
                ->map(fn (Task $task): array => [
                    'id' => (int) $task->id,
                    'name' => (string) $task->name,
                ])
                ->all();
        } catch (QueryException) {
            return [];
        }
    }

    /**
     * @return array<int, array{id: int, name: string}>
     */
    private function loadAuthorOptions(): array
    {
        try {
            return Author::query()
                ->select(['id', 'name'])
                ->orderBy('name')
                ->get()
                ->map(fn (Author $author): array => [
                    'id' => (int) $author->id,
                    'name' => (string) $author->name,
                ])
                ->all();
        } catch (QueryException) {
            return [];
        }
    }

    /**
     * @return array{
     *     categories: array<int, array{id: int, name: string}>,
     *     authors: array<int, array{id: int, name: string}>
     * }
     */
    private function loadFormOptions(): array
    {
        $categories = [];
        $authors = $this->loadAuthorOptions();

        try {
            $categories = Category::query()
                ->select(['id', 'name'])
                ->orderBy('name')
                ->get()
                ->map(fn (Category $category): array => [
                    'id' => (int) $category->id,
                    'name' => (string) $category->name,
                ])
                ->all();
        } catch (QueryException) {
            $categories = [];
        }

        return [
            'categories' => $categories,
            'authors' => $authors,
        ];
    }

    /**
     * @return array{
     *     title: string,
     *     excerpt: string,
     *     content: string,
     *     keywords: string,
     *     meta_description: string,
     *     category_id: int,
     *     author_id: int,
     *     status: string,
     *     review_status: string
     *     is_hot: bool,
     *     is_featured: bool
     * }
     */
    private function validateArticleForm(Request $request, bool $isEdit): array
    {
        $keyPrefix = $isEdit ? 'admin.article_edit.error' : 'admin.article_create.error';

        return $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'excerpt' => ['nullable', 'string'],
            'content' => ['required', 'string'],
            'keywords' => ['nullable', 'string', 'max:500'],
            'meta_description' => ['nullable', 'string', 'max:500'],
            'category_id' => ['required', 'integer', 'min:1'],
            'author_id' => ['required', 'integer', 'min:1'],
            'status' => ['required', 'string', 'in:draft,published,private'],
            'review_status' => ['required', 'string', 'in:pending,approved,rejected,auto_approved'],
            'is_hot' => ['nullable', 'boolean'],
            'is_featured' => ['nullable', 'boolean'],
        ], [
            'title.required' => __($keyPrefix.'.title_required'),
            'content.required' => __($keyPrefix.'.content_required'),
            'category_id.required' => __($keyPrefix.'.category_required'),
            'category_id.min' => __($keyPrefix.'.category_required'),
            'author_id.required' => __($keyPrefix.'.author_required'),
            'author_id.min' => __($keyPrefix.'.author_required'),
        ]);
    }

    /**
     * @return array<int, int>
     */
    private function extractArticleIds(Request $request): array
    {
        return collect($request->input('article_ids', []))
            ->map(static fn ($id): int => (int) $id)
            ->filter(static fn (int $id): bool => $id > 0)
            ->values()
            ->all();
    }

    /**
     * @param  array<int, int>  $articleIds
     */
    private function handleBatchUpdateStatus(Request $request, array $articleIds): RedirectResponse
    {
        $newStatus = (string) $request->input('new_status', '');
        if (! in_array($newStatus, ['draft', 'published', 'private'], true)) {
            return back()->withErrors(__('admin.articles.message.select_status'));
        }

        $articles = Article::query()
            ->select(['id', 'review_status', 'published_at'])
            ->whereIn('id', $articleIds)
            ->get();

        foreach ($articles as $article) {
            $workflowState = ArticleWorkflow::normalizeState(
                $newStatus,
                (string) ($article->review_status ?? 'pending'),
                $article->published_at?->format('Y-m-d H:i:s')
            );

            Article::query()->whereKey((int) $article->id)->update([
                'status' => $workflowState['status'],
                'review_status' => $workflowState['review_status'],
                'published_at' => $workflowState['published_at'],
            ]);
        }

        return back()->with('message', __('admin.articles.message.batch_status_updated', ['count' => count($articleIds)]));
    }

    /**
     * @param  array<int, int>  $articleIds
     */
    private function handleBatchUpdateReview(Request $request, array $articleIds): RedirectResponse
    {
        $reviewStatus = (string) $request->input('review_status', '');
        if (! in_array($reviewStatus, ['pending', 'approved', 'rejected', 'auto_approved'], true)) {
            return back()->withErrors(__('admin.articles.message.select_review'));
        }

        $articles = Article::query()
            ->with(['task:id,need_review'])
            ->select(['id', 'status', 'review_status', 'published_at', 'task_id'])
            ->whereIn('id', $articleIds)
            ->get();

        foreach ($articles as $article) {
            $desiredStatus = (string) ($article->status ?? 'draft');
            $needsReview = (int) ($article->task->need_review ?? 0);
            if (in_array($reviewStatus, ['approved', 'auto_approved'], true) && ($reviewStatus === 'auto_approved' || $needsReview === 0)) {
                $desiredStatus = 'published';
            }

            $workflowState = ArticleWorkflow::normalizeState(
                $desiredStatus,
                $reviewStatus,
                $article->published_at?->format('Y-m-d H:i:s')
            );

            Article::query()->whereKey((int) $article->id)->update([
                'status' => $workflowState['status'],
                'review_status' => $workflowState['review_status'],
                'published_at' => $workflowState['published_at'],
            ]);
        }

        return back()->with('message', __('admin.articles.message.batch_review_updated', ['count' => count($articleIds)]));
    }

    /**
     * @param  array<int, int>  $articleIds
     */
    private function handleBatchDelete(array $articleIds): RedirectResponse
    {
        $articles = Article::query()->whereIn('id', $articleIds)->get();
        foreach ($articles as $article) {
            Article::query()->whereKey((int) $article->id)->delete();
        }

        return back()->with('message', __('admin.articles.message.batch_delete_success', ['count' => count($articleIds)]));
    }

    /**
     * 前端批量栏与快捷动作使用的文案字典。
     *
     * @return array<string, string>
     */
    private function articlesI18n(): array
    {
        return [
            'selectArticles' => __('admin.articles.message.select_articles'),
            'selectAction' => __('admin.articles.message.select_action'),
            'selectStatus' => __('admin.articles.message.select_status'),
            'selectReview' => __('admin.articles.message.select_review'),
            'confirmDeleteSelected' => __('admin.articles.confirm.delete_selected', ['count' => '__COUNT__']),
            'reviewApproved' => __('admin.articles.review.approved'),
            'reviewRejected' => __('admin.articles.review.rejected'),
            'confirmQuickReview' => __('admin.articles.confirm.quick_review', ['action' => '__ACTION__']),
            'confirmDelete' => __('admin.articles.confirm.delete'),
        ];
    }

    /**
     * 垃圾箱视图脚本使用的确认与操作文案。
     *
     * @return array<string, string>
     */
    private function trashI18n(): array
    {
        return [
            'alertSelect' => __('admin.articles.trash.alert_select'),
            'confirmBatchRestore' => __('admin.articles.trash.confirm_batch_restore', ['count' => '__COUNT__']),
            'confirmBatchForceDelete' => __('admin.articles.trash.confirm_batch_delete', ['count' => '__COUNT__']),
            'confirmEmpty' => __('admin.articles.trash.confirm_empty'),
        ];
    }

    /**
     * 批量操作表单提交目标 URL（普通列表与垃圾箱不同）。
     *
     * @return array<string, string>
     */
    private function articleBatchRoutes(bool $isTrashView): array
    {
        if ($isTrashView) {
            return [
                'batch_restore' => route('admin.articles.batch.restore'),
                'batch_force_delete' => route('admin.articles.batch.force-delete'),
            ];
        }

        return [
            'batch_update_status' => route('admin.articles.batch.update-status'),
            'batch_update_review' => route('admin.articles.batch.update-review'),
            'delete_articles' => route('admin.articles.batch.delete'),
        ];
    }
}
