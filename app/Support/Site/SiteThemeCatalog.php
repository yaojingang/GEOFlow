<?php

namespace App\Support\Site;

class SiteThemeCatalog
{
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
            $theme['distribution'] = is_array($manifest) ? (array) ($manifest['distribution'] ?? []) : [];
            $theme['can_export'] = is_array($manifest) && $theme['id'] !== 'default';
        }
        unset($theme);
        foreach (app(InstalledSiteThemeRepository::class)->all() as $theme) {
            if (! in_array($theme['id'], $builtinIds, true)) {
                $theme['distribution'] = (array) ($theme['manifest']['distribution'] ?? []);
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
