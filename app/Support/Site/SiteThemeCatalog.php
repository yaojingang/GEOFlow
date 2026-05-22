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
        if (! is_dir($themesRoot)) {
            return [];
        }

        $themes = [];
        $entries = scandir($themesRoot);
        if (! is_array($entries)) {
            return [];
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
}
