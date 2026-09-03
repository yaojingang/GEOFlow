<?php

namespace Tests\Feature;

use App\Models\Admin;
use App\Models\AdminAiSetting;
use App\Models\AiConversation;
use App\Models\AiModel;
use App\Models\AiWorkspaceRun;
use App\Models\Article;
use App\Models\ArticleAiOptimizationRun;
use App\Models\Author;
use App\Models\Category;
use App\Models\KnowledgeBase;
use App\Models\KnowledgeFactGenerationRun;
use App\Models\KnowledgeFactLibrary;
use App\Models\TitleGenerationRun;
use App\Models\TitleLibrary;
use App\Services\Admin\AdminAiDependencyInspector;
use App\Services\Admin\AdminAiSharingService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Lang;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\MessageBag;
use Illuminate\Support\Str;
use Illuminate\Support\ViewErrorBag;
use RuntimeException;
use Tests\TestCase;

class AdminAiSharingManagementTest extends TestCase
{
    use RefreshDatabase;

    public function test_dependency_inspector_counts_only_pending_title_generation_runs_for_the_admin(): void
    {
        $admin = $this->admin('title-run-admin', 'admin');
        $otherAdmin = $this->admin('other-title-run-admin', 'admin');
        $library = TitleLibrary::query()->create(['name' => 'Pending title runs']);

        $this->titleGenerationRun($library, $admin, TitleGenerationRun::STATUS_QUEUED);
        $this->titleGenerationRun($library, $admin, TitleGenerationRun::STATUS_COMPLETED);
        $this->titleGenerationRun($library, $otherAdmin, TitleGenerationRun::STATUS_RUNNING);

        $this->assertSame([
            'title_generation_runs' => 1,
            'article_ai_optimization_runs' => 0,
            'knowledge_fact_generation_runs' => 0,
            'ai_workspace_runs' => 0,
            'url_import_jobs' => 0,
            'enterprise_knowledge_projects' => 0,
            'total' => 1,
        ], app(AdminAiDependencyInspector::class)->pendingTaskCounts($admin));
    }

    public function test_dependency_inspector_counts_retryable_title_generation_runs(): void
    {
        $admin = $this->admin('retryable-title-admin', 'admin');
        $library = TitleLibrary::query()->create(['name' => 'Retryable title runs']);
        $model = $this->model($admin, 'chat');

        $this->titleGenerationRun($library, $admin, TitleGenerationRun::STATUS_PARTIAL, [
            'ai_model_id' => $model->id,
            'manual_retry_count' => 1,
        ]);
        $this->titleGenerationRun($library, $admin, TitleGenerationRun::STATUS_FAILED, [
            'ai_model_id' => $model->id,
            'failure_code' => 'request_budget_exhausted',
        ]);

        $this->assertSame(
            1,
            app(AdminAiDependencyInspector::class)->pendingTaskCounts($admin)['title_generation_runs'],
        );
    }

    public function test_dependency_inspector_counts_only_active_article_optimization_runs_for_the_admin(): void
    {
        $admin = $this->admin('optimization-run-admin', 'admin');
        $otherAdmin = $this->admin('other-optimization-run-admin', 'admin');
        $author = Author::query()->create(['name' => 'Optimization author']);
        $category = Category::query()->create([
            'name' => 'Optimization category',
            'slug' => 'optimization-category',
        ]);
        $article = Article::query()->create([
            'title' => 'Optimization run article',
            'slug' => 'optimization-run-article',
            'content' => 'Content',
            'category_id' => $category->id,
            'author_id' => $author->id,
            'status' => 'draft',
            'review_status' => 'pending',
        ]);

        $this->articleOptimizationRun($article, $admin, ArticleAiOptimizationRun::STATUS_AWAITING_QUALITY);
        $this->articleOptimizationRun($article, $admin, ArticleAiOptimizationRun::STATUS_COMPLETED);
        $this->articleOptimizationRun($article, $otherAdmin, ArticleAiOptimizationRun::STATUS_REWRITING);

        $this->assertSame(
            1,
            app(AdminAiDependencyInspector::class)->pendingTaskCounts($admin)['article_ai_optimization_runs'],
        );
    }

    public function test_dependency_inspector_counts_only_active_knowledge_fact_generation_runs_for_the_admin(): void
    {
        $admin = $this->admin('knowledge-run-admin', 'admin');
        $otherAdmin = $this->admin('other-knowledge-run-admin', 'admin');
        $knowledgeBase = KnowledgeBase::query()->create(['name' => 'Pending knowledge facts']);
        $library = KnowledgeFactLibrary::query()->create(['knowledge_base_id' => $knowledgeBase->id]);

        $this->knowledgeFactGenerationRun($library, $admin, 'running');
        $this->knowledgeFactGenerationRun($library, $admin, 'completed');
        $this->knowledgeFactGenerationRun($library, $otherAdmin, 'queued');

        $this->assertSame(
            1,
            app(AdminAiDependencyInspector::class)->pendingTaskCounts($admin)['knowledge_fact_generation_runs'],
        );
    }

    public function test_dependency_inspector_counts_only_non_terminal_ai_workspace_runs_for_the_admin(): void
    {
        $admin = $this->admin('workspace-run-admin', 'admin');
        $otherAdmin = $this->admin('other-workspace-run-admin', 'admin');

        $this->aiWorkspaceRun($admin, 'awaiting_approval');
        $this->aiWorkspaceRun($admin, 'completed');
        $this->aiWorkspaceRun($otherAdmin, 'running');

        $this->assertSame(
            1,
            app(AdminAiDependencyInspector::class)->pendingTaskCounts($admin)['ai_workspace_runs'],
        );
    }

    public function test_dependency_inspector_treats_optional_missing_run_tables_as_zero(): void
    {
        $admin = $this->admin('missing-run-tables-admin', 'admin');

        foreach ([
            'title_generation_runs',
            'article_ai_optimization_runs',
            'knowledge_fact_generation_runs',
            'ai_workspace_runs',
            'url_import_jobs',
            'enterprise_knowledge_projects',
        ] as $table) {
            $temporaryTable = $table.'_temporarily_unavailable';
            Schema::rename($table, $temporaryTable);

            try {
                $counts = app(AdminAiDependencyInspector::class)->pendingTaskCounts($admin);
                $this->assertSame(0, $counts[$table]);
            } finally {
                Schema::rename($temporaryTable, $table);
            }
        }
    }

