<?php

namespace Tests\Feature;

use App\Models\Admin;
use App\Models\AiModel;
use App\Models\Article;
use App\Models\ArticleDistribution;
use App\Models\Author;
use App\Models\Category;
use App\Models\DistributionChannel;
use App\Models\ImageLibrary;
use App\Models\KnowledgeBase;
use App\Models\Prompt;
use App\Models\Task;
use App\Models\TaskRun;
use App\Models\TitleLibrary;
use App\Models\WorkerHeartbeat;
use App\Services\GeoFlow\DistributionOrchestrator;
use App\Services\GeoFlow\JobQueueService;
use App\Support\AdminWeb;
use App\Support\GeoFlow\ApiKeyCrypto;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
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

    public function test_task_pages_share_the_section_navigation_and_keep_index_actions(): void
    {
        $this->ensureWorkerHeartbeatTable();
        $admin = $this->createTaskFormAdmin('tasks_section_navigation_admin');

        foreach ([
            'admin.tasks.index' => 'task-list',
            'admin.tasks.workers' => 'workers',
            'admin.tasks.jobs' => 'jobs',
        ] as $routeName => $activeKey) {
            $response = $this->actingAs($admin, 'admin')
                ->get(route($routeName));

            $response
                ->assertOk()
                ->assertSee('data-tasks-navigation', false)
                ->assertSee(AdminWeb::routePath('admin.tasks.index'), false)
                ->assertSee(AdminWeb::routePath('admin.tasks.workers'), false)
                ->assertSee(AdminWeb::routePath('admin.tasks.jobs'), false);

            $document = new \DOMDocument;
            $document->loadHTML((string) $response->getContent(), LIBXML_NOERROR | LIBXML_NOWARNING | LIBXML_NONET);
            $xpath = new \DOMXPath($document);
            $navigation = $xpath->query('//*[@data-tasks-navigation]')?->item(0);
            $activeItems = $xpath->query('.//*[@aria-current="page"]', $navigation);

            self::assertNotNull($navigation, $routeName);
            self::assertSame(3, $xpath->query('.//*[@data-tasks-navigation-item]', $navigation)?->length, $routeName);
            self::assertSame(3, $xpath->query('.//*[@data-tasks-navigation-dot]', $navigation)?->length, $routeName);
            self::assertSame(1, $activeItems?->length, $routeName);
            self::assertSame(
                $activeKey,
                $activeItems?->item(0)?->attributes?->getNamedItem('data-tasks-navigation-item')?->nodeValue,
                $routeName,
            );

            if ($routeName === 'admin.tasks.index') {
                $response
                    ->assertSee('href="'.route('admin.tasks.create').'"', false)
                    ->assertSee('data-run-all-tasks', false);
            }
        }

        foreach (['zh_CN', 'en', 'ja', 'es', 'ru', 'pt_BR'] as $locale) {
            App::setLocale($locale);

            foreach (['admin.tasks.page_title', 'admin.tasks.worker.title', 'admin.tasks.jobs.recent'] as $key) {
                self::assertNotSame($key, __($key), $locale.': '.$key);
            }
        }
    }

    public function test_task_page_combines_queue_metrics_and_limits_monitoring_previews_to_ten_rows(): void
    {
        $this->ensureWorkerHeartbeatTable();
        $admin = $this->createTaskFormAdmin('tasks_monitoring_overview_admin');
        $task = Task::query()->create([
            'name' => 'Monitoring Overview Task',
            'status' => 'active',
            'schedule_enabled' => 1,
        ]);

        for ($index = 0; $index < 12; $index++) {
            TaskRun::query()->create([
                'task_id' => $task->id,
                'status' => 'completed',
                'created_at' => now()->subSeconds($index),
                'finished_at' => now()->subSeconds($index),
            ]);
            WorkerHeartbeat::query()->create([
                'worker_id' => 'monitor-worker-'.$index,
                'status' => 'idle',
                'last_seen_at' => now()->subSeconds($index),
                'meta' => [],
            ]);
        }

        $response = $this->actingAs($admin, 'admin')
            ->get(route('admin.tasks.index'))
            ->assertOk()
            ->assertSee(__('admin.tasks.monitoring.overview_title'))
            ->assertSee(route('admin.tasks.workers'), false)
            ->assertSee(route('admin.tasks.jobs'), false)
            ->assertSee('id="queue-pending"', false)
            ->assertSee('id="stats-total-tasks"', false)
            ->assertSeeInOrder([
                'id="task-overview-heading"',
                'data-task-list',
            ], false);

        $html = (string) $response->getContent();
        $document = new \DOMDocument;
        $document->loadHTML($html, LIBXML_NOERROR | LIBXML_NOWARNING | LIBXML_NONET);
        $xpath = new \DOMXPath($document);
        $metricCells = $xpath->query('//section[@aria-labelledby="task-overview-heading"]/dl/div');

        $this->assertSame(8, $metricCells->length);
        foreach ($metricCells as $metricCell) {
            $classTokens = preg_split('/\s+/', trim($metricCell->getAttribute('class'))) ?: [];
            foreach (['flex', 'flex-col', 'items-center', 'justify-center', 'text-center'] as $expectedClass) {
                $this->assertContains($expectedClass, $classTokens);
            }
        }

        $this->assertCount(10, $response->viewData('workers'));
        $this->assertCount(10, $response->viewData('recentJobs'));
        $this->assertSame(1, substr_count($html, 'id="queue-pending"'));
    }

    public function test_worker_and_job_detail_pages_paginate_ten_records_per_page(): void
    {
        $this->ensureWorkerHeartbeatTable();
        $admin = $this->createTaskFormAdmin('tasks_monitoring_pagination_admin');
        $task = Task::query()->create([
            'name' => 'Monitoring Pagination Task',
            'status' => 'active',
            'schedule_enabled' => 1,
        ]);

        $oldestRun = null;
        for ($index = 0; $index < 12; $index++) {
            $run = TaskRun::query()->create([
                'task_id' => $task->id,
                'status' => 'completed',
                'created_at' => now()->subSeconds($index),
                'finished_at' => now()->subSeconds($index),
            ]);
            WorkerHeartbeat::query()->create([
                'worker_id' => 'paged-worker-'.$index,
                'status' => 'idle',
                'last_seen_at' => now()->subSeconds($index),
                'meta' => [],
            ]);
            if ($index === 11) {
                $oldestRun = $run;
            }
        }

        $jobsFirstPage = $this->actingAs($admin, 'admin')
            ->get(route('admin.tasks.jobs'))
            ->assertOk()
            ->assertViewHas('jobs', static fn ($jobs): bool => $jobs->perPage() === 10 && $jobs->total() === 12);
        $this->assertSame(10, substr_count((string) $jobsFirstPage->getContent(), 'data-job-row'));

        $jobsSecondPage = $this->actingAs($admin, 'admin')
            ->get(route('admin.tasks.jobs', ['page' => 2]))
            ->assertOk();
        $this->assertSame(2, substr_count((string) $jobsSecondPage->getContent(), 'data-job-row'));

        $workersFirstPage = $this->actingAs($admin, 'admin')
            ->get(route('admin.tasks.workers'))
            ->assertOk()
            ->assertViewHas('workers', static fn ($workers): bool => $workers->perPage() === 10 && $workers->total() === 12);
        $this->assertSame(10, substr_count((string) $workersFirstPage->getContent(), 'data-worker-row'));

        $workersSecondPage = $this->actingAs($admin, 'admin')
            ->get(route('admin.tasks.workers', ['page' => 2]))
            ->assertOk();
        $this->assertSame(2, substr_count((string) $workersSecondPage->getContent(), 'data-worker-row'));

        $focusedJob = $this->actingAs($admin, 'admin')
            ->get(route('admin.tasks.jobs', ['run_id' => $oldestRun->id]))
            ->assertOk()
            ->assertSee(__('admin.tasks.jobs.focused_run', ['id' => $oldestRun->id]))
            ->assertSee('id="job-'.$oldestRun->id.'"', false);
        $this->assertSame(1, substr_count((string) $focusedJob->getContent(), 'data-job-row'));
    }

    public function test_monitoring_rows_explain_the_task_record_and_failure_in_plain_language(): void
    {
        $this->ensureWorkerHeartbeatTable();
        $admin = $this->createTaskFormAdmin('tasks_monitoring_plain_language_admin');
        $task = Task::query()->create([
            'name' => 'Plain Language Task',
            'status' => 'active',
            'schedule_enabled' => 1,
        ]);
        $category = Category::query()->create([
            'name' => 'Monitoring Category',
            'slug' => 'monitoring-category',
        ]);
        $author = Author::query()->create(['name' => 'Monitoring Author']);
        $article = Article::query()->create([
            'title' => 'Problem Article',
            'slug' => 'problem-article',
            'content' => 'Article body.',
            'category_id' => $category->id,
            'author_id' => $author->id,
            'task_id' => $task->id,
            'status' => 'draft',
            'review_status' => 'pending',
        ]);
        $run = TaskRun::query()->create([
            'task_id' => $task->id,
            'article_id' => $article->id,
            'status' => 'failed',
            'error_message' => '正文过短 internal-token-value',
            'created_at' => now(),
            'finished_at' => now(),
        ]);
        WorkerHeartbeat::query()->create([
            'worker_id' => 'plain-language-worker',
            'status' => 'running',
            'last_seen_at' => now(),
            'meta' => ['task_run_id' => $run->id],
        ]);
        $runWithoutArticle = TaskRun::query()->create([
            'task_id' => $task->id,
            'status' => 'running',
            'created_at' => now()->subSecond(),
        ]);
        WorkerHeartbeat::query()->create([
            'worker_id' => 'plain-language-worker-without-article',
            'status' => 'running',
            'last_seen_at' => now()->subSecond(),
            'meta' => ['task_run_id' => $runWithoutArticle->id],
        ]);

        $this->actingAs($admin, 'admin')
            ->get(route('admin.tasks.jobs'))
            ->assertOk()
            ->assertSee(__('admin.tasks.jobs.summary.failed', ['task' => $task->name]))
            ->assertSee(__('admin.tasks.jobs.failed_article', [
                'article' => $article->title,
                'reason' => __('admin.tasks.failure.content_too_short_detail'),
            ]))
            ->assertDontSee('internal-token-value')
            ->assertSee(route('admin.articles.edit', ['articleId' => $article->id]), false)
            ->assertSee(route('admin.articles.index', ['task_id' => $task->id]), false);

        $healthResponse = $this->actingAs($admin, 'admin')
            ->get(route('admin.tasks.health'))
            ->assertOk()
            ->assertJsonPath('success', true);
        $failedRunPayload = collect($healthResponse->json('recent_runs'))
            ->firstWhere('id', $run->id);
        $this->assertSame('failed', $failedRunPayload['status']);
        $this->assertArrayNotHasKey('error_message', $failedRunPayload);
        $this->assertStringNotContainsString(
            'internal-token-value',
            (string) $healthResponse->json('recent_runs_html')
        );

        $this->actingAs($admin, 'admin')
            ->get(route('admin.tasks.workers'))
            ->assertOk()
            ->assertSee(__('admin.tasks.worker.summary_busy', ['task' => $task->name]))
            ->assertSee('Job #'.$run->id)
            ->assertSee(route('admin.tasks.jobs', ['run_id' => $runWithoutArticle->id]).'#job-'.$runWithoutArticle->id, false);
    }

    public function test_monitoring_pages_handle_deleted_relations_unknown_statuses_and_long_worker_ids(): void
    {
        $this->ensureWorkerHeartbeatTable();
        $admin = $this->createTaskFormAdmin('tasks_monitoring_edge_cases_admin');
        $task = Task::query()->create([
            'name' => 'Monitoring Edge Task',
            'status' => 'active',
            'schedule_enabled' => 1,
        ]);
        $category = Category::query()->create([
            'name' => 'Monitoring Edge Category',
            'slug' => 'monitoring-edge-category',
        ]);
        $author = Author::query()->create(['name' => 'Monitoring Edge Author']);
        $article = Article::query()->create([
            'title' => 'Deleted Monitoring Article',
            'slug' => 'deleted-monitoring-article',
            'content' => 'Article body.',
            'category_id' => $category->id,
            'author_id' => $author->id,
            'task_id' => $task->id,
            'status' => 'draft',
            'review_status' => 'pending',
        ]);
        $run = TaskRun::query()->create([
            'task_id' => $task->id,
            'article_id' => $article->id,
            'status' => 'recovering',
            'created_at' => now(),
        ]);
        $longWorkerId = str_repeat('worker-segment-', 10);
        WorkerHeartbeat::query()->create([
            'worker_id' => $longWorkerId,
            'status' => 'draining',
            'last_seen_at' => now(),
            'meta' => ['task_run_id' => $run->id],
        ]);

        $article->delete();
        $task->delete();

        $this->actingAs($admin, 'admin')
            ->get(route('admin.tasks.jobs', ['run_id' => $run->id]))
            ->assertOk()
            ->assertSee(__('admin.tasks.jobs.status.unknown'))
            ->assertSee(__('admin.tasks.monitoring.record_deleted'))
            ->assertDontSee(route('admin.articles.edit', ['articleId' => $article->id]), false)
            ->assertDontSee(route('admin.articles.index', ['task_id' => $task->id]), false);

        $this->actingAs($admin, 'admin')
            ->get(route('admin.tasks.workers'))
            ->assertOk()
            ->assertSee(__('admin.tasks.worker.status.unknown'))
            ->assertSee($longWorkerId)
            ->assertSee('class="break-all"', false);
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
        Category::query()->create([
            'name' => '任务分类',
            'slug' => 'task-create-ai-quality-category',
        ]);

        $response = $this->actingAs($admin, 'admin')
            ->get(route('admin.tasks.create'))
            ->assertOk()
            ->assertSee(__('admin.task_create.page_heading'));

        $response->assertSeeInOrder([
            '<label class="relative inline-flex cursor-pointer items-center gap-3">',
            'data-ai-quality-toggle',
        ], false);
        $response->assertSee('name="ai_quality_timeout_sampling_enabled"', false)
            ->assertSee(__('admin.task_create.ai_quality.timeout_sampling_label'));
    }

    public function test_admin_task_form_persists_timeout_sampling_only_when_ai_quality_is_enabled(): void
    {
        $admin = $this->createTaskFormAdmin('tasks_timeout_sampling_admin');
        $dependencies = $this->createTaskFormDependencies();

        $this->actingAs($admin, 'admin')
            ->post(route('admin.tasks.store'), $this->validTaskPayload($dependencies, [
                'task_name' => 'Disabled quality sampling task',
                'ai_quality_timeout_sampling_enabled' => '1',
            ]))
            ->assertRedirect(route('admin.tasks.index'))
            ->assertSessionHasNoErrors();

        $task = Task::query()->where('name', 'Disabled quality sampling task')->firstOrFail();
        $this->assertFalse($task->ai_quality_timeout_sampling_enabled);
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
            ->assertSee('data-task-form', false)
            ->assertSee('data-title-readiness-url', false)
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

    public function test_phase_one_hosted_task_contract_is_enforced_when_saving_the_task(): void
    {
        $admin = $this->createTaskFormAdmin('hosted_task_contract_admin');
        $admin->update(['role' => 'super_admin']);
        $dependencies = $this->createTaskFormDependencies();
        $channels = collect(['alpha', 'beta'])->map(fn (string $label) => DistributionChannel::query()->create([
            'name' => ucfirst($label).' hosted site',
            'domain' => $label.'.sites.test',
            'endpoint_url' => 'https://'.$label.'.sites.test',
            'channel_type' => DistributionChannel::TYPE_HOSTED_SITE,
            'status' => DistributionChannel::STATUS_ACTIVE,
        ]));

        $this->actingAs($admin, 'admin')
            ->from(route('admin.tasks.create'))
            ->post(route('admin.tasks.store'), $this->validTaskPayload($dependencies, [
                'task_name' => '托管站错误范围',
                'publish_scope' => 'local_and_distribution',
                'distribution_channel_ids' => [(string) $channels[0]->id],
            ]))
            ->assertRedirect(route('admin.tasks.create'))
            ->assertSessionHasErrors('publish_scope');

        $this->actingAs($admin, 'admin')
            ->from(route('admin.tasks.create'))
            ->post(route('admin.tasks.store'), $this->validTaskPayload($dependencies, [
                'task_name' => '托管站数量超限',
                'publish_scope' => 'distribution_only',
                'distribution_channel_ids' => $channels->pluck('id')->map('strval')->all(),
            ]))
            ->assertRedirect(route('admin.tasks.create'))
            ->assertSessionHasErrors('distribution_channel_ids');

        $this->assertDatabaseMissing('tasks', ['name' => '托管站错误范围']);
        $this->assertDatabaseMissing('tasks', ['name' => '托管站数量超限']);
    }

    public function test_regular_admin_cannot_see_bind_or_edit_a_hosted_site_task(): void
    {
        $admin = $this->createTaskFormAdmin('regular_hosted_task_admin');
        $dependencies = $this->createTaskFormDependencies();
        $channel = DistributionChannel::query()->create([
            'name' => 'Restricted hosted site',
            'domain' => 'restricted.sites.test',
            'endpoint_url' => 'https://restricted.sites.test',
            'channel_type' => DistributionChannel::TYPE_HOSTED_SITE,
            'status' => DistributionChannel::STATUS_ACTIVE,
        ]);

        $this->actingAs($admin, 'admin')
            ->get(route('admin.tasks.create'))
            ->assertOk()
            ->assertDontSee('Restricted hosted site');

        $this->actingAs($admin, 'admin')
            ->from(route('admin.tasks.create'))
            ->post(route('admin.tasks.store'), $this->validTaskPayload($dependencies, [
                'task_name' => 'Unauthorized hosted task',
                'publish_scope' => 'distribution_only',
                'distribution_channel_ids' => [(string) $channel->id],
            ]))
            ->assertRedirect(route('admin.tasks.create'))
            ->assertSessionHasErrors('distribution_channel_ids');

        $task = Task::query()->create([
            'name' => 'Existing hosted task',
            'status' => 'paused',
            'publish_scope' => 'distribution_only',
        ]);
        $task->distributionChannels()->attach($channel->id);

        $this->actingAs($admin, 'admin')
            ->get(route('admin.tasks.index'))
            ->assertOk()
            ->assertSee('Existing hosted task')
            ->assertSee(__('admin.tasks.action.super_admin_managed'))
            ->assertDontSee('id="status-form-'.$task->id.'"', false)
            ->assertDontSee('id="batch-btn-'.$task->id.'"', false)
            ->assertDontSee('href="'.route('admin.tasks.edit', ['taskId' => $task->id]).'"', false)
            ->assertDontSee('action="'.route('admin.tasks.delete', ['taskId' => $task->id]).'"', false);

        $this->actingAs($admin, 'admin')
            ->get(route('admin.tasks.edit', ['taskId' => $task->id]))
            ->assertForbidden();
        $this->actingAs($admin, 'admin')
            ->post(route('admin.tasks.toggle-status', ['taskId' => $task->id]), ['status' => 'paused'])
            ->assertForbidden();
        $this->actingAs($admin, 'admin')
            ->post(route('admin.tasks.batch'), ['task_id' => $task->id, 'action' => 'start'])
            ->assertForbidden();
        $this->actingAs($admin, 'admin')
            ->post(route('admin.tasks.delete', ['taskId' => $task->id]))
            ->assertForbidden();

        $this->assertNotNull(Task::query()->find($task->id));
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
                'task_revision' => app(DistributionOrchestrator::class)->taskRevision($task->fresh()),
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
                'task_revision' => app(DistributionOrchestrator::class)->taskRevision($task->fresh()),
            ]))
            ->assertRedirect(route('admin.tasks.index'))
            ->assertSessionHasNoErrors();

        $task->refresh();
        $this->assertNull($task->knowledge_base_id);
        $this->assertSame(0, $task->knowledgeBases()->count());
    }

    public function test_stale_task_edit_cannot_restore_distribution_state_after_channel_deletion(): void
    {
        $admin = $this->createTaskFormAdmin('tasks_stale_distribution_edit_admin');
        $dependencies = $this->createTaskFormDependencies();
        $channel = DistributionChannel::query()->create([
            'name' => '即将删除的任务渠道',
            'domain' => 'stale-task-channel.example.com',
            'endpoint_url' => 'https://stale-task-channel.example.com',
            'status' => 'active',
        ]);
        $task = Task::query()->create([
            'name' => '待并发保护任务',
            'title_library_id' => $dependencies['title_library']->id,
            'prompt_id' => $dependencies['prompt']->id,
            'ai_model_id' => $dependencies['ai_model']->id,
            'status' => 'active',
            'publish_scope' => 'distribution_only',
            'schedule_enabled' => 1,
            'publish_interval' => 3600,
            'draft_limit' => 5,
            'article_limit' => 10,
        ]);
        $task->distributionChannels()->attach($channel->id);

        $editResponse = $this->actingAs($admin, 'admin')
            ->get(route('admin.tasks.edit', ['taskId' => (int) $task->id]))
            ->assertOk();
        $this->assertSame(
            1,
            preg_match('/name="task_revision" value="([a-f0-9]{64})"/', (string) $editResponse->getContent(), $matches)
        );

        $task->forceFill([
            'status' => 'paused',
            'publish_scope' => 'local_only',
        ])->save();
        $task->distributionChannels()->detach($channel->id);

        $this->actingAs($admin, 'admin')
            ->put(route('admin.tasks.update', ['taskId' => (int) $task->id]), $this->validTaskPayload($dependencies, [
                'task_name' => '旧表单不应覆盖任务',
                'status' => 'active',
                'publish_scope' => 'distribution_only',
                'distribution_channel_ids' => [],
                'task_revision' => $matches[1],
            ]))
            ->assertSessionHasErrors();

        $this->get(route('admin.tasks.edit', ['taskId' => (int) $task->id]))
            ->assertOk()
            ->assertSee('option value="paused" selected', false)
            ->assertSee('name="publish_scope" value="local_only" checked', false)
            ->assertDontSee('name="publish_scope" value="distribution_only" checked', false);

        $this->assertDatabaseHas('tasks', [
            'id' => (int) $task->id,
            'status' => 'paused',
            'publish_scope' => 'local_only',
        ]);
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

    public function test_task_lifecycle_actions_use_an_accessible_in_page_confirmation_dialog(): void
    {
        $admin = $this->createTaskFormAdmin('tasks_lifecycle_dialog_admin');
        $taskName = "Lifecycle Dialog Task\nwith 'quotes' and <markup>";
        $task = Task::query()->create([
            'name' => $taskName,
            'status' => 'paused',
            'schedule_enabled' => 0,
            'publish_interval' => 3600,
            'draft_limit' => 5,
            'article_limit' => 10,
        ]);

        $response = $this->actingAs($admin, 'admin')
            ->get(route('admin.tasks.index'))
            ->assertOk()
            ->assertSee('data-admin-action-dialog', false)
            ->assertSee('role="alertdialog"', false)
            ->assertSee('aria-modal="true"', false)
            ->assertSee('data-admin-action-cancel', false)
            ->assertSee('data-admin-action-confirm', false)
            ->assertSee('window.AdminActionDialog.confirm', false)
            ->assertSee('data-run-all-tasks', false)
            ->assertDontSee('data-task-lifecycle-dialog', false)
            ->assertDontSee('onclick="executeAllActiveTasks()', false)
            ->assertDontSee('onclick="startBatchExecution(', false)
            ->assertDontSee('onclick="stopBatchExecution(', false)
            ->assertDontSee('onchange="handleStatusToggle(', false)
            ->assertDontSee('window.confirm(', false);

        $document = new \DOMDocument;
        @$document->loadHTML((string) $response->getContent());
        $button = $document->getElementById('batch-btn-'.(int) $task->id);

        $this->assertNotNull($button);
        $this->assertSame((string) $task->id, $button->getAttribute('data-task-id'));
        $this->assertSame($taskName, $button->getAttribute('data-task-name'));
    }

    public function test_task_stop_succeeds_while_optimization_tables_are_pending_migration(): void
    {
        $admin = $this->createTaskFormAdmin('tasks_stop_schema_rollout_admin');
        $task = Task::query()->create([
            'name' => 'Schema rollout stop task',
            'status' => 'active',
            'schedule_enabled' => 1,
            'publish_interval' => 3600,
            'draft_limit' => 5,
            'article_limit' => 10,
        ]);
        $run = TaskRun::query()->create([
            'task_id' => $task->id,
            'status' => 'pending',
        ]);

        Schema::dropIfExists('article_ai_optimization_steps');
        Schema::dropIfExists('article_ai_optimization_runs');

        $this->actingAs($admin, 'admin')
            ->postJson(route('admin.tasks.batch'), [
                'task_id' => $task->id,
                'action' => 'stop',
            ])
            ->assertOk()
            ->assertJsonPath('success', true);

        $this->assertSame('paused', $task->fresh()->status);
        $this->assertSame(0, (int) $task->fresh()->schedule_enabled);
        $this->assertSame('cancelled', $run->fresh()->status);
    }

    public function test_task_batch_failure_does_not_expose_database_details(): void
    {
        $admin = $this->createTaskFormAdmin('tasks_stop_error_redaction_admin');
        $task = Task::query()->create([
            'name' => 'Redacted stop failure task',
            'status' => 'active',
            'schedule_enabled' => 1,
            'publish_interval' => 3600,
            'draft_limit' => 5,
            'article_limit' => 10,
        ]);

        Schema::dropIfExists('task_runs');

        $response = $this->actingAs($admin, 'admin')
            ->postJson(route('admin.tasks.batch'), [
                'task_id' => $task->id,
                'action' => 'stop',
            ])
            ->assertStatus(422)
            ->assertJsonPath('success', false)
            ->assertJsonPath('message', __('admin.tasks.message.status_update_failed'));

        $this->assertStringNotContainsString('task_runs', (string) $response->getContent());
        $this->assertSame('active', $task->fresh()->status);
        $this->assertSame(1, (int) $task->fresh()->schedule_enabled);
    }

    public function test_task_health_failure_does_not_expose_database_details(): void
    {
        $admin = $this->createTaskFormAdmin('tasks_health_error_redaction_admin');

        Schema::dropIfExists('task_runs');

        $response = $this->actingAs($admin, 'admin')
            ->getJson(route('admin.tasks.health'))
            ->assertStatus(500)
            ->assertJsonPath('success', false)
            ->assertJsonPath('message', __('admin.tasks.message.status_update_failed'));

        $this->assertStringNotContainsString('task_runs', (string) $response->getContent());
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
        TaskRun::query()->create([
            'task_id' => $task->id,
            'status' => 'completed',
            'finished_at' => now(),
        ]);
        $runningRun = TaskRun::query()->create([
            'task_id' => $task->id,
            'status' => 'running',
            'started_at' => now(),
        ]);

        $this->actingAs($admin, 'admin')
            ->from(route('admin.tasks.index'))
            ->post(route('admin.tasks.delete', ['taskId' => (int) $task->id]))
            ->assertRedirect(route('admin.tasks.index'))
            ->assertSessionHasNoErrors()
            ->assertSessionHas('message', __('admin.tasks.message.delete_success'));

        $this->assertNull(Task::query()->find($task->id));
        $this->assertNotNull(Task::onlyTrashed()->find($task->id));
        $this->assertDatabaseHas('task_trash_entries', ['task_id' => $task->id]);
        $this->assertDatabaseHas('task_runs', [
            'id' => $runningRun->id,
            'status' => 'cancelled',
            'error_message' => '任务已删除',
        ]);

        $queueService = app(JobQueueService::class);
        $queueService->completeJob((int) $runningRun->id, (int) $task->id, null, 10);
        $queueService->failJob((int) $runningRun->id, (int) $task->id, 'late failure', 10, 1);
        $this->assertSame('cancelled', (string) $runningRun->fresh()->status);

        $trashResponse = $this->actingAs($admin, 'admin')
            ->get(route('admin.tasks.index'))
            ->assertOk()
            ->assertSee('data-task-trash', false)
            ->assertSee('data-task-trash-content', false)
            ->assertSee('Delete Task Without Legacy Queue')
            ->assertSee(__('admin.tasks.trash.retention', ['days' => Task::TRASH_RETENTION_DAYS]));

        $trashHtml = (string) $trashResponse->getContent();
        $this->assertLessThan(strpos($trashHtml, 'data-task-trash'), strpos($trashHtml, 'data-task-list'));
        $this->assertGreaterThanOrEqual(2, substr_count($trashHtml, 'Delete Task Without Legacy Queue'));
        $this->assertMatchesRegularExpression(
            '/<details(?=[^>]*data-task-trash)(?![^>]*\sopen(?:\s|=|>))[^>]*>/',
            $trashHtml,
        );
    }

    public function test_authenticated_admin_can_restore_a_task_from_trash_in_a_safe_paused_state(): void
    {
        $admin = $this->createTaskFormAdmin('tasks_restore_admin');
        $category = Category::query()->create([
            'name' => 'Restore task category',
            'slug' => 'restore-task-category',
        ]);
        $author = Author::query()->create(['name' => 'Restore task author']);
        $task = Task::query()->create([
            'name' => 'Task ready to restore',
            'status' => 'active',
            'schedule_enabled' => 1,
            'next_run_at' => now()->addHour(),
        ]);
        $article = Article::query()->create([
            'title' => 'Article moved with deleted task',
            'slug' => 'article-moved-with-deleted-task',
            'content' => 'Restore task article body.',
            'category_id' => $category->id,
            'author_id' => $author->id,
            'task_id' => $task->id,
            'status' => 'draft',
            'review_status' => 'pending',
        ]);

        $this->actingAs($admin, 'admin')
            ->post(route('admin.tasks.delete', ['taskId' => $task->id]))
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $trashPage = $this->actingAs($admin, 'admin')
            ->get(route('admin.tasks.index'))
            ->assertOk()
            ->assertSee('data-admin-confirm-form', false)
            ->assertSee('data-admin-confirm-tone="success"', false)
            ->assertSee('data-admin-confirm-title="'.__('admin.tasks.trash.confirm_restore', ['name' => $task->name]).'"', false)
            ->assertSee('data-admin-confirm-submit disabled aria-disabled="true"', false)
            ->assertSee(__('admin.tasks.trash.action_restore'))
            ->assertDontSee('onsubmit="return confirm', false)
            ->assertSee('data-lucide="rotate-ccw"', false);

        $snapshotId = (int) $trashPage->viewData('trashPagination')['snapshot_id'];
        $trashSequence = (int) $trashPage->viewData('trashedTasks')[0]['trash_sequence'];
        $restoreUrl = route('admin.tasks.restore', [
            'taskId' => $task->id,
            'page' => 1,
            'trash_page' => 1,
            'trash_snapshot_id' => $snapshotId,
            'trash_sequence' => $trashSequence,
        ]);
        $returnUrl = route('admin.tasks.index', [
            'page' => 1,
            'trash_page' => 1,
            'trash_snapshot_id' => $snapshotId,
        ]).'#task-trash';

        $this->actingAs($admin, 'admin')
            ->post($restoreUrl)
            ->assertRedirect($returnUrl)
            ->assertSessionHasNoErrors()
            ->assertSessionHas('message', __('admin.tasks.message.restore_success', [
                'name' => 'Task ready to restore',
            ]));

        $restoredTask = Task::query()->findOrFail($task->id);
        $this->assertSame('paused', (string) $restoredTask->status);
        $this->assertSame(0, (int) $restoredTask->schedule_enabled);
        $this->assertNull($restoredTask->next_run_at);
        $this->assertDatabaseMissing('task_trash_entries', ['task_id' => $task->id]);
        $this->assertTrue(Article::onlyTrashed()->whereKey($article->id)->exists());
        $this->assertNull(Article::withTrashed()->findOrFail($article->id)->task_id);
    }

    public function test_task_restore_rejects_an_expired_trash_entry_even_before_pruning_runs(): void
    {
        $admin = $this->createTaskFormAdmin('tasks_restore_expired_admin');
        $task = Task::query()->create([
            'name' => 'Expired restore task',
            'status' => 'paused',
            'schedule_enabled' => 0,
        ]);
        $task->delete();
        Task::onlyTrashed()->whereKey($task->id)->update([
            'deleted_at' => now()->subDays(Task::TRASH_RETENTION_DAYS),
        ]);
        DB::table('task_trash_entries')->where('task_id', $task->id)->update([
            'deleted_at' => now()->subDays(Task::TRASH_RETENTION_DAYS),
        ]);
        $trashSequence = (int) DB::table('task_trash_entries')
            ->where('task_id', $task->id)
            ->value('sequence');

        $returnUrl = route('admin.tasks.index', [
            'page' => 1,
            'trash_page' => 1,
        ]).'#task-trash';

        $this->actingAs($admin, 'admin')
            ->post(route('admin.tasks.restore', [
                'taskId' => $task->id,
                'page' => 1,
                'trash_page' => 1,
                'trash_sequence' => $trashSequence,
            ]))
            ->assertRedirect($returnUrl)
            ->assertSessionHasErrors();

        $this->assertTrue(Task::onlyTrashed()->whereKey($task->id)->exists());
        $this->assertDatabaseHas('task_trash_entries', ['task_id' => $task->id]);
    }

    public function test_stale_restore_form_cannot_undo_a_newer_task_deletion(): void
    {
        $admin = $this->createTaskFormAdmin('tasks_restore_stale_form_admin');
        $task = Task::query()->create([
            'name' => 'Task deleted twice',
            'status' => 'paused',
            'schedule_enabled' => 0,
        ]);

        $this->actingAs($admin, 'admin')
            ->post(route('admin.tasks.delete', ['taskId' => $task->id]))
            ->assertSessionHasNoErrors();
        $firstSequence = (int) DB::table('task_trash_entries')
            ->where('task_id', $task->id)
            ->value('sequence');
        $this->actingAs($admin, 'admin')
            ->post(route('admin.tasks.restore', [
                'taskId' => $task->id,
                'trash_sequence' => $firstSequence,
            ]))
            ->assertSessionHasNoErrors();
        $this->actingAs($admin, 'admin')
            ->post(route('admin.tasks.delete', ['taskId' => $task->id]))
            ->assertSessionHasNoErrors();
        $secondSequence = (int) DB::table('task_trash_entries')
            ->where('task_id', $task->id)
            ->value('sequence');
        $this->assertGreaterThan($firstSequence, $secondSequence);

        $this->actingAs($admin, 'admin')
            ->post(route('admin.tasks.restore', [
                'taskId' => $task->id,
                'trash_sequence' => $firstSequence,
            ]))
            ->assertSessionHasErrors();

        $this->assertTrue(Task::onlyTrashed()->whereKey($task->id)->exists());
        $this->assertDatabaseHas('task_trash_entries', [
            'task_id' => $task->id,
            'sequence' => $secondSequence,
        ]);
    }

    public function test_only_a_super_admin_can_restore_a_task_that_used_a_hosted_site(): void
    {
        $superAdmin = $this->createTaskFormAdmin('tasks_restore_hosted_super_admin');
        $superAdmin->update(['role' => 'super_admin']);
        $regularAdmin = $this->createTaskFormAdmin('tasks_restore_hosted_regular_admin');
        $channel = DistributionChannel::query()->create([
            'name' => 'Restore protected hosted site',
            'domain' => 'restore-protected.sites.test',
            'endpoint_url' => 'https://restore-protected.sites.test',
            'channel_type' => DistributionChannel::TYPE_HOSTED_SITE,
            'status' => DistributionChannel::STATUS_ACTIVE,
        ]);
        $task = Task::query()->create([
            'name' => 'Protected hosted task in trash',
            'status' => 'paused',
            'schedule_enabled' => 0,
            'publish_scope' => 'distribution_only',
        ]);
        $task->distributionChannels()->attach($channel->id);

        $this->actingAs($superAdmin, 'admin')
            ->post(route('admin.tasks.delete', ['taskId' => $task->id]))
            ->assertRedirect()
            ->assertSessionHasNoErrors();
        $trashSequence = (int) DB::table('task_trash_entries')
            ->where('task_id', $task->id)
            ->value('sequence');

        $regularTrash = $this->actingAs($regularAdmin, 'admin')
            ->get(route('admin.tasks.index', ['trash_page' => 1]))
            ->assertOk()
            ->assertSee('Protected hosted task in trash')
            ->assertSee(__('admin.tasks.trash.super_admin_restore'));
        $this->assertStringNotContainsString(
            route('admin.tasks.restore', ['taskId' => $task->id]),
            (string) $regularTrash->getContent(),
        );

        $this->actingAs($regularAdmin, 'admin')
            ->post(route('admin.tasks.restore', [
                'taskId' => $task->id,
                'trash_sequence' => $trashSequence,
            ]))
            ->assertRedirect()
            ->assertSessionHasErrors();
        $this->assertTrue(Task::onlyTrashed()->whereKey($task->id)->exists());

        $this->actingAs($superAdmin, 'admin')
            ->post(route('admin.tasks.restore', [
                'taskId' => $task->id,
                'trash_sequence' => $trashSequence,
            ]))
            ->assertRedirect()
            ->assertSessionHasNoErrors();
        $this->assertNotNull(Task::query()->find($task->id));
    }

    public function test_task_restore_copy_is_available_in_every_supported_admin_locale(): void
    {
        $keys = [
            'admin.tasks.trash.action_restore',
            'admin.tasks.trash.super_admin_restore',
            'admin.tasks.trash.confirm_restore',
            'admin.tasks.trash.column.actions',
            'admin.tasks.message.restore_success',
            'admin.tasks.message.restore_failed',
        ];

        foreach (['zh_CN', 'en', 'ja', 'es', 'ru', 'pt_BR'] as $locale) {
            app()->setLocale($locale);
            foreach ($keys as $key) {
                $translated = __($key, ['name' => 'Example']);
                $this->assertIsString($translated, $locale.' '.$key);
                $this->assertNotSame($key, $translated, $locale.' '.$key);
                $this->assertNotSame('', trim($translated), $locale.' '.$key);
            }
        }
    }

    public function test_task_delete_terminalizes_queued_and_in_flight_distributions(): void
    {
        $admin = $this->createTaskFormAdmin('tasks_delete_distribution_admin');
        $task = Task::query()->create([
            'name' => 'Delete task with distributions',
            'status' => 'paused',
            'schedule_enabled' => 0,
        ]);
        $category = Category::query()->create(['name' => 'Delete distribution category', 'slug' => 'delete-distribution-category']);
        $author = Author::query()->create(['name' => 'Delete distribution author']);
        $channel = DistributionChannel::query()->create([
            'name' => 'Delete distribution channel',
            'domain' => 'delete-distribution.test',
            'endpoint_url' => 'https://delete-distribution.test',
            'status' => DistributionChannel::STATUS_ACTIVE,
        ]);
        $article = Article::query()->create([
            'title' => 'Delete distribution article',
            'slug' => 'delete-distribution-article',
            'content' => 'Distribution body.',
            'category_id' => $category->id,
            'author_id' => $author->id,
            'task_id' => $task->id,
            'status' => 'published',
            'review_status' => 'approved',
            'published_at' => now(),
        ]);
        $queued = ArticleDistribution::query()->create([
            'article_id' => $article->id,
            'distribution_channel_id' => $channel->id,
            'action' => 'publish',
            'status' => 'queued',
            'idempotency_key' => 'task-delete-queued-distribution',
        ]);
        $sending = ArticleDistribution::query()->create([
            'article_id' => $article->id,
            'distribution_channel_id' => $channel->id,
            'action' => 'update',
            'status' => 'sending',
            'idempotency_key' => 'task-delete-sending-distribution',
        ]);

        $this->actingAs($admin, 'admin')
            ->post(route('admin.tasks.delete', ['taskId' => $task->id]))
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $this->assertSame('failed', (string) $queued->fresh()->status);
        $this->assertNull($queued->fresh()->next_retry_at);
        $this->assertSame('outcome_unknown', (string) $sending->fresh()->status);
        $this->assertNull($sending->fresh()->next_retry_at);
    }

    public function test_task_trash_history_is_paginated_and_reopens_for_follow_up_pages(): void
    {
        $this->travelTo('2026-08-27 12:00:00.123456');
        $admin = $this->createTaskFormAdmin('tasks_trash_pagination_admin');
        $deletedAfterSnapshot = Task::query()->create([
            'name' => 'trash-lower-id-after-snapshot-unique',
            'status' => 'paused',
        ]);
        $first = Task::query()->create(['name' => 'trash-first-oldest-unique', 'status' => 'paused']);
        $first->delete();

        for ($index = 0; $index < 49; $index++) {
            Task::query()->create(['name' => 'trash-middle-'.$index, 'status' => 'paused'])->delete();
        }

        $last = Task::query()->create(['name' => 'trash-newest-unique', 'status' => 'paused']);
        $last->delete();

        $firstPage = $this->actingAs($admin, 'admin')
            ->get(route('admin.tasks.index'))
            ->assertOk()
            ->assertSee(__('admin.tasks.trash.count', ['count' => 51]))
            ->assertSee('trash-newest-unique')
            ->assertDontSee('trash-first-oldest-unique');

        $this->assertMatchesRegularExpression(
            '/<details(?=[^>]*data-task-trash)(?![^>]*\sopen(?:\s|=|>))[^>]*>/',
            (string) $firstPage->getContent(),
        );
        $this->assertMatchesRegularExpression(
            '/trash_snapshot_id=\d+/',
            (string) $firstPage->getContent(),
        );
        $snapshot = $firstPage->viewData('trashPagination');
        $this->travelTo('2026-08-27 12:00:00.123456');
        $deletedAfterSnapshot->delete();
        $deletedAfterSnapshotSequence = (int) DB::table('task_trash_entries')
            ->where('task_id', $deletedAfterSnapshot->id)
            ->value('sequence');
        $this->assertGreaterThan((int) $snapshot['snapshot_id'], $deletedAfterSnapshotSequence);

        $secondPage = $this->actingAs($admin, 'admin')
            ->get(route('admin.tasks.index', [
                'trash_page' => 2,
                'trash_snapshot_id' => (int) $snapshot['snapshot_id'],
            ]))
            ->assertOk()
            ->assertSee('trash-first-oldest-unique')
            ->assertDontSee('trash-newest-unique')
            ->assertDontSee('trash-lower-id-after-snapshot-unique');

        $this->assertSame(51, $secondPage->viewData('trashPagination')['total']);

        $this->assertMatchesRegularExpression(
            '/<details(?=[^>]*data-task-trash)(?=[^>]*\sopen(?:\s|=|>))[^>]*>/',
            (string) $secondPage->getContent(),
        );
    }

    public function test_malformed_task_trash_query_falls_back_without_hiding_active_tasks(): void
    {
        $admin = $this->createTaskFormAdmin('tasks_trash_query_guard_admin');
        Task::query()->create([
            'name' => 'Visible task after malformed trash query',
            'status' => 'paused',
        ]);

        $this->actingAs($admin, 'admin')
            ->get(route('admin.tasks.index').'?trash_page[x]=1&trash_snapshot_at[x]=1&trash_snapshot_id[x]=1')
            ->assertOk()
            ->assertSee('Visible task after malformed trash query')
            ->assertViewHas('legacyError', null)
            ->assertViewHas('trashPagination', static fn (array $pagination): bool => $pagination['page'] === 1);
    }

    public function test_trashed_task_keeps_referenced_materials_protected_until_expiration(): void
    {
        $admin = $this->createTaskFormAdmin('tasks_trash_material_guard_admin');
        $dependencies = $this->createTaskFormDependencies();
        $imageLibrary = ImageLibrary::query()->create(['name' => '任务回收站图片库']);
        $knowledgeBases = $this->createKnowledgeBases(2);
        $knowledgeBase = $knowledgeBases->firstOrFail();
        $secondaryKnowledgeBase = $knowledgeBases->last();
        $author = Author::query()->create(['name' => '任务回收站作者']);
        $task = Task::query()->create([
            'name' => 'Material reference retained in task trash',
            'title_library_id' => $dependencies['title_library']->id,
            'image_library_id' => $imageLibrary->id,
            'knowledge_base_id' => $knowledgeBase->id,
            'prompt_id' => $dependencies['prompt']->id,
            'ai_model_id' => $dependencies['ai_model']->id,
            'author_id' => $author->id,
            'fixed_category_id' => $dependencies['category']->id,
            'category_mode' => 'fixed',
            'status' => 'paused',
            'schedule_enabled' => 0,
        ]);
        $task->knowledgeBases()->sync([
            (int) $knowledgeBase->id => ['sort_order' => 0],
            (int) $secondaryKnowledgeBase->id => ['sort_order' => 1],
        ]);

        $this->actingAs($admin, 'admin')
            ->post(route('admin.tasks.delete', ['taskId' => $task->id]))
            ->assertRedirect();

        $this->assertDatabaseCount('task_knowledge_bases', 2);

        $protectedResources = [
            [route('admin.title-libraries.delete', ['libraryId' => $dependencies['title_library']->id]), TitleLibrary::class, $dependencies['title_library']->id],
            [route('admin.image-libraries.delete', ['libraryId' => $imageLibrary->id]), ImageLibrary::class, $imageLibrary->id],
            [route('admin.knowledge-bases.delete', ['knowledgeBaseId' => $knowledgeBase->id]), KnowledgeBase::class, $knowledgeBase->id],
            [route('admin.knowledge-bases.delete', ['knowledgeBaseId' => $secondaryKnowledgeBase->id]), KnowledgeBase::class, $secondaryKnowledgeBase->id],
            [route('admin.ai-prompts.delete', ['promptId' => $dependencies['prompt']->id]), Prompt::class, $dependencies['prompt']->id],
            [route('admin.ai-models.delete', ['modelId' => $dependencies['ai_model']->id]), AiModel::class, $dependencies['ai_model']->id],
            [route('admin.authors.delete', ['authorId' => $author->id]), Author::class, $author->id],
            [route('admin.categories.delete', ['categoryId' => $dependencies['category']->id]), Category::class, $dependencies['category']->id],
        ];

        foreach ($protectedResources as [$url, $modelClass, $id]) {
            $this->actingAs($admin, 'admin')
                ->from(route('admin.tasks.index'))
                ->post($url)
                ->assertRedirect(route('admin.tasks.index'))
                ->assertSessionHasErrors();
            $this->assertNotNull($modelClass::query()->find($id));
        }

        Task::onlyTrashed()->findOrFail($task->id)->forceDelete();
        $this->assertDatabaseCount('task_knowledge_bases', 0);
    }

    public function test_task_trash_hides_and_prunes_tasks_at_the_ninety_day_boundary(): void
    {
        $this->travelTo('2026-08-27 12:00:00');
        $admin = $this->createTaskFormAdmin('tasks_trash_retention_admin');

        $recent = Task::query()->create(['name' => 'Recent trashed task', 'status' => 'paused']);
        $atBoundary = Task::query()->create(['name' => 'Boundary trashed task', 'status' => 'paused']);
        $expired = Task::query()->create(['name' => 'Expired trashed task', 'status' => 'paused']);
        $active = Task::query()->create(['name' => 'Active retained task', 'status' => 'paused']);

        $recent->delete();
        $atBoundary->delete();
        $expired->delete();
        Task::onlyTrashed()->whereKey($recent->id)->update(['deleted_at' => now()->subDays(89)]);
        Task::onlyTrashed()->whereKey($atBoundary->id)->update(['deleted_at' => now()->subDays(90)]);
        Task::onlyTrashed()->whereKey($expired->id)->update(['deleted_at' => now()->subDays(91)]);
        DB::table('task_trash_entries')->where('task_id', $recent->id)->update(['deleted_at' => now()->subDays(89)]);
        DB::table('task_trash_entries')->where('task_id', $atBoundary->id)->update(['deleted_at' => now()->subDays(90)]);
        DB::table('task_trash_entries')->where('task_id', $expired->id)->update(['deleted_at' => now()->subDays(91)]);

        $this->actingAs($admin, 'admin')
            ->get(route('admin.tasks.index'))
            ->assertOk()
            ->assertSee('Recent trashed task')
            ->assertDontSee('Boundary trashed task')
            ->assertDontSee('Expired trashed task')
            ->assertSee('Active retained task');

        $this->artisan('geoflow:prune-task-trash')
            ->expectsOutput('Permanently deleted 2 expired tasks.')
            ->assertSuccessful();

        $this->assertNotNull(Task::onlyTrashed()->find($recent->id));
        $this->assertNull(Task::withTrashed()->find($atBoundary->id));
        $this->assertNull(Task::withTrashed()->find($expired->id));
        $this->assertNotNull(Task::query()->find($active->id));

        $this->artisan('geoflow:prune-task-trash')
            ->expectsOutput('Permanently deleted 0 expired tasks.')
            ->assertSuccessful();
    }

    public function test_task_delete_rolls_back_when_trash_history_cannot_be_created(): void
    {
        $task = Task::query()->create(['name' => 'Trash history invariant task', 'status' => 'paused']);
        DB::table('task_trash_entries')->insert([
            'task_id' => $task->id,
            'sequence' => 1,
            'deleted_at' => now()->format('Y-m-d H:i:s.u'),
        ]);
        DB::table('task_trash_state')->where('id', 1)->update(['last_sequence' => 1]);

        try {
            $task->delete();
            $this->fail('Expected duplicate trash history to abort task deletion.');
        } catch (\Throwable) {
            $this->assertNotNull(Task::query()->find($task->id));
            $this->assertSame(1, (int) DB::table('task_trash_state')->where('id', 1)->value('last_sequence'));
            $this->assertDatabaseCount('task_trash_entries', 1);
        }
    }

    public function test_restored_task_is_not_pruned_from_stale_trash_history(): void
    {
        $task = Task::query()->create(['name' => 'Restored retention task', 'status' => 'paused']);
        $task->delete();
        Task::onlyTrashed()->whereKey($task->id)->update(['deleted_at' => now()->subDays(91)]);
        DB::table('task_trash_entries')->where('task_id', $task->id)->update(['deleted_at' => now()->subDays(91)]);

        $restored = Task::onlyTrashed()->findOrFail($task->id);
        $this->assertTrue($restored->restore());
        $this->artisan('geoflow:prune-task-trash')
            ->expectsOutput('Permanently deleted 0 expired tasks.')
            ->assertSuccessful();

        $this->assertNotNull(Task::query()->find($task->id));
        $this->assertDatabaseMissing('task_trash_entries', ['task_id' => $task->id]);
    }

    public function test_task_delete_uses_an_accessible_centered_confirmation_dialog(): void
    {
        config()->set('geoflow.admin_ui_v3_enabled', true);

        $admin = $this->createTaskFormAdmin('tasks_delete_dialog_admin');
        Task::query()->create([
            'name' => 'Dialog Preview Task',
            'status' => 'paused',
            'schedule_enabled' => 0,
            'publish_interval' => 3600,
            'draft_limit' => 5,
            'article_limit' => 10,
        ]);

        $this->actingAs($admin, 'admin')
            ->get(route('admin.tasks.index'))
            ->assertOk()
            ->assertSee('data-admin-confirm-form', false)
            ->assertSee('data-admin-confirm-tone="danger"', false)
            ->assertSee('data-admin-confirm-title="'.__('admin.tasks.delete_dialog.title').' “Dialog Preview Task”"', false)
            ->assertSee('data-admin-confirm-message="'.__('admin.tasks.delete_dialog.impact').'"', false)
            ->assertSee('data-admin-confirm-submit disabled aria-disabled="true"', false)
            ->assertSee('data-admin-action-dialog', false)
            ->assertSee('role="alertdialog"', false)
            ->assertSee('aria-modal="true"', false)
            ->assertSee(__('admin.tasks.delete_dialog.title'))
            ->assertSee(__('admin.tasks.delete_dialog.impact'))
            ->assertDontSee('data-task-delete-dialog', false)
            ->assertDontSee('onsubmit="return confirm', false);
    }

    public function test_task_action_column_keeps_space_between_delete_button_and_table_edge(): void
    {
        config()->set('geoflow.admin_ui_v3_enabled', true);

        $admin = $this->createTaskFormAdmin('tasks_action_spacing_admin');
        Task::query()->create([
            'name' => 'Action Spacing Task',
            'status' => 'paused',
            'schedule_enabled' => 0,
            'publish_interval' => 3600,
            'draft_limit' => 5,
            'article_limit' => 10,
        ]);

        $this->actingAs($admin, 'admin')
            ->get(route('admin.tasks.index'))
            ->assertOk()
            ->assertSee('mb-8 flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between', false)
            ->assertSee('data-task-list-table', false)
            ->assertSee('min-w-[1200px]', false)
            ->assertSee('w-[11.5rem] py-4 pl-3 pr-4 align-top sm:w-[12.5rem] sm:pl-4 sm:pr-5', false)
            ->assertSee('flex items-center justify-end gap-1.5 sm:gap-2', false);
    }

    private function ensureWorkerHeartbeatTable(): void
    {
        if (Schema::hasTable('worker_heartbeats')) {
            return;
        }

        Schema::create('worker_heartbeats', function (Blueprint $table): void {
            $table->string('worker_id')->primary();
            $table->string('status', 20)->default('idle');
            $table->timestamp('last_seen_at')->nullable();
            $table->text('meta')->nullable();
            $table->timestamps();
        });
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
     * @return Collection<int, KnowledgeBase>
     */
    private function createKnowledgeBases(int $count): Collection
    {
        $knowledgeBases = new Collection;
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
