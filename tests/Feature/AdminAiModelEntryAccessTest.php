<?php

namespace Tests\Feature;

use App\Models\Admin;
use App\Models\AiModel;
use App\Models\Article;
use App\Models\Author;
use App\Models\Category;
use App\Models\KeywordLibrary;
use App\Models\KnowledgeBase;
use App\Models\Prompt;
use App\Models\Task;
use App\Models\TitleLibrary;
use App\Services\GeoFlow\TaskLifecycleService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\TestCase;

class AdminAiModelEntryAccessTest extends TestCase
{
    use RefreshDatabase;

    public function test_api_catalog_is_scoped_and_sanitized_with_personal_models_before_shared_models(): void
    {
        $provider = $this->admin('provider', 'super_admin');
        $actor = $this->admin('actor', 'admin', $provider);
        $peer = $this->admin('peer', 'admin');
        $personalChat = $this->model($actor, 'Personal Chat', 'chat', priority: 90);
        $personalEmbedding = $this->model($actor, 'Personal Embedding', 'embedding', priority: 80);
        $sharedChat = $this->model($provider, 'Shared Chat', 'chat', priority: 1);
        $peerChat = $this->model($peer, 'Peer Chat', 'chat');
        $systemModel = $this->model($provider, 'System Chat', 'chat', AiModel::ACCESS_SCOPE_SYSTEM_ONLY);

        $response = $this->actingWithToken($actor, ['catalog:read'])->getJson('/api/v1/catalog')->assertOk();

        $modelIds = collect($response->json('data.models'))->pluck('id')->all();
        $this->assertSame([$personalEmbedding->id, $personalChat->id, $sharedChat->id], $modelIds);
        $this->assertNotContains($peerChat->id, $modelIds);
        $this->assertNotContains($systemModel->id, $modelIds);
        foreach ($response->json('data.models') as $modelData) {
            $this->assertSame(
                ['id', 'name', 'version', 'model_type', 'status', 'failover_priority', 'is_available', 'is_shared'],
                array_keys($modelData),
            );
        }
        $this->assertStringNotContainsString('catalog-secret', $response->getContent());
        $this->assertStringNotContainsString('provider.example', $response->getContent());

        $superResponse = $this->actingWithToken($provider, ['catalog:read'])->getJson('/api/v1/catalog')->assertOk();
        $this->assertSame(
            [$sharedChat->id],
            collect($superResponse->json('data.models'))->pluck('id')->all(),
        );
    }

    public function test_closing_sharing_removes_shared_models_from_the_catalog_immediately(): void
    {
        $provider = $this->admin('provider', 'super_admin');
        $actor = $this->admin('actor', 'admin', $provider);
        $shared = $this->model($provider, 'Shared Chat', 'chat');
        $token = $actor->createToken('catalog', ['catalog:read'])->plainTextToken;

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/api/v1/catalog')
            ->assertOk()
            ->assertJsonPath('data.models.0.id', $shared->id);

        $actor->forceFill([
            'shared_ai_config_owner_id' => null,
            'ai_config_access_version' => (int) $actor->ai_config_access_version + 1,
        ])->save();

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/api/v1/catalog')
            ->assertOk()
            ->assertJsonCount(0, 'data.models');
    }

    public function test_task_api_rejects_peer_and_system_models_with_a_stable_error(): void
    {
        $actor = $this->admin('actor', 'admin');
        $peer = $this->admin('peer', 'admin');
        $peerModel = $this->model($peer, 'Peer Chat', 'chat');
        $systemModel = $this->model($actor, 'System Chat', 'chat', AiModel::ACCESS_SCOPE_SYSTEM_ONLY);

        foreach ([$peerModel, $systemModel] as $model) {
            $this->actingWithToken($actor, ['tasks:write'])
                ->postJson('/api/v1/tasks', $this->taskPayload((int) $model->id))
                ->assertNotFound()
                ->assertJsonPath('error.code', 'ai_model_not_accessible');
        }

        $this->assertDatabaseCount('tasks', 0);
    }

    public function test_task_update_rejects_a_forged_peer_model_without_changing_the_task(): void
    {
        $actor = $this->admin('actor', 'admin');
        $peer = $this->admin('peer', 'admin');
        $personal = $this->model($actor, 'Personal Chat', 'chat');
        $peerModel = $this->model($peer, 'Peer Chat', 'chat');
        $token = $actor->createToken('tasks', ['tasks:write', 'tasks:read'])->plainTextToken;
        $created = $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/v1/tasks', $this->taskPayload((int) $personal->id))
            ->assertCreated();
        $taskId = (int) $created->json('data.id');

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->patchJson('/api/v1/tasks/'.$taskId, [
                'ai_model_id' => $peerModel->id,
                'config_version' => 1,
            ])
            ->assertNotFound()
            ->assertJsonPath('error.code', 'ai_model_not_accessible');

        $this->assertDatabaseHas('tasks', ['id' => $taskId, 'ai_model_id' => $personal->id]);
    }

