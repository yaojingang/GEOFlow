<?php

namespace App\Support;

use App\Models\Admin;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

final class AdminUiRegistry
{
    public const RECENT_SESSION_KEY = 'geoflow.admin_ui_v3.recent';

    /**
     * Canonical descriptors for navigation, active state, shell classification
     * and recent-page metadata.
     *
     * @return list<array{key:string,group:string,label_key:string,icon:string,route:string,protected:bool,patterns:list<string>,recent_tone:string}>
     */
    private function modules(): array
    {
        return [
            [
                'key' => 'ai-workspace', 'group' => 'workspace', 'label_key' => 'admin.nav.ai_workspace',
                'icon' => 'sparkles', 'route' => 'admin.ai-workspace', 'protected' => false,
                'patterns' => ['admin.ai-workspace', 'admin.ai-workspace.*'], 'recent_tone' => 'blue',
            ],
            [
                'key' => 'dashboard', 'group' => 'data', 'label_key' => 'admin.nav.data_center',
                'icon' => 'chart-no-axes-combined', 'route' => 'admin.analytics', 'protected' => false,
                'patterns' => ['admin.dashboard', 'admin.analytics', 'admin.analytics.*'], 'recent_tone' => 'blue',
            ],
            [
                'key' => 'tasks', 'group' => 'content', 'label_key' => 'admin.nav.tasks',
                'icon' => 'workflow', 'route' => 'admin.tasks.index', 'protected' => false,
                'patterns' => ['admin.tasks.*'], 'recent_tone' => 'green',
            ],
            [
                'key' => 'articles', 'group' => 'content', 'label_key' => 'admin.nav.articles',
                'icon' => 'file-text', 'route' => 'admin.articles.index', 'protected' => false,
                'patterns' => ['admin.articles.*', 'admin.manual-publications.*'], 'recent_tone' => 'violet',
            ],
            [
                'key' => 'materials', 'group' => 'content', 'label_key' => 'admin.nav.materials',
                'icon' => 'database', 'route' => 'admin.materials.index', 'protected' => false,
                'patterns' => [
                    'admin.materials.*', 'admin.categories.*', 'admin.authors.*', 'admin.keyword-libraries.*',
                    'admin.title-libraries.*', 'admin.image-libraries.*', 'admin.knowledge-bases.*',
                    'admin.enterprise-knowledge.*', 'admin.url-import*',
                ],
                'recent_tone' => 'green',
            ],
            [
                'key' => 'distribution', 'group' => 'distribution', 'label_key' => 'admin.nav.distribution',
                'icon' => 'radio-tower', 'route' => 'admin.distribution.index', 'protected' => true,
                'patterns' => ['admin.distribution.*'], 'recent_tone' => 'violet',
            ],
            [
                'key' => 'ai_config', 'group' => 'system', 'label_key' => 'admin.nav.ai_config',
                'icon' => 'network', 'route' => 'admin.ai.configurator', 'protected' => false,
                'patterns' => [
                    'admin.ai.configurator', 'admin.ai-models.*', 'admin.ai-source-providers.*',
                    'admin.ai-prompts*', 'admin.ai-special-prompts*',
                ],
                'recent_tone' => 'blue',
            ],
            [
                'key' => 'site_settings', 'group' => 'system', 'label_key' => 'admin.nav.site_settings',
                'icon' => 'settings', 'route' => 'admin.site-settings.index', 'protected' => false,
                'patterns' => array_values(array_unique(array_merge(
                    ['admin.account.*'],
                    ...array_column($this->settingsSections(), 'patterns'),
                ))),
                'recent_tone' => 'green',
            ],
        ];
    }

