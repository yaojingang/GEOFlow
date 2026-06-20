<?php

namespace Tests\Feature;

use App\Models\Admin;
use App\Models\AiModel;
use App\Models\Article;
use App\Models\ArticleDistribution;
use App\Models\Author;
use App\Models\Category;
use App\Models\DistributionChannel;
use App\Models\KnowledgeBase;
use App\Models\Prompt;
use App\Models\Task;
use App\Models\TitleLibrary;
use App\Support\AdminWeb;
use App\Support\GeoFlow\ApiKeyCrypto;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * 后台任务页（Blade）最小可用测试：鉴权与页面渲染。
 */
class AdminTasksPageTest extends TestCase
{
    use RefreshDatabase;

    public function test_guest_is_redirected_to_admin_login_when_visiting_tasks_page(): void
    {
        $this->get(route('admin.tasks.index'))
            ->assertRedirect(route('admin.login'));
    }

    public function test_authenticated_admin_can_view_tasks_page_with_filters(): void
    {
        $admin = Admin::query()->create([
            'username' => 'tasks_admin',
            'password' => 'secret-123',
            'email' => 'tasks-admin@example.com',
            'display_name' => 'Tasks Admin',
            'role' => 'admin',
            'status' => 'active',
        ]);

        $this->actingAs($admin, 'admin')
            ->get(route('admin.tasks.index', ['keyword' => 'demo', 'status' => 'active']))
            ->assertOk()
            ->assertSee(__('admin.tasks.page_title'))
            ->assertSee(__('admin.tasks.empty_title'))
            ->assertViewHas('tasks')
            ->assertViewHas('taskI18n');
    }

    public function test_authenticated_admin_can_open_task_create_page(): void
    {
        $admin = Admin::query()->create([
            'username' => 'tasks_admin_create',
            'password' => 'secret-123',
            'email' => 'tasks-admin-create@example.com',
            'display_name' => 'Tasks Admin',
            'role' => 'admin',
            'status' => 'active',
        ]);

        $this->actingAs($admin, 'admin')
            ->get(route('admin.tasks.create'))
            ->assertOk()
            ->assertSee(__('admin.task_create.page_heading'));
    }

    public function test_task_create_and_edit_forms_use_full_admin_content_width(): void
    {
        $admin = Admin::query()->create([
            'username' => 'tasks_form_layout_admin',
            'password' => 'secret-123',
            'email' => 'tasks-form-layout@example.com',
            'display_name' => 'Tasks Form Layout Admin',
            'role' => 'admin',
            'status' => 'active',
        ]);
        Category::query()->create([
            'name' => '任务分类',
            'slug' => 'task-form-layout-category',
        ]);
        $task = Task::query()->create([
            'name' => 'Layout Task',
            'status' => 'active',
            'schedule_enabled' => 1,
            'publish_interval' => 3600,
            'draft_limit' => 5,
            'article_limit' => 10,
        ]);

        $this->actingAs($admin, 'admin')
            ->get(route('admin.tasks.create'))
            ->assertOk()
            ->assertSee('data-task-form-shell', false)
            ->assertSee('xl:grid-cols-12', false)
            ->assertSee('lg:grid-cols-3', false)
            ->assertDontSee('max-w-4xl mx-auto', false);

        $this->actingAs($admin, 'admin')
            ->get(route('admin.tasks.edit', ['taskId' => (int) $task->id]))
            ->assertOk()
            ->assertSee('data-task-form-shell', false)
            ->assertSee('xl:grid-cols-12', false)
            ->assertDontSee('max-w-4xl mx-auto', false);
    }

