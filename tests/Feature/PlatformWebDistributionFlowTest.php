<?php

namespace Tests\Feature;

use App\Exceptions\ApiException;
use App\Exceptions\PlatformPublishBlockedException;
use App\Models\Admin;
use App\Models\Article;
use App\Models\ArticleDistribution;
use App\Models\Author;
use App\Models\Category;
use App\Models\DistributionChannel;
use App\Models\DistributionChannelOperation;
use App\Models\DistributionLog;
use App\Models\ManualPublication;
use App\Models\ManualPublicationAccount;
use App\Models\ManualPublicationPersona;
use App\Models\Task;
use App\Services\BrowserOperations\ManualPublicationBrowserService;
use App\Services\GeoFlow\ArticleWorkflowTransitionService;
use App\Services\GeoFlow\Distribution\PlatformWeb\PlatformWebDistributionBridge;
use App\Services\GeoFlow\DistributionChannelDeletionService;
use App\Services\GeoFlow\DistributionOrchestrator;
use App\Services\GeoFlow\DistributionPublisherManager;
use App\Services\GeoFlow\ManualPublicationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class PlatformWebDistributionFlowTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();
    }

    public function test_platform_web_publish_creates_ready_work_order_and_parks_distribution_in_awaiting_extension(): void
    {
        [$article, $channel] = $this->fixtures('first');
        $orchestrator = app(DistributionOrchestrator::class);

        $ids = $orchestrator->enqueueForArticle($article);
        $this->assertNotEmpty($ids);

        $orchestrator->process(ArticleDistribution::query()->findOrFail($ids[0]));

        $distribution = ArticleDistribution::query()->findOrFail($ids[0]);
        $this->assertSame('awaiting_extension', (string) $distribution->status);

        $publication = ManualPublication::query()
            ->where('source_distribution_id', (int) $distribution->id)
            ->firstOrFail();
        $this->assertSame('ready', (string) $publication->status);
        $this->assertSame('toutiao', (string) $publication->platform);
        $this->assertSame('toutiao_post', (string) ($publication->publication_payload['target_action'] ?? ''));
        $this->assertStringStartsWith('https://mp.toutiao.com', (string) $publication->target_url);

        $bodyPlain = (string) ($publication->publication_payload['body_plain'] ?? '');
        $this->assertStringContainsString('PLATFORMWEB-MARKER 正文第一段', $bodyPlain);
        $this->assertStringContainsString('第二段纯文本内容', $bodyPlain);
        $this->assertStringNotContainsString('<', $bodyPlain);
    }

    public function test_platform_web_publish_hard_blocks_second_distribution_to_same_platform_after_sync(): void
    {
        [$article, $channel] = $this->fixtures('first');
        $orchestrator = app(DistributionOrchestrator::class);

        $ids = $orchestrator->enqueueForArticle($article);
        $orchestrator->process(ArticleDistribution::query()->findOrFail($ids[0]));
        $distribution = ArticleDistribution::query()->findOrFail($ids[0]);
        $distribution->forceFill([
            'status' => 'synced',
            'remote_url' => 'https://mp.toutiao.com/profile_v4/graph/articles/detail/123',
        ])->save();

        $secondIds = $orchestrator->enqueueForArticle($article->fresh());

        $this->assertSame([], $secondIds);
        $this->assertSame('synced', (string) $distribution->fresh()->status);
        $this->assertSame(
            1,
            ManualPublication::query()->where('source_distribution_id', (int) $distribution->id)->count(),
        );
        $this->assertDatabaseHas('distribution_logs', [
            'event' => 'platform_publish_blocked_duplicate',
            'article_distribution_id' => null,
        ]);
    }

    public function test_enqueue_guard_blocks_reenqueue_while_distribution_awaits_extension(): void
    {
        [$article, $channel] = $this->fixtures('first');
        $orchestrator = app(DistributionOrchestrator::class);
        $ids = $orchestrator->enqueueForArticle($article);
        $orchestrator->process(ArticleDistribution::query()->findOrFail($ids[0]));
        $distribution = ArticleDistribution::query()->findOrFail($ids[0]);
        $this->assertSame('awaiting_extension', (string) $distribution->status);

        $secondIds = $orchestrator->enqueueForArticle($article->fresh());

        $this->assertSame([], $secondIds);
        $this->assertSame('awaiting_extension', (string) $distribution->fresh()->status);
        $this->assertSame(
            1,
            ManualPublication::query()->where('source_distribution_id', (int) $distribution->id)->count(),
        );
        $this->assertDatabaseHas('distribution_logs', [
            'event' => 'platform_publish_blocked_duplicate',
            'distribution_channel_id' => (int) $channel->id,
        ]);
    }

    public function test_awaiting_extension_on_channel_a_blocks_publisher_for_channel_b(): void
    {
        [$article, $channelA] = $this->fixtures('first');
        $admin = Admin::query()->firstOrFail();
        $accountB = $this->createAccount($admin, '平台B账号');
        $channelB = $this->createPlatformWebChannel($admin, $accountB, 'Channel B');
        $article->task->distributionChannels()->attach($channelB->id, [
            'trigger' => 'after_local_publish',
            'remote_status' => 'follow_local',
            'failure_policy' => 'ignore_distribution_failure',
            'max_attempts' => 3,
            'sort_order' => 2,
        ]);

        $orchestrator = app(DistributionOrchestrator::class);
        $ids = $orchestrator->enqueueForArticleTargets($article->fresh(), [$channelA->id, $channelB->id], []);
        $orchestrator->process(ArticleDistribution::query()->findOrFail($ids[0]));
        $distributionB = ArticleDistribution::query()->findOrFail($ids[1]);

        $this->expectException(PlatformPublishBlockedException::class);
        $this->expectExceptionMessage('同平台严禁重发');
        app(DistributionPublisherManager::class)->forChannel($channelB)->publish($distributionB->fresh(), []);
    }

    public function test_bridge_ignores_non_terminal_work_order_receipt_and_preserves_awaiting_extension(): void
    {
        [$article, $channel] = $this->fixtures('first');
        $orchestrator = app(DistributionOrchestrator::class);
        $ids = $orchestrator->enqueueForArticle($article);
        $orchestrator->process(ArticleDistribution::query()->findOrFail($ids[0]));
        $distribution = ArticleDistribution::query()->findOrFail($ids[0]);
        $publication = ManualPublication::query()
            ->where('source_distribution_id', (int) $distribution->id)
            ->firstOrFail();
        // 模拟工单被重开（陈旧回执场景）：工单回到 ready，随后回执不应覆盖现状。
        $publication->forceFill(['status' => ManualPublication::STATUS_READY])->save();

        app(PlatformWebDistributionBridge::class)->handleReceipt($publication->fresh());

        $distribution = $distribution->fresh();
        $this->assertSame('awaiting_extension', (string) $distribution->status);
        $this->assertDatabaseMissing('distribution_logs', [
            'event' => 'platform_web_receipt',
            'article_distribution_id' => (int) $distribution->id,
        ]);

        // 已 synced 的分发行同样拒绝回执写回（只接受 awaiting_extension/sending/outcome_unknown）。
        $distribution->forceFill(['status' => 'synced'])->save();
        $publication->forceFill([
            'status' => ManualPublication::STATUS_FAILED,
            'execution_receipt' => ['error_code' => 'late_failure'],
        ])->save();
        app(PlatformWebDistributionBridge::class)->handleReceipt($publication->fresh());

        $this->assertSame('synced', (string) $distribution->fresh()->status);
    }

    public function test_content_refresh_skips_platform_web_channels(): void
    {
        [$article, $channel] = $this->fixtures('first');
        $orchestrator = app(DistributionOrchestrator::class);
        $ids = $orchestrator->enqueueForArticle($article);
        $orchestrator->process(ArticleDistribution::query()->findOrFail($ids[0]));
        $distribution = ArticleDistribution::query()->findOrFail($ids[0]);

        $count = $orchestrator->enqueueChannelContentRefresh($channel);

        $this->assertSame(0, $count);
        $distribution = $distribution->fresh();
        $this->assertSame('awaiting_extension', (string) $distribution->status);
        $this->assertSame('publish', (string) $distribution->action);
    }

    public function test_unsupported_update_action_restores_full_row_state_for_later_retry(): void
    {
        [$article, $channel] = $this->fixtures('first');
        $orchestrator = app(DistributionOrchestrator::class);
        $ids = $orchestrator->enqueueForArticle($article);
        $orchestrator->process(ArticleDistribution::query()->findOrFail($ids[0]));
        $distribution = ArticleDistribution::query()->findOrFail($ids[0]);
        $publication = ManualPublication::query()
            ->where('source_distribution_id', (int) $distribution->id)
            ->firstOrFail();
        // 模拟扩展回执失败落定后的 failed 分发行。
        $publication->forceFill(['status' => ManualPublication::STATUS_FAILED, 'result_note' => 'boom'])->save();
        $distribution->forceFill(['status' => 'failed', 'last_error_message' => 'boom'])->save();
        $payloadHashBefore = (string) $distribution->payload_hash;
        $idempotencyKeyBefore = (string) $distribution->idempotency_key;

        $orchestrator->updateRemoteArticle($distribution->fresh());

        $distribution = $distribution->fresh();
        $this->assertSame('failed', (string) $distribution->status);
        $this->assertSame('publish', (string) $distribution->action);
        $this->assertSame($payloadHashBefore, (string) $distribution->payload_hash);
        $this->assertSame($idempotencyKeyBefore, (string) $distribution->idempotency_key);
        $this->assertSame('boom', (string) $distribution->last_error_message);

        // 重试：载荷摘要校验必须通过（若 payload_hash 未还原会确定性抛“分发载荷摘要校验失败”），
        // 且发布器应重开 failed 工单。
        $distribution->forceFill(['status' => 'queued'])->save();
        $orchestrator->process($distribution->fresh());

        $this->assertSame('awaiting_extension', (string) $distribution->fresh()->status);
        $this->assertSame('ready', (string) $publication->fresh()->status);
    }

    public function test_unsupported_update_action_restores_status_and_logs(): void
    {
        [$article, $channel] = $this->fixtures('first');
        $orchestrator = app(DistributionOrchestrator::class);
        $ids = $orchestrator->enqueueForArticle($article);
        $orchestrator->process(ArticleDistribution::query()->findOrFail($ids[0]));
        $distribution = ArticleDistribution::query()->findOrFail($ids[0]);
        $distribution->forceFill(['status' => 'synced'])->save();

        $orchestrator->updateRemoteArticle($distribution->fresh());

        $distribution = $distribution->fresh();
        $this->assertSame('synced', (string) $distribution->status);
        $this->assertNotSame('update', (string) $distribution->action);
        $this->assertDatabaseHas('distribution_logs', [
            'event' => 'platform_web_action_unsupported',
            'article_distribution_id' => (int) $distribution->id,
        ]);
    }

    public function test_channel_deletion_cancels_awaiting_extension_distribution_and_work_order(): void
    {
        [$article, $channel] = $this->fixtures('first');
        $orchestrator = app(DistributionOrchestrator::class);
        $ids = $orchestrator->enqueueForArticle($article);
        $orchestrator->process(ArticleDistribution::query()->findOrFail($ids[0]));
        $distribution = ArticleDistribution::query()->findOrFail($ids[0]);
        $publication = ManualPublication::query()
            ->where('source_distribution_id', (int) $distribution->id)
            ->firstOrFail();

        app(DistributionChannelDeletionService::class)->prepare($channel);

        $distribution = $distribution->fresh();
        $this->assertSame('failed', (string) $distribution->status);
        $this->assertSame(
            __('admin.distribution.delete.queued_cancelled_error'),
            (string) $distribution->last_error_message,
        );

        $publication = $publication->fresh();
        $this->assertSame(ManualPublication::STATUS_CANCELLED, (string) $publication->status);
        $this->assertSame('渠道删除，扩展发布工单已取消。', (string) $publication->result_note);
        $this->assertDatabaseHas('manual_publication_transitions', [
            'manual_publication_id' => (int) $publication->id,
            'from_status' => ManualPublication::STATUS_READY,
            'to_status' => ManualPublication::STATUS_CANCELLED,
        ]);
    }

    public function test_publisher_level_guard_throws_for_synced_platform_via_different_channel(): void
    {
        [$article, $channelA] = $this->fixtures('first');
        $admin = Admin::query()->firstOrFail();
        $accountB = $this->createAccount($admin, '平台B账号');
        $channelB = $this->createPlatformWebChannel($admin, $accountB, 'Channel B');
        $article->task->distributionChannels()->attach($channelB->id, [
            'trigger' => 'after_local_publish',
            'remote_status' => 'follow_local',
            'failure_policy' => 'ignore_distribution_failure',
            'max_attempts' => 3,
            'sort_order' => 2,
        ]);

        $orchestrator = app(DistributionOrchestrator::class);
        $ids = $orchestrator->enqueueForArticleTargets($article->fresh(), [$channelA->id, $channelB->id], []);
        $this->assertCount(2, $ids);

        $distributionA = ArticleDistribution::query()->findOrFail($ids[0]);
        $distributionB = ArticleDistribution::query()->findOrFail($ids[1]);
        $orchestrator->process($distributionA);
        $distributionA->fresh()->forceFill([
            'status' => 'synced',
            'remote_url' => 'https://mp.toutiao.com/profile_v4/graph/articles/detail/456',
        ])->save();

        $this->expectException(PlatformPublishBlockedException::class);
        app(DistributionPublisherManager::class)->forChannel($channelB)->publish($distributionB->fresh(), []);
    }

    public function test_bridge_writes_distribution_synced_on_completed_receipt(): void
    {
        [$article, $channel] = $this->fixtures('first');
        $orchestrator = app(DistributionOrchestrator::class);
        $ids = $orchestrator->enqueueForArticle($article);
        $orchestrator->process(ArticleDistribution::query()->findOrFail($ids[0]));
        $distribution = ArticleDistribution::query()->findOrFail($ids[0]);
        $publication = ManualPublication::query()
            ->where('source_distribution_id', (int) $distribution->id)
            ->firstOrFail();

        $publication->forceFill([
            'status' => 'completed',
            'completion_url' => 'https://mp.toutiao.com/profile_v4/graph/articles/detail/123',
        ])->save();
        app(PlatformWebDistributionBridge::class)->handleReceipt($publication->fresh());

        $distribution = $distribution->fresh();
        $this->assertSame('synced', (string) $distribution->status);
        $this->assertSame((string) $publication->id, (string) $distribution->remote_id);
        $this->assertSame(
            'https://mp.toutiao.com/profile_v4/graph/articles/detail/123',
            (string) $distribution->remote_url,
        );
        $this->assertNull($distribution->last_error_message);
        $this->assertDatabaseHas('distribution_logs', [
            'event' => 'platform_web_receipt',
            'article_distribution_id' => (int) $distribution->id,
            'level' => 'info',
        ]);
    }

    public function test_bridge_maps_failed_receipt_to_failed_distribution(): void
    {
        [$article, $channel] = $this->fixtures('first');
        $orchestrator = app(DistributionOrchestrator::class);
        $ids = $orchestrator->enqueueForArticle($article);
        $orchestrator->process(ArticleDistribution::query()->findOrFail($ids[0]));
        $distribution = ArticleDistribution::query()->findOrFail($ids[0]);
        $publication = ManualPublication::query()
            ->where('source_distribution_id', (int) $distribution->id)
            ->firstOrFail();

        $publication->forceFill([
            'status' => 'failed',
            'execution_receipt' => ['error_code' => 'login_required'],
        ])->save();
        app(PlatformWebDistributionBridge::class)->handleReceipt($publication->fresh());

        $distribution = $distribution->fresh();
        $this->assertSame('failed', (string) $distribution->status);
        $this->assertSame('login_required', (string) $distribution->last_error_message);
        $this->assertNull($distribution->remote_url);
        $this->assertDatabaseHas('distribution_logs', [
            'event' => 'platform_web_receipt',
            'article_distribution_id' => (int) $distribution->id,
            'level' => 'error',
        ]);
    }

    public function test_applied_unknown_receipts_do_not_starve_new_completions_in_bounded_reconcile(): void
    {
        $orchestrator = app(DistributionOrchestrator::class);
        foreach (['old-unknown-a', 'old-unknown-b'] as $key) {
            [$article] = $this->fixtures($key);
            $ids = $orchestrator->enqueueForArticle($article);
            $orchestrator->process(ArticleDistribution::findOrFail($ids[0]));
            $publication = ManualPublication::where('source_distribution_id', $ids[0])->firstOrFail();
            $publication->update(['status' => 'outcome_unknown']);
            app(PlatformWebDistributionBridge::class)->handleReceipt($publication);
        }
        [$article] = $this->fixtures('new-completion');
        $ids = $orchestrator->enqueueForArticle($article);
        $orchestrator->process(ArticleDistribution::findOrFail($ids[0]));
        $publication = ManualPublication::where('source_distribution_id', $ids[0])->firstOrFail();
        $publication->update(['status' => 'completed', 'completion_url' => 'https://mp.toutiao.com/a']);
        $bridge = app(PlatformWebDistributionBridge::class);
        $this->assertSame(1, $bridge->reconcile(1));
        $this->assertSame('synced', ArticleDistribution::findOrFail($ids[0])->status);
        $this->assertSame(0, $bridge->reconcile(1));
        $this->assertSame(3, DistributionLog::where('event', 'platform_web_receipt')->count());
    }

    public function test_repeated_unknown_receipt_reconciliation_is_idempotent(): void
    {
        [$article] = $this->fixtures('reconcile');
        $orchestrator = app(DistributionOrchestrator::class);
        $ids = $orchestrator->enqueueForArticle($article);
        $orchestrator->process(ArticleDistribution::findOrFail($ids[0]));
        $publication = ManualPublication::where('source_distribution_id', $ids[0])->firstOrFail();
        $publication->forceFill(['status' => ManualPublication::STATUS_OUTCOME_UNKNOWN])->save();
        $bridge = app(PlatformWebDistributionBridge::class);
        $bridge->handleReceipt($publication);
        $before = ArticleDistribution::findOrFail($ids[0])->getAttributes();
        $bridge->handleReceipt($publication);
        $this->assertSame($before, ArticleDistribution::findOrFail($ids[0])->getAttributes());
        $this->assertSame(1, DistributionLog::where('event', 'platform_web_receipt')->count());
    }

    public function test_bridge_maps_outcome_unknown_receipt(): void
    {
        [$article, $channel] = $this->fixtures('first');
        $orchestrator = app(DistributionOrchestrator::class);
        $ids = $orchestrator->enqueueForArticle($article);
        $orchestrator->process(ArticleDistribution::query()->findOrFail($ids[0]));
        $distribution = ArticleDistribution::query()->findOrFail($ids[0]);
        $publication = ManualPublication::query()
            ->where('source_distribution_id', (int) $distribution->id)
            ->firstOrFail();

        $publication->forceFill(['status' => 'outcome_unknown'])->save();
        app(PlatformWebDistributionBridge::class)->handleReceipt($publication->fresh());

        $distribution = $distribution->fresh();
        $this->assertSame('outcome_unknown', (string) $distribution->status);
        $this->assertSame('extension_reported_failure', (string) $distribution->last_error_message);
    }

    public function test_real_extension_receipt_updates_distribution_via_record_receipt(): void
    {
        [$distribution, , $response] = $this->browserReceiptFlow('1', [
            'outcome' => 'completed',
            'completion_url' => 'https://mp.toutiao.com/profile_v4/graph/articles/detail/9',
            'adapter_version' => '0.1.0',
            'target_origin' => 'https://mp.toutiao.com',
            'started_at' => now()->subMinute()->toIso8601String(),
            'finished_at' => now()->toIso8601String(),
        ]);

        $response->assertJsonPath('data.publication.status', ManualPublication::STATUS_COMPLETED);

        $distribution = $distribution->fresh();
        $this->assertSame('synced', (string) $distribution->status);
        $this->assertSame(
            'https://mp.toutiao.com/profile_v4/graph/articles/detail/9',
            (string) $distribution->remote_url,
        );
        $this->assertNull($distribution->last_error_message);
        $this->assertDatabaseHas('distribution_logs', [
            'event' => 'platform_web_receipt',
            'article_distribution_id' => (int) $distribution->id,
            'level' => 'info',
        ]);
    }

    public function test_failed_receipt_marks_distribution_failed_via_record_receipt(): void
    {
        [$distribution, , $response] = $this->browserReceiptFlow('2', [
            'outcome' => 'failed',
            'adapter_version' => '0.1.0',
            'target_origin' => 'https://mp.toutiao.com',
            'finished_at' => now()->toIso8601String(),
            'error_code' => 'login_required',
        ]);

        $response->assertJsonPath('data.publication.status', ManualPublication::STATUS_FAILED);

        $distribution = $distribution->fresh();
        $this->assertSame('failed', (string) $distribution->status);
        $this->assertSame('login_required', (string) $distribution->last_error_message);
        $this->assertNull($distribution->remote_url);
        $this->assertDatabaseHas('distribution_logs', [
            'event' => 'platform_web_receipt',
            'article_distribution_id' => (int) $distribution->id,
            'level' => 'error',
        ]);
    }

    public function test_bridge_failure_does_not_break_receipt(): void
    {
        Log::spy();
        $this->partialMock(PlatformWebDistributionBridge::class, function ($mock): void {
            $mock->shouldReceive('handleReceipt')->andThrow(new \RuntimeException('bridge exploded'));
        });
        [$distribution, $publication, $response] = $this->browserReceiptFlow('3', [
            'outcome' => 'completed',
            'completion_url' => 'https://mp.toutiao.com/profile_v4/graph/articles/detail/10',
            'adapter_version' => '0.1.0',
            'target_origin' => 'https://mp.toutiao.com',
            'started_at' => now()->subMinute()->toIso8601String(),
            'finished_at' => now()->toIso8601String(),
        ]);

        $response->assertOk()
            ->assertJsonPath('data.publication.status', ManualPublication::STATUS_COMPLETED);
        $this->assertSame(
            ManualPublication::STATUS_COMPLETED,
            (string) $publication->fresh()->status,
        );
        Log::shouldHaveReceived('warning')->once()->withArgs(
            function (string $message, array $context) use ($publication): bool {
                return str_contains($message, 'platform_web 回执桥接失败')
                    && (int) ($context['manual_publication_id'] ?? 0) === (int) $publication->id
                    && ($context['error'] ?? '') === 'RuntimeException: bridge exploded';
            }
        );

        $distribution = $distribution->fresh();
        $this->assertSame('awaiting_extension', (string) $distribution->status);
        $this->assertDatabaseMissing('distribution_logs', [
            'event' => 'platform_web_receipt',
            'article_distribution_id' => (int) $distribution->id,
        ]);
        $this->app->forgetInstance(PlatformWebDistributionBridge::class);
        $this->artisan('geoflow:reconcile-platform-web')->expectsOutputToContain('Reconciled 1')->assertSuccessful();
        $this->assertSame('synced', $distribution->fresh()->status);
        $this->artisan('geoflow:reconcile-platform-web')->expectsOutputToContain('Reconciled 0')->assertSuccessful();
        $this->assertSame(1, DistributionLog::where('event', 'platform_web_receipt')->count());
    }

    /**
     * 驱动真实回执链路：分发 → awaiting_extension → 扩展领取 → 提交回执。
     *
     * @param  array<string, mixed>  $receiptPayload  回执字段（revision 按 claim 后版本自动回填）
     * @return array{0: ArticleDistribution, 1: ManualPublication, 2: TestResponse}
     */
    private function browserReceiptFlow(string $keySuffix, array $receiptPayload): array
    {
        [$article, $channel] = $this->fixtures('first');
        $orchestrator = app(DistributionOrchestrator::class);
        $ids = $orchestrator->enqueueForArticle($article);
        $orchestrator->process(ArticleDistribution::query()->findOrFail($ids[0]));
        $distribution = ArticleDistribution::query()->findOrFail($ids[0]);
        $publication = ManualPublication::query()
            ->where('source_distribution_id', (int) $distribution->id)
            ->firstOrFail();
        $admin = Admin::query()->findOrFail((int) $publication->assigned_admin_id);
        $token = $admin->createToken('Receipt Chrome', [
            'browser-operations:read', 'browser-operations:execute',
        ])->plainTextToken;

        $this->withHeaders($this->extensionHeaders($token) + [
            'X-Idempotency-Key' => 'receipt-bridge-claim-'.$keySuffix,
        ])->postJson('/api/v1/manual-publications/'.$publication->id.'/claim', [
            'revision' => (int) $publication->revision,
        ])->assertOk()
            ->assertJsonPath('data.publication.status', ManualPublication::STATUS_IN_PROGRESS);

        $response = $this->withHeaders($this->extensionHeaders($token) + [
            'X-Idempotency-Key' => 'receipt-bridge-receipt-'.$keySuffix,
        ])->postJson('/api/v1/manual-publications/'.$publication->id.'/receipt', array_replace($receiptPayload, [
            'revision' => (int) $publication->revision + 1,
        ]));

        return [$distribution, $publication, $response];
    }

    /** @return array<string,string> */
    private function extensionHeaders(string $plainToken): array
    {
        return [
            'X-GEOFlow-Browser-Protocol' => '1',
            'X-GEOFlow-Client-Version' => '0.1.0',
            'Authorization' => 'Bearer '.$plainToken,
        ];
    }

    public function test_source_link_extra_flows_when_channel_opts_in(): void
    {
        [$article, $channelA] = $this->fixtures('first', ['append_source_link' => true]);
        $admin = Admin::query()->firstOrFail();
        // 不同平台（sohu）避免触发同平台严禁重发守卫；两个渠道各自绑定对应平台账号。
        $accountB = $this->createAccount($admin, '无来源链接账号', ManualPublicationAccount::PLATFORM_SOHU);
        $channelB = $this->createPlatformWebChannel($admin, $accountB, 'Channel B', [
            'platform' => 'sohu',
            'append_source_link' => false,
        ]);
        $article->task->distributionChannels()->attach($channelB->id, [
            'trigger' => 'after_local_publish',
            'remote_status' => 'follow_local',
            'failure_policy' => 'ignore_distribution_failure',
            'max_attempts' => 3,
            'sort_order' => 2,
        ]);

        $orchestrator = app(DistributionOrchestrator::class);
        $ids = $orchestrator->enqueueForArticleTargets($article->fresh(), [$channelA->id, $channelB->id], []);
        $orchestrator->process(ArticleDistribution::query()->findOrFail($ids[0]));
        $orchestrator->process(ArticleDistribution::query()->findOrFail($ids[1]));

        $publicationA = ManualPublication::query()
            ->where('source_distribution_id', (int) $ids[0])
            ->firstOrFail();
        $publicationB = ManualPublication::query()
            ->where('source_distribution_id', (int) $ids[1])
            ->firstOrFail();

        $this->assertTrue((bool) ($publicationA->publication_payload['append_source_link'] ?? false));
        $this->assertSame(
            'http://localhost/article/'.$article->slug,
            (string) ($publicationA->publication_payload['source_url'] ?? ''),
        );
        $this->assertFalse((bool) ($publicationB->publication_payload['append_source_link'] ?? true));
        $this->assertNull($publicationB->publication_payload['source_url'] ?? null);
    }

    public function test_publisher_reopens_failed_work_order_on_retry(): void
    {
        [$article, $channel] = $this->fixtures('first');
        $orchestrator = app(DistributionOrchestrator::class);
        $ids = $orchestrator->enqueueForArticle($article);
        $orchestrator->process(ArticleDistribution::query()->findOrFail($ids[0]));
        $distribution = ArticleDistribution::query()->findOrFail($ids[0]);
        $publication = ManualPublication::query()
            ->where('source_distribution_id', (int) $distribution->id)
            ->firstOrFail();
        $revisionBefore = (int) $publication->revision;
        $publication->forceFill(['status' => 'failed', 'result_note' => 'extension failed once'])->save();
        $distribution->forceFill(['status' => 'queued'])->save();

        $orchestrator->process($distribution->fresh());

        $publication = $publication->fresh();
        $this->assertSame('ready', (string) $publication->status);
        $this->assertSame($revisionBefore + 1, (int) $publication->revision);
        $this->assertSame('awaiting_extension', (string) $distribution->fresh()->status);
    }

    public function test_publisher_health_reflects_account_state(): void
    {
        [$article, $channel] = $this->fixtures('first');
        $publisher = app(DistributionPublisherManager::class)->forChannel($channel);
        $account = ManualPublicationAccount::query()->firstOrFail();

        $health = $publisher->health($channel);
        $this->assertTrue($health['healthy']);
        $this->assertSame('ok', $health['status']);
        $this->assertSame((int) $account->id, (int) $health['account_id']);

        $account->update(['is_active' => false]);
        $health = $publisher->health($channel->fresh());
        $this->assertFalse($health['healthy']);
        $this->assertSame('account_missing', $health['status']);
    }

    public function test_disabled_config_throws(): void
    {
        [$article, $channel] = $this->fixtures('first');
        $orchestrator = app(DistributionOrchestrator::class);
        $ids = $orchestrator->enqueueForArticle($article);
        $distribution = ArticleDistribution::query()->findOrFail($ids[0]);
        config(['geoflow.platform_web.enabled' => false]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('platform_web 分发未启用。');
        app(DistributionPublisherManager::class)->forChannel($channel)->publish($distribution, []);
    }

    public function test_platform_web_can_be_created_and_configured_from_normal_admin_forms(): void
    {
        [, $channel] = $this->fixtures('form');
        $admin = Admin::findOrFail($channel->created_by_admin_id);
        $account = ManualPublicationAccount::findOrFail($channel->resolvedPlatformWebConfig()['manual_publication_account_id']);
        $this->actingAs($admin, 'admin')->get(route('admin.distribution.create'))->assertOk()->assertSee('人工辅助发布')->assertSee($account->account_name);
        $payload = ['name' => '辅助发布渠道', 'channel_type' => 'platform_web', 'platform' => 'toutiao',
            'manual_publication_account_id' => $account->id, 'status' => 'active', 'append_source_link' => '1'];
        $this->post(route('admin.distribution.store'), $payload)->assertRedirect()->assertSessionHasNoErrors();
        $created = DistributionChannel::where('name', '辅助发布渠道')->firstOrFail();
        $this->assertTrue($created->isPlatformWeb());
        $this->assertSame('https://mp.toutiao.com/profile_v4/graph/articles/publish', $created->resolvedPlatformWebConfig()['editor_url']);
        $this->assertDatabaseMissing('distribution_channel_secrets', ['distribution_channel_id' => $created->id]);
        $this->get(route('admin.distribution.edit', ['channelId' => $created->id]))->assertOk()->assertSee('data-platform-web-config', false);
        $this->put(route('admin.distribution.update', ['channelId' => $created->id]), $payload + ['editor_url' => 'https://mp.toutiao.com/profile_v4/graph/articles/edit'])
            ->assertRedirect()->assertSessionHasNoErrors();
        $this->assertStringEndsWith('/edit', $created->fresh()->resolvedPlatformWebConfig()['editor_url']);
    }

    public function test_platform_configuration_rejects_wrong_or_disabled_accounts_and_unsafe_editor_urls(): void
    {
        [, $channel] = $this->fixtures('invalid');
        $admin = Admin::findOrFail($channel->created_by_admin_id);
        $account = ManualPublicationAccount::findOrFail($channel->resolvedPlatformWebConfig()['manual_publication_account_id']);
        $this->actingAs($admin, 'admin');
        $payload = ['name' => 'Invalid channel', 'channel_type' => 'platform_web', 'platform' => 'sohu', 'manual_publication_account_id' => $account->id, 'status' => 'active'];
        $this->post(route('admin.distribution.store'), $payload)->assertSessionHasErrors('manual_publication_account_id');
        $payload['platform'] = 'toutiao';
        foreach (['https://evil.test/a', 'http://mp.toutiao.com/profile_v4/graph/articles/publish', 'https://mp.toutiao.com.evil.test/profile_v4/graph/articles/publish', 'https://mp.toutiao.com/login'] as $url) {
            $this->post(route('admin.distribution.store'), $payload + ['editor_url' => $url])->assertSessionHasErrors('editor_url');
        }
        $account->update(['is_active' => false]);
        $this->post(route('admin.distribution.store'), $payload)->assertSessionHasErrors('manual_publication_account_id');
        $account->update(['is_active' => true]);
        $account->persona->update(['is_active' => false]);
        $this->post(route('admin.distribution.store'), $payload)->assertSessionHasErrors('manual_publication_account_id');
        $admin->update(['role' => 'admin']);
        $this->actingAs($admin->fresh(), 'admin')->post(route('admin.distribution.store'), $payload)->assertForbidden();
    }

    public function test_inactive_channel_creator_cannot_create_a_work_order(): void
    {
        [$article, $channel] = $this->fixtures('inactive-creator');
        $orchestrator = app(DistributionOrchestrator::class);
        $ids = $orchestrator->enqueueForArticle($article);
        Admin::findOrFail($channel->created_by_admin_id)->update(['status' => 'inactive']);
        try {
            $orchestrator->process(ArticleDistribution::findOrFail($ids[0]));
            $this->fail('An inactive creator must not create work orders.');
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString('创建管理员', $exception->getMessage());
        }
        $this->assertDatabaseCount('manual_publications', 0);
    }

    public function test_demoted_channel_creator_cannot_publish_a_new_work_order(): void
    {
        [$article, $channel] = $this->fixtures('demoted-publisher');
        $orchestrator = app(DistributionOrchestrator::class);
        $ids = $orchestrator->enqueueForArticle($article);
        Admin::findOrFail($channel->created_by_admin_id)->update(['role' => 'admin']);
        try {
            $orchestrator->process(ArticleDistribution::findOrFail($ids[0]));
            $this->fail('A demoted creator must not create work orders.');
        } catch (ApiException $exception) {
            $this->assertSame('publication_unavailable', $exception->getErrorCode());
        }
        $this->assertDatabaseCount('manual_publications', 0);
    }

    public function test_active_platform_accounts_are_shared_instance_management_resources(): void
    {
        [, $channel] = $this->fixtures('shared-account');
        $other = Admin::create(['username' => 'other-platform-manager', 'password' => 'test', 'role' => 'super_admin', 'status' => 'active']);
        $this->actingAs($other, 'admin')->post(route('admin.distribution.store'), [
            'name' => 'Shared account channel', 'channel_type' => 'platform_web', 'platform' => 'toutiao',
            'manual_publication_account_id' => $channel->resolvedPlatformWebConfig()['manual_publication_account_id'], 'status' => 'active',
        ])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame((int) $other->id, (int) DistributionChannel::where('name', 'Shared account channel')->sole()->created_by_admin_id);
    }

    public function test_existing_delivery_prevents_platform_identity_change(): void
    {
        [$article, $channel] = $this->fixtures('identity');
        $orchestrator = app(DistributionOrchestrator::class);
        $orchestrator->enqueueForArticle($article);
        $admin = Admin::findOrFail($channel->created_by_admin_id);
        $account = $this->createAccount($admin, '搜狐账号', ManualPublicationAccount::PLATFORM_SOHU);
        $this->actingAs($admin, 'admin')->put(route('admin.distribution.update', ['channelId' => $channel->id]), [
            'name' => $channel->name, 'channel_type' => 'platform_web', 'platform' => 'sohu',
            'manual_publication_account_id' => $account->id, 'status' => 'active',
        ])->assertSessionHasErrors('platform');
        $this->assertSame('toutiao', $channel->fresh()->resolvedPlatformWebConfig()['platform']);
    }

    public function test_late_completed_receipt_preserves_external_identity_without_reviving_cancelled_delivery(): void
    {
        [$article, $channel] = $this->fixtures('late-fact');
        $orchestrator = app(DistributionOrchestrator::class);
        $ids = $orchestrator->enqueueForArticle($article);
        $orchestrator->process(ArticleDistribution::findOrFail($ids[0]));
        $publication = ManualPublication::where('source_distribution_id', $ids[0])->firstOrFail();
        $publication->update(['status' => 'completed', 'completion_url' => 'https://mp.toutiao.com/known-result']);
        ArticleDistribution::whereKey($ids[0])->update(['status' => 'failed', 'last_error_message' => 'Task deleted']);
        $channel->update(['status' => 'deleting']);
        $this->artisan('geoflow:reconcile-platform-web')->assertSuccessful();
        $distribution = ArticleDistribution::findOrFail($ids[0]);
        $this->assertSame('failed', $distribution->status);
        $this->assertSame('Task deleted', $distribution->last_error_message);
        $this->assertSame('https://mp.toutiao.com/known-result', $distribution->remote_url);
        $this->assertSame((string) $publication->id, $distribution->remote_id);
        $this->assertSame('completed', $publication->fresh()->status);
        $admin = Admin::findOrFail($channel->created_by_admin_id);
        $otherChannel = $this->createPlatformWebChannel($admin, ManualPublicationAccount::findOrFail($publication->account_id), 'Replacement channel');
        $article->task->distributionChannels()->attach($otherChannel->id);
        $this->assertSame([], $orchestrator->enqueueForArticleTargets($article->fresh(), [$otherChannel->id], []));
    }

    public function test_failed_pending_distribution_save_rolls_back_local_work_order_without_external_ack_identity(): void
    {
        [$article] = $this->fixtures('atomic-pending');
        $orchestrator = app(DistributionOrchestrator::class);
        $ids = $orchestrator->enqueueForArticle($article);
        $dispatcher = ArticleDistribution::getEventDispatcher();
        ArticleDistribution::setEventDispatcher(clone $dispatcher);
        $failed = false;
        ArticleDistribution::saving(function (ArticleDistribution $row) use (&$failed): void {
            if ($row->status === 'awaiting_extension' && ! $failed) {
                $failed = true;
                throw new \RuntimeException('injected_local_save_failure');
            }
        });
        try {
            try {
                $orchestrator->process(ArticleDistribution::findOrFail($ids[0]));
                $this->fail('The local persistence fault must escape.');
            } catch (\RuntimeException $exception) {
                $this->assertSame('injected_local_save_failure', $exception->getMessage());
            }
        } finally {
            ArticleDistribution::setEventDispatcher($dispatcher);
        }
        $this->assertDatabaseCount('manual_publications', 0);
        $distribution = ArticleDistribution::findOrFail($ids[0]);
        $this->assertNull($distribution->remote_id);
        $this->assertNotSame('outcome_unknown', $distribution->status);
    }

    public function test_manual_work_order_uses_immutable_distribution_content_and_title(): void
    {
        [$article] = $this->fixtures('snapshot');
        $orchestrator = app(DistributionOrchestrator::class);
        $ids = $orchestrator->enqueueForArticle($article);
        $originalTitle = $article->title;
        $article->update(['title' => 'Live changed title', 'content' => 'Changed live content']);
        $orchestrator->process(ArticleDistribution::findOrFail($ids[0]));
        $publication = ManualPublication::where('source_distribution_id', $ids[0])->firstOrFail();
        $this->assertSame($originalTitle, $publication->publication_payload['title']);
        $this->assertStringContainsString('PLATFORMWEB-MARKER', $publication->content);
        $this->assertStringNotContainsString('Changed live', $publication->content);
    }

    public function test_claimed_order_cancelled_by_channel_deletion_becomes_unknown_with_new_revision(): void
    {
        [$article, $channel] = $this->fixtures('cancel-claimed');
        $orchestrator = app(DistributionOrchestrator::class);
        $ids = $orchestrator->enqueueForArticle($article);
        $orchestrator->process(ArticleDistribution::findOrFail($ids[0]));
        $publication = ManualPublication::where('source_distribution_id', $ids[0])->firstOrFail();
        $admin = Admin::findOrFail($channel->created_by_admin_id);
        $token = $admin->createToken('claim', ['browser-operations:read', 'browser-operations:execute']);
        $browser = app(ManualPublicationBrowserService::class);
        $publication = $browser->claim($admin, $token->accessToken->id, $publication->id, $publication->revision);
        $revision = $publication->revision;
        app(DistributionChannelDeletionService::class)->prepare($channel);
        $this->assertSame(ManualPublication::STATUS_OUTCOME_UNKNOWN, $publication->fresh()->status);
        $this->assertSame($revision + 1, $publication->fresh()->revision);
        $this->assertNull($publication->fresh()->browser_claimed_by_token_id);
        $this->assertSame('outcome_unknown', ArticleDistribution::findOrFail($ids[0])->status);
        $this->withHeaders($this->extensionHeaders($token->plainTextToken) + ['X-Idempotency-Key' => 'cancelled-old-receipt'])
            ->postJson('/api/v1/manual-publications/'.$publication->id.'/receipt', [
                'revision' => $revision, 'outcome' => 'completed', 'completion_url' => 'https://mp.toutiao.com/a',
                'adapter_version' => '0.1.0', 'target_origin' => 'https://mp.toutiao.com', 'finished_at' => now()->toIso8601String(),
            ])->assertConflict();
    }

    /**
     * @param  array<string, mixed>  $channelConfigOverrides
     * @return array{Article, DistributionChannel}
     */
    private function guardedWorkOrder(string $suffix): array
    {
        [$article, $channel] = $this->fixtures($suffix);
        $orchestrator = app(DistributionOrchestrator::class);
        $ids = $orchestrator->enqueueForArticle($article);
        $orchestrator->process(ArticleDistribution::findOrFail($ids[0]));
        $publication = ManualPublication::where('source_distribution_id', $ids[0])->sole();
        $admin = Admin::findOrFail($publication->assigned_admin_id);
        $token = $admin->createToken('guarded', ['browser-operations:read', 'browser-operations:execute']);

        return [$article, $channel, $publication, $admin, $token->accessToken->id, ArticleDistribution::findOrFail($ids[0])];
    }

    public function test_linked_work_order_rejects_generic_rebinding_and_reopen(): void
    {
        [$article, , $publication, $admin] = $this->guardedWorkOrder('immutable-admin');
        $this->actingAs($admin, 'admin')->put(route('admin.manual-publications.update', ['manualPublicationId' => $publication->id]), [
            'revision' => $publication->revision, 'type' => 'post', 'article_id' => $article->id,
            'persona_id' => $publication->persona_id, 'account_id' => $publication->account_id,
            'assigned_admin_id' => $admin->id, 'platform' => 'sohu', 'content' => 'Changed', 'target_url' => 'https://mp.sohu.com/suc-ce/article/publish',
        ])->assertForbidden();
        $this->assertSame('toutiao', $publication->fresh()->platform);
        $publication->update(['status' => 'failed']);
        $this->expectException(\DomainException::class);
        app(ManualPublicationService::class)->transition($publication->fresh(), 'ready', $publication->revision, $admin);
    }

    public function test_linked_claim_rejects_hold_and_cancelled_distribution(): void
    {
        foreach (['hold', 'cancelled'] as $state) {
            [$article, , $publication, $admin, $tokenId, $distribution] = $this->guardedWorkOrder('fenced-'.$state);
            if ($state === 'hold') {
                app(ArticleWorkflowTransitionService::class)->humanAction($article, 'hold', $admin->id, expectedVersion: $article->workflow_version);
            } else {
                $distribution->update(['status' => 'cancelled']);
            }
            try {
                app(ManualPublicationBrowserService::class)->claim($admin, $tokenId, $publication->id, $publication->revision);
                $this->fail('Superseded distribution must reject a fresh claim.');
            } catch (ApiException $exception) {
                $this->assertSame('publication_source_superseded', $exception->getErrorCode());
            }
            $this->assertSame('ready', $publication->fresh()->status);
        }
    }

    public function test_controlled_reenqueue_refreshes_unclaimed_work_after_hold_is_released(): void
    {
        [$article, , $publication, $admin, $tokenId, $distribution] = $this->guardedWorkOrder('renew-fence');
        $workflow = app(ArticleWorkflowTransitionService::class);
        $article = $workflow->humanAction($article, 'private', $admin->id, expectedVersion: $article->workflow_version);
        $article = $workflow->humanAction($article, 'publish', $admin->id, expectedVersion: $article->workflow_version);
        $ids = app(DistributionOrchestrator::class)->enqueueForArticle($article->fresh());
        $this->assertSame('queued', $distribution->fresh()->status);
        app(DistributionOrchestrator::class)->process($distribution->fresh());
        $publication->refresh();
        $claimed = app(ManualPublicationBrowserService::class)->claim($admin, $tokenId, $publication->id, $publication->revision);
        $this->assertSame('in_progress', $claimed->status);
        $this->assertDatabaseCount('manual_publications', 1);
    }

    public function test_explicit_unpublished_confirmation_reauthorizes_failed_and_unknown_attempts(): void
    {
        foreach (['failed', 'outcome_unknown'] as $outcome) {
            [$article, , $publication, $admin, $tokenId, $distribution] = $this->guardedWorkOrder('confirm-'.$outcome);
            $browser = app(ManualPublicationBrowserService::class);
            $claimed = $browser->claim($admin, $tokenId, $publication->id, $publication->revision);
            $finished = $browser->recordReceipt($admin, $tokenId, $publication->id, $claimed->revision, [
                'outcome' => $outcome, 'target_origin' => 'https://mp.toutiao.com',
            ], '0.1.0');
            $revision = $finished->revision;
            $url = route('admin.distribution.retry', ['distributionId' => $distribution->id]);
            $this->actingAs($admin, 'admin')->post($url)->assertSessionHasErrors();
            $this->post($url, ['confirm_unpublished' => '确认未发布', 'publication_revision' => $revision - 1])->assertSessionHasErrors();
            $this->assertSame($outcome, $publication->fresh()->status);
            $this->post($url, ['confirm_unpublished' => '确认未发布', 'publication_revision' => $revision])->assertSessionHasNoErrors();
            $ready = $publication->fresh();
            $this->assertSame('ready', $ready->status);
            $this->assertSame($revision + 1, $ready->revision);
            $this->assertSame($outcome, data_get($ready->execution_receipt, 'previous_attempts.0.receipt.outcome'));
            $this->assertSame(1, $ready->transitions()->where('to_status', 'in_progress')->count());
            $this->post($url, ['confirm_unpublished' => '确认未发布', 'publication_revision' => $ready->revision])->assertSessionHasErrors();
            try {
                $browser->recordReceipt($admin, $tokenId, $publication->id, $claimed->revision, ['outcome' => 'failed'], '0.1.0');
                $this->fail('An old receipt must not finalize the authorized revision.');
            } catch (ApiException $exception) {
                $this->assertSame('revision_conflict', $exception->getErrorCode());
            }
            $next = $browser->claim($admin, $tokenId, $publication->id, $ready->revision);
            $this->assertSame('in_progress', $next->status);
            $final = $browser->recordReceipt($admin, $tokenId, $publication->id, $next->revision, [
                'outcome' => 'completed', 'completion_url' => 'https://www.toutiao.com/article/123456789/',
                'target_origin' => 'https://mp.toutiao.com',
            ], '0.1.0');
            $this->assertSame('completed', $final->status);
            $this->assertSame($outcome, data_get($final->execution_receipt, 'previous_attempts.0.receipt.outcome'));
            $this->post($url, ['confirm_unpublished' => '确认未发布', 'publication_revision' => $final->revision])->assertSessionHasErrors();
            $this->assertDatabaseCount('manual_publications', $outcome === 'failed' ? 1 : 2);
        }
    }

    public function test_unpublished_retry_requires_current_actor_source_and_uncompleted_facts(): void
    {
        foreach (['unauthorized', 'inactive', 'held', 'stopped', 'account', 'completed', 'binding', 'deleted', 'leased'] as $case) {
            [$article, $channel, $publication, $admin, $tokenId, $distribution] = $this->guardedWorkOrder('reject-confirm-'.$case);
            $browser = app(ManualPublicationBrowserService::class);
            $claimed = $browser->claim($admin, $tokenId, $publication->id, $publication->revision);
            $finished = $browser->recordReceipt($admin, $tokenId, $publication->id, $claimed->revision, [
                'outcome' => $case === 'completed' ? 'completed' : 'failed',
                'completion_url' => $case === 'completed' ? 'https://www.toutiao.com/article/123456789/' : null,
                'target_origin' => 'https://mp.toutiao.com',
            ], '0.1.0');
            match ($case) {
                'unauthorized' => $admin->update(['role' => 'admin']),
                'inactive' => $admin->update(['status' => 'inactive']),
                'held' => app(ArticleWorkflowTransitionService::class)->humanAction($article, 'hold', $admin->id, expectedVersion: $article->workflow_version),
                'stopped' => $article->task->update(['status' => 'paused']),
                'account' => $publication->account->update(['is_active' => false]),
                'binding' => $publication->update(['content' => 'Tampered payload']),
                'deleted' => app(DistributionChannelDeletionService::class)->prepare($channel),
                'leased' => DistributionChannelOperation::create(['distribution_channel_id' => $channel->id, 'token' => (string) Str::uuid(), 'operation' => 'fixture', 'started_at' => now(), 'expires_at' => now()->addMinute()]),
                default => null,
            };
            $this->actingAs($admin->fresh(), 'admin');
            $response = $this->post(route('admin.distribution.retry', ['distributionId' => $distribution->id]), [
                'confirm_unpublished' => '确认未发布', 'publication_revision' => $finished->revision,
            ]);
            if (in_array($case, ['unauthorized', 'inactive'], true)) {
                $this->assertContains($response->status(), [302, 403]);
            } else {
                $response->assertSessionHasErrors();
            }
            $this->assertNotSame('ready', $publication->fresh()->status, $case);
        }
    }

    public function test_confirmed_retry_refreshes_payload_after_current_article_publication_is_restored(): void
    {
        [$article, , $publication, $admin, $tokenId, $distribution] = $this->guardedWorkOrder('retry-restored');
        $browser = app(ManualPublicationBrowserService::class);
        $claim = $browser->claim($admin, $tokenId, $publication->id, $publication->revision);
        $finished = $browser->recordReceipt($admin, $tokenId, $publication->id, $claim->revision, [
            'outcome' => 'failed', 'target_origin' => 'https://mp.toutiao.com',
        ], '0.1.0');
        $workflow = app(ArticleWorkflowTransitionService::class);
        $article = $workflow->humanAction($article, 'hold', $admin->id, expectedVersion: $article->workflow_version);
        $article->update(['content' => 'Current approved replacement body.']);
        $article = $workflow->humanAction($article, 'publish', $admin->id, expectedVersion: $article->workflow_version);
        $ready = $browser->confirmUnpublishedRetry($admin, $publication->id, $finished->revision);
        $this->assertSame('Current approved replacement body.', $ready->content);
        $this->assertStringContainsString('PLATFORMWEB-MARKER', (string) data_get($ready->execution_receipt, 'previous_attempts.0.publication_payload.body_plain'));
        $this->assertStringNotContainsString('replacement body', (string) data_get($ready->execution_receipt, 'previous_attempts.0.source_snapshot.content'));
        $this->assertSame($article->workflow_version, data_get($distribution->fresh()->remote_meta, 'workflow_fence.workflow_version'));
        $this->assertSame('in_progress', $browser->claim($admin, $tokenId, $ready->id, $ready->revision)->status);
    }

    public function test_demoted_channel_creator_blocks_confirmation_without_changing_failed_attempt(): void
    {
        [, , $publication, $creator, $tokenId, $distribution] = $this->guardedWorkOrder('demoted-confirm');
        $browser = app(ManualPublicationBrowserService::class);
        $claim = $browser->claim($creator, $tokenId, $publication->id, $publication->revision);
        $failed = $browser->recordReceipt($creator, $tokenId, $publication->id, $claim->revision, [
            'outcome' => 'failed', 'target_origin' => 'https://mp.toutiao.com',
        ], '0.1.0');
        $before = $failed->getAttributes();
        $creator->update(['role' => 'admin']);
        $confirmer = Admin::create(['username' => 'other-confirming-super', 'password' => 'test', 'role' => 'super_admin', 'status' => 'active']);
        $this->actingAs($confirmer, 'admin')->post(route('admin.distribution.retry', ['distributionId' => $distribution->id]), [
            'confirm_unpublished' => '确认未发布', 'publication_revision' => $failed->revision,
        ])->assertSessionHasErrors();
        $this->assertSame($before, $publication->fresh()->getAttributes());
        $this->assertSame('failed', $distribution->fresh()->status);
    }

    public function test_demoted_channel_creator_blocks_a_fresh_linked_browser_claim(): void
    {
        [, , $publication, $creator] = $this->guardedWorkOrder('demoted-ready');
        $creator->update(['role' => 'admin']);
        $operator = Admin::create(['username' => 'other-browser-super', 'password' => 'test', 'role' => 'super_admin', 'status' => 'active']);
        $token = $operator->createToken('Demoted creator guard', ['browser-operations:read', 'browser-operations:execute']);
        $this->withHeaders($this->extensionHeaders($token->plainTextToken) + ['X-Idempotency-Key' => 'demoted-ready-claim'])
            ->postJson('/api/v1/manual-publications/'.$publication->id.'/claim', ['revision' => $publication->revision])
            ->assertConflict();
        $this->assertSame('ready', $publication->fresh()->status);
        $this->assertSame($publication->revision, $publication->fresh()->revision);
        $this->assertNull($publication->fresh()->browser_claimed_by_token_id);
    }

    public function test_creator_demotion_after_claim_preserves_the_observed_completion_receipt(): void
    {
        [, , $publication, $creator, , $distribution] = $this->guardedWorkOrder('demoted-receipt');
        $operator = Admin::create(['username' => 'receipt-browser-super', 'password' => 'test', 'role' => 'super_admin', 'status' => 'active']);
        $token = $operator->createToken('Receipt facts', ['browser-operations:read', 'browser-operations:execute']);
        $this->withHeaders($this->extensionHeaders($token->plainTextToken) + ['X-Idempotency-Key' => 'before-creator-demotion'])
            ->postJson('/api/v1/manual-publications/'.$publication->id.'/claim', ['revision' => $publication->revision])->assertOk();
        $creator->update(['role' => 'admin']);
        $this->withHeaders($this->extensionHeaders($token->plainTextToken) + ['X-Idempotency-Key' => 'after-creator-demotion'])
            ->postJson('/api/v1/manual-publications/'.$publication->id.'/receipt', [
                'revision' => $publication->fresh()->revision, 'outcome' => 'completed',
                'completion_url' => 'https://www.toutiao.com/article/123456789/',
                'adapter_version' => '0.1.0', 'target_origin' => 'https://mp.toutiao.com', 'finished_at' => now()->toIso8601String(),
            ])->assertOk();
        $this->assertSame('completed', $publication->fresh()->status);
        $this->assertSame('synced', $distribution->fresh()->status);
    }

    public function test_executed_failed_work_order_blocks_replacement_same_platform_channels(): void
    {
        foreach (['failed', 'outcome_unknown'] as $outcome) {
            [$article, $channel, $publication, $admin, , $distribution] = $this->guardedWorkOrder('replacement-'.$outcome);
            $token = $admin->createToken('Actual failed execution', ['browser-operations:read', 'browser-operations:execute']);
            $this->withHeaders($this->extensionHeaders($token->plainTextToken) + ['X-Idempotency-Key' => 'replacement-claim-'.$outcome])
                ->postJson('/api/v1/manual-publications/'.$publication->id.'/claim', ['revision' => $publication->revision])->assertOk();
            $this->withHeaders($this->extensionHeaders($token->plainTextToken) + ['X-Idempotency-Key' => 'replacement-receipt-'.$outcome])
                ->postJson('/api/v1/manual-publications/'.$publication->id.'/receipt', [
                    'revision' => $publication->fresh()->revision, 'outcome' => $outcome,
                    'target_origin' => 'https://mp.toutiao.com', 'adapter_version' => '0.1.0', 'finished_at' => now()->toIso8601String(),
                ])->assertOk();
            if ($outcome === 'outcome_unknown') {
                $this->actingAs($admin, 'admin')->post(route('admin.manual-publications.transition', ['manualPublicationId' => $publication->id]), [
                    'revision' => $publication->fresh()->revision, 'target_status' => 'failed', 'result_note' => 'Needs human verification',
                ])->assertSessionHasNoErrors();
            }
            $second = $channel->replicate();
            $second->name = 'Replacement same platform';
            $second->save();
            $orchestrator = app(DistributionOrchestrator::class);
            $orchestrator->syncTaskChannels($article->task, [$second->id]);
            $this->assertSame([], $orchestrator->enqueueForArticle($article->fresh()));
            $this->assertSame(1, ManualPublication::where('article_id', $article->id)->count());
            $this->assertNotSame('ready', $publication->fresh()->status);
            $orchestrator->syncTaskChannels($article->task->fresh(), [$channel->id]);
            $ready = app(ManualPublicationBrowserService::class)->confirmUnpublishedRetry($admin, $publication->id, $publication->fresh()->revision);
            $this->assertSame($publication->id, $ready->id);
            $this->assertSame($outcome, data_get($ready->execution_receipt, 'previous_attempts.0.receipt.outcome'));
        }
    }

    public function test_deleting_unknown_receipts_do_not_starve_new_completions(): void
    {
        [, $oldChannel, $old, $oldAdmin, $oldToken, $oldDistribution] = $this->guardedWorkOrder('deleting-unknown-old');
        $browser = app(ManualPublicationBrowserService::class);
        $browser->claim($oldAdmin, $oldToken, $old->id, $old->revision);
        app(DistributionChannelDeletionService::class)->prepare($oldChannel);
        $oldState = $oldDistribution->fresh()->getAttributes();
        [, , $new, $newAdmin, $newToken, $newDistribution] = $this->guardedWorkOrder('deleting-new-completion');
        $claim = $browser->claim($newAdmin, $newToken, $new->id, $new->revision);
        $browser->recordReceipt($newAdmin, $newToken, $new->id, $claim->revision, [
            'outcome' => 'completed', 'completion_url' => 'https://www.toutiao.com/article/123456789/', 'target_origin' => 'https://mp.toutiao.com',
        ], '0.1.0');
        $newDistribution->refresh()->forceFill(['status' => 'awaiting_extension', 'remote_meta' => array_diff_key($newDistribution->remote_meta, ['platform_web_receipt_revision' => true])])->save();
        $bridge = app(PlatformWebDistributionBridge::class);
        $this->assertSame(1, $bridge->reconcile(1));
        $this->assertSame('synced', $newDistribution->fresh()->status);
        $logCount = DistributionLog::count();
        $this->assertSame(0, $bridge->reconcile(1));
        $this->assertSame($logCount, DistributionLog::count());
        $this->assertSame($oldState, $oldDistribution->fresh()->getAttributes());
    }

    public function test_markdown_copy_is_plain_for_creation_safe_refresh_and_confirmed_retry(): void
    {
        [$article] = $this->fixtures('plain-copy');
        $fixture = json_decode(file_get_contents(base_path('tests/Fixtures/platform-web/clipboard.json')), true, flags: JSON_THROW_ON_ERROR);
        $article->update(['content' => $fixture['markdown']]);
        $orchestrator = app(DistributionOrchestrator::class);
        $ids = $orchestrator->enqueueForArticle($article->fresh());
        $distribution = ArticleDistribution::findOrFail($ids[0]);
        $orchestrator->process($distribution);
        $publication = ManualPublication::where('source_distribution_id', $distribution->id)->sole();
        $this->assertSame($fixture['plain'], $publication->publication_payload['body_plain']);
        $this->assertSame($fixture['markdown'], $publication->source_snapshot['content']);
        $article->update(['content' => str_replace('Heading', 'Refreshed heading', $fixture['markdown'])]);
        $orchestrator->enqueueForArticle($article->fresh());
        $orchestrator->process($distribution->fresh());
        $publication->refresh();
        $this->assertSame(str_replace('Heading', 'Refreshed heading', $fixture['plain']), $publication->publication_payload['body_plain']);
        $admin = Admin::findOrFail($publication->assigned_admin_id);
        $token = $admin->createToken('Plain text confirmed retry', ['browser-operations:read', 'browser-operations:execute']);
        $browser = app(ManualPublicationBrowserService::class);
        $claim = $browser->claim($admin, $token->accessToken->id, $publication->id, $publication->revision);
        $failed = $browser->recordReceipt($admin, $token->accessToken->id, $publication->id, $claim->revision,
            ['outcome' => 'failed', 'target_origin' => 'https://mp.toutiao.com'], '0.1.0');
        $article->update(['content' => str_replace('Heading', 'Final heading', $fixture['markdown'])]);
        $this->actingAs($admin, 'admin')->post(route('admin.distribution.retry', ['distributionId' => $distribution->id]), [
            'confirm_unpublished' => '确认未发布', 'publication_revision' => $failed->revision,
        ])->assertSessionHasNoErrors();
        $publication->refresh();
        $this->assertSame(str_replace('Heading', 'Final heading', $fixture['plain']), $publication->publication_payload['body_plain']);
        $this->assertSame(str_replace('Heading', 'Final heading', $fixture['markdown']), $publication->source_snapshot['content']);
    }

    public function test_already_queued_replacement_publisher_keeps_the_original_execution_history(): void
    {
        [$article, $firstChannel] = $this->fixtures('queued-replacement');
        $second = $firstChannel->replicate();
        $second->name = 'Queued replacement';
        $second->save();
        $orchestrator = app(DistributionOrchestrator::class);
        $orchestrator->syncTaskChannels($article->task, [$firstChannel->id, $second->id]);
        $ids = $orchestrator->enqueueForArticle($article->fresh());
        $this->assertCount(2, $ids);
        $first = ArticleDistribution::whereIn('id', $ids)->where('distribution_channel_id', $firstChannel->id)->sole();
        $orchestrator->process($first);
        $publication = ManualPublication::where('source_distribution_id', $first->id)->sole();
        $admin = Admin::findOrFail($publication->assigned_admin_id);
        $token = $admin->createToken('Queued replacement guard', ['browser-operations:read', 'browser-operations:execute']);
        $browser = app(ManualPublicationBrowserService::class);
        $claim = $browser->claim($admin, $token->accessToken->id, $publication->id, $publication->revision);
        $browser->recordReceipt($admin, $token->accessToken->id, $publication->id, $claim->revision,
            ['outcome' => 'failed', 'target_origin' => 'https://mp.toutiao.com'], '0.1.0');
        try {
            $orchestrator->process(ArticleDistribution::whereIn('id', $ids)->where('distribution_channel_id', $second->id)->sole());
            $this->fail('An already queued replacement must also preserve the first execution history.');
        } catch (PlatformPublishBlockedException) {
            $this->assertSame(1, ManualPublication::where('article_id', $article->id)->count());
        }
    }

    public function test_never_claimed_cancelled_work_can_use_a_replacement_channel(): void
    {
        [$article, $channel, $publication, $admin] = $this->guardedWorkOrder('unclaimed-replacement');
        $cancelled = app(ManualPublicationService::class)->transition($publication, 'cancelled', $publication->revision, $admin);
        app(PlatformWebDistributionBridge::class)->handleReceipt($cancelled);
        $second = $channel->replicate();
        $second->name = 'Safe replacement';
        $second->save();
        $orchestrator = app(DistributionOrchestrator::class);
        $orchestrator->syncTaskChannels($article->task, [$second->id]);
        $ids = $orchestrator->enqueueForArticle($article->fresh());
        $this->assertCount(1, $ids);
        $orchestrator->process(ArticleDistribution::findOrFail($ids[0]));
        $this->assertSame('ready', ManualPublication::where('source_distribution_id', $ids[0])->sole()->status);
    }

    private function fixtures(string $suffix, array $channelConfigOverrides = []): array
    {
        $admin = Admin::query()->create([
            'username' => 'platform-web-admin-'.uniqid(),
            'password' => 'secret-123',
            'email' => uniqid('platform-web-').'@example.test',
            'display_name' => 'Platform Web Admin',
            'role' => 'super_admin',
            'status' => 'active',
        ]);
        $task = Task::query()->create([
            'name' => 'Platform web task',
            'status' => 'active',
            'publish_scope' => 'distribution_only',
            'distribution_strategy' => 'broadcast',
        ]);
        $account = $this->createAccount($admin, 'GEOFlow 头条账号');
        $channel = $this->createPlatformWebChannel($admin, $account, '头条渠道', $channelConfigOverrides);
        $task->distributionChannels()->attach($channel->id, [
            'trigger' => 'after_local_publish',
            'remote_status' => 'follow_local',
            'failure_policy' => 'ignore_distribution_failure',
            'max_attempts' => 3,
            'sort_order' => 1,
        ]);
        $article = $this->createArticle($task, $suffix);

        return [$article, $channel];
    }

    private function createAccount(Admin $admin, string $name, string $platform = ManualPublicationAccount::PLATFORM_TOUTIAO): ManualPublicationAccount
    {
        $persona = ManualPublicationPersona::query()->create([
            'name' => 'GEOFlow 专家',
            'tone' => '专业',
            'domain' => 'GEO',
            'disclosure_text' => '本账号代表 GEOFlow 团队。',
            'created_by_admin_id' => $admin->getKey(),
        ]);

        return ManualPublicationAccount::query()->create([
            'persona_id' => $persona->getKey(),
            'platform' => $platform,
            'account_name' => $name,
            'profile_url' => 'https://mp.toutiao.com/profile/geoflow',
            'created_by_admin_id' => $admin->getKey(),
        ]);
    }

    /**
     * @param  array<string, mixed>  $configOverrides
     */
    private function createPlatformWebChannel(
        Admin $admin,
        ManualPublicationAccount $account,
        string $name,
        array $configOverrides = [],
    ): DistributionChannel {
        return DistributionChannel::query()->create([
            'name' => $name,
            'domain' => 'mp.toutiao.com',
            'endpoint_url' => 'https://mp.toutiao.com',
            'channel_type' => DistributionChannel::TYPE_PLATFORM_WEB,
            'channel_config' => array_replace([
                'platform' => 'toutiao',
                'manual_publication_account_id' => (int) $account->id,
                'append_source_link' => false,
            ], $configOverrides),
            'status' => DistributionChannel::STATUS_ACTIVE,
            'created_by_admin_id' => $admin->getKey(),
        ]);
    }

    private function createArticle(Task $task, string $suffix): Article
    {
        $category = Category::query()->firstOrCreate(
            ['slug' => 'platform-web-flow'],
            ['name' => 'Platform Web Flow', 'description' => '', 'sort_order' => 0]
        );
        $author = Author::query()->firstOrCreate(
            ['email' => 'platform-web-flow@example.test'],
            ['name' => 'Platform Web Flow Author', 'bio' => '', 'avatar' => '', 'website' => '']
        );

        return Article::query()->create([
            'title' => 'Platform web '.$suffix,
            'slug' => 'platform-web-flow-'.$suffix.'-'.uniqid(),
            'content' => "PLATFORMWEB-MARKER 正文第一段\n\n第二段纯文本内容",
            'category_id' => $category->id,
            'author_id' => $author->id,
            'task_id' => $task->id,
            'status' => 'private',
            'review_status' => 'approved',
        ]);
    }
}
