<?php

namespace Tests\Feature\Topics;

use App\Ai\Agents\MarkdownContentWriterAgent;
use App\Exceptions\AiModelAccessException;
use App\Exceptions\ApiException;
use App\Jobs\ProcessGeoFlowTaskJob;
use App\Jobs\ProcessTopicBuildJob;
use App\Models\Admin;
use App\Models\AiModel;
use App\Models\Article;
use App\Models\Author;
use App\Models\Category;
use App\Models\DistributionChannel;
use App\Models\HostedSiteProfile;
use App\Models\SiteSetting;
use App\Models\Task;
use App\Models\TaskRun;
use App\Models\Title;
use App\Models\TitleLibrary;
use App\Models\Topic;
use App\Models\TopicBuildRun;
use App\Services\AiWorkspace\AiModelInvocationLock;
use App\Services\GeoFlow\AiExecutionContextFactory;
use App\Services\GeoFlow\JobQueueService;
use App\Services\GeoFlow\TaskLifecycleService;
use App\Services\GeoFlow\TaskMonitoringQueryService;
use App\Services\GeoFlow\WorkerExecutionService;
use App\Services\Topics\TopicAiComposer;
use App\Services\Topics\TopicBatchService;
use App\Services\Topics\TopicGenerationService;
use App\Services\Topics\TopicService;
use App\Services\Topics\TopicTaskProgress;
use App\Services\Topics\TopicTaskService;
use App\Support\GeoFlow\ApiKeyCrypto;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Laravel\Ai\Events\PromptingAgent;
use Tests\TestCase;

class TopicExecutionTest extends TestCase
{
    use RefreshDatabase;

    public function test_missing_content_retains_a_linked_editable_draft_without_calling_ai(): void
    {
        MarkdownContentWriterAgent::fake([])->preventStrayPrompts();
        $run = $this->prepare();
        $result = app(TopicGenerationService::class)->process($run->id);
        $this->assertSame('waiting_content', $result->status);
        $this->assertNotNull($result->topic_id);
        $this->assertSame('', $result->topic->draft_payload['intro']);
        $this->assertNull($result->topic->public_revision_id);
        $this->assertNull($result->result);
    }

    public function test_generation_publishes_once_and_repeated_delivery_finds_the_original_result(): void
    {
        $sources = [$this->article('first'), $this->article('second')];
        MarkdownContentWriterAgent::fake([$this->aiOutput($sources), $this->verificationOutput()])->preventStrayPrompts();
        $run = $this->prepare(['after' => 'auto_publish']);
        $service = app(TopicGenerationService::class);
        $first = $service->process($run->id);
        $second = $service->process($run->id);
        $this->assertSame('completed', $first->status);
        $this->assertSame($first->topic_id, $second->topic_id);
        $this->assertSame(1, Topic::query()->count());
        $this->assertSame(1, $first->topic->revisions()->count());
        $this->assertCount(2, array_filter($first->telemetry, fn ($event) => isset($event['status'])));
    }

    public function test_format_repair_is_bounded_and_uses_the_same_sources(): void
    {
        $sources = [$this->article('first'), $this->article('second')];
        MarkdownContentWriterAgent::fake(['invalid json', $this->aiOutput($sources), $this->verificationOutput()])->preventStrayPrompts();
        $result = app(TopicGenerationService::class)->process($this->prepare()->id);
        $this->assertSame('completed', $result->status);
        $attempts = array_values(array_filter($result->telemetry, fn ($event) => isset($event['status'])));
        $this->assertCount(3, $attempts);
        $this->assertSame('invalid_output', $attempts[0]['status']);
        $this->assertSame('completed', $attempts[1]['status']);
    }

    public function test_human_changes_during_ai_call_are_preserved_as_a_separate_suggestion(): void
    {
        $sources = [$this->article('first'), $this->article('second')];
        $topic = app(TopicService::class)->create('primary', ['title' => 'GEO Guide']);
        $run = $this->prepare([], $topic);
        MarkdownContentWriterAgent::fake(function (string $prompt) use ($topic, $sources): string {
            if (str_contains($prompt, '"phase":"verify"')) {
                return $this->verificationOutput();
            }
            app(TopicService::class)->save($topic, ['intro' => 'My own introduction'], 1);

            return $this->aiOutput($sources);
        })->preventStrayPrompts();
        $result = app(TopicGenerationService::class)->process($run->id);
        $this->assertSame('needs_adoption', $result->status);
        $this->assertSame('My own introduction', $topic->fresh()->draft_payload['intro']);
        $this->assertNull($topic->fresh()->public_revision_id);
    }

    public function test_source_withdrawal_during_ai_prevents_commit(): void
    {
        $sources = [$this->article('first'), $this->article('second')];
        MarkdownContentWriterAgent::fake(function (string $prompt) use ($sources): string {
            if (str_contains($prompt, '"phase":"verify"')) {
                return $this->verificationOutput();
            }
            $sources[0]->update(['status' => 'private']);

            return $this->aiOutput($sources);
        })->preventStrayPrompts();
        $result = app(TopicGenerationService::class)->process($this->prepare(['after' => 'auto_publish'])->id);
        $this->assertSame('waiting_content', $result->status);
        $this->assertNull($result->topic->public_revision_id);
    }

    public function test_request_key_cannot_cross_owner_or_input_boundaries(): void
    {
        $service = app(TopicGenerationService::class);
        $run = $this->prepare();
        $this->expectException(ValidationException::class);
        $service->prepare($this->actor(), 'primary', ['title' => 'Different input'], $run->request_key);
    }

    public function test_cancelled_batch_keeps_successful_rows_and_resumes_only_unfinished_rows(): void
    {
        Queue::fake();
        $service = app(TopicBatchService::class);
        $batch = $service->create($this->actor(), 'primary', ['GEO First', 'GEO Second', 'GEO First'], ['mode' => 'draft'], (string) Str::uuid());
        $service->processNext($batch->id, 1);
        $this->assertSame(1, Topic::query()->count());
        $service->cancel($batch);
        $service->processNext($batch->id, 1);
        $this->assertSame(1, Topic::query()->count());
        $service->retry($batch->fresh(), true);
        $service->processNext($batch->id, $batch->fresh()->generation);
        $rows = $batch->fresh()->rows;
        $this->assertSame(2, Topic::query()->count());
        $this->assertSame($rows[0]['topic_id'], $rows[2]['topic_id']);
        $this->assertSame('duplicate', $rows[2]['status']);
    }

    public function test_topic_task_can_save_missing_configuration_as_paused(): void
    {
        $task = app(TopicTaskService::class)->save(['name' => '待配置专题任务', 'target_site_key' => 'primary', 'topic_limit' => 10, 'publish_interval' => 3600], $this->actor());
        $this->assertSame('topic', $task->content_type);
        $this->assertSame('paused', $task->status);
        $this->assertNull($task->title_library_id);
        $this->assertNull($task->ai_model_id);
        $this->assertSame(0, $task->created_count);
    }