    public function test_task_form_disables_distribution_channels_when_local_only_scope_is_selected(): void
    {
        $admin = Admin::query()->create([
            'username' => 'tasks_distribution_scope_admin',
            'password' => 'secret-123',
            'email' => 'tasks-distribution-scope@example.com',
            'display_name' => 'Tasks Distribution Scope Admin',
            'role' => 'admin',
            'status' => 'active',
        ]);
        Category::query()->create([
            'name' => '任务分类',
            'slug' => 'task-distribution-scope-category',
        ]);
        DistributionChannel::query()->create([
            'name' => '目标站点',
            'domain' => 'target.example.com',
            'endpoint_url' => 'https://target.example.com',
            'status' => 'active',
        ]);

        $this->actingAs($admin, 'admin')
            ->withSession([
                '_old_input' => [
                    'publish_scope' => 'local_only',
                    'distribution_channel_ids' => ['1'],
                ],
            ])
            ->get(route('admin.tasks.create'))
            ->assertOk()
            ->assertSee('data-publish-scope-option', false)
            ->assertSee('data-distribution-channel-card', false)
            ->assertSee('data-distribution-channel-input', false)
            ->assertSee('data-distribution-strategy-input', false)
            ->assertSee('syncDistributionChannelsByScope', false)
            ->assertSee('disabled data-distribution-channel-input', false)
            ->assertSee('disabled data-distribution-strategy-input', false)
            ->assertSee('data-distribution-channel-count', false)
            ->assertDontSee('value="1" checked', false);
    }

    public function test_task_form_collapses_distribution_channels_after_two_rows(): void
    {
        $admin = Admin::query()->create([
            'username' => 'tasks_distribution_channel_collapse_admin',
            'password' => 'secret-123',
            'email' => 'tasks-distribution-channel-collapse@example.com',
            'display_name' => 'Tasks Distribution Collapse Admin',
            'role' => 'admin',
            'status' => 'active',
        ]);
        Category::query()->create([
            'name' => '任务分类',
            'slug' => 'task-distribution-channel-collapse-category',
        ]);

        for ($index = 1; $index <= 8; $index++) {
            DistributionChannel::query()->create([
                'name' => '目标站点 '.$index,
                'domain' => 'target-'.$index.'.example.com',
                'endpoint_url' => 'https://target-'.$index.'.example.com',
                'status' => 'active',
            ]);
        }

        $response = $this->actingAs($admin, 'admin')
            ->get(route('admin.tasks.create'))
            ->assertOk()
            ->assertSee(__('admin.task_create.button.distribution_channel_expand_more', ['count' => 2]))
            ->assertSee(__('admin.task_create.button.distribution_channel_select_all'))
            ->assertSee(__('admin.task_create.button.distribution_channel_clear'));

        $this->assertSame(
            2,
            preg_match_all('/<label[^>]*data-distribution-channel-card[^>]*data-distribution-channel-collapsed="true"/', (string) $response->getContent())
        );
    }

    public function test_local_only_task_submission_ignores_distribution_channel_ids(): void
    {
        $admin = Admin::query()->create([
            'username' => 'tasks_local_only_submit_admin',
            'password' => 'secret-123',
            'email' => 'tasks-local-only-submit@example.com',
            'display_name' => 'Tasks Local Only Submit Admin',
            'role' => 'admin',
            'status' => 'active',
        ]);
        $aiModel = AiModel::query()->create([
            'name' => '测试模型',
            'api_key' => app(ApiKeyCrypto::class)->encrypt('test-key'),
            'model_id' => 'test-model',
            'model_type' => 'chat',
            'api_url' => 'https://api.example.com/v1',
            'status' => 'active',
        ]);
        $prompt = Prompt::query()->create([
            'name' => '正文提示词',
            'type' => 'content',
            'content' => '请写 {{title}}',
        ]);
        $titleLibrary = TitleLibrary::query()->create([
            'name' => '标题库',
        ]);
        $category = Category::query()->create([
            'name' => '科技资讯',
            'slug' => 'tech',
        ]);
        $channel = DistributionChannel::query()->create([
            'name' => '目标站点',
            'domain' => 'target.example.com',
            'endpoint_url' => 'https://target.example.com',
            'status' => 'active',
        ]);

        $this->actingAs($admin, 'admin')
            ->post(route('admin.tasks.store'), [
                'task_name' => '仅本站任务',
                'title_library_id' => $titleLibrary->id,
                'prompt_id' => $prompt->id,
                'ai_model_id' => $aiModel->id,
                'fixed_category_id' => $category->id,
                'status' => 'paused',
                'publish_scope' => 'local_only',
                'article_limit' => 3,
                'draft_limit' => 2,
                'publish_interval' => 60,
                'category_mode' => 'fixed',
                'model_selection_mode' => 'fixed',
                'distribution_channel_ids' => [(string) $channel->id],
            ])
            ->assertRedirect(route('admin.tasks.index'));

        $task = Task::query()->where('name', '仅本站任务')->firstOrFail();
        $this->assertSame('local_only', (string) $task->publish_scope);
        $this->assertDatabaseMissing('task_distribution_channels', [
            'task_id' => (int) $task->id,
            'distribution_channel_id' => (int) $channel->id,
        ]);
    }

