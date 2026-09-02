<?php

namespace Tests\Feature;

use App\Models\Admin;
use App\Models\AiModel;
use App\Models\Category;
use App\Models\DistributionChannel;
use App\Models\Prompt;
use App\Models\Task;
use App\Models\Title;
use App\Models\TitleLibrary;
use App\Support\GeoFlow\ApiKeyCrypto;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminTaskTitleReadinessTest extends TestCase
{
    use RefreshDatabase;

    public function test_title_readiness_endpoint_requires_admin_authentication(): void
    {
        $this->post('/geo_admin/tasks/title-readiness', [])->assertRedirect();
    }

    public function test_title_readiness_endpoint_validates_its_public_contract(): void
    {
        $admin = $this->admin();
        $this->actingAs($admin, 'admin')
            ->postJson('/geo_admin/tasks/title-readiness', [
                'title_library_id' => 0,
                'article_limit' => 0,
                'is_loop' => 'invalid',
                'status' => 'running',
                'task_id' => -1,
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors([
                'title_library_id',
                'article_limit',
                'is_loop',
                'status',
                'task_id',
            ]);
    }

    public function test_title_readiness_endpoint_returns_localized_report_and_management_url(): void
    {
        app()->setLocale('zh_CN');
        $library = TitleLibrary::query()->create(['name' => '增长标题库']);
        Title::query()->create([
            'library_id' => $library->id,
            'title' => '已用标题',
            'keyword' => '增长',
            'used_count' => 1,
        ]);

        $response = $this->actingAs($this->admin(), 'admin')
            ->postJson('/geo_admin/tasks/title-readiness', [
                'title_library_id' => $library->id,
                'article_limit' => 2,
                'is_loop' => false,
                'status' => 'active',
            ])
            ->assertOk()
            ->assertJsonPath('status', 'blocked')
            ->assertJsonPath('can_save', false)
            ->assertJsonPath('library.available', 0)
            ->assertJsonPath('shortage', 2)
            ->assertJsonPath('issues.0.code', 'title_library_exhausted')
            ->assertJsonPath('issues.0.title', '当前标题库的可用标题已耗尽')
            ->assertJsonPath('manage_url', route('admin.title-libraries.detail', ['libraryId' => $library->id]));

        $this->assertNotContains('将文章总数调整为 0 篇。', $response->json('issues.0.suggestions'));
    }

    public function test_regular_admin_cannot_inspect_a_protected_hosted_task(): void
    {
        $library = TitleLibrary::query()->create(['name' => '托管任务标题库']);
        $task = Task::query()->create([
            'name' => '托管任务',
            'title_library_id' => $library->id,
            'article_limit' => 2,
            'status' => 'paused',
            'schedule_enabled' => 0,
        ]);
        $channel = DistributionChannel::query()->create([
            'name' => '托管站点',
            'domain' => 'hosted.example.test',
            'endpoint_url' => 'https://hosted.example.test',
            'channel_type' => DistributionChannel::TYPE_HOSTED_SITE,
            'status' => 'active',
        ]);
        $task->distributionChannels()->attach($channel->id, [
            'trigger' => 'after_local_publish',
            'remote_status' => 'follow_local',
            'failure_policy' => 'ignore_distribution_failure',
            'max_attempts' => 3,
            'sort_order' => 0,
        ]);

        $admin = $this->admin();
        $this->actingAs($admin, 'admin')
            ->postJson('/geo_admin/tasks/title-readiness', [
                'title_library_id' => $library->id,
                'article_limit' => 2,
                'is_loop' => false,
                'status' => 'paused',
                'task_id' => $task->id,
            ])
            ->assertForbidden();
    }

    public function test_regular_admin_does_not_receive_protected_conflict_task_details(): void
    {
        $library = TitleLibrary::query()->create(['name' => '共享托管标题库']);
        Title::query()->create([
            'library_id' => $library->id,
            'title' => '共享标题',
            'keyword' => '共享',
            'used_count' => 0,
        ]);
        $inspectedTask = Task::query()->create([
            'name' => '普通任务',
            'title_library_id' => $library->id,
            'article_limit' => 1,
            'status' => 'paused',
            'schedule_enabled' => 0,
        ]);
        $protectedTask = Task::query()->create([
            'name' => '受保护托管任务',
            'title_library_id' => $library->id,
            'article_limit' => 2,
            'created_count' => 0,
            'status' => 'active',
            'schedule_enabled' => 1,
        ]);
        $channel = DistributionChannel::query()->create([
            'name' => '受保护站点',
            'domain' => 'protected-conflict.example.test',
            'endpoint_url' => 'https://protected-conflict.example.test',
            'channel_type' => DistributionChannel::TYPE_HOSTED_SITE,
            'status' => 'active',
        ]);
        $protectedTask->distributionChannels()->attach($channel->id, [
            'trigger' => 'after_local_publish',
            'remote_status' => 'follow_local',
            'failure_policy' => 'ignore_distribution_failure',
            'max_attempts' => 3,
            'sort_order' => 0,
        ]);

        $admin = $this->admin();
        $this->actingAs($admin, 'admin')
            ->postJson('/geo_admin/tasks/title-readiness', [
                'title_library_id' => $library->id,
                'article_limit' => 1,
                'is_loop' => false,
                'status' => 'paused',
                'task_id' => $inspectedTask->id,
            ])
            ->assertOk()
            ->assertJsonPath('status', 'warning')
            ->assertJsonCount(0, 'conflicts')
            ->assertJsonPath('redacted_conflict_count', 1)
            ->assertJsonMissing(['name' => '受保护托管任务']);

        Title::query()->where('library_id', $library->id)->update(['used_count' => 1]);
        $this->actingAs($admin, 'admin')
            ->postJson(route('admin.tasks.batch'), [
                'task_id' => $inspectedTask->id,
                'action' => 'start',
            ])
            ->assertConflict()
            ->assertJsonCount(0, 'details.title_readiness.conflicts')
            ->assertJsonPath('details.title_readiness.redacted_conflict_count', 1)
            ->assertJsonMissing(['name' => '受保护托管任务']);
    }

    public function test_immediate_start_returns_conflict_without_creating_a_queue_record(): void
    {
        $library = TitleLibrary::query()->create(['name' => '立即执行空库']);
        $task = Task::query()->create([
            'name' => '立即执行检查任务',
            'title_library_id' => $library->id,
            'article_limit' => 2,
            'created_count' => 0,
            'is_loop' => 0,
            'status' => 'paused',
            'schedule_enabled' => 0,
        ]);

        $this->actingAs($this->admin(), 'admin')
            ->postJson(route('admin.tasks.batch'), [
                'task_id' => $task->id,
                'action' => 'start',
            ])
            ->assertConflict()
            ->assertJsonPath('error', 'task_title_library_not_ready')
            ->assertJsonPath('details.title_readiness.can_activate', false)
            ->assertJsonPath('details.title_readiness.edit_url', route('admin.tasks.edit', ['taskId' => $task->id]))
            ->assertJsonPath('details.title_readiness.manage_url', route('admin.title-libraries.detail', ['libraryId' => $library->id]));

        $this->assertDatabaseMissing('task_runs', ['task_id' => $task->id]);
        $this->assertDatabaseHas('tasks', [
            'id' => $task->id,
            'status' => 'paused',
            'schedule_enabled' => 0,
        ]);
    }

    public function test_task_form_shows_live_title_capacity_and_accessible_centered_dialog(): void
    {
        $library = TitleLibrary::query()->create(['name' => '页面标题库']);
        foreach ([0, 1] as $index => $usedCount) {
            Title::query()->create([
                'library_id' => $library->id,
                'title' => '页面标题'.($index + 1),
                'keyword' => '页面'.($index + 1),
                'used_count' => $usedCount,
            ]);
        }
        Category::query()->create(['name' => '页面分类', 'slug' => 'readiness-page']);

        $this->actingAs($this->admin(), 'admin')
            ->get(route('admin.tasks.create'))
            ->assertOk()
            ->assertSee('页面标题库（可用 1 / 共 2）')
            ->assertSee('data-title-total="2"', false)
            ->assertSee('data-title-used="1"', false)
            ->assertSee('data-title-available="1"', false)
            ->assertSee('data-task-title-stats', false)
            ->assertSee('aria-live="polite"', false)
            ->assertSee('data-task-title-readiness-dialog', false)
            ->assertSee('role="alertdialog"', false)
            ->assertSee('fixed inset-0 m-auto', false)
            ->assertDontSee('window.confirm', false)
            ->assertDontSee('alert(', false);
    }

    public function test_task_index_has_a_centered_readiness_dialog_for_start_failures(): void
    {
        $this->actingAs($this->admin(), 'admin')
            ->withSession([
                'title_readiness_report' => [
                    'status' => 'blocked',
                    'summary' => '标题不足',
                    'recommendation' => '请补充标题',
                    'library' => ['total' => 1, 'used' => 1, 'available' => 0],
                    'task' => ['remaining' => 2],
                    'issues' => [],
                ],
            ])
            ->get(route('admin.tasks.index'))
            ->assertOk()
            ->assertSee('data-task-index-readiness-dialog', false)
            ->assertSee('data-task-index-readiness-initial', false)
            ->assertSee('w-[min(600px,calc(100vw-2rem))]', false)
            ->assertSee('max-h-[min(760px,calc(100dvh-2rem))]', false)
            ->assertSee('data-task-index-readiness-edit', false)
            ->assertSee('data-task-index-readiness-manage', false);
    }

    public function test_active_form_submission_is_blocked_with_structured_report_while_paused_submission_is_allowed(): void
    {
        $admin = $this->admin();
        $dependencies = $this->formDependencies();
        $dependencies['model']->forceFill(['owner_admin_id' => $admin->id])->save();
        $payload = [
            'task_name' => '标题检查任务',
            'title_library_id' => $dependencies['library']->id,
            'prompt_id' => $dependencies['prompt']->id,
            'ai_model_id' => $dependencies['model']->id,
            'fixed_category_id' => $dependencies['category']->id,
            'publish_scope' => 'local_only',
            'article_limit' => 2,
            'draft_limit' => 2,
            'publish_interval' => 60,
            'category_mode' => 'fixed',
            'model_selection_mode' => 'fixed',
        ];

        $this->actingAs($admin, 'admin')
            ->from(route('admin.tasks.create'))
            ->post(route('admin.tasks.store'), $payload + ['status' => 'active'])
            ->assertRedirect(route('admin.tasks.create'))
            ->assertSessionHas('title_readiness_report')
            ->assertSessionHasErrors();
        $this->assertDatabaseMissing('tasks', ['name' => '标题检查任务']);

        $this->actingAs($admin, 'admin')
            ->post(route('admin.tasks.store'), array_merge($payload, [
                'task_name' => '暂停标题检查任务',
                'status' => 'paused',
            ]))
            ->assertRedirect(route('admin.tasks.index'));
        $this->assertDatabaseHas('tasks', [
            'name' => '暂停标题检查任务',
            'status' => 'paused',
            'schedule_enabled' => 0,
        ]);
    }

    private function admin(): Admin
    {
        return Admin::query()->create([
            'username' => 'title_readiness_admin_'.uniqid(),
            'password' => 'secret-123',
            'email' => uniqid().'@example.test',
            'display_name' => 'Title readiness admin',
            'role' => 'admin',
            'status' => 'active',
        ]);
    }

    /** @return array{library:TitleLibrary,prompt:Prompt,model:AiModel,category:Category} */
    private function formDependencies(): array
    {
        return [
            'library' => TitleLibrary::query()->create(['name' => '表单空标题库']),
            'prompt' => Prompt::query()->create([
                'name' => '表单提示词',
                'type' => 'content',
                'content' => '请写 {{title}}',
            ]),
            'model' => AiModel::query()->create([
                'name' => '表单模型',
                'api_key' => app(ApiKeyCrypto::class)->encrypt('test-key'),
                'model_id' => 'form-model',
                'model_type' => 'chat',
                'api_url' => 'https://api.example.test/v1',
                'status' => 'active',
            ]),
            'category' => Category::query()->create([
                'name' => '表单分类',
                'slug' => 'readiness-form-'.uniqid(),
            ]),
        ];
    }
}
