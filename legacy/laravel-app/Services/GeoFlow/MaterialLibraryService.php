<?php

namespace App\Services\GeoFlow;

use App\Exceptions\ApiException;
use App\Models\Article;
use App\Models\ArticleImage;
use App\Models\Author;
use App\Models\Category;
use App\Models\Image;
use App\Models\ImageLibrary;
use App\Models\Keyword;
use App\Models\KeywordLibrary;
use App\Models\KnowledgeBase;
use App\Models\KnowledgeChunk;
use App\Models\Task;
use App\Models\Title;
use App\Models\TitleLibrary;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * API v1 素材库服务：暴露后台材料管理的 JSON 契约，供 GEO CLI 直接调用。
 */
class MaterialLibraryService
{
    private const MATERIAL_TYPES = [
        'categories',
        'authors',
        'keyword-libraries',
        'title-libraries',
        'image-libraries',
        'knowledge-bases',
    ];

    private const ITEM_TYPES = [
        'keyword-libraries',
        'title-libraries',
        'image-libraries',
        'knowledge-bases',
    ];

    public function __construct(private readonly KnowledgeChunkSyncService $chunkSyncService) {}

    /**
     * @return array{types:list<array{type:string,count:int}>}
     */
    public function summary(): array
    {
        return [
            'types' => [
                ['type' => 'categories', 'count' => Category::query()->count()],
                ['type' => 'authors', 'count' => Author::query()->count()],
                ['type' => 'keyword-libraries', 'count' => KeywordLibrary::query()->count()],
                ['type' => 'title-libraries', 'count' => TitleLibrary::query()->count()],
                ['type' => 'image-libraries', 'count' => ImageLibrary::query()->count()],
                ['type' => 'knowledge-bases', 'count' => KnowledgeBase::query()->count()],
            ],
        ];
    }

    /**
     * @param  array<string,mixed>  $filters
     * @return array<string,mixed>
     */
    public function list(string $type, int $page = 1, int $perPage = 20, array $filters = []): array
    {
        $type = $this->normalizeType($type);
        $query = $this->buildListQuery($type);
        $search = trim((string) ($filters['search'] ?? ''));
        if ($search !== '') {
            $this->applySearch($query, $type, $search);
        }

        return $this->paginate($type, $query, $page, $perPage, fn (Model $row): array => $this->serializeMaterial($type, $row));
    }

    /**
     * @return array<string,mixed>
     */
    public function show(string $type, int $id): array
    {
        $type = $this->normalizeType($type);
        $row = $this->buildListQuery($type)->whereKey($id)->first();
        if (! $row) {
            throw new ApiException('material_not_found', '素材不存在', 404);
        }

        return [
            'type' => $type,
            'item' => $this->serializeMaterial($type, $row),
        ];
    }

    /**
     * @param  array<string,mixed>  $data
     * @return array<string,mixed>
     */
    public function create(string $type, array $data): array
    {
        $type = $this->normalizeType($type);

        $row = DB::transaction(function () use ($type, $data): Model {
            return match ($type) {
                'categories' => $this->createCategory($data),
                'authors' => Author::query()->create($this->normalizeAuthorPayload($data, false)),
                'keyword-libraries' => KeywordLibrary::query()->create($this->normalizeBasicLibraryPayload($data, false) + ['keyword_count' => 0]),
                'title-libraries' => TitleLibrary::query()->create($this->normalizeBasicLibraryPayload($data, false) + [
                    'title_count' => 0,
                    'generation_type' => 'manual',
                    'generation_rounds' => 1,
                    'is_ai_generated' => 0,
                ]),
                'image-libraries' => ImageLibrary::query()->create($this->normalizeBasicLibraryPayload($data, false) + [
                    'image_count' => 0,
                    'used_task_count' => 0,
                ]),
                'knowledge-bases' => $this->createKnowledgeBase($data),
            };
        });

        return [
            'type' => $type,
            'item' => $this->serializeMaterial($type, $this->freshMaterial($type, (int) $row->getKey())),
        ];
    }

    /**
     * @param  array<string,mixed>  $data
     * @return array<string,mixed>
     */
    public function update(string $type, int $id, array $data): array
    {
        $type = $this->normalizeType($type);
        $row = $this->findMaterial($type, $id);

        DB::transaction(function () use ($type, $row, $data): void {
            match ($type) {
                'categories' => $this->updateCategory($row, $data),
                'authors' => $row->update($this->normalizeAuthorPayload($data, true)),
                'keyword-libraries', 'title-libraries', 'image-libraries' => $row->update($this->normalizeBasicLibraryPayload($data, true)),
                'knowledge-bases' => $this->updateKnowledgeBase($row, $data),
            };
        });

        return [
            'type' => $type,
            'item' => $this->serializeMaterial($type, $this->freshMaterial($type, (int) $row->getKey())),
        ];
    }