    public function test_admin_can_create_task_with_zero_one_and_five_knowledge_bases(): void
    {
        $admin = $this->createTaskFormAdmin('tasks_multi_kb_create_admin');
        $dependencies = $this->createTaskFormDependencies();
        $knowledgeBases = $this->createKnowledgeBases(5);

        $cases = [
            '不使用知识库' => [],
            '单知识库' => [(string) $knowledgeBases[0]->id],
            '五个知识库' => $knowledgeBases->pluck('id')->map(static fn ($id): string => (string) $id)->all(),
        ];

        foreach ($cases as $taskName => $knowledgeBaseIds) {
            $this->actingAs($admin, 'admin')
                ->post(route('admin.tasks.store'), $this->validTaskPayload($dependencies, [
                    'task_name' => $taskName,
                    'knowledge_base_ids' => $knowledgeBaseIds,
                ]))
                ->assertRedirect(route('admin.tasks.index'))
                ->assertSessionHasNoErrors();

            $task = Task::query()->where('name', $taskName)->firstOrFail();
            $this->assertSame($knowledgeBaseIds[0] ?? null, $task->knowledge_base_id !== null ? (string) $task->knowledge_base_id : null);
            $this->assertSame(
                $knowledgeBaseIds,
                $task->knowledgeBases()
                    ->pluck('knowledge_bases.id')
                    ->map(static fn ($id): string => (string) $id)
                    ->all()
            );
        }
    }

    public function test_admin_cannot_create_task_with_more_than_five_knowledge_bases(): void
    {
        $admin = $this->createTaskFormAdmin('tasks_multi_kb_limit_admin');
        $dependencies = $this->createTaskFormDependencies();
        $knowledgeBaseIds = $this->createKnowledgeBases(6)
            ->pluck('id')
            ->map(static fn ($id): string => (string) $id)
            ->all();

        $this->actingAs($admin, 'admin')
            ->from(route('admin.tasks.create'))
            ->post(route('admin.tasks.store'), $this->validTaskPayload($dependencies, [
                'task_name' => '超过五个知识库任务',
                'knowledge_base_ids' => $knowledgeBaseIds,
            ]))
            ->assertRedirect(route('admin.tasks.create'))
            ->assertSessionHasErrors('knowledge_base_ids');

        $this->assertDatabaseMissing('tasks', [
            'name' => '超过五个知识库任务',
        ]);
    }

