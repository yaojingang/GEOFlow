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
        $this->assertSame('本账号代表 GEOFlow 团队。', $first->disclosure_snapshot);
        $this->assertSame('GEOFlow 专家', $first->personaDisplayName());
        $this->assertSame('GEOFlow 知乎账号', $first->accountDisplayName());
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

        $persona->update(['name' => '已更名身份']);
        $account->update(['account_name' => '已更名账号']);

        $publication->refresh()->load(['persona', 'account']);

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

        $publication = $service->transition($publication, ManualPublication::STATUS_READY, 1, actor: $admin);
        $publication = $service->transition($publication, ManualPublication::STATUS_IN_PROGRESS, 2, actor: $admin);
        $publication = $service->transition(
            $publication,
            ManualPublication::STATUS_FAILED,
            3,
            resultNote: '平台暂时不可用',
            actor: $admin,
        );
        $publication = $service->transition($publication, ManualPublication::STATUS_READY, 4, actor: $admin);

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
