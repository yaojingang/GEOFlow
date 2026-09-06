<?php

namespace Tests\PostgreSQL;

use App\Jobs\GenerateEnterpriseKnowledgeDraftJob;
use App\Jobs\ProcessUrlImportJob;
use App\Models\EnterpriseKnowledgeProject;
use App\Models\UrlImportJob;
use App\Services\GeoFlow\EnterpriseKnowledgeDraftRecoveryService;
use App\Services\GeoFlow\UrlImportRecoveryService;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;

class ExecutionLeaseRecoveryQueryTest extends PostgreSqlTestCase
{
    use DatabaseMigrations;

    #[DataProvider('recoveryServices')]
    public function test_recovery_queries_handle_native_uuid_leases(
        string $modelClass,
        string $serviceClass,
        string $jobClass,
        string $runningStatus,
        array $attributes,
    ): void {
        Queue::fake();

        $missing = $modelClass::query()->create(array_merge($attributes, [
            'status' => $runningStatus,
            'execution_lease_token' => null,
            'lease_expires_at' => null,
        ]));
        $expired = $modelClass::query()->create(array_merge($attributes, [
            'status' => $runningStatus,
            'execution_lease_token' => (string) Str::uuid(),
            'lease_expires_at' => now()->subMinute(),
        ]));
        $activeToken = (string) Str::uuid();
        $active = $modelClass::query()->create(array_merge($attributes, [
            'status' => $runningStatus,
            'execution_lease_token' => $activeToken,
            'lease_expires_at' => now()->addHour(),
        ]));
        $completed = $modelClass::query()->create(array_merge($attributes, [
            'status' => 'completed',
            'execution_lease_token' => null,
            'lease_expires_at' => null,
        ]));

        $service = app($serviceClass);
        $this->assertSame(['recovered' => 2, 'dispatch_failed' => 0], $service->reconcile());
        Queue::assertPushed($jobClass, 2);
        foreach ([$missing, $expired] as $recovered) {
            $recovered->refresh();
            $this->assertSame('queued', $recovered->status);
            $this->assertNull($recovered->execution_lease_token);
            $this->assertNull($recovered->lease_expires_at);
        }
        $this->assertSame($runningStatus, $active->refresh()->status);
        $this->assertSame($activeToken, $active->execution_lease_token);
        $this->assertSame('completed', $completed->refresh()->status);
        $this->assertSame(['recovered' => 0, 'dispatch_failed' => 0], $service->reconcile());
        Queue::assertPushed($jobClass, 2);
    }

    public static function recoveryServices(): array
    {
        return [
            'enterprise knowledge' => [
                EnterpriseKnowledgeProject::class,
                EnterpriseKnowledgeDraftRecoveryService::class,
                GenerateEnterpriseKnowledgeDraftJob::class,
                'processing',
                ['name' => 'PostgreSQL recovery project'],
            ],
            'URL import' => [
                UrlImportJob::class,
                UrlImportRecoveryService::class,
                ProcessUrlImportJob::class,
                'running',
                ['url' => 'https://example.test/recovery', 'normalized_url' => 'https://example.test/recovery'],
            ],
        ];
    }
}