    public function test_task_form_collapses_knowledge_bases_after_two_rows(): void
    {
        $admin = $this->createTaskFormAdmin('tasks_multi_kb_collapse_admin');
        $dependencies = $this->createTaskFormDependencies();
        $knowledgeBases = $this->createKnowledgeBases(8);

        $response = $this->actingAs($admin, 'admin')
            ->get(route('admin.tasks.create'))
            ->assertOk()
            ->assertSee('展开更多 2 个知识库');

        $this->assertSame(
            2,
            preg_match_all('/<label[^>]*data-knowledge-base-card[^>]*data-knowledge-base-collapsed="true"/', (string) $response->getContent())
        );

        $task = Task::query()->create([
            'name' => '已选后排知识库任务',
            'title_library_id' => $dependencies['title_library']->id,
            'prompt_id' => $dependencies['prompt']->id,
            'ai_model_id' => $dependencies['ai_model']->id,
            'knowledge_base_id' => $knowledgeBases[6]->id,
            'status' => 'paused',
            'schedule_enabled' => 0,
            'publish_interval' => 3600,
            'draft_limit' => 5,
            'article_limit' => 10,
        ]);
        $task->knowledgeBases()->sync([
            (int) $knowledgeBases[6]->id => ['sort_order' => 0],
        ]);

        $response = $this->actingAs($admin, 'admin')
            ->get(route('admin.tasks.edit', ['taskId' => (int) $task->id]))
            ->assertOk()
            ->assertSee('展开更多 1 个知识库')
            ->assertSee('name="knowledge_base_ids[]" value="'.(int) $knowledgeBases[6]->id.'" checked', false);

        $this->assertSame(
            1,
            preg_match_all('/<label[^>]*data-knowledge-base-card[^>]*data-knowledge-base-collapsed="true"/', (string) $response->getContent())
        );
    }

    public function test_admin_can_edit_task_knowledge_base_selection_and_clear_it(): void
    {
        $admin = $this->createTaskFormAdmin('tasks_multi_kb_edit_admin');
        $dependencies = $this->createTaskFormDependencies();
        $knowledgeBases = $this->createKnowledgeBases(3);

        $task = Task::query()->create([
            'name' => '待编辑知识库任务',
            'title_library_id' => $dependencies['title_library']->id,
            'prompt_id' => $dependencies['prompt']->id,
            'ai_model_id' => $dependencies['ai_model']->id,
            'knowledge_base_id' => $knowledgeBases[0]->id,
            'status' => 'paused',
            'schedule_enabled' => 0,
            'publish_interval' => 3600,
            'draft_limit' => 5,
            'article_limit' => 10,
        ]);
        $task->knowledgeBases()->sync([
            (int) $knowledgeBases[0]->id => ['sort_order' => 0],
            (int) $knowledgeBases[1]->id => ['sort_order' => 1],
        ]);

        $this->actingAs($admin, 'admin')
            ->get(route('admin.tasks.edit', ['taskId' => (int) $task->id]))
            ->assertOk()
            ->assertSee('name="knowledge_base_ids[]" value="'.(int) $knowledgeBases[0]->id.'" checked', false)
            ->assertSee('name="knowledge_base_ids[]" value="'.(int) $knowledgeBases[1]->id.'" checked', false);

        $this->actingAs($admin, 'admin')
            ->put(route('admin.tasks.update', ['taskId' => (int) $task->id]), $this->validTaskPayload($dependencies, [
                'task_name' => '已更新知识库任务',
                'knowledge_base_ids' => [(string) $knowledgeBases[2]->id],
            ]))
            ->assertRedirect(route('admin.tasks.index'))
            ->assertSessionHasNoErrors();

        $task->refresh();
        $this->assertSame((int) $knowledgeBases[2]->id, (int) $task->knowledge_base_id);
        $this->assertSame(
            [(string) $knowledgeBases[2]->id],
            $task->knowledgeBases()
                ->pluck('knowledge_bases.id')
                ->map(static fn ($id): string => (string) $id)
                ->all()
        );

        $this->actingAs($admin, 'admin')
            ->put(route('admin.tasks.update', ['taskId' => (int) $task->id]), $this->validTaskPayload($dependencies, [
                'task_name' => '已清空知识库任务',
            ]))
            ->assertRedirect(route('admin.tasks.index'))
            ->assertSessionHasNoErrors();

        $task->refresh();
        $this->assertNull($task->knowledge_base_id);
        $this->assertSame(0, $task->knowledgeBases()->count());
    }