    /**
     * @return array{id:int,type:string,deleted:bool}
     */
    public function delete(string $type, int $id): array
    {
        $type = $this->normalizeType($type);
        $row = $this->findMaterial($type, $id);

        DB::transaction(function () use ($type, $row, $id): void {
            match ($type) {
                'categories' => $this->deleteCategory($id),
                'authors' => $this->deleteAuthor($id),
                'keyword-libraries' => $this->deleteKeywordLibrary($id),
                'title-libraries' => $this->deleteTitleLibrary($id),
                'image-libraries' => $this->deleteImageLibrary($id),
                'knowledge-bases' => $this->deleteKnowledgeBase($row),
            };
        });

        return [
            'id' => $id,
            'type' => $type,
            'deleted' => true,
        ];
    }

    /**
     * @return array<string,mixed>
     */
    public function listItems(string $type, int $parentId, int $page = 1, int $perPage = 20): array
    {
        $type = $this->normalizeItemType($type);
        $this->findMaterial($type, $parentId);
        $query = $this->buildItemQuery($type, $parentId);

        return $this->paginate($type, $query, $page, $perPage, fn (Model $row): array => $this->serializeItem($type, $row), $parentId);
    }

    /**
     * @param  array<string,mixed>  $data
     * @return array<string,mixed>
     */
    public function createItem(string $type, int $parentId, array $data): array
    {
        $type = $this->normalizeWritableItemType($type);
        $this->findMaterial($type, $parentId);

        $row = DB::transaction(function () use ($type, $parentId, $data): Model {
            return match ($type) {
                'keyword-libraries' => $this->createKeywordItem($parentId, $data),
                'title-libraries' => $this->createTitleItem($parentId, $data),
                'image-libraries' => $this->createImageItem($parentId, $data),
            };
        });

        return [
            'type' => $type,
            'parent_id' => $parentId,
            'item' => $this->serializeItem($type, $row),
        ];
    }

    /**
     * @param  array<string,mixed>  $data
     * @return array{type:string,parent_id:int,deleted_count:int}
     */
    public function deleteItems(string $type, int $parentId, array $data): array
    {
        $type = $this->normalizeWritableItemType($type);
        $this->findMaterial($type, $parentId);
        $ids = $this->extractIds($data);
        if ($ids === []) {
            throw new ApiException('validation_failed', '请选择要删除的素材条目', 422, [
                'field_errors' => ['ids' => '请选择要删除的素材条目'],
            ]);
        }

        $deletedCount = DB::transaction(function () use ($type, $parentId, $ids): int {
            return match ($type) {
                'keyword-libraries' => $this->deleteKeywordItems($parentId, $ids),
                'title-libraries' => $this->deleteTitleItems($parentId, $ids),
                'image-libraries' => $this->deleteImageItems($parentId, $ids),
            };
        });

        return [
            'type' => $type,
            'parent_id' => $parentId,
            'deleted_count' => $deletedCount,
        ];
    }

    private function normalizeType(string $type): string
    {
        $type = str_replace('_', '-', trim($type));
        $aliases = [
            'keyword-libraries' => 'keyword-libraries',
            'keywords' => 'keyword-libraries',
            'title-libraries' => 'title-libraries',
            'titles' => 'title-libraries',
            'image-libraries' => 'image-libraries',
            'images' => 'image-libraries',
            'knowledge-bases' => 'knowledge-bases',
            'knowledge' => 'knowledge-bases',
            'categories' => 'categories',
            'authors' => 'authors',
        ];
        $normalized = $aliases[$type] ?? $type;
        if (! in_array($normalized, self::MATERIAL_TYPES, true)) {
            throw new ApiException('unsupported_material_type', '不支持的素材类型', 404);
        }

        return $normalized;
    }

    private function normalizeItemType(string $type): string
    {
        $type = $this->normalizeType($type);
        if (! in_array($type, self::ITEM_TYPES, true)) {
            throw new ApiException('unsupported_material_items', '该素材类型没有条目接口', 422);
        }

        return $type;
    }

    private function normalizeWritableItemType(string $type): string
    {
        $type = $this->normalizeItemType($type);
        if ($type === 'knowledge-bases') {
            throw new ApiException('unsupported_material_items', '知识库条目由正文自动切块生成', 422);
        }

        return $type;
    }

