<?php

namespace App\Services\GeoFlow;

use App\Data\Admin\SharedAiModelData;
use App\Models\Admin;
use App\Models\AiModel;
use App\Models\Author;
use App\Models\Category;
use App\Models\ImageLibrary;
use App\Models\KeywordLibrary;
use App\Models\KnowledgeBase;
use App\Models\Prompt;
use App\Models\TitleLibrary;
use App\Services\Admin\AdminAiModelAccessResolver;

class CatalogGeoFlowService
{
    public function __construct(private readonly AdminAiModelAccessResolver $modelAccessResolver) {}

    /**
     * @return array<string, mixed>
     */
    public function getCatalog(Admin|int $actor): array
    {
        $models = $this->catalogModels($actor);

        $prompts = Prompt::query()
            ->where('type', 'content')
            ->orderBy('name')
            ->get(['id', 'name', 'type'])
            ->map(fn (Prompt $p) => $p->getAttributes())
            ->all();

        $titleLibraries = TitleLibrary::query()
            ->withCount(['titles as title_count'])
            ->orderBy('name')
            ->get(['id', 'name'])
            ->map(fn (TitleLibrary $tl) => [
                'id' => $tl->id,
                'name' => $tl->name,
                'title_count' => (int) ($tl->title_count ?? 0),
            ])
            ->all();

        $keywordLibraries = KeywordLibrary::query()
            ->withCount(['keywords as keyword_count'])
            ->orderBy('name')
            ->get(['id', 'name'])
            ->map(fn (KeywordLibrary $kl) => [
                'id' => $kl->id,
                'name' => $kl->name,
                'keyword_count' => (int) ($kl->keyword_count ?? 0),
            ])
            ->all();

        $imageLibraries = ImageLibrary::query()
            ->withCount(['images as image_count'])
            ->orderBy('name')
            ->get(['id', 'name'])
            ->map(fn (ImageLibrary $il) => [
                'id' => $il->id,
                'name' => $il->name,
                'image_count' => (int) ($il->image_count ?? 0),
            ])
            ->all();

        $knowledgeBases = KnowledgeBase::query()
            ->orderBy('name')
            ->get(['id', 'name'])
            ->map(fn (KnowledgeBase $k) => $k->getAttributes())
            ->all();

        $authors = Author::query()
            ->orderBy('name')
            ->get(['id', 'name'])
            ->map(fn (Author $a) => $a->getAttributes())
            ->all();

        $categories = Category::query()
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get(['id', 'name', 'slug'])
            ->map(fn (Category $c) => $c->getAttributes())
            ->all();

        return [
            'models' => $models,
            'prompts' => $prompts,
            'keyword_libraries' => $keywordLibraries,
            'title_libraries' => $titleLibraries,
            'image_libraries' => $imageLibraries,
            'knowledge_bases' => $knowledgeBases,
            'authors' => $authors,
            'categories' => $categories,
        ];
    }

    /** @return list<array<string, int|string|bool>> */
    private function catalogModels(Admin|int $actor): array
    {
        $admin = $actor instanceof Admin
            ? $actor
            : Admin::query()->findOrFail($actor);

        return $this->modelAccessResolver
            ->usableQuery($admin)
            ->where(function ($query): void {
                $query->whereIn('model_type', ['chat', 'embedding'])
                    ->orWhereNull('model_type')
                    ->orWhere('model_type', '');
            })
            ->get()
            ->map(static fn (AiModel $model): array => SharedAiModelData::fromModel(
                $model,
                (int) $model->owner_admin_id !== (int) $admin->getKey(),
            )->toArray())
            ->all();
    }
}
