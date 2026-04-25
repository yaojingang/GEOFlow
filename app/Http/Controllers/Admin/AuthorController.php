<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Article;
use App\Models\Author;
use App\Support\AdminWeb;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\View\View;

/**
 * 作者管理控制器。
 */
class AuthorController extends Controller
{
    private const INDEX_PER_PAGE = 20;

    /**
     * 作者列表页。
     */
    public function index(Request $request): View
    {
        $search = trim((string) $request->query('search', ''));

        $authors = $this->loadAuthors($search);

        return view('admin.authors.index', [
            'pageTitle' => __('admin.authors.page_title'),
            'activeMenu' => 'materials',
            'adminSiteName' => AdminWeb::siteName(),
            'search' => $search,
            'authors' => $authors->items(),
            'authorsPagination' => $authors,
            'stats' => $this->loadStats(),
        ]);
    }

    /**
     * 作者详情页。
     */
    public function detail(int $authorId): View|RedirectResponse
    {
        $author = Author::query()->whereKey($authorId)->firstOrFail();

        $articles = Article::query()
            ->select(['id', 'title', 'status', 'review_status', 'created_at', 'deleted_at'])
            ->where('author_id', $authorId)
            ->orderByDesc('created_at')
            ->limit(10)
            ->get();

        return view('admin.authors.detail', [
            'pageTitle' => __('admin.authors.page_title'),
            'activeMenu' => 'materials',
            'adminSiteName' => AdminWeb::siteName(),
            'author' => $author,
            'articles' => $articles,
        ]);
    }

    /**
     * 创建作者表单页。
     */
    public function create(): View
    {
        return view('admin.authors.form', [
            'pageTitle' => __('admin.authors.page_title'),
            'activeMenu' => 'materials',
            'adminSiteName' => AdminWeb::siteName(),
            'isEdit' => false,
            'authorId' => 0,
            'authorForm' => $this->emptyAuthorForm(),
        ]);
    }