    private function buildListQuery(string $type): Builder
    {
        return match ($type) {
            'categories' => Category::query()
                ->select(['id', 'name', 'slug', 'description', 'sort_order', 'created_at'])
                ->withCount('articles as article_count')
                ->orderBy('sort_order')
                ->orderBy('name'),
            'authors' => Author::query()
                ->select(['id', 'name', 'bio', 'email', 'avatar', 'website', 'social_links', 'created_at', 'updated_at'])
                ->withCount([
                    'articles as article_count' => fn (Builder $q) => $q->whereNull('deleted_at'),
                    'articles as trashed_count' => fn (Builder $q) => $q->whereNotNull('deleted_at'),
                ])
                ->orderByDesc('created_at'),
            'keyword-libraries' => KeywordLibrary::query()
                ->select(['id', 'name', 'description', 'keyword_count', 'created_at', 'updated_at'])
                ->withCount('keywords as item_count')
                ->withCount('titleLibraries as title_library_count')
                ->orderByDesc('created_at'),
            'title-libraries' => TitleLibrary::query()
                ->select(['id', 'name', 'description', 'title_count', 'generation_type', 'keyword_library_id', 'ai_model_id', 'prompt_id', 'generation_rounds', 'is_ai_generated', 'created_at', 'updated_at'])
                ->withCount('titles as item_count')
                ->withCount('tasks as task_count')
                ->orderByDesc('created_at'),
            'image-libraries' => ImageLibrary::query()
                ->select(['id', 'name', 'description', 'image_count', 'used_task_count', 'created_at', 'updated_at'])
                ->withCount('images as item_count')
                ->withCount('tasks as task_count')
                ->withSum('images as total_size', 'file_size')
                ->orderByDesc('created_at'),
            'knowledge-bases' => KnowledgeBase::query()
                ->select(['id', 'name', 'description', 'content', 'character_count', 'used_task_count', 'file_type', 'file_path', 'word_count', 'usage_count', 'created_at', 'updated_at'])
                ->withCount('chunks as chunk_count')
                ->withCount('tasks as task_count')
                ->orderByDesc('created_at'),
        };
    }

    private function buildItemQuery(string $type, int $parentId): Builder
    {
        return match ($type) {
            'keyword-libraries' => Keyword::query()
                ->where('library_id', $parentId)
                ->orderByDesc('created_at')
                ->orderByDesc('id'),
            'title-libraries' => Title::query()
                ->where('library_id', $parentId)
                ->orderByDesc('created_at')
                ->orderByDesc('id'),
            'image-libraries' => Image::query()
                ->where('library_id', $parentId)
                ->orderByDesc('created_at')
                ->orderByDesc('id'),
            'knowledge-bases' => KnowledgeChunk::query()
                ->where('knowledge_base_id', $parentId)
                ->orderBy('chunk_index')
                ->orderBy('id'),
        };
    }

    private function applySearch(Builder $query, string $type, string $search): void
    {
        $fields = match ($type) {
            'categories' => ['name', 'slug', 'description'],
            'authors' => ['name', 'email', 'bio'],
            'keyword-libraries', 'title-libraries', 'image-libraries', 'knowledge-bases' => ['name', 'description'],
        };

        $query->where(function (Builder $builder) use ($fields, $search): void {
            foreach ($fields as $index => $field) {
                $method = $index === 0 ? 'where' : 'orWhere';
                $builder->{$method}($field, 'like', '%'.$search.'%');
            }
        });
    }

    /**
     * @return array<string,mixed>
     */
    private function paginate(string $type, Builder $query, int $page, int $perPage, callable $serializer, ?int $parentId = null): array
    {
        $page = max(1, $page);
        $perPage = max(1, min(100, $perPage));
        $paginator = $query->paginate($perPage, ['*'], 'page', $page);

        $data = [
            'type' => $type,
            'items' => $paginator->getCollection()->map($serializer)->values()->all(),
            'pagination' => [
                'page' => $paginator->currentPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
                'total_pages' => $paginator->lastPage(),
            ],
        ];
        if ($parentId !== null) {
            $data['parent_id'] = $parentId;
        }

        return $data;
    }

    private function findMaterial(string $type, int $id): Model
    {
        $row = $this->buildListQuery($type)->whereKey($id)->first();
        if (! $row) {
            throw new ApiException('material_not_found', '素材不存在', 404);
        }

        return $row;
    }

