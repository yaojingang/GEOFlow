<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Category;
use App\Support\AdminWeb;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
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
        $slug = $this->buildCategorySlug($name, (string) ($payload['slug'] ?? ''), 0);

        if (Category::query()->where('name', $name)->exists()) {
            return back()->withInput()->withErrors(__('admin.categories.error.name_exists'));
        }

        Category::query()->create([
            'name' => $name,
            'slug' => $slug,
            'description' => $description,
            'sort_order' => $sortOrder,
        ]);

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
        $slug = $this->buildCategorySlug($name, (string) ($payload['slug'] ?? ''), $categoryId);

        $duplicateQuery = Category::query()->where('name', $name)->where('id', '!=', $categoryId);
        if ($duplicateQuery->exists()) {
            return back()->withInput()->withErrors(__('admin.categories.error.name_exists'));
        }

        $category->update([
            'name' => $name,
            'slug' => $slug,
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
        $category = Category::query()->withCount('articles')->whereKey($categoryId)->firstOrFail();

        if ((int) ($category->articles_count ?? 0) > 0) {
            return back()->withErrors(__('admin.categories.error.delete_blocked', ['count' => (int) $category->articles_count]));
        }

        Category::query()->whereKey($categoryId)->delete();

        return redirect()->route('admin.categories.index')->with('message', __('admin.categories.message.delete_success'));
    }

    /**
     * 读取分类列表并附带文章数量。
     *
     * @return array<int, array{id:int,name:string,slug:string,description:string,sort_order:int,article_count:int,created_at:?string}>
     */
    private function loadCategories(): array
    {
        $query = Category::query()
            ->select(['id', 'name', 'slug', 'description', 'sort_order', 'created_at'])
            ->withCount('articles')
            ->orderBy('sort_order')
            ->orderBy('name');

        return $query->get()
            ->map(static function (Category $category): array {
                return [
                    'id' => (int) $category->id,
                    'name' => (string) $category->name,
                    'slug' => (string) ($category->slug ?? ''),
                    'description' => (string) ($category->description ?? ''),
                    'sort_order' => (int) ($category->sort_order ?? 0),
                    'article_count' => (int) ($category->articles_count ?? 0),
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
        $source = trim($rawSlug) !== '' ? trim($rawSlug) : trim($name);
        $slug = mb_strtolower($source, 'UTF-8');
        $slug = preg_replace('/[^a-z0-9]+/i', '-', $slug) ?: '';
        $slug = trim((string) $slug, '-');

        if ($slug === '') {
            $slug = 'cat-'.substr(md5($name), 0, 8);
        }

        $baseSlug = $slug;
        $counter = 2;
        while (true) {
            $existsQuery = Category::query()->where('slug', $slug);
            if ($excludeId > 0) {
                $existsQuery->where('id', '!=', $excludeId);
            }
            if (! $existsQuery->exists()) {
                return $slug;
            }
            $slug = $baseSlug.'-'.$counter;
            $counter++;
        }
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