    public function test_task_update_preserves_an_unchanged_formerly_shared_model_after_sharing_is_closed(): void
    {
        $provider = $this->admin('provider', 'super_admin');
        $actor = $this->admin('actor', 'admin', $provider);
        $shared = $this->model($provider, 'Shared Chat', 'chat');
        $token = $actor->createToken('tasks', ['tasks:write', 'tasks:read'])->plainTextToken;
        $created = $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/v1/tasks', $this->taskPayload((int) $shared->id))
            ->assertCreated();
        $taskId = (int) $created->json('data.id');
        $actor->forceFill([
            'shared_ai_config_owner_id' => null,
            'ai_config_access_version' => (int) $actor->ai_config_access_version + 1,
        ])->save();

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->patchJson('/api/v1/tasks/'.$taskId, [
                'ai_model_id' => $shared->id,
                'name' => 'Updated without changing model',
                'config_version' => 1,
            ])
            ->assertOk();

        $this->assertDatabaseHas('tasks', [
            'id' => $taskId,
            'name' => 'Updated without changing model',
            'ai_model_id' => $shared->id,
        ]);
    }

    public function test_task_update_validates_a_new_model_against_the_persisted_execution_admin(): void
    {
        $executor = $this->admin('executor', 'admin');
        $editor = $this->admin('editor', 'super_admin');
        $executorModel = $this->model($executor, 'Executor Chat', 'chat');
        $editorModel = $this->model($editor, 'Editor Chat', 'chat');
        $created = $this->actingWithToken($executor, ['tasks:write'])
            ->postJson('/api/v1/tasks', $this->taskPayload((int) $executorModel->id))
            ->assertCreated();
        $taskId = (int) $created->json('data.id');

        $this->actingWithToken($editor, ['tasks:write'])
            ->patchJson('/api/v1/tasks/'.$taskId, [
                'ai_model_id' => $editorModel->id,
                'config_version' => 1,
            ])
            ->assertNotFound()
            ->assertJsonPath('error.code', 'ai_model_not_accessible');

        $this->assertDatabaseHas('tasks', [
            'id' => $taskId,
            'model_access_admin_id' => $executor->id,
            'ai_model_id' => $executorModel->id,
        ]);
    }

    public function test_super_admin_can_edit_unrelated_fields_without_gaining_access_to_the_task_model(): void
    {
        $executor = $this->admin('executor', 'admin');
        $editor = $this->admin('editor', 'super_admin');
        $executorModel = $this->model($executor, 'Executor Private Chat', 'chat');
        $editorModel = $this->model($editor, 'Editor Chat', 'chat');
        $created = $this->actingWithToken($executor, ['tasks:write'])
            ->postJson('/api/v1/tasks', $this->taskPayload((int) $executorModel->id))
            ->assertCreated();
        $taskId = (int) $created->json('data.id');

        $this->actingWithToken($editor, ['tasks:write'])
            ->patchJson('/api/v1/tasks/'.$taskId, [
                'name' => 'Governed task rename',
                'ai_model_id' => $executorModel->id,
                'config_version' => 1,
            ])
            ->assertOk();

        $this->actingWithToken($editor, ['tasks:write'])
            ->patchJson('/api/v1/tasks/'.$taskId, [
                'ai_model_id' => $editorModel->id,
                'config_version' => 1,
            ])
            ->assertNotFound()
            ->assertJsonPath('error.code', 'ai_model_not_accessible');

        $this->assertDatabaseHas('tasks', [
            'id' => $taskId,
            'name' => 'Governed task rename',
            'ai_model_id' => $executorModel->id,
            'model_access_admin_id' => $executor->id,
        ]);
    }

