<?php

namespace App\Services\AiWorkspace;

use App\Models\Admin;
use App\Support\AdminWeb;
use Illuminate\Routing\Route;
use Illuminate\Support\Str;

final class AdminHelpFeatureRegistry
{
    public function __construct(private readonly AdminHelpKnowledgeCatalog $catalog) {}

    /** @return list<array<string, mixed>> */
    public function entries(): array
    {
        return $this->catalog->entries();
    }

    /** @return array<string, mixed>|null */
    public function featureForRoute(string $routeName): ?array
    {
        foreach ($this->entries() as $entry) {
            if ((string) ($entry['route'] ?? '') === $routeName) {
                return $entry;
            }
        }

        $featureId = $this->routeOwners()[$routeName] ?? null;
        if (is_string($featureId)) {
            return $this->featureForId($featureId);
        }

        return null;
    }

    /** @return array<string, mixed>|null */
    public function featureForId(string $featureId): ?array
    {
        foreach ($this->entries() as $entry) {
            if ((string) ($entry['id'] ?? '') === $featureId) {
                return $entry;
            }
        }

        return null;
    }

    /** @return list<string> */
    public function trustedRouteNames(Admin $admin, string $content): array
    {
        preg_match_all('/\[\[route:([^|\]]+)\|[^\]]+\]\]/u', $content, $matches);

        return collect($matches[1] ?? [])
            ->map(static fn (mixed $route): string => trim((string) $route))
            ->filter(fn (string $route): bool => $this->isTrustedEntryRoute($admin, $route))
            ->unique()
            ->values()
            ->all();
    }

    /** @param list<string> $routeNames @return list<array{id:string,title:string,description:string,icon:string,url:string}> */
    public function relatedFeatures(Admin $admin, array $routeNames, int $limit = 3): array
    {
        return collect($routeNames)
            ->map(function (string $routeName) use ($admin): ?array {
                $entry = $this->featureForRoute($routeName);
                if (! is_array($entry) || ! $this->isAvailableTo($entry, $admin)) {
                    return null;
                }

                return ['route_name' => $routeName, 'entry' => $entry];
            })
            ->filter()
            ->unique('route_name')
            ->take(max(1, min(3, $limit)))
            ->map(static fn (array $item): array => [
                'id' => (string) $item['entry']['id'],
                'title' => (string) $item['entry']['name'],
                'description' => (string) $item['entry']['description'],
                'icon' => (string) $item['entry']['icon'],
                'url' => AdminWeb::routePath((string) $item['route_name']),
            ])
            ->values()
            ->all();
    }

    /** @return list<string> */
    public function aliasesFor(array $entry): array
    {
        return collect([
            (string) ($entry['id'] ?? ''),
            (string) ($entry['name'] ?? ''),
            ...((array) ($entry['keywords'] ?? [])),
            Str::afterLast((string) ($entry['route'] ?? ''), '.'),
        ])->map(static fn (mixed $alias): string => Str::lower(trim((string) $alias)))
            ->filter()
            ->reject(static fn (string $alias): bool => in_array($alias, ['index', 'show', 'create', 'edit'], true))
            ->unique()
            ->values()
            ->all();
    }

    public function canAccessRoute(Admin $admin, string $routeName): bool
    {
        return $this->isTrustedEntryRoute($admin, $routeName);
    }

    private function isTrustedEntryRoute(Admin $admin, string $routeName): bool
    {
        $entry = $this->featureForRoute($routeName);
        if (! is_array($entry) || ! $this->isAvailableTo($entry, $admin)) {
            return false;
        }

        $route = app('router')->getRoutes()->getByName($routeName);

        return $route !== null
            && in_array('GET', $route->methods(), true)
            && $route->parameterNames() === []
            && (! $this->requiresProtectedWorkflow($route) || $admin->canManageProtectedWorkflows());
    }

    /** @return array<string, string> */
    private function routeOwners(): array
    {
        return [
            'admin.dashboard' => 'data-center',
            'admin.analytics.content' => 'data-center',
            'admin.articles.create' => 'articles',
            'admin.tasks.create' => 'tasks',
            'admin.title-libraries.index' => 'materials',
            'admin.url-import' => 'materials',
            'admin.ai-models.index' => 'ai-config',
            'admin.ai-prompts' => 'ai-config',
            'admin.account.browser-clients.index' => 'manual-publications',
            'admin.distribution.hosted-sites.index' => 'distribution',
            'admin.enterprise-knowledge.index' => 'knowledge-bases',
        ];
    }

    /** @param array<string, mixed> $entry */
    private function isAvailableTo(array $entry, Admin $admin): bool
    {
        $route = app('router')->getRoutes()->getByName((string) ($entry['route'] ?? ''));

        return $route instanceof Route
            && (! (bool) ($entry['protected'] ?? false) || $admin->canManageProtectedWorkflows())
            && (! $this->requiresProtectedWorkflow($route) || $admin->canManageProtectedWorkflows());
    }

    private function requiresProtectedWorkflow(Route $route): bool
    {
        return collect($route->gatherMiddleware())->contains(
            static fn (string $middleware): bool => $middleware === 'admin.super'
                || str_starts_with($middleware, 'admin.super:'),
        );
    }
}