    private function freshMaterial(string $type, int $id): Model
    {
        return $this->findMaterial($type, $id);
    }

    /**
     * @return array<string,mixed>
     */
    private function serializeMaterial(string $type, Model $row): array
    {
        return match ($type) {
            'categories' => [
                'id' => (int) $row->id,
                'name' => (string) $row->name,
                'slug' => (string) ($row->slug ?? ''),
                'description' => (string) ($row->description ?? ''),
                'sort_order' => (int) ($row->sort_order ?? 0),
                'article_count' => (int) ($row->article_count ?? 0),
                'created_at' => $this->formatDate($row->created_at ?? null),
            ],
            'authors' => [
                'id' => (int) $row->id,
                'name' => (string) $row->name,
                'email' => (string) ($row->email ?? ''),
                'bio' => (string) ($row->bio ?? ''),
                'avatar' => (string) ($row->avatar ?? ''),
                'website' => (string) ($row->website ?? ''),
                'social_links' => (string) ($row->social_links ?? ''),
                'article_count' => (int) ($row->article_count ?? 0),
                'trashed_count' => (int) ($row->trashed_count ?? 0),
                'created_at' => $this->formatDate($row->created_at ?? null),
                'updated_at' => $this->formatDate($row->updated_at ?? null),
            ],
            'keyword-libraries' => [
                'id' => (int) $row->id,
                'name' => (string) $row->name,
                'description' => (string) ($row->description ?? ''),
                'keyword_count' => (int) ($row->keyword_count ?? 0),
                'item_count' => (int) ($row->item_count ?? 0),
                'title_library_count' => (int) ($row->title_library_count ?? 0),
                'created_at' => $this->formatDate($row->created_at ?? null),
                'updated_at' => $this->formatDate($row->updated_at ?? null),
            ],
            'title-libraries' => [
                'id' => (int) $row->id,
                'name' => (string) $row->name,
                'description' => (string) ($row->description ?? ''),
                'title_count' => (int) ($row->title_count ?? 0),
                'item_count' => (int) ($row->item_count ?? 0),
                'generation_type' => (string) ($row->generation_type ?? 'manual'),
                'keyword_library_id' => $row->keyword_library_id !== null ? (int) $row->keyword_library_id : null,
                'ai_model_id' => $row->ai_model_id !== null ? (int) $row->ai_model_id : null,
                'prompt_id' => $row->prompt_id !== null ? (int) $row->prompt_id : null,
                'generation_rounds' => (int) ($row->generation_rounds ?? 1),
                'is_ai_generated' => (int) ($row->is_ai_generated ?? 0),
                'task_count' => (int) ($row->task_count ?? 0),
                'created_at' => $this->formatDate($row->created_at ?? null),
                'updated_at' => $this->formatDate($row->updated_at ?? null),
            ],
            'image-libraries' => [
                'id' => (int) $row->id,
                'name' => (string) $row->name,
                'description' => (string) ($row->description ?? ''),
                'image_count' => (int) ($row->image_count ?? 0),
                'item_count' => (int) ($row->item_count ?? 0),
                'used_task_count' => (int) ($row->used_task_count ?? 0),
                'task_count' => (int) ($row->task_count ?? 0),
                'total_size' => (int) ($row->total_size ?? 0),
                'created_at' => $this->formatDate($row->created_at ?? null),
                'updated_at' => $this->formatDate($row->updated_at ?? null),
            ],
            'knowledge-bases' => [
                'id' => (int) $row->id,
                'name' => (string) $row->name,
                'description' => (string) ($row->description ?? ''),
                'content' => (string) ($row->content ?? ''),
                'file_type' => (string) ($row->file_type ?? 'markdown'),
                'file_path' => (string) ($row->file_path ?? ''),
                'character_count' => (int) ($row->character_count ?? 0),
                'word_count' => (int) ($row->word_count ?? 0),
                'usage_count' => (int) ($row->usage_count ?? 0),
                'used_task_count' => (int) ($row->used_task_count ?? 0),
                'chunk_count' => (int) ($row->chunk_count ?? 0),
                'task_count' => (int) ($row->task_count ?? 0),
                'created_at' => $this->formatDate($row->created_at ?? null),
                'updated_at' => $this->formatDate($row->updated_at ?? null),
            ],
        };
    }