    public function test_topic_task_routes_through_the_existing_queue_with_topic_result_counts(): void
    {
        Queue::fake();
        $sources = [$this->article('first'), $this->article('second')];
        MarkdownContentWriterAgent::fake([$this->aiOutput($sources), $this->verificationOutput()])->preventStrayPrompts();
        $library = TitleLibrary::query()->create(['name' => '专题标题']);
        Title::query()->create(['library_id' => $library->id, 'title' => 'GEO Guide', 'used_count' => 0]);
        $actor = $this->actor();
        $task = app(TopicTaskService::class)->save(['name' => '专题自动发布', 'target_site_key' => 'primary', 'title_library_id' => $library->id, 'ai_model_id' => $this->model()->id, 'topic_limit' => 3, 'publish_interval' => 3600, 'status' => 'active'], $actor);
        $queue = app(JobQueueService::class);
        $id = $queue->enqueueTaskJob($task->id);
        $claimed = $queue->claimPendingJobById($id, 'topic-test-worker');
        $run = TaskRun::query()->findOrFail($id);
        $context = app(AiExecutionContextFactory::class)->fromTaskRun($run);
        $result = app(WorkerExecutionService::class)->executeTask($task->id, $context);
        $this->assertNotNull($result['topic_id']);
        $this->assertNull($result['article_id']);
        $this->assertSame('generate_topic', $run->meta['job_type']);
        $this->assertSame(1, $task->fresh()->created_count);
        $this->assertSame(1, $task->fresh()->published_count);
        $this->assertSame(2, Article::query()->count());
    }

    public function test_manual_task_generation_retry_uses_a_new_execution_and_keeps_the_original_topic(): void
    {
        Queue::fake();
        MarkdownContentWriterAgent::fake([])->preventStrayPrompts();
        $actor = $this->actor();
        $library = TitleLibrary::query()->create(['name' => 'Retry topic titles']);
        Title::query()->create(['library_id' => $library->id, 'title' => 'GEO Guide']);
        Title::query()->create(['library_id' => $library->id, 'title' => 'GEO Other Guide']);
        $task = app(TopicTaskService::class)->save(['name' => 'Retry original topic', 'target_site_key' => 'primary', 'title_library_id' => $library->id, 'ai_model_id' => $this->model()->id, 'topic_limit' => 2, 'publish_interval' => 3600, 'status' => 'active'], $actor);
        $queue = app(JobQueueService::class);
        $firstId = $queue->enqueueTaskJob($task->id);
        $queue->claimPendingJobById($firstId, 'first-topic-worker');
        $context = app(AiExecutionContextFactory::class)->fromTaskRun(TaskRun::query()->findOrFail($firstId));
        $output = app(WorkerExecutionService::class)->executeTask($task->id, $context);
        $queue->completeJob($firstId, $task->id, null, 1, $output['meta'], $context, $context->executionLeaseToken());
        $build = TopicBuildRun::query()->sole();
        $topicId = $build->topic_id;
        $oldDispatchKey = $build->dispatch_key;
        $this->assertSame('waiting_content', $build->status);
        $this->assertSame('completed', TaskRun::query()->findOrFail($firstId)->status);
        $sources = [$this->article('retry-first'), $this->article('retry-second')];
        Queue::fake();
        $this->actingAs($actor, 'admin')->post(route('admin.topics.runs.action', ['run' => $build->id, 'action' => 'retry']))->assertRedirect();
        $this->post(route('admin.topics.runs.action', ['run' => $build->id, 'action' => 'retry']))->assertRedirect();
        $next = TaskRun::query()->where('task_id', $task->id)->where('status', 'pending')->sole();
        $this->assertNotSame($firstId, $next->id);
        Queue::assertPushed(ProcessGeoFlowTaskJob::class, 1);
        Queue::assertNotPushed(ProcessTopicBuildJob::class);
        $this->assertTrue(app(TopicTaskService::class)->hasWork($task->fresh()));
        app(TopicGenerationService::class)->process($build->id, $oldDispatchKey);
        $this->assertSame('pending', $build->fresh()->status);
        $queue->claimPendingJobById($next->id, 'replacement-topic-worker');
        $newContext = app(AiExecutionContextFactory::class)->fromTaskRun($next->fresh());
        MarkdownContentWriterAgent::fake([$this->aiOutput($sources), $this->verificationOutput()])->preventStrayPrompts();
        app(WorkerExecutionService::class)->executeTask($task->id, $newContext);
        $this->assertSame('completed', $build->fresh()->status);
        $this->assertSame($next->id, $build->fresh()->task_run_id);
        $this->assertSame($newContext->executionLeaseToken(), $build->fresh()->expected_execution_lease_token);
        $this->assertSame($topicId, $build->fresh()->topic_id);
        $this->assertSame(1, Topic::query()->count());
        $this->assertSame(1, TopicBuildRun::query()->count());
        $this->assertSame(1, $task->fresh()->created_count);
        $this->assertSame(1, $task->fresh()->published_count);
    }

    public function test_task_retry_waits_for_the_current_execution_without_dispatching_a_standalone_build(): void
    {
        Queue::fake();
        $actor = $this->actor();
        $library = TitleLibrary::query()->create(['name' => 'Serial retry titles']);
        Title::query()->create(['library_id' => $library->id, 'title' => 'GEO Guide']);
        $task = app(TopicTaskService::class)->save(['name' => 'Serial topic retry', 'target_site_key' => 'primary', 'title_library_id' => $library->id, 'ai_model_id' => $this->model()->id, 'topic_limit' => 1, 'publish_interval' => 60, 'status' => 'active'], $actor);
        $generation = app(TopicGenerationService::class);
        $build = $generation->prepare($actor, 'primary', ['title' => 'GEO Guide', 'title_id' => $library->titles()->sole()->id, 'model_id' => $task->ai_model_id, 'after' => 'auto_publish', 'defer_publication' => true], (string) Str::uuid(), null, $task);
        $build->update(['status' => 'failed', 'phase' => 'finished']);
        $queue = app(JobQueueService::class);
        $busyId = $queue->enqueueTaskJob($task->id);
        $queue->claimPendingJobById($busyId, 'busy-topic-worker');
        $busyContext = app(AiExecutionContextFactory::class)->fromTaskRun(TaskRun::query()->findOrFail($busyId));
        Queue::fake();
        $this->actingAs($actor, 'admin')->post(route('admin.topics.runs.action', ['run' => $build->id, 'action' => 'retry']))->assertRedirect();
        $this->assertSame('pending', $build->fresh()->status);
        Queue::assertNothingPushed();
        $this->assertSame(1, TaskRun::query()->where('task_id', $task->id)->whereIn('status', ['running', 'pending'])->count());
        $queue->completeJob($busyId, $task->id, null, 1, ['content_type' => 'topic', 'action' => 'noop'], $busyContext, $busyContext->executionLeaseToken());
        $sources = [$this->article('serial-first'), $this->article('serial-second')];
        MarkdownContentWriterAgent::fake([$this->aiOutput($sources), $this->verificationOutput()])->preventStrayPrompts();
        $this->artisan('geoflow:schedule-tasks')->assertSuccessful();
        $next = TaskRun::query()->where('task_id', $task->id)->where('status', 'pending')->sole();
        Queue::assertPushed(ProcessGeoFlowTaskJob::class, 1);
        Queue::assertNotPushed(ProcessTopicBuildJob::class);
        (new ProcessGeoFlowTaskJob($next->id))->handle($queue, app(WorkerExecutionService::class), app(AiExecutionContextFactory::class));
        $this->assertSame('completed', $next->fresh()->status);
        $this->assertSame('completed', $build->fresh()->status);
        $this->assertSame($next->id, $build->fresh()->task_run_id);
        $this->assertNotNull($build->fresh()->topic->public_revision_id);
        $this->assertFalse(app(TopicTaskService::class)->hasWork($task->fresh()));
        $build->refresh()->update(['status' => 'failed']);
        $task = app(TopicTaskService::class)->save(['status' => 'paused', 'topic_config_version' => $task->fresh()->topic_config_version], $actor, $task->fresh());
        $this->actingAs($actor, 'admin')->post(route('admin.topics.runs.action', ['run' => $build->id, 'action' => 'retry']))->assertSessionHasErrors('run');
        $this->assertSame('failed', $build->fresh()->status);
        $this->assertSame('paused', $task->status);
    }