    public function test_dependency_inspector_treats_run_tables_without_stable_admin_identity_as_zero(): void
    {
        $admin = $this->admin('missing-run-identity-admin', 'admin');
        $temporaryTable = 'title_generation_runs_with_identity';
        Schema::rename('title_generation_runs', $temporaryTable);
        Schema::create('title_generation_runs', function (Blueprint $table): void {
            $table->id();
            $table->string('status');
        });
        DB::flushQueryLog();
        DB::enableQueryLog();

        try {
            $counts = app(AdminAiDependencyInspector::class)->pendingTaskCounts($admin);
            $runQueries = array_filter(
                DB::getQueryLog(),
                static fn (array $query): bool => str_contains($query['query'], 'from "title_generation_runs"'),
            );
            $this->assertSame(0, $counts['title_generation_runs']);
            $this->assertSame([], $runQueries);
        } finally {
            DB::disableQueryLog();
            Schema::dropIfExists('title_generation_runs');
            Schema::rename($temporaryTable, 'title_generation_runs');
        }
    }

    public function test_legacy_title_run_table_with_stable_identity_still_blocks_admin_deletion(): void
    {
        $superAdmin = $this->admin('root', 'super_admin');
        $ordinaryAdmin = $this->admin('legacy-title-run-admin', 'admin');
        $temporaryTable = 'title_generation_runs_with_retry_metadata';
        Schema::rename('title_generation_runs', $temporaryTable);
        Schema::create('title_generation_runs', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('created_by_admin_id')->nullable();
            $table->string('status');
        });
        DB::table('title_generation_runs')->insert([
            [
                'created_by_admin_id' => $ordinaryAdmin->id,
                'status' => TitleGenerationRun::STATUS_QUEUED,
            ],
            [
                'created_by_admin_id' => $ordinaryAdmin->id,
                'status' => TitleGenerationRun::STATUS_FAILED,
            ],
            [
                'created_by_admin_id' => $ordinaryAdmin->id,
                'status' => TitleGenerationRun::STATUS_COMPLETED,
            ],
        ]);

