<?php

namespace App\Services\AiWorkspace;

use App\Models\Admin;
use App\Models\Author;
use App\Models\Category;
use App\Models\ImageLibrary;
use App\Models\KnowledgeBase;
use App\Models\Prompt;
use App\Models\TitleLibrary;
use App\Services\Admin\AdminAiModelAccessResolver;

final readonly class TaskCreationCatalog
{
    public const REFERENCES = [
        'title_library_id' => 'title_libraries',
        'prompt_id' => 'prompts',
        'ai_model_id' => 'models',
        'fixed_category_id' => 'categories',
        'author_id' => 'authors',
        'image_library_id' => 'image_libraries',
    ];

    public function __construct(private AdminAiModelAccessResolver $models) {}

    public function forAdmin(Admin $admin): array
    {
        $models = $this->models->usableQuery($admin)
            ->where(fn ($q) => $q->whereNull('model_type')->orWhere('model_type', '')->orWhere('model_type', 'chat'))
            ->orderBy('failover_priority')->orderBy('id')->get(['id', 'name'])->toArray();
        $preferred = (int) ($admin->aiSettings?->default_chat_model_id ?? 0);

        return [
            'title_libraries' => TitleLibrary::query()->select(['id', 'name'])
                ->withCount(['titles as available' => fn ($q) => $q->where(fn ($q) => $q->whereNull('used_count')->orWhere('used_count', '<=', 0))])
                ->orderByDesc('id')->get()->toArray(),
            'prompts' => Prompt::query()->where('type', 'content')->orderByDesc('id')->get(['id', 'name'])->toArray(),
            'models' => $models,
            'recommended_model_id' => in_array($preferred, array_column($models, 'id'), true) ? $preferred : ($models[0]['id'] ?? null),
            'categories' => Category::query()->orderBy('sort_order')->orderBy('id')->get(['id', 'name'])->toArray(),
            'knowledge_bases' => KnowledgeBase::query()->whereDoesntHave('systemBinding')->orderByDesc('id')->get(['id', 'name'])->toArray(),
            'authors' => Author::query()->orderBy('id')->get(['id', 'name'])->toArray(),
            'image_libraries' => ImageLibrary::query()->select(['id', 'name'])->withCount('images')->orderBy('id')->get()->toArray(),
        ];
    }

    public function label(array $catalog, string $group, int $id): ?string
    {
        foreach ($catalog[$group] ?? [] as $item) {
            if ((int) $item['id'] === $id) {
                return (string) $item['name'];
            }
        }

        return null;
    }
}
