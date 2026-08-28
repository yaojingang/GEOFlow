<?php

namespace Tests\Feature;

use App\Exceptions\ApiException;
use App\Models\Admin;
use App\Models\KnowledgeBase;
use App\Services\AiWorkspace\SystemKnowledgeBaseManager;
use App\Services\GeoFlow\MaterialLibraryService;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

final class SystemKnowledgeBaseTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_sync_command_installs_the_ai_workspace_system_knowledge_once(): void
    {
        Queue::fake();

        $this->artisan('geoflow:sync-system-knowledge', [
            '--key' => 'ai_workspace_manual',
        ])->assertSuccessful();

        $this->artisan('geoflow:sync-system-knowledge', [
            '--key' => 'ai_workspace_manual',
        ])->assertSuccessful();

        $binding = DB::table('system_knowledge_bases')
            ->where('system_key', 'ai_workspace_manual')
            ->first();

        self::assertNotNull($binding);
        self::assertSame(1, DB::table('system_knowledge_bases')->count());
        self::assertSame(1, DB::table('knowledge_base_revisions')->count());

        $knowledgeBase = KnowledgeBase::query()->findOrFail($binding->knowledge_base_id);
        self::assertSame('GEOFlow 后台功能与操作指南（AI 工作台专用）', $knowledgeBase->name);
        self::assertSame('markdown', $knowledgeBase->file_type);
        self::assertSame('reviewed', $knowledgeBase->review_status);
        self::assertSame('low', $knowledgeBase->risk_level);
        self::assertGreaterThanOrEqual(10_000, preg_match_all('/\p{Han}/u', (string) $knowledgeBase->content));
    }

    public function test_system_knowledge_cannot_be_deleted_through_web_or_material_service(): void
    {
        Queue::fake();
        $result = app(SystemKnowledgeBaseManager::class)->sync();
        $knowledgeBase = $result['knowledge_base'];
        $admin = Admin::query()->create([
            'username' => 'system-knowledge-owner',
            'password' => 'secret-123',
            'email' => 'system-knowledge-owner@example.com',
            'display_name' => 'System Knowledge Owner',
            'role' => 'super_admin',
            'status' => 'active',
        ]);

        $this->actingAs($admin, 'admin')
            ->post(route('admin.knowledge-bases.delete', ['knowledgeBaseId' => $knowledgeBase->getKey()]))
            ->assertRedirect()
            ->assertSessionHasErrors();
        $this->assertModelExists($knowledgeBase);

        try {
            app(MaterialLibraryService::class)->delete('knowledge-bases', (int) $knowledgeBase->getKey());
            self::fail('System knowledge deletion should be rejected by the material service.');
        } catch (ApiException $exception) {
            self::assertSame('system_knowledge_protected', $exception->getErrorCode());
            self::assertSame(409, $exception->getHttpStatus());
        }

        $this->assertModelExists($knowledgeBase);

        try {
            app(MaterialLibraryService::class)->update('knowledge-bases', (int) $knowledgeBase->getKey(), [
                'content' => 'untrusted overwrite',
            ]);
            self::fail('System knowledge updates must use the protected admin workflow.');
        } catch (ApiException $exception) {
            self::assertSame('system_knowledge_edit_requires_admin', $exception->getErrorCode());
            self::assertSame(409, $exception->getHttpStatus());
        }
    }

    public function test_database_foreign_key_blocks_direct_system_knowledge_deletion(): void
    {
        Queue::fake();
        $knowledgeBase = app(SystemKnowledgeBaseManager::class)->sync()['knowledge_base'];

        $this->expectException(QueryException::class);

        KnowledgeBase::query()->whereKey($knowledgeBase->getKey())->delete();
    }

    public function test_only_protected_admins_can_edit_and_restore_system_knowledge(): void
    {
        Queue::fake();
        $result = app(SystemKnowledgeBaseManager::class)->sync();
        $knowledgeBase = $result['knowledge_base'];
        $originalContent = (string) $knowledgeBase->content;
        $customContent = $originalContent."\n\n## 管理员补充\n\n这一段用于验证受控编辑和修订恢复。\n";
        $regularAdmin = $this->createAdmin('knowledge-editor', 'admin');
        $superAdmin = $this->createAdmin('knowledge-owner', 'super_admin');

        $this->actingAs($regularAdmin, 'admin')
            ->put(route('admin.knowledge-bases.detail.update', ['knowledgeBaseId' => $knowledgeBase->getKey()]), [
                'name' => $knowledgeBase->name,
                'description' => $knowledgeBase->description,
                'content' => $customContent,
                'file_type' => 'markdown',
            ])
            ->assertForbidden();

        $this->actingAs($superAdmin, 'admin')
            ->put(route('admin.knowledge-bases.detail.update', ['knowledgeBaseId' => $knowledgeBase->getKey()]), [
                'name' => $knowledgeBase->name,
                'description' => $knowledgeBase->description,
                'content' => $customContent,
                'file_type' => 'markdown',
            ])
            ->assertRedirect(route('admin.knowledge-bases.detail', ['knowledgeBaseId' => $knowledgeBase->getKey()]));

        $knowledgeBase->refresh();
        self::assertSame(trim($customContent), $knowledgeBase->content);
        self::assertNotNull($knowledgeBase->systemBinding?->customized_at);
        self::assertSame(2, $knowledgeBase->revisions()->count());
        $this->actingAs($superAdmin, 'admin')
            ->get(route('admin.knowledge-bases.detail', ['knowledgeBaseId' => $knowledgeBase->getKey()]))
            ->assertOk()
            ->assertSee(__('admin.knowledge_detail.system_diff_title'))
            ->assertSee(__('admin.knowledge_detail.system_diff_current'))
            ->assertSee(__('admin.knowledge_detail.system_diff_official'));
        $originalRevision = $knowledgeBase->revisions()->reorder()->orderBy('revision_number')->firstOrFail();

        $this->actingAs($superAdmin, 'admin')
            ->post(route('admin.knowledge-bases.revisions.restore', [
                'knowledgeBaseId' => $knowledgeBase->getKey(),
                'revisionId' => $originalRevision->getKey(),
            ]))
            ->assertRedirect(route('admin.knowledge-bases.detail', ['knowledgeBaseId' => $knowledgeBase->getKey()]));

        $knowledgeBase->refresh();
        self::assertSame($originalContent, $knowledgeBase->content);
        self::assertNull($knowledgeBase->systemBinding?->customized_at);
        self::assertSame(3, $knowledgeBase->revisions()->count());
        self::assertSame('restore', $knowledgeBase->revisions()->firstOrFail()->source);
    }

    public function test_system_metadata_and_manual_reindex_are_protected(): void
    {
        Queue::fake();
        $knowledgeBase = app(SystemKnowledgeBaseManager::class)->sync()['knowledge_base'];
        $regularAdmin = $this->createAdmin('knowledge-metadata-reader', 'admin');
        $superAdmin = $this->createAdmin('knowledge-metadata-owner', 'super_admin');

        $this->actingAs($regularAdmin, 'admin')
            ->post(route('admin.knowledge-bases.chunks.refresh', ['knowledgeBaseId' => $knowledgeBase->getKey()]))
            ->assertForbidden();

        $this->actingAs($superAdmin, 'admin')
            ->put(route('admin.knowledge-bases.detail.update', ['knowledgeBaseId' => $knowledgeBase->getKey()]), [
                'name' => '伪造的系统知识名称',
                'description' => '伪造的系统知识说明',
                'content' => $knowledgeBase->content,
                'file_type' => 'text',
            ])
            ->assertRedirect();

        $knowledgeBase->refresh();
        self::assertSame('GEOFlow 后台功能与操作指南（AI 工作台专用）', $knowledgeBase->name);
        self::assertSame('markdown', $knowledgeBase->file_type);
        self::assertNotSame('伪造的系统知识说明', $knowledgeBase->description);
        self::assertSame(1, $knowledgeBase->revisions()->count());
    }

    public function test_system_knowledge_rejects_unsafe_route_directives(): void
    {
        Queue::fake();
        $knowledgeBase = app(SystemKnowledgeBaseManager::class)->sync()['knowledge_base'];
        $superAdmin = $this->createAdmin('route-reviewer', 'super_admin');
        $unsafeContent = str_replace(
            '[[route:admin.analytics|进入数据中心]]',
            '[[route:admin.tasks.edit|打开动态任务]]',
            (string) $knowledgeBase->content,
        );

        $this->actingAs($superAdmin, 'admin')
            ->from(route('admin.knowledge-bases.detail', ['knowledgeBaseId' => $knowledgeBase->getKey()]))
            ->put(route('admin.knowledge-bases.detail.update', ['knowledgeBaseId' => $knowledgeBase->getKey()]), [
                'name' => $knowledgeBase->name,
                'description' => $knowledgeBase->description,
                'content' => $unsafeContent,
                'file_type' => 'markdown',
            ])
            ->assertRedirect(route('admin.knowledge-bases.detail', ['knowledgeBaseId' => $knowledgeBase->getKey()]))
            ->assertSessionHasErrors('content');

        self::assertSame(1, $knowledgeBase->revisions()->count());
    }

    public function test_sync_preserves_customized_content_and_adopt_official_creates_a_revision(): void
    {
        Queue::fake();
        $manager = app(SystemKnowledgeBaseManager::class);
        $knowledgeBase = $manager->sync()['knowledge_base'];
        $officialContent = (string) $knowledgeBase->content;
        $customContent = $officialContent."\n\n## 管理员本地说明\n\n这一段验证官方同步会保留后台受控编辑的内容。\n";
        $superAdmin = $this->createAdmin('official-adopter', 'super_admin');
        $manager->update($knowledgeBase, $superAdmin, [
            'name' => $knowledgeBase->name,
            'description' => $knowledgeBase->description,
            'content' => $customContent,
        ]);

        $sync = $manager->sync();

        self::assertTrue($sync['customized']);
        self::assertFalse($sync['updated']);
        self::assertSame(trim($customContent), $knowledgeBase->fresh()->content);

        $revision = $manager->adoptOfficial($knowledgeBase->fresh('systemBinding'), $superAdmin);

        self::assertSame('official', $revision->source);
        self::assertSame($officialContent, $knowledgeBase->fresh()->content);
        self::assertSame(3, $knowledgeBase->revisions()->count());
    }

    public function test_system_knowledge_rejects_embedded_images_external_links_and_secret_like_content(): void
    {
        Queue::fake();
        $knowledgeBase = app(SystemKnowledgeBaseManager::class)->sync()['knowledge_base'];
        $superAdmin = $this->createAdmin('privacy-reviewer', 'super_admin');

        foreach ([
            "\n\n![后台截图](https://example.test/admin.png)",
            "\n\n[外部入口](https://example.test/admin)",
            "\n\n调试密钥：".'sk-'.str_repeat('a', 22),
        ] as $unsafeSuffix) {
            $this->actingAs($superAdmin, 'admin')
                ->put(route('admin.knowledge-bases.detail.update', ['knowledgeBaseId' => $knowledgeBase->getKey()]), [
                    'name' => $knowledgeBase->name,
                    'description' => $knowledgeBase->description,
                    'content' => (string) $knowledgeBase->content.$unsafeSuffix,
                    'file_type' => 'markdown',
                ])
                ->assertSessionHasErrors('content');
        }

        self::assertSame(1, $knowledgeBase->revisions()->count());
    }

    public function test_system_knowledge_edit_requires_every_official_route_entry(): void
    {
        Queue::fake();
        $knowledgeBase = app(SystemKnowledgeBaseManager::class)->sync()['knowledge_base'];
        $superAdmin = $this->createAdmin('route-completeness-reviewer', 'super_admin');
        $contentWithoutRoutes = (string) preg_replace(
            '/\[\[route:[^\]]+\]\]/u',
            '后台入口',
            (string) $knowledgeBase->content,
        );

        $this->actingAs($superAdmin, 'admin')
            ->put(route('admin.knowledge-bases.detail.update', ['knowledgeBaseId' => $knowledgeBase->getKey()]), [
                'name' => $knowledgeBase->name,
                'description' => $knowledgeBase->description,
                'content' => $contentWithoutRoutes,
                'file_type' => 'markdown',
            ])
            ->assertSessionHasErrors('content');

        self::assertSame(1, $knowledgeBase->revisions()->count());
    }

    public function test_unchanged_edit_does_not_create_a_duplicate_revision(): void
    {
        Queue::fake();
        $manager = app(SystemKnowledgeBaseManager::class);
        $knowledgeBase = $manager->sync()['knowledge_base'];
        $superAdmin = $this->createAdmin('idempotent-editor', 'super_admin');

        $manager->update($knowledgeBase, $superAdmin, [
            'name' => $knowledgeBase->name,
            'description' => $knowledgeBase->description,
            'content' => $knowledgeBase->content,
        ]);

        self::assertSame(1, $knowledgeBase->revisions()->count());
    }

    public function test_revision_history_keeps_the_initial_official_and_30_latest_revisions(): void
    {
        Queue::fake();
        $manager = app(SystemKnowledgeBaseManager::class);
        $knowledgeBase = $manager->sync()['knowledge_base'];
        $officialContent = (string) $knowledgeBase->content;
        $superAdmin = $this->createAdmin('revision-retention-owner', 'super_admin');

        for ($index = 1; $index <= 33; $index++) {
            $manager->update($knowledgeBase, $superAdmin, [
                'name' => $knowledgeBase->name,
                'description' => $knowledgeBase->description,
                'content' => $officialContent."\n\n## 管理员补充 {$index}\n\n这是第 {$index} 次受控修订，用于验证系统知识修订留存。",
            ]);
        }

        self::assertSame(31, $knowledgeBase->revisions()->count());
        self::assertTrue($knowledgeBase->revisions()->where('revision_number', 1)->exists());
        self::assertSame(34, (int) $knowledgeBase->revisions()->max('revision_number'));
    }

    public function test_sync_recognizes_content_that_already_matches_the_new_official_hash(): void
    {
        Queue::fake();
        $manager = app(SystemKnowledgeBaseManager::class);
        $knowledgeBase = $manager->sync()['knowledge_base'];
        $binding = $knowledgeBase->systemBinding;
        $binding->forceFill([
            'official_content_hash' => hash('sha256', 'previous official content'),
            'customized_at' => now()->subDay(),
        ])->save();

        $result = $manager->sync();

        self::assertFalse($result['customized']);
        self::assertNull($result['binding']->customized_at);
        self::assertSame(1, $knowledgeBase->revisions()->count());
    }

    public function test_adopting_the_already_current_official_version_is_idempotent(): void
    {
        Queue::fake();
        $manager = app(SystemKnowledgeBaseManager::class);
        $knowledgeBase = $manager->sync()['knowledge_base'];
        $superAdmin = $this->createAdmin('official-idempotent-owner', 'super_admin');

        $revision = $manager->adoptOfficial($knowledgeBase, $superAdmin);

        self::assertSame(1, $revision->revision_number);
        self::assertSame(1, $knowledgeBase->revisions()->count());
    }

    private function createAdmin(string $username, string $role): Admin
    {
        return Admin::query()->create([
            'username' => $username,
            'password' => 'secret-123',
            'email' => $username.'@example.com',
            'display_name' => $username,
            'role' => $role,
            'status' => 'active',
        ]);
    }
}
