<?php

declare(strict_types=1);

use App\Console\GeoFlowCli\OperationRegistry;
use App\Support\Api\ManagementOperationRegistry;
use Illuminate\Contracts\Console\Kernel;

require dirname(__DIR__).'/vendor/autoload.php';
$app = require dirname(__DIR__).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
$routes = [];
foreach ($app['router']->getRoutes() as $route) {
    if (str_starts_with((string) $route->getName(), 'admin.')) {
        $routes[] = ['methods' => array_values(array_diff($route->methods(), ['HEAD'])), 'uri' => $route->uri(), 'name' => $route->getName(), 'action' => $route->getActionName(), 'remote_coverage' => 'pending_domain_mapping'];
    }
}
usort($routes, static fn (array $a, array $b): int => strcmp($a['uri'].implode(',', $a['methods']), $b['uri'].implode(',', $b['methods'])));
$legacy = OperationRegistry::all();
$management = ManagementOperationRegistry::all();
ksort($legacy);
ksort($management);
$coverage = [
    'schema_version' => 1,
    'support_stage' => 'remote-management-preview',
    'admin_route_count' => count($routes),
    'legacy_cli_operations' => array_values($legacy),
    'management_operations' => array_values($management),
    'admin_routes' => $routes,
    'pending_batches' => [
        'A' => ['complete_domain_coverage_mapping', 'complete_operation_schemas', 'public_signed_distribution', 'cli_skill_installer'],
        'B' => ['managed_revision_source', 'remote_configuration', 'large_file_staging', 'checks', 'publish_plan_and_apply', 'field_rollback', 'upgrade_interlock', 'consistent_backup_restore', 'scheduled_retention', 'installed_theme_two_round_acceptance'],
        'C' => ['full_backend_adapters'], 'D' => ['hosted_and_agent_frontends'], 'E' => ['cross_platform', 'multi_replica', 'restore_acceptance', 'stable_release'],
    ],
];
$openapi = ['openapi' => '3.1.0', 'info' => ['title' => 'GEOFlow remote management preview', 'version' => ManagementOperationRegistry::PROTOCOL_VERSION], 'servers' => [['url' => '/api/v1']], 'paths' => [], 'components' => ['securitySchemes' => ['bearerAuth' => ['type' => 'http', 'scheme' => 'bearer']]], 'x-schema-completeness' => 'partial: nested business schemas remain pending'];
foreach ($management as $name => $operation) {
    $entry = ['operationId' => $name, 'security' => [['bearerAuth' => []]], 'x-required-scope' => $operation['scope'], 'x-idempotent' => $operation['idempotent'], 'x-receipt' => $operation['receipt'] ?? false, 'responses' => ['200' => ['description' => 'Successful API envelope'], '401' => ['description' => 'Authentication required'], '403' => ['description' => 'Permission denied'], '409' => ['description' => 'Baseline or request identity conflict']]];
    preg_match_all('/\{([^}]+)\}/', $operation['path'], $matches);
    foreach ($matches[1] as $parameter) {
        $entry['parameters'][] = ['name' => $parameter, 'in' => 'path', 'required' => true, 'schema' => ['type' => 'string']];
    }
    foreach ($operation['input_schema']['properties']['query']['required'] ?? [] as $parameter) {
        $entry['parameters'][] = ['name' => $parameter, 'in' => 'query', 'required' => true, 'schema' => ['type' => 'string']];
    }
    if (isset($operation['input_schema']['properties']['body'])) {
        $entry['requestBody'] = ['required' => true, 'content' => ['application/json' => ['schema' => $operation['input_schema']['properties']['body']]]];
    }
    if ($operation['method'] === 'POST') {
        $entry['responses']['201'] = ['description' => 'Resource or receipt created'];
    }
    $openapi['paths']['/'.$operation['path']][strtolower($operation['method'])] = $entry;
}
$files = ['docs/api/management-coverage.json' => $coverage, 'docs/api/management-openapi.json' => $openapi];
$checking = in_array('--check', $argv, true);
foreach ($files as $relative => $value) {
    $contents = json_encode($value, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)."\n";
    $path = dirname(__DIR__).'/'.$relative;
    if ($checking) {
        if (! is_file($path) || file_get_contents($path) !== $contents) {
            fwrite(STDERR, "Management contract drift: {$relative}; regenerate the coverage artifacts.\n");
            exit(1);
        }
    } else {
        if (! is_dir(dirname($path))) {
            mkdir(dirname($path), 0755, true);
        }
        file_put_contents($path, $contents);
    }
}
fwrite(STDOUT, json_encode(['admin_routes' => count($routes), 'legacy_operations' => count($legacy), 'management_operations' => count($management), 'mode' => $checking ? 'checked' : 'generated'], JSON_THROW_ON_ERROR)."\n");
