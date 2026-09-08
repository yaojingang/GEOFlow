<?php

namespace App\Services\Deployment;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;

class UpgradePlan
{
    public const KINDS = ['migrate', 'retrieval_backfill', 'managed_images', 'security_audit', 'system_knowledge', 'cache_warmup'];

    public const PROTECTED_MIGRATIONS = [
        '2026_07_17_000400_security_upgrade_preflight',
        '2026_07_17_000403_add_managed_path_hash_to_images_table',
    ];

    /** @return array{plan:array<string,mixed>,sha256:string,version:string} */
    public function load(string $path, string $expectedHash = ''): array
    {
        if (! is_file($path) || ! is_readable($path) || filesize($path) > 1048576) {
            throw new RuntimeException('upgrade_plan_unreadable');
        }
        $bytes = file_get_contents($path);
        $hash = hash('sha256', $bytes);
        if ($expectedHash !== '' && (! preg_match('/\A[a-f0-9]{64}\z/', $expectedHash) || ! hash_equals($expectedHash, $hash))) {
            throw new RuntimeException('upgrade_plan_hash_mismatch');
        }
        $plan = json_decode($bytes, true, 32, JSON_THROW_ON_ERROR);
        $this->validate($plan);
        $this->assertMigrationManifest($plan);
        $version = json_decode((string) file_get_contents(base_path('version.json')), true, 32, JSON_THROW_ON_ERROR);
        if (! is_string($version['version'] ?? null) || ! preg_match('/\A[0-9]+\.[0-9]+\.[0-9]+(?:[-+][0-9A-Za-z.-]+)?\z/', $version['version'])) {
            throw new RuntimeException('upgrade_version_invalid');
        }

        return ['plan' => $plan, 'sha256' => $hash, 'version' => $version['version']];
    }

    /** @return array<string,string> */
    public function migrationFiles(): array
    {
        $files = [];
        foreach (glob(database_path('migrations/*.php')) ?: [] as $path) {
            if (is_link($path) || ! is_file($path)) {
                throw new RuntimeException('upgrade_migration_file_invalid');
            }
            $files[basename($path, '.php')] = $path;
        }
        ksort($files);

        return $files;
    }

    /** @param array<string,mixed> $plan */
    public function assertMigrationManifest(array $plan): void
    {
        $files = $this->migrationFiles();
        $listed = array_column($plan['migrations'], null, 'name');
        if (array_diff_key($files, $listed) !== [] || array_diff_key($listed, $files) !== []) {
            throw new RuntimeException('upgrade_migration_manifest_coverage_mismatch');
        }
        foreach ($files as $name => $path) {
            if (! hash_equals($listed[$name]['sha256'], hash_file('sha256', $path))) {
                throw new RuntimeException('upgrade_migration_hash_mismatch:'.$name);
            }
        }
    }

    /**
     * @param  array<string,mixed>  $plan
     * @return list<array{name:string,sha256:string,online:bool}>
     */
    public function pending(array $plan): array
    {
        $table = config('database.migrations.table', 'migrations');
        if (! Schema::hasTable($table)) {
            throw new RuntimeException('upgrade_existing_migration_repository_required');
        }
        $ran = DB::table($table)->pluck('migration')->all();
        $known = array_column($plan['migrations'], 'name');
        if (array_diff($ran, $known) !== []) {
            throw new RuntimeException('upgrade_database_has_unknown_migrations');
        }

        return array_values(array_filter($plan['migrations'], static fn (array $migration): bool => ! in_array($migration['name'], $ran, true)));
    }

    private function validate(mixed $plan): void
    {
        if (! is_array($plan)) {
            throw new RuntimeException('upgrade_plan_invalid');
        }
        $this->assertKeys($plan, ['schema_version', 'strategy', 'allowed_sources', 'migrations', 'compatibility', 'steps']);
        if ($plan['schema_version'] !== 1 || ! in_array($plan['strategy'], ['online', 'maintenance'], true)) {
            throw new RuntimeException('upgrade_plan_schema_invalid');
        }
        if (! is_array($plan['allowed_sources']) || ! array_is_list($plan['allowed_sources'])
            || ($plan['strategy'] === 'online' && $plan['allowed_sources'] === [])) {
            throw new RuntimeException('upgrade_plan_sources_invalid');
        }
        foreach ($plan['allowed_sources'] as $source) {
            if (! is_int($source) || $source < 1) {
                throw new RuntimeException('upgrade_plan_source_invalid');
            }
        }
        if (! is_array($plan['compatibility'])) {
            throw new RuntimeException('upgrade_plan_compatibility_invalid');
        }
        $this->assertKeys($plan['compatibility'], ['schema', 'queue', 'cache', 'storage']);
        foreach ($plan['compatibility'] as $compatible) {
            if (! is_bool($compatible)) {
                throw new RuntimeException('upgrade_plan_compatibility_invalid');
            }
        }
        if (! is_array($plan['migrations']) || ! array_is_list($plan['migrations']) || ! is_array($plan['steps']) || ! array_is_list($plan['steps'])) {
            throw new RuntimeException('upgrade_plan_lists_invalid');
        }
        $names = [];
        foreach ($plan['migrations'] as $migration) {
            if (! is_array($migration)) {
                throw new RuntimeException('upgrade_plan_migration_invalid');
            }
            $this->assertKeys($migration, ['name', 'sha256', 'online']);
            if (! is_string($migration['name']) || ! preg_match('/\A[0-9]{4}_[0-9]{2}_[0-9]{2}_[0-9]{6}_[A-Za-z0-9_]+\z/', $migration['name'])
                || isset($names[$migration['name']]) || ! is_string($migration['sha256'])
                || ! preg_match('/\A[a-f0-9]{64}\z/', $migration['sha256']) || ! is_bool($migration['online'])) {
                throw new RuntimeException('upgrade_plan_migration_invalid');
            }
            $names[$migration['name']] = true;
        }
        $ids = [];
        $kinds = [];
        foreach ($plan['steps'] as $position => $step) {
            if (! is_array($step)) {
                throw new RuntimeException('upgrade_plan_step_invalid');
            }
            $this->assertKeys($step, ['id', 'kind', 'phase', 'timeout_seconds', 'online']);
            if (! is_string($step['id']) || ! preg_match('/\A[a-z][a-z0-9_-]{0,63}\z/', $step['id'])
                || isset($ids[$step['id']]) || ! in_array($step['kind'], self::KINDS, true)
                || isset($kinds[$step['kind']]) || $step['phase'] !== 'apply' || ! is_int($step['timeout_seconds'])
                || $step['timeout_seconds'] < 1 || $step['timeout_seconds'] > 7200 || ! is_bool($step['online'])
                || ($step['kind'] === 'migrate' && $position !== 0)) {
                throw new RuntimeException('upgrade_plan_step_invalid');
            }
            $ids[$step['id']] = true;
            $kinds[$step['kind']] = true;
        }
        if (! isset($kinds['migrate'])) {
            throw new RuntimeException('upgrade_plan_migrate_step_required');
        }
    }

    /**
     * @param  array<string,mixed>  $value
     * @param  list<string>  $keys
     */
    private function assertKeys(array $value, array $keys): void
    {
        if (array_diff(array_keys($value), $keys) !== [] || array_diff($keys, array_keys($value)) !== []) {
            throw new RuntimeException('upgrade_plan_fields_invalid');
        }
    }
}