    public function test_super_admin_task_edit_shows_the_inaccessible_current_model_as_a_sanitized_disabled_option(): void
    {
        $executor = $this->admin('form-executor', 'admin');
        $editor = $this->admin('form-editor', 'super_admin');
        $executorModel = $this->model($executor, 'Executor Private Form Model', 'chat');
        $this->model($editor, 'Editor Form Model', 'chat');
        Category::query()->create(['name' => 'Task form category', 'slug' => 'task-form-category']);
        $created = $this->actingWithToken($executor, ['tasks:write'])
            ->postJson('/api/v1/tasks', $this->taskPayload((int) $executorModel->id))
            ->assertCreated();
        $taskId = (int) $created->json('data.id');

        $response = $this->actingAs($editor, 'admin')
            ->get(route('admin.tasks.edit', ['taskId' => $taskId]))
            ->assertOk();

        $response->assertViewHas('formOptions', function (array $options) use ($executorModel): bool {
            $current = collect($options['aiModels'])->firstWhere('id', $executorModel->id);

            return is_array($current)
                && ($current['disabled'] ?? false) === true
                && ($current['current_inaccessible'] ?? false) === true
                && array_keys($current) === ['id', 'name', 'disabled', 'current_inaccessible'];
        });
        $response
            ->assertSee('Executor Private Form Model')
            ->assertSee('type="hidden" name="ai_model_id" value="'.$executorModel->id.'"', false)
            ->assertDontSee('catalog-secret')
            ->assertDontSee('provider.example');

        $task = Task::query()->findOrFail($taskId);
        $taskForm = $response->viewData('taskForm');
        $this->actingAs($editor, 'admin')
            ->put(route('admin.tasks.update', ['taskId' => $taskId]), [
                'task_name' => 'Web governed rename',
                'title_library_id' => $task->title_library_id,
                'prompt_id' => $task->prompt_id,
                'ai_model_id' => $executorModel->id,
                'status' => 'paused',
                'article_limit' => 1,
                'draft_limit' => 1,
                'publish_interval' => 60,
                'category_mode' => 'smart',
                'model_selection_mode' => 'fixed',
                'task_revision' => $taskForm['task_revision'],
                'config_version' => 1,
            ])
            ->assertRedirect(route('admin.tasks.index'));

        $this->assertDatabaseHas('tasks', [
            'id' => $taskId,
            'name' => 'Web governed rename',
            'ai_model_id' => $executorModel->id,
        ]);
    }

    public function test_admin_task_form_only_lists_models_the_current_admin_can_use(): void
    {
        $provider = $this->admin('provider', 'super_admin');
        $actor = $this->admin('actor', 'admin', $provider);
        $peer = $this->admin('peer', 'admin');
        $personal = $this->model($actor, 'Personal Chat', 'chat', priority: 90);
        $shared = $this->model($provider, 'Shared Chat', 'chat', priority: 1);
        $this->model($peer, 'Peer Chat', 'chat');
        $this->model($actor, 'System Chat', 'chat', AiModel::ACCESS_SCOPE_SYSTEM_ONLY);

        $this->actingAs($actor, 'admin')
            ->get(route('admin.tasks.create'))
            ->assertOk()
            ->assertViewHas('formOptions', function (array $options) use ($personal, $shared): bool {
                return collect($options['aiModels'])->pluck('id')->all() === [$personal->id, $shared->id];
            });
    }

    public function test_ai_workspace_draft_chooses_the_personal_model_before_the_shared_model(): void
    {
        $provider = $this->admin('provider', 'super_admin');
        $actor = $this->admin('actor', 'admin', $provider);
        $personal = $this->model($actor, 'Personal Chat', 'chat', priority: 90);
        $this->model($provider, 'Shared Chat', 'chat', priority: 1);
        $this->taskDependencies();

        $draft = app(TaskLifecycleService::class)->createDraftTask(['name' => 'Scoped draft'], $actor);

        $this->assertSame($personal->id, $draft['ai_model_id']);
        $this->assertSame(
            $actor->id,
            Task::query()->findOrFail((int) $draft['id'])->model_access_admin_id,
        );
    }

