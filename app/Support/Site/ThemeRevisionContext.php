<?php

namespace App\Support\Site;

use App\Models\SiteThemeBinding;
use App\Models\ThemeRevision;
use App\Services\Api\ThemeRevisionStorage;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\View;

final class ThemeRevisionContext
{
    private bool $loaded = false;

    private ?array $snapshot = null;

    public function reset(): void
    {
        $this->loaded = false;
        $this->snapshot = null;
    }

    public function snapshot(): ?array
    {
        if (! $this->loaded) {
            $this->loaded = true;
            if (app(CurrentSite::class)->isPrimary() && Schema::hasTable('site_theme_bindings')) {
                $binding = SiteThemeBinding::query()->useWritePdo()->find('primary');
                $this->snapshot = $binding?->revision_id !== null ? ['revision_id' => $binding->revision_id, 'theme_id' => $binding->theme_id, 'settings' => $binding->settings] : null;
            }
        }

        return $this->snapshot;
    }

    public function views(): void
    {
        $id = $this->snapshot()['revision_id'] ?? null;
        if ($id === null) {
            return;
        }
        $revision = ThemeRevision::query()->find($id);
        abort_unless($revision && $revision->state === 'ready', 503);
        $storage = app(ThemeRevisionStorage::class);
        $root = $storage->storage->path('revisions/'.$id);
        $finder = View::getFinder();
        if (! $finder instanceof ManagedThemeViewFinder || ! $finder->hasRoot($root)) {
            $storage->contents($revision);
            View::setFinder(new ManagedThemeViewFinder($revision->theme_id, $root, $revision->files, $finder instanceof ManagedThemeViewFinder ? $finder->baseFinder() : $finder));
        }
    }

    public function preview(ThemeRevision $revision, callable $render): mixed
    {
        $loaded = $this->loaded;
        $snapshot = $this->snapshot;
        $finder = View::getFinder();
        $paths = $finder->getPaths();
        if ($finder instanceof ManagedThemeViewFinder) {
            View::setFinder($finder->baseFinder());
        }
        $this->loaded = true;
        $this->snapshot = ['revision_id' => $revision->id, 'theme_id' => $revision->theme_id, 'settings' => $revision->settings];
        try {
            return $render();
        } finally {
            $this->loaded = $loaded;
            $this->snapshot = $snapshot;
            View::setFinder($finder);
            $finder->setPaths($paths);
            $finder->flush();
        }
    }
}
