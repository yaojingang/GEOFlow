<?php

namespace Tests\Feature;

use App\Exceptions\ManualPublicationConflictException;
use App\Models\Admin;
use App\Models\Article;
use App\Models\Author;
use App\Models\Category;
use App\Models\ManualPublication;
use App\Models\ManualPublicationAccount;
use App\Models\ManualPublicationPersona;
use App\Models\SensitiveWord;
use App\Services\GeoFlow\ManualPublicationDuplicateDetector;
use App\Services\GeoFlow\ManualPublicationService;
use DomainException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Tests\TestCase;

class ManualPublicationServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_post_creation_snapshots_approved_article_and_records_risk_and_duplicates(): void
    {
        $admin = $this->admin('super_admin');
        [$persona, $account] = $this->identity($admin);
        $article = $this->article('approved');
        SensitiveWord::query()->create([
            'word' => '绝对第一',
            'severity' => 'warning',
            'category' => 'claim',
            'applies_to' => ['content'],
        ]);
        $service = app(ManualPublicationService::class);
        $payload = $this->payload($persona, $account, $admin, [
            'article_id' => $article->getKey(),
            'content' => '这是一段绝对第一的发布内容。',
        ]);

        $first = $service->create($payload, $admin);
        $second = $service->create($payload, $admin);

        $this->assertSame('warning', $first->risk_status);
        $this->assertSame($article->title, $first->source_snapshot['title']);
        $this->assertSame('GEOFlow 专家', $first->personaDisplayName());
        $this->assertSame('GEOFlow 知乎账号', $first->accountDisplayName());
        $this->assertSame('本账号代表 GEOFlow 团队。', $first->disclosure_snapshot);
        $this->assertSame(0, $first->duplicate_warning_count);
        $this->assertSame(1, $second->duplicate_warning_count);
        $this->assertCount(1, $service->duplicatesFor($second));
    }

    public function test_identity_snapshot_is_stable_after_persona_and_account_changes(): void
    {
        $admin = $this->admin('super_admin');
        [$persona, $account] = $this->identity($admin);
        $article = $this->article('approved');
        $publication = app(ManualPublicationService::class)->create(
            $this->payload($persona, $account, $admin, ['article_id' => $article->getKey()]),
            $admin,
        );

        $persona->update(['name' => '已更新身份']);
        $account->update(['account_name' => '已更新账号']);

        $publication->refresh();
        $this->assertSame('GEOFlow 专家', $publication->personaDisplayName());
        $this->assertSame('GEOFlow 知乎账号', $publication->accountDisplayName());
    }

    public function test_post_creation_rejects_unapproved_article_and_mismatched_account(): void
    {
        $admin = $this->admin('super_admin');
        [$persona, $account] = $this->identity($admin);
        $article = $this->article('pending');
        $service = app(ManualPublicationService::class);

        $this->expectException(DomainException::class);
        $service->create($this->payload($persona, $account, $admin, [
            'article_id' => $article->getKey(),
        ]), $admin);
    }

    public function test_state_transitions_require_current_revision_and_completion_url(): void
    {
        $admin = $this->admin('super_admin');
        [$persona, $account] = $this->identity($admin);
        $article = $this->article('approved');
        $service = app(ManualPublicationService::class);
        $publication = $service->create($this->payload($persona, $account, $admin, [
            'article_id' => $article->getKey(),
        ]), $admin);

        $publication = $service->transition($publication, ManualPublication::STATUS_READY, 1, $admin);
        $this->assertSame(2, $publication->revision);

        try {
            $service->transition($publication, ManualPublication::STATUS_IN_PROGRESS, 1, $admin);
            $this->fail('Expected stale revision to be rejected.');
        } catch (ManualPublicationConflictException) {
            $this->assertSame(ManualPublication::STATUS_READY, $publication->refresh()->status);
        }

        $publication = $service->transition($publication, ManualPublication::STATUS_IN_PROGRESS, 2, $admin);
        $token = $admin->createToken('Browser claim', [
            'browser-operations:read', 'browser-operations:execute',
        ])->accessToken;
        $publication->forceFill([
            'browser_claimed_by_token_id' => $token->id,
            'browser_claimed_at' => now(),
            'browser_last_seen_at' => now(),
        ])->save();

        try {
            $service->transition($publication, ManualPublication::STATUS_COMPLETED, 3, $admin);
            $this->fail('Expected completion without URL to be rejected.');
        } catch (DomainException) {
            $this->assertSame(ManualPublication::STATUS_IN_PROGRESS, $publication->refresh()->status);
        }

        $publication = $service->transition(
            $publication,
            ManualPublication::STATUS_COMPLETED,
            3,
            $admin,
            'https://example.com/published/1',
            '发布成功',
        );

        $this->assertSame(ManualPublication::STATUS_COMPLETED, $publication->status);
        $this->assertSame('https://example.com/published/1', $publication->completion_url);
        $this->assertNotNull($publication->completed_at);
        $this->assertSame(4, $publication->revision);
        $this->assertNull($publication->browser_claimed_by_token_id);
        $this->assertNull($publication->browser_claimed_at);
        $this->assertNull($publication->browser_last_seen_at);
    }

    public function test_transition_rechecks_assignee_after_locking_current_revision(): void
    {
        $superAdmin = $this->admin('super_admin');
        $originalAssignee = $this->admin();
        $newAssignee = $this->admin();
        [$persona, $account] = $this->identity($superAdmin);
        $article = $this->article('approved');
        $service = app(ManualPublicationService::class);
        $publication = $service->create($this->payload($persona, $account, $originalAssignee, [
            'article_id' => $article->getKey(),
        ]), $superAdmin);
        $publication = $service->transition(
            $publication,
            ManualPublication::STATUS_READY,
            1,
            $originalAssignee,
        );

        $this->assertTrue(Gate::forUser($originalAssignee)->allows('transition', $publication));

        ManualPublication::query()->whereKey($publication->getKey())->update([
            'assigned_admin_id' => $newAssignee->getKey(),
            'revision' => 3,
        ]);

        try {
            $service->transition(
                $publication,
                ManualPublication::STATUS_IN_PROGRESS,
                3,
                $originalAssignee,
            );
            $this->fail('Expected the former assignee to be rejected after reassignment.');
        } catch (AuthorizationException) {
            $publication->refresh();
            $this->assertSame($newAssignee->getKey(), $publication->assigned_admin_id);
            $this->assertSame(ManualPublication::STATUS_READY, $publication->status);
            $this->assertSame(3, $publication->revision);
        }
    }

    public function test_state_transition_history_keeps_failure_reason_after_reopen(): void
    {
        $admin = $this->admin('super_admin');
        [$persona, $account] = $this->identity($admin);
        $article = $this->article('approved');
        $service = app(ManualPublicationService::class);
        $publication = $service->create(
            $this->payload($persona, $account, $admin, ['article_id' => $article->getKey()]),
            $admin,
        );

        $publication = $service->transition($publication, ManualPublication::STATUS_READY, 1, $admin);
        $publication = $service->transition($publication, ManualPublication::STATUS_IN_PROGRESS, 2, $admin);
        $publication = $service->transition(
            $publication,
            ManualPublication::STATUS_FAILED,
            3,
            $admin,
            resultNote: '平台暂时不可用',
        );
        $publication = $service->transition($publication, ManualPublication::STATUS_READY, 4, $admin);

        $history = $publication->transitions()->oldest('id')->get();

        $this->assertCount(5, $history);
        $this->assertNull($history[0]->from_status);
        $this->assertSame(ManualPublication::STATUS_DRAFT, $history[0]->to_status);
        $this->assertSame(ManualPublication::STATUS_FAILED, $history[3]->to_status);
        $this->assertSame('平台暂时不可用', $history[3]->result_note);
        $this->assertSame(ManualPublication::STATUS_READY, $history[4]->to_status);
        $this->assertSame($admin->getKey(), $history[4]->changed_by_admin_id);
    }

    public function test_source_foreign_key_is_cleared_when_article_is_force_deleted(): void
    {
        $admin = $this->admin('super_admin');
        [$persona, $account] = $this->identity($admin);
        $article = $this->article('approved');
        $publication = app(ManualPublicationService::class)->create(
            $this->payload($persona, $account, $admin, ['article_id' => $article->getKey()]),
            $admin,
        );

        $article->forceDelete();

        $this->assertNull($publication->refresh()->article_id);
        $this->assertNotEmpty($publication->source_snapshot['title']);
    }

    public function test_similar_content_on_same_platform_is_flagged_across_different_articles(): void
    {
        $admin = $this->admin('super_admin');
        [$persona, $account] = $this->identity($admin);
        $firstArticle = $this->article('approved');
        $secondArticle = $this->article('approved');
        $service = app(ManualPublicationService::class);
        $service->create($this->payload($persona, $account, $admin, [
            'article_id' => $firstArticle->getKey(),
            'content' => 'GEOFlow 可以帮助团队管理可信内容和人工发布流程。',
        ]), $admin);

        $similar = $service->create($this->payload($persona, $account, $admin, [
            'article_id' => $secondArticle->getKey(),
            'content' => 'GEOFlow 可以帮助团队管理可信内容与人工发布流程。',
        ]), $admin);

        $this->assertSame(1, $similar->duplicate_warning_count);
    }

    public function test_exact_duplicate_detection_covers_the_full_lookback_window(): void
    {
        $detector = app(ManualPublicationDuplicateDetector::class);
        $duplicateContent = '九十天窗口内的历史完全重复内容';
        $historicalDuplicate = ManualPublication::query()->create([
            'type' => ManualPublication::TYPE_COMMENT,
            'platform' => ManualPublicationAccount::PLATFORM_ZHIHU,
            'content' => $duplicateContent,
            'content_fingerprint' => $detector->fingerprint($duplicateContent),
            'identity_snapshot' => [],
        ]);

        foreach (range(1, ManualPublicationDuplicateDetector::MAX_SIMILARITY_CANDIDATES) as $sequence) {
            $content = '近期不同内容 '.$sequence;
            ManualPublication::query()->create([
                'type' => ManualPublication::TYPE_COMMENT,
                'platform' => ManualPublicationAccount::PLATFORM_ZHIHU,
                'content' => $content,
                'content_fingerprint' => $detector->fingerprint($content),
                'identity_snapshot' => [],
            ]);
        }

        $matches = $detector->find([
            'platform' => ManualPublicationAccount::PLATFORM_ZHIHU,
            'article_id' => null,
            'target_url_hash' => null,
            'content' => $duplicateContent,
            'content_fingerprint' => $detector->fingerprint($duplicateContent),
        ]);

        $this->assertTrue($matches->contains('id', $historicalDuplicate->getKey()));
    }

    public function test_update_persists_casted_scan_data_and_rejects_stale_revision(): void
    {
        $admin = $this->admin('super_admin');
        [$persona, $account] = $this->identity($admin);
        $article = $this->article('approved');
        $service = app(ManualPublicationService::class);
        $publication = $service->create($this->payload($persona, $account, $admin, [
            'article_id' => $article->getKey(),
        ]), $admin);
        $updatedPayload = $this->payload($persona, $account, $admin, [
            'article_id' => $article->getKey(),
            'content' => '修订后的人工发布文案',
        ]);
        unset($updatedPayload['status']);

        $updated = $service->update($publication, $updatedPayload, 1);

        $this->assertSame(2, $updated->revision);
        $this->assertSame('修订后的人工发布文案', $updated->content);
        $this->assertIsArray($updated->risk_result);
        $this->assertSame('clean', $updated->risk_result['status']);

        $this->expectException(ManualPublicationConflictException::class);
        $service->update($updated, $updatedPayload, 1);
    }

    public function test_ready_post_builds_browser_payload_and_claimed_content_is_immutable(): void
    {
        $admin = $this->admin('super_admin');
        [$persona, $account] = $this->identity($admin);
        $article = $this->article('approved');
        $service = app(ManualPublicationService::class);
        $longContent = str_repeat('GEO 浏览器运营内容。', 240);
        $payload = $this->payload($persona, $account, $admin, [
            'article_id' => $article->getKey(),
            'target_url' => 'https://www.zhihu.com/question/123456',
            'content' => $longContent,
            'status' => ManualPublication::STATUS_READY,
        ]);

        $publication = $service->create($payload, $admin);

        $this->assertSame(1, $publication->publication_payload['schema_version']);
        $this->assertSame('zhihu_answer', $publication->publication_payload['target_action']);
        $this->assertSame($longContent, $publication->publication_payload['body_plain']);

        $publication = $service->transition($publication, ManualPublication::STATUS_IN_PROGRESS, 1, $admin);
        unset($payload['status']);

        $this->expectException(DomainException::class);
        $service->update($publication, $payload, 2);
    }

    public function test_publication_content_limits_are_applied_by_work_order_type(): void
    {
        $this->assertSame(2000, ManualPublication::maxContentCharactersForType(ManualPublication::TYPE_COMMENT));
        $this->assertSame(100000, ManualPublication::maxContentCharactersForType(ManualPublication::TYPE_POST));

        $admin = $this->admin('super_admin');
        [$persona, $account] = $this->identity($admin);

        $this->expectException(DomainException::class);
        app(ManualPublicationService::class)->create($this->payload($persona, $account, $admin, [
            'type' => ManualPublication::TYPE_COMMENT,
            'article_id' => null,
            'target_url' => 'https://www.zhihu.com/question/123456',
            'target_context' => '问题上下文',
            'content' => str_repeat('评', 2001),
        ]), $admin);
    }

    public function test_stale_browser_claim_can_be_recovered_after_operator_confirmation(): void
    {
        $admin = $this->admin('super_admin');
        [$persona, $account] = $this->identity($admin);
        $article = $this->article('approved');
        $service = app(ManualPublicationService::class);
        $publication = $service->create($this->payload($persona, $account, $admin, [
            'article_id' => $article->getKey(),
            'target_url' => 'https://www.zhihu.com/question/123456',
            'status' => ManualPublication::STATUS_READY,
        ]), $admin);
        $token = $admin->createToken('Recovery browser', [
            'browser-operations:read', 'browser-operations:execute',
        ])->accessToken;
        $publication->forceFill([
            'status' => ManualPublication::STATUS_IN_PROGRESS,
            'browser_claimed_by_token_id' => $token->id,
            'browser_claimed_at' => now(),
            'browser_last_seen_at' => now(),
            'revision' => 2,
        ])->save();

        try {
            $service->transition($publication, ManualPublication::STATUS_READY, 2, $admin);
            $this->fail('Expected an active browser claim to remain locked.');
        } catch (DomainException) {
            $this->assertSame(ManualPublication::STATUS_IN_PROGRESS, $publication->refresh()->status);
        }

        $this->travel(11)->minutes();
        $recovered = $service->transition($publication, ManualPublication::STATUS_READY, 2, $admin);

        $this->assertSame(ManualPublication::STATUS_READY, $recovered->status);
        $this->assertNull($recovered->browser_claimed_by_token_id);
        $this->assertSame(3, $recovered->revision);
    }

    private function admin(string $role = 'admin'): Admin
    {
        return Admin::query()->create([
            'username' => uniqid('manual_admin_'),
            'password' => 'secret-123',
            'email' => uniqid('manual-').'@example.com',
            'display_name' => 'Manual Publisher',
            'role' => $role,
            'status' => 'active',
        ]);
    }

    /** @return array{ManualPublicationPersona, ManualPublicationAccount} */
    private function identity(Admin $admin): array
    {
        $persona = ManualPublicationPersona::query()->create([
            'name' => 'GEOFlow 专家',
            'tone' => '专业',
            'domain' => 'GEO',
            'disclosure_text' => '本账号代表 GEOFlow 团队。',
            'created_by_admin_id' => $admin->getKey(),
        ]);
        $account = ManualPublicationAccount::query()->create([
            'persona_id' => $persona->getKey(),
            'platform' => ManualPublicationAccount::PLATFORM_ZHIHU,
            'account_name' => 'GEOFlow 知乎账号',
            'profile_url' => 'https://www.zhihu.com/people/geoflow',
            'created_by_admin_id' => $admin->getKey(),
        ]);

        return [$persona, $account];
    }

    private function article(string $reviewStatus): Article
    {
        $category = Category::query()->create([
            'name' => uniqid('分类'),
            'slug' => uniqid('manual-category-'),
        ]);
        $author = Author::query()->create(['name' => uniqid('作者')]);

        return Article::query()->create([
            'title' => '人工发布测试文章',
            'slug' => uniqid('manual-publication-article-'),
            'excerpt' => '摘要',
            'content' => '文章原始正文',
            'category_id' => $category->getKey(),
            'author_id' => $author->getKey(),
            'status' => 'draft',
            'review_status' => $reviewStatus,
        ]);
    }

    /** @param array<string, mixed> $overrides */
    private function payload(
        ManualPublicationPersona $persona,
        ManualPublicationAccount $account,
        Admin $assignee,
        array $overrides = [],
    ): array {
        return array_replace([
            'type' => ManualPublication::TYPE_POST,
            'article_id' => null,
            'persona_id' => $persona->getKey(),
            'account_id' => $account->getKey(),
            'assigned_admin_id' => $assignee->getKey(),
            'platform' => ManualPublicationAccount::PLATFORM_ZHIHU,
            'custom_platform' => null,
            'target_url' => null,
            'target_context' => null,
            'content' => '普通发布内容',
            'scheduled_at' => now()->addHour(),
            'status' => ManualPublication::STATUS_DRAFT,
        ], $overrides);
    }
}
