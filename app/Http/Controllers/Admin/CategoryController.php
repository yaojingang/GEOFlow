<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Category;
use App\Models\Task;
use App\Services\GeoFlow\CategorySlugRegistry;
use App\Services\Site\UrlChangeGuard;
use App\Support\AdminWeb;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/**
 * 栏目（分类）管理页：
 * - 列表：index
 * - 新增：create/store
 * - 编辑：edit/update
 * - 删除：destroy
 */
class CategoryController extends Controller
{
    public function __construct(private readonly CategorySlugRegistry $slugs, private readonly UrlChangeGuard $urlGuard) {}

    /**
     * 栏目列表页。
     */
    public function index(): View
    {
        return view('admin.categories.index', [
            'pageTitle' => __('admin.categories.page_title'),
            'activeMenu' => 'articles',
            'adminSiteName' => AdminWeb::siteName(),
            'categories' => $this->loadCategories(),
        ]);
    }

    /**
     * 新增分类表单页。
     */
    public function create(): View
    {
        return view('admin.categories.form', [
            'pageTitle' => __('admin.categories.page_title'),
            'activeMenu' => 'articles',
            'adminSiteName' => AdminWeb::siteName(),
            'isEdit' => false,
            'categoryId' => 0,
            'categoryForm' => $this->emptyCategoryForm(),
        ]);
    }

    /**
     * 创建分类。
     */
    public function store(Request $request): RedirectResponse
    {
        $payload = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'slug' => ['nullable', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'sort_order' => ['nullable', 'integer', 'min:0'],
        ], [
            'name.required' => __('admin.categories.error.name_required'),
        ]);

        $name = trim((string) $payload['name']);
        $description = trim((string) ($payload['description'] ?? ''));
        $sortOrder = (int) ($payload['sort_order'] ?? 0);

        if (Category::query()->where('name', $name)->exists()) {
            return back()->withInput()->withErrors(__('admin.categories.error.name_exists'));
        }

        DB::transaction(function () use ($name, $payload, $description, $sortOrder): void {
            $slug = $this->buildCategorySlug($name, (string) ($payload['slug'] ?? ''), 0);
            Category::query()->create(['name' => $name, 'slug' => $slug, 'description' => $description, 'sort_order' => $sortOrder]);
        }, 3);

