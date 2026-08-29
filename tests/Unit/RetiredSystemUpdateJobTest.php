<?php

namespace Tests\Unit;

use App\Jobs\ProcessSystemUpdateApplyJob;
use App\Jobs\ProcessSystemUpdateRollbackJob;
use App\Models\SystemUpdateRun;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RetiredSystemUpdateJobTest extends TestCase
{
    use RefreshDatabase;

    public function test_serialized_legacy_jobs_only_retire_their_database_run(): void
    {
        foreach ([ProcessSystemUpdateApplyJob::class, ProcessSystemUpdateRollbackJob::class] as $index => $jobClass) {
            $run = SystemUpdateRun::query()->create([
                'run_uuid' => 'retired-job-'.$index,
                'action' => $index === 0 ? 'apply' : 'rollback',
                'status' => 'queued',
            ]);

            $serialized = serialize(new $jobClass((int) $run->id));
            $restored = unserialize($serialized, ['allowed_classes' => [$jobClass]]);
            $this->assertInstanceOf($jobClass, $restored);
            $restored->handle();

            $run->refresh();
            $this->assertSame('failed', $run->status);
            $this->assertSame('legacy_executor_retired', $run->error_message);
            $this->assertNotNull($run->finished_at);
        }
    }
}