    public function test_configuration_changes_cancel_a_queued_retry_and_rebuild_with_current_rules(): void
    {
        Queue::fake();
        $actor = $this->actor();
        $library = TitleLibrary::query()->create(['name' => 'Configuration retry titles']);
        $title = Title::query()->create(['library_id' => $library->id, 'title' => 'GEO Guide']);
        $tasks = app(TopicTaskService::class);
        $task = $tasks->save(['name' => 'Current retry rules', 'target_site_key' => 'primary', 'title_library_id' => $library->id, 'ai_model_id' => $this->model()->id, 'topic_limit' => 1, 'publish_interval' => 60, 'status' => 'active'], $actor);
        $generation = app(TopicGenerationService::class);
        $build = $generation->prepare($actor, 'primary', ['title' => $title->title, 'title_id' => $title->id, 'model_id' => $task->ai_model_id, 'after' => 'auto_publish', 'defer_publication' => true], (string) Str::uuid(), null, $task);
        $build->update(['status' => 'failed', 'phase' => 'finished']);
        $this->actingAs($actor, 'admin')->post(route('admin.topics.runs.action', ['run' => $build->id, 'action' => 'retry']))->assertRedirect();
        $this->assertSame('pending', $build->fresh()->status);
        $task = $tasks->save(['topic_config_version' => $task->topic_config_version, 'topic_settings' => ['rules' => 'Use current configuration wording.'], 'status' => 'active'], $actor, $task);
        $this->assertSame('cancelled', $build->fresh()->status);
        $sources = [$this->article('current-first'), $this->article('current-second')];
        MarkdownContentWriterAgent::fake([$this->aiOutput($sources), $this->verificationOutput()])->preventStrayPrompts();
        $next = TaskRun::query()->where('task_id', $task->id)->where('status', 'pending')->sole();
        (new ProcessGeoFlowTaskJob($next->id))->handle(app(JobQueueService::class), app(WorkerExecutionService::class), app(AiExecutionContextFactory::class));
        $this->assertSame('completed', $build->fresh()->status);
        $this->assertSame($task->topic_config_version, $build->fresh()->config_version);
        $this->assertSame('Use current configuration wording.', $build->fresh()->input['rules']);
        $this->assertArrayNotHasKey('retry_requested', $build->fresh()->input);
        $this->assertSame(1, TopicBuildRun::query()->count());
    }

    public function test_scheduler_automatically_enqueues_a_due_topic_task_once(): void
    {
        $this->freezeTime();
        Queue::fake([ProcessGeoFlowTaskJob::class]);
        $library = TitleLibrary::query()->create(['name' => 'Scheduled topic titles']);
        Title::query()->create(['library_id' => $library->id, 'title' => 'GEO Guide', 'used_count' => 0]);
        $task = app(TopicTaskService::class)->save(['name' => 'Scheduled topic', 'target_site_key' => 'primary', 'title_library_id' => $library->id, 'ai_model_id' => $this->model()->id, 'topic_limit' => 10, 'publish_interval' => 60, 'status' => 'active'], $this->actor());

        $this->artisan('geoflow:schedule-tasks')->assertSuccessful();

        $run = TaskRun::query()->where('task_id', $task->id)->sole();
        $this->assertSame('topic', $run->content_type);
        $this->assertSame('pending', $run->status);
        $this->assertSame('generate_topic', $run->meta['job_type']);
        $this->assertSame(now()->addMinute()->toDateTimeString(), $task->fresh()->next_run_at->toDateTimeString());
        Queue::assertPushed(ProcessGeoFlowTaskJob::class, fn ($job) => $job->taskRunId === $run->id);

        $this->artisan('geoflow:schedule-tasks')->assertSuccessful();

        $this->assertSame(1, TaskRun::query()->where('task_id', $task->id)->count());
        Queue::assertPushed(ProcessGeoFlowTaskJob::class, 1);
    }

    public function test_task_edit_preserves_progress_and_rejects_stale_configuration(): void
    {
        $service = app(TopicTaskService::class);
        $actor = $this->actor();
        $task = $service->save(['name' => '专题任务', 'target_site_key' => 'primary', 'topic_limit' => 10, 'publish_interval' => 3600], $actor);
        $task->update(['created_count' => 3]);
        $saved = $service->save(['name' => '新名称', 'topic_config_version' => 1], $actor, $task);
        $this->assertSame(3, $saved->created_count);
        $this->assertSame(2, $saved->topic_config_version);
        $this->expectException(ValidationException::class);
        $service->save(['name' => '过期内容', 'topic_config_version' => 1], $actor, $task);
    }

    public function test_review_withdraw_during_generation_cannot_republish(): void
    {
        $sources = [$this->article('first'), $this->article('second')];
        $topics = app(TopicService::class);
        $topic = $topics->create('primary', ['title' => 'GEO Guide', 'intro' => 'GEO introduction', 'articles' => array_map(fn ($a) => ['article_id' => $a->id], $sources)]);
        $topics->publish($topic, 1, $this->actor()->id);
        $run = $this->prepare(['after' => 'auto_publish'], $topic->fresh());
        MarkdownContentWriterAgent::fake(function (string $prompt) use ($topic, $sources, $topics): string {
            if (str_contains($prompt, '"phase":"verify"')) {
                return $this->verificationOutput();
            }
            $topics->withdraw($topic, $this->actor()->id);

            return $this->aiOutput($sources);
        })->preventStrayPrompts();
        $result = app(TopicGenerationService::class)->process($run->id);
        $this->assertNull($topic->fresh()->public_revision_id, 'Old AI run republished a withdrawn topic: '.$result->status);
    }

    public function test_review_batch_retry_regenerates_after_source_changed(): void
    {
        Queue::fake();
        $sources = [$this->article('first'), $this->article('second')];
        MarkdownContentWriterAgent::fake(function (string $prompt) use ($sources): string {
            if (str_contains($prompt, '"phase":"verify"')) {
                return $this->verificationOutput();
            }
            $sources[0]->update(['content' => 'GEO changed sourced content']);

            return $this->aiOutput($sources);
        })->preventStrayPrompts();
        $service = app(TopicBatchService::class);
        $batch = $service->create($this->actor(), 'primary', ['GEO Guide'], ['mode' => 'ai', 'model_id' => $this->model()->id, 'after' => 'draft_only'], (string) Str::uuid());
        $service->processNext($batch->id, 1);
        $this->assertSame('waiting_content', $batch->fresh()->rows[0]['status']);
        MarkdownContentWriterAgent::fake([$this->aiOutput($sources), $this->verificationOutput()])->preventStrayPrompts();
        $service->processNext($batch->id, $batch->fresh()->generation);
        $service->retry($batch->fresh());
        $service->processNext($batch->id, $batch->fresh()->generation);
        $this->assertSame('completed', $batch->fresh()->rows[0]['status']);
    }

