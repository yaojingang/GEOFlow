<?php

namespace Tests\Feature;

use App\Models\Admin;
use App\Models\KnowledgeMediaAsset;
use App\Services\Admin\SystemUpdateManualCommandService;
use App\Services\AiWorkspace\AdminHelpFeatureRegistry;
use App\Services\AiWorkspace\AdminHelpMediaSelector;
use App\Services\AiWorkspace\AiConversationRepository;
use App\Services\AiWorkspace\SystemKnowledgeBaseManager;
use App\Services\AiWorkspace\SystemKnowledgeMediaManager;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

final class AiWorkspaceKnowledgeMediaTest extends TestCase
{
    use LazilyRefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('geoflow.admin_ui_v3_enabled', true);
    }

    public function test_bundled_media_manifest_imports_24_verified_assets_idempotently(): void
    {
        Queue::fake();
        Storage::fake('local');
        $manager = app(SystemKnowledgeMediaManager::class);

        $this->artisan('geoflow:sync-system-knowledge', [
            '--key' => 'ai_workspace_manual',
            '--media' => true,
        ])->expectsOutputToContain('24 imported')->assertSuccessful();
        $second = $manager->syncBundled();

        self::assertSame(['imported' => 0, 'updated' => 0, 'unchanged' => 24, 'total' => 24], $second);
        self::assertSame(24, KnowledgeMediaAsset::query()->where('is_active', true)->count());
        self::assertSame(24, KnowledgeMediaAsset::query()->distinct()->count('content_hash'));
        self::assertTrue(KnowledgeMediaAsset::query()->get()->every(
            fn (KnowledgeMediaAsset $asset): bool => $manager->isReadable($asset),
        ));

        $selected = app(AdminHelpMediaSelector::class)->select(
            $this->admin('bundled-media-reader', 'super_admin'),
            [[
                'knowledge_base_id' => app(SystemKnowledgeBaseManager::class)->binding()?->knowledge_base_id,
                'feature_id' => 'tasks',
                'section_path' => '任务管理与内容生产 > 创建与编辑流程',
            ]],
            'zh_CN',
        );
        self::assertCount(1, $selected);
        self::assertSame(
            'tasks.create.basics',
            KnowledgeMediaAsset::query()->findOrFail($selected[0]['id'])->asset_key,
        );
    }

    public function test_system_update_readiness_requires_every_bundled_media_identity(): void
    {
        Queue::fake();
        Storage::fake('local');
        $manager = app(SystemKnowledgeMediaManager::class);
        $knowledgeBase = app(SystemKnowledgeBaseManager::class)->sync()['knowledge_base'];
        $manager->syncBundled();
        $admin = $this->admin('readiness-owner', 'super_admin');
        $missing = KnowledgeMediaAsset::query()->where('asset_key', 'tasks.index')->firstOrFail();
        $manager->setActive($missing, $admin, false);
        $manager->replace(
            $knowledgeBase,
            $admin,
            $this->image('extra.png', $this->pngOne()),
            [...$this->metadata(), 'asset_key' => 'custom.extra'],
        );

        self::assertSame(24, KnowledgeMediaAsset::query()->where('is_active', true)->count());
        $pendingCommand = app(SystemUpdateManualCommandService::class)->manualCommands()[0];
        self::assertSame('pending', $pendingCommand['status']);
        self::assertSame(
            __('admin.system_updates.manual_commands.sync_system_knowledge_pending_desc'),
            $pendingCommand['status_description'],
        );

        $manager->setActive($missing, $admin, true);

        $completeCommand = app(SystemUpdateManualCommandService::class)->manualCommands()[0];
        self::assertSame('complete', $completeCommand['status']);
        self::assertSame(
            __('admin.system_updates.manual_commands.sync_system_knowledge_complete_desc'),
            $completeCommand['status_description'],
        );
    }

    public function test_system_update_readiness_rejects_stale_knowledge_and_media_content(): void
    {
        Queue::fake();
        Storage::fake('local');
        $knowledgeManager = app(SystemKnowledgeBaseManager::class);
        $mediaManager = app(SystemKnowledgeMediaManager::class);
        $binding = $knowledgeManager->sync()['binding'];
        $mediaManager->syncBundled();

        self::assertSame('complete', app(SystemUpdateManualCommandService::class)->manualCommands()[0]['status']);

        $binding->forceFill(['official_content_hash' => str_repeat('0', 64)])->save();
        self::assertSame('pending', app(SystemUpdateManualCommandService::class)->manualCommands()[0]['status']);

        $knowledgeManager->sync();
        $asset = KnowledgeMediaAsset::query()->where('asset_key', 'tasks.index')->firstOrFail();
        $asset->forceFill(['content_hash' => str_repeat('f', 64)])->save();

        self::assertSame('pending', app(SystemUpdateManualCommandService::class)->manualCommands()[0]['status']);
    }

    public function test_private_media_requires_login_and_keeps_inactive_versions_available_to_history(): void
    {
        config()->set('geoflow.admin_ui_v3_enabled', true);
        Queue::fake();
        Storage::fake('local');
        $knowledgeBase = app(SystemKnowledgeBaseManager::class)->sync()['knowledge_base'];
        $superAdmin = $this->admin('media-owner', 'super_admin');
        $asset = app(SystemKnowledgeMediaManager::class)->replace(
            $knowledgeBase,
            $superAdmin,
            $this->image('tasks.png', $this->pngOne()),
            $this->metadata(),
        );

        $this->get(route('admin.ai-workspace.media.show', ['mediaAsset' => $asset->getKey()]))
            ->assertUnauthorized();

        self::assertTrue(app(SystemKnowledgeMediaManager::class)->isReadable($asset));
        self::assertTrue(app(AdminHelpFeatureRegistry::class)->canAccessRoute($superAdmin, 'admin.tasks.create'));
        self::assertTrue($asset->knowledgeBase->isSystemManaged());

        $response = $this->actingAs($superAdmin, 'admin')
            ->get(route('admin.ai-workspace.media.show', ['mediaAsset' => $asset->getKey()]))
            ->assertOk()
            ->assertHeader('Content-Type', 'image/png')
            ->assertHeader('X-Content-Type-Options', 'nosniff')
            ->assertHeader('Cross-Origin-Resource-Policy', 'same-origin');
        $this->actingAs($superAdmin, 'admin')
            ->withHeader('If-None-Match', (string) $response->headers->get('ETag'))
            ->get(route('admin.ai-workspace.media.show', ['mediaAsset' => $asset->getKey()]))
            ->assertStatus(304);
        $this->actingAs($superAdmin, 'admin')
            ->get(route('admin.ai-workspace.media.show', [
                'mediaAsset' => $asset->getKey(),
                'variant' => 'thumbnail',
            ]))
            ->assertOk()
            ->assertHeader('Content-Type', 'image/webp');

        app(SystemKnowledgeMediaManager::class)->setActive($asset, $superAdmin, false);
        $this->actingAs($superAdmin, 'admin')
            ->withHeader('If-None-Match', '')
            ->get(route('admin.ai-workspace.media.show', ['mediaAsset' => $asset->getKey()]))
            ->assertOk();
    }

    public function test_major_app_version_change_marks_bundled_media_for_review_until_admin_clears_it(): void
    {
        Queue::fake();
        Storage::fake('local');
        config()->set('geoflow.app_version', '4.0.0');
        $knowledgeBase = app(SystemKnowledgeBaseManager::class)->sync()['knowledge_base'];
        $manager = app(SystemKnowledgeMediaManager::class);

        $manager->syncBundled();

        self::assertSame(24, KnowledgeMediaAsset::query()->where('needs_review', true)->count());
        $asset = KnowledgeMediaAsset::query()->firstOrFail();
        $superAdmin = $this->admin('media-reviewer', 'super_admin');
        $manager->updateMetadata($asset, $superAdmin, ['needs_review' => false]);
        self::assertFalse($asset->fresh()->needs_review);
    }

    public function test_replacement_creates_an_immutable_version_and_selector_returns_structured_media(): void
    {
        Queue::fake();
        Storage::fake('local');
        $knowledgeBase = app(SystemKnowledgeBaseManager::class)->sync()['knowledge_base'];
        $superAdmin = $this->admin('media-replacer', 'super_admin');
        $manager = app(SystemKnowledgeMediaManager::class);
        $first = $manager->replace(
            $knowledgeBase,
            $superAdmin,
            $this->image('tasks.png', $this->pngOne()),
            $this->metadata(),
        );
        $second = $manager->replace(
            $knowledgeBase,
            $superAdmin,
            $this->image('tasks-new.png', $this->pngTwo()),
            $this->metadata(),
        );

        self::assertSame(1, $first->asset_version);
        self::assertSame(2, $second->asset_version);
        self::assertSame($first->getKey(), $second->supersedes_id);
        self::assertFalse($first->fresh()->is_active);
        self::assertTrue($second->is_active);
        self::assertSame(2, KnowledgeMediaAsset::query()->count());

        $selected = app(AdminHelpMediaSelector::class)->select($superAdmin, [[
            'knowledge_base_id' => $knowledgeBase->getKey(),
            'feature_id' => 'tasks',
            'section_path' => '内容生产与任务 > 任务创建',
        ]], 'zh_CN');

        self::assertCount(1, $selected);
        self::assertSame($second->getKey(), $selected[0]['id']);
        self::assertSame(2, $selected[0]['version']);
        self::assertStringContainsString('/ai-workspace/media/', $selected[0]['url']);
        self::assertStringContainsString('variant=thumbnail', $selected[0]['thumbnail_url']);
        self::assertSame([], app(AdminHelpMediaSelector::class)->select($superAdmin, [[
            'knowledge_base_id' => $knowledgeBase->getKey(),
            'feature_id' => 'tasks',
            'section_path' => '任务创建',
        ]], 'en'));
        self::assertSame([], app(AdminHelpMediaSelector::class)->select($superAdmin, [[
            'knowledge_base_id' => $knowledgeBase->getKey(),
            'feature_id' => 'articles',
            'section_path' => '文章管理 > 文章审核',
        ]], 'zh_CN'));

        $manager->replace(
            $knowledgeBase,
            $superAdmin,
            $this->image('system-update.png', $this->pngOne()),
            [
                'asset_key' => 'system.updates',
                'section_key' => '系统更新',
                'route_name' => 'admin.system-updates.index',
                'title' => '系统更新中心',
                'alt_text' => '系统更新中心页面',
                'keywords' => ['系统更新'],
                'locale' => 'zh_CN',
            ],
        );
        $regularAdmin = $this->admin('media-reader', 'admin');
        self::assertSame([], app(AdminHelpMediaSelector::class)->select($regularAdmin, [[
            'knowledge_base_id' => $knowledgeBase->getKey(),
            'feature_id' => 'system-updates',
            'section_path' => '系统更新',
        ]], 'zh_CN'));
    }

    public function test_media_selection_does_not_read_image_files_before_the_text_answer(): void
    {
        Queue::fake();
        Storage::fake('local');
        $knowledgeBase = app(SystemKnowledgeBaseManager::class)->sync()['knowledge_base'];
        $superAdmin = $this->admin('media-latency-reviewer', 'super_admin');
        $manager = app(SystemKnowledgeMediaManager::class);
        $asset = $manager->replace(
            $knowledgeBase,
            $superAdmin,
            $this->image('tasks.png', $this->pngOne()),
            $this->metadata(),
        );
        Storage::disk('local')->delete([$asset->storage_path, $asset->thumbnail_path]);

        $selected = app(AdminHelpMediaSelector::class)->select($superAdmin, [[
            'knowledge_base_id' => $knowledgeBase->getKey(),
            'feature_id' => 'tasks',
            'section_path' => '内容生产与任务 > 任务创建',
        ]], 'zh_CN');

        self::assertSame($asset->getKey(), $selected[0]['id']);
        $this->actingAs($superAdmin, 'admin')
            ->get(route('admin.ai-workspace.media.show', ['mediaAsset' => $asset->getKey()]))
            ->assertNotFound();
    }

    public function test_prune_keeps_media_referenced_by_live_history_and_removes_unreferenced_old_media(): void
    {
        Queue::fake();
        Storage::fake('local');
        $knowledgeBase = app(SystemKnowledgeBaseManager::class)->sync()['knowledge_base'];
        $superAdmin = $this->admin('media-pruner', 'super_admin');
        $manager = app(SystemKnowledgeMediaManager::class);
        $referenced = $manager->replace(
            $knowledgeBase,
            $superAdmin,
            $this->image('referenced.png', $this->pngOne()),
            $this->metadata(),
        );
        $unreferenced = $manager->replace(
            $knowledgeBase,
            $superAdmin,
            $this->image('unreferenced.png', $this->pngTwo()),
            ['asset_key' => 'tasks.create.unreferenced'] + $this->metadata(),
        );
        $manager->setActive($referenced, $superAdmin, false);
        $manager->setActive($unreferenced, $superAdmin, false);
        KnowledgeMediaAsset::query()->whereKey([$referenced->getKey(), $unreferenced->getKey()])->update([
            'created_at' => now()->subDays(100),
            'updated_at' => now()->subDays(100),
        ]);

        $conversation = app(AiConversationRepository::class)->create($superAdmin, '媒体历史');
        app(AiConversationRepository::class)->append($conversation, 'assistant', '带图回答', [
            'related_media' => [['id' => $referenced->getKey()]],
        ]);

        $this->artisan('geoflow:prune-ai-workspace', ['--days' => 90, '--dry-run' => true])
            ->expectsOutputToContain('1 inactive knowledge media assets are eligible')
            ->assertSuccessful();
        self::assertTrue(KnowledgeMediaAsset::query()->whereKey($unreferenced->getKey())->exists());

        $this->artisan('geoflow:prune-ai-workspace', ['--days' => 90])->assertSuccessful();

        self::assertTrue(KnowledgeMediaAsset::query()->whereKey($referenced->getKey())->exists());
        self::assertFalse(KnowledgeMediaAsset::query()->whereKey($unreferenced->getKey())->exists());
        Storage::disk('local')->assertExists((string) $referenced->storage_path);
        Storage::disk('local')->assertMissing((string) $unreferenced->storage_path);
        Storage::disk('local')->assertMissing((string) $unreferenced->thumbnail_path);
    }

    public function test_media_management_routes_require_protected_admin_and_write_activity_logs(): void
    {
        Queue::fake();
        Storage::fake('local');
        if (! Schema::hasTable('admin_activity_logs')) {
            Schema::create('admin_activity_logs', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('admin_id');
                $table->string('admin_username');
                $table->string('admin_role');
                $table->string('action');
                $table->string('request_method');
                $table->string('page')->nullable();
                $table->string('target_type')->nullable();
                $table->unsignedBigInteger('target_id')->nullable();
                $table->string('ip_address')->nullable();
                $table->text('details')->nullable();
                $table->timestamps();
            });
        }
        $knowledgeBase = app(SystemKnowledgeBaseManager::class)->sync()['knowledge_base'];
        $regularAdmin = $this->admin('media-regular', 'admin');
        $superAdmin = $this->admin('media-super', 'super_admin');
        $payload = [
            'image' => $this->image('tasks.png', $this->pngOne()),
            ...$this->metadata(),
            'keywords' => '任务, 创建, 模型',
        ];

        $this->actingAs($regularAdmin, 'admin')
            ->post(route('admin.knowledge-bases.media.store', ['knowledgeBaseId' => $knowledgeBase->getKey()]), $payload)
            ->assertForbidden();

        $payload['image'] = $this->image('tasks.png', $this->pngOne());
        $this->actingAs($superAdmin, 'admin')
            ->post(route('admin.knowledge-bases.media.store', ['knowledgeBaseId' => $knowledgeBase->getKey()]), $payload)
            ->assertRedirect(route('admin.knowledge-bases.detail', ['knowledgeBaseId' => $knowledgeBase->getKey()]));

        $asset = KnowledgeMediaAsset::query()->firstOrFail();
        $this->actingAs($superAdmin, 'admin')
            ->put(route('admin.knowledge-bases.media.update', [
                'knowledgeBaseId' => $knowledgeBase->getKey(),
                'mediaAsset' => $asset->getKey(),
            ]), [
                'section_key' => '任务创建',
                'route_name' => 'admin.tasks.create',
                'title' => '更新后的任务表单',
                'alt_text' => '更新后的任务创建配置表单',
                'caption' => '更新后的说明',
                'keywords' => '任务, 表单',
                'sort_order' => 9,
            ])->assertRedirect();

        $this->actingAs($superAdmin, 'admin')
            ->post(route('admin.knowledge-bases.media.replace', [
                'knowledgeBaseId' => $knowledgeBase->getKey(),
                'mediaAsset' => $asset->getKey(),
            ]), [
                'image' => $this->image('tasks-new.png', $this->pngTwo()),
            ])->assertRedirect();
        $replacement = KnowledgeMediaAsset::query()->latest('asset_version')->firstOrFail();

        $this->actingAs($superAdmin, 'admin')
            ->post(route('admin.knowledge-bases.media.toggle', [
                'knowledgeBaseId' => $knowledgeBase->getKey(),
                'mediaAsset' => $replacement->getKey(),
            ]), ['active' => '0'])
            ->assertRedirect();

        self::assertSame('更新后的任务表单', $replacement->fresh()->title);
        self::assertFalse($replacement->fresh()->is_active);
        foreach ([
            'system_knowledge.media_imported',
            'system_knowledge.media_updated',
            'system_knowledge.media_replaced',
            'system_knowledge.media_status_changed',
        ] as $action) {
            $this->assertDatabaseHas('admin_activity_logs', ['action' => $action]);
        }

        $this->actingAs($superAdmin, 'admin')
            ->get(route('admin.knowledge-bases.detail', ['knowledgeBaseId' => $knowledgeBase->getKey()]))
            ->assertOk()
            ->assertSee('variant=thumbnail', false);
    }

    public function test_enabling_a_historical_version_makes_it_the_only_active_asset_version(): void
    {
        Queue::fake();
        Storage::fake('local');
        $knowledgeBase = app(SystemKnowledgeBaseManager::class)->sync()['knowledge_base'];
        $superAdmin = $this->admin('media-version-owner', 'super_admin');
        $manager = app(SystemKnowledgeMediaManager::class);
        $first = $manager->replace(
            $knowledgeBase,
            $superAdmin,
            $this->image('first.png', $this->pngOne()),
            $this->metadata(),
        );
        $second = $manager->replace(
            $knowledgeBase,
            $superAdmin,
            $this->image('second.png', $this->pngTwo()),
            $this->metadata(),
        );

        $manager->setActive($first, $superAdmin, true);

        self::assertTrue($first->fresh()->is_active);
        self::assertFalse($second->fresh()->is_active);
        self::assertSame(1, KnowledgeMediaAsset::query()
            ->where('asset_key', $first->asset_key)
            ->where('locale', $first->locale)
            ->where('is_active', true)
            ->count());
    }

    public function test_regular_admin_cannot_read_media_for_a_super_admin_route(): void
    {
        Queue::fake();
        Storage::fake('local');
        $knowledgeBase = app(SystemKnowledgeBaseManager::class)->sync()['knowledge_base'];
        $superAdmin = $this->admin('protected-media-owner', 'super_admin');
        $regularAdmin = $this->admin('protected-media-reader', 'admin');
        $asset = app(SystemKnowledgeMediaManager::class)->replace(
            $knowledgeBase,
            $superAdmin,
            $this->image('url-import.png', $this->pngOne()),
            [
                ...$this->metadata(),
                'asset_key' => 'url-import.index',
                'section_key' => 'URL 导入',
                'route_name' => 'admin.url-import',
                'title' => 'URL 导入',
                'alt_text' => 'URL 导入管理页面',
            ],
        );

        $this->actingAs($regularAdmin, 'admin')
            ->get(route('admin.ai-workspace.media.show', ['mediaAsset' => $asset->getKey()]))
            ->assertNotFound();
        $this->actingAs($regularAdmin, 'admin')
            ->get(route('admin.knowledge-bases.detail', ['knowledgeBaseId' => $knowledgeBase->getKey()]))
            ->assertOk()
            ->assertDontSee('/ai-workspace/media/'.$asset->getKey(), false);

        $this->actingAs($superAdmin, 'admin')
            ->get(route('admin.ai-workspace.media.show', ['mediaAsset' => $asset->getKey()]))
            ->assertOk();
    }

    public function test_replacement_keeps_the_asset_identity_even_when_the_request_is_tampered(): void
    {
        Queue::fake();
        Storage::fake('local');
        $knowledgeBase = app(SystemKnowledgeBaseManager::class)->sync()['knowledge_base'];
        $superAdmin = $this->admin('media-identity-owner', 'super_admin');
        $asset = app(SystemKnowledgeMediaManager::class)->replace(
            $knowledgeBase,
            $superAdmin,
            $this->image('original.png', $this->pngOne()),
            $this->metadata(),
        );

        $this->actingAs($superAdmin, 'admin')
            ->post(route('admin.knowledge-bases.media.replace', [
                'knowledgeBaseId' => $knowledgeBase->getKey(),
                'mediaAsset' => $asset->getKey(),
            ]), [
                'image' => $this->image('replacement.png', $this->pngTwo()),
                'asset_key' => 'system.updates',
                'locale' => 'zh_CN',
            ])->assertRedirect();

        $replacement = KnowledgeMediaAsset::query()->orderByDesc('asset_version')->firstOrFail();
        self::assertSame($asset->asset_key, $replacement->asset_key);
        self::assertSame($asset->locale, $replacement->locale);
        self::assertSame($asset->getKey(), $replacement->supersedes_id);
    }

    /** @return array<string, mixed> */
    private function metadata(): array
    {
        return [
            'asset_key' => 'tasks.create.form',
            'section_key' => '任务创建',
            'route_name' => 'admin.tasks.create',
            'title' => '任务创建表单',
            'alt_text' => 'GEOFlow 任务创建页的配置表单',
            'caption' => '在这里选择模型、标题库和知识库。',
            'keywords' => ['任务', '创建', '模型'],
            'locale' => 'zh_CN',
            'sort_order' => 1,
        ];
    }

    private function image(string $name, string $bytes): UploadedFile
    {
        return UploadedFile::fake()->createWithContent($name, $bytes);
    }

    private function pngOne(): string
    {
        return (string) base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=', true);
    }

    private function pngTwo(): string
    {
        return (string) base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAusB9Wl2ZQAAAABJRU5ErkJggg==', true);
    }

    private function admin(string $username, string $role): Admin
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