    public function test_task_article_action_links_to_filtered_article_list(): void
    {
        $admin = Admin::query()->create([
            'username' => 'tasks_article_filter_admin',
            'password' => 'secret-123',
            'email' => 'tasks-article-filter-admin@example.com',
            'display_name' => 'Tasks Admin',
            'role' => 'admin',
            'status' => 'active',
        ]);

        $task = Task::query()->create([
            'name' => 'Filtered Task',
            'status' => 'active',
            'schedule_enabled' => 1,
            'publish_interval' => 3600,
            'draft_limit' => 5,
            'article_limit' => 10,
        ]);

        $this->actingAs($admin, 'admin')
            ->get(route('admin.tasks.index'))
            ->assertOk()
            ->assertSee('/'.AdminWeb::basePath().'/articles?task_id='.(int) $task->id, false);
    }

    public function test_task_lifecycle_button_matches_task_status(): void
    {
        $admin = Admin::query()->create([
            'username' => 'tasks_lifecycle_admin',
            'password' => 'secret-123',
            'email' => 'tasks-lifecycle-admin@example.com',
            'display_name' => 'Tasks Admin',
            'role' => 'admin',
            'status' => 'active',
        ]);

        $activeTask = Task::query()->create([
            'name' => 'Active Task',
            'status' => 'active',
            'schedule_enabled' => 1,
            'publish_interval' => 3600,
            'draft_limit' => 5,
            'article_limit' => 10,
        ]);
        $pausedTask = Task::query()->create([
            'name' => 'Paused Task',
            'status' => 'paused',
            'schedule_enabled' => 0,
            'publish_interval' => 3600,
            'draft_limit' => 5,
            'article_limit' => 10,
        ]);

        $response = $this->actingAs($admin, 'admin')
            ->get(route('admin.tasks.index'))
            ->assertOk();

        $response->assertSee('id="batch-btn-'.(int) $activeTask->id.'"', false)
            ->assertSee('data-batch-action="stop"', false)
            ->assertSee('id="batch-btn-'.(int) $pausedTask->id.'"', false)
            ->assertSee('data-batch-action="start"', false)
            ->assertSee('text-green-600 hover:text-green-800 hover:bg-green-50', false);
    }

    public function test_task_list_shows_distribution_failure_summary(): void
    {
        $admin = Admin::query()->create([
            'username' => 'tasks_distribution_status_admin',
            'password' => 'secret-123',
            'email' => 'tasks-distribution-status@example.com',
            'display_name' => 'Tasks Distribution Admin',
            'role' => 'admin',
            'status' => 'active',
        ]);
        $task = Task::query()->create([
            'name' => 'Distribution Failure Task',
            'status' => 'active',
            'schedule_enabled' => 1,
            'publish_interval' => 3600,
            'draft_limit' => 5,
            'article_limit' => 10,
        ]);
        $category = Category::query()->create([
            'name' => '任务分发分类',
            'slug' => 'task-distribution-category',
        ]);
        $author = Author::query()->create([
            'name' => 'GEOFlow',
        ]);
        $channel = DistributionChannel::query()->create([
            'name' => '失败目标站点',
            'domain' => 'failed-target.example.com',
            'endpoint_url' => 'https://failed-target.example.com/geoflow/agent',
            'status' => 'active',
        ]);
        $article = Article::query()->create([
            'title' => '任务分发失败文章',
            'slug' => 'task-distribution-failed-article',
            'excerpt' => '摘要',
            'content' => '正文',
            'category_id' => $category->id,
            'author_id' => $author->id,
            'task_id' => $task->id,
            'status' => 'published',
            'review_status' => 'approved',
            'published_at' => now(),
        ]);
        ArticleDistribution::query()->create([
            'article_id' => $article->id,
            'distribution_channel_id' => $channel->id,
            'action' => 'publish',
            'status' => 'failed',
            'idempotency_key' => 'task-list-failed',
            'last_error_message' => 'Target timeout',
        ]);

        $this->actingAs($admin, 'admin')
            ->get(route('admin.tasks.index'))
            ->assertOk()
            ->assertSee(__('admin.distribution.task_status.failed', ['count' => 1]));
    }

