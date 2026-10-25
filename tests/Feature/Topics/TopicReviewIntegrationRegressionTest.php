<?php

namespace Tests\Feature\Topics;

use App\Ai\Agents\MarkdownContentWriterAgent;
use App\Models\Admin;
use App\Models\AiModel;
use App\Models\Article;
use App\Models\Author;
use App\Models\Category;
use App\Models\Task;
use App\Models\TaskRun;
use App\Models\Title;
use App\Models\TitleLibrary;
use App\Models\Topic;
use App\Models\TopicBuildRun;
use App\Services\GeoFlow\AiExecutionContextFactory;
use App\Services\GeoFlow\JobQueueService;
use App\Services\GeoFlow\WorkerExecutionService;
use App\Services\Topics\TopicBatchService;
use App\Services\Topics\TopicGenerationService;
use App\Services\Topics\TopicImportRows;
use App\Services\Topics\TopicService;
use App\Services\Topics\TopicTaskService;
use App\Support\GeoFlow\ApiKeyCrypto;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class TopicReviewIntegrationRegressionTest extends TestCase
{
    use RefreshDatabase;

    public function test_review_20261001_maintenance_rule_change_rebuilds_with_same_sources(): void
    {
        Queue::fake();
        $actor = $this->actor();
        $sources = [$this->article('first'), $this->article('second')];
        $topics = app(TopicService::class);
        $tasks = app(TopicTaskService::class);
        $payload = json_decode($this->aiOutput($sources), true);
        $topic = $topics->create('primary', $payload + ['title' => 'GEO Guide', 'automatic_write' => true], $actor->id);
        $topics->publish($topic, 1, $actor->id);
        $legacySignature = hash('sha256', json_encode(array_column(app(TopicGenerationService::class)->candidates('primary', $topic->title), 'source_hash', 'article_id')));
        $topic->forceFill(['last_maintenance_signature' => $legacySignature])->save();
        $task = $tasks->save(['name' => 'Rule update', 'target_site_key' => 'primary', 'ai_model_id' => $this->model()->id, 'topic_limit' => 3, 'publish_interval' => 3600, 'status' => 'active', 'topic_settings' => ['after' => 'draft_only', 'auto_maintain' => true, 'bound_topic_ids' => [$topic->id], 'protect_manual' => false, 'rules' => 'Original rule']], $actor);
        $queue = app(JobQueueService::class);
        $id = $queue->enqueueTaskJob($task->id);
        $queue->claimPendingJobById($id, 'rule-1');
        $context = app(AiExecutionContextFactory::class)->fromTaskRun(TaskRun::query()->findOrFail($id));
        MarkdownContentWriterAgent::fake([$this->aiOutput($sources), $this->verificationOutput()])->preventStrayPrompts();
        $out = app(WorkerExecutionService::class)->executeTask($task->id, $context);
        $queue->completeJob($id, $task->id, null, 1, $out['meta'], $context, $context->executionLeaseToken());
        $this->assertNotSame($legacySignature, $topic->fresh()->last_maintenance_signature);
        $task = $tasks->save(['topic_config_version' => 1, 'status' => 'active', 'topic_settings' => ['rules' => 'Use a short introduction and audience guidance']], $actor, $task->fresh());
        $next = $queue->enqueueTaskJob($task->id);
        $queue->claimPendingJobById($next, 'rule-2');
        $context = app(AiExecutionContextFactory::class)->fromTaskRun(TaskRun::query()->findOrFail($next));
        MarkdownContentWriterAgent::fake([$this->aiOutput($sources), $this->verificationOutput()])->preventStrayPrompts();
        $out = app(WorkerExecutionService::class)->executeTask($task->id, $context);
        $queue->completeJob($next, $task->id, null, 1, $out['meta'], $context, $context->executionLeaseToken());
        $this->assertSame(2, TopicBuildRun::query()->count(), 'Changed maintenance instructions did not execute against unchanged sources');
        $task = $tasks->save(['topic_config_version' => 2, 'status' => 'active', 'topic_settings' => ['rules' => 'Use a short introduction and audience guidance']], $actor, $task->fresh());
        $this->executeTopicTask($task, $sources, false);
        $this->assertSame(2, TopicBuildRun::query()->count(), 'Saving unchanged effective settings retriggered maintenance');
    }

    public function test_review_20261001_task_update_existing_updates_its_own_topic(): void
    {
        Queue::fake();
        $actor = $this->actor();
        $sources = [$this->article('first'), $this->article('second')];
        $lib = TitleLibrary::query()->create(['name' => 'Existing titles']);
        Title::query()->create(['library_id' => $lib->id, 'title' => 'GEO Guide']);
        $task = app(TopicTaskService::class)->save(['name' => 'Update existing', 'target_site_key' => 'primary', 'title_library_id' => $lib->id, 'ai_model_id' => $this->model()->id, 'topic_limit' => 3, 'publish_interval' => 3600, 'status' => 'active', 'topic_settings' => ['after' => 'draft_only', 'update_existing' => true]], $actor);
        $topic = app(TopicService::class)->create('primary', json_decode($this->aiOutput($sources), true) + ['title' => 'GEO Guide', 'task_id' => $task->id, 'automatic_write' => true], $actor->id);
        $queue = app(JobQueueService::class);
        $id = $queue->enqueueTaskJob($task->id);
        $queue->claimPendingJobById($id, 'existing');
        $context = app(AiExecutionContextFactory::class)->fromTaskRun(TaskRun::query()->findOrFail($id));
        MarkdownContentWriterAgent::fake([$this->aiOutput($sources), $this->verificationOutput()])->preventStrayPrompts();
        app(WorkerExecutionService::class)->executeTask($task->id, $context);
        $this->assertSame('completed', TopicBuildRun::query()->sole()->status, 'API accepts update_existing=true but duplicates are always skipped');
    }

    public function test_documented_chinese_csv_headers_preserve_each_optional_field(): void
    {
        $category = Category::query()->create(['name' => '本地资料', 'slug' => 'local-materials']);
        $rows = app(TopicImportRows::class)->csv("适合人群,专题标题,文章分类,标签,模板,时效模式,来源覆盖期\n内容编辑,GEO Guide,本地资料,GEO,guide,evergreen,已核实文章范围\n");
        $normalized = app(TopicImportRows::class)->normalize($rows[0]);
        $this->assertSame(['category_ids' => [$category->id]], $normalized['filters']);
        $this->assertSame('guide', $normalized['payload']['template_key']);
        $this->assertSame('evergreen', $normalized['payload']['freshness']['mode']);
        $this->assertSame('内容编辑', $normalized['metadata']['audience']);
        $this->assertSame('已核实文章范围', $normalized['metadata']['sourcecoverage']);
    }

    public function test_invalid_batch_row_keeps_a_receipt_while_valid_row_executes(): void
    {
        Queue::fake();
        $service = app(TopicBatchService::class);
        $batch = $service->create($this->actor(), 'primary', [['title' => 'GEO Valid'], ['title' => 'GEO Invalid', 'metadata' => ['category' => '不存在的分类']]], ['mode' => 'draft'], (string) Str::uuid());
        $service->processNext($batch->id, 1);
        $this->assertSame('completed', $batch->fresh()->rows[0]['status']);
        $this->assertSame('failed', $batch->fresh()->rows[1]['status']);
        $this->assertStringContainsString('分类不存在', $batch->fresh()->rows[1]['error']);
        $this->assertSame(1, Topic::query()->count());
    }

    public function test_batch_preview_can_start_valid_rows_and_preserves_common_category_scope(): void
    {
        Queue::fake();
        $actor = $this->actor();
        $category = Category::query()->create(['name' => '指定文章', 'slug' => 'specific']);
        $payload = ['site' => 'primary', 'mode' => 'draft', 'template_key' => 'default', 'after' => 'draft_only', 'target_count' => 8, 'rules' => '保留统一规则', 'category_ids' => [$category->id], 'titles_text' => "GEO Valid\nGEO Other"];
        $this->actingAs($actor, 'admin')->post(route('admin.topics.batches.preview'), $payload)->assertOk()->assertViewHas('settings.category_ids', [$category->id]);
        $batch = app(TopicBatchService::class)->create($actor, 'primary', [['title' => 'GEO Good'], ['title' => 'GEO Bad', 'metadata' => ['category' => 'missing']]], ['mode' => 'draft', 'category_ids' => [$category->id]], (string) Str::uuid());
        $this->assertSame([$category->id], $batch->settings['category_ids']);
        $this->assertSame('pending', $batch->rows[0]['status']);
        $this->assertSame('failed', $batch->rows[1]['status']);
    }

    public function test_batch_transfer_preserves_input_and_creates_one_real_title_library_on_double_save(): void
    {
        Queue::fake();
        $actor = $this->actor();
        $model = $this->model();
        $category = Category::query()->create(['name' => '资料类', 'slug' => 'materials']);
        $this->actingAs($actor, 'admin');
        $payload = ['site' => 'primary', 'mode' => 'ai', 'model_id' => $model->id, 'template_key' => 'guide', 'after' => 'draft_only', 'target_count' => 4, 'rules' => '适合新读者', 'category_ids' => [$category->id], 'rows' => [['title' => 'GEO First', 'metadata' => ['tags' => 'GEO,阅读', 'template' => 'roundup']], ['title' => 'GEO Second'], ['title' => 'GEO Broken', 'metadata' => ['category' => '不存在']]], 'request_key' => (string) Str::uuid(), 'intent' => 'task'];
        $response = $this->post(route('admin.topics.batches.store'), $payload)->assertRedirect();
        $location = $response->headers->get('Location');
        parse_str(parse_url($location, PHP_URL_QUERY), $query);
        $this->assertSame('topic', $query['content_type']);
        $form = $this->get($location)->assertOk()->assertSee('2 个有效标题')->assertSee('适合新读者');
        $transfer = $form->viewData('batchTransfer');
        $this->assertSame(0, TitleLibrary::query()->count());
        $post = ['content_type' => 'topic', 'name' => '转入专题任务', 'target_site_key' => 'primary', 'title_library_id' => '', 'ai_model_id' => $model->id, 'topic_limit' => 2, 'interval_value' => 1, 'interval_unit' => 'hour', 'status' => 'paused', 'request_key' => (string) Str::uuid(), 'batch_snapshot_key' => $query['fromBatch'], 'topic_settings' => ['template_key' => 'guide', 'after' => 'draft_only', 'target_count' => 4, 'rules' => '适合新读者', 'category_ids' => [$category->id]]];
        $this->post(route('admin.tasks.store'), $post)->assertRedirect();
        $this->post(route('admin.tasks.store'), $post)->assertRedirect();
        $this->assertSame(1, Task::query()->count());
        $this->assertSame(1, TitleLibrary::query()->count());
        $task = Task::query()->sole();
        $this->assertSame('paused', $task->status);
        $this->assertSame('guide', $task->topic_settings['template_key']);
        $this->assertSame([$category->id], $task->topic_settings['category_ids']);
        $this->assertSame(['GEO First', 'GEO Second'], array_column(array_values($task->topic_settings['title_overrides']), 'topic_title'));
        $this->assertCount(2, Title::query()->get());
        $this->assertStringContainsString('范围', Title::query()->orderBy('id')->first()->title);
        $this->assertSame(0, (int) Title::query()->sum('used_count'));
        $this->assertSame('roundup', array_values($task->topic_settings['title_overrides'])[0]['template']);
        $this->assertSame(1, $transfer['ignored_count']);
        $this->post(route('admin.tasks.store'), array_replace($post, ['name' => 'Another task']))->assertStatus(409);
    }

    public function test_task_creation_request_key_deduplicates_plain_topic_form_without_a_batch(): void
    {
        Queue::fake();
        $this->actingAs($this->actor(), 'admin');
        $post = ['content_type' => 'topic', 'name' => '快速保存', 'target_site_key' => 'primary', 'topic_limit' => 3, 'interval_value' => 1, 'interval_unit' => 'hour', 'status' => 'paused', 'request_key' => (string) Str::uuid()];
        $this->post(route('admin.tasks.store'), $post)->assertRedirect();
        $this->post(route('admin.tasks.store'), $post)->assertRedirect();
        $this->assertSame(1, Task::query()->count());
    }

    public function test_title_overrides_reject_a_title_outside_the_selected_library(): void
    {
        $first = TitleLibrary::query()->create(['name' => '目标库']);
        $other = TitleLibrary::query()->create(['name' => '其他库']);
        $title = Title::query()->create(['library_id' => $other->id, 'title' => 'GEO Other']);
        try {
            app(TopicTaskService::class)->save(['name' => 'Scope check', 'target_site_key' => 'primary', 'title_library_id' => $first->id, 'topic_limit' => 1, 'publish_interval' => 3600, 'topic_settings' => ['title_overrides' => [(string) $title->id => ['template' => 'guide']]]], $this->actor());
            $this->fail('A foreign title override was accepted');
        } catch (ValidationException $error) {
            $this->assertArrayHasKey('topic_settings.title_overrides', $error->errors());
        }
        $this->assertSame(0, Task::query()->count());
    }

    public function test_update_existing_preserves_manual_topics_other_task_ownership_and_withdrawal(): void
    {
        Queue::fake();
        $actor = $this->actor();
        $sources = [$this->article('first'), $this->article('second')];
        foreach (['manual' => 'needs_adoption', 'other' => 'duplicate', 'withdrawn' => 'duplicate', 'paused' => 'duplicate'] as $kind => $expected) {
            $title = 'GEO '.$kind;
            $lib = TitleLibrary::query()->create(['name' => $kind]);
            Title::query()->create(['library_id' => $lib->id, 'title' => $title]);
            $task = app(TopicTaskService::class)->save(['name' => $kind, 'target_site_key' => 'primary', 'title_library_id' => $lib->id, 'ai_model_id' => $this->model()->id, 'topic_limit' => 3, 'publish_interval' => 3600, 'status' => 'active', 'topic_settings' => ['after' => 'draft_only', 'update_existing' => true]], $actor);
            $topic = app(TopicService::class)->create('primary', json_decode($this->aiOutput($sources), true) + ['title' => $title, 'task_id' => $kind === 'other' ? null : $task->id, 'automatic_write' => true], $actor->id);
            if ($kind === 'manual') {
                $topic->forceFill(['manual_edit_version' => 1])->save();
            } elseif ($kind === 'withdrawn') {
                $topic->forceFill(['withdrawn_at' => now(), 'maintenance_paused_at' => now()])->save();
            } elseif ($kind === 'paused') {
                $topic->forceFill(['maintenance_paused_at' => now()])->save();
            }
            $before = $topic->draft_payload;
            $this->executeTopicTask($task, $sources, $kind === 'manual');
            $run = TopicBuildRun::query()->where('task_id', $task->id)->sole();
            $this->assertSame($expected, $run->status, $kind);
            $this->assertSame($before, $topic->fresh()->draft_payload, $kind);
            $this->assertSame(0, (int) Title::query()->where('library_id', $lib->id)->sum('used_count'));
        }
    }

    public function test_switching_title_library_discards_old_row_bindings(): void
    {
        $first = TitleLibrary::query()->create(['name' => '旧库']);
        $other = TitleLibrary::query()->create(['name' => '新库']);
        $title = Title::query()->create(['library_id' => $first->id, 'title' => 'GEO Existing']);
        $service = app(TopicTaskService::class);
        $task = $service->save(['name' => '切换标题库', 'target_site_key' => 'primary', 'title_library_id' => $first->id, 'topic_limit' => 2, 'publish_interval' => 3600, 'topic_settings' => ['title_overrides' => [$title->id => ['tags' => 'GEO']]]], $this->actor());
        $task = $service->save(['topic_config_version' => 1, 'title_library_id' => $other->id], $this->actor(), $task);
        $this->assertSame([], $task->topic_settings['title_overrides']);
        $this->assertSame($other->id, $task->title_library_id);
    }

    public function test_saving_bound_task_keeps_user_maintenance_pause(): void
    {
        Queue::fake();
        $actor = $this->actor();
        $sources = [$this->article('first'), $this->article('second')];
        $topic = app(TopicService::class)->create('primary', json_decode($this->aiOutput($sources), true) + ['title' => 'GEO Guide'], $actor->id);
        $service = app(TopicTaskService::class);
        $task = $service->save(['name' => '保持暂停', 'target_site_key' => 'primary', 'topic_limit' => 2, 'publish_interval' => 3600, 'topic_settings' => ['bound_topic_ids' => [$topic->id]]], $actor);
        $topic->refresh()->forceFill(['maintenance_paused_at' => now(), 'next_maintenance_at' => null])->save();
        $service->save(['topic_config_version' => 1, 'topic_settings' => ['rules' => '新的整理规则']], $actor, $task);
        $this->assertNotNull($topic->fresh()->maintenance_paused_at);
        $this->assertNull($topic->fresh()->next_maintenance_at);
    }

    public function test_batches_use_declared_scope_and_repeated_scope_retains_one_topic(): void
    {
        Queue::fake();
        $category = Category::query()->create(['name' => '企业资料', 'slug' => 'enterprise']);
        $other = Category::query()->create(['name' => '个人资料', 'slug' => 'personal']);
        $service = app(TopicBatchService::class);
        $batch = $service->create($this->actor(), 'primary', [['title' => 'GEO Guide', 'metadata' => ['category' => (string) $category->id]], ['title' => 'GEO Guide', 'metadata' => ['category' => (string) $other->id]]], ['mode' => 'draft'], (string) Str::uuid());
        $service->processNext($batch->id, 1);
        $service->processNext($batch->id, 1);
        $this->assertSame(2, Topic::query()->count());
        $this->assertNotSame($batch->fresh()->rows[0]['topic_id'], $batch->fresh()->rows[1]['topic_id']);
        $repeat = $service->create($this->actor(), 'primary', [['title' => 'GEO Guide', 'metadata' => ['category' => (string) $category->id]]], ['mode' => 'draft'], (string) Str::uuid());
        $service->processNext($repeat->id, 1);
        $this->assertSame('duplicate', $repeat->fresh()->rows[0]['status']);
        $this->assertSame(2, Topic::query()->count());
        $preview = $this->actingAs($this->actor(), 'admin')->post(route('admin.topics.batches.preview'), ['site' => 'primary', 'mode' => 'draft', 'template_key' => 'default', 'after' => 'draft_only', 'target_count' => 8, 'category_ids' => [$category->id], 'titles_text' => 'GEO Guide'])->assertOk();
        $this->assertSame($repeat->fresh()->rows[0]['topic_id'], $preview->viewData('rows')[0]['existing_id']);
    }

    public function test_ai_scope_wording_cannot_change_declared_identity(): void
    {
        Queue::fake();
        $sources = [$this->article('first'), $this->article('second')];
        $category = $sources[0]->category_id;
        $run = $this->prepare(['filters' => ['category_ids' => [$category]]]);
        $output = json_decode($this->aiOutput($sources), true);
        $output['summary']['scope'] = 'AI 随机生成的说明';
        MarkdownContentWriterAgent::fake([json_encode($output, JSON_UNESCAPED_UNICODE), $this->verificationOutput()])->preventStrayPrompts();
        $result = app(TopicGenerationService::class)->process($run->id);
        $this->assertSame('completed', $result->status);
        $this->assertSame('来源分类 #'.$category, $result->result['summary']['scope']);
        $this->assertStringContainsString('AI 随机生成的说明', $result->result['summary']['reading_advice']);
        $repeat = $this->prepare(['filters' => ['category_ids' => [$category]]]);
        MarkdownContentWriterAgent::fake()->preventStrayPrompts();
        $duplicate = app(TopicGenerationService::class)->process($repeat->id);
        $this->assertSame('duplicate', $duplicate->status);
        $this->assertSame($result->topic_id, $duplicate->topic_id);
    }

    public function test_transfer_into_existing_library_only_executes_batch_titles_and_preserves_article_use_counts(): void
    {
        Queue::fake();
        $actor = $this->actor();
        $sources = [$this->article('first'), $this->article('second')];
        $library = TitleLibrary::query()->create(['name' => '已有库']);
        $old = Title::query()->create(['library_id' => $library->id, 'title' => 'GEO Older', 'used_count' => 7]);
        $imported = Title::query()->create(['library_id' => $library->id, 'title' => 'GEO Batch', 'used_count' => 5]);
        $this->actingAs($actor, 'admin');
        $response = $this->post(route('admin.topics.batches.store'), ['site' => 'primary', 'mode' => 'ai', 'model_id' => $this->model()->id, 'template_key' => 'guide', 'after' => 'draft_only', 'target_count' => 4, 'rows' => [['title' => 'GEO Batch', 'metadata' => ['template' => 'roundup', 'audience' => '资料读者', 'tags' => '固定标签']]], 'request_key' => (string) Str::uuid(), 'intent' => 'task'])->assertRedirect();
        parse_str(parse_url($response->headers->get('Location'), PHP_URL_QUERY), $query);
        $post = ['content_type' => 'topic', 'name' => '仅处理本批', 'target_site_key' => 'primary', 'title_library_id' => $library->id, 'ai_model_id' => $this->model()->id, 'topic_limit' => 1, 'interval_value' => 1, 'interval_unit' => 'hour', 'status' => 'active', 'request_key' => (string) Str::uuid(), 'batch_snapshot_key' => $query['fromBatch'], 'topic_settings' => ['template_key' => 'guide', 'after' => 'draft_only', 'target_count' => 4]];
        $this->post(route('admin.tasks.store'), $post)->assertRedirect();
        $task = Task::query()->sole();
        $this->assertCount(1, $task->topic_settings['title_ids']);
        $this->assertNotSame([$imported->id], $task->topic_settings['title_ids']);
        $this->executeTopicTask($task, $sources);
        $run = TopicBuildRun::query()->sole();
        $this->assertSame($task->topic_settings['title_ids'][0], $run->title_id);
        $this->assertSame('GEO Batch', $run->topic->title);
        $this->assertSame('completed', $run->status);
        $this->assertSame('roundup', $run->topic->draft_payload['template_key']);
        $this->assertSame(['固定标签'], $run->topic->draft_payload['tags']);
        $this->assertSame(7, $old->fresh()->used_count);
        $this->assertSame(5, $imported->fresh()->used_count);
        $this->assertFalse(app(TopicTaskService::class)->hasWork($task->fresh()));
        $newLibrary = TitleLibrary::query()->create(['name' => '换库']);
        $updated = app(TopicTaskService::class)->save(['topic_config_version' => 1, 'title_library_id' => $newLibrary->id, 'status' => 'paused'], $actor, $task->fresh());
        $this->assertArrayNotHasKey('title_ids', $updated->topic_settings);
    }

    public function test_plain_topic_task_still_selects_all_titles_in_its_library(): void
    {
        Queue::fake();
        $sources = [$this->article('first'), $this->article('second')];
        $library = TitleLibrary::query()->create(['name' => '普通标题库']);
        $first = Title::query()->create(['library_id' => $library->id, 'title' => 'GEO First', 'used_count' => 9]);
        Title::query()->create(['library_id' => $library->id, 'title' => 'GEO Second']);
        $task = app(TopicTaskService::class)->save(['name' => '普通专题任务', 'target_site_key' => 'primary', 'title_library_id' => $library->id, 'ai_model_id' => $this->model()->id, 'topic_limit' => 2, 'publish_interval' => 3600, 'status' => 'active', 'topic_settings' => ['after' => 'draft_only']], $this->actor());
        $this->assertArrayNotHasKey('title_ids', $task->topic_settings);
        $this->executeTopicTask($task, $sources);
        $this->assertSame($first->id, TopicBuildRun::query()->sole()->title_id);
        $this->assertTrue(app(TopicTaskService::class)->hasWork($task->fresh()));
        $this->assertSame(9, $first->fresh()->used_count);
    }

    public function test_batch_preview_with_invalid_optional_row_keeps_valid_action_enabled(): void
    {
        $file = UploadedFile::fake()->createWithContent('topics.csv', "标题,文章分类\nGEO Valid,\nGEO Broken,不存在\n");
        $response = $this->actingAs($this->actor(), 'admin')->post(route('admin.topics.batches.preview'), ['site' => 'primary', 'mode' => 'draft', 'template_key' => 'default', 'after' => 'draft_only', 'target_count' => 8, 'csv' => $file])->assertOk()->assertViewHas('executableCount', 1)->assertSee('有错误的行会保留失败回执');
        $this->assertStringNotContainsString('class="topic-button topic-primary" disabled', $response->getContent());
    }

    public function test_all_documented_csv_scope_headers_preserve_the_declared_range(): void
    {
        $parser = app(TopicImportRows::class);
        foreach (['适用范围与限制', '适用范围', '范围', 'scope', 'scope_note'] as $header) {
            $row = $parser->normalize($parser->csv("标题,$header\nGEO Guide,已核实本地资料\n")[0]);
            $this->assertSame('已核实本地资料', $row['metadata']['sourcecoverage']);
            $identity = app(TopicGenerationService::class)->identityPayload($row['payload']);
            $this->assertSame('来源范围：已核实本地资料', $identity['summary']['scope']);
        }
    }

    public function test_filter_date_scope_uses_the_same_semantics_as_candidate_search(): void
    {
        $source = $this->article('first');
        $source->update(['published_at' => '2026-10-01 09:00:00']);
        $service = app(TopicGenerationService::class);
        $a = ['filters' => ['after' => '2026-10-01T08:00:00+08:00']];
        $b = ['filters' => ['after' => '2026-10-01T00:00:00Z']];
        $this->assertSame($service->identityPayload($a), $service->identityPayload($b));
        $this->assertSame($service->candidates('primary', 'GEO Guide', $a['filters']), $service->candidates('primary', 'GEO Guide', $b['filters']));
        $date = ['filters' => ['before' => '2026-10-01']];
        $time = ['filters' => ['before' => '2026-10-01T00:00:00+08:00']];
        $this->assertNotSame($service->identityPayload($date), $service->identityPayload($time));
        $this->assertCount(1, $service->candidates('primary', 'GEO Guide', $date['filters']));
        $this->assertCount(0, $service->candidates('primary', 'GEO Guide', $time['filters']));
        $this->assertStringContainsString('2026-10-01', $service->identityPayload($date)['summary']['scope']);
    }

    public function test_batch_ai_retains_one_declared_scope_when_multiple_csv_fields_are_present(): void
    {
        Queue::fake();
        $sources = [$this->article('first'), $this->article('second')];
        $service = app(TopicBatchService::class);
        $row = ['title' => 'GEO Guide', 'metadata' => ['category' => (string) $sources[0]->category_id, 'audience' => '内容编辑', 'sourcecoverage' => '本地公开文章']];
        $batch = $service->create($this->actor(), 'primary', [$row], ['mode' => 'ai', 'model_id' => $this->model()->id, 'after' => 'draft_only'], (string) Str::uuid());
        MarkdownContentWriterAgent::fake([$this->aiOutput($sources), $this->verificationOutput()])->preventStrayPrompts();
        $service->processNext($batch->id, 1);
        $this->assertSame('completed', $batch->fresh()->rows[0]['status']);
        $run = TopicBuildRun::query()->sole();
        $this->assertSame($batch->rows[0]['payload']['summary']['scope'], $run->result['summary']['scope']);
        $repeat = $service->create($this->actor(), 'primary', [$row], ['mode' => 'ai', 'model_id' => $this->model()->id, 'after' => 'draft_only'], (string) Str::uuid());
        MarkdownContentWriterAgent::fake([])->preventStrayPrompts();
        $service->processNext($repeat->id, 1);
        $this->assertSame('duplicate', $repeat->fresh()->rows[0]['status']);
        $this->assertSame($run->topic_id, $repeat->fresh()->rows[0]['topic_id']);
    }

    public function test_existing_topic_batch_keeps_same_title_topics_with_distinct_declared_scopes(): void
    {
        Queue::fake();
        $service = app(TopicService::class);
        $first = $service->create('primary', ['title' => 'GEO Guide', 'summary' => ['scope' => '企业资料']], $this->actor()->id);
        $second = $service->create('primary', ['title' => 'GEO Guide', 'summary' => ['scope' => '个人资料']], $this->actor()->id);
        $batch = app(TopicBatchService::class)->create($this->actor(), 'primary', [['topic_id' => $first->id, 'expected_version' => 1], ['topic_id' => $second->id, 'expected_version' => 1]], ['mode' => 'existing', 'after' => 'draft_only', 'model_id' => $this->model()->id], (string) Str::uuid());
        $this->assertSame(['pending', 'pending'], array_column($batch->rows, 'status'));
    }

    public function test_oversized_combined_scope_is_an_individual_row_receipt(): void
    {
        Queue::fake();
        $batch = app(TopicBatchService::class)->create($this->actor(), 'primary', [['title' => 'GEO Good'], ['title' => 'GEO Long', 'metadata' => ['audience' => str_repeat('人', 500), 'sourcecoverage' => str_repeat('范', 2000)]]], ['mode' => 'draft'], (string) Str::uuid());
        app(TopicBatchService::class)->processNext($batch->id, 1);
        $this->assertSame('completed', $batch->fresh()->rows[0]['status']);
        $this->assertSame('failed', $batch->fresh()->rows[1]['status']);
        $this->assertStringContainsString('合计最多 2000 字', $batch->fresh()->rows[1]['error']);
    }

    public function test_same_title_different_scopes_transfer_to_two_real_title_bindings_and_worker_topics(): void
    {
        Queue::fake();
        $actor = $this->actor();
        $sources = [$this->article('first'), $this->article('second')];
        $library = TitleLibrary::query()->create(['name' => '保留原库']);
        $original = Title::query()->create(['library_id' => $library->id, 'title' => 'GEO Guide', 'used_count' => 7]);
        $this->actingAs($actor, 'admin');
        $batch = ['site' => 'primary', 'mode' => 'ai', 'model_id' => $this->model()->id, 'template_key' => 'guide', 'after' => 'draft_only', 'target_count' => 4, 'rows' => [['title' => 'GEO Guide', 'metadata' => ['sourcecoverage' => '企业读者资料']], ['title' => 'GEO Guide', 'metadata' => ['sourcecoverage' => '个人读者资料']]], 'request_key' => (string) Str::uuid(), 'intent' => 'task'];
        $response = $this->post(route('admin.topics.batches.store'), $batch)->assertRedirect();
        parse_str(parse_url($response->headers->get('Location'), PHP_URL_QUERY), $query);
        $form = $this->get($response->headers->get('Location'))->assertOk();
        $transfer = $form->viewData('batchTransfer');
        $post = ['content_type' => 'topic', 'name' => '同名不同范围', 'target_site_key' => 'primary', 'title_library_id' => $library->id, 'ai_model_id' => $this->model()->id, 'topic_limit' => 2, 'interval_value' => 1, 'interval_unit' => 'hour', 'status' => 'active', 'request_key' => (string) Str::uuid(), 'batch_snapshot_key' => $query['fromBatch'], 'topic_settings' => ['template_key' => 'guide', 'after' => 'draft_only', 'target_count' => 4]];
        $this->post(route('admin.tasks.store'), $post)->assertRedirect();
        $this->post(route('admin.tasks.store'), $post)->assertRedirect();
        $task = Task::query()->sole();
        $this->executeTopicTask($task, $sources);
        $this->executeTopicTask($task->fresh(), $sources);
        $this->assertSame(2, Topic::query()->count());
        $this->assertSame(2, count($transfer['rows']));
        $this->assertSame(0, $transfer['ignored_count']);
        $this->assertCount(2, $task->topic_settings['title_ids']);
        $this->assertSame(['GEO Guide', 'GEO Guide'], Topic::query()->orderBy('id')->pluck('title')->all());
        $this->assertCount(2, Topic::query()->pluck('normalized_title_key')->unique());
        $this->assertSame(['completed', 'completed'], TopicBuildRun::query()->orderBy('id')->pluck('status')->all());
        $this->assertCount(2, TopicBuildRun::query()->pluck('title_id')->unique());
        $this->assertSame('GEO Guide', $original->fresh()->title);
        $this->assertSame(7, $original->fresh()->used_count);
        foreach (Title::query()->whereIn('id', $task->topic_settings['title_ids'])->get() as $title) {
            $this->assertStringContainsString('资料', $title->title);
            $this->assertSame(0, $title->used_count);
        }
        $this->assertFalse(app(TopicTaskService::class)->hasWork($task->fresh()));
        $batch['rows'] = [['title' => 'geo guide', 'metadata' => ['sourcecoverage' => '企业读者资料']]];
        $batch['request_key'] = (string) Str::uuid();
        $response = $this->post(route('admin.topics.batches.store'), $batch)->assertRedirect();
        parse_str(parse_url($response->headers->get('Location'), PHP_URL_QUERY), $query);
        $post['batch_snapshot_key'] = $query['fromBatch'];
        $post['request_key'] = (string) Str::uuid();
        $post['name'] = '单范围再次导入';
        $post['status'] = 'paused';
        $this->post(route('admin.tasks.store'), $post)->assertRedirect();
        $next = Task::query()->latest('id')->first();
        $this->assertSame([TopicBuildRun::query()->orderBy('id')->first()->title_id], $next->topic_settings['title_ids']);
        $this->assertSame(3, Title::query()->where('library_id', $library->id)->count());
        $this->assertSame(7, $original->fresh()->used_count);
    }

    public function test_invalid_first_row_does_not_claim_the_valid_same_title_identity(): void
    {
        Queue::fake();
        $rows = [['title' => 'GEO Guide', 'metadata' => ['category' => '不存在']], ['title' => 'GEO Guide']];
        $service = app(TopicBatchService::class);
        $batch = $service->create($this->actor(), 'primary', $rows, ['mode' => 'draft'], (string) Str::uuid());
        $service->processNext($batch->id, 1);
        $this->assertSame('failed', $batch->fresh()->rows[0]['status']);
        $this->assertSame('completed', $batch->fresh()->rows[1]['status']);
        $this->assertSame(1, Topic::query()->count());
        $scopeBatch = $service->create($this->actor(), 'primary', [['title' => 'GEO Scope', 'metadata' => ['audience' => str_repeat('人', 500), 'sourcecoverage' => str_repeat('范', 2000)]], ['title' => 'GEO Scope']], ['mode' => 'draft'], (string) Str::uuid());
        $service->processNext($scopeBatch->id, 1);
        $this->assertSame('failed', $scopeBatch->fresh()->rows[0]['status']);
        $this->assertSame('completed', $scopeBatch->fresh()->rows[1]['status']);
        $this->assertSame(2, Topic::query()->count());
        $file = UploadedFile::fake()->createWithContent('topics.csv', "标题,文章分类\nGEO Guide,不存在\nGEO Guide,\n");
        $this->actingAs($this->actor(), 'admin')->post(route('admin.topics.batches.preview'), ['site' => 'primary', 'mode' => 'draft', 'template_key' => 'default', 'after' => 'draft_only', 'target_count' => 8, 'csv' => $file])->assertOk()->assertViewHas('executableCount', 1);
    }

    public function test_transfer_can_save_paused_annual_settings_before_period_is_completed(): void
    {
        Queue::fake();
        $this->actingAs($this->actor(), 'admin');
        $response = $this->post(route('admin.topics.batches.store'), ['site' => 'primary', 'mode' => 'draft', 'template_key' => 'default', 'after' => 'draft_only', 'target_count' => 4, 'rows' => [['title' => 'GEO Guide']], 'request_key' => (string) Str::uuid(), 'intent' => 'task'])->assertRedirect();
        parse_str(parse_url($response->headers->get('Location'), PHP_URL_QUERY), $query);
        $post = ['content_type' => 'topic', 'name' => '稍后补充年度', 'target_site_key' => 'primary', 'topic_limit' => 1, 'interval_value' => 1, 'interval_unit' => 'hour', 'status' => 'paused', 'request_key' => (string) Str::uuid(), 'batch_snapshot_key' => $query['fromBatch'], 'topic_settings' => ['freshness' => ['mode' => 'annual']]];
        $this->post(route('admin.tasks.store'), $post)->assertRedirect();
        $this->assertSame('paused', Task::query()->sole()->status);
        $this->assertSame('annual', Task::query()->sole()->topic_settings['freshness']['mode']);
        $this->assertNull(Task::query()->sole()->topic_settings['freshness']['year']);
        $this->assertStringContainsString('待补充', Title::query()->sole()->title);
        $this->assertSame(0, TopicBuildRun::query()->count());
    }

    private function executeTopicTask(Task $task, array $sources, bool $expectAi = true): array
    {
        $queue = app(JobQueueService::class);
        $id = $queue->enqueueTaskJob($task->id);
        $queue->claimPendingJobById($id, 'integration-review');
        $context = app(AiExecutionContextFactory::class)->fromTaskRun(TaskRun::query()->findOrFail($id));
        MarkdownContentWriterAgent::fake($expectAi ? [$this->aiOutput($sources), $this->verificationOutput()] : [])->preventStrayPrompts();
        $result = app(WorkerExecutionService::class)->executeTask($task->id, $context);
        $queue->completeJob($id, $task->id, null, 1, $result['meta'], $context, $context->executionLeaseToken());

        return $result;
    }

    private function actor(): Admin
    {
        return Admin::query()->firstOrCreate(['username' => 'topic_engine_admin'], ['password' => 'password', 'email' => 'topic-engine@example.test', 'role' => 'super_admin', 'status' => 'active']);
    }

    private function model(): AiModel
    {
        $model = AiModel::query()->firstOrNew(['name' => 'Topic test model']);
        $model->fill(['version' => 'test', 'api_key' => app(ApiKeyCrypto::class)->encrypt('test-key'), 'model_id' => 'test-chat-model', 'model_type' => 'chat', 'api_url' => 'https://ai.test', 'daily_limit' => 100, 'status' => 'active']);
        $model->forceFill(['owner_admin_id' => $this->actor()->id, 'access_scope' => AiModel::ACCESS_SCOPE_USER_CONTENT])->save();

        return $model;
    }

    private function prepare(array $input = [], ?Topic $topic = null): TopicBuildRun
    {
        return app(TopicGenerationService::class)->prepare($this->actor(), 'primary', array_replace(['title' => 'GEO Guide', 'model_id' => $this->model()->id, 'after' => 'draft_only'], $input), (string) Str::uuid(), $topic);
    }

    private function article(string $key): Article
    {
        $category = Category::query()->firstOrCreate(['slug' => 'geo'], ['name' => 'GEO']);
        $author = Author::query()->firstOrCreate(['email' => 'geo@example.test'], ['name' => 'GEO Editor']);

        return Article::query()->create(['title' => 'GEO '.$key, 'slug' => 'geo-'.$key, 'content' => 'GEO sourced content for '.$key, 'excerpt' => 'GEO facts for '.$key, 'status' => 'published', 'review_status' => 'approved', 'category_id' => $category->id, 'author_id' => $author->id, 'published_at' => now()]);
    }

    private function verificationOutput(): string
    {
        return json_encode(['supported' => true, 'unsupported_fields' => []], JSON_THROW_ON_ERROR);
    }

    private function aiOutput(array $sources): string
    {
        return json_encode(['intro' => 'GEO 导读', 'summary' => ['one_sentence' => 'GEO Summary', 'facts' => [['text' => 'GEO fact', 'article_ids' => [$sources[0]->id], 'evidence' => [$this->sourceEvidence($sources[0])]]]], 'tags' => ['GEO'], 'articles' => array_map(fn (Article $a): array => ['article_id' => $a->id, 'group' => '阅读', 'reason' => 'GEO 来源'], $sources)], JSON_UNESCAPED_UNICODE);
    }

    private function sourceEvidence(Article $article): array
    {
        $text = mb_substr((string) $article->content, 0, 1800, 'UTF-8');

        return ['article_id' => (int) $article->id, 'field' => 'content', 'start' => 0, 'end' => mb_strlen($text, 'UTF-8'), 'text' => $text, 'sha256' => hash('sha256', $text)];
    }
}