    /**
     * @return array<string,mixed>
     */
    private function serializeItem(string $type, Model $row): array
    {
        return match ($type) {
            'keyword-libraries' => [
                'id' => (int) $row->id,
                'library_id' => (int) $row->library_id,
                'keyword' => (string) $row->keyword,
                'used_count' => (int) ($row->used_count ?? 0),
                'usage_count' => (int) ($row->usage_count ?? 0),
                'created_at' => $this->formatDate($row->created_at ?? null),
            ],
            'title-libraries' => [
                'id' => (int) $row->id,
                'library_id' => (int) $row->library_id,
                'title' => (string) $row->title,
                'keyword' => (string) ($row->keyword ?? ''),
                'is_ai_generated' => (bool) ($row->is_ai_generated ?? false),
                'used_count' => (int) ($row->used_count ?? 0),
                'usage_count' => (int) ($row->usage_count ?? 0),
                'created_at' => $this->formatDate($row->created_at ?? null),
            ],
            'image-libraries' => [
                'id' => (int) $row->id,
                'library_id' => (int) $row->library_id,
                'filename' => (string) $row->filename,
                'original_name' => (string) $row->original_name,
                'file_name' => (string) ($row->file_name ?? ''),
                'file_path' => (string) ($row->file_path ?? ''),
                'file_size' => (int) ($row->file_size ?? 0),
                'mime_type' => (string) ($row->mime_type ?? ''),
                'width' => (int) ($row->width ?? 0),
                'height' => (int) ($row->height ?? 0),
                'tags' => (string) ($row->tags ?? ''),
                'used_count' => (int) ($row->used_count ?? 0),
                'usage_count' => (int) ($row->usage_count ?? 0),
                'created_at' => $this->formatDate($row->created_at ?? null),
            ],
            'knowledge-bases' => [
                'id' => (int) $row->id,
                'knowledge_base_id' => (int) $row->knowledge_base_id,
                'chunk_index' => (int) $row->chunk_index,
                'content' => (string) $row->content,
                'content_hash' => (string) ($row->content_hash ?? ''),
                'token_count' => (int) ($row->token_count ?? 0),
                'created_at' => $this->formatDate($row->created_at ?? null),
                'updated_at' => $this->formatDate($row->updated_at ?? null),
            ],
        };
    }

    private function createCategory(array $data): Category
    {
        $name = $this->requiredString($data, 'name', '分类名称不能为空', 255);
        $this->ensureUniqueCategoryName($name);

        return Category::query()->create([
            'name' => $name,
            'slug' => $this->buildCategorySlug($name, (string) ($data['slug'] ?? ''), 0),
            'description' => $this->optionalString($data, 'description'),
            'sort_order' => max(0, (int) ($data['sort_order'] ?? 0)),
        ]);
    }

    private function updateCategory(Model $row, array $data): void
    {
        $updates = [];
        if (array_key_exists('name', $data)) {
            $name = $this->requiredString($data, 'name', '分类名称不能为空', 255);
            $this->ensureUniqueCategoryName($name, (int) $row->id);
            $updates['name'] = $name;
        }
        if (array_key_exists('slug', $data) || array_key_exists('name', $updates)) {
            $updates['slug'] = $this->buildCategorySlug($updates['name'] ?? (string) $row->name, (string) ($data['slug'] ?? $row->slug ?? ''), (int) $row->id);
        }
        if (array_key_exists('description', $data)) {
            $updates['description'] = $this->optionalString($data, 'description');
        }
        if (array_key_exists('sort_order', $data)) {
            $updates['sort_order'] = max(0, (int) $data['sort_order']);
        }

        $this->ensureHasUpdates($updates);
        $row->update($updates);
    }

    /**
     * @return array<string,mixed>
     */
    private function normalizeAuthorPayload(array $data, bool $isUpdate): array
    {
        $payload = [];
        if (array_key_exists('name', $data)) {
            $payload['name'] = $this->requiredString($data, 'name', '作者名称不能为空', 100);
        } elseif (! $isUpdate) {
            $payload['name'] = $this->requiredString($data, 'name', '作者名称不能为空', 100);
        }

        foreach (['email' => 100, 'bio' => 0, 'avatar' => 200, 'website' => 200, 'social_links' => 0] as $field => $max) {
            if (array_key_exists($field, $data) || ! $isUpdate) {
                $payload[$field] = $this->optionalString($data, $field, $max);
            }
        }

        $this->ensureHasUpdates($payload);

        return $payload;
    }