    public function test_article_title_and_fact_model_dropdowns_are_scoped_to_the_current_admin(): void
    {
        $actor = $this->admin('actor', 'admin');
        $peer = $this->admin('peer', 'admin');
        $personal = $this->model($actor, 'Legacy Personal', null);
        $this->model($peer, 'Peer Chat', 'chat');
        $this->model($actor, 'System Chat', 'chat', AiModel::ACCESS_SCOPE_SYSTEM_ONLY);
        $titleLibrary = TitleLibrary::query()->create(['name' => 'Scoped title library']);
        $knowledgeBase = KnowledgeBase::query()->create(['name' => 'Scoped knowledge base', 'content' => 'Evidence']);

        $this->actingAs($actor, 'admin')
            ->get(route('admin.title-libraries.ai-generate', ['libraryId' => $titleLibrary->id]))
            ->assertOk()
            ->assertDontSee('legacy-personal')
            ->assertViewHas('aiModels', fn ($models): bool => $models->pluck('id')->all() === [$personal->id]);
        $this->actingAs($actor, 'admin')
            ->get(route('admin.knowledge-bases.facts.index', ['knowledgeBaseId' => $knowledgeBase->id]))
            ->assertOk()
            ->assertDontSee('legacy-personal')
            ->assertViewHas('factGenerationModels', fn ($models): bool => $models->pluck('id')->all() === [$personal->id]);
        $this->actingAs($actor, 'admin')
            ->get(route('admin.articles.create'))
            ->assertOk()
            ->assertDontSee('legacy-personal')
            ->assertViewHas('formOptions', fn (array $options): bool => collect($options['ai_models'])->pluck('id')->all() === [$personal->id]);
    }

    public function test_secondary_ai_entry_points_reject_a_peer_model_before_dispatch_or_outbound_work(): void
    {
        $actor = $this->admin('actor', 'admin');
        $peer = $this->admin('peer', 'admin');
        $peerModel = $this->model($peer, 'Peer Chat', 'chat');
        $titleLibrary = TitleLibrary::query()->create(['name' => 'Scoped title library']);
        $keywordLibrary = KeywordLibrary::query()->create(['name' => 'Scoped keyword library']);
        $knowledgeBase = KnowledgeBase::query()->create(['name' => 'Scoped knowledge base', 'content' => 'Evidence']);
        $prompt = Prompt::query()->create([
            'name' => 'Scoped article prompt',
            'type' => 'content',
            'content' => 'Write an article.',
        ]);

        $this->actingAs($actor, 'admin')
            ->post(route('admin.title-libraries.ai-generate.submit', ['libraryId' => $titleLibrary->id]), [
                'keyword_library_id' => $keywordLibrary->id,
                'ai_model_id' => $peerModel->id,
                'title_count' => 1,
                'title_style' => 'professional',
            ])
            ->assertSessionHasErrors('ai_model_id');
        $this->actingAs($actor, 'admin')
            ->postJson(route('admin.knowledge-bases.fact-generation.store', ['knowledgeBaseId' => $knowledgeBase->id]), [
                'mode' => 'initial',
                'target_count' => 1,
                'ai_model_id' => $peerModel->id,
                'request_key' => (string) Str::uuid(),
            ])
            ->assertNotFound()
            ->assertJsonPath('error.code', 'ai_model_not_accessible');
        $this->actingAs($actor, 'admin')
            ->postJson(route('admin.articles.editor.generate'), [
                'title' => 'Scoped article',
                'knowledge_base_id' => $knowledgeBase->id,
                'prompt_id' => $prompt->id,
                'ai_model_id' => $peerModel->id,
            ])
            ->assertNotFound()
            ->assertJsonPath('error_code', 'ai_model_not_accessible');
    }

    public function test_api_article_optimization_rejects_a_peer_model_before_starting_a_run(): void
    {
        $actor = $this->admin('actor', 'admin');
        $peer = $this->admin('peer', 'admin');
        $peerModel = $this->model($peer, 'Peer Optimization', 'chat');
        $category = Category::query()->create(['name' => 'Scoped category', 'slug' => 'scoped-category']);
        $author = Author::query()->create(['name' => 'Scoped author']);
        $article = Article::query()->create([
            'title' => 'Scoped optimization article',
            'slug' => 'scoped-optimization-article',
            'content' => 'Scoped content.',
            'status' => 'draft',
            'review_status' => 'approved',
            'category_id' => $category->id,
            'author_id' => $author->id,
        ]);

        $this->actingWithToken($actor, ['articles:publish'])
            ->withHeader('X-Idempotency-Key', 'scoped-optimization-'.$article->id)
            ->postJson("/api/v1/articles/{$article->id}/ai-quality/optimization", [
                'strategy' => 'excellent_80',
                'optimization_model_id' => $peerModel->id,
            ])
            ->assertNotFound()
            ->assertJsonPath('error.code', 'ai_model_not_accessible');

        $this->assertDatabaseCount('article_ai_optimization_runs', 0);
    }

