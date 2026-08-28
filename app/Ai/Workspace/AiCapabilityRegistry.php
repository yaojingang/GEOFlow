<?php

namespace App\Ai\Workspace;

use App\Ai\Workspace\CapabilityPackages\AiDomainCapabilityPackage;
use App\Ai\Workspace\CapabilityPackages\AnalyticsCapabilityPackage;
use App\Ai\Workspace\CapabilityPackages\ContentCapabilityPackage;
use App\Ai\Workspace\CapabilityPackages\DistributionCapabilityPackage;
use App\Ai\Workspace\CapabilityPackages\KnowledgeCapabilityPackage;
use App\Ai\Workspace\CapabilityPackages\SystemCapabilityPackage;
use App\Ai\Workspace\CapabilityPackages\TasksCapabilityPackage;
use App\Ai\Workspace\CapabilityPackages\VisibilityCapabilityPackage;
use App\Models\Admin;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use InvalidArgumentException;

final class AiCapabilityRegistry
{
    /** @var Collection<string,AiCapabilityDefinition> */
    private Collection $capabilities;

    /** @var list<string> */
    private array $featuredCapabilityKeys;

    /** @param iterable<AiDomainCapabilityPackage>|null $packages */
    public function __construct(?iterable $packages = null)
    {
        $packages ??= [
            new SystemCapabilityPackage,
            new AnalyticsCapabilityPackage,
            new VisibilityCapabilityPackage,
            new ContentCapabilityPackage,
            new TasksCapabilityPackage,
            new KnowledgeCapabilityPackage,
            new DistributionCapabilityPackage,
        ];
        $capabilities = collect();
        $packageKeys = [];
        $featuredCapabilityKeys = [];
        foreach ($packages as $package) {
            if (! $package instanceof AiDomainCapabilityPackage) {
                throw new InvalidArgumentException('Invalid AI capability package registration.');
            }
            if (isset($packageKeys[$package->key()])) {
                throw new InvalidArgumentException('Duplicate AI capability package: '.$package->key());
            }
            $packageKeys[$package->key()] = true;
            $definitions = $package->definitions();
            foreach ($definitions as $key => $definition) {
                if (! $definition instanceof AiCapabilityDefinition || $definition->key !== $key) {
                    throw new InvalidArgumentException('Invalid AI capability definition in package: '.$package->key());
                }
                if ($capabilities->has($key)) {
                    $existing = $capabilities->get($key);
                    $version = $existing instanceof AiCapabilityDefinition ? $existing->version : 'unknown';

                    throw new InvalidArgumentException(sprintf(
                        'Duplicate AI capability %s, existing version %s, incoming version %s.',
                        $key,
                        $version,
                        $definition->version,
                    ));
                }
                $capabilities->put($key, $definition);
            }
            foreach ($package->featuredPrompts() as $key) {
                if (! is_string($key) || $key === '' || ! array_key_exists($key, $definitions)) {
                    throw new InvalidArgumentException(sprintf(
                        'Invalid featured AI capability %s in package %s.',
                        is_scalar($key) ? (string) $key : get_debug_type($key),
                        $package->key(),
                    ));
                }
                if (! in_array($key, $featuredCapabilityKeys, true)) {
                    $featuredCapabilityKeys[] = $key;
                }
            }
        }

        $this->capabilities = $capabilities;
        $this->featuredCapabilityKeys = $featuredCapabilityKeys;
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

    /** @return list<string> */
    public function featuredCapabilityKeys(): array
    {
        return $this->featuredCapabilityKeys;
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
