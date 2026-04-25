<?php

namespace App\Services\GeoFlow;

use App\Models\AiModel;
use App\Models\Author;
use App\Models\Category;
use App\Models\KnowledgeBase;
use App\Models\Prompt;
use App\Models\TitleLibrary;

class CatalogGeoFlowService
{
    /**
     * @return array<string, mixed>
     */
    public function getCatalog(): array
    {
        $models = AiModel::query()
            ->where('status', 'active')
            ->where(function ($q) {
                $q->whereNull('model_type')
                    ->orWhere('model_type', '')
                    ->orWhere('model_type', 'chat');
            })
            ->orderBy('name')
            ->get(['id', 'name', 'model_id', 'model_type', 'status'])
            ->map(fn (AiModel $m) => [
                'id' => $m->id,
                'name' => $m->name,
                'model_id' => $m->model_id,
                'model_type' => ($m->model_type === null || $m->model_type === '') ? 'chat' : $m->model_type,
                'status' => $m->status,
            ])
            ->all();

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
            'title_libraries' => $titleLibraries,
            'knowledge_bases' => $knowledgeBases,
            'authors' => $authors,
            'categories' => $categories,
        ];
    }
}