    public function test_review_publication_retry_preserves_manual_draft(): void
    {
        Queue::fake();
        $sources = [$this->article('first'), $this->article('second')];
        MarkdownContentWriterAgent::fake([$this->aiOutput($sources), $this->verificationOutput()])->preventStrayPrompts();
        $generation = app(TopicGenerationService::class);
        $run = $generation->process($this->prepare()->id);
        $input = $run->input;
        $input['after'] = 'auto_publish';
        $run->update(['status' => 'publication_failed', 'phase' => 'publishing', 'input' => $input]);
        $topic = app(TopicService::class)->save($run->topic, ['intro' => 'Human edited introduction'], $run->expected_version);
        $generation->retry($run->fresh());
        $result = $generation->process($run->id);
        $this->assertSame('Human edited introduction', $topic->fresh()->draft_payload['intro'], 'Publication retry overwrote human draft: '.$result->status);
    }

    public function test_review_reclaimed_task_run_cannot_create_topic(): void
    {
        Queue::fake();
        $sources = [$this->article('first'), $this->article('second')];
        $library = TitleLibrary::query()->create(['name' => 'Topic titles']);
        Title::query()->create(['library_id' => $library->id, 'title' => 'GEO Guide']);
        $task = app(TopicTaskService::class)->save(['name' => 'Topic lease test', 'target_site_key' => 'primary', 'title_library_id' => $library->id, 'ai_model_id' => $this->model()->id, 'topic_limit' => 3, 'publish_interval' => 3600, 'status' => 'active'], $this->actor());
        $queue = app(JobQueueService::class);
        $id = $queue->enqueueTaskJob($task->id);
        $queue->claimPendingJobById($id, 'topic-review-worker');
        $context = app(AiExecutionContextFactory::class)->fromTaskRun(TaskRun::query()->findOrFail($id));
        MarkdownContentWriterAgent::fake(function (string $prompt) use ($sources, $id): string {
            if (str_contains($prompt, '"phase":"verify"')) {
                return $this->verificationOutput();
            }
            TaskRun::query()->whereKey($id)->update(['execution_lease_token' => (string) Str::uuid()]);

            return $this->aiOutput($sources);
        })->preventStrayPrompts();
        try {
            app(WorkerExecutionService::class)->executeTask($task->id, $context);
        } catch (AiModelAccessException) {
        }
        $this->assertSame(0, Topic::query()->count(), 'Old TaskRun lease wrote a topic');
    }

    public function test_review_cancelled_task_title_is_available_after_restart(): void
    {
        Queue::fake();
        $sources = [$this->article('first'), $this->article('second')];
        $library = TitleLibrary::query()->create(['name' => 'Topic titles']);
        Title::query()->create(['library_id' => $library->id, 'title' => 'GEO Guide']);
        $taskService = app(TopicTaskService::class);
        $task = $taskService->save(['name' => 'Topic pause test', 'target_site_key' => 'primary', 'title_library_id' => $library->id, 'ai_model_id' => $this->model()->id, 'topic_limit' => 3, 'publish_interval' => 3600, 'status' => 'active'], $this->actor());
        $queue = app(JobQueueService::class);
        $id = $queue->enqueueTaskJob($task->id);
        $queue->claimPendingJobById($id, 'topic-review-worker');
        $context = app(AiExecutionContextFactory::class)->fromTaskRun(TaskRun::query()->findOrFail($id));
        MarkdownContentWriterAgent::fake(function (string $prompt) use ($sources, $taskService, $task): string {
            if (str_contains($prompt, '"phase":"verify"')) {
                return $this->verificationOutput();
            }
            $taskService->save(['status' => 'paused', 'topic_config_version' => 1], $this->actor(), $task);

            return $this->aiOutput($sources);
        })->preventStrayPrompts();
        try {
            app(WorkerExecutionService::class)->executeTask($task->id, $context);
        } catch (AiModelAccessException) {
        }
        $this->assertSame('cancelled', TopicBuildRun::query()->first()->status);
        $task = $taskService->start($task->fresh(), $this->actor(), false);
        $this->assertTrue($taskService->hasWork($task), 'Cancelled unfinished title is permanently excluded');
    }

    public function test_review_cancel_after_completed_run_preserves_row_on_continue(): void
    {
        Queue::fake();
        $sources = [$this->article('first'), $this->article('second')];
        MarkdownContentWriterAgent::fake([$this->aiOutput($sources), $this->verificationOutput()])->preventStrayPrompts();
        $service = app(TopicBatchService::class);
        $batch = $service->create($this->actor(), 'primary', ['GEO Guide'], ['mode' => 'ai', 'model_id' => $this->model()->id, 'after' => 'auto_publish'], (string) Str::uuid());
        $cancelled = false;
        TopicBuildRun::updated(function ($run) use ($service, $batch, &$cancelled): void {
            if (! $cancelled && $run->batch_id === $batch->id && $run->status === 'completed') {
                $cancelled = true;
                $service->cancel($batch->fresh());
            }
        });
        $service->processNext($batch->id, 1);
        $this->assertNotNull(Topic::query()->first()->public_revision_id);
        $this->assertSame('completed', TopicBuildRun::query()->first()->status);
        $service->retry($batch->fresh(), true);
        $service->processNext($batch->id, $batch->fresh()->generation);
        $this->assertSame('completed', $batch->fresh()->rows[0]['status'], 'Continue replayed an already completed run');
    }

    public function test_review_extra_existing_public_revision_can_publish_approved_update(): void
    {
        Queue::fake();
        $sources = [$this->article('first'), $this->article('second')];
        $service = app(TopicService::class);
        $task = app(TopicTaskService::class)->save(['name' => 'Review update task', 'target_site_key' => 'primary', 'ai_model_id' => $this->model()->id, 'topic_limit' => 3, 'publish_interval' => 3600, 'status' => 'active', 'topic_settings' => ['after' => 'review_then_publish']], $this->actor());
        $topic = $service->create('primary', ['title' => 'GEO Guide', 'intro' => 'Old introduction', 'task_id' => $task->id, 'articles' => array_map(fn ($a) => ['article_id' => $a->id], $sources)]);
        $old = $service->publish($topic, 1, $this->actor()->id);
        $topic = $service->save($topic, ['intro' => 'New introduction'], 1);
        $run = app(TopicGenerationService::class)->prepare($this->actor(), 'primary', ['title' => 'GEO Guide', 'after' => 'review_then_publish', 'defer_publication' => true], (string) Str::uuid(), $topic, $task);
        $run->update(['status' => 'completed', 'phase' => 'finished']);
        $new = $service->publish($topic, 2, $this->actor()->id, true);
        $service->approve($topic->fresh(), $this->actor()->id, $new->id);
        $this->assertSame($old->id, $topic->fresh()->public_revision_id);
        $this->assertNotNull(app(TopicTaskService::class)->dueRun($task->fresh()));
    }

    public function test_review_extra_hosted_task_delete_requires_super_admin(): void
    {
        Queue::fake();
        $channel = DistributionChannel::query()->create(['name' => 'Review hosted', 'domain' => 'review.sites.test', 'endpoint_url' => 'https://review.sites.test', 'channel_type' => DistributionChannel::TYPE_HOSTED_SITE, 'status' => DistributionChannel::STATUS_ACTIVE]);
        $profile = HostedSiteProfile::query()->create(['distribution_channel_id' => $channel->id, 'hostname' => 'review.sites.test', 'root_domain' => 'sites.test']);
        $task = app(TopicTaskService::class)->save(['name' => 'Hosted task', 'target_site_key' => 'hosted:'.$profile->id, 'topic_limit' => 3, 'publish_interval' => 3600], $this->actor());
        try {
            app(TaskLifecycleService::class)->deleteTask($task->id, false);
        } catch (ApiException $e) {
            $this->assertSame('forbidden', $e->getErrorCode());
        }
        $this->assertNotNull(Task::query()->find($task->id), 'Hosted task was deleted without super admin permission');
    }