    /**
     * 创建作者。
     */
    public function store(Request $request): RedirectResponse
    {
        $payload = $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'email' => ['nullable', 'string', 'max:100'],
            'bio' => ['nullable', 'string'],
            'website' => ['nullable', 'string', 'max:200'],
            'social_links' => ['nullable', 'string'],
        ], [
            'name.required' => __('admin.authors.error.name_required'),
        ]);

        Author::query()->create([
            'name' => trim((string) $payload['name']),
            'email' => trim((string) ($payload['email'] ?? '')),
            'bio' => trim((string) ($payload['bio'] ?? '')),
            'website' => trim((string) ($payload['website'] ?? '')),
            'social_links' => trim((string) ($payload['social_links'] ?? '')),
        ]);

        return redirect()->route('admin.authors.index')->with('message', __('admin.authors.message.create_success'));
    }

    /**
     * 编辑作者表单页。
     */
    public function edit(int $authorId): View|RedirectResponse
    {
        $author = Author::query()->whereKey($authorId)->firstOrFail();

        return view('admin.authors.form', [
            'pageTitle' => __('admin.authors.page_title'),
            'activeMenu' => 'materials',
            'adminSiteName' => AdminWeb::siteName(),
            'isEdit' => true,
            'authorId' => (int) $author->id,
            'authorForm' => [
                'name' => (string) $author->name,
                'email' => (string) ($author->email ?? ''),
                'bio' => (string) ($author->bio ?? ''),
                'website' => (string) ($author->website ?? ''),
                'social_links' => (string) ($author->social_links ?? ''),
            ],
        ]);
    }

    /**
     * 更新作者。
     */
    public function update(Request $request, int $authorId): RedirectResponse
    {
        $author = Author::query()->whereKey($authorId)->firstOrFail();

        $payload = $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'email' => ['nullable', 'string', 'max:100'],
            'bio' => ['nullable', 'string'],
            'website' => ['nullable', 'string', 'max:200'],
            'social_links' => ['nullable', 'string'],
        ], [
            'name.required' => __('admin.authors.error.name_required'),
        ]);

        $author->update([
            'name' => trim((string) $payload['name']),
            'email' => trim((string) ($payload['email'] ?? '')),
            'bio' => trim((string) ($payload['bio'] ?? '')),
            'website' => trim((string) ($payload['website'] ?? '')),
            'social_links' => trim((string) ($payload['social_links'] ?? '')),
        ]);

        return redirect()->route('admin.authors.index')->with('message', __('admin.authors.message.update_success'));
    }

    /**
     * 删除作者（仍被文章引用时阻止删除）。
     */
    public function destroy(int $authorId): RedirectResponse
    {
        $author = Author::query()->whereKey($authorId)->firstOrFail();

        $visibleCount = Article::query()->where('author_id', $authorId)->whereNull('deleted_at')->count();
        if ($visibleCount > 0) {
            return back()->withErrors(__('admin.authors.error.delete_visible', ['count' => $visibleCount]));
        }

        $trashedCount = Article::query()->where('author_id', $authorId)->whereNotNull('deleted_at')->count();
        if ($trashedCount > 0) {
            return back()->withErrors(__('admin.authors.error.delete_trashed', ['count' => $trashedCount]));
        }

        $author->delete();

        return redirect()->route('admin.authors.index')->with('message', __('admin.authors.message.delete_success'));
    }

    /**
     * 加载作者列表。
     *
     * @return array<int, array<string, mixed>>
     */
    private function loadAuthors(string $search): LengthAwarePaginator
    {
        $query = Author::query()
            ->select(['id', 'name', 'email', 'bio', 'website', 'social_links', 'created_at'])
            ->orderByDesc('created_at');

        if ($search !== '') {
            $query->where(function ($builder) use ($search): void {
                $builder->where('name', 'like', '%'.$search.'%')
                    ->orWhere('email', 'like', '%'.$search.'%')
                    ->orWhere('bio', 'like', '%'.$search.'%');
            });
        }

        $query->withCount([
            'articles as article_count' => fn ($builder) => $builder->whereNull('deleted_at'),
            'articles as published_count' => fn ($builder) => $builder->where('status', 'published')->whereNull('deleted_at'),
            'articles as trashed_count' => fn ($builder) => $builder->whereNotNull('deleted_at'),
        ]);

        return $query->paginate(self::INDEX_PER_PAGE)->withQueryString()->through(static function (Author $author): array {
            return [
                'id' => (int) $author->id,
                'name' => (string) $author->name,
                'email' => (string) ($author->email ?? ''),
                'bio' => (string) ($author->bio ?? ''),
                'website' => (string) ($author->website ?? ''),
                'social_links' => (string) ($author->social_links ?? ''),
                'created_at' => $author->created_at?->format('Y-m-d H:i:s'),
                'article_count' => (int) ($author->article_count ?? 0),
                'published_count' => (int) ($author->published_count ?? 0),
                'trashed_count' => (int) ($author->trashed_count ?? 0),
            ];
        });
    }

    /**
     * 加载统计卡片数据。
     *
     * @return array{total_authors:int,active_authors:int,avg_articles:float}
     */
    private function loadStats(): array
    {
        $totalAuthors = Author::query()->count();
        $activeAuthors = Article::query()
            ->whereNotNull('author_id')
            ->whereNull('deleted_at')
            ->distinct('author_id')
            ->count('author_id');
        $totalArticles = Article::query()->whereNotNull('author_id')->whereNull('deleted_at')->count();

        return [
            'total_authors' => $totalAuthors,
            'active_authors' => $activeAuthors,
            'avg_articles' => $totalAuthors > 0 ? round($totalArticles / $totalAuthors, 1) : 0.0,
        ];
    }

    /**
     * 创建页空表单结构。
     *
     * @return array{name:string,email:string,bio:string,website:string,social_links:string}
     */
    private function emptyAuthorForm(): array
    {
        return [
            'name' => '',
            'email' => '',
            'bio' => '',
            'website' => '',
            'social_links' => '',
        ];
    }
}
