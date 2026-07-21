<?php

namespace Tests\Feature;

use App\Exceptions\DistributionChannelDeletionBlocked;
use App\Models\Admin;
use App\Models\Article;
use App\Models\ArticleDistribution;
use App\Models\Author;
use App\Models\Category;
use App\Models\DistributionChannel;
use App\Models\DistributionChannelOperation;
use App\Models\DistributionChannelSecret;
use App\Models\DistributionLog;
use App\Models\Task;
use App\Services\GeoFlow\DistributionChannelDeletionConfirmation;
use App\Services\GeoFlow\DistributionChannelDeletionService;
use App\Services\GeoFlow\DistributionChannelOperationLeaseService;
use App\Services\GeoFlow\DistributionOrchestrator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class DistributionChannelDeletionServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_deletion_impact_reports_remote_content_credentials_jobs_and_task_changes(): void
    {
        $channel = $this->channel();
        $task = $this->task('local_and_distribution');
        $task->distributionChannels()->attach($channel->id);
        $article = $this->article($task);

        DistributionChannelSecret::query()->create([
            'distribution_channel_id' => (int) $channel->id,
            'key_id' => 'delete_impact_key',
            'secret_ciphertext' => 'encrypted-secret',
            'status' => 'active',
        ]);
        ArticleDistribution::query()->create([
            'article_id' => (int) $article->id,
            'distribution_channel_id' => (int) $channel->id,
            'action' => 'publish',
            'status' => 'failed',
            'remote_id' => 'remote-100',
            'remote_url' => 'https://target.example.com/posts/100',
            'idempotency_key' => 'delete-impact-synced',
        ]);
        ArticleDistribution::query()->create([
            'article_id' => (int) $article->id,
            'distribution_channel_id' => (int) $channel->id,
            'action' => 'update',
            'status' => 'queued',
            'idempotency_key' => 'delete-impact-queued',
        ]);
        ArticleDistribution::query()->create([
            'article_id' => (int) $article->id,
            'distribution_channel_id' => (int) $channel->id,
            'action' => 'delete',
            'status' => 'failed',
            'remote_id' => 'remote-101',
            'idempotency_key' => 'delete-impact-failed-delete',
        ]);

        $impact = app(DistributionChannelDeletionService::class)->inspect($channel);

        $this->assertSame(1, $impact['linked_task_count']);
        $this->assertSame(1, $impact['tasks_switch_to_local_only']);
        $this->assertSame(2, $impact['remote_content_count']);
        $this->assertSame(1, $impact['secret_count']);
        $this->assertSame(1, $impact['queued_count']);
        $this->assertSame(0, $impact['fresh_sending_count']);
    }

    public function test_delete_detaches_channel_adjusts_tasks_removes_children_and_keeps_audit_log(): void
    {
        $channel = $this->channel();
        $otherChannel = $this->channel([
            'name' => '保留渠道',
            'domain' => 'remaining.example.com',
            'endpoint_url' => 'https://remaining.example.com',
        ]);
        $taskWithAnotherChannel = $this->task('local_and_distribution', '保留其他渠道的任务');
        $taskWithAnotherChannel->distributionChannels()->attach([$channel->id, $otherChannel->id]);
        $taskSwitchToLocal = $this->task('local_and_distribution', '切换为本地发布的任务');
        $taskSwitchToLocal->distributionChannels()->attach($channel->id);
        $taskToPause = $this->task('distribution_only', '需要暂停的任务');
        $taskToPause->distributionChannels()->attach($channel->id);
        $article = $this->article($taskSwitchToLocal);
        $distribution = ArticleDistribution::query()->create([
            'article_id' => (int) $article->id,
            'distribution_channel_id' => (int) $channel->id,
            'action' => 'publish',
            'status' => 'synced',
            'remote_id' => 'remote-200',
            'remote_url' => 'https://user:password@target.example.com/posts/200?access_token=secret#fragment',
            'idempotency_key' => 'delete-adjust-tasks',
        ]);
        DistributionChannelSecret::query()->create([
            'distribution_channel_id' => (int) $channel->id,
            'key_id' => 'delete_adjust_key',
            'secret_ciphertext' => 'encrypted-secret',
            'status' => 'active',
        ]);
        DistributionLog::query()->create([
            'distribution_channel_id' => (int) $channel->id,
            'article_distribution_id' => (int) $distribution->id,
            'article_id' => (int) $article->id,
            'level' => 'info',
            'event' => 'distribution.synced',
            'message' => '同步完成',
            'created_at' => now(),
        ]);
        $detachedLog = DistributionLog::query()->create([
            'distribution_channel_id' => null,
            'article_distribution_id' => (int) $distribution->id,
            'article_id' => (int) $article->id,
            'level' => 'info',
            'event' => 'distribution.detached_log',
            'message' => '保留的脱钩日志',
            'created_at' => now(),
        ]);
        $admin = $this->admin();
        $service = app(DistributionChannelDeletionService::class);

        $service->prepare($channel);
        $service->delete($channel, $admin, $this->confirmation($service, $channel));

        $this->assertDatabaseMissing('distribution_channels', ['id' => (int) $channel->id]);
        $this->assertDatabaseMissing('distribution_channel_secrets', ['distribution_channel_id' => (int) $channel->id]);
        $this->assertDatabaseMissing('article_distributions', ['distribution_channel_id' => (int) $channel->id]);
        $this->assertDatabaseMissing('task_distribution_channels', ['distribution_channel_id' => (int) $channel->id]);
        $this->assertDatabaseHas('tasks', [
            'id' => (int) $taskWithAnotherChannel->id,
            'publish_scope' => 'local_and_distribution',
            'distribution_cursor' => 0,
        ]);
        $this->assertDatabaseHas('task_distribution_channels', [
            'task_id' => (int) $taskWithAnotherChannel->id,
            'distribution_channel_id' => (int) $otherChannel->id,
        ]);
        $this->assertDatabaseHas('tasks', [
            'id' => (int) $taskSwitchToLocal->id,
            'publish_scope' => 'local_only',
            'distribution_cursor' => 0,
        ]);
        $this->assertDatabaseHas('tasks', [
            'id' => (int) $taskToPause->id,
            'publish_scope' => 'distribution_only',
            'status' => 'paused',
            'distribution_cursor' => 0,
        ]);
        $this->assertNotNull($taskToPause->fresh()->last_error_message);
        $audit = DistributionLog::query()->where('event', 'channel.deleted')->firstOrFail();
        $this->assertSame((int) $channel->id, (int) $audit->distribution_channel_id);
        $this->assertSame('待删除渠道', $audit->context['channel']['name']);
        $this->assertArrayNotHasKey('secret_ciphertext', $audit->context['channel']);
        $this->assertStringNotContainsString('encrypted-secret', json_encode($audit->context) ?: '');
        $this->assertSame('remote-200', $audit->context['remote_cleanup_manifest'][0]['remote_id']);
        $this->assertSame('https://target.example.com/posts/200', $audit->context['remote_cleanup_manifest'][0]['remote_url']);
        $this->assertStringNotContainsString('access_token', json_encode($audit->context) ?: '');
        $this->assertNull($detachedLog->fresh()->article_distribution_id);
        $this->assertDatabaseHas('articles', ['id' => (int) $article->id]);
    }

    public function test_prepare_cancels_queued_jobs_and_cancel_keeps_the_channel_paused(): void
    {
        $channel = $this->channel();
        $task = $this->task('local_and_distribution');
        $article = $this->article($task);
        $distribution = ArticleDistribution::query()->create([
            'article_id' => (int) $article->id,
            'distribution_channel_id' => (int) $channel->id,
            'action' => 'publish',
            'status' => 'queued',
            'next_retry_at' => now(),
            'idempotency_key' => 'prepare-cancels-queued',
        ]);
        $service = app(DistributionChannelDeletionService::class);

        $service->prepare($channel);

        $this->assertSame(DistributionChannel::STATUS_DELETING, $channel->fresh()->status);
        $this->assertSame('failed', $distribution->fresh()->status);
        $this->assertNull($distribution->fresh()->next_retry_at);

        $service->cancel($channel);

        $this->assertSame(DistributionChannel::STATUS_PAUSED, $channel->fresh()->status);
        $this->assertSame('failed', $distribution->fresh()->status);
    }

    public function test_paused_channel_can_be_prepared_and_deleted(): void
    {
        $channel = $this->channel(['status' => DistributionChannel::STATUS_PAUSED]);
        $service = app(DistributionChannelDeletionService::class);

        $service->prepare($channel);
        $service->delete($channel, $this->admin(), $this->confirmation($service, $channel));

        $this->assertDatabaseMissing('distribution_channels', ['id' => (int) $channel->id]);
    }

    public function test_fresh_sending_job_blocks_final_delete(): void
    {
        $channel = $this->channel();
        $task = $this->task('local_and_distribution');
        $article = $this->article($task);
        ArticleDistribution::query()->create([
            'article_id' => (int) $article->id,
            'distribution_channel_id' => (int) $channel->id,
            'action' => 'publish',
            'status' => 'sending',
            'last_attempt_at' => now(),
            'idempotency_key' => 'delete-fresh-sending',
        ]);
        $service = app(DistributionChannelDeletionService::class);
        $service->prepare($channel);

        try {
            $service->delete($channel, $this->admin(), $this->confirmation($service, $channel));
            $this->fail('A channel with a fresh sending job must remain available.');
        } catch (DistributionChannelDeletionBlocked $exception) {
            $this->assertSame('sending_in_progress', $exception->reason);
        }

        $this->assertDatabaseHas('distribution_channels', [
            'id' => (int) $channel->id,
            'status' => DistributionChannel::STATUS_DELETING,
        ]);
    }

    public function test_stale_sending_job_requires_explicit_force_confirmation(): void
    {
        $channel = $this->channel();
        $task = $this->task('local_and_distribution');
        $article = $this->article($task);
        $service = app(DistributionChannelDeletionService::class);
        $admin = $this->admin();
        ArticleDistribution::query()->create([
            'article_id' => (int) $article->id,
            'distribution_channel_id' => (int) $channel->id,
            'action' => 'publish',
            'status' => 'sending',
            'last_attempt_at' => now()->subSeconds($service->staleAfterSeconds() + 1),
            'idempotency_key' => 'delete-stale-sending',
        ]);
        $service->prepare($channel);

        try {
            $service->delete($channel, $admin, $this->confirmation($service, $channel));
            $this->fail('A stale sending job must require force confirmation.');
        } catch (DistributionChannelDeletionBlocked $exception) {
            $this->assertSame('stale_sending_requires_force', $exception->reason);
        }

        $service->delete($channel, $admin, $this->confirmation($service, $channel, forceStaleSending: true));

        $this->assertDatabaseMissing('distribution_channels', ['id' => (int) $channel->id]);
    }

    public function test_distribution_job_claims_queued_work_only_for_an_active_channel(): void
    {
        $activeChannel = $this->channel();
        $pausedChannel = $this->channel([
            'name' => '暂停渠道',
            'domain' => 'paused.example.com',
            'endpoint_url' => 'https://paused.example.com',
            'status' => DistributionChannel::STATUS_PAUSED,
        ]);
        $task = $this->task('local_and_distribution');
        $article = $this->article($task);
        $activeDistribution = ArticleDistribution::query()->create([
            'article_id' => (int) $article->id,
            'distribution_channel_id' => (int) $activeChannel->id,
            'action' => 'publish',
            'status' => 'queued',
            'idempotency_key' => 'claim-active-channel',
        ]);
        $pausedDistribution = ArticleDistribution::query()->create([
            'article_id' => (int) $article->id,
            'distribution_channel_id' => (int) $pausedChannel->id,
            'action' => 'publish',
            'status' => 'queued',
            'idempotency_key' => 'claim-paused-channel',
        ]);
        $orchestrator = app(DistributionOrchestrator::class);

        $claimed = $orchestrator->claimForProcessing((int) $activeDistribution->id);
        $rejected = $orchestrator->claimForProcessing((int) $pausedDistribution->id);

        $this->assertNotNull($claimed);
        $this->assertSame('sending', $claimed->status);
        $this->assertSame(1, (int) $claimed->attempt_count);
        $this->assertNull($rejected);
        $this->assertDatabaseHas('article_distributions', [
            'id' => (int) $pausedDistribution->id,
            'status' => 'failed',
        ]);
    }

    public function test_immediate_remote_action_cannot_start_after_channel_enters_deleting_state(): void
    {
        Http::fake();
        $channel = $this->channel();
        $task = $this->task('local_and_distribution');
        $article = $this->article($task);
        $distribution = ArticleDistribution::query()->create([
            'article_id' => (int) $article->id,
            'distribution_channel_id' => (int) $channel->id,
            'action' => 'publish',
            'status' => 'synced',
            'remote_id' => 'remote-300',
            'idempotency_key' => 'immediate-action-deleting',
        ]);
        app(DistributionChannelDeletionService::class)->prepare($channel);

        try {
            app(DistributionOrchestrator::class)->updateRemoteArticle($distribution);
            $this->fail('A remote action must not start for a deleting channel.');
        } catch (\RuntimeException $exception) {
            $this->assertSame(__('admin.distribution.delete.operation_blocked'), $exception->getMessage());
        }

        $this->assertSame('synced', $distribution->fresh()->status);
        Http::assertNothingSent();
    }

    public function test_manual_retry_cannot_requeue_work_for_a_deleting_channel(): void
    {
        $channel = $this->channel();
        $task = $this->task('local_and_distribution');
        $article = $this->article($task);
        $distribution = ArticleDistribution::query()->create([
            'article_id' => (int) $article->id,
            'distribution_channel_id' => (int) $channel->id,
            'action' => 'publish',
            'status' => 'failed',
            'idempotency_key' => 'retry-deleting-channel',
        ]);
        app(DistributionChannelDeletionService::class)->prepare($channel);

        $this->actingAs($this->admin(), 'admin')
            ->post(route('admin.distribution.retry', ['distributionId' => (int) $distribution->id]))
            ->assertRedirect(route('admin.distribution.delete', ['channelId' => (int) $channel->id]))
            ->assertSessionHasErrors();

        $this->assertDatabaseHas('article_distributions', [
            'id' => (int) $distribution->id,
            'status' => 'failed',
        ]);
    }

    public function test_article_enqueue_does_not_overwrite_a_sending_distribution(): void
    {
        Queue::fake();
        $channel = $this->channel();
        $task = $this->task('local_and_distribution');
        $task->distributionChannels()->attach($channel->id);
        $article = $this->article($task);
        $distribution = ArticleDistribution::query()->create([
            'article_id' => (int) $article->id,
            'distribution_channel_id' => (int) $channel->id,
            'action' => 'publish',
            'status' => 'sending',
            'last_attempt_at' => now(),
            'idempotency_key' => 'enqueue-must-not-overwrite-sending',
        ]);

        app(DistributionOrchestrator::class)->enqueueForArticle($article);

        $this->assertSame('sending', $distribution->fresh()->status);
        Queue::assertNothingPushed();
    }

    public function test_channel_content_refresh_does_not_overwrite_a_sending_distribution(): void
    {
        Queue::fake();
        $channel = $this->channel();
        $task = $this->task('local_and_distribution');
        $article = $this->article($task);
        $distribution = ArticleDistribution::query()->create([
            'article_id' => (int) $article->id,
            'distribution_channel_id' => (int) $channel->id,
            'action' => 'publish',
            'status' => 'sending',
            'last_attempt_at' => now(),
            'idempotency_key' => 'refresh-must-not-overwrite-sending',
        ]);

        $queuedCount = app(DistributionOrchestrator::class)->enqueueChannelContentRefresh($channel);

        $this->assertSame(0, $queuedCount);
        $this->assertSame('sending', $distribution->fresh()->status);
        Queue::assertNothingPushed();
    }

    public function test_manual_retry_does_not_overwrite_a_sending_distribution(): void
    {
        Queue::fake();
        $channel = $this->channel();
        $task = $this->task('local_and_distribution');
        $article = $this->article($task);
        $distribution = ArticleDistribution::query()->create([
            'article_id' => (int) $article->id,
            'distribution_channel_id' => (int) $channel->id,
            'action' => 'publish',
            'status' => 'sending',
            'last_attempt_at' => now(),
            'idempotency_key' => 'retry-must-not-overwrite-sending',
        ]);

        $this->actingAs($this->admin(), 'admin')
            ->post(route('admin.distribution.retry', ['distributionId' => (int) $distribution->id]))
            ->assertSessionHasErrors();

        $this->assertSame('sending', $distribution->fresh()->status);
        Queue::assertNothingPushed();
    }

    public function test_stale_cutoff_covers_the_longest_supported_remote_request(): void
    {
        config()->set('queue.default', 'sync');

        $this->assertGreaterThanOrEqual(150, app(DistributionChannelDeletionService::class)->staleAfterSeconds());
    }

    public function test_sending_row_without_attempt_timestamps_requires_force_confirmation(): void
    {
        $channel = $this->channel();
        $task = $this->task('local_and_distribution');
        $article = $this->article($task);
        $distribution = ArticleDistribution::query()->create([
            'article_id' => (int) $article->id,
            'distribution_channel_id' => (int) $channel->id,
            'action' => 'publish',
            'status' => 'sending',
            'idempotency_key' => 'sending-without-timestamps',
        ]);
        $distribution->timestamps = false;
        $distribution->forceFill(['last_attempt_at' => null, 'updated_at' => null])->save();

        $impact = app(DistributionChannelDeletionService::class)->inspect($channel);

        $this->assertSame(1, $impact['sending_count']);
        $this->assertSame(0, $impact['fresh_sending_count']);
        $this->assertSame(1, $impact['stale_sending_count']);
    }

    public function test_active_channel_operation_lease_blocks_final_delete_until_release(): void
    {
        $channel = $this->channel();
        $service = app(DistributionChannelDeletionService::class);
        $admin = $this->admin();

        app(DistributionChannelOperationLeaseService::class)->run(
            $channel,
            'test_remote_operation',
            function (DistributionChannel $lockedChannel) use ($service, $admin): void {
                $service->prepare($lockedChannel);
                $impact = $service->inspect($lockedChannel->fresh());
                $this->assertSame(1, $impact['fresh_operation_count']);

                try {
                    $service->delete(
                        $lockedChannel,
                        $admin,
                        $this->confirmation($service, $lockedChannel),
                    );
                    $this->fail('A live channel operation must block final deletion.');
                } catch (DistributionChannelDeletionBlocked $exception) {
                    $this->assertSame('operation_in_progress', $exception->reason);
                }
            },
        );

        $this->assertDatabaseCount('distribution_channel_operations', 0);
        $service->delete($channel, $admin, $this->confirmation($service, $channel));
        $this->assertDatabaseMissing('distribution_channels', ['id' => (int) $channel->id]);
    }

    public function test_final_delete_rejects_a_changed_impact_snapshot(): void
    {
        $channel = $this->channel();
        $service = app(DistributionChannelDeletionService::class);
        $service->prepare($channel);
        $confirmation = $this->confirmation($service, $channel);
        DistributionChannelSecret::query()->create([
            'distribution_channel_id' => (int) $channel->id,
            'key_id' => 'late-secret',
            'secret_ciphertext' => 'encrypted-secret',
            'status' => 'active',
        ]);

        try {
            $service->delete($channel, $this->admin(), $confirmation);
            $this->fail('A changed deletion impact must require a fresh review.');
        } catch (DistributionChannelDeletionBlocked $exception) {
            $this->assertSame('impact_changed', $exception->reason);
        }

        $this->assertDatabaseHas('distribution_channels', ['id' => (int) $channel->id]);
        $this->assertDatabaseHas('distribution_channel_secrets', ['key_id' => 'late-secret']);
    }

    public function test_stale_channel_operation_requires_separate_force_confirmation(): void
    {
        $channel = $this->channel();
        $service = app(DistributionChannelDeletionService::class);
        $admin = $this->admin();
        DistributionChannelOperation::query()->create([
            'distribution_channel_id' => (int) $channel->id,
            'token' => 'stale-operation-token',
            'operation' => 'site_settings_sync',
            'started_at' => now()->subMinutes(10),
            'expires_at' => now()->subMinute(),
        ]);
        $service->prepare($channel);

        try {
            $service->delete($channel, $admin, $this->confirmation($service, $channel));
            $this->fail('A stale channel operation must require force confirmation.');
        } catch (DistributionChannelDeletionBlocked $exception) {
            $this->assertSame('stale_operation_requires_force', $exception->reason);
        }

        $service->delete(
            $channel,
            $admin,
            $this->confirmation($service, $channel, forceStaleOperations: true),
        );
        $this->assertDatabaseMissing('distribution_channels', ['id' => (int) $channel->id]);
    }

    public function test_deleting_channel_cannot_be_attached_to_a_task(): void
    {
        $channel = $this->channel();
        $task = $this->task('distribution_only');
        app(DistributionChannelDeletionService::class)->prepare($channel);

        $this->expectException(\RuntimeException::class);
        app(DistributionOrchestrator::class)->syncTaskChannels($task, [(int) $channel->id]);
    }

    public function test_deleting_channel_cannot_be_silently_detached_from_a_task(): void
    {
        $channel = $this->channel();
        $task = $this->task('distribution_only');
        $task->distributionChannels()->attach($channel->id);
        app(DistributionChannelDeletionService::class)->prepare($channel);

        try {
            app(DistributionOrchestrator::class)->syncTaskChannels($task, []);
            $this->fail('A deleting channel must remain attached until the deletion service adjusts its task.');
        } catch (\RuntimeException) {
            $this->assertDatabaseHas('task_distribution_channels', [
                'task_id' => (int) $task->id,
                'distribution_channel_id' => (int) $channel->id,
            ]);
        }
    }

    public function test_task_revision_guard_rejects_a_stale_form_after_channel_deletion(): void
    {
        $channel = $this->channel();
        $task = $this->task('distribution_only');
        $task->distributionChannels()->attach($channel->id);
        $orchestrator = app(DistributionOrchestrator::class);
        $this->assertTrue(method_exists($orchestrator, 'taskRevision'));
        $revision = $orchestrator->taskRevision($task);
        $service = app(DistributionChannelDeletionService::class);
        $service->prepare($channel);
        $service->delete($channel, $this->admin(), $this->confirmation($service, $channel));

        $this->expectException(\RuntimeException::class);
        DB::transaction(
            fn () => $orchestrator->assertTaskRevision((int) $task->id, $revision)
        );
    }

    public function test_task_revision_ignores_fields_unrelated_to_distribution_safety(): void
    {
        $task = $this->task('local_and_distribution');
        $orchestrator = app(DistributionOrchestrator::class);
        $revision = $orchestrator->taskRevision($task);
        $task->timestamps = false;
        $task->forceFill([
            'name' => '普通字段更新后的任务',
            'updated_at' => now()->addMinute(),
        ])->save();

        $this->assertSame($revision, $orchestrator->taskRevision($task->fresh()));
    }

    private function channel(array $attributes = []): DistributionChannel
    {
        return DistributionChannel::query()->create($attributes + [
            'name' => '待删除渠道',
            'domain' => 'target.example.com',
            'endpoint_url' => 'https://target.example.com',
            'status' => 'active',
        ]);
    }

    private function confirmation(
        DistributionChannelDeletionService $service,
        DistributionChannel $channel,
        bool $forceStaleSending = false,
        bool $forceStaleOperations = false,
    ): DistributionChannelDeletionConfirmation {
        $impact = $service->inspect($channel->fresh());

        return new DistributionChannelDeletionConfirmation(
            impactFingerprint: (string) $impact['impact_fingerprint'],
            ackRemoteContent: true,
            ackTaskChanges: true,
            ackCredentials: true,
            ackHistory: true,
            forceStaleSending: $forceStaleSending,
            forceStaleOperations: $forceStaleOperations,
        );
    }

    private function task(string $publishScope, string $name = '渠道删除测试任务'): Task
    {
        return Task::query()->create([
            'name' => $name,
            'status' => 'active',
            'publish_scope' => $publishScope,
            'distribution_strategy' => 'round_robin',
            'distribution_cursor' => 4,
        ]);
    }

    private function admin(): Admin
    {
        return Admin::query()->create([
            'username' => 'distribution_delete_admin',
            'password' => 'secret-123',
            'email' => 'distribution-delete@example.com',
            'display_name' => 'Distribution Delete Admin',
            'role' => 'super_admin',
            'status' => 'active',
        ]);
    }

    private function article(Task $task): Article
    {
        $author = Author::query()->create(['name' => '测试作者']);
        $category = Category::query()->create([
            'name' => '测试分类',
            'slug' => 'distribution-channel-deletion',
        ]);

        return Article::query()->create([
            'title' => '渠道删除测试文章',
            'slug' => 'distribution-channel-deletion-article',
            'content' => '正文',
            'status' => 'published',
            'review_status' => 'approved',
            'published_at' => now(),
            'task_id' => (int) $task->id,
            'author_id' => (int) $author->id,
            'category_id' => (int) $category->id,
        ]);
    }
}
