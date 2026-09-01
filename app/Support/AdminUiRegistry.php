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

    /** @return list<array{key:string,label_key:string,route:string,patterns:list<string>}> */
    private function aiConfiguratorSections(): array
    {
        return [
            ['key' => 'models', 'label_key' => 'admin.ai_configurator.models_title', 'route' => 'admin.ai-models.index', 'patterns' => ['admin.ai-models.*']],
            ['key' => 'prompts', 'label_key' => 'admin.ai_configurator.prompts_title', 'route' => 'admin.ai-prompts', 'patterns' => ['admin.ai-prompts', 'admin.ai-prompts.*']],
            ['key' => 'special', 'label_key' => 'admin.ai_configurator.special_title', 'route' => 'admin.ai-special-prompts', 'patterns' => ['admin.ai-special-prompts', 'admin.ai-special-prompts.*']],
            ['key' => 'sources', 'label_key' => 'admin.ai_configurator.search_title', 'route' => 'admin.ai-source-providers.index', 'patterns' => ['admin.ai-source-providers.*']],
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

    /**
     * Canonical page identities for the V3 admin topbar.
     *
     * The body heading mode controls whether the legacy page title is visually
     * retained as page content. Detail and assistant pages keep meaningful,
     * dynamic headings; list and form pages expose their existing H1 to screen
     * readers while the compact identity is shown in the topbar.
     *
     * @return array<string, array{key:string,icon:string,body_heading:'content'|'hidden'}>
     */
    private function pageIdentities(): array
    {
        return [
            'admin.ai-workspace' => ['key' => 'ai_workspace', 'icon' => 'sparkles', 'body_heading' => 'content'],
            'admin.dashboard' => ['key' => 'operations_dashboard', 'icon' => 'layout-dashboard', 'body_heading' => 'hidden'],
            'admin.analytics' => ['key' => 'analytics', 'icon' => 'chart-no-axes-combined', 'body_heading' => 'hidden'],
            'admin.analytics.content' => ['key' => 'content_analytics', 'icon' => 'file-text', 'body_heading' => 'hidden'],
            'admin.analytics.traffic' => ['key' => 'traffic_analytics', 'icon' => 'activity', 'body_heading' => 'hidden'],
            'admin.analytics.ai-visibility' => ['key' => 'ai_visibility', 'icon' => 'eye', 'body_heading' => 'hidden'],
            'admin.analytics.leads' => ['key' => 'lead_analytics', 'icon' => 'users-round', 'body_heading' => 'hidden'],
            'admin.analytics.distribution' => ['key' => 'distribution_analytics', 'icon' => 'radio-tower', 'body_heading' => 'hidden'],

            'admin.tasks.index' => ['key' => 'tasks', 'icon' => 'workflow', 'body_heading' => 'hidden'],
            'admin.tasks.workers' => ['key' => 'task_workers', 'icon' => 'cpu', 'body_heading' => 'hidden'],
            'admin.tasks.jobs' => ['key' => 'task_jobs', 'icon' => 'list-checks', 'body_heading' => 'hidden'],
            'admin.tasks.create' => ['key' => 'task_create', 'icon' => 'workflow', 'body_heading' => 'hidden'],
            'admin.tasks.edit' => ['key' => 'task_edit', 'icon' => 'square-pen', 'body_heading' => 'hidden'],

            'admin.articles.index' => ['key' => 'articles', 'icon' => 'file-text', 'body_heading' => 'hidden'],
            'admin.articles.create' => ['key' => 'article_create', 'icon' => 'file-plus-2', 'body_heading' => 'hidden'],
            'admin.articles.edit' => ['key' => 'article_edit', 'icon' => 'file-pen-line', 'body_heading' => 'hidden'],
            'admin.manual-publications.browser-connect.show' => ['key' => 'browser_connect', 'icon' => 'monitor-smartphone', 'body_heading' => 'hidden'],
            'admin.manual-publications.index' => ['key' => 'manual_publications', 'icon' => 'send', 'body_heading' => 'hidden'],
            'admin.manual-publications.create' => ['key' => 'manual_publication_create', 'icon' => 'send', 'body_heading' => 'hidden'],
            'admin.manual-publications.settings.index' => ['key' => 'manual_publication_settings', 'icon' => 'settings-2', 'body_heading' => 'hidden'],
            'admin.manual-publications.show' => ['key' => 'manual_publication_detail', 'icon' => 'file-check-2', 'body_heading' => 'content'],
            'admin.manual-publications.edit' => ['key' => 'manual_publication_edit', 'icon' => 'file-pen-line', 'body_heading' => 'hidden'],

            'admin.distribution.index' => ['key' => 'distribution', 'icon' => 'radio-tower', 'body_heading' => 'hidden'],
            'admin.distribution.create' => ['key' => 'distribution_create', 'icon' => 'radio-tower', 'body_heading' => 'hidden'],
            'admin.distribution.hosted-sites.index' => ['key' => 'hosted_sites', 'icon' => 'globe-2', 'body_heading' => 'hidden'],
            'admin.distribution.hosted-sites.create' => ['key' => 'hosted_site_create', 'icon' => 'globe-2', 'body_heading' => 'hidden'],
            'admin.distribution.hosted-sites.show' => ['key' => 'hosted_site_detail', 'icon' => 'globe', 'body_heading' => 'content'],
            'admin.distribution.hosted-sites.edit' => ['key' => 'hosted_site_edit', 'icon' => 'settings-2', 'body_heading' => 'hidden'],
            'admin.distribution.jobs' => ['key' => 'distribution_jobs', 'icon' => 'list-checks', 'body_heading' => 'hidden'],
            'admin.distribution.article.edit' => ['key' => 'distribution_article_edit', 'icon' => 'file-pen-line', 'body_heading' => 'hidden'],
            'admin.distribution.delete' => ['key' => 'distribution_delete', 'icon' => 'triangle-alert', 'body_heading' => 'hidden'],
            'admin.distribution.edit' => ['key' => 'distribution_edit', 'icon' => 'settings-2', 'body_heading' => 'hidden'],
            'admin.distribution.show' => ['key' => 'distribution_detail', 'icon' => 'radio-tower', 'body_heading' => 'content'],
            'admin.distribution.sync-settings-all.preview' => ['key' => 'distribution_sync_preview', 'icon' => 'git-compare-arrows', 'body_heading' => 'hidden'],
            'admin.distribution.sync-settings-selected.preview' => ['key' => 'distribution_sync_preview', 'icon' => 'git-compare-arrows', 'body_heading' => 'hidden'],
            'admin.distribution.sync-settings.preview' => ['key' => 'distribution_sync_preview', 'icon' => 'git-compare-arrows', 'body_heading' => 'hidden'],

            'admin.categories.index' => ['key' => 'categories', 'icon' => 'folder', 'body_heading' => 'hidden'],
            'admin.categories.create' => ['key' => 'category_create', 'icon' => 'folder-plus', 'body_heading' => 'hidden'],
            'admin.categories.edit' => ['key' => 'category_edit', 'icon' => 'folder-cog', 'body_heading' => 'hidden'],
            'admin.authors.index' => ['key' => 'authors', 'icon' => 'users', 'body_heading' => 'hidden'],
            'admin.authors.create' => ['key' => 'author_create', 'icon' => 'user-plus', 'body_heading' => 'hidden'],
            'admin.authors.edit' => ['key' => 'author_edit', 'icon' => 'user-round-pen', 'body_heading' => 'hidden'],
            'admin.authors.detail' => ['key' => 'author_detail', 'icon' => 'user', 'body_heading' => 'content'],
            'admin.keyword-libraries.index' => ['key' => 'keyword_libraries', 'icon' => 'tag', 'body_heading' => 'hidden'],
            'admin.keyword-libraries.create' => ['key' => 'keyword_library_create', 'icon' => 'tag', 'body_heading' => 'hidden'],
            'admin.keyword-libraries.edit' => ['key' => 'keyword_library_edit', 'icon' => 'tag', 'body_heading' => 'hidden'],
            'admin.keyword-libraries.detail' => ['key' => 'keyword_library_detail', 'icon' => 'library-big', 'body_heading' => 'content'],
            'admin.keyword-libraries.keywords.create' => ['key' => 'keyword_create', 'icon' => 'tag', 'body_heading' => 'hidden'],
            'admin.keyword-libraries.import.create' => ['key' => 'keyword_import', 'icon' => 'file-up', 'body_heading' => 'hidden'],
            'admin.title-libraries.index' => ['key' => 'title_libraries', 'icon' => 'type', 'body_heading' => 'hidden'],
            'admin.title-libraries.create' => ['key' => 'title_library_create', 'icon' => 'type', 'body_heading' => 'hidden'],
            'admin.title-libraries.edit' => ['key' => 'title_library_edit', 'icon' => 'type', 'body_heading' => 'hidden'],
            'admin.title-libraries.detail' => ['key' => 'title_library_detail', 'icon' => 'library-big', 'body_heading' => 'content'],
            'admin.title-libraries.titles.create' => ['key' => 'title_create', 'icon' => 'type', 'body_heading' => 'hidden'],
            'admin.title-libraries.import.create' => ['key' => 'title_import', 'icon' => 'file-up', 'body_heading' => 'hidden'],
            'admin.title-libraries.ai-generate' => ['key' => 'title_ai_generate', 'icon' => 'wand-sparkles', 'body_heading' => 'hidden'],
            'admin.image-libraries.index' => ['key' => 'image_libraries', 'icon' => 'images', 'body_heading' => 'hidden'],
            'admin.image-libraries.create' => ['key' => 'image_library_create', 'icon' => 'image-plus', 'body_heading' => 'hidden'],
            'admin.image-libraries.edit' => ['key' => 'image_library_edit', 'icon' => 'images', 'body_heading' => 'hidden'],
            'admin.image-libraries.detail' => ['key' => 'image_library_detail', 'icon' => 'library-big', 'body_heading' => 'content'],
            'admin.image-libraries.images.create' => ['key' => 'image_upload', 'icon' => 'upload', 'body_heading' => 'hidden'],
            'admin.knowledge-bases.index' => ['key' => 'knowledge_bases', 'icon' => 'library-big', 'body_heading' => 'hidden'],
            'admin.knowledge-bases.create' => ['key' => 'knowledge_base_create', 'icon' => 'library-big', 'body_heading' => 'hidden'],
            'admin.knowledge-bases.edit' => ['key' => 'knowledge_base_edit', 'icon' => 'library-big', 'body_heading' => 'hidden'],
            'admin.knowledge-bases.detail' => ['key' => 'knowledge_base_detail', 'icon' => 'file-search', 'body_heading' => 'content'],
            'admin.knowledge-bases.chunks.index' => ['key' => 'knowledge_base_chunks', 'icon' => 'blocks', 'body_heading' => 'content'],
            'admin.knowledge-bases.facts.index' => ['key' => 'knowledge_base_facts', 'icon' => 'list-checks', 'body_heading' => 'content'],
            'admin.enterprise-knowledge.index' => ['key' => 'enterprise_knowledge', 'icon' => 'database-zap', 'body_heading' => 'hidden'],
            'admin.enterprise-knowledge.create' => ['key' => 'enterprise_knowledge_create', 'icon' => 'database-zap', 'body_heading' => 'hidden'],
            'admin.enterprise-knowledge.show' => ['key' => 'enterprise_knowledge_detail', 'icon' => 'file-search', 'body_heading' => 'content'],
            'admin.materials.index' => ['key' => 'materials', 'icon' => 'database', 'body_heading' => 'hidden'],
            'admin.url-import' => ['key' => 'url_import', 'icon' => 'link', 'body_heading' => 'hidden'],
            'admin.url-import.history' => ['key' => 'url_import_history', 'icon' => 'history', 'body_heading' => 'hidden'],
            'admin.url-import.show' => ['key' => 'url_import_detail', 'icon' => 'file-search', 'body_heading' => 'content'],

            'admin.ai.configurator' => ['key' => 'ai_configurator', 'icon' => 'network', 'body_heading' => 'hidden'],
            'admin.ai-models.index' => ['key' => 'ai_models', 'icon' => 'cpu', 'body_heading' => 'hidden'],
            'admin.ai-models.create' => ['key' => 'ai_model_create', 'icon' => 'cpu', 'body_heading' => 'hidden'],
            'admin.ai-models.edit' => ['key' => 'ai_model_edit', 'icon' => 'cpu', 'body_heading' => 'hidden'],
            'admin.ai-source-providers.index' => ['key' => 'ai_sources', 'icon' => 'plug-zap', 'body_heading' => 'hidden'],
            'admin.ai-source-providers.create' => ['key' => 'ai_source_create', 'icon' => 'plug-zap', 'body_heading' => 'hidden'],
            'admin.ai-source-providers.edit' => ['key' => 'ai_source_edit', 'icon' => 'plug-zap', 'body_heading' => 'hidden'],
            'admin.ai-prompts' => ['key' => 'ai_prompts', 'icon' => 'message-square', 'body_heading' => 'hidden'],
            'admin.ai-prompts.create' => ['key' => 'ai_prompt_create', 'icon' => 'message-square', 'body_heading' => 'hidden'],
            'admin.ai-prompts.edit' => ['key' => 'ai_prompt_edit', 'icon' => 'message-square', 'body_heading' => 'hidden'],
            'admin.ai-special-prompts' => ['key' => 'ai_special_prompts', 'icon' => 'list', 'body_heading' => 'hidden'],

            'admin.account.show' => ['key' => 'account', 'icon' => 'user-round-cog', 'body_heading' => 'hidden'],
            'admin.account.browser-clients.index' => ['key' => 'browser_clients', 'icon' => 'monitor-smartphone', 'body_heading' => 'hidden'],
            'admin.system-updates.index' => ['key' => 'system_updates', 'icon' => 'refresh-cw', 'body_heading' => 'hidden'],
            'admin.system-updates.runs.show' => ['key' => 'system_update_detail', 'icon' => 'history', 'body_heading' => 'hidden'],
            'admin.system-updates.backups.show' => ['key' => 'system_backup_detail', 'icon' => 'archive', 'body_heading' => 'hidden'],
            'admin.lead-forms.index' => ['key' => 'lead_forms', 'icon' => 'clipboard-list', 'body_heading' => 'hidden'],
            'admin.lead-forms.create' => ['key' => 'lead_form_create', 'icon' => 'file-plus-2', 'body_heading' => 'hidden'],
            'admin.lead-forms.edit' => ['key' => 'lead_form_edit', 'icon' => 'file-pen-line', 'body_heading' => 'hidden'],
            'admin.leads.index' => ['key' => 'leads', 'icon' => 'inbox', 'body_heading' => 'hidden'],
            'admin.leads.show' => ['key' => 'lead_detail', 'icon' => 'user', 'body_heading' => 'hidden'],
            'admin.site-settings.index' => ['key' => 'site_settings', 'icon' => 'settings', 'body_heading' => 'hidden'],
            'admin.site-settings.homepage-modules.edit' => ['key' => 'homepage_modules', 'icon' => 'panels-top-left', 'body_heading' => 'hidden'],
            'admin.site-settings.theme-replications.create' => ['key' => 'theme_replication_create', 'icon' => 'copy-plus', 'body_heading' => 'hidden'],
            'admin.site-settings.theme-replications.show' => ['key' => 'theme_replication_detail', 'icon' => 'copy-check', 'body_heading' => 'content'],
            'admin.site-settings.sensitive-words' => ['key' => 'sensitive_words', 'icon' => 'shield-alert', 'body_heading' => 'hidden'],
            'admin.admin-users.index' => ['key' => 'admin_users', 'icon' => 'users', 'body_heading' => 'hidden'],
            'admin.admin-users.create' => ['key' => 'admin_user_create', 'icon' => 'user-plus', 'body_heading' => 'hidden'],
            'admin.admin-users.edit' => ['key' => 'admin_user_edit', 'icon' => 'user-round-cog', 'body_heading' => 'hidden'],
            'admin.admin-activity-logs' => ['key' => 'activity_logs', 'icon' => 'history', 'body_heading' => 'hidden'],
            'admin.api-tokens.index' => ['key' => 'api_tokens', 'icon' => 'key-round', 'body_heading' => 'hidden'],
        ];
    }

    /** @return array{key:string,title:string,icon:string,body_heading:'content'|'hidden'}|null */
    public function pageIdentity(?string $routeName): ?array
    {
        $descriptor = $this->pageIdentities()[(string) $routeName] ?? null;
        if ($descriptor === null) {
            return null;
        }

        return [
            'key' => $descriptor['key'],
            'title' => __('admin_pages.'.$descriptor['key']),
            'icon' => $descriptor['icon'],
            'body_heading' => $descriptor['body_heading'],
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

    /** @return list<array{key:string,label:string,route:string,active:bool}> */
    public function aiConfiguratorNavigation(?string $routeName): array
    {
        $routeName = (string) $routeName;

        return collect($this->aiConfiguratorSections())
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
                'admin.articles.ai-quality.status', 'admin.articles.ai-quality.optimization.candidate',
                'admin.articles.editor.titles',
                'admin.distribution.sync-settings*.preview',
                'admin.enterprise-knowledge.status', 'admin.site-settings.theme-replications.status',
                'admin.system-updates.runs.status', 'admin.tasks.health', 'admin.url-import.status',
                'admin.title-libraries.ai-generate.status',
                'admin.knowledge-bases.fact-generation.show',
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
