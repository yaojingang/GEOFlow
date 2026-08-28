<?php

namespace App\Services\AiWorkspace;

use Illuminate\Routing\Route;

final class AdminHelpRouteCoverage
{
    public function __construct(private readonly AdminHelpFeatureRegistry $features) {}

    /** @return array{route:string,category:string,feature_id:?string,methods:list<string>,uri:string} */
    public function classify(Route $route): array
    {
        $name = (string) $route->getName();
        $entry = $this->features->featureForRoute($name);
        $methods = array_values(array_filter($route->methods(), static fn (string $method): bool => $method !== 'HEAD'));
        $category = match (true) {
            is_array($entry) => 'entry',
            $route->parameterNames() !== [] => 'dynamic',
            ! in_array('GET', $methods, true) => 'action',
            $this->isRestricted($name, $route) => 'restricted',
            default => 'endpoint',
        };

        return [
            'route' => $name,
            'category' => $category,
            'feature_id' => is_array($entry) ? (string) $entry['id'] : null,
            'methods' => $methods,
            'uri' => $route->uri(),
        ];
    }

    /** @return list<array{route:string,category:string,feature_id:?string,methods:list<string>,uri:string}> */
    public function all(): array
    {
        return collect(app('router')->getRoutes()->getRoutes())
            ->filter(static fn (Route $route): bool => str_starts_with((string) $route->getName(), 'admin.'))
            ->map(fn (Route $route): array => $this->classify($route))
            ->sortBy('route')
            ->values()
            ->all();
    }

    private function isRestricted(string $routeName, Route $route): bool
    {
        if (str_starts_with($routeName, 'admin.admin-users.')
            || str_starts_with($routeName, 'admin.system-updates.')
            || str_starts_with($routeName, 'admin.admin-activity-logs')) {
            return true;
        }

        return collect($route->gatherMiddleware())
            ->contains(static fn (string $middleware): bool => str_contains($middleware, 'super'));
    }
}