    /**
     * @return array{name?:string,description?:string}
     */
    private function normalizeBasicLibraryPayload(array $data, bool $isUpdate): array
    {
        $payload = [];
        if (array_key_exists('name', $data)) {
            $payload['name'] = $this->requiredString($data, 'name', '素材库名称不能为空', 100);
        } elseif (! $isUpdate) {
            $payload['name'] = $this->requiredString($data, 'name', '素材库名称不能为空', 100);
        }
        if (array_key_exists('description', $data) || ! $isUpdate) {
            $payload['description'] = $this->optionalString($data, 'description');
        }

        $this->ensureHasUpdates($payload);

        return $payload;
    }

    private function createKnowledgeBase(array $data): KnowledgeBase
    {
        $payload = $this->normalizeKnowledgePayload($data, false);
        $knowledgeBase = KnowledgeBase::query()->create($payload);
        $this->chunkSyncService->sync((int) $knowledgeBase->id, (string) $payload['content']);

        return $knowledgeBase;
    }

    private function updateKnowledgeBase(Model $row, array $data): void
    {
        $payload = $this->normalizeKnowledgePayload($data, true);
        $contentChanged = array_key_exists('content', $payload);
        $row->update($payload);
        if ($contentChanged) {
            $this->chunkSyncService->sync((int) $row->id, (string) $payload['content']);
        }
    }

    /**
     * @return array<string,mixed>
     */
    private function normalizeKnowledgePayload(array $data, bool $isUpdate): array
    {
        $payload = [];
        if (array_key_exists('name', $data)) {
            $payload['name'] = $this->requiredString($data, 'name', '知识库名称不能为空', 100);
        } elseif (! $isUpdate) {
            $payload['name'] = $this->requiredString($data, 'name', '知识库名称不能为空', 100);
        }
        if (array_key_exists('description', $data) || ! $isUpdate) {
            $payload['description'] = $this->optionalString($data, 'description');
        }
        if (array_key_exists('content', $data)) {
            $content = $this->requiredString($data, 'content', '知识库正文不能为空', 0);
            $payload['content'] = $content;
            $payload['character_count'] = mb_strlen($content, 'UTF-8');
            $payload['word_count'] = mb_strlen(strip_tags($content), 'UTF-8');
        } elseif (! $isUpdate) {
            $content = $this->requiredString($data, 'content', '知识库正文不能为空', 0);
            $payload['content'] = $content;
            $payload['character_count'] = mb_strlen($content, 'UTF-8');
            $payload['word_count'] = mb_strlen(strip_tags($content), 'UTF-8');
        }
        if (array_key_exists('file_type', $data) || ! $isUpdate) {
            $fileType = trim((string) ($data['file_type'] ?? 'markdown'));
            if (! in_array($fileType, ['markdown', 'word', 'text'], true)) {
                $this->validationError('file_type', '知识库文件类型无效');
            }
            $payload['file_type'] = $fileType;
        }
        if (array_key_exists('file_path', $data) || ! $isUpdate) {
            $payload['file_path'] = $this->optionalString($data, 'file_path', 500);
        }
        if (! $isUpdate) {
            $payload['usage_count'] = 0;
            $payload['used_task_count'] = 0;
        }

        $this->ensureHasUpdates($payload);

        return $payload;
    }

    private function createKeywordItem(int $parentId, array $data): Keyword
    {
        $keyword = $this->requiredString($data, 'keyword', '关键词不能为空', 200);
        if (Keyword::query()->where('library_id', $parentId)->where('keyword', $keyword)->exists()) {
            throw new ApiException('material_item_exists', '关键词已存在', 409);
        }

        $row = Keyword::query()->create([
            'library_id' => $parentId,
            'keyword' => $keyword,
            'used_count' => 0,
            'usage_count' => 0,
        ]);
        $this->refreshKeywordLibraryCount($parentId);

        return $row;
    }

    private function createTitleItem(int $parentId, array $data): Title
    {
        $title = $this->requiredString($data, 'title', '标题不能为空', 500);
        if (Title::query()->where('library_id', $parentId)->where('title', $title)->exists()) {
            throw new ApiException('material_item_exists', '标题已存在', 409);
        }

        $row = Title::query()->create([
            'library_id' => $parentId,
            'title' => $title,
            'keyword' => $this->optionalString($data, 'keyword', 200),
            'is_ai_generated' => false,
            'used_count' => 0,
            'usage_count' => 0,
        ]);
        $this->refreshTitleLibraryCount($parentId);

        return $row;
    }

