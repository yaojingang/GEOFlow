<?php

namespace App\Services\GeoFlow;

use App\Exceptions\ApiException;
use App\Models\Article;
use App\Models\ArticleImage;
use App\Models\ArticleReview;
use App\Models\Author;
use App\Models\Category;
use App\Models\Task;
use App\Support\GeoFlow\ArticleWorkflow;
use Illuminate\Support\Facades\DB;

class ArticleGeoFlowService
{
    public function listArticles(int $page = 1, int $perPage = 20, array $filters = []): array
    {
        $page = max(1, $page);
        $perPage = max(1, min(100, $perPage));

        $query = Article::query();

        foreach (['task_id', 'status', 'review_status', 'author_id'] as $key) {
            if (! empty($filters[$key])) {
                $query->where($key, $filters[$key]);
            }
        }

        if (! empty($filters['search'])) {
            $s = '%'.$filters['search'].'%';
            $query->where(function ($q) use ($s) {
                $q->where('title', 'like', $s)->orWhere('content', 'like', $s);
            });
        }

        $total = (clone $query)->count();

        $items = $query
            ->orderByDesc('created_at')
            ->forPage($page, $perPage)
            ->get([
                'id', 'title', 'slug', 'status', 'review_status',
                'task_id', 'author_id', 'category_id', 'published_at',
                'created_at', 'updated_at',
            ])
            ->map(fn (Article $a) => $a->getAttributes())
            ->all();

        return [
            'items' => $items,
            'pagination' => [
                'page' => $page,
                'per_page' => $perPage,
                'total' => $total,
                'total_pages' => (int) ceil($total / $perPage),
            ],
        ];
    }

    public function createArticle(array $data): array
    {
        $normalized = $this->normalizeCreateInput($data);
        $workflowState = ArticleWorkflow::normalizeState(
            $normalized['status'],
            $normalized['review_status']
        );
        $slug = $normalized['slug'] ?: ArticleWorkflow::generateUniqueSlug($normalized['title']);
        $excerpt = $normalized['excerpt'] !== '' ? $normalized['excerpt'] : mb_substr(strip_tags($normalized['content']), 0, 200);

        $article = Article::query()->create([
            'title' => $normalized['title'],
            'slug' => $slug,
            'content' => $normalized['content'],
            'excerpt' => $excerpt,
            'keywords' => $normalized['keywords'],
            'meta_description' => $normalized['meta_description'],
            'category_id' => $normalized['category_id'],
            'author_id' => $normalized['author_id'],
            'task_id' => $normalized['task_id'],
            'status' => $workflowState['status'],
            'review_status' => $workflowState['review_status'],
            'is_ai_generated' => $normalized['is_ai_generated'],
            'published_at' => $workflowState['published_at'],
        ]);

        return $this->getArticle((int) $article->id);
    }

    public function getArticle(int $articleId): array
    {
        $article = Article::query()
            ->with(['task:id,name', 'author:id,name', 'category:id,name'])
            ->find($articleId);
        if (! $article) {
            throw new ApiException('article_not_found', '文章不存在', 404);
        }

        $images = ArticleImage::query()
            ->where('article_id', $articleId)
            ->with('image:id,file_path,original_name')
            ->orderBy('position')
            ->orderBy('id')
            ->get()
            ->map(fn (ArticleImage $ai) => [
                'id' => $ai->id,
                'image_id' => $ai->image_id,
                'position' => $ai->position,
                'file_path' => $ai->image->file_path ?? null,
                'original_name' => $ai->image->original_name ?? null,
            ])
            ->all();

        return [
            'id' => (int) $article->id,
            'title' => $article->title,
            'slug' => $article->slug,
            'content' => $article->content,
            'excerpt' => $article->excerpt,
            'keywords' => $article->keywords,
            'meta_description' => $article->meta_description,
            'status' => $article->status,
            'review_status' => $article->review_status,
            'task_id' => $this->nullableInt($article->task_id),
            'task_name' => $article->task->name ?? null,
            'author_id' => $this->nullableInt($article->author_id),
            'author_name' => $article->author->name ?? null,
            'category_id' => $this->nullableInt($article->category_id),
            'category_name' => $article->category->name ?? null,
            'published_at' => $article->published_at?->format('Y-m-d H:i:s'),
            'created_at' => $article->created_at?->format('Y-m-d H:i:s'),
            'updated_at' => $article->updated_at?->format('Y-m-d H:i:s'),
            'images' => $images,
        ];
    }

