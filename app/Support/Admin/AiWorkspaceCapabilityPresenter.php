<?php

namespace App\Support\Admin;

use App\Ai\Workspace\AiCapabilityDefinition;
use Illuminate\Support\Collection;

final class AiWorkspaceCapabilityPresenter
{
    /** @param Collection<string,AiCapabilityDefinition> $capabilities */
    public function present(Collection $capabilities, array $featuredCapabilityKeys): array
    {
        $groups = $this->capabilityGroups($capabilities);

        return [
            'count' => $capabilities->count(),
            'featured' => $this->featuredCapabilities($capabilities, $featuredCapabilityKeys),
            'slides' => $this->capabilitySlides($groups),
        ];
    }

    /** @param Collection<string,AiCapabilityDefinition> $capabilities */
    private function featuredCapabilities(Collection $capabilities, array $featuredCapabilityKeys): array
    {
        $icons = [
            'visibility.diagnose' => 'scan-search',
            'task.draft' => 'workflow',
            'knowledge.draft' => 'database',
            'distribution.preview' => 'radio-tower',
            'content.opportunities' => 'lightbulb',
            'analytics.daily_report' => 'chart-no-axes-combined',
            'article.draft' => 'file-pen-line',
        ];

        return collect($featuredCapabilityKeys)
            ->map(fn (string $key): ?AiCapabilityDefinition => $capabilities->get($key))
            ->filter()
            ->take(6)
            ->map(fn (AiCapabilityDefinition $capability): array => [
                'key' => $capability->key,
                'name' => $capability->name,
                'icon' => $icons[$capability->key] ?? 'sparkles',
                'prompt' => __('admin.ai_workspace.capability_prompt', ['name' => $capability->name]),
            ])
            ->values()
            ->all();
    }

    /** @param Collection<string,AiCapabilityDefinition> $capabilities */
    private function capabilityGroups(Collection $capabilities): array
    {
        $groups = [
            'insight' => [
                'icon' => 'scan-search',
                'keys' => ['analytics.daily_report', 'analytics.weekly_report', 'visibility.diagnose', 'content.opportunities'],
            ],
            'creation' => [
                'icon' => 'file-pen-line',
                'keys' => ['task.draft', 'article.draft', 'knowledge.draft', 'url_import.preview'],
            ],
            'operations' => [
                'icon' => 'workflow',
                'keys' => [
                    'url_import.commit', 'distribution.preview', 'task.status.change', 'distribution.publish',
                    'distribution.site_settings_sync', 'hosted_site.preflight',
                ],
            ],
            'guidance' => [
                'icon' => 'shield-check',
                'keys' => ['system.capabilities.explain', 'content.catalog', 'site.operations', 'managed.operations', 'admin.governance'],
            ],
        ];

        return collect($groups)->map(function (array $group, string $key) use ($capabilities): array {
            $items = collect($group['keys'])
                ->map(fn (string $capabilityKey): ?AiCapabilityDefinition => $capabilities->get($capabilityKey))
                ->filter()
                ->map(fn (AiCapabilityDefinition $capability): array => $this->presentCapability($capability))
                ->values()
                ->all();

            return [
                'key' => $key,
                'icon' => $group['icon'],
                'title' => __('admin.ai_workspace.capability_group_'.$key),
                'description' => __('admin.ai_workspace.capability_group_'.$key.'_description'),
                'capabilities' => $items,
            ];
        })->filter(static fn (array $group): bool => $group['capabilities'] !== [])->values()->all();
    }

    private function presentCapability(AiCapabilityDefinition $capability): array
    {
        $requiredCount = collect($capability->inputSchema)->filter(
            static fn (array $field): bool => (bool) ($field['required'] ?? false)
        )->count();
        $restricted = $capability->maturity === 'restricted';

        return $capability->toArray() + [
            'maturity_label' => __('admin.ai_workspace.maturity_'.$capability->maturity),
            'scope_label' => __('admin.ai_workspace.scope_'.$capability->executionScope),
            'approval_label' => __('admin.ai_workspace.approval_'.$capability->approvalPolicy),
            'required_label' => $requiredCount === 0
                ? __('admin.ai_workspace.required_none')
                : __('admin.ai_workspace.required_count', ['count' => $requiredCount]),
            'prompt' => __(
                $restricted ? 'admin.ai_workspace.capability_boundary_prompt' : 'admin.ai_workspace.capability_prompt',
                ['name' => $capability->name],
            ),
            'action_label' => __($restricted
                ? 'admin.ai_workspace.learn_boundary'
                : 'admin.ai_workspace.add_to_conversation'),
        ];
    }

    /** @param array<int,array<string,mixed>> $groups */
    private function capabilitySlides(array $groups): array
    {
        $capabilities = collect($groups)->flatMap(static function (array $group): array {
            return collect($group['capabilities'])->map(static fn (array $capability): array => $capability + [
                'group_key' => $group['key'],
                'group_title' => $group['title'],
                'group_description' => $group['description'],
                'group_icon' => $group['icon'],
            ])->all();
        })->values();

        if ($capabilities->isEmpty()) {
            return [];
        }

        $pageCount = max(1, (int) ceil($capabilities->count() / 8));
        $pageSize = intdiv($capabilities->count(), $pageCount);
        $remainder = $capabilities->count() % $pageCount;
        $offset = 0;
        $slides = [];

        for ($index = 0; $index < $pageCount; $index++) {
            $size = $pageSize + ($index >= $pageCount - $remainder ? 1 : 0);
            $items = $capabilities->slice($offset, $size)->values();
            $offset += $size;
            $groupTitles = $items->pluck('group_title')->unique()->values();
            $groupDescriptions = $items->pluck('group_description')->unique()->values();

            $slides[] = [
                'icon' => $items->first()['group_icon'],
                'title' => $groupTitles->implode(' · '),
                'description' => $groupDescriptions->implode(' · '),
                'capabilities' => $items->all(),
            ];
        }

        return $slides;
    }
}
