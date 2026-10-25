<?php

namespace App\Support\Site;

use App\Models\SiteSetting;
use App\Models\SiteThemeBinding;
use App\Services\Site\SiteAppearanceService;
use App\Services\Topics\TopicTemplateCatalog;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class SiteThemeCatalog
{
    public const LIBRARY_STATE_KEY = 'theme_library_state';

    public const DEFAULT_PREVIEW_ID = '__system_default';

    /**
     * @return array<int, array{id:string,name:string,version:string,description:string}>
     */
    public function all(): array
    {
        $themesRoot = resource_path('views/theme');
        $themes = [];
        $entries = is_dir($themesRoot) ? scandir($themesRoot) : [];
        if (! is_array($entries)) {
            $entries = [];
        }

        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            if (! preg_match('/^[a-zA-Z0-9_-]+$/', $entry)) {
                continue;
            }

            $themeDir = $themesRoot.DIRECTORY_SEPARATOR.$entry;
            if (! is_dir($themeDir)) {
                continue;
            }

            $manifestPath = $themeDir.DIRECTORY_SEPARATOR.'manifest.json';
            if (is_file($manifestPath)) {
                $manifestRaw = file_get_contents($manifestPath);
                if (! is_string($manifestRaw) || $manifestRaw === '') {
                    continue;
                }

                $manifest = json_decode($manifestRaw, true);
                if (! is_array($manifest)) {
                    continue;
                }

                $themes[] = [
                    'id' => (string) $entry,
                    'name' => (string) ($manifest['name'] ?? $entry),
                    'version' => (string) ($manifest['version'] ?? ''),
                    'description' => (string) ($manifest['description'] ?? ''),
                ];

                continue;
            }

            if (! is_file($themeDir.DIRECTORY_SEPARATOR.'home.blade.php')) {
                continue;
            }

            $themes[] = [
                'id' => (string) $entry,
                'name' => ucfirst(str_replace(['-', '_'], ' ', $entry)),
                'version' => '',
                'description' => '',
            ];
        }

        $builtinIds = array_column($themes, 'id');
        foreach ($themes as &$theme) {
            $manifest = json_decode((string) @file_get_contents($themesRoot.'/'.$theme['id'].'/manifest.json'), true);
            $theme['source'] = 'builtin';
            $theme['topic'] = TopicTemplateCatalog::capability(is_array($manifest) ? $manifest : []);
            $theme['distribution'] = is_array($manifest) ? (array) ($manifest['distribution'] ?? []) : [];
            $theme['can_export'] = is_array($manifest) && $theme['id'] !== 'default';
        }
        unset($theme);
        foreach (app(InstalledSiteThemeRepository::class)->all() as $theme) {
            if (! in_array($theme['id'], $builtinIds, true)) {
                $theme['distribution'] = (array) ($theme['manifest']['distribution'] ?? []);
                $theme['topic'] = TopicTemplateCatalog::capability($theme['manifest'] ?? []);
                $theme['can_export'] = true;
                $themes[] = $theme;
            }
        }

        usort($themes, static fn (array $a, array $b): int => strcmp((string) $a['name'], (string) $b['name']));

        return $themes;
    }

    /**
     * @return array<int,string>
     */
    public function ids(): array
    {
        return array_map(static fn (array $theme): string => (string) $theme['id'], $this->all());
    }

    public function defaultTheme(): array
    {
        return ['id' => '', 'name' => __('theme_library.selections.general.name'), 'description' => '', 'version' => '', 'source' => 'builtin', 'can_export' => false, 'distribution' => []];
    }

    public function libraryTheme(string $id): ?array
    {
        return $id === self::DEFAULT_PREVIEW_ID
            ? $this->defaultTheme()
            : collect($this->all())->firstWhere('id', $id);
    }

    /** @return array{current:array,items:LengthAwarePaginator,counts:array,tab:string,search:string,source:string} */
    public function library(array $filters, string $activeTheme): array
    {
        $state = $this->libraryState();
        $featured = (array) config('theme-library.featured', []);
        $samples = (array) config('theme-library.samples', []);
        $themes = array_merge([$this->defaultTheme()], $this->all());
        $groups = ['featured' => [], 'personal' => [], 'archived' => []];
        $current = null;

        foreach ($themes as $theme) {
            $item = $this->present($theme, $activeTheme);
            if ($item['active']) {
                $current = $item;
            }
            $id = $theme['id'];
            $tab = array_key_exists($id, $featured) ? 'featured' : 'personal';
            if (($state[$id] ?? null) === 'archived' || (in_array($id, $samples, true) && ($state[$id] ?? null) !== 'restored')) {
                $tab = $item['active'] ? 'personal' : 'archived';
            }
            $groups[$tab][] = $item;
        }

        $tab = $filters['theme_tab'] ?? 'featured';
        $search = trim((string) ($filters['theme_search'] ?? ''));
        $source = $filters['theme_source'] ?? '';
        $counts = array_map(fn (array $items): int => count($this->groupVersions($items)), $groups);
        $items = array_values(array_filter($groups[$tab], static function (array $item) use ($search, $source): bool {
            $sourceMatches = $source === '' || ($source === 'private' ? $item['private'] : $item['source'] === $source);
            $text = implode(' ', [$item['id'], $item['name'], $item['display_name'], $item['description'], $item['display_description']]);

            return $sourceMatches && ($search === '' || Str::contains(Str::lower($text), Str::lower($search)));
        }));
        $items = $this->groupVersions($items);
        if ($tab === 'featured') {
            $order = array_flip(array_keys($featured));
            usort($items, static fn (array $a, array $b): int => ($order[$a['id']] ?? PHP_INT_MAX) <=> ($order[$b['id']] ?? PHP_INT_MAX));
        }
        $page = min(max(1, (int) ($filters['theme_page'] ?? 1)), max(1, (int) ceil(count($items) / 12)));
        $query = array_filter(['theme_tab' => $tab, 'theme_search' => $search, 'theme_source' => $source], static fn ($value): bool => $value !== '');
        $paginator = new LengthAwarePaginator(array_slice($items, ($page - 1) * 12, 12), count($items), 12, $page, [
            'path' => route('admin.site-settings.index'), 'pageName' => 'theme_page', 'query' => $query,
        ]);
        $paginator->fragment('site-settings-theme');

        return ['current' => $current ?? $this->present(['id' => $activeTheme, 'name' => $activeTheme, 'description' => '', 'version' => '', 'source' => 'builtin'], $activeTheme), 'items' => $paginator, 'counts' => $counts, 'tab' => $tab, 'search' => $search, 'source' => $source];
    }

    /** @return array{changed:int,retained:list<string>} */
    public function manageLibrary(array $ids, string $action): array
    {
        app(SiteAppearanceService::class)->binding();

        return DB::transaction(function () use ($ids, $action): array {
            SiteThemeBinding::query()->whereKey('primary')->lockForUpdate()->firstOrFail();
            $active = (string) (SiteSetting::query()->useWritePdo()->where('setting_key', 'active_theme')->value('setting_value') ?? config('geoflow.default_theme', ''));
            $row = SiteSetting::query()->firstOrCreate(['setting_key' => self::LIBRARY_STATE_KEY], ['setting_value' => '{}']);
            $state = $this->decodeLibraryState($row->setting_value);
            $names = collect($this->all())->keyBy('id');
            $retained = [];
            $changed = 0;
            foreach (array_unique($ids) as $id) {
                if ($action === 'archive' && $id === $active) {
                    $retained[] = $this->present($names[$id], $active)['display_name'];

                    continue;
                }
                $state[$id] = $action === 'archive' ? 'archived' : 'restored';
                $changed++;
            }
            $row->update(['setting_value' => json_encode($state, JSON_THROW_ON_ERROR)]);

            return ['changed' => $changed, 'retained' => $retained];
        });
    }

    private function libraryState(): array
    {
        return $this->decodeLibraryState(SiteSetting::query()->useWritePdo()->where('setting_key', self::LIBRARY_STATE_KEY)->value('setting_value'));
    }

    private function decodeLibraryState(?string $value): array
    {
        $state = json_decode($value ?? '{}', true);

        return is_array($state) ? array_filter($state, static fn ($status): bool => in_array($status, ['archived', 'restored'], true)) : [];
    }

    private function present(array $theme, string $active): array
    {
        $key = config('theme-library.featured', [])[$theme['id']] ?? null;
        $thumbnail = $key ? 'images/theme-library/'.$key.'.jpg' : null;
        $private = in_array($theme['distribution']['visibility'] ?? '', ['private', 'customer_private'], true);

        return array_merge($theme, [
            'display_name' => $key ? __('theme_library.selections.'.$key.'.name') : $theme['name'],
            'display_description' => $key ? __('theme_library.selections.'.$key.'.description') : __('theme_library.custom_description'),
            'active' => $theme['id'] === $active,
            'private' => $private,
            'source' => $theme['source'] ?? 'builtin',
            'can_export' => $theme['can_export'] ?? false,
            'preview_id' => $theme['id'] === '' ? self::DEFAULT_PREVIEW_ID : $theme['id'],
            'thumbnail' => $thumbnail && is_file(public_path($thumbnail)) ? asset($thumbnail) : null,
        ]);
    }

    private function groupVersions(array $items): array
    {
        $groupIds = [];
        foreach ((array) config('theme-library.version_groups', []) as $group => $ids) {
            foreach ($ids as $id) {
                $groupIds[$id] = $group;
            }
        }
        $groups = [];
        foreach ($items as $item) {
            $groups[$groupIds[$item['id']] ?? $item['id']][] = $item;
        }
        $result = [];
        foreach ($groups as $versions) {
            usort($versions, static fn (array $a, array $b): int => ($b['active'] <=> $a['active']) ?: version_compare($b['version'], $a['version']));
            $head = $versions[0];
            $head['versions'] = $versions;
            $result[] = $head;
        }

        return $result;
    }

    /**
     * @return array<int, array{id:string,name:string,version:string,description:string}>
     */
    public function hostedCompatible(): array
    {
        $certifiedIds = array_values(array_unique(array_map(
            'strval',
            (array) config('geoflow.hosted_sites.certified_themes', ['default'])
        )));

        return array_values(array_filter(
            $this->all(),
            static fn (array $theme): bool => ($theme['source'] ?? 'builtin') === 'builtin'
                && in_array((string) $theme['id'], $certifiedIds, true)
        ));
    }

    /** @return array<int,string> */
    public function hostedCompatibleIds(): array
    {
        return array_map(
            static fn (array $theme): string => (string) $theme['id'],
            $this->hostedCompatible()
        );
    }
}
