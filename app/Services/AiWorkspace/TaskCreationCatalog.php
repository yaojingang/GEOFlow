<?php

namespace App\Services\AiWorkspace;

use App\Models\Admin;
use App\Models\Author;
use App\Models\Category;
use App\Models\HostedSiteProfile;
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

        $sites = [['id' => 'primary', 'name' => __('ai-task.primary_site')]];
        if ($admin->isSuperAdmin()) {
            foreach (HostedSiteProfile::query()->orderBy('id')->get(['id', 'hostname']) as $site) {
                $sites[] = ['id' => 'hosted:'.$site->id, 'name' => $site->hostname];
            }
        }

        return [
            'sites' => $sites,
            'content_types' => $this->options('content_types', ['article', 'topic']),
            'topic_generation_modes' => $this->options('topic_generation_modes', ['auto_publish', 'draft_only', 'review_then_publish']),
            'topic_templates' => $this->options('topic_templates', ['default', 'guide', 'roundup']),
            'title_libraries' => TitleLibrary::query()->select(['id', 'name'])
                ->withCount('titles')
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

    private function options(string $group, array $values): array
    {
        return array_map(fn (string $value): array => ['id' => $value, 'name' => __('ai-task.'.$group.'.'.$value)], $values);
    }

    public function label(array $catalog, string $group, int|string $id): ?string
    {
        foreach ($catalog[$group] ?? [] as $item) {
            if ((string) $item['id'] === (string) $id) {
                return (string) $item['name'];
            }
        }

        return null;
    }
}
