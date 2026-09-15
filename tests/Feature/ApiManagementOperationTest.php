<?php

namespace Tests\Feature;

use App\Jobs\ProcessGeoFlowTaskJob;
use App\Models\Admin;
use App\Models\ManagementOperation;
use App\Models\Task;
use App\Models\TaskRun;
use App\Models\Title;
use App\Models\TitleLibrary;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ApiManagementOperationTest extends TestCase
{
    use RefreshDatabase;

    private function setupTask(): array
    {
        Queue::fake();
        $admin = Admin::query()->create(['username' => 'receipt-owner', 'password' => 'test-only', 'role' => 'admin', 'status' => 'active']);
        $library = TitleLibrary::query()->create(['name' => 'Receipt titles', 'title_count' => 1]);
        Title::query()->create(['library_id' => $library->id, 'title' => 'Queue once', 'used_count' => 0, 'usage_count' => 0]);
        $task = Task::query()->create(['name' => 'Receipt task', 'title_library_id' => $library->id, 'status' => 'active', 'schedule_enabled' => 1, 'article_limit' => 1, 'created_count' => 0, 'need_review' => true]);

        return [$admin, $task, $admin->createToken('first', ['tasks:write'])->plainTextToken];
    }

    public function test_response_loss_and_relogin_recover_the_same_real_queue_run(): void
    {
        [$admin, $task, $token] = $this->setupTask();
        $first = $this->withToken($token)->withHeader('X-Client-Request-Id', 'receipt-response-loss')
            ->postJson('/api/v1/tasks/'.$task->id.'/enqueue')->assertCreated()
            ->assertJsonPath('data.state', 'queued')->assertJsonPath('data.replayed', false);
        $operation = $first->json('data.operation_id');
        $admin->tokens()->delete();
        $replacement = $admin->createToken('replacement', ['tasks:write'])->plainTextToken;
        $this->withToken($replacement)->postJson('/api/v1/tasks/'.$task->id.'/enqueue')->assertOk()
            ->assertJsonPath('data.operation_id', $operation)->assertJsonPath('data.replayed', true);
        $this->assertSame(1, TaskRun::query()->count());
        $this->assertSame(1, ManagementOperation::query()->count());
        Queue::assertPushed(ProcessGeoFlowTaskJob::class, 1);
        $this->withToken($replacement)->getJson('/api/v1/management/operations/lookup?client_request_id=receipt-response-loss')
            ->assertOk()->assertJsonPath('data.operation_id', $operation);
        TaskRun::query()->update(['status' => 'completed']);
        $this->withToken($replacement)->getJson('/api/v1/management/operations/'.$operation)->assertOk()->assertJsonPath('data.state', 'succeeded');
    }

    public function test_request_id_cannot_be_reused_for_another_task(): void
    {
        [$admin, $task, $token] = $this->setupTask();
        $this->withToken($token)->withHeader('X-Client-Request-Id', 'receipt-conflict')
            ->postJson('/api/v1/tasks/'.$task->id.'/enqueue')->assertCreated();
        $other = $task->replicate();
        $other->save();
        $this->withToken($token)->postJson('/api/v1/tasks/'.$other->id.'/enqueue')->assertConflict()
            ->assertJsonPath('error.code', 'client_request_conflict');
        $this->assertSame(1, TaskRun::query()->count());
    }

    public function test_receipt_lookup_rechecks_scopes_and_owner(): void
    {
        [$admin, $task, $token] = $this->setupTask();
        $id = $this->withToken($token)->withHeader('X-Client-Request-Id', 'receipt-permissions')
            ->postJson('/api/v1/tasks/'.$task->id.'/enqueue')->assertCreated()->json('data.operation_id');
        $this->withToken($admin->createToken('narrow', ['tasks:read'])->plainTextToken)
            ->getJson('/api/v1/management/operations/'.$id)->assertForbidden();
        $other = Admin::query()->create(['username' => 'other-reader', 'password' => 'test-only', 'role' => 'super_admin', 'status' => 'active']);
        $this->withToken($other->createToken('other', ['tasks:write'])->plainTextToken)
            ->getJson('/api/v1/management/operations/'.$id)->assertNotFound();
    }

    public function test_failed_enqueue_rolls_back_receipt_and_queue_intent_together(): void
    {
        [$admin, $task, $token] = $this->setupTask();
        $task->update(['status' => 'paused']);
        $this->withToken($token)->withHeader('X-Client-Request-Id', 'receipt-failed-queue')
            ->postJson('/api/v1/tasks/'.$task->id.'/enqueue')->assertConflict();
        $this->assertSame(0, ManagementOperation::query()->count());
        $this->assertSame(0, TaskRun::query()->count());
        Queue::assertNothingPushed();
    }

    public function test_invalid_request_id_rejects_before_enqueuing(): void
    {
        [$admin, $task, $token] = $this->setupTask();
        $this->withToken($token)->withHeader('X-Client-Request-Id', '../bad')
            ->postJson('/api/v1/tasks/'.$task->id.'/enqueue')->assertUnprocessable();
        $this->assertSame(0, TaskRun::query()->count());
    }

    #[DataProvider('mixedIdempotencyHeaders')]
    public function test_mixed_idempotency_protocols_are_rejected_before_receipts_runs_or_jobs_are_created(string $clientRequestId, string $legacyKey): void
    {
        [$admin, $task, $token] = $this->setupTask();
        $this->withToken($token)->withHeaders(['X-Client-Request-Id' => $clientRequestId, 'X-Idempotency-Key' => $legacyKey])
            ->postJson('/api/v1/tasks/'.$task->id.'/enqueue')->assertUnprocessable()
            ->assertJsonPath('error.code', 'conflicting_idempotency_headers');
        $this->assertSame(0, ManagementOperation::query()->count());
        $this->assertSame(0, TaskRun::query()->count());
        Queue::assertNothingPushed();
    }

    public static function mixedIdempotencyHeaders(): array
    {
        return [
            'two populated headers' => ['receipt-mixed-protocol', 'legacy-key'],
            'empty legacy header' => ['receipt-mixed-protocol', ''],
            'empty client header' => ['', 'legacy-key'],
        ];
    }

    public function test_receipt_lookup_rechecks_current_task_publication_scope(): void
    {
        [$admin, $task, $token] = $this->setupTask();
        $id = $this->withToken($token)->withHeader('X-Client-Request-Id', 'receipt-publication-change')
            ->postJson('/api/v1/tasks/'.$task->id.'/enqueue')->assertCreated()->json('data.operation_id');
        $task->update(['need_review' => false]);
        $this->getJson('/api/v1/management/operations/'.$id)->assertForbidden();
        $this->assertSame(1, TaskRun::query()->count());
    }
}
