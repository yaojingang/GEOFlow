<?php

namespace Tests\Unit\AiWorkspace;

use App\Models\Admin;
use App\Services\AiWorkspace\AdminHelpFeatureRegistry;
use App\Services\AiWorkspace\AdminHelpRouteCoverage;
use Tests\TestCase;

final class AdminHelpRouteCoverageTest extends TestCase
{
    public function test_all_admin_routes_receive_one_supported_classification(): void
    {
        $coverage = app(AdminHelpRouteCoverage::class)->all();

        self::assertGreaterThanOrEqual(290, count($coverage));
        self::assertSame(count($coverage), collect($coverage)->pluck('route')->unique()->count());
        foreach ($coverage as $row) {
            self::assertContains($row['category'], ['entry', 'action', 'endpoint', 'dynamic', 'restricted']);
            self::assertNotSame('', $row['route']);
        }
    }

    public function test_every_official_route_directive_is_registered_as_a_stable_get_entry(): void
    {
        $registry = app(AdminHelpFeatureRegistry::class);
        $content = file_get_contents(resource_path('knowledge/ai-workspace/geoflow-admin-guide.zh_CN.md'));
        self::assertIsString($content);
        preg_match_all('/\[\[route:([^|\]]+)\|[^\]]+\]\]/u', $content, $matches);

        foreach (array_unique($matches[1] ?? []) as $routeName) {
            $entry = $registry->featureForRoute((string) $routeName);
            $route = app('router')->getRoutes()->getByName((string) $routeName);
            self::assertIsArray($entry, 'Unregistered route directive: '.$routeName);
            self::assertNotNull($route, 'Missing route directive: '.$routeName);
            self::assertContains('GET', $route->methods());
            self::assertSame([], $route->parameterNames());
        }
    }

    public function test_registry_uses_real_route_permissions_and_correct_entry_ownership(): void
    {
        $registry = app(AdminHelpFeatureRegistry::class);
        $regularAdmin = new Admin(['role' => 'admin', 'status' => 'active']);
        $superAdmin = new Admin(['role' => 'super_admin', 'status' => 'active']);

        self::assertSame('ai-workspace', $registry->featureForRoute('admin.ai-workspace')['id'] ?? null);
        self::assertSame('account', $registry->featureForRoute('admin.account.show')['id'] ?? null);
        self::assertTrue($registry->canAccessRoute($regularAdmin, 'admin.ai-workspace'));
        self::assertTrue($registry->canAccessRoute($regularAdmin, 'admin.account.show'));
        self::assertFalse($registry->canAccessRoute($regularAdmin, 'admin.url-import'));
        self::assertFalse($registry->canAccessRoute($regularAdmin, 'admin.system-updates.index'));
        self::assertTrue($registry->canAccessRoute($superAdmin, 'admin.url-import'));
    }
}