    /** @return list<array{key:string,label_key:string,route:string,patterns:list<string>,protected:bool}> */
    private function settingsSections(): array
    {
        return [
            ['key' => 'site', 'label_key' => 'admin.ui_v3.settings_site_brand', 'route' => 'admin.site-settings.index', 'patterns' => ['admin.site-settings.index'], 'protected' => false],
            ['key' => 'theme', 'label_key' => 'admin.ui_v3.settings_home_theme', 'route' => 'admin.site-settings.homepage-modules.edit', 'patterns' => ['admin.site-settings.homepage*', 'admin.site-settings.theme-replications.*', 'admin.site-theme-replications.*'], 'protected' => false],
            ['key' => 'forms', 'label_key' => 'admin.ui_v3.settings_forms_leads', 'route' => 'admin.lead-forms.index', 'patterns' => ['admin.lead-forms.*', 'admin.leads.*'], 'protected' => false],
            ['key' => 'users', 'label_key' => 'admin.ui_v3.users_permissions', 'route' => 'admin.admin-users.index', 'patterns' => ['admin.admin-users.*', 'admin.api-tokens.*'], 'protected' => true],
            ['key' => 'security', 'label_key' => 'admin.ui_v3.security_audit', 'route' => 'admin.security-settings.index', 'patterns' => ['admin.security-settings.*', 'admin.site-settings.sensitive-words', 'admin.admin-activity-logs'], 'protected' => false],
            ['key' => 'updates', 'label_key' => 'admin.ui_v3.system_updates', 'route' => 'admin.system-updates.index', 'patterns' => ['admin.system-updates.*'], 'protected' => true],
        ];
    }

    /** @return array<string, string|null> */
    private function groups(): array
    {
        return [
            'workspace' => null,
            'data' => 'admin.nav.group_data',
            'content' => 'admin.nav.group_content',
            'distribution' => 'admin.nav.group_distribution',
            'system' => 'admin.nav.group_system',
        ];
    }

    /** @return list<array{id:string,label:string|null,items:list<array{key:string,label:string,icon:string,route:string,protected:bool}>}> */
    public function navigation(Admin $admin): array
    {
        $modules = collect($this->modules())
            ->filter(fn (array $module): bool => ! $module['protected'] || $admin->canManageProtectedWorkflows());

        return collect($this->groups())
            ->map(function (?string $labelKey, string $group) use ($modules): array {
                $items = $modules
                    ->where('group', $group)
                    ->map(fn (array $module): array => $this->navigationItem($module))
                    ->values()
                    ->all();

                return [
                    'id' => $group,
                    'label' => $labelKey === null ? null : __($labelKey),
                    'items' => $items,
                ];
            })
            ->filter(static fn (array $group): bool => $group['items'] !== [])
            ->values()
            ->all();
    }

    /** @return array{key:string,label:string,icon:string,route:string,protected:bool} */
    public function currentPage(Admin $admin, ?string $routeName, string $legacyActive = ''): array
    {
        $activeKey = $this->activeKey($routeName, $legacyActive);

        foreach ($this->navigation($admin) as $group) {
            foreach ($group['items'] as $item) {
                if ($item['key'] === $activeKey) {
                    return $item;
                }
            }
        }

        $dashboard = collect($this->modules())->firstWhere('key', 'dashboard');

        return $this->navigationItem($dashboard);
    }

    /** @return list<array{key:string,label:string,route:string,active:bool}> */
    public function settingsNavigation(Admin $admin, ?string $routeName): array
    {
        $routeName = (string) $routeName;

        return collect($this->settingsSections())
            ->filter(fn (array $item): bool => ! $item['protected'] || $admin->canManageProtectedWorkflows())
            ->map(static fn (array $item): array => [
                'key' => $item['key'],
                'label' => __($item['label_key']),
                'route' => $item['route'],
                'active' => Str::is($item['patterns'], $routeName),
            ])
            ->values()
            ->all();
    }

    public function activeKey(?string $routeName, string $legacyActive = ''): string
    {
        $routeName = (string) $routeName;

        foreach ($this->modules() as $module) {
            if (Str::is($module['patterns'], $routeName)) {
                return $module['key'];
            }
        }

        return match ($legacyActive) {
            'analytics' => 'dashboard',
            'admin_users' => 'site_settings',
            default => $legacyActive,
        };
    }