        return redirect()->route('admin.categories.index')->with('message', __('admin.categories.message.add_success'));
    }

    /**
     * 编辑分类表单页。
     */
    public function edit(int $categoryId): View|RedirectResponse
    {
        $category = Category::query()->whereKey($categoryId)->firstOrFail();

        return view('admin.categories.form', [
            'pageTitle' => __('admin.categories.page_title'),
            'activeMenu' => 'articles',
            'adminSiteName' => AdminWeb::siteName(),
            'isEdit' => true,
            'categoryId' => (int) $category->id,
            'categoryForm' => [
                'name' => (string) $category->name,
                'slug' => (string) ($category->slug ?? ''),
                'description' => (string) ($category->description ?? ''),
                'sort_order' => (int) ($category->sort_order ?? 0),
            ],
        ]);
    }

    /**
     * 更新分类。
     */
    public function update(Request $request, int $categoryId): RedirectResponse
    {
        $category = Category::query()->whereKey($categoryId)->firstOrFail();

        $payload = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'slug' => ['nullable', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'sort_order' => ['nullable', 'integer', 'min:0'],
        ], [
            'name.required' => __('admin.categories.error.name_required'),
        ]);

        $name = trim((string) $payload['name']);
        $description = trim((string) ($payload['description'] ?? ''));
        $sortOrder = (int) ($payload['sort_order'] ?? 0);
        $slug = (string) $category->slug;
        if (array_key_exists('slug', $payload) && (string) $payload['slug'] !== $slug) {
            if (trim((string) $payload['slug']) === '') {
                throw ValidationException::withMessages(['slug' => __('url_change.errors.category_slug_invalid')]);
            }
            $this->urlGuard->category($category, (string) $payload['slug'], $request->user('admin'));
        }

        $duplicateQuery = Category::query()->where('name', $name)->where('id', '!=', $categoryId);
        if ($duplicateQuery->exists()) {
            return back()->withInput()->withErrors(__('admin.categories.error.name_exists'));
        }

        $category->update([
            'name' => $name,
            'description' => $description,
            'sort_order' => $sortOrder,
        ]);

        return redirect()->route('admin.categories.index')->with('message', __('admin.categories.message.update_success'));
    }

    /**
     * 删除分类：若分类下仍有关联文章则阻止删除。
     */
    public function destroy(int $categoryId): RedirectResponse
    {
        $category = Category::query()
            ->withCount(['articlesIncludingTrashed as all_articles_count'])
            ->whereKey($categoryId)
            ->firstOrFail();

        if ((int) ($category->all_articles_count ?? 0) > 0) {
            return back()->withErrors(__('admin.categories.error.delete_blocked', ['count' => (int) $category->all_articles_count]));
        }
        $taskCount = Task::withTrashed()->where('fixed_category_id', $categoryId)->count();
        if ($taskCount > 0) {
            return back()->withErrors(__('admin.categories.error.delete_tasks', ['count' => $taskCount]));
        }

        DB::transaction(function () use ($categoryId): void {
            $locked = Category::query()->whereKey($categoryId)->lockForUpdate()->firstOrFail();
            if ($locked->articlesIncludingTrashed()->exists() || Task::withTrashed()->where('fixed_category_id', $categoryId)->exists()) {
                throw ValidationException::withMessages(['category' => __('admin.categories.error.delete_blocked', ['count' => $locked->articlesIncludingTrashed()->count()])]);
            }
            $this->slugs->rememberDeleted($locked);
            $locked->delete();
        }, 3);

        return redirect()->route('admin.categories.index')->with('message', __('admin.categories.message.delete_success'));
    }

    /**
     * 读取分类列表并附带文章数量。
     *
     * @return array<int, array{id:int,name:string,slug:string,description:string,sort_order:int,article_count:int,active_article_count:int,trashed_article_count:int,created_at:?string}>
     */
    private function loadCategories(): array
    {
        $query = Category::query()
            ->select(['id', 'name', 'slug', 'description', 'sort_order', 'created_at'])
            ->withCount([
                'articles',
                'articlesIncludingTrashed as all_articles_count',
            ])
            ->orderBy('sort_order')
            ->orderBy('name');

        return $query->get()
            ->map(static function (Category $category): array {
                $activeArticleCount = (int) ($category->articles_count ?? 0);
                $allArticleCount = (int) ($category->all_articles_count ?? $activeArticleCount);

                return [
                    'id' => (int) $category->id,
                    'name' => (string) $category->name,
                    'slug' => (string) ($category->slug ?? ''),
                    'description' => (string) ($category->description ?? ''),
                    'sort_order' => (int) ($category->sort_order ?? 0),
                    'article_count' => $allArticleCount,
                    'active_article_count' => $activeArticleCount,
                    'trashed_article_count' => max(0, $allArticleCount - $activeArticleCount),
                    'created_at' => $category->created_at?->format('Y-m-d H:i:s'),
                ];
            })
            ->all();
    }

    /**
     * 生成唯一 slug：优先使用手输 slug，缺省时按名称生成。
     */
    private function buildCategorySlug(string $name, string $rawSlug = '', int $excludeId = 0): string
    {
        $registry = app(CategorySlugRegistry::class);
        if (trim($rawSlug) === '') {
            return $registry->generate($name, $excludeId > 0 ? $excludeId : null);
        }
        $slug = $registry->normalize($rawSlug);
        $registry->assertAvailable($slug, $excludeId > 0 ? $excludeId : null);

        return $slug;
    }

    /**
     * 返回空表单结构，保证 create 页数据结构稳定。
     *
     * @return array{name:string,slug:string,description:string,sort_order:int}
     */
    private function emptyCategoryForm(): array
    {
        return [
            'name' => '',
            'slug' => '',
            'description' => '',
            'sort_order' => 0,
        ];
    }
}