    public function test_review_extra_generation_checks_supported_facts_before_publish(): void
    {
        $sources = [$this->article('first'), $this->article('second')];
        $data = json_decode($this->aiOutput($sources), true);
        $data['intro'] = 'GEO 价格为每月 999 元，已经通过认证。';
        $data['summary']['facts'][0]['text'] = 'GEO 价格为每月 999 元，已经通过认证。';
        MarkdownContentWriterAgent::fake([json_encode($data, JSON_UNESCAPED_UNICODE), json_encode(['supported' => false, 'unsupported_fields' => ['summary.facts.0']])])->preventStrayPrompts();
        $run = app(TopicGenerationService::class)->process($this->prepare(['after' => 'auto_publish'])->id);
        $view = $run->topic ? app(TopicService::class)->publicView($run->topic) : null;
        $this->assertFalse(str_contains(json_encode($view, JSON_UNESCAPED_UNICODE), '999'), 'Fabricated fact was published with valid IDs and no content check');
    }

    public function test_review_extra_cancelled_title_uses_new_task_model(): void
    {
        Queue::fake();
        $sources = [$this->article('first'), $this->article('second')];
        $oldModel = $this->model();
        $newModel = $oldModel->replicate();
        $newModel->name = 'New topic model';
        $newModel->save();
        $library = TitleLibrary::query()->create(['name' => 'Topic titles']);
        Title::query()->create(['library_id' => $library->id, 'title' => 'GEO Guide']);
        $tasks = app(TopicTaskService::class);
        $task = $tasks->save(['name' => 'New model test', 'target_site_key' => 'primary', 'title_library_id' => $library->id, 'ai_model_id' => $oldModel->id, 'topic_limit' => 3, 'publish_interval' => 3600, 'status' => 'active'], $this->actor());
        $queue = app(JobQueueService::class);
        $id = $queue->enqueueTaskJob($task->id);
        $queue->claimPendingJobById($id, 'topic-review-worker');
        $context = app(AiExecutionContextFactory::class)->fromTaskRun(TaskRun::query()->findOrFail($id));
        MarkdownContentWriterAgent::fake(function (string $prompt) use ($tasks, $task, $newModel, $sources): string {
            if (str_contains($prompt, '"phase":"verify"')) {
                return $this->verificationOutput();
            }
            $tasks->save(['status' => 'paused', 'ai_model_id' => $newModel->id, 'topic_config_version' => 1], $this->actor(), $task);

            return $this->aiOutput($sources);
        })->preventStrayPrompts();
        try {
            app(WorkerExecutionService::class)->executeTask($task->id, $context);
        } catch (AiModelAccessException) {
        }
        $task = $tasks->start($task->fresh(), $this->actor(), false);
        $next = $queue->enqueueTaskJob($task->id);
        $queue->claimPendingJobById($next, 'topic-review-worker-2');
        $context = app(AiExecutionContextFactory::class)->fromTaskRun(TaskRun::query()->findOrFail($next));
        MarkdownContentWriterAgent::fake([$this->aiOutput($sources), $this->verificationOutput()])->preventStrayPrompts();
        app(WorkerExecutionService::class)->executeTask($task->id, $context);
        $this->assertSame($newModel->id, collect(TopicBuildRun::query()->first()->telemetry)->last()['model_id']);
    }

    public function test_review_extra_scheduled_source_change_can_regenerate(): void
    {
        Queue::fake();
        $sources = [$this->article('first'), $this->article('second')];
        $library = TitleLibrary::query()->create(['name' => 'Topic titles']);
        Title::query()->create(['library_id' => $library->id, 'title' => 'GEO Guide']);
        $task = app(TopicTaskService::class)->save(['name' => 'Source change test', 'target_site_key' => 'primary', 'title_library_id' => $library->id, 'ai_model_id' => $this->model()->id, 'topic_limit' => 3, 'publish_interval' => 3600, 'status' => 'active'], $this->actor());
        $task->update(['next_publish_at' => now()->addDay()]);
        $queue = app(JobQueueService::class);
        $id = $queue->enqueueTaskJob($task->id);
        $queue->claimPendingJobById($id, 'topic-review-worker');
        $context = app(AiExecutionContextFactory::class)->fromTaskRun(TaskRun::query()->findOrFail($id));
        MarkdownContentWriterAgent::fake([$this->aiOutput($sources), $this->verificationOutput()])->preventStrayPrompts();
        $out = app(WorkerExecutionService::class)->executeTask($task->id, $context);
        $queue->completeJob($id, $task->id, null, 1, $out['meta'], $context, $context->executionLeaseToken());
        $sources[0]->update(['content' => 'GEO source changed before scheduled publication']);
        $task->update(['next_publish_at' => now()->subMinute()]);
        $next = $queue->enqueueTaskJob($task->id);
        $queue->claimPendingJobById($next, 'topic-review-worker-2');
        $context = app(AiExecutionContextFactory::class)->fromTaskRun(TaskRun::query()->findOrFail($next));
        try {
            app(WorkerExecutionService::class)->executeTask($task->id, $context);
        } catch (ValidationException) {
        }
        $run = TopicBuildRun::query()->first();
        $this->assertNotSame('completed', $run->status, 'Stale scheduled result never enters regeneration');
    }

    public function test_review_extra_expired_task_with_build_receipt_can_be_pruned(): void
    {
        Queue::fake();
        $task = app(TopicTaskService::class)->save(['name' => 'Prune test', 'target_site_key' => 'primary', 'topic_limit' => 3, 'publish_interval' => 3600], $this->actor());
        app(TopicGenerationService::class)->prepare($this->actor(), 'primary', ['title' => 'GEO Guide'], (string) Str::uuid(), null, $task);
        app(TaskLifecycleService::class)->deleteTask($task->id, true);
        DB::table('task_trash_entries')->where('task_id', $task->id)->update(['deleted_at' => now()->subDays(91)]);
        $this->artisan('geoflow:prune-task-trash')->assertExitCode(0);
        $this->assertNull(Task::withTrashed()->find($task->id));
    }

    public function test_review_extra_single_field_uses_existing_sources_without_matching_title(): void
    {
        $sources = [$this->article('first'), $this->article('second')];
        $topic = app(TopicService::class)->create('primary', ['title' => 'Getting Started', 'intro' => 'Human introduction', 'articles' => array_map(fn ($a) => ['article_id' => $a->id], $sources)]);
        MarkdownContentWriterAgent::fake([$this->aiOutput($sources), $this->verificationOutput()])->preventStrayPrompts();
        $run = $this->prepare(['title' => 'Getting Started', 'field' => 'intro', 'suggestion_only' => true], $topic);
        $result = app(TopicGenerationService::class)->process($run->id);
        $this->assertSame('needs_adoption', $result->status);
    }