    public function test_attached_task_optimization_rejects_forged_peer_and_system_models_on_web_and_api(): void
    {
        config()->set('geoflow.ai_quality_optimization_enabled', true);
        Queue::fake();
        $executor = $this->admin('optimization-executor', 'admin');
        $operator = $this->admin('optimization-operator', 'super_admin');
        $peer = $this->admin('optimization-peer', 'admin');
        $taskModel = $this->model($executor, 'Task Private Model', 'chat');
        $peerModel = $this->model($peer, 'Forged Peer Model', 'chat');
        $systemModel = $this->model(
            $operator,
            'Forged System Model',
            'chat',
            AiModel::ACCESS_SCOPE_SYSTEM_ONLY,
        );
        $created = $this->actingWithToken($executor, ['tasks:write'])
            ->postJson('/api/v1/tasks', $this->taskPayload((int) $taskModel->id))
            ->assertCreated();
        $category = Category::query()->create(['name' => 'Optimization category', 'slug' => 'optimization-category']);
        $author = Author::query()->create(['name' => 'Optimization author']);
        $article = Article::query()->create([
            'title' => 'Attached optimization article',
            'slug' => 'attached-optimization-article',
            'content' => 'Draft content.',
            'status' => 'draft',
            'review_status' => 'approved',
            'category_id' => $category->id,
            'author_id' => $author->id,
            'task_id' => (int) $created->json('data.id'),
        ]);

        foreach ([$peerModel, $systemModel] as $forgedModel) {
            $this->actingAs($operator, 'admin')
                ->postJson(route('admin.articles.ai-quality.optimization.store', ['articleId' => $article->id]), [
                    'request_key' => (string) Str::uuid(),
                    'strategy' => 'excellent_80',
                    'optimization_model_id' => $forgedModel->id,
                ])
                ->assertNotFound()
                ->assertJsonPath('error.code', 'ai_model_not_accessible');

            $this->actingWithToken($operator, ['articles:publish'])
                ->withHeader('X-Idempotency-Key', 'forged-optimization-'.$forgedModel->id)
                ->postJson("/api/v1/articles/{$article->id}/ai-quality/optimization", [
                    'strategy' => 'excellent_80',
                    'optimization_model_id' => $forgedModel->id,
                ])
                ->assertNotFound()
                ->assertJsonPath('error.code', 'ai_model_not_accessible');
        }

        $this->assertDatabaseCount('article_ai_optimization_runs', 0);
    }

    private function admin(string $username, string $role, ?Admin $provider = null): Admin
    {
        $admin = Admin::query()->create([
            'username' => $username,
            'password' => 'password',
            'email' => $username.'@example.test',
            'display_name' => ucfirst($username),
            'role' => $role,
            'status' => 'active',
        ]);
        $admin->forceFill([
            'shared_ai_config_owner_id' => $provider?->id,
            'ai_config_access_version' => 1,
        ])->save();

        return $admin;
    }

    private function model(
        Admin $owner,
        string $name,
        ?string $type,
        string $scope = AiModel::ACCESS_SCOPE_USER_CONTENT,
        int $priority = 100,
    ): AiModel {
        $model = new AiModel;
        $model->forceFill([
            'owner_admin_id' => $owner->id,
            'name' => $name,
            'version' => 'v1',
            'api_key' => 'catalog-secret',
            'model_id' => str($name)->slug()->toString(),
            'model_type' => $type,
            'api_url' => 'https://provider.example/v1?token=catalog-secret',
            'failover_priority' => $priority,
            'access_scope' => $scope,
            'status' => 'active',
        ])->save();

        return $model;
    }

    /** @return array<string, int|string> */
    private function taskPayload(int $modelId): array
    {
        [$prompt, $titleLibrary] = $this->taskDependencies();

        return [
            'name' => 'Scoped API task '.$modelId,
            'title_library_id' => $titleLibrary->id,
            'prompt_id' => $prompt->id,
            'ai_model_id' => $modelId,
            'status' => 'paused',
            'category_mode' => 'smart',
            'draft_limit' => 1,
            'article_limit' => 1,
        ];
    }

    /** @return array{Prompt, TitleLibrary} */
    private function taskDependencies(): array
    {
        $prompt = Prompt::query()->firstOrCreate(
            ['name' => 'Scoped Prompt'],
            ['type' => 'content', 'content' => 'Write an article.'],
        );
        $titleLibrary = TitleLibrary::query()->firstOrCreate(
            ['name' => 'Scoped Titles'],
            ['description' => '', 'title_count' => 0],
        );

        return [$prompt, $titleLibrary];
    }

    /** @param list<string> $scopes */
    private function actingWithToken(Admin $admin, array $scopes): static
    {
        $plainToken = $admin->createToken('entry-access', $scopes)->plainTextToken;

        return $this->withHeader('Authorization', 'Bearer '.$plainToken);
    }
}
