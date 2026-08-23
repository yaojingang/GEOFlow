<?php

namespace App\Ai\Workspace;

use App\Models\Admin;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use InvalidArgumentException;

final class AiCapabilityRegistry
{
    /** @var Collection<string,AiCapabilityDefinition> */
    private Collection $capabilities;

    public function __construct()
    {
        $this->capabilities = collect((array) config('ai-workspace.capabilities', []))
            ->mapWithKeys(static fn (array $definition, string $key): array => [
                $key => AiCapabilityDefinition::fromArray($key, $definition),
            ]);
    }

    /** @return Collection<string,AiCapabilityDefinition> */
    public function all(): Collection
    {
        return $this->capabilities;
    }

    /** @return Collection<string,AiCapabilityDefinition> */
    public function visibleTo(Admin $admin): Collection
    {
        return $this->capabilities->filter(static fn (AiCapabilityDefinition $capability): bool => $capability->allows($admin));
    }

    public function get(string $key): AiCapabilityDefinition
    {
        $capability = $this->capabilities->get($key);
        if (! $capability instanceof AiCapabilityDefinition) {
            throw new InvalidArgumentException('Unknown AI capability: '.$key);
        }

        return $capability;
    }

    public function findForRoute(string $routeName): ?AiCapabilityDefinition
    {
        $risk = ['low' => 1, 'medium' => 2, 'high' => 3, 'critical' => 4];

        return $this->capabilities
            ->filter(static fn (AiCapabilityDefinition $capability): bool => $capability->coversRoute($routeName))
            ->sortByDesc(static function (AiCapabilityDefinition $capability) use ($routeName, $risk): int {
                $exact = in_array($routeName, $capability->routePatterns, true) ? 1 : 0;
                $restricted = $capability->maturity === 'restricted' ? 1 : 0;

                return ($exact * 100) + ($restricted * 10) + ($risk[$capability->risk] ?? 4);
            })
            ->first();
    }

    public function routeIsExcluded(string $routeName): bool
    {
        foreach ((array) config('ai-workspace.route_exclusions', []) as $pattern) {
            if (Str::is((string) $pattern, $routeName)) {
                return true;
            }
        }

        return false;
    }

    /** @return array<string,string> */
    public function versions(array $keys): array
    {
        $versions = [];
        foreach (array_values(array_unique(array_map('strval', $keys))) as $key) {
            $versions[$key] = $this->get($key)->version;
        }

        ksort($versions);

        return $versions;
    }
}