    public function test_review_extra_monitoring_tracks_manual_publication_current_state(): void
    {
        $actor = $this->actor();
        $task = app(TopicTaskService::class)->save(['name' => '草稿任务', 'target_site_key' => 'primary', 'topic_limit' => 1, 'publish_interval' => 3600, 'topic_settings' => ['after' => 'draft_only']], $actor);
        $sources = [$this->article('first'), $this->article('second')];
        $payload = json_decode($this->aiOutput($sources), true);
        $topic = app(TopicService::class)->create('primary', $payload + ['title' => 'GEO Guide', 'task_id' => $task->id], $actor->id);
        $task->update(['created_count' => 1, 'published_count' => 0]);
        app(TopicService::class)->publish($topic, $topic->draft_version, $actor->id);
        $projection = app(TaskMonitoringQueryService::class)->getTaskMonitoringDetail($task->id);
        $this->assertSame(1, $projection['task_progress']['published_topics']);
        $this->assertSame(0, $projection['task_progress']['draft_topics']);
    }

    public function test_review_extra_withdrawn_pending_scheduled_run_stays_unpublished(): void
    {
        Queue::fake();
        $actor = $this->actor();
        $sources = [$this->article('first'), $this->article('second')];
        $task = app(TopicTaskService::class)->save(['name' => 'Withdraw pending', 'target_site_key' => 'primary', 'ai_model_id' => $this->model()->id, 'topic_limit' => 3, 'publish_interval' => 3600, 'status' => 'active', 'topic_settings' => ['after' => 'auto_publish']], $actor);
        $payload = json_decode($this->aiOutput($sources), true);
        $topic = app(TopicService::class)->create('primary', $payload + ['title' => 'GEO Guide', 'task_id' => $task->id], $actor->id);
        $run = app(TopicGenerationService::class)->prepare($actor, 'primary', ['title' => 'GEO Guide', 'after' => 'auto_publish', 'defer_publication' => true], (string) Str::uuid(), $topic, $task);
        $payload['source_hashes'] = array_combine(array_map(fn ($a) => $a->id, $sources), array_map(fn ($a) => TopicService::contentHash($a), $sources));
        $run->update(['status' => 'completed', 'phase' => 'finished', 'result' => $payload]);
        app(TopicService::class)->publish($topic, $topic->draft_version, $actor->id, true);
        app(TopicService::class)->withdraw($topic->fresh(), $actor->id);
        $queue = app(JobQueueService::class);
        $id = $queue->enqueueTaskJob($task->id);
        $queue->claimPendingJobById($id, 'withdraw-pending-worker');
        $context = app(AiExecutionContextFactory::class)->fromTaskRun(TaskRun::query()->findOrFail($id));
        app(WorkerExecutionService::class)->executeTask($task->id, $context);
        $this->assertNull($topic->fresh()->public_revision_id, 'Withdrawn pending revision was republished by the old completed run');
    }

    public function test_review_extra_manual_completion_counts_as_generated_task_result(): void
    {
        Queue::fake();
        $actor = $this->actor();
        $library = TitleLibrary::query()->create(['name' => 'Manual completion titles']);
        Title::query()->create(['library_id' => $library->id, 'title' => 'GEO Guide']);
        Title::query()->create(['library_id' => $library->id, 'title' => 'GEO Second Guide']);
        $tasks = app(TopicTaskService::class);
        $task = $tasks->save(['name' => 'Manual completion', 'target_site_key' => 'primary', 'title_library_id' => $library->id, 'ai_model_id' => $this->model()->id, 'topic_limit' => 1, 'publish_interval' => 3600, 'status' => 'active', 'topic_settings' => ['after' => 'draft_only']], $actor);
        $queue = app(JobQueueService::class);
        $id = $queue->enqueueTaskJob($task->id);
        $queue->claimPendingJobById($id, 'manual-completion-worker');
        $context = app(AiExecutionContextFactory::class)->fromTaskRun(TaskRun::query()->findOrFail($id));
        $out = app(WorkerExecutionService::class)->executeTask($task->id, $context);
        $this->assertSame('waiting_content', TopicBuildRun::query()->first()->status);
        $topic = Topic::query()->findOrFail($out['topic_id']);
        $sources = [$this->article('first'), $this->article('second')];
        app(TopicService::class)->save($topic, json_decode($this->aiOutput($sources), true), $topic->draft_version);
        $projection = app(TaskMonitoringQueryService::class)->getTaskMonitoringDetail($task->id);
        $this->assertSame(1, $projection['task_progress']['created_topics']);
        $this->assertFalse($tasks->hasWork($task->fresh()), 'Manual output did not fulfil the single-topic goal');
    }

    public function test_review_extra_maintenance_updates_untouched_generated_topic(): void
    {
        Queue::fake();
        $actor = $this->actor();
        $sources = [$this->article('first'), $this->article('second')];
        $topics = app(TopicService::class);
        $tasks = app(TopicTaskService::class);
        $payload = json_decode($this->aiOutput($sources), true);
        $topic = $topics->create('primary', $payload + ['title' => 'GEO Guide', 'automatic_write' => true], $actor->id);
        $topics->publish($topic, 1, $actor->id);
        $task = $tasks->save(['name' => 'Maintain untouched', 'target_site_key' => 'primary', 'ai_model_id' => $this->model()->id, 'topic_limit' => 3, 'publish_interval' => 3600, 'status' => 'active', 'topic_settings' => ['after' => 'auto_publish', 'auto_maintain' => true, 'bound_topic_ids' => [$topic->id], 'protect_manual' => true]], $actor);
        $queue = app(JobQueueService::class);
        $id = $queue->enqueueTaskJob($task->id);
        $queue->claimPendingJobById($id, 'untouched-maintenance-worker');
        $context = app(AiExecutionContextFactory::class)->fromTaskRun(TaskRun::query()->findOrFail($id));
        $payload['intro'] = 'Updated GEO introduction';
        MarkdownContentWriterAgent::fake([json_encode($payload, JSON_UNESCAPED_UNICODE), json_encode(['supported' => true, 'unsupported_fields' => []])])->preventStrayPrompts();
        app(WorkerExecutionService::class)->executeTask($task->id, $context);
        $run = TopicBuildRun::query()->first();
        $this->assertSame(0, $topic->fresh()->manual_edit_version);
        $this->assertSame('completed', $run->status, 'Maintenance never updates untouched AI fields when manual protection is enabled');
        $this->assertSame('Updated GEO introduction', $topic->fresh()->draft_payload['intro']);
    }

    public function test_review_extra_ineligible_manual_draft_does_not_fulfil_task_goal(): void
    {
        Queue::fake();
        $actor = $this->actor();
        $library = TitleLibrary::query()->create(['name' => 'Manual completion titles']);
        Title::query()->create(['library_id' => $library->id, 'title' => 'GEO Guide']);
        Title::query()->create(['library_id' => $library->id, 'title' => 'GEO Second Guide']);
        $tasks = app(TopicTaskService::class);
        $task = $tasks->save(['name' => 'Manual completion', 'target_site_key' => 'primary', 'title_library_id' => $library->id, 'ai_model_id' => $this->model()->id, 'topic_limit' => 1, 'publish_interval' => 3600, 'status' => 'active', 'topic_settings' => ['after' => 'draft_only']], $actor);
        $queue = app(JobQueueService::class);
        $id = $queue->enqueueTaskJob($task->id);
        $queue->claimPendingJobById($id, 'manual-completion-worker');
        $context = app(AiExecutionContextFactory::class)->fromTaskRun(TaskRun::query()->findOrFail($id));
        $out = app(WorkerExecutionService::class)->executeTask($task->id, $context);
        $this->assertSame('waiting_content', TopicBuildRun::query()->first()->status);
        $topic = Topic::query()->findOrFail($out['topic_id']);
        $sources = [$this->article('first'), $this->article('second')];
        foreach ($sources as $source) {
            $source->update(['status' => 'draft']);
        }
        app(TopicService::class)->save($topic, json_decode($this->aiOutput($sources), true), $topic->draft_version);
        $projection = app(TaskMonitoringQueryService::class)->getTaskMonitoringDetail($task->id);
        $this->assertSame(0, $projection['task_progress']['created_topics']);
        $this->assertTrue($tasks->hasWork($task->fresh()), 'Incomplete private-source draft prematurely fulfilled the goal');
    }

