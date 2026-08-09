<?php

namespace Tests\Feature;

use App\Models\Admin;
use App\Models\Article;
use App\Models\Author;
use App\Models\Category;
use App\Models\ManualPublication;
use App\Models\ManualPublicationAccount;
use App\Models\ManualPublicationPersona;
use App\Services\GeoFlow\ManualPublicationService;
use App\Support\AdminActivityLogger;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminManualPublicationsTest extends TestCase
{
    use RefreshDatabase;

    public function test_manual_publications_are_grouped_under_content_management_navigation(): void
    {
        $admin = $this->admin('admin');

        $articlesResponse = $this->actingAs($admin, 'admin')
            ->get(route('admin.articles.index'));

        $articlesResponse
            ->assertOk()
            ->assertSee(route('admin.manual-publications.index'), false)
            ->assertSee(__('admin.manual_publications.nav'));

        $this->assertSame(1, substr_count(
            (string) $articlesResponse->getContent(),
            'href="'.route('admin.manual-publications.index').'"',
        ));

        $this->actingAs($admin, 'admin')
            ->get(route('admin.manual-publications.index'))
            ->assertOk()
            ->assertViewHas('activeMenu', 'articles');
    }

    public function test_super_admin_can_create_post_work_order_from_approved_article_and_open_workbench(): void
    {
        $superAdmin = $this->admin('super_admin');
        [$persona, $account] = $this->identity($superAdmin);
        $article = $this->article('approved');

        $this->actingAs($superAdmin, 'admin')
            ->get(route('admin.manual-publications.create', ['article_id' => $article->getKey()]))
            ->assertOk()
            ->assertSee($article->title)
            ->assertSee(__('admin.manual_publications.create_title'));

        $response = $this->actingAs($superAdmin, 'admin')
            ->post(route('admin.manual-publications.store'), $this->payload($persona, $account, $superAdmin, [
                'article_id' => $article->getKey(),
                'content' => '最终发布文案',
                'status' => ManualPublication::STATUS_READY,
            ]));

        $publication = ManualPublication::query()->firstOrFail();
        $response->assertRedirect(route('admin.manual-publications.show', ['manualPublicationId' => $publication->getKey()]));
        $this->assertSame(ManualPublication::STATUS_READY, $publication->status);
        $this->assertSame($article->title, $publication->source_snapshot['title']);

        $this->actingAs($superAdmin, 'admin')
            ->get(route('admin.manual-publications.index'))
            ->assertOk()
            ->assertSee(__('admin.manual_publications.page_title'))
            ->assertSee('最终发布文案');

        $this->actingAs($superAdmin, 'admin')
            ->get(route('admin.manual-publications.show', ['manualPublicationId' => $publication->getKey()]))
            ->assertOk()
            ->assertSee('data-copy-target="manual-publication-content"', false)
            ->assertSee('最终发布文案');
    }

    public function test_article_picker_searches_paginated_results_and_keeps_current_article_selected(): void
    {
        $superAdmin = $this->admin('super_admin');
        [$persona, $account] = $this->identity($superAdmin);
        $currentArticle = $this->article('approved');
        $currentArticle->update(['title' => '当前工单历史文章']);
        $searchableArticle = $this->article('approved');
        $searchableArticle->update(['title' => '归档检索针文章']);
        $publication = app(ManualPublicationService::class)->create(
            $this->payload($persona, $account, $superAdmin, ['article_id' => $currentArticle->getKey()]),
            $superAdmin,
        );

        foreach (range(1, 55) as $sequence) {
            Article::query()->create([
                'title' => '近期已审核文章 '.$sequence,
                'slug' => 'recent-approved-article-'.$sequence,
                'excerpt' => '摘要',
                'content' => '文章正文',
                'category_id' => $currentArticle->category_id,
                'author_id' => $currentArticle->author_id,
                'status' => 'draft',
                'review_status' => 'approved',
            ]);
        }

        $this->actingAs($superAdmin, 'admin')
            ->get(route('admin.manual-publications.create', ['article_search' => '归档检索针']))
            ->assertOk()
            ->assertSee($searchableArticle->title)
            ->assertViewHas('articles', fn ($articles): bool => $articles->total() === 1);

        $this->actingAs($superAdmin, 'admin')
            ->get(route('admin.manual-publications.edit', ['manualPublicationId' => $publication->getKey()]))
            ->assertOk()
            ->assertSee($currentArticle->title)
            ->assertSee('value="'.$currentArticle->getKey().'" selected', false)
            ->assertViewHas('articles', fn ($articles): bool => $articles->perPage() === 50
                && $articles->getCollection()->contains('id', $currentArticle->getKey()));
    }

    public function test_standard_admin_only_sees_assigned_work_and_can_complete_it(): void
    {
        $superAdmin = $this->admin('super_admin');
        $worker = $this->admin('admin');
        $otherWorker = $this->admin('admin');
        [$persona, $account] = $this->identity($superAdmin);
        $article = $this->article('approved');
        $service = app(ManualPublicationService::class);
        $assigned = $service->create($this->payload($persona, $account, $worker, [
            'article_id' => $article->getKey(),
            'content' => 'Worker visible content',
        ]), $superAdmin);
        $other = $service->create($this->payload($persona, $account, $otherWorker, [
            'article_id' => $article->getKey(),
            'content' => 'Other worker secret content',
        ]), $superAdmin);

        $this->actingAs($worker, 'admin')
            ->get(route('admin.manual-publications.index'))
            ->assertOk()
            ->assertSee('Worker visible content')
            ->assertDontSee('Other worker secret content');
        $this->actingAs($worker, 'admin')
            ->get(route('admin.manual-publications.show', ['manualPublicationId' => $other->getKey()]))
            ->assertForbidden();
        $this->actingAs($worker, 'admin')
            ->get(route('admin.manual-publications.create'))
            ->assertForbidden();
        $this->actingAs($worker, 'admin')
            ->get(route('admin.manual-publications.settings.index'))
            ->assertForbidden();
        $this->actingAs($worker, 'admin')
            ->get(route('admin.manual-publications.edit', ['manualPublicationId' => $assigned->getKey()]))
            ->assertForbidden();

        $this->actingAs($worker, 'admin')->post(route('admin.manual-publications.transition', ['manualPublicationId' => $assigned->getKey()]), [
            'target_status' => ManualPublication::STATUS_READY,
            'revision' => 1,
        ])->assertRedirect();
        $this->actingAs($worker, 'admin')->post(route('admin.manual-publications.transition', ['manualPublicationId' => $assigned->getKey()]), [
            'target_status' => ManualPublication::STATUS_IN_PROGRESS,
            'revision' => 2,
        ])->assertRedirect();
        $this->actingAs($worker, 'admin')->post(route('admin.manual-publications.transition', ['manualPublicationId' => $assigned->getKey()]), [
            'target_status' => ManualPublication::STATUS_COMPLETED,
            'revision' => 3,
            'completion_url' => 'https://example.com/posts/worker-result',
            'result_note' => '执行完成',
        ])->assertRedirect();

        $assigned->refresh();
        $this->assertSame(ManualPublication::STATUS_COMPLETED, $assigned->status);
        $this->assertSame('https://example.com/posts/worker-result', $assigned->completion_url);
        $this->assertCount(4, $assigned->transitions()->get());
        $this->assertSame($worker->getKey(), $assigned->transitions()->latest('id')->firstOrFail()->changed_by_admin_id);
    }

    public function test_comment_validation_requires_target_and_rejects_account_persona_mismatch(): void
    {
        $superAdmin = $this->admin('super_admin');
        [$persona, $account] = $this->identity($superAdmin);
        $otherPersona = ManualPublicationPersona::query()->create(['name' => '另一个身份']);

        $this->actingAs($superAdmin, 'admin')
            ->from(route('admin.manual-publications.create'))
            ->post(route('admin.manual-publications.store'), $this->payload($persona, $account, $superAdmin, [
                'type' => ManualPublication::TYPE_COMMENT,
                'article_id' => null,
                'target_url' => null,
                'target_context' => null,
            ]))
            ->assertRedirect(route('admin.manual-publications.create'))
            ->assertSessionHasErrors(['target_url', 'target_context']);

        $this->actingAs($superAdmin, 'admin')
            ->from(route('admin.manual-publications.create'))
            ->post(route('admin.manual-publications.store'), $this->payload($otherPersona, $account, $superAdmin, [
                'type' => ManualPublication::TYPE_COMMENT,
                'article_id' => null,
                'target_url' => 'https://example.com/thread/1',
                'target_context' => '讨论 GEO 内容生产流程。',
            ]))
            ->assertSessionHasErrors(['account_id']);

        $this->actingAs($superAdmin, 'admin')
            ->post(route('admin.manual-publications.store'), $this->payload($persona, $account, $superAdmin, [
                'type' => ManualPublication::TYPE_COMMENT,
                'article_id' => null,
                'target_url' => 'https://example.com/thread/1',
                'target_context' => '讨论 GEO 内容生产流程。',
            ]))
            ->assertRedirect();

        $this->assertDatabaseHas('manual_publications', [
            'type' => ManualPublication::TYPE_COMMENT,
            'target_url' => 'https://example.com/thread/1',
        ]);
    }

    public function test_super_admin_can_manage_personas_and_accounts_without_logging_full_text(): void
    {
        $superAdmin = $this->admin('super_admin');

        $this->actingAs($superAdmin, 'admin')->post(route('admin.manual-publications.settings.personas.store'), [
            'name' => '品牌专家',
            'tone' => '克制专业',
            'domain' => 'GEO',
            'bio' => '这是一段不应完整进入审计日志的身份介绍。',
            'disclosure_text' => '本账号与 GEOFlow 项目有关联。',
            'is_active' => '1',
        ])->assertRedirect();

        $persona = ManualPublicationPersona::query()->firstOrFail();
        $this->actingAs($superAdmin, 'admin')->post(route('admin.manual-publications.settings.accounts.store'), [
            'persona_id' => $persona->getKey(),
            'platform' => ManualPublicationAccount::PLATFORM_LINKEDIN,
            'account_name' => 'GEOFlow LinkedIn',
            'profile_url' => 'https://www.linkedin.com/company/geoflow',
            'notes' => '仅保存账号引用，不保存密码。',
            'is_active' => '1',
        ])->assertRedirect();

        $this->assertDatabaseHas('manual_publication_accounts', ['account_name' => 'GEOFlow LinkedIn']);
        $sanitize = new \ReflectionMethod(AdminActivityLogger::class, 'sanitizePayload');
        $details = $sanitize->invoke(null, [
            'bio' => '这是一段不应完整进入审计日志的身份介绍。',
            'disclosure_text' => '本账号与 GEOFlow 项目有关联。',
            'target_context' => '目标讨论上下文',
        ]);
        $this->assertStringStartsWith('[text:', $details['bio']);
        $this->assertStringStartsWith('[text:', $details['disclosure_text']);
        $this->assertStringStartsWith('[text:', $details['target_context']);
    }

    public function test_export_is_scoped_to_worker_and_protects_spreadsheet_cells(): void
    {
        $superAdmin = $this->admin('super_admin');
        $worker = $this->admin('admin');
        $otherWorker = $this->admin('admin');
        [$persona, $account] = $this->identity($superAdmin);
        $article = $this->article('approved');
        $service = app(ManualPublicationService::class);
        $visible = $service->create($this->payload($persona, $account, $worker, [
            'article_id' => $article->getKey(), 'content' => '=SUM(1,1)',
        ]), $superAdmin);
        $service->create($this->payload($persona, $account, $otherWorker, [
            'article_id' => $article->getKey(), 'content' => 'hidden export row',
        ]), $superAdmin);
        $persona->update(['name' => '已更名身份']);
        $account->update(['account_name' => '已更名账号']);

        $this->actingAs($worker, 'admin')
            ->get(route('admin.manual-publications.show', ['manualPublicationId' => $visible->getKey()]))
            ->assertOk()
            ->assertSee('GEOFlow 专家')
            ->assertSee('GEOFlow 知乎账号')
            ->assertDontSee('已更名身份')
            ->assertDontSee('已更名账号');

        $response = $this->actingAs($worker, 'admin')->get(route('admin.manual-publications.export'));

        $response->assertOk()->assertStreamed();
        $csv = $response->streamedContent();
        $this->assertStringStartsWith("\xEF\xBB\xBF", $csv);
        $this->assertStringContainsString("'=SUM(1,1)", $csv);
        $this->assertStringContainsString('GEOFlow 专家', $csv);
        $this->assertStringContainsString('GEOFlow 知乎账号', $csv);
        $this->assertStringNotContainsString('已更名身份', $csv);
        $this->assertStringNotContainsString('已更名账号', $csv);
        $this->assertStringNotContainsString('hidden export row', $csv);
    }

    public function test_approved_articles_show_manual_publication_entry_only_to_super_admin(): void
    {
        $superAdmin = $this->admin('super_admin');
        $worker = $this->admin('admin');
        $article = $this->article('approved');

        $this->actingAs($superAdmin, 'admin')
            ->get(route('admin.articles.edit', ['articleId' => $article->getKey()]))
            ->assertOk()
            ->assertSee(route('admin.manual-publications.create', ['article_id' => $article->getKey()]), false);

        $this->actingAs($worker, 'admin')
            ->get(route('admin.articles.edit', ['articleId' => $article->getKey()]))
            ->assertOk()
            ->assertDontSee(route('admin.manual-publications.create', ['article_id' => $article->getKey()]), false);
    }

    public function test_only_super_admin_can_reopen_terminal_work_order_and_invalid_jump_is_rejected(): void
    {
        $superAdmin = $this->admin('super_admin');
        $worker = $this->admin('admin');
        [$persona, $account] = $this->identity($superAdmin);
        $article = $this->article('approved');
        $service = app(ManualPublicationService::class);
        $publication = $service->create($this->payload($persona, $account, $worker, [
            'article_id' => $article->getKey(),
        ]), $superAdmin);

        $this->actingAs($worker, 'admin')->post(route('admin.manual-publications.transition', ['manualPublicationId' => $publication->getKey()]), [
            'target_status' => ManualPublication::STATUS_COMPLETED,
            'revision' => 1,
            'completion_url' => 'https://example.com/invalid-jump',
        ])->assertSessionHasErrors();
        $this->assertSame(ManualPublication::STATUS_DRAFT, $publication->refresh()->status);

        $publication = $service->transition($publication, ManualPublication::STATUS_READY, 1, $superAdmin);
        $publication = $service->transition($publication, ManualPublication::STATUS_IN_PROGRESS, 2, $superAdmin);
        $publication = $service->transition($publication, ManualPublication::STATUS_FAILED, 3, $superAdmin, resultNote: '平台暂时不可用');

        $this->actingAs($worker, 'admin')->post(route('admin.manual-publications.transition', ['manualPublicationId' => $publication->getKey()]), [
            'target_status' => ManualPublication::STATUS_READY,
            'revision' => 4,
        ])->assertForbidden();

        $this->actingAs($superAdmin, 'admin')->post(route('admin.manual-publications.transition', ['manualPublicationId' => $publication->getKey()]), [
            'target_status' => ManualPublication::STATUS_READY,
            'revision' => 4,
        ])->assertRedirect();
        $this->assertSame(ManualPublication::STATUS_READY, $publication->refresh()->status);
    }

    private function admin(string $role): Admin
    {
        return Admin::query()->create([
            'username' => uniqid('manual_web_'), 'password' => 'secret-123', 'email' => uniqid('manual-web-').'@example.com',
            'display_name' => 'Manual Web Admin', 'role' => $role, 'status' => 'active',
        ]);
    }

    /** @return array{ManualPublicationPersona, ManualPublicationAccount} */
    private function identity(Admin $admin): array
    {
        $persona = ManualPublicationPersona::query()->create([
            'name' => 'GEOFlow 专家', 'disclosure_text' => '本账号代表 GEOFlow 团队。', 'created_by_admin_id' => $admin->getKey(),
        ]);
        $account = ManualPublicationAccount::query()->create([
            'persona_id' => $persona->getKey(), 'platform' => ManualPublicationAccount::PLATFORM_ZHIHU,
            'account_name' => 'GEOFlow 知乎账号', 'created_by_admin_id' => $admin->getKey(),
        ]);

        return [$persona, $account];
    }

    private function article(string $reviewStatus): Article
    {
        $category = Category::query()->create(['name' => uniqid('分类'), 'slug' => uniqid('manual-web-category-')]);
        $author = Author::query()->create(['name' => uniqid('作者')]);

        return Article::query()->create([
            'title' => '管理端人工发布测试文章', 'slug' => uniqid('manual-web-article-'), 'excerpt' => '摘要', 'content' => '文章正文',
            'category_id' => $category->getKey(), 'author_id' => $author->getKey(), 'status' => 'draft', 'review_status' => $reviewStatus,
        ]);
    }

    /** @param array<string, mixed> $overrides */
    private function payload(ManualPublicationPersona $persona, ManualPublicationAccount $account, Admin $assignee, array $overrides = []): array
    {
        return array_replace([
            'type' => ManualPublication::TYPE_POST, 'article_id' => null, 'persona_id' => $persona->getKey(),
            'account_id' => $account->getKey(), 'assigned_admin_id' => $assignee->getKey(), 'platform' => ManualPublicationAccount::PLATFORM_ZHIHU,
            'custom_platform' => null, 'target_url' => null, 'target_context' => null, 'content' => '普通发布内容',
            'scheduled_at' => now()->addHour()->format('Y-m-d H:i:s'), 'status' => ManualPublication::STATUS_DRAFT,
        ], $overrides);
    }
}