    private function createImageItem(int $parentId, array $data): Image
    {
        $filePath = $this->requiredString($data, 'file_path', '图片 file_path 不能为空', 500);
        $filename = $this->optionalString($data, 'filename', 255);
        if ($filename === '') {
            $filename = basename($filePath);
        }

        $row = Image::query()->create([
            'library_id' => $parentId,
            'filename' => $filename,
            'original_name' => $this->optionalString($data, 'original_name', 255) ?: $filename,
            'file_name' => $this->optionalString($data, 'file_name', 255) ?: $filename,
            'file_path' => $filePath,
            'file_size' => max(0, (int) ($data['file_size'] ?? 0)),
            'mime_type' => $this->optionalString($data, 'mime_type', 100),
            'width' => max(0, (int) ($data['width'] ?? 0)),
            'height' => max(0, (int) ($data['height'] ?? 0)),
            'tags' => $this->optionalString($data, 'tags'),
            'used_count' => 0,
            'usage_count' => 0,
        ]);
        $this->refreshImageLibraryCount($parentId);

        return $row;
    }

    /**
     * @param  list<int>  $ids
     */
    private function deleteKeywordItems(int $parentId, array $ids): int
    {
        $deleted = Keyword::query()->where('library_id', $parentId)->whereIn('id', $ids)->delete();
        $this->refreshKeywordLibraryCount($parentId);

        return $deleted;
    }

    /**
     * @param  list<int>  $ids
     */
    private function deleteTitleItems(int $parentId, array $ids): int
    {
        $deleted = Title::query()->where('library_id', $parentId)->whereIn('id', $ids)->delete();
        $this->refreshTitleLibraryCount($parentId);

        return $deleted;
    }

    /**
     * @param  list<int>  $ids
     */
    private function deleteImageItems(int $parentId, array $ids): int
    {
        $filePaths = Image::query()->where('library_id', $parentId)->whereIn('id', $ids)->pluck('file_path')->filter()->values()->all();
        ArticleImage::query()->whereIn('image_id', $ids)->delete();
        $deleted = Image::query()->where('library_id', $parentId)->whereIn('id', $ids)->delete();
        $this->refreshImageLibraryCount($parentId);
        $this->cleanupFiles($filePaths);

        return $deleted;
    }

    private function deleteCategory(int $id): void
    {
        $count = Article::query()->where('category_id', $id)->count();
        if ($count > 0) {
            throw new ApiException('material_in_use', '分类仍有关联文章，无法删除', 409, ['article_count' => $count]);
        }
        Category::query()->whereKey($id)->delete();
    }

    private function deleteAuthor(int $id): void
    {
        $visibleCount = Article::query()->where('author_id', $id)->whereNull('deleted_at')->count();
        if ($visibleCount > 0) {
            throw new ApiException('material_in_use', '作者仍有关联文章，无法删除', 409, ['article_count' => $visibleCount]);
        }
        $trashedCount = Article::query()->where('author_id', $id)->whereNotNull('deleted_at')->count();
        if ($trashedCount > 0) {
            throw new ApiException('material_in_use', '作者仍有关联回收站文章，无法删除', 409, ['trashed_count' => $trashedCount]);
        }
        Author::query()->whereKey($id)->delete();
    }

    private function deleteKeywordLibrary(int $id): void
    {
        $titleLibraryCount = TitleLibrary::query()->where('keyword_library_id', $id)->count();
        if ($titleLibraryCount > 0) {
            throw new ApiException('material_in_use', '关键词库仍被标题库引用，无法删除', 409, ['title_library_count' => $titleLibraryCount]);
        }
        Keyword::query()->where('library_id', $id)->delete();
        KeywordLibrary::query()->whereKey($id)->delete();
    }

    private function deleteTitleLibrary(int $id): void
    {
        $taskCount = Task::query()->where('title_library_id', $id)->count();
        if ($taskCount > 0) {
            throw new ApiException('material_in_use', '标题库仍被任务引用，无法删除', 409, ['task_count' => $taskCount]);
        }
        Title::query()->where('library_id', $id)->delete();
        TitleLibrary::query()->whereKey($id)->delete();
    }

    private function deleteImageLibrary(int $id): void
    {
        $taskCount = Task::query()->where('image_library_id', $id)->count();
        if ($taskCount > 0) {
            throw new ApiException('material_in_use', '图片库仍被任务引用，无法删除', 409, ['task_count' => $taskCount]);
        }
        $filePaths = Image::query()->where('library_id', $id)->pluck('file_path')->filter()->values()->all();
        $imageIds = Image::query()->where('library_id', $id)->pluck('id')->all();
        ArticleImage::query()->whereIn('image_id', $imageIds)->delete();
        Image::query()->where('library_id', $id)->delete();
        ImageLibrary::query()->whereKey($id)->delete();
        $this->cleanupFiles($filePaths);
    }

