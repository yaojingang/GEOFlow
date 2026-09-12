<?php

namespace Tests\Feature;

use App\Exceptions\PlatformPublishBlockedException;
use App\Models\Admin;
use App\Models\Article;
use App\Models\ArticleDistribution;
use App\Models\Author;
use App\Models\Category;
use App\Models\DistributionChannel;
use App\Models\ManualPublication;
use App\Models\ManualPublicationAccount;
use App\Models\ManualPublicationPersona;
use App\Models\Task;
use App\Services\GeoFlow\Distribution\PlatformWeb\PlatformWebDistributionBridge;
use App\Services\GeoFlow\DistributionChannelDeletionService;
use App\Services\GeoFlow\DistributionOrchestrator;
use App\Services\GeoFlow\DistributionPublisherManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
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
    {        [$article, $channelA] = $this->fixtures('first');
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

    /**
     * @param  array<string, mixed>  $channelConfigOverrides
     * @return array{Article, DistributionChannel}
     */
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
            'content' => '<p>PLATFORMWEB-MARKER 正文第一段</p><div>第二段纯文本内容</div>',
            'category_id' => $category->id,
            'author_id' => $author->id,
            'task_id' => $task->id,
            'status' => 'private',
            'review_status' => 'approved',
        ]);
    }
}