    public function test_review_extra_stricter_task_review_policy_blocks_old_scheduled_auto_publish(): void
    {
        Queue::fake();
        $actor = $this->actor();
        $sources = [$this->article('first'), $this->article('second')];
        $tasks = app(TopicTaskService::class);
        $task = $tasks->save(['name' => 'Policy tightening', 'target_site_key' => 'primary', 'ai_model_id' => $this->model()->id, 'topic_limit' => 3, 'publish_interval' => 3600, 'status' => 'active', 'topic_settings' => ['after' => 'auto_publish']], $actor);
        $payload = json_decode($this->aiOutput($sources), true);
        $topic = app(TopicService::class)->create('primary', $payload + ['title' => 'GEO Guide', 'task_id' => $task->id], $actor->id);
        $run = app(TopicGenerationService::class)->prepare($actor, 'primary', ['title' => 'GEO Guide', 'after' => 'auto_publish', 'defer_publication' => true], (string) Str::uuid(), $topic, $task);
        $payload['source_hashes'] = array_combine(array_map(fn ($a) => $a->id, $sources), array_map(fn ($a) => TopicService::contentHash($a), $sources));
        $run->update(['status' => 'completed', 'phase' => 'finished', 'result' => $payload]);
        $task = $tasks->save(['topic_config_version' => 1, 'topic_settings' => ['after' => 'review_then_publish']], $actor, $task);
        $queue = app(JobQueueService::class);
        $id = $queue->enqueueTaskJob($task->id);
        $queue->claimPendingJobById($id, 'policy-tightening-worker');
        $context = app(AiExecutionContextFactory::class)->fromTaskRun(TaskRun::query()->findOrFail($id));
        app(WorkerExecutionService::class)->executeTask($task->id, $context);
        $this->assertNull($topic->fresh()->public_revision_id, 'Old scheduled run bypassed the current review policy');
    }

    public function test_review_extra_trashed_topic_keeps_history_without_breaking_progress(): void
    {
        $actor = $this->actor();
        $sources = [$this->article('first'), $this->article('second')];
        $task = app(TopicTaskService::class)->save(['name' => 'Trashed progress', 'target_site_key' => 'primary', 'topic_limit' => 1, 'publish_interval' => 3600], $actor);
        $topic = app(TopicService::class)->create('primary', json_decode($this->aiOutput($sources), true) + ['title' => 'GEO Guide', 'task_id' => $task->id], $actor->id);
        $topic->delete();
        $counts = app(TopicTaskProgress::class)->forTasks([$task->id])[$task->id];
        $this->assertSame(1, $counts['created_topics']);
        $this->assertSame(0, $counts['draft_topics']);
    }

    public function test_review_extra_unchanged_manual_maintenance_does_not_repeat_ai_suggestion(): void
    {
        Queue::fake();
        $actor = $this->actor();
        $sources = [$this->article('first'), $this->article('second')];
        $topics = app(TopicService::class);
        $tasks = app(TopicTaskService::class);
        $payload = json_decode($this->aiOutput($sources), true);
        $topic = $topics->create('primary', $payload + ['title' => 'GEO Guide'], $actor->id);
        $topic = $topics->save($topic, ['intro' => 'Protected manual introduction'], 1);
        $topics->publish($topic, 2, $actor->id);
        $task = $tasks->save(['name' => 'Manual maintenance', 'target_site_key' => 'primary', 'ai_model_id' => $this->model()->id, 'topic_limit' => 3, 'publish_interval' => 3600, 'status' => 'active', 'topic_settings' => ['after' => 'draft_only', 'auto_maintain' => true, 'bound_topic_ids' => [$topic->id], 'protect_manual' => true]], $actor);
        $queue = app(JobQueueService::class);
        $id = $queue->enqueueTaskJob($task->id);
        $queue->claimPendingJobById($id, 'manual-maintenance-worker');
        $context = app(AiExecutionContextFactory::class)->fromTaskRun(TaskRun::query()->findOrFail($id));
        MarkdownContentWriterAgent::fake([json_encode($payload), json_encode(['supported' => true, 'unsupported_fields' => []])])->preventStrayPrompts();
        $out = app(WorkerExecutionService::class)->executeTask($task->id, $context);
        $this->assertSame('needs_adoption', TopicBuildRun::query()->sole()->status);
        $queue->completeJob($id, $task->id, null, 1, $out['meta'], $context, $context->executionLeaseToken());
        $topic->refresh()->update(['next_maintenance_at' => now()->subSecond()]);
        $next = $queue->enqueueTaskJob($task->id);
        $queue->claimPendingJobById($next, 'manual-maintenance-worker-2');
        $context = app(AiExecutionContextFactory::class)->fromTaskRun(TaskRun::query()->findOrFail($next));
        app(WorkerExecutionService::class)->executeTask($task->id, $context);
        $this->assertSame(1, TopicBuildRun::query()->count(), 'Unchanged sources created another maintenance invocation');
    }

    public function test_review_extra_task_pause_between_compose_and_verify_stops_new_ai_call(): void
    {
        Queue::fake();
        $actor = $this->actor();
        $sources = [$this->article('first'), $this->article('second')];
        $tasks = app(TopicTaskService::class);
        $library = TitleLibrary::query()->create(['name' => 'Pause before verify']);
        Title::query()->create(['library_id' => $library->id, 'title' => 'GEO Guide']);
        $task = $tasks->save(['name' => 'Pause before verify', 'target_site_key' => 'primary', 'title_library_id' => $library->id, 'ai_model_id' => $this->model()->id, 'topic_limit' => 3, 'publish_interval' => 3600, 'status' => 'active'], $actor);
        $queue = app(JobQueueService::class);
        $id = $queue->enqueueTaskJob($task->id);
        $queue->claimPendingJobById($id, 'pause-before-verify-worker');
        $context = app(AiExecutionContextFactory::class)->fromTaskRun(TaskRun::query()->findOrFail($id));
        $calls = 0;
        MarkdownContentWriterAgent::fake(function (string $prompt) use (&$calls, $task, $sources) {
            $calls++;
            if ($calls === 1) {
                $task->update(['status' => 'paused', 'schedule_enabled' => 0]);
                TaskRun::query()->where('task_id', $task->id)->update(['status' => 'cancelled', 'execution_lease_token' => null]);

                return $this->aiOutput($sources);
            }

            return json_encode(['supported' => true, 'unsupported_fields' => []]);
        })->preventStrayPrompts();
        try {
            app(WorkerExecutionService::class)->executeTask($task->id, $context);
        } catch (\Throwable) {
        }
        $this->assertSame(1, $calls, 'Verification began after the task and execution lease were cancelled');
        $this->assertSame(0, Topic::query()->count());
    }