    public function routeClassification(string $routeName): ?string
    {
        $classifications = [
            'redirect' => ['admin.entry', 'admin.locale.switch', 'admin.security-settings.index'],
            'special' => ['admin.login', 'admin.site-settings.theme-replications.preview'],
            'download' => [
                'admin.leads.export', 'admin.manual-publications.export',
                'admin.articles.batch.export-markdown.download',
                'admin.site-settings.theme-replications.package',
                'admin.system-updates.updater.download',
            ],
            'binary' => ['admin.ai-workspace.media.show'],
            'endpoint' => [
                'admin.recent.index',
                'admin.articles.editor.titles', 'admin.distribution.sync-settings*.preview',
                'admin.enterprise-knowledge.status', 'admin.site-settings.theme-replications.status',
                'admin.system-updates.runs.status', 'admin.tasks.health', 'admin.url-import.status',
                'admin.title-libraries.ai-generate.status',
                'admin.ai-workspace.conversations.index', 'admin.ai-workspace.conversations.show',
            ],
            'shell' => collect($this->modules())->pluck('patterns')->flatten()->unique()->values()->all(),
        ];

        foreach ($classifications as $classification => $patterns) {
            if (Str::is($patterns, $routeName)) {
                return $classification;
            }
        }

        return null;
    }

    public function shouldRememberRoute(string $routeName): bool
    {
        if ($this->routeClassification($routeName) !== 'shell') {
            return false;
        }

        return collect($this->modules())->contains(
            static fn (array $module): bool => Str::is($module['patterns'], $routeName)
        );
    }

    public function remember(Request $request, Admin $admin): void
    {
        if (! (bool) config('geoflow.admin_ui_v3_enabled', false)
            || ! $request->isMethod('GET')
            || ! $request->hasSession()) {
            return;
        }

        $routeName = (string) ($request->route()?->getName() ?? '');
        if (! $this->shouldRememberRoute($routeName)) {
            return;
        }
        $entry = $this->recentEntryForRoute($routeName, $admin);
        if ($entry === null) {
            return;
        }

        $sessionKey = $this->recentSessionKey($admin);
        $entries = collect((array) $request->session()->get($sessionKey, []))
            ->filter(static fn (mixed $candidate): bool => is_array($candidate))
            ->reject(static fn (array $candidate): bool => ($candidate['route'] ?? null) === $entry['route'])
            ->prepend($entry)
            ->take(10)
            ->values()
            ->all();

        $request->session()->put($sessionKey, $entries);
    }

    /** @return list<array{route:string,label:string,tone:string,visited_at:?string}> */
    public function recent(Admin $admin): array
    {
        if (! request()->hasSession()) {
            return [];
        }

        return collect((array) request()->session()->get($this->recentSessionKey($admin), []))
            ->filter(static fn (mixed $entry): bool => is_array($entry))
            ->filter(fn (array $entry): bool => $this->isRecentEntryAllowed($entry, $admin))
            ->take(10)
            ->map(static fn (array $entry): array => [
                'route' => (string) $entry['route'],
                'label' => __((string) $entry['label_key']),
                'tone' => (string) $entry['tone'],
                'visited_at' => isset($entry['visited_at']) ? (string) $entry['visited_at'] : null,
            ])
            ->values()
            ->all();
    }

    /** @return array{route:string,label_key:string,tone:string,visited_at:string}|null */
    private function recentEntryForRoute(string $routeName, Admin $admin): ?array
    {
        $activeKey = $this->activeKey($routeName);
        $module = collect($this->modules())->firstWhere('key', $activeKey);

        if ($module === null) {
            return null;
        }

        $entry = [
            'route' => $module['route'],
            'label_key' => $module['label_key'],
            'tone' => $module['recent_tone'],
            'visited_at' => now()->toISOString(),
        ];

        return $this->isRecentEntryAllowed($entry, $admin) ? $entry : null;
    }

    /** @param array<string,mixed> $entry */
    private function isRecentEntryAllowed(array $entry, Admin $admin): bool
    {
        $routeName = (string) ($entry['route'] ?? '');
        if ($routeName === '' || ! app('router')->has($routeName)) {
            return false;
        }

        $module = collect($this->modules())->firstWhere('route', $routeName);

        return $module !== null && (! $module['protected'] || $admin->canManageProtectedWorkflows());
    }

    private function recentSessionKey(Admin $admin): string
    {
        return self::RECENT_SESSION_KEY.'.'.(int) $admin->getKey();
    }

    /**
     * @param  array{key:string,label_key:string,icon:string,route:string,protected:bool}  $module
     * @return array{key:string,label:string,icon:string,route:string,protected:bool}
     */
    private function navigationItem(array $module): array
    {
        return [
            'key' => $module['key'],
            'label' => __($module['label_key']),
            'icon' => $module['icon'],
            'route' => $module['route'],
            'protected' => $module['protected'],
        ];
    }
}
