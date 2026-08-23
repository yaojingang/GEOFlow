<?php

namespace App\Support\Admin;

use App\Ai\Workspace\AiCapabilityDefinition;
use Illuminate\Support\Collection;

final class AiWorkspaceCapabilityPresenter
{
    private const FEATURED_CAPABILITY_KEYS = [
        'visibility.diagnose',
        'task.draft',
        'knowledge.draft',
        'distribution.preview',
        'content.opportunities',
        'analytics.daily_report',
        'article.draft',
    ];

    /** @param Collection<string,AiCapabilityDefinition> $capabilities */
    public function present(Collection $capabilities): array
    {
        return [
            'count' => $capabilities->count(),
            'featured' => $this->featuredCapabilities($capabilities),
            'groups' => $this->capabilityGroups($capabilities),
        ];
    }

    /** @param Collection<string,AiCapabilityDefinition> $capabilities */
    private function featuredCapabilities(Collection $capabilities): array
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

        return collect(self::FEATURED_CAPABILITY_KEYS)
            ->map(fn (string $key): ?AiCapabilityDefinition => $capabilities->get($key))
            ->filter()
            ->take(4)
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
}
