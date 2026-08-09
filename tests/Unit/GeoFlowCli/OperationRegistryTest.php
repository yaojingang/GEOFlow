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
    public function every_api_v1_route_has_a_cli_operation(): void
    {
        $apiRoutes = collect(RouteFacade::getRoutes()->getRoutes())
            ->filter(fn (Route $route): bool => str_starts_with($route->uri(), 'api/v1/'))
            ->map(function (Route $route): string {
                $method = collect($route->methods())->first(fn (string $method): bool => $method !== 'HEAD');

                return $method.' '.substr($route->uri(), strlen('api/v1/'));
            })
            ->sort()
            ->values()
            ->all();

        $this->assertCount(28, $apiRoutes);
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