    public function updateArticle(int $articleId, array $data): array
    {
        $existing = $this->getArticleRecord($articleId);
        $normalized = $this->normalizeUpdateInput($data, $existing);
        if (empty($normalized)) {
            throw new ApiException('validation_failed', '没有可更新的字段', 422);
        }

        $normalized['updated_at'] = now();

        Article::query()->whereKey($articleId)->update($normalized);

        return $this->getArticle($articleId);
    }

    public function reviewArticle(int $articleId, string $reviewStatus, string $reviewNote, int $auditAdminId): array
    {
        $article = $this->getArticleRecord($articleId);
        $reviewStatus = trim($reviewStatus);
        if (! in_array($reviewStatus, ['pending', 'approved', 'rejected', 'auto_approved'], true)) {
            throw new ApiException('validation_failed', '审核状态无效', 422, [
                'field_errors' => ['review_status' => '审核状态无效'],
            ]);
        }

        $desiredStatus = $article['status'] ?? 'draft';
        if (in_array($reviewStatus, ['approved', 'auto_approved'], true)) {
            $taskNeedReview = 1;
            if (! empty($article['task_id'])) {
                $taskNeedReview = (int) (Task::query()
                    ->whereKey((int) $article['task_id'])
                    ->value('need_review') ?? 1);
            }

            if ($reviewStatus === 'auto_approved' || $taskNeedReview === 0) {
                $desiredStatus = 'published';
            }
        }

        $workflowState = ArticleWorkflow::normalizeState(
            $desiredStatus,
            $reviewStatus,
            $article['published_at'] ?? null
        );

        DB::transaction(function () use ($articleId, $workflowState, $reviewStatus, $reviewNote, $auditAdminId) {
            Article::query()->whereKey($articleId)->update([
                'status' => $workflowState['status'],
                'review_status' => $workflowState['review_status'],
                'published_at' => $workflowState['published_at'],
                'updated_at' => now(),
            ]);

            ArticleReview::query()->create([
                'article_id' => $articleId,
                'admin_id' => $auditAdminId,
                'review_status' => $reviewStatus,
                'review_note' => trim($reviewNote),
            ]);
        });

        return $this->getArticle($articleId);
    }

    public function publishArticle(int $articleId): array
    {
        $article = $this->getArticleRecord($articleId);
        $reviewStatus = $article['review_status'] ?? 'pending';
        if (! in_array($reviewStatus, ['approved', 'auto_approved'], true)) {
            throw new ApiException('article_not_publishable', '当前文章状态不允许直接发布', 409);
        }

        $workflowState = ArticleWorkflow::normalizeState(
            'published',
            $reviewStatus,
            $article['published_at'] ?? null
        );

        Article::query()->whereKey($articleId)->update([
            'status' => $workflowState['status'],
            'review_status' => $workflowState['review_status'],
            'published_at' => $workflowState['published_at'],
            'updated_at' => now(),
        ]);

        return $this->getArticle($articleId);
    }

    public function trashArticle(int $articleId): array
    {
        $article = Article::query()->whereKey($articleId)->first();
        if (! $article) {
            throw new ApiException('article_not_found', '文章不存在', 404);
        }

        $article->delete();

        return [
            'id' => $articleId,
            'trashed' => true,
        ];
    }

    private function normalizeCreateInput(array $data): array
    {
        $title = trim((string) ($data['title'] ?? ''));
        $content = trim((string) ($data['content'] ?? ''));
        if ($title === '' || $content === '') {
            $errors = [];
            if ($title === '') {
                $errors['title'] = '文章标题不能为空';
            }
            if ($content === '') {
                $errors['content'] = '文章内容不能为空';
            }
            throw new ApiException('validation_failed', '参数校验失败', 422, ['field_errors' => $errors]);
        }

        $normalized = [
            'title' => $title,
            'content' => $content,
            'excerpt' => trim((string) ($data['excerpt'] ?? '')),
            'keywords' => trim((string) ($data['keywords'] ?? '')),
            'meta_description' => trim((string) ($data['meta_description'] ?? '')),
            'status' => trim((string) ($data['status'] ?? 'draft')),
            'review_status' => trim((string) ($data['review_status'] ?? 'pending')),
            'is_ai_generated' => $this->toFlag($data['is_ai_generated'] ?? 0),
        ];

        $normalized['slug'] = null;
        if (! empty($data['slug'])) {
            $slug = trim((string) $data['slug']);
            $this->ensureSlugAvailable($slug);
            $normalized['slug'] = $slug;
        }

        $normalized['category_id'] = $this->normalizeReference(Category::class, $data['category_id'] ?? null, 'category_id', true);
        $normalized['author_id'] = $this->normalizeReference(Author::class, $data['author_id'] ?? null, 'author_id', true);
        $normalized['task_id'] = $this->normalizeNullableReference(Task::class, $data['task_id'] ?? null, 'task_id');

        return $normalized;
    }