    public function test_authenticated_admin_can_delete_task_without_legacy_article_queue_table(): void
    {
        $admin = Admin::query()->create([
            'username' => 'tasks_delete_admin',
            'password' => 'secret-123',
            'email' => 'tasks-delete-admin@example.com',
            'display_name' => 'Tasks Delete Admin',
            'role' => 'admin',
            'status' => 'active',
        ]);

        $task = Task::query()->create([
            'name' => 'Delete Task Without Legacy Queue',
            'status' => 'paused',
            'schedule_enabled' => 0,
            'publish_interval' => 3600,
            'draft_limit' => 5,
            'article_limit' => 10,
        ]);

        $this->actingAs($admin, 'admin')
            ->from(route('admin.tasks.index'))
            ->post(route('admin.tasks.delete', ['taskId' => (int) $task->id]))
            ->assertRedirect(route('admin.tasks.index'))
            ->assertSessionHasNoErrors()
            ->assertSessionHas('message', __('admin.tasks.message.delete_success'));

        $this->assertDatabaseMissing('tasks', ['id' => $task->id]);
    }

    private function createTaskFormAdmin(string $username): Admin
    {
        return Admin::query()->create([
            'username' => $username,
            'password' => 'secret-123',
            'email' => $username.'@example.com',
            'display_name' => 'Tasks Admin',
            'role' => 'admin',
            'status' => 'active',
        ]);
    }

    /**
     * @return array{ai_model: AiModel, prompt: Prompt, title_library: TitleLibrary, category: Category}
     */
    private function createTaskFormDependencies(): array
    {
        return [
            'ai_model' => AiModel::query()->create([
                'name' => '任务测试模型',
                'api_key' => app(ApiKeyCrypto::class)->encrypt('test-key'),
                'model_id' => 'test-model',
                'model_type' => 'chat',
                'api_url' => 'https://api.example.com/v1',
                'status' => 'active',
            ]),
            'prompt' => Prompt::query()->create([
                'name' => '任务正文提示词',
                'type' => 'content',
                'content' => '请写 {{title}}',
            ]),
            'title_library' => TitleLibrary::query()->create([
                'name' => '任务标题库',
            ]),
            'category' => Category::query()->create([
                'name' => '任务分类',
                'slug' => 'task-category-'.uniqid(),
            ]),
        ];
    }

    /**
     * @return \Illuminate\Database\Eloquent\Collection<int, KnowledgeBase>
     */
    private function createKnowledgeBases(int $count): \Illuminate\Database\Eloquent\Collection
    {
        $knowledgeBases = new \Illuminate\Database\Eloquent\Collection();
        for ($index = 1; $index <= $count; $index++) {
            $knowledgeBases->push(KnowledgeBase::query()->create([
                'name' => '任务知识库 '.$index,
                'description' => '',
                'content' => '任务知识库内容 '.$index,
                'file_type' => 'markdown',
                'word_count' => 12,
                'character_count' => 12,
            ]));
        }

        return $knowledgeBases;
    }

    /**
     * @param  array{ai_model: AiModel, prompt: Prompt, title_library: TitleLibrary, category: Category}  $dependencies
     * @param  array<string,mixed>  $overrides
     * @return array<string,mixed>
     */
    private function validTaskPayload(array $dependencies, array $overrides = []): array
    {
        return array_merge([
            'task_name' => '多知识库任务',
            'title_library_id' => (int) $dependencies['title_library']->id,
            'prompt_id' => (int) $dependencies['prompt']->id,
            'ai_model_id' => (int) $dependencies['ai_model']->id,
            'fixed_category_id' => (int) $dependencies['category']->id,
            'status' => 'paused',
            'publish_scope' => 'local_only',
            'article_limit' => 3,
            'draft_limit' => 2,
            'publish_interval' => 60,
            'category_mode' => 'fixed',
            'model_selection_mode' => 'fixed',
        ], $overrides);
    }
}
