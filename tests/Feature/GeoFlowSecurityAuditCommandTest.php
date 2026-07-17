<?php

namespace Tests\Feature;

use App\Contracts\Outbound\HostResolver;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Tests\TestCase;

class GeoFlowSecurityAuditCommandTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-07-17 12:00:00');
        config()->set('geoflow.legacy_image_path_input', false);
        config()->set('geoflow.managed_image_deletion_enabled', false);
        config()->set('geoflow.outbound_private_targets', []);
        config()->set('geoflow.security_audit_deleting_stale_minutes', 15);
        config()->set('geoflow.security_audit_orphan_age_hours', 24);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_json_audit_is_clean_and_uses_a_stable_schema(): void
    {
        [$exitCode, $output] = $this->runAudit(true);

        $this->assertSame(0, $exitCode);
        $this->assertSame([
            'schema_version' => 1,
            'status' => 'ok',
            'summary' => [
                'critical' => 0,
                'high' => 0,
                'medium' => 0,
                'low' => 0,
                'total' => 0,
            ],
            'findings' => [],
        ], json_decode($output, true, flags: JSON_THROW_ON_ERROR));
        $this->assertStringNotContainsString('2026-07-17', $output);
    }

    public function test_human_audit_reports_the_same_clean_result(): void
    {
        [$exitCode, $output] = $this->runAudit(false);

        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString('Security audit: OK', $output);
        $this->assertStringContainsString('Critical', $output);
        $this->assertStringContainsString('Total', $output);
    }

    public function test_json_audit_reports_integrity_configuration_and_expired_reservation_findings(): void
    {
        $this->seedUnsafeAuditFixtures();
        config()->set('geoflow.legacy_image_path_input', true);
        config()->set('geoflow.managed_image_deletion_enabled', true);
        config()->set('geoflow.outbound_private_targets', [
            'internal-secret.example:443',
            '127.0.0.1:8443',
        ]);

        [$exitCode, $firstOutput] = $this->runAudit(true);
        [$secondExitCode, $secondOutput] = $this->runAudit(true);

        $this->assertSame(1, $exitCode);
        $this->assertSame(1, $secondExitCode);
        $this->assertSame($firstOutput, $secondOutput);

        $report = json_decode($firstOutput, true, flags: JSON_THROW_ON_ERROR);
        $codes = array_column($report['findings'], 'code');
        $sortedCodes = $codes;
        sort($sortedCodes);
        $this->assertSame($sortedCodes, $codes);
        $this->assertSame($codes, array_values(array_unique($codes)));
        $this->assertSame('findings', $report['status']);
        $this->assertSame(count($codes), $report['summary']['total']);

        foreach ([
            'IDEMPOTENCY_EXPIRED_RESERVATION',
            'IDEMPOTENCY_INVALID_FINGERPRINT',
            'IDEMPOTENCY_INVALID_RESERVATION',
            'IDEMPOTENCY_INVALID_STATE',
            'IMAGE_MANAGED_HASH_MISSING',
            'IMAGE_REGISTRY_MISSING',
            'IMAGE_REGISTRY_UNSAFE_STATE',
            'LEGACY_IMAGE_PATH_INPUT_ENABLED',
            'MANAGED_IMAGE_DELETION_NOT_READY',
            'MANAGED_REGISTRY_CONTENT_HASH_MISSING',
            'MANAGED_REGISTRY_INVALID_STATE',
            'MANAGED_REGISTRY_ORPHAN',
            'MANAGED_REGISTRY_STALE_DELETING',
            'OUTBOUND_PRIVATE_TARGETS_CONFIGURED',
        ] as $expectedCode) {
            $this->assertContains($expectedCode, $codes);
        }

        $privateTargets = collect($report['findings'])
            ->firstWhere('code', 'OUTBOUND_PRIVATE_TARGETS_CONFIGURED');
        $this->assertSame(2, $privateTargets['count']);

        foreach ($report['findings'] as $finding) {
            $this->assertSame(
                ['code', 'severity', 'count', 'message', 'remediation'],
                array_keys($finding),
            );
        }
    }

    public function test_partial_schema_is_reported_without_querying_the_missing_table(): void
    {
        Schema::drop('managed_image_paths');
        config()->set('geoflow.managed_image_deletion_enabled', true);

        [$exitCode, $output] = $this->runAudit(true);

        $this->assertSame(1, $exitCode);
        $report = json_decode($output, true, flags: JSON_THROW_ON_ERROR);
        $this->assertContains(
            'SECURITY_SCHEMA_MANAGED_IMAGE_PATHS_INCOMPLETE',
            array_column($report['findings'], 'code'),
        );
        $this->assertContains(
            'MANAGED_IMAGE_DELETION_NOT_READY',
            array_column($report['findings'], 'code'),
        );
        $this->assertStringNotContainsString('no such table', strtolower($output));
    }

    public function test_missing_managed_registry_security_column_is_reported_before_data_queries(): void
    {
        Schema::table('managed_image_paths', static function (Blueprint $table): void {
            $table->dropColumn('file_path');
        });

        [$exitCode, $output] = $this->runAudit(true);

        $this->assertSame(1, $exitCode);
        $report = json_decode($output, true, flags: JSON_THROW_ON_ERROR);
        $this->assertContains(
            'SECURITY_SCHEMA_MANAGED_IMAGE_PATHS_INCOMPLETE',
            array_column($report['findings'], 'code'),
        );
        $this->assertStringNotContainsString('SQLSTATE', $output);
    }

    public function test_audit_performs_no_mutating_sql_http_dns_or_subprocess_work(): void
    {
        Http::preventStrayRequests();
        $this->app->instance(HostResolver::class, new class implements HostResolver
        {
            public function resolve(string $host): array
            {
                throw new RuntimeException('DNS must not be used by the security audit.');
            }
        });

        $queries = [];
        DB::listen(static function (QueryExecuted $query) use (&$queries): void {
            $queries[] = strtolower(ltrim($query->sql));
        });

        [$exitCode] = $this->runAudit(true);

        $this->assertSame(0, $exitCode);
        $this->assertNotEmpty($queries);
        foreach ($queries as $query) {
            $this->assertDoesNotMatchRegularExpression(
                '/^(insert|update|delete|replace|alter|create|drop|truncate|vacuum|attach|detach|reindex|migrate)\b/',
                $query,
            );
        }

        $source = (string) file_get_contents(app_path('Services/Security/SecurityAuditService.php'))
            .(string) file_get_contents(app_path('Console/Commands/GeoFlowSecurityAuditCommand.php'));
        foreach ([
            '/\b(Http|Storage|File|Cache|Process)::/',
            '/\b(curl_|dns_get_record|gethostbyname|gethostbynamel|file_put_contents|fopen|proc_open|shell_exec|exec|system|passthru)\s*\(/',
        ] as $forbiddenPattern) {
            $this->assertDoesNotMatchRegularExpression($forbiddenPattern, $source);
        }
    }

    public function test_audit_rejects_non_hex_fingerprints_and_owner_tokens_of_the_expected_length(): void
    {
        DB::table('api_idempotency_keys')->insert([
            [
                'idempotency_key' => 'non-hex-fingerprint',
                'route_key' => 'POST /audit',
                'request_hash' => str_repeat('z', 64),
                'response_body' => '{}',
                'response_status' => 200,
                'fingerprint_version' => 2,
                'state' => 'completed',
                'owner_token' => null,
                'lease_expires_at' => null,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'idempotency_key' => 'non-hex-owner',
                'route_key' => 'POST /audit',
                'request_hash' => str_repeat('1', 64),
                'response_body' => '{}',
                'response_status' => 0,
                'fingerprint_version' => 2,
                'state' => 'in_progress',
                'owner_token' => str_repeat('g', 64),
                'lease_expires_at' => now()->addHour(),
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);

        [$exitCode, $output] = $this->runAudit(true);

        $this->assertSame(1, $exitCode);
        $codes = array_column(
            json_decode($output, true, flags: JSON_THROW_ON_ERROR)['findings'],
            'code',
        );
        $this->assertContains('IDEMPOTENCY_INVALID_FINGERPRINT', $codes);
        $this->assertContains('IDEMPOTENCY_INVALID_RESERVATION', $codes);
    }

    public function test_audit_rejects_a_non_empty_invalid_registry_content_hash(): void
    {
        DB::table('managed_image_paths')->insert([
            'path_hash' => str_repeat('1', 64),
            'file_path' => 'storage/uploads/images/audit/invalid-content.png',
            'content_sha256' => str_repeat('z', 64),
            'state' => 'missing',
            'lock_version' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        [$exitCode, $output] = $this->runAudit(true);

        $this->assertSame(1, $exitCode);
        $this->assertContains(
            'MANAGED_REGISTRY_INVALID_CONTENT_HASH',
            array_column(
                json_decode($output, true, flags: JSON_THROW_ON_ERROR)['findings'],
                'code',
            ),
        );
    }

    public function test_invalid_hash_and_reservation_counts_cross_multiple_read_only_chunks(): void
    {
        $registryRows = [];
        $idempotencyRows = [];
        for ($index = 0; $index < 205; $index++) {
            $registryRows[] = [
                'path_hash' => hash('sha256', 'registry-path-'.$index),
                'file_path' => 'storage/uploads/images/audit/chunk-'.$index.'.png',
                'content_sha256' => str_repeat('z', 64),
                'state' => 'missing',
                'lock_version' => 0,
                'created_at' => now(),
                'updated_at' => now(),
            ];
            $idempotencyRows[] = [
                'idempotency_key' => 'chunk-key-'.$index,
                'route_key' => 'POST /audit/chunk',
                'request_hash' => str_repeat('z', 64),
                'response_body' => '{}',
                'response_status' => 0,
                'fingerprint_version' => 2,
                'state' => 'in_progress',
                'owner_token' => str_repeat('g', 64),
                'lease_expires_at' => now()->addHour(),
                'created_at' => now(),
                'updated_at' => now(),
            ];
        }
        foreach (array_chunk($registryRows, 40) as $chunk) {
            DB::table('managed_image_paths')->insert($chunk);
        }
        foreach (array_chunk($idempotencyRows, 40) as $chunk) {
            DB::table('api_idempotency_keys')->insert($chunk);
        }

        [$exitCode, $output] = $this->runAudit(true);

        $this->assertSame(1, $exitCode);
        $findings = collect(json_decode($output, true, flags: JSON_THROW_ON_ERROR)['findings']);
        $this->assertSame(205, $findings->firstWhere('code', 'MANAGED_REGISTRY_INVALID_CONTENT_HASH')['count']);
        $this->assertSame(205, $findings->firstWhere('code', 'IDEMPOTENCY_INVALID_FINGERPRINT')['count']);
        $this->assertSame(205, $findings->firstWhere('code', 'IDEMPOTENCY_INVALID_RESERVATION')['count']);

        $source = (string) file_get_contents(app_path('Services/Security/SecurityAuditService.php'));
        $this->assertStringContainsString('->lazyById(', $source);
        $this->assertStringNotContainsString('->cursor()', $source);
    }

    public function test_null_registry_and_idempotency_states_are_counted_as_unsafe(): void
    {
        Schema::drop('managed_image_paths');
        Schema::create('managed_image_paths', static function (Blueprint $table): void {
            $table->id();
            $table->char('path_hash', 64)->unique();
            $table->text('file_path');
            $table->char('content_sha256', 64)->nullable()->index();
            $table->string('state', 20)->nullable()->index();
            $table->unsignedBigInteger('lock_version')->default(0);
            $table->timestamps();
        });
        Schema::drop('api_idempotency_keys');
        Schema::create('api_idempotency_keys', static function (Blueprint $table): void {
            $table->id();
            $table->string('idempotency_key', 120);
            $table->string('route_key', 120);
            $table->string('request_hash', 64);
            $table->text('response_body');
            $table->integer('response_status');
            $table->unsignedTinyInteger('fingerprint_version')->default(1);
            $table->string('state', 20)->nullable()->index();
            $table->char('owner_token', 64)->nullable();
            $table->timestamp('lease_expires_at')->nullable()->index();
            $table->timestamps();
            $table->unique(['idempotency_key', 'route_key']);
        });

        $pathHash = hash('sha256', 'null-state-path');
        DB::table('managed_image_paths')->insert([
            'path_hash' => $pathHash,
            'file_path' => 'storage/uploads/images/audit/null-state.png',
            'content_sha256' => hash('sha256', 'null-state-content'),
            'state' => null,
            'lock_version' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $libraryId = DB::table('image_libraries')->insertGetId([
            'name' => 'Null state fixture',
            'description' => null,
            'image_count' => 1,
            'used_task_count' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('images')->insert([
            'library_id' => $libraryId,
            'filename' => 'null-state.png',
            'original_name' => 'null-state.png',
            'file_name' => 'null-state.png',
            'file_path' => 'storage/uploads/images/audit/null-state.png',
            'managed_path_hash' => $pathHash,
            'file_size' => 1,
            'mime_type' => 'image/png',
            'width' => 1,
            'height' => 1,
            'tags' => null,
            'used_count' => 0,
            'usage_count' => 0,
            'created_at' => now(),
        ]);
        DB::table('api_idempotency_keys')->insert([
            'idempotency_key' => 'null-state-idempotency',
            'route_key' => 'POST /audit/null-state',
            'request_hash' => hash('sha256', 'null-state-request'),
            'response_body' => '{}',
            'response_status' => 200,
            'fingerprint_version' => 2,
            'state' => null,
            'owner_token' => null,
            'lease_expires_at' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        [$exitCode, $output] = $this->runAudit(true);

        $this->assertSame(1, $exitCode);
        $codes = array_column(
            json_decode($output, true, flags: JSON_THROW_ON_ERROR)['findings'],
            'code',
        );
        $this->assertContains('IMAGE_REGISTRY_UNSAFE_STATE', $codes);
        $this->assertContains('MANAGED_REGISTRY_INVALID_STATE', $codes);
        $this->assertContains('IDEMPOTENCY_INVALID_STATE', $codes);
    }

    public function test_deleting_and_orphan_findings_use_separate_short_and_long_thresholds(): void
    {
        DB::table('managed_image_paths')->insert([
            [
                'path_hash' => str_repeat('1', 64),
                'file_path' => 'storage/uploads/images/audit/stale-deleting.png',
                'content_sha256' => str_repeat('a', 64),
                'state' => 'deleting',
                'lock_version' => 1,
                'created_at' => now()->subMinutes(30),
                'updated_at' => now()->subMinutes(30),
            ],
            [
                'path_hash' => str_repeat('2', 64),
                'file_path' => 'storage/uploads/images/audit/recent-orphan.png',
                'content_sha256' => str_repeat('b', 64),
                'state' => 'present',
                'lock_version' => 0,
                'created_at' => now()->subHours(2),
                'updated_at' => now()->subHours(2),
            ],
            [
                'path_hash' => str_repeat('3', 64),
                'file_path' => 'storage/uploads/images/audit/long-lived-orphan.png',
                'content_sha256' => str_repeat('c', 64),
                'state' => 'present',
                'lock_version' => 0,
                'created_at' => now()->subHours(25),
                'updated_at' => now()->subHours(25),
            ],
        ]);

        [$exitCode, $output] = $this->runAudit(true);

        $this->assertSame(1, $exitCode);
        $findings = collect(json_decode($output, true, flags: JSON_THROW_ON_ERROR)['findings']);
        $this->assertSame(1, $findings->firstWhere('code', 'MANAGED_REGISTRY_STALE_DELETING')['count']);
        $this->assertSame(1, $findings->firstWhere('code', 'MANAGED_REGISTRY_ORPHAN')['count']);
    }

    public function test_output_never_discloses_paths_hosts_tokens_or_hash_values(): void
    {
        $this->seedUnsafeAuditFixtures();
        config()->set('geoflow.outbound_private_targets', ['private-token-host.example:9443']);

        [, $jsonOutput] = $this->runAudit(true);
        [, $humanOutput] = $this->runAudit(false);
        $combinedOutput = $jsonOutput.$humanOutput;

        foreach ([
            'storage/uploads/images/secret-customer/secret-missing-registry.png',
            'private-token-host.example',
            'expired-secret-key',
            'POST /secret',
            'plain-text-secret-token',
            str_repeat('a', 64),
            str_repeat('b', 64),
            str_repeat('c', 64),
            str_repeat('1', 64),
            str_repeat('2', 64),
        ] as $secret) {
            $this->assertStringNotContainsString($secret, $combinedOutput);
        }
    }

    public function test_database_failure_becomes_a_stable_redacted_finding(): void
    {
        config()->set('database.connections.security_audit_broken', [
            'driver' => 'sqlite',
            'database' => '/missing/security-audit/database.sqlite',
            'prefix' => '',
        ]);
        config()->set('database.default', 'security_audit_broken');

        [$exitCode, $output] = $this->runAudit(true);

        config()->set('database.default', 'sqlite');
        DB::purge('security_audit_broken');

        $this->assertSame(1, $exitCode);
        $report = json_decode($output, true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame(['SECURITY_AUDIT_INCOMPLETE'], array_column($report['findings'], 'code'));
        $this->assertStringNotContainsString('/missing/security-audit', $output);
        $this->assertStringNotContainsString('sqlite', strtolower($output));
    }

    /**
     * @return array{int,string}
     */
    private function runAudit(bool $json): array
    {
        $exitCode = Artisan::call('geoflow:security-audit', $json ? ['--json' => true] : []);

        return [$exitCode, Artisan::output()];
    }

    private function seedUnsafeAuditFixtures(): void
    {
        $libraryId = DB::table('image_libraries')->insertGetId([
            'name' => 'Audit fixture',
            'description' => null,
            'image_count' => 0,
            'used_task_count' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $missingRegistryHash = str_repeat('a', 64);
        $unsafeRegistryHash = str_repeat('b', 64);
        $nullHashFilename = 'secret-null-hash.png';
        foreach ([
            [$missingRegistryHash, 'secret-missing-registry.png'],
            [$unsafeRegistryHash, 'secret-unsafe-registry.png'],
            ['', $nullHashFilename],
        ] as [$managedPathHash, $filename]) {
            DB::table('images')->insert([
                'library_id' => $libraryId,
                'filename' => $filename,
                'original_name' => $filename,
                'file_name' => $filename,
                'file_path' => 'storage/uploads/images/secret-customer/'.$filename,
                'managed_path_hash' => $managedPathHash,
                'file_size' => 1,
                'mime_type' => 'image/png',
                'width' => 1,
                'height' => 1,
                'tags' => null,
                'used_count' => 0,
                'usage_count' => 0,
                'created_at' => now(),
            ]);
        }

        DB::table('managed_image_paths')->insert([
            [
                'path_hash' => $unsafeRegistryHash,
                'file_path' => 'storage/uploads/images/secret-customer/secret-unsafe-registry.png',
                'content_sha256' => str_repeat('c', 64),
                'state' => 'deleting',
                'lock_version' => 4,
                'created_at' => now()->subHours(2),
                'updated_at' => now()->subHours(2),
            ],
            [
                'path_hash' => str_repeat('d', 64),
                'file_path' => 'storage/uploads/images/secret-customer/orphan.png',
                'content_sha256' => str_repeat('e', 64),
                'state' => 'present',
                'lock_version' => 0,
                'created_at' => now()->subHours(48),
                'updated_at' => now()->subHours(48),
            ],
            [
                'path_hash' => str_repeat('f', 64),
                'file_path' => 'storage/uploads/images/secret-customer/invalid.png',
                'content_sha256' => null,
                'state' => 'corrupted',
                'lock_version' => 0,
                'created_at' => now()->subHours(2),
                'updated_at' => now()->subHours(2),
            ],
        ]);

        DB::table('api_idempotency_keys')->insert([
            [
                'idempotency_key' => 'expired-secret-key',
                'route_key' => 'POST /secret',
                'request_hash' => str_repeat('1', 64),
                'response_body' => '{}',
                'response_status' => 0,
                'fingerprint_version' => 2,
                'state' => 'in_progress',
                'owner_token' => str_repeat('2', 64),
                'lease_expires_at' => now()->subMinute(),
                'created_at' => now()->subMinutes(10),
                'updated_at' => now()->subMinutes(10),
            ],
            [
                'idempotency_key' => 'invalid-combination',
                'route_key' => 'POST /secret',
                'request_hash' => 'not-a-fingerprint',
                'response_body' => '{}',
                'response_status' => 201,
                'fingerprint_version' => 9,
                'state' => 'unexpected',
                'owner_token' => 'plain-text-secret-token',
                'lease_expires_at' => now()->addHour(),
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'idempotency_key' => 'invalid-owner',
                'route_key' => 'POST /secret',
                'request_hash' => str_repeat('3', 64),
                'response_body' => '{}',
                'response_status' => 0,
                'fingerprint_version' => 2,
                'state' => 'in_progress',
                'owner_token' => null,
                'lease_expires_at' => null,
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);
    }
}