    public function test_server_freshness_task_input_is_merged_into_the_saved_result(): void
    {
        Queue::fake();
        $sources = [$this->article('fresh-first'), $this->article('fresh-second')];
        MarkdownContentWriterAgent::fake([$this->aiOutput($sources), $this->verificationOutput()])->preventStrayPrompts();
        $run = $this->prepare(['freshness' => ['mode' => 'version', 'version' => 'v2.5', 'coverage_note' => 'Verified v2.5 source scope.', 'effective_to' => now()->addMonth()->toDateString()]]);
        $result = app(TopicGenerationService::class)->process($run->id);
        $this->assertSame('completed', $result->status);
        $this->assertSame('version', $result->topic->draft_payload['freshness']['mode']);
        $this->assertSame('v2.5', $result->topic->draft_payload['freshness']['version']);
        $this->assertSame('Verified v2.5 source scope.', $result->topic->draft_payload['freshness']['coverage_note']);
    }

    public function test_disabled_channel_blocks_task_start_before_generation(): void
    {
        Queue::fake();
        $actor = $this->actor();
        $tasks = app(TopicTaskService::class);
        $task = $tasks->save(['name' => 'Closed channel', 'target_site_key' => 'primary', 'ai_model_id' => $this->model()->id, 'topic_limit' => 3, 'publish_interval' => 3600, 'status' => 'paused'], $actor);
        SiteSetting::query()->updateOrCreate(['setting_key' => 'topics'], ['setting_value' => json_encode(['enabled' => false])]);
        $this->expectException(ValidationException::class);
        $tasks->start($task, $actor);
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

    public function test_single_block_missing_output_does_not_offer_empty_adoption(): void
    {
        $actor = $this->actor();
        $sources = [$this->article('one'), $this->article('two')];
        $topic = app(TopicService::class)->create('primary', json_decode($this->aiOutput($sources), true) + ['title' => 'GEO Guide'], $actor->id);
        $run = $this->prepare(['field' => 'seo', 'suggestion_only' => true], $topic);
        MarkdownContentWriterAgent::fake(fn (string $prompt): string => str_contains($prompt, '"phase":"verify"') ? $this->verificationOutput() : $this->aiOutput($sources))->preventStrayPrompts();
        $result = app(TopicGenerationService::class)->process($run->id);
        $this->assertSame('failed', $result->status, 'Requested SEO output was absent and cannot be reviewed or adopted');
        $this->assertSame(1, $topic->fresh()->draft_version);
    }

    public function test_maintenance_matching_respects_selected_category(): void
    {
        Queue::fake();
        $actor = $this->actor();
        $sources = [$this->article('first'), $this->article('second')];
        $other = $this->article('outside');
        $category = Category::query()->create(['name' => 'Outside', 'slug' => 'outside']);
        $other->update(['category_id' => $category->id]);
        $payload = json_decode($this->aiOutput($sources), true);
        $payload['summary']['facts'] = [];
        $topic = app(TopicService::class)->create('primary', $payload + ['title' => 'GEO Guide', 'automatic_write' => true], $actor->id);
        app(TopicService::class)->publish($topic, 1, $actor->id);
        $task = app(TopicTaskService::class)->save(['name' => 'Category maintenance', 'target_site_key' => 'primary', 'ai_model_id' => $this->model()->id, 'topic_limit' => 3, 'publish_interval' => 3600, 'status' => 'active', 'topic_settings' => ['after' => 'draft_only', 'auto_maintain' => true, 'bound_topic_ids' => [$topic->id], 'protect_manual' => true, 'category_ids' => [$sources[0]->category_id]]], $actor);
        $queue = app(JobQueueService::class);
        $id = $queue->enqueueTaskJob($task->id);
        $queue->claimPendingJobById($id, 'range-review');
        $context = app(AiExecutionContextFactory::class)->fromTaskRun(TaskRun::query()->findOrFail($id));
        MarkdownContentWriterAgent::fake([json_encode($payload), json_encode(['supported' => true, 'unsupported_fields' => []])])->preventStrayPrompts();
        app(WorkerExecutionService::class)->executeTask($task->id, $context);
        $run = TopicBuildRun::query()->sole();
        $report = collect($run->telemetry)->firstWhere('kind', 'matching')['report'];
        $this->assertSame(2, $report['scanned_count'], 'Maintenance must use the same category scope used for its signature');
        $this->assertSame([$sources[0]->category_id], $run->input['filters']['category_ids']);
    }

    public function test_independent_verification_bounds_the_complete_prompt_with_repeated_fact_sources(): void
    {
        $sources = [];
        for ($n = 0; $n < 20; $n++) {
            $source = $this->article('budget-'.$n);
            $source->update(['content' => 'GEO '.str_repeat('supporting evidence ', 110).' source '.$n]);
            $sources[] = $source;
        }
        $draft = json_decode($this->aiOutput($sources), true);
        $draft['summary']['facts'] = [];
        for ($n = 0; $n < 30; $n++) {
            $draft['summary']['facts'][] = ['text' => 'GEO evidence '.$n, 'article_ids' => array_map(fn ($a) => (string) $a->id, $sources)];
        }
        $lengths = [];
        $calls = 0;
        MarkdownContentWriterAgent::fake(function (string $prompt) use (&$lengths, &$calls, $draft): string {
            $lengths[] = strlen($prompt);
            $calls++;

            return $calls === 1 ? json_encode($draft) : $this->verificationOutput();
        })->preventStrayPrompts();
        $run = $this->prepare();
        $candidates = app(TopicGenerationService::class)->candidates('primary', 'GEO Guide');
        try {
            $result = app(TopicAiComposer::class)->compose($run, $candidates);
            $this->assertNotEmpty($result['summary']['facts'][0]['evidence']);
        } catch (ValidationException) {
            $this->assertNotEmpty($lengths);
        }
        $this->assertLessThanOrEqual(240000, max($lengths));
    }

    public function test_a_slower_independent_verification_keeps_its_model_lock_and_can_publish(): void
    {
        $sources = [$this->article('slow-first'), $this->article('slow-second')];
        $run = $this->prepare(['after' => 'auto_publish']);
        $timeouts = [];
        Event::listen(PromptingAgent::class, function (PromptingAgent $event) use (&$timeouts): void {
            $timeouts[] = $event->prompt->timeout;
        });
        MarkdownContentWriterAgent::fake(function (string $prompt) use ($sources, $run, &$timeouts): string {
            if (end($timeouts) < 100) {
                throw new \RuntimeException('The provider needs at least 100 seconds for each request.');
            }
            if (str_contains($prompt, '"phase":"verify"')) {
                $this->assertNull(app(AiModelInvocationLock::class)->acquireForMutation((int) $run->model_id));

                return $this->verificationOutput();
            }

            return $this->aiOutput($sources);
        })->preventStrayPrompts();
        $result = app(TopicGenerationService::class)->process($run->id);
        $this->assertSame('completed', $result->status);
        $this->assertNotNull($result->topic->public_revision_id);
        $this->assertCount(2, $timeouts);
        $this->assertLessThanOrEqual(120, $timeouts[0]);
        $this->assertLessThanOrEqual(120, $timeouts[1]);
        $this->assertSame('verified', collect($result->telemetry)->last()['status']);
        $lock = app(AiModelInvocationLock::class)->acquireForMutation((int) $run->model_id);
        $this->assertNotNull($lock);
        app(AiModelInvocationLock::class)->release($lock);
    }
}