    /**
     * @param  array<string, mixed>  $existing
     */
    private function normalizeUpdateInput(array $data, array $existing): array
    {
        $normalized = [];
        $fieldErrors = [];

        if (array_key_exists('title', $data)) {
            $title = trim((string) $data['title']);
            if ($title === '') {
                $fieldErrors['title'] = '文章标题不能为空';
            } else {
                $normalized['title'] = $title;
            }
        }

        if (array_key_exists('content', $data)) {
            $content = trim((string) $data['content']);
            if ($content === '') {
                $fieldErrors['content'] = '文章内容不能为空';
            } else {
                $normalized['content'] = $content;
            }
        }

        foreach (['excerpt', 'keywords', 'meta_description'] as $field) {
            if (array_key_exists($field, $data)) {
                $normalized[$field] = trim((string) $data[$field]);
            }
        }

        if (array_key_exists('category_id', $data)) {
            $normalized['category_id'] = $this->normalizeReference(Category::class, $data['category_id'], 'category_id', true);
        }

        if (array_key_exists('author_id', $data)) {
            $normalized['author_id'] = $this->normalizeReference(Author::class, $data['author_id'], 'author_id', true);
        }

        if (array_key_exists('task_id', $data)) {
            $normalized['task_id'] = $this->normalizeNullableReference(Task::class, $data['task_id'], 'task_id');
        }

        if (array_key_exists('slug', $data)) {
            $slug = trim((string) $data['slug']);
            if ($slug === '') {
                $fieldErrors['slug'] = 'slug 不能为空';
            } else {
                $this->ensureSlugAvailable($slug, (int) $existing['id']);
                $normalized['slug'] = $slug;
            }
        } elseif (isset($normalized['title']) && $normalized['title'] !== $existing['title']) {
            $normalized['slug'] = ArticleWorkflow::generateUniqueSlug($normalized['title'], (int) $existing['id']);
        }

        if (! empty($fieldErrors)) {
            throw new ApiException('validation_failed', '参数校验失败', 422, ['field_errors' => $fieldErrors]);
        }

        return $normalized;
    }

    /**
     * @return array<string, mixed>
     */
    private function getArticleRecord(int $articleId): array
    {
        $article = Article::query()->whereKey($articleId)->first();
        if (! $article) {
            throw new ApiException('article_not_found', '文章不存在', 404);
        }

        return $article->getAttributes();
    }

    private function normalizeNullableReference(string $modelClass, mixed $value, string $field): ?int
    {
        return $this->normalizeReference($modelClass, $value, $field, false);
    }

    private function normalizeReference(string $modelClass, mixed $value, string $field, bool $required = false): ?int
    {
        if ($value === null || $value === '' || (int) $value <= 0) {
            if ($required) {
                throw new ApiException('validation_failed', '参数校验失败', 422, [
                    'field_errors' => [$field => $this->requiredReferenceMessage($field)],
                ]);
            }

            return null;
        }

        $id = (int) $value;
        if (! $modelClass::query()->whereKey($id)->exists()) {
            throw new ApiException('validation_failed', '参数校验失败', 422, [
                'field_errors' => [$field => "{$field} 对应资源不存在"],
            ]);
        }

        return $id;
    }

    private function requiredReferenceMessage(string $field): string
    {
        return match ($field) {
            'category_id' => '请选择文章分类',
            'author_id' => '请选择文章作者',
            default => "{$field} 不能为空"
        };
    }

    private function ensureSlugAvailable(string $slug, ?int $excludeId = null): void
    {
        if (! $this->isSlugAvailable($slug, $excludeId)) {
            throw new ApiException('validation_failed', '参数校验失败', 422, [
                'field_errors' => ['slug' => 'slug 已存在'],
            ]);
        }
    }

    private function isSlugAvailable(string $slug, ?int $excludeId = null): bool
    {
        $q = Article::withTrashed()->where('slug', $slug);
        if ($excludeId !== null) {
            $q->where('id', '!=', $excludeId);
        }

        return ! $q->exists();
    }

    private function nullableInt(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        return (int) $value;
    }

    private function toFlag(mixed $value): int
    {
        if (is_bool($value)) {
            return $value ? 1 : 0;
        }
        if (is_numeric($value)) {
            return (int) $value > 0 ? 1 : 0;
        }

        return in_array(strtolower(trim((string) $value)), ['1', 'true', 'yes', 'on'], true) ? 1 : 0;
    }
}
