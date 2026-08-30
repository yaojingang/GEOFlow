<?php

namespace Tests\Unit\GeoFlowCli;

use App\Console\GeoFlowCli\CommandSpec;
use App\Console\GeoFlowCli\OperationRegistry;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\Route as RouteFacade;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class OperationRegistryTest extends TestCase
{
    #[Test]
    public function every_cli_api_route_has_an_operation_and_browser_routes_remain_separate(): void
    {
        $v1Routes = collect(RouteFacade::getRoutes()->getRoutes())
            ->filter(fn (Route $route): bool => str_starts_with($route->uri(), 'api/v1/'))
            ->values();
        $browserRoutes = $v1Routes->filter(fn (Route $route): bool => str_starts_with($route->uri(), 'api/v1/browser-operations')
            || str_starts_with($route->uri(), 'api/v1/manual-publications'));
        $apiRoutes = $v1Routes
            ->reject(fn (Route $route): bool => str_starts_with($route->uri(), 'api/v1/browser-operations')
                || str_starts_with($route->uri(), 'api/v1/manual-publications'))
            // The CLI reads the latest candidate and keeps rollback in the authenticated API and Admin surfaces.
            ->reject(fn (Route $route): bool => in_array($route->getName(), [
                'api.v1.articles.ai-quality.optimization.candidate',
                'api.v1.articles.ai-quality.optimization.rollback',
            ], true))
            ->map(function (Route $route): string {
                $method = collect($route->methods())->first(fn (string $method): bool => $method !== 'HEAD');

                return $method.' '.substr($route->uri(), strlen('api/v1/'));
            })
            ->sort()
            ->values()
            ->all();

        $this->assertCount(10, $browserRoutes);
        $this->assertCount(35, $apiRoutes);
        $this->assertSame($apiRoutes, OperationRegistry::routeSignatures());
    }

    #[Test]
    public function image_upload_reuses_the_item_create_route(): void
    {
        $create = OperationRegistry::get('material.item-create');
        $upload = OperationRegistry::get('material.item-upload');

        $this->assertSame($create['method'], $upload['method']);
        $this->assertSame($create['path'], $upload['path']);
    }

    #[Test]
    public function delete_operations_never_support_idempotency_keys(): void
    {
        foreach (OperationRegistry::all() as $operation) {
            if ($operation['method'] === 'DELETE') {
                $this->assertFalse($operation['idempotent'], $operation['name']);
            }
        }
    }

    #[Test]
    public function command_specs_and_api_operations_are_bidirectionally_reachable(): void
    {
        $registryOperations = array_keys(OperationRegistry::all());
        sort($registryOperations);
        $specOperations = CommandSpec::apiOperations();

        $this->assertSame($registryOperations, $specOperations);
    }
}