        try {
            $this->assertSame(
                2,
                app(AdminAiDependencyInspector::class)->pendingTaskCounts($ordinaryAdmin)['title_generation_runs'],
            );

            $this->actingAs($superAdmin, 'admin')
                ->from(route('admin.admin-users.index'))
                ->post(route('admin.admin-users.delete', ['adminId' => $ordinaryAdmin->id]))
                ->assertRedirect(route('admin.admin-users.index'))
                ->assertSessionHasErrors('admin');

            $this->assertModelExists($ordinaryAdmin);
        } finally {
            Schema::dropIfExists('title_generation_runs');
            Schema::rename($temporaryTable, 'title_generation_runs');
        }
    }

    public function test_new_ordinary_admin_defaults_to_independent_ai_configuration(): void
    {
        $superAdmin = $this->admin('root', 'super_admin');

        $this->actingAs($superAdmin, 'admin')
            ->post(route('admin.admin-users.store'), $this->createPayload('independent-admin'))
            ->assertRedirect(route('admin.admin-users.index'));

        $created = Admin::query()->where('username', 'independent-admin')->firstOrFail();

        $this->assertNull($created->shared_ai_config_owner_id);
        $this->assertSame(1, $created->ai_config_access_version);
    }

    public function test_super_admin_can_explicitly_share_their_configuration_when_creating_an_admin(): void
    {
        $superAdmin = $this->admin('root', 'super_admin');

        $this->actingAs($superAdmin, 'admin')
            ->post(route('admin.admin-users.store'), [
                ...$this->createPayload('shared-admin'),
                'ai_config_mode' => 'shared_current_super',
            ])
            ->assertRedirect(route('admin.admin-users.index'));

        $created = Admin::query()->where('username', 'shared-admin')->firstOrFail();

        $this->assertSame($superAdmin->id, $created->shared_ai_config_owner_id);
        $this->assertSame(1, $created->ai_config_access_version);
    }

    public function test_admin_creation_rejects_forged_ai_configuration_provider_identity(): void
    {
        $superAdmin = $this->admin('root', 'super_admin');
        $unrelatedSuperAdmin = $this->admin('other-root', 'super_admin');

        $this->actingAs($superAdmin, 'admin')
            ->from(route('admin.admin-users.create'))
            ->post(route('admin.admin-users.store'), [
                ...$this->createPayload('forged-admin'),
                'ai_config_mode' => 'shared_current_super',
                'shared_ai_config_owner_id' => $unrelatedSuperAdmin->id,
            ])
            ->assertRedirect(route('admin.admin-users.create'))
            ->assertSessionHasErrors('shared_ai_config_owner_id');

        $this->assertDatabaseMissing('admins', ['username' => 'forged-admin']);
    }

    public function test_switching_from_shared_to_independent_increments_access_version_and_clears_only_shared_defaults(): void
    {
        $superAdmin = $this->admin('root', 'super_admin');
        $ordinaryAdmin = $this->admin('editor', 'admin', [
            'shared_ai_config_owner_id' => $superAdmin->id,
        ]);
        $personalChat = $this->model($ordinaryAdmin, 'chat');
        $sharedEmbedding = $this->model($superAdmin, 'embedding');
        $settings = $this->settings($ordinaryAdmin, $personalChat, $sharedEmbedding, $superAdmin);

        $this->actingAs($superAdmin, 'admin')
            ->post(
                route('admin.admin-users.update', ['adminId' => $ordinaryAdmin->id]),
                $this->updatePayload($ordinaryAdmin, 'independent'),
            )
            ->assertRedirect(route('admin.admin-users.index'));

        $ordinaryAdmin->refresh();
        $settings->refresh();

        $this->assertNull($ordinaryAdmin->shared_ai_config_owner_id);
        $this->assertSame(2, $ordinaryAdmin->ai_config_access_version);
        $this->assertSame($personalChat->id, $settings->default_chat_model_id);
        $this->assertNull($settings->default_embedding_model_id);
        $this->assertSame($superAdmin->id, $settings->updated_by_admin_id);
    }

    public function test_switching_to_current_super_admin_and_repeating_the_same_mode_has_stable_versions(): void
    {
        $superAdmin = $this->admin('root', 'super_admin');
        $ordinaryAdmin = $this->admin('editor', 'admin');

        $this->actingAs($superAdmin, 'admin')
            ->post(
                route('admin.admin-users.update', ['adminId' => $ordinaryAdmin->id]),
                $this->updatePayload($ordinaryAdmin, 'shared_current_super'),
            )
            ->assertRedirect(route('admin.admin-users.index'));

        $ordinaryAdmin->refresh();
        $this->assertSame($superAdmin->id, $ordinaryAdmin->shared_ai_config_owner_id);
        $this->assertSame(2, $ordinaryAdmin->ai_config_access_version);

        $this->post(
            route('admin.admin-users.update', ['adminId' => $ordinaryAdmin->id]),
            $this->updatePayload($ordinaryAdmin, 'shared_current_super'),
        )->assertRedirect(route('admin.admin-users.index'));

        $this->assertSame(2, $ordinaryAdmin->refresh()->ai_config_access_version);
    }

    public function test_stale_ai_configuration_access_version_does_not_overwrite_a_newer_change(): void
    {
        $superAdmin = $this->admin('root', 'super_admin');
        $ordinaryAdmin = $this->admin('editor', 'admin', [
            'ai_config_access_version' => 4,
        ]);

        $payload = $this->updatePayload($ordinaryAdmin, 'shared_current_super');
        $payload['expected_ai_config_access_version'] = 3;

        $this->actingAs($superAdmin, 'admin')
            ->from(route('admin.admin-users.edit', ['adminId' => $ordinaryAdmin->id]))
            ->post(route('admin.admin-users.update', ['adminId' => $ordinaryAdmin->id]), $payload)
            ->assertRedirect(route('admin.admin-users.edit', ['adminId' => $ordinaryAdmin->id]))
            ->assertSessionHasErrors('ai_config_mode');

        $ordinaryAdmin->refresh();
        $this->assertNull($ordinaryAdmin->shared_ai_config_owner_id);
        $this->assertSame(4, $ordinaryAdmin->ai_config_access_version);
    }

    public function test_deactivating_an_admin_increments_access_version_once_for_update_and_toggle_paths(): void
    {
        $superAdmin = $this->admin('root', 'super_admin');
        $updatedAdmin = $this->admin('updated-editor', 'admin');
        $toggledAdmin = $this->admin('toggled-editor', 'admin');

        $updatePayload = $this->updatePayload($updatedAdmin, 'independent');
        $updatePayload['status'] = 'inactive';

        $this->actingAs($superAdmin, 'admin')
            ->post(
                route('admin.admin-users.update', ['adminId' => $updatedAdmin->id]),
                $updatePayload,
            )
            ->assertRedirect(route('admin.admin-users.index'));

        $updatedAdmin->refresh();
        $this->assertSame('inactive', $updatedAdmin->status);
        $this->assertSame(2, $updatedAdmin->ai_config_access_version);

        $this->post(
            route('admin.admin-users.toggle-status', ['adminId' => $toggledAdmin->id]),
            ['next_status' => 'inactive'],
        )->assertRedirect(route('admin.admin-users.index'));

        $toggledAdmin->refresh();
        $this->assertSame('inactive', $toggledAdmin->status);
        $this->assertSame(2, $toggledAdmin->ai_config_access_version);

        $this->post(
            route('admin.admin-users.toggle-status', ['adminId' => $toggledAdmin->id]),
            ['next_status' => 'inactive'],
        )->assertRedirect(route('admin.admin-users.index'));

        $this->assertSame(2, $toggledAdmin->refresh()->ai_config_access_version);
    }

    public function test_reactivating_an_admin_increments_access_version_and_revokes_authentication_for_update_and_toggle_paths(): void
    {
        $superAdmin = $this->admin('root', 'super_admin');
        $updatedAdmin = $this->admin('updated-editor', 'admin', [
            'status' => 'inactive',
            'ai_config_access_version' => 5,
        ]);
        $toggledAdmin = $this->admin('toggled-editor', 'admin', [
            'status' => 'inactive',
            'ai_config_access_version' => 7,
        ]);
        $updatedAuthVersion = (int) $updatedAdmin->auth_version;
        $toggledAuthVersion = (int) $toggledAdmin->auth_version;
        $payload = $this->updatePayload($updatedAdmin, 'independent');
        $payload['status'] = 'active';

        $this->actingAs($superAdmin, 'admin')
            ->post(
                route('admin.admin-users.update', ['adminId' => $updatedAdmin->id]),
                $payload,
            )
            ->assertRedirect(route('admin.admin-users.index'));

        $updatedAdmin->refresh();
        $this->assertSame('active', $updatedAdmin->status);
        $this->assertSame(6, $updatedAdmin->ai_config_access_version);
        $this->assertSame($updatedAuthVersion + 1, (int) $updatedAdmin->auth_version);

        $this->post(
            route('admin.admin-users.update', ['adminId' => $updatedAdmin->id]),
            $this->updatePayload($updatedAdmin, 'independent'),
        )->assertRedirect(route('admin.admin-users.index'));
        $this->assertSame(6, $updatedAdmin->refresh()->ai_config_access_version);
        $this->assertSame($updatedAuthVersion + 1, (int) $updatedAdmin->auth_version);

        $this->post(
            route('admin.admin-users.toggle-status', ['adminId' => $toggledAdmin->id]),
            ['next_status' => 'active'],
        )->assertRedirect(route('admin.admin-users.index'));

        $toggledAdmin->refresh();
        $this->assertSame('active', $toggledAdmin->status);
        $this->assertSame(8, $toggledAdmin->ai_config_access_version);
        $this->assertSame($toggledAuthVersion + 1, (int) $toggledAdmin->auth_version);

        $this->post(
            route('admin.admin-users.toggle-status', ['adminId' => $toggledAdmin->id]),
            ['next_status' => 'active'],
        )->assertRedirect(route('admin.admin-users.index'));

        $this->assertSame(8, $toggledAdmin->refresh()->ai_config_access_version);
        $this->assertSame($toggledAuthVersion + 1, (int) $toggledAdmin->auth_version);
    }

    public function test_admin_with_owned_active_or_archived_models_cannot_be_hard_deleted(): void
    {
        $superAdmin = $this->admin('root', 'super_admin');
        $activeOwner = $this->admin('active-model-owner', 'admin');
        $archivedOwner = $this->admin('archived-model-owner', 'admin');
        $activeModel = $this->model($activeOwner, 'chat');
        $archivedModel = $this->model($archivedOwner, 'chat');
        $archivedModel->forceFill(['archived_at' => now()])->save();

        $this->actingAs($superAdmin, 'admin');
        foreach ([[$activeOwner, $activeModel], [$archivedOwner, $archivedModel]] as [$owner, $model]) {
            $this->from(route('admin.admin-users.index'))
                ->post(route('admin.admin-users.delete', ['adminId' => $owner->id]))
                ->assertRedirect(route('admin.admin-users.index'))
                ->assertSessionHasErrors('admin');

            $this->assertModelExists($owner);
            $this->assertModelExists($model);
            $this->assertSame(
                __('admin.admin_users.error.delete_has_ai_dependencies', [
                    'models' => 1,
                    'tasks' => 0,
                    'dependents' => 0,
                ]),
                session('errors')->first('admin'),
            );
        }
    }

    public function test_admin_with_pending_ai_runs_cannot_be_hard_deleted(): void
    {
        $superAdmin = $this->admin('root', 'super_admin');
        $ordinaryAdmin = $this->admin('pending-run-owner', 'admin');
        $library = TitleLibrary::query()->create(['name' => 'Delete dependency runs']);
        $run = $this->titleGenerationRun($library, $ordinaryAdmin, TitleGenerationRun::STATUS_RUNNING);

        $this->actingAs($superAdmin, 'admin')
            ->from(route('admin.admin-users.index'))
            ->post(route('admin.admin-users.delete', ['adminId' => $ordinaryAdmin->id]))
            ->assertRedirect(route('admin.admin-users.index'))
            ->assertSessionHasErrors('admin');

        $this->assertModelExists($ordinaryAdmin);
        $this->assertModelExists($run);
        $this->assertSame(
            __('admin.admin_users.error.delete_has_ai_dependencies', [
                'models' => 0,
                'tasks' => 1,
                'dependents' => 0,
            ]),
            session('errors')->first('admin'),
        );
    }

    public function test_terminal_ai_runs_do_not_block_admin_hard_deletion(): void
    {
        $superAdmin = $this->admin('root', 'super_admin');
        $ordinaryAdmin = $this->admin('terminal-run-owner', 'admin');
        $library = TitleLibrary::query()->create(['name' => 'Terminal delete runs']);
        $run = $this->titleGenerationRun($library, $ordinaryAdmin, TitleGenerationRun::STATUS_COMPLETED);

        $this->actingAs($superAdmin, 'admin')
            ->post(route('admin.admin-users.delete', ['adminId' => $ordinaryAdmin->id]))
            ->assertRedirect(route('admin.admin-users.index'))
            ->assertSessionDoesntHaveErrors();

        $this->assertModelMissing($ordinaryAdmin);
        $this->assertNull($run->refresh()->created_by_admin_id);
    }

    public function test_admin_ai_settings_follow_the_existing_admin_deletion_lifecycle(): void
    {
        $superAdmin = $this->admin('root', 'super_admin');
        $ordinaryAdmin = $this->admin('settings-owner', 'admin');
        $settings = $this->settings($ordinaryAdmin, null, null, $superAdmin);

        $this->actingAs($superAdmin, 'admin')
            ->post(route('admin.admin-users.delete', ['adminId' => $ordinaryAdmin->id]))
            ->assertRedirect(route('admin.admin-users.index'));

        $this->assertModelMissing($ordinaryAdmin);
        $this->assertModelMissing($settings);
    }

    public function test_different_super_admin_profile_save_preserves_the_existing_shared_provider(): void
    {
        $previousProvider = $this->admin('previous-root', 'super_admin');
        $currentProvider = $this->admin('current-root', 'super_admin');
        $ordinaryAdmin = $this->admin('editor', 'admin', [
            'shared_ai_config_owner_id' => $previousProvider->id,
        ]);
        $previousChat = $this->model($previousProvider, 'chat');
        $settings = $this->settings($ordinaryAdmin, $previousChat, null, $previousProvider);

        $this->actingAs($currentProvider, 'admin')
            ->post(
                route('admin.admin-users.update', ['adminId' => $ordinaryAdmin->id]),
                $this->updatePayload($ordinaryAdmin, 'shared_current_super'),
            )
            ->assertRedirect(route('admin.admin-users.index'));

        $this->assertSame($previousProvider->id, $ordinaryAdmin->refresh()->shared_ai_config_owner_id);
        $this->assertSame(1, $ordinaryAdmin->ai_config_access_version);
        $this->assertSame($previousChat->id, $settings->fresh()->default_chat_model_id);
    }

    public function test_explicit_provider_switch_moves_sharing_to_the_current_super_admin(): void
    {
        $previousProvider = $this->admin('previous-root', 'super_admin');
        $currentProvider = $this->admin('current-root', 'super_admin');
        $ordinaryAdmin = $this->admin('editor', 'admin', [
            'shared_ai_config_owner_id' => $previousProvider->id,
        ]);
        $previousChat = $this->model($previousProvider, 'chat');
        $settings = $this->settings($ordinaryAdmin, $previousChat, null, $previousProvider);
        $payload = $this->updatePayload($ordinaryAdmin, 'shared_current_super');
        $payload['switch_shared_provider'] = '1';

        $this->actingAs($currentProvider, 'admin')
            ->post(
                route('admin.admin-users.update', ['adminId' => $ordinaryAdmin->id]),
                $payload,
            )
            ->assertRedirect(route('admin.admin-users.index'));

        $this->assertSame($currentProvider->id, $ordinaryAdmin->refresh()->shared_ai_config_owner_id);
        $this->assertSame(2, $ordinaryAdmin->ai_config_access_version);
        $this->assertNull($settings->fresh()->default_chat_model_id);
    }

    public function test_provider_switch_intent_is_rejected_when_the_actor_is_already_the_provider(): void
    {
        $provider = $this->admin('root', 'super_admin');
        $ordinaryAdmin = $this->admin('editor', 'admin', [
            'shared_ai_config_owner_id' => $provider->id,
        ]);
        $payload = $this->updatePayload($ordinaryAdmin, 'shared_current_super');
        $payload['switch_shared_provider'] = '1';

        $this->actingAs($provider, 'admin')
            ->from(route('admin.admin-users.edit', ['adminId' => $ordinaryAdmin->id]))
            ->post(route('admin.admin-users.update', ['adminId' => $ordinaryAdmin->id]), $payload)
            ->assertRedirect(route('admin.admin-users.edit', ['adminId' => $ordinaryAdmin->id]))
            ->assertSessionHasErrors('ai_config_mode');

        $this->assertSame($provider->id, $ordinaryAdmin->refresh()->shared_ai_config_owner_id);
        $this->assertSame(1, $ordinaryAdmin->ai_config_access_version);
    }

    public function test_provider_switch_intent_is_rejected_for_independent_mode_or_invalid_values(): void
    {
        $existingProvider = $this->admin('existing-root', 'super_admin');
        $currentActor = $this->admin('current-root', 'super_admin');
        $ordinaryAdmin = $this->admin('editor', 'admin', [
            'shared_ai_config_owner_id' => $existingProvider->id,
        ]);

        $independentPayload = $this->updatePayload($ordinaryAdmin, 'independent');
        $independentPayload['switch_shared_provider'] = '1';
        $this->actingAs($currentActor, 'admin')
            ->from(route('admin.admin-users.edit', ['adminId' => $ordinaryAdmin->id]))
            ->post(route('admin.admin-users.update', ['adminId' => $ordinaryAdmin->id]), $independentPayload)
            ->assertRedirect(route('admin.admin-users.edit', ['adminId' => $ordinaryAdmin->id]))
            ->assertSessionHasErrors('ai_config_mode');

        $invalidPayload = $this->updatePayload($ordinaryAdmin->refresh(), 'shared_current_super');
        $invalidPayload['switch_shared_provider'] = 'unexpected';
        $this->post(
            route('admin.admin-users.update', ['adminId' => $ordinaryAdmin->id]),
            $invalidPayload,
        )->assertSessionHasErrors('switch_shared_provider');

        $this->assertSame($existingProvider->id, $ordinaryAdmin->refresh()->shared_ai_config_owner_id);
        $this->assertSame(1, $ordinaryAdmin->ai_config_access_version);
    }

    public function test_stale_shared_provider_snapshot_cannot_overwrite_the_current_provider(): void
    {
        $existingProvider = $this->admin('existing-root', 'super_admin');
        $currentActor = $this->admin('current-root', 'super_admin');
        $forgedProvider = $this->admin('forged-root', 'super_admin');
        $ordinaryAdmin = $this->admin('editor', 'admin', [
            'shared_ai_config_owner_id' => $existingProvider->id,
            'ai_config_access_version' => 4,
        ]);
        $payload = $this->updatePayload($ordinaryAdmin, 'shared_current_super');
        $payload['expected_shared_ai_config_owner_id'] = $forgedProvider->id;
        $payload['switch_shared_provider'] = '1';

        $this->actingAs($currentActor, 'admin')
            ->from(route('admin.admin-users.edit', ['adminId' => $ordinaryAdmin->id]))
            ->post(route('admin.admin-users.update', ['adminId' => $ordinaryAdmin->id]), $payload)
            ->assertRedirect(route('admin.admin-users.edit', ['adminId' => $ordinaryAdmin->id]))
            ->assertSessionHasErrors('ai_config_mode');

        $this->assertSame($existingProvider->id, $ordinaryAdmin->refresh()->shared_ai_config_owner_id);
        $this->assertSame(4, $ordinaryAdmin->ai_config_access_version);
    }

    public function test_inactive_existing_provider_is_preserved_until_an_active_super_admin_explicitly_switches_it(): void
    {
        $inactiveProvider = $this->admin('inactive-root', 'super_admin', ['status' => 'inactive']);
        $currentActor = $this->admin('current-root', 'super_admin');
        $ordinaryAdmin = $this->admin('editor', 'admin', [
            'shared_ai_config_owner_id' => $inactiveProvider->id,
        ]);

        $this->actingAs($currentActor, 'admin')
            ->post(
                route('admin.admin-users.update', ['adminId' => $ordinaryAdmin->id]),
                $this->updatePayload($ordinaryAdmin, 'shared_current_super'),
            )
            ->assertRedirect(route('admin.admin-users.index'));

        $this->assertSame($inactiveProvider->id, $ordinaryAdmin->refresh()->shared_ai_config_owner_id);
        $this->assertSame(1, $ordinaryAdmin->ai_config_access_version);

        $payload = $this->updatePayload($ordinaryAdmin, 'shared_current_super');
        $payload['switch_shared_provider'] = '1';
        $this->post(
            route('admin.admin-users.update', ['adminId' => $ordinaryAdmin->id]),
            $payload,
        )->assertRedirect(route('admin.admin-users.index'));

        $this->assertSame($currentActor->id, $ordinaryAdmin->refresh()->shared_ai_config_owner_id);
        $this->assertSame(2, $ordinaryAdmin->ai_config_access_version);
    }

    public function test_sharing_update_rolls_back_defaults_and_access_identity_when_profile_save_fails(): void
    {
        $superAdmin = $this->admin('root', 'super_admin');
        $ordinaryAdmin = $this->admin('editor', 'admin', [
            'shared_ai_config_owner_id' => $superAdmin->id,
        ]);
        $sharedChat = $this->model($superAdmin, 'chat');
        $settings = $this->settings($ordinaryAdmin, $sharedChat, null, $superAdmin);
        Exceptions::fake();
        Admin::updating(static function (Admin $admin): void {
            if ($admin->username === 'rollback-editor') {
                throw new RuntimeException('sensitive-rollback-detail');
            }
        });
        $payload = $this->updatePayload($ordinaryAdmin, 'independent');
        $payload['username'] = 'rollback-editor';

        $this->actingAs($superAdmin, 'admin')
            ->from(route('admin.admin-users.edit', ['adminId' => $ordinaryAdmin->id]))
            ->post(route('admin.admin-users.update', ['adminId' => $ordinaryAdmin->id]), $payload)
            ->assertRedirect(route('admin.admin-users.edit', ['adminId' => $ordinaryAdmin->id]))
            ->assertSessionHasErrors();

        $ordinaryAdmin->refresh();
        $settings->refresh();
        $this->assertSame('editor', $ordinaryAdmin->username);
        $this->assertSame($superAdmin->id, $ordinaryAdmin->shared_ai_config_owner_id);
        $this->assertSame(1, $ordinaryAdmin->ai_config_access_version);
        $this->assertSame($sharedChat->id, $settings->default_chat_model_id);
        $this->assertStringNotContainsString('sensitive-rollback-detail', implode(' ', session('errors')->all()));
        Exceptions::assertReported(RuntimeException::class);
    }

    public function test_create_and_ordinary_edit_forms_render_accessible_ai_configuration_cards(): void
    {
        $superAdmin = $this->admin('root', 'super_admin');
        $ordinaryAdmin = $this->admin('editor', 'admin', [
            'shared_ai_config_owner_id' => $superAdmin->id,
            'ai_config_access_version' => 7,
        ]);
        $sharedChat = $this->model($superAdmin, 'chat');
        $this->settings($ordinaryAdmin, $sharedChat, null, $superAdmin);
        $errors = (new ViewErrorBag)->put(
            'default',
            new MessageBag(['ai_config_mode' => 'Accessible AI mode error']),
        );

        $createHtml = $this->actingAs($superAdmin, 'admin')
            ->withSession(['errors' => $errors])
            ->get(route('admin.admin-users.create'))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('name="ai_config_mode"', $createHtml);
        $this->assertMatchesRegularExpression('/value="independent"[^>]*checked/', $createHtml);
        $this->assertSame(2, substr_count($createHtml, 'name="ai_config_mode"'));
        $this->assertSame(1, substr_count($createHtml, 'id="admin-user-ai-config-mode-error"'));
        $this->assertSame(2, substr_count($createHtml, 'aria-describedby="admin-user-ai-config-mode-help admin-user-ai-config-mode-error"'));
        $this->assertStringContainsString(e($superAdmin->name), $createHtml);
        $this->assertStringContainsString(__('admin.admin_users.ai_config_shared'), $createHtml);
        $this->assertStringContainsString(__('admin.admin_users.ai_config_shared_priority'), $createHtml);

        $editHtml = $this->get(route('admin.admin-users.edit', ['adminId' => $ordinaryAdmin->id]))
            ->assertOk()
            ->getContent();

        $this->assertMatchesRegularExpression('/value="shared_current_super"[^>]*checked/', $editHtml);
        $this->assertStringContainsString('name="expected_ai_config_access_version" value="7"', $editHtml);
        $this->assertStringContainsString(
            'name="expected_shared_ai_config_owner_id" value="'.$superAdmin->id.'"',
            $editHtml,
        );
        $this->assertStringContainsString(
            __('admin.admin_users.ai_config_independent_impact', ['defaults' => 1, 'tasks' => 0]),
            $editHtml,
        );
    }

    public function test_multi_super_edit_form_shows_existing_provider_and_requires_explicit_switch_confirmation(): void
    {
        $existingProvider = $this->admin('existing-root', 'super_admin', [
            'display_name' => 'Existing Provider A',
            'status' => 'inactive',
        ]);
        $currentActor = $this->admin('current-root', 'super_admin', [
            'display_name' => 'Current Provider B',
        ]);
        $ordinaryAdmin = $this->admin('editor', 'admin', [
            'shared_ai_config_owner_id' => $existingProvider->id,
            'ai_config_access_version' => 6,
        ]);

        $html = $this->actingAs($currentActor, 'admin')
            ->get(route('admin.admin-users.edit', ['adminId' => $ordinaryAdmin->id]))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('Existing Provider A', $html);
        $this->assertStringContainsString('Current Provider B', $html);
        $this->assertStringContainsString(
            __('admin.admin_users.ai_config_shared_existing', ['provider' => $existingProvider->name]),
            $html,
        );
        $this->assertStringContainsString(
            __('admin.admin_users.ai_config_provider_status', [
                'status' => __('admin.admin_users.status_inactive'),
            ]),
            $html,
        );
        $this->assertStringContainsString(
            'name="expected_shared_ai_config_owner_id" value="'.$existingProvider->id.'"',
            $html,
        );
        $this->assertStringContainsString('name="switch_shared_provider"', $html);
        $this->assertStringContainsString('aria-describedby="admin-user-provider-switch-help"', $html);
        $this->assertStringContainsString(
            __('admin.admin_users.ai_config_switch_provider', ['provider' => $currentActor->name]),
            $html,
        );
        $this->assertDoesNotMatchRegularExpression('/name="switch_shared_provider"[^>]*checked/', $html);

        $existingProvider->forceFill(['status' => 'active'])->save();
        $sameProviderHtml = $this->actingAs($existingProvider, 'admin')
            ->get(route('admin.admin-users.edit', ['adminId' => $ordinaryAdmin->id]))
            ->assertOk()
            ->getContent();
        $this->assertStringNotContainsString('name="switch_shared_provider"', $sameProviderHtml);
    }

    public function test_long_provider_names_have_scoped_overflow_protection(): void
    {
        $existingProviderName = str_repeat('a', 50);
        $currentActorName = str_repeat('B', 100);
        $existingProvider = $this->admin($existingProviderName, 'super_admin', [
            'display_name' => '',
        ]);
        $currentActor = $this->admin('current-root', 'super_admin', [
            'display_name' => $currentActorName,
        ]);
        $ordinaryAdmin = $this->admin('editor', 'admin', [
            'shared_ai_config_owner_id' => $existingProvider->id,
        ]);

        $html = $this->actingAs($currentActor, 'admin')
            ->get(route('admin.admin-users.edit', ['adminId' => $ordinaryAdmin->id]))
            ->assertOk()
            ->getContent();

        $this->assertMatchesRegularExpression(
            '/class="[^"]*min-w-0[^"]*\\[overflow-wrap:anywhere\\][^"]*"[^>]*>\s*'.preg_quote($existingProviderName, '/').'\s*<\/span>/u',
            $html,
        );
        $this->assertMatchesRegularExpression(
            '/class="[^"]*min-w-0[^"]*\\[overflow-wrap:anywhere\\][^"]*"[^>]*>\s*'.preg_quote(__('admin.admin_users.ai_config_provider_status', [
                'status' => __('admin.admin_users.status_active'),
            ]), '/').'\s*<\/span>/u',
            $html,
        );
        $this->assertMatchesRegularExpression(
            '/class="[^"]*min-w-0[^"]*\\[overflow-wrap:anywhere\\][^"]*"[^>]*>\s*'.preg_quote(__('admin.admin_users.ai_config_switch_provider', [
                'provider' => $currentActorName,
            ]), '/').'\s*<\/span>/u',
            $html,
        );
        $this->assertMatchesRegularExpression(
            '/id="admin-user-provider-switch-help" class="[^"]*min-w-0[^"]*\\[overflow-wrap:anywhere\\][^"]*"/u',
            $html,
        );
    }

    public function test_independent_impact_is_only_rendered_for_a_saved_shared_provider(): void
    {
        $superAdmin = $this->admin('root', 'super_admin');
        $independentAdmin = $this->admin('independent-editor', 'admin');
        $sharedAdmin = $this->admin('shared-editor', 'admin', [
            'shared_ai_config_owner_id' => $superAdmin->id,
        ]);
        $impactText = __('admin.admin_users.ai_config_independent_impact', [
            'defaults' => 0,
            'tasks' => 0,
        ]);

        $this->actingAs($superAdmin, 'admin')
            ->get(route('admin.admin-users.create'))
            ->assertOk()
            ->assertDontSee($impactText);

        $this->withSession(['_old_input' => ['ai_config_mode' => 'shared_current_super']])
            ->get(route('admin.admin-users.edit', ['adminId' => $independentAdmin->id]))
            ->assertOk()
            ->assertDontSee($impactText);

        $this->get(route('admin.admin-users.edit', ['adminId' => $sharedAdmin->id]))
            ->assertOk()
            ->assertSee($impactText);
    }

    public function test_super_admin_self_edit_hides_and_rejects_ordinary_admin_ai_configuration_fields(): void
    {
        $superAdmin = $this->admin('root', 'super_admin');

        $this->actingAs($superAdmin, 'admin')
            ->get(route('admin.admin-users.edit', ['adminId' => $superAdmin->id]))
            ->assertOk()
            ->assertDontSee('name="ai_config_mode"', false)
            ->assertDontSee('name="expected_ai_config_access_version"', false)
            ->assertDontSee('name="expected_shared_ai_config_owner_id"', false)
            ->assertDontSee('name="switch_shared_provider"', false);

        $this->from(route('admin.admin-users.edit', ['adminId' => $superAdmin->id]))
            ->post(route('admin.admin-users.update', ['adminId' => $superAdmin->id]), [
                'username' => $superAdmin->username,
                'display_name' => $superAdmin->display_name,
                'email' => $superAdmin->email,
                'status' => 'active',
                'password' => '',
                'confirm_password' => '',
                'ai_config_mode' => 'shared_current_super',
                'expected_ai_config_access_version' => 1,
                'expected_shared_ai_config_owner_id' => $superAdmin->id,
                'switch_shared_provider' => '1',
            ])
            ->assertRedirect(route('admin.admin-users.edit', ['adminId' => $superAdmin->id]))
            ->assertSessionHasErrors([
                'ai_config_mode',
                'expected_ai_config_access_version',
                'expected_shared_ai_config_owner_id',
                'switch_shared_provider',
            ]);

        $this->assertNull($superAdmin->refresh()->shared_ai_config_owner_id);
    }

    public function test_admin_list_shows_ai_mode_and_shared_provider_without_exposing_credentials(): void
    {
        $superAdmin = $this->admin('root', 'super_admin');
        $ordinaryAdmin = $this->admin('editor', 'admin', [
            'shared_ai_config_owner_id' => $superAdmin->id,
        ]);
        $model = $this->model($superAdmin, 'chat');

        $response = $this->actingAs($superAdmin, 'admin')
            ->get(route('admin.admin-users.index'))
            ->assertOk()
            ->assertSee(__('admin.admin_users.column_ai_config'))
            ->assertSee(__('admin.admin_users.ai_config_super_self'))
            ->assertSee(__('admin.admin_users.ai_config_shared'))
            ->assertSee($superAdmin->name)
            ->assertDontSee($model->api_key, false)
            ->assertDontSee($model->api_url, false);

        $this->assertStringContainsString($ordinaryAdmin->username, $response->getContent());
    }

    public function test_ai_configuration_translation_keys_exist_in_every_supported_admin_locale(): void
    {
        $keys = [
            'column_ai_config',
            'ai_config_heading',
            'ai_config_independent',
            'ai_config_shared',
            'ai_config_shared_existing',
            'ai_config_shared_priority',
            'ai_config_independent_impact',
            'ai_config_super_self',
            'ai_config_provider_status',
            'ai_config_current_provider',
            'ai_config_switch_provider',
            'ai_config_switch_provider_help',
            'error.ai_config_mode_invalid',
            'error.ai_config_access_conflict',
            'error.delete_has_ai_dependencies',
        ];

        foreach (['zh_CN', 'en', 'ja', 'es', 'ru', 'pt_BR'] as $locale) {
            foreach ($keys as $key) {
                $translationKey = 'admin.admin_users.'.$key;
                $this->assertTrue(Lang::hasForLocale($translationKey, $locale), $locale.': '.$translationKey);
                $this->assertNotSame($translationKey, Lang::get($translationKey, locale: $locale));
            }
        }
    }

    public function test_sharing_service_returns_a_sanitized_structured_change_result(): void
    {
        $superAdmin = $this->admin('root', 'super_admin');
        $ordinaryAdmin = $this->admin('editor', 'admin', [
            'shared_ai_config_owner_id' => $superAdmin->id,
        ]);
        $sharedChat = $this->model($superAdmin, 'chat');
        $this->settings($ordinaryAdmin, $sharedChat, null, $superAdmin);
        $library = TitleLibrary::query()->create(['name' => 'Structured impact runs']);
        $this->titleGenerationRun($library, $ordinaryAdmin, TitleGenerationRun::STATUS_QUEUED);

        $result = app(AdminAiSharingService::class)->updateAdmin(
            $superAdmin,
            $ordinaryAdmin,
            $this->updatePayload($ordinaryAdmin, 'independent'),
            'independent',
            1,
            $superAdmin->id,
        );
        $serialized = $result->toArray();

        $this->assertSame($superAdmin->id, $serialized['old_provider_admin_id']);
        $this->assertNull($serialized['new_provider_admin_id']);
        $this->assertSame(1, $serialized['old_access_version']);
        $this->assertSame(2, $serialized['new_access_version']);
        $this->assertSame([$sharedChat->id], $serialized['cleared_default_model_ids']);
        $this->assertSame(
            ['chat' => $sharedChat->id, 'embedding' => null],
            $serialized['cleared_default_ids'],
        );
        $this->assertSame(1, $serialized['cleared_default_count']);
        $this->assertSame([
            'title_generation_runs' => 1,
            'article_ai_optimization_runs' => 0,
            'knowledge_fact_generation_runs' => 0,
            'ai_workspace_runs' => 0,
            'url_import_jobs' => 0,
            'enterprise_knowledge_projects' => 0,
            'total' => 1,
        ], $serialized['pending_impact_counts']);
        $this->assertArrayNotHasKey('api_key', $serialized);
        $this->assertArrayNotHasKey('api_url', $serialized);
        $this->assertStringNotContainsString('sensitive-key', json_encode($serialized, JSON_THROW_ON_ERROR));
    }

    public function test_update_rejects_forged_provider_identity_and_ordinary_admins_cannot_use_management_endpoints(): void
    {
        $superAdmin = $this->admin('root', 'super_admin');
        $otherSuperAdmin = $this->admin('other-root', 'super_admin');
        $ordinaryAdmin = $this->admin('editor', 'admin');
        $payload = $this->updatePayload($ordinaryAdmin, 'shared_current_super');
        $payload['shared_ai_config_owner_id'] = $otherSuperAdmin->id;

        $this->actingAs($superAdmin, 'admin')
            ->from(route('admin.admin-users.edit', ['adminId' => $ordinaryAdmin->id]))
            ->post(route('admin.admin-users.update', ['adminId' => $ordinaryAdmin->id]), $payload)
            ->assertRedirect(route('admin.admin-users.edit', ['adminId' => $ordinaryAdmin->id]))
            ->assertSessionHasErrors('shared_ai_config_owner_id');

        $this->assertNull($ordinaryAdmin->refresh()->shared_ai_config_owner_id);
        $this->assertSame(1, $ordinaryAdmin->ai_config_access_version);

        $this->actingAs($ordinaryAdmin, 'admin')
            ->post(route('admin.admin-users.store'), $this->createPayload('blocked-admin'))
            ->assertForbidden();
        $this->post(
            route('admin.admin-users.update', ['adminId' => $ordinaryAdmin->id]),
            $this->updatePayload($ordinaryAdmin, 'independent'),
        )->assertForbidden();
        $this->post(
            route('admin.admin-users.toggle-status', ['adminId' => $ordinaryAdmin->id]),
            ['next_status' => 'inactive'],
        )->assertForbidden();
        $this->post(route('admin.admin-users.delete', ['adminId' => $ordinaryAdmin->id]))
            ->assertForbidden();
    }

    public function test_array_shaped_old_ai_configuration_input_falls_back_to_the_persisted_mode_safely(): void
    {
        $superAdmin = $this->admin('root', 'super_admin');
        $ordinaryAdmin = $this->admin('editor', 'admin', [
            'shared_ai_config_owner_id' => $superAdmin->id,
        ]);

        $html = $this->actingAs($superAdmin, 'admin')
            ->withSession(['_old_input' => [
                'ai_config_mode' => ['unexpected'],
                'expected_ai_config_access_version' => ['unexpected'],
            ]])
            ->get(route('admin.admin-users.edit', ['adminId' => $ordinaryAdmin->id]))
            ->assertOk()
            ->getContent();

        $this->assertMatchesRegularExpression('/value="shared_current_super"[^>]*checked/', $html);
        $this->assertStringNotContainsString('value="Array"', $html);
        $this->assertStringContainsString('name="expected_ai_config_access_version" value="1"', $html);
    }

    public function test_update_validation_never_flashes_password_fields(): void
    {
        $superAdmin = $this->admin('root', 'super_admin');
        $ordinaryAdmin = $this->admin('editor', 'admin');

        $this->actingAs($superAdmin, 'admin')
            ->from(route('admin.admin-users.edit', ['adminId' => $ordinaryAdmin->id]))
            ->post(route('admin.admin-users.update', ['adminId' => $ordinaryAdmin->id]), [
                ...$this->updatePayload($ordinaryAdmin, 'independent'),
                'username' => '',
                'password' => 'password-must-stay-hidden',
                'confirm_password' => 'password-must-stay-hidden',
            ])
            ->assertRedirect(route('admin.admin-users.edit', ['adminId' => $ordinaryAdmin->id]))
            ->assertSessionHasErrors('username');

        $oldInput = session()->getOldInput();
        $this->assertArrayNotHasKey('password', $oldInput);
        $this->assertArrayNotHasKey('confirm_password', $oldInput);
        $this->assertSame('independent', $oldInput['ai_config_mode']);
    }

    /** @return array<string, mixed> */
    private function updatePayload(Admin $admin, string $mode): array
    {
        return [
            'username' => $admin->username,
            'display_name' => $admin->display_name,
            'email' => $admin->email,
            'status' => $admin->status,
            'password' => '',
            'confirm_password' => '',
            'ai_config_mode' => $mode,
            'expected_ai_config_access_version' => $admin->ai_config_access_version,
            'expected_shared_ai_config_owner_id' => $admin->shared_ai_config_owner_id,
        ];
    }

    /** @return array<string, string> */
    private function createPayload(string $username): array
    {
        return [
            'username' => $username,
            'display_name' => ucfirst($username),
            'email' => $username.'@example.test',
            'password' => 'safe-password-123',
            'confirm_password' => 'safe-password-123',
        ];
    }

    /** @param array<string, mixed> $attributes */
    private function admin(string $username, string $role, array $attributes = []): Admin
    {
        $admin = Admin::query()->create([
            'username' => $username,
            'password' => 'secret-123',
            'email' => $username.'@example.test',
            'display_name' => ucfirst($username),
            'role' => $role,
            'status' => 'active',
        ]);
        $admin->forceFill($attributes)->save();

        return $admin->refresh();
    }

    private function model(Admin $owner, string $type): AiModel
    {
        $model = new AiModel([
            'name' => $owner->username.' '.$type,
            'version' => 'test',
            'api_key' => 'sensitive-key',
            'model_id' => $owner->username.'-'.$type,
            'model_type' => $type,
            'api_url' => 'https://ai.example.test',
            'status' => 'active',
        ]);
        $model->forceFill([
            'owner_admin_id' => $owner->id,
            'access_scope' => AiModel::ACCESS_SCOPE_USER_CONTENT,
        ])->save();

        return $model;
    }

    private function settings(
        Admin $admin,
        ?AiModel $chat,
        ?AiModel $embedding,
        Admin $updatedBy,
    ): AdminAiSetting {
        $settings = new AdminAiSetting;
        $settings->forceFill([
            'admin_id' => $admin->id,
            'default_chat_model_id' => $chat?->id,
            'default_embedding_model_id' => $embedding?->id,
            'updated_by_admin_id' => $updatedBy->id,
        ])->save();

        return $settings;
    }

    /** @param array<string, mixed> $attributes */
    private function titleGenerationRun(
        TitleLibrary $library,
        Admin $admin,
        string $status,
        array $attributes = [],
    ): TitleGenerationRun {
        return TitleGenerationRun::query()->create([
            'title_library_id' => $library->id,
            'created_by_admin_id' => $admin->id,
            'status' => $status,
            'requested_count' => 10,
            'batch_size' => 10,
            'model_request_budget' => 30,
            'title_style' => 'professional',
            'locale' => 'zh_CN',
            'keyword_snapshot' => ['GEO'],
            ...$attributes,
        ]);
    }

    private function articleOptimizationRun(
        Article $article,
        Admin $admin,
        string $status,
    ): ArticleAiOptimizationRun {
        return ArticleAiOptimizationRun::query()->create([
            'article_id' => $article->id,
            'requested_by_admin_id' => $admin->id,
            'request_key' => (string) Str::uuid(),
            'trigger' => ArticleAiOptimizationRun::TRIGGER_ADMIN_MANUAL,
            'status' => $status,
            'base_article_hash' => hash('sha256', (string) Str::uuid()),
            'policy_hash' => hash('sha256', (string) Str::uuid()),
        ]);
    }

    private function knowledgeFactGenerationRun(
        KnowledgeFactLibrary $library,
        Admin $admin,
        string $status,
    ): KnowledgeFactGenerationRun {
        return KnowledgeFactGenerationRun::query()->create([
            'library_id' => $library->id,
            'mode' => 'supplement',
            'target_count' => 10,
            'source_hash' => hash('sha256', (string) Str::uuid()),
            'base_working_version' => 1,
            'status' => $status,
            'created_by_admin_id' => $admin->id,
            'request_key' => (string) Str::uuid(),
        ]);
    }

    private function aiWorkspaceRun(Admin $admin, string $state): AiWorkspaceRun
    {
        $conversation = AiConversation::query()->create([
            'id' => (string) Str::uuid(),
            'participant_type' => Admin::class,
            'participant_id' => $admin->id,
            'title' => 'Pending workspace run',
        ]);

        return AiWorkspaceRun::query()->create([
            'id' => (string) Str::uuid(),
            'conversation_id' => $conversation->id,
            'admin_id' => $admin->id,
            'admin_username_snapshot' => $admin->username,
            'admin_auth_version' => $admin->auth_version,
            'state' => $state,
            'prompt' => 'Generate content',
        ]);
    }
}