    private function deleteKnowledgeBase(Model $row): void
    {
        $id = (int) $row->id;
        $taskCount = Task::query()->where('knowledge_base_id', $id)->count();
        if ($taskCount > 0) {
            throw new ApiException('material_in_use', '知识库仍被任务引用，无法删除', 409, ['task_count' => $taskCount]);
        }
        KnowledgeChunk::query()->where('knowledge_base_id', $id)->delete();
        KnowledgeBase::query()->whereKey($id)->delete();
        $this->cleanupFiles([(string) ($row->file_path ?? '')]);
    }

    private function refreshKeywordLibraryCount(int $libraryId): void
    {
        KeywordLibrary::query()->whereKey($libraryId)->update([
            'keyword_count' => Keyword::query()->where('library_id', $libraryId)->count(),
            'updated_at' => now(),
        ]);
    }

    private function refreshTitleLibraryCount(int $libraryId): void
    {
        TitleLibrary::query()->whereKey($libraryId)->update([
            'title_count' => Title::query()->where('library_id', $libraryId)->count(),
            'updated_at' => now(),
        ]);
    }

    private function refreshImageLibraryCount(int $libraryId): void
    {
        ImageLibrary::query()->whereKey($libraryId)->update([
            'image_count' => Image::query()->where('library_id', $libraryId)->count(),
            'updated_at' => now(),
        ]);
    }

    private function requiredString(array $data, string $field, string $message, int $maxLength = 0): string
    {
        $value = trim((string) ($data[$field] ?? ''));
        if ($value === '') {
            $this->validationError($field, $message);
        }
        if ($maxLength > 0 && mb_strlen($value, 'UTF-8') > $maxLength) {
            $this->validationError($field, $field.' 长度不能超过 '.$maxLength.' 个字符');
        }

        return $value;
    }

    private function optionalString(array $data, string $field, int $maxLength = 0): string
    {
        $value = trim((string) ($data[$field] ?? ''));
        if ($maxLength > 0 && mb_strlen($value, 'UTF-8') > $maxLength) {
            $this->validationError($field, $field.' 长度不能超过 '.$maxLength.' 个字符');
        }

        return $value;
    }

    private function validationError(string $field, string $message): never
    {
        throw new ApiException('validation_failed', '参数校验失败', 422, [
            'field_errors' => [$field => $message],
        ]);
    }

    /**
     * @param  array<string,mixed>  $updates
     */
    private function ensureHasUpdates(array $updates): void
    {
        if ($updates === []) {
            throw new ApiException('validation_failed', '没有可更新的字段', 422);
        }
    }

    private function ensureUniqueCategoryName(string $name, int $excludeId = 0): void
    {
        $query = Category::query()->where('name', $name);
        if ($excludeId > 0) {
            $query->where('id', '!=', $excludeId);
        }
        if ($query->exists()) {
            throw new ApiException('material_exists', '分类名称已存在', 409);
        }
    }

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
            $query = Category::query()->where('slug', $slug);
            if ($excludeId > 0) {
                $query->where('id', '!=', $excludeId);
            }
            if (! $query->exists()) {
                return $slug;
            }
            $slug = $baseSlug.'-'.$counter;
            $counter++;
        }
    }

    /**
     * @return list<int>
     */
    private function extractIds(array $data): array
    {
        $raw = $data['ids'] ?? $data['item_ids'] ?? $data['keyword_ids'] ?? $data['title_ids'] ?? $data['image_ids'] ?? [];
        if (! is_array($raw)) {
            $raw = [$raw];
        }

        return collect($raw)
            ->map(static fn (mixed $id): int => (int) $id)
            ->filter(static fn (int $id): bool => $id > 0)
            ->unique()
            ->values()
            ->all();
    }

    /**
     * @param  list<string>  $paths
     */
    private function cleanupFiles(array $paths): void
    {
        foreach ($paths as $path) {
            $path = trim($path);
            if ($path === '' || str_starts_with($path, '/') || str_contains($path, '..')) {
                continue;
            }
            foreach (['public', 'local'] as $disk) {
                if (Storage::disk($disk)->exists($path)) {
                    Storage::disk($disk)->delete($path);
                }
            }
        }
    }

    private function formatDate(mixed $value): ?string
    {
        return is_object($value) && method_exists($value, 'format') ? $value->format('Y-m-d H:i:s') : null;
    }
}
