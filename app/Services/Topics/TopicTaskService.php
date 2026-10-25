<?php

namespace App\Services\Topics;

use App\Data\Ai\AiExecutionContext;
use App\Models\Admin;
use App\Models\AiModel;
use App\Models\Task;
use App\Models\TaskRun;
use App\Models\Title;
use App\Models\Topic;
use App\Models\TopicBuildRun;
use App\Services\Admin\AdminAiModelAccessResolver;
use App\Services\Api\IdempotencyService;
use App\Services\GeoFlow\AiExecutionAccessGuard;
use App\Services\GeoFlow\AiExecutionContextFactory;
use App\Services\GeoFlow\JobQueueService;
use App\Services\GeoFlow\TaskActivationGuard;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class TopicTaskService
{
    public function __construct(private readonly TopicService $topics, private readonly TopicGenerationService $generation, private readonly AiExecutionContextFactory $identities, private readonly AiExecutionAccessGuard $access, private readonly TaskActivationGuard $activation, private readonly AdminAiModelAccessResolver $models, private readonly JobQueueService $queue) {}

    /** @param array<string,mixed> $data */
    public function save(array $data, Admin $actor, ?Task $existing = null): Task
    {
        if (is_array($data['topic_settings']['matching_rules'] ?? null)) {
            $data['topic_settings']['matching_rules'] = app(TopicMatchingRules::class)->parse($data['topic_settings']['matching_rules']);
        }
        $data['name'] = $data['name'] ?? $data['task_name'] ?? $existing?->name;
        if ($existing) {
            $settings = array_replace($existing->topic_settings ?? [], $data['topic_settings'] ?? []);
            if (array_key_exists('title_library_id', $data) && (int) $data['title_library_id'] !== (int) $existing->title_library_id && ! array_key_exists('title_overrides', $data['topic_settings'] ?? [])) {
                $settings['title_overrides'] = [];
                unset($settings['title_ids']);
            }
            $data = array_replace($existing->only(['name', 'title_library_id', 'ai_model_id', 'target_site_key', 'topic_limit', 'publish_interval', 'status']), $data, ['topic_settings' => $settings]);
        }
        $input = Validator::make($data, [
            'name' => ['required', 'string', 'max:200'], 'title_library_id' => ['nullable', 'integer', 'exists:title_libraries,id'], 'ai_model_id' => ['nullable', 'integer', 'exists:ai_models,id'],
            'target_site_key' => ['required', 'string', 'max:80'], 'topic_limit' => ['required', 'integer', 'min:1', 'max:99999'], 'publish_interval' => ['required', 'integer', 'min:60', 'max:31536000'],
            'topic_config_version' => [$existing ? 'required' : 'nullable', 'integer', 'min:1'], 'status' => ['nullable', 'in:active,paused'],
            'topic_settings' => ['nullable', 'array:after,template_key,target_count,rules,category_ids,update_existing,auto_maintain,bound_topic_ids,protect_manual,fallback_model_ids,freshness,matching_rules,title_overrides,title_ids'],
            'topic_settings.after' => ['nullable', 'in:auto_publish,draft_only,review_then_publish'], 'topic_settings.template_key' => ['nullable', 'string', 'max:64', 'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/D'],
            'topic_settings.target_count' => ['nullable', 'integer', 'min:2', 'max:24'], 'topic_settings.rules' => ['nullable', 'string', 'max:5000'],
            'topic_settings.category_ids' => ['nullable', 'array', 'max:100'], 'topic_settings.category_ids.*' => ['integer', 'exists:categories,id'],
            'topic_settings.bound_topic_ids' => ['nullable', 'array', 'max:100'], 'topic_settings.bound_topic_ids.*' => ['integer', 'exists:topics,id'], 'topic_settings.protect_manual' => ['nullable', 'boolean'], 'topic_settings.fallback_model_ids' => ['nullable', 'array', 'max:2'], 'topic_settings.fallback_model_ids.*' => ['integer', 'exists:ai_models,id'],
            'topic_settings.title_ids' => ['nullable', 'array', 'max:100'], 'topic_settings.title_ids.*' => ['integer', 'distinct', 'exists:titles,id'],
            'topic_settings.title_overrides' => ['nullable', 'array', 'max:100'], 'topic_settings.title_overrides.*' => ['array:audience,category,tags,template,freshness,sourcecoverage,topic_title'],
            'topic_settings.title_overrides.*.topic_title' => ['nullable', 'string', 'max:200'],
            'topic_settings.update_existing' => ['nullable', 'boolean'], 'topic_settings.auto_maintain' => ['nullable', 'boolean'],
        ] + TopicFreshnessService::rules('topic_settings.freshness') + TopicMatchingRules::rules('topic_settings.matching_rules'))->validate();
        if (isset($input['topic_settings']['matching_rules'])) {
            $input['topic_settings']['matching_rules'] = app(TopicMatchingRules::class)->normalize($input['topic_settings']['matching_rules']);
        }
        if (isset($input['topic_settings']['freshness'])) {
            try {
                $input['topic_settings']['freshness'] = app(TopicFreshnessService::class)->normalize($input['topic_settings']['freshness']);
            } catch (ValidationException $failure) {
                throw ValidationException::withMessages(collect($failure->errors())->mapWithKeys(fn ($messages, $key) => ['topic_settings.'.$key => $messages])->all());
            }
        }
        if (array_key_exists('title_ids', $input['topic_settings'] ?? [])) {
            $ids = array_map('intval', $input['topic_settings']['title_ids'] ?? []);
            if (count($ids) !== Title::query()->where('library_id', $input['title_library_id'] ?? null)->whereIn('id', $ids)->count()) {
                throw ValidationException::withMessages(['topic_settings.title_ids' => '本批标题只能属于当前标题库，请从批量入口重新导入。']);
            }
            $input['topic_settings']['title_ids'] = $ids;
        }
        $overrides = $input['topic_settings']['title_overrides'] ?? [];
        foreach ($overrides as $titleId => $metadata) {
            $title = ctype_digit((string) $titleId) ? Title::query()->where('library_id', $input['title_library_id'] ?? null)->find((int) $titleId) : null;
            if (! $title) {
                throw ValidationException::withMessages(['topic_settings.title_overrides' => '本行配置只能绑定当前标题库中的标题，请重新选择标题库或从批量入口导入。']);
            }
            try {
                $row = $this->titleRow($title, $metadata);
                $this->generation->identityPayload(array_replace($input['topic_settings'] ?? [], $row['payload'], ['filters' => array_replace(['category_ids' => $input['topic_settings']['category_ids'] ?? []], $row['filters'])]));
                app(TopicTemplateCatalog::class)->assertAvailableForSite($input['target_site_key'], $row['payload']['template_key'] ?? $input['topic_settings']['template_key'] ?? 'default');
            } catch (ValidationException $failure) {
                throw ValidationException::withMessages(['topic_settings.title_overrides' => collect($failure->errors())->flatten()->implode('；')]);
            }
        }
        $this->topics->assertValidSite($input['target_site_key']);
        app(TopicTemplateCatalog::class)->assertAvailableForSite($input['target_site_key'], $input['topic_settings']['template_key'] ?? 'default', 'topic_settings.template_key');
        if ($input['target_site_key'] !== 'primary' && ! $actor->canManageProtectedWorkflows()) {
            abort(403);
        }
        if (! empty($input['ai_model_id']) && ! $this->models->canView($actor, AiModel::query()->findOrFail($input['ai_model_id']))) {
            abort(403);
        }

        return DB::transaction(function () use ($input, $existing, $actor): Task {
            $task = $existing ? Task::query()->lockForUpdate()->findOrFail($existing->id) : new Task;
            if ($existing) {
                if ($task->content_type !== 'topic') {
                    throw ValidationException::withMessages(['content_type' => '任务类型保持不变，可以复制创建专题任务。']);
                }
                if ((int) $task->topic_config_version !== (int) $input['topic_config_version']) {
                    throw ValidationException::withMessages(['topic_config_version' => '任务设置已更新，请重新加载后保存。']);
                }
                if ($task->target_site_key !== $input['target_site_key'] && Topic::withTrashed()->where('task_id', $task->id)->exists()) {
                    throw ValidationException::withMessages(['target_site_key' => '已有生成结果的任务保留原站点，请创建新任务。']);
                }
                if ((int) $task->model_access_admin_id !== $actor->id && ! $actor->isSuperAdmin()) {
                    abort(403);
                }
            }
            $settings = array_replace(['after' => 'auto_publish', 'template_key' => 'default', 'target_count' => 8, 'rules' => ''], $input['topic_settings'] ?? []);
            $task->fill(['name' => $input['name'], 'content_type' => 'topic', 'target_site_key' => $input['target_site_key'], 'title_library_id' => $input['title_library_id'] ?? null, 'ai_model_id' => $input['ai_model_id'] ?? null, 'topic_limit' => $input['topic_limit'], 'topic_settings' => $settings, 'publish_interval' => $input['publish_interval'], 'publish_scope' => 'local_only', 'model_selection_mode' => 'fixed', 'ai_quality_enabled' => false, 'need_review' => $settings['after'] === 'review_then_publish', 'is_loop' => false, 'article_limit' => 1, 'draft_limit' => 1, 'topic_config_version' => $existing ? (int) $task->topic_config_version + 1 : 1]);
            if (! $existing) {
                $task->fill(['status' => 'paused', 'schedule_enabled' => 0]);
                $task->forceFill($this->identities->identityForTaskCreation((int) $actor->id) ?? []);
            }
            $task->save();
            if ($existing) {
                TopicBuildRun::query()->where('task_id', $task->id)->where('status', 'pending')->where('input->retry_requested', true)->update(['status' => 'cancelled', 'phase' => 'finished', 'error' => '任务设置已更新，原重试请求已停止。', 'finished_at' => now()]);
            }
            Topic::query()->where('maintenance_task_id', $task->id)->whereNotIn('id', $settings['bound_topic_ids'] ?? [])->update(['maintenance_task_id' => null, 'maintenance_paused_at' => now(), 'next_maintenance_at' => null]);
            foreach (($settings['bound_topic_ids'] ?? []) as $id) {
                $bound = Topic::query()->whereKey($id)->where('site_key', $task->target_site_key)->lockForUpdate()->firstOrFail();
                if ($bound->maintenance_task_id && $bound->maintenance_task_id !== $task->id && Task::query()->whereKey($bound->maintenance_task_id)->where('status', 'active')->exists()) {
                    throw ValidationException::withMessages(['topic_settings.bound_topic_ids' => '该专题已有维护任务，请先暂停原任务。']);
                }
                $paused = $bound->withdrawn_at !== null || ($bound->maintenance_task_id === $task->id && $bound->maintenance_paused_at !== null);
                $bound->update(['maintenance_task_id' => $task->id, 'maintenance_paused_at' => $paused ? ($bound->maintenance_paused_at ?? now()) : null, 'maintenance_settings' => ['protect_manual' => $settings['protect_manual'] ?? true], 'next_maintenance_at' => $paused ? null : now()]);
            }
            if (($input['status'] ?? 'paused') === 'active') {
                $task = $this->start($task, $actor, false);
            } elseif ($existing) {
                $task->update(['status' => 'paused', 'schedule_enabled' => 0, 'next_run_at' => null]);
                TaskRun::query()->where('task_id', $task->id)->whereIn('status', ['pending', 'running'])->update(['status' => 'cancelled', 'execution_lease_token' => null, 'finished_at' => now()]);
            }

            return $task->fresh();
        });
    }

    public function start(Task $task, Admin $actor, bool $enqueue = true): Task
    {
        return DB::transaction(function () use ($task, $actor, $enqueue): Task {
            $fresh = Task::query()->lockForUpdate()->findOrFail($task->id);
            if ((int) $fresh->model_access_admin_id !== $actor->id && ! $actor->isSuperAdmin()) {
                abort(403);
            }
            if ($fresh->target_site_key !== 'primary' && ! $actor->isSuperAdmin()) {
                abort(403);
            }
            $this->topics->assertValidSite($fresh->target_site_key);
            $this->topics->assertPublishingEnabled($fresh->target_site_key);
            try {
                app(TopicFreshnessService::class)->assertConfiguration($fresh->topic_settings['freshness'] ?? []);
            } catch (ValidationException $failure) {
                throw ValidationException::withMessages(collect($failure->errors())->mapWithKeys(fn ($messages, $key) => ['topic_settings.'.$key => $messages])->all());
            }
            $this->activation->assertCanActivate($fresh, $actor);
            $fresh->update(['status' => 'active', 'schedule_enabled' => 1, 'next_run_at' => now(), 'next_publish_at' => $fresh->next_publish_at ?? now()]);
            if ($enqueue) {
                $this->queue->enqueueTaskJob((int) $fresh->id, 'generate_topic');
            }

            return $fresh;
        });
    }

    public function retryGeneration(TopicBuildRun $run): void
    {
        $taskId = DB::transaction(function () use ($run): ?int {
            $task = Task::query()->lockForUpdate()->findOrFail($run->task_id);
            $current = TopicBuildRun::query()->lockForUpdate()->findOrFail($run->id);
            if (! $this->generation->canRetry($current)) {
                return null;
            }
            if ($task->content_type !== 'topic' || $task->status !== 'active' || ! $task->schedule_enabled) {
                throw ValidationException::withMessages(['run' => '请先启用专题任务，再重试本次生成。']);
            }
            if ((int) $task->topic_config_version !== (int) $current->config_version) {
                throw ValidationException::withMessages(['run' => '任务设置已更新，请从任务管理继续执行，系统会按当前设置重新核对。']);
            }
            $this->topics->assertPublishingEnabled($task->target_site_key);
            $this->access->assertPersistedAdminSnapshot($current->identity, (int) $task->id);
            if ($current->topic_id) {
                $topic = Topic::query()->lockForUpdate()->find($current->topic_id);
                if (! $topic || $topic->withdrawn_at !== null || (int) $topic->control_version !== (int) $current->expected_control_version) {
                    throw ValidationException::withMessages(['run' => '专题已撤回、暂停维护或移入回收站，请到专题页面查看当前状态。']);
                }
            }
            $input = $current->input;
            $input['retry_requested'] = true;
            $current->update(['input' => $input, 'dispatch_key' => (string) Str::uuid(), 'status' => 'pending', 'phase' => $current->status === 'publication_failed' ? 'publishing' : 'waiting', 'lease_token' => null, 'lease_expires_at' => null, 'finished_at' => null, 'error' => null]);
            $task->update(['next_run_at' => now()]);

            return (int) $task->id;
        });
        if ($taskId !== null) {
            $this->queue->enqueueTaskJob($taskId, 'generate_topic');
        }
    }

    private function requestedRetries(Task $task): Builder
    {
        return TopicBuildRun::query()->where('task_id', $task->id)->where('config_version', $task->topic_config_version)->where('input->retry_requested', true)->whereIn('status', ['pending', 'cancelled'])
            ->where(fn ($q) => $q->whereNull('topic_id')->orWhereHas('topic', fn ($q) => $q->whereColumn('topics.control_version', 'topic_build_runs.expected_control_version')->whereNull('withdrawn_at')));
    }

    private function attachRequestedRetry(TopicBuildRun $run, AiExecutionContext $context): TopicBuildRun
    {
        $input = $run->input;
        unset($input['retry_requested']);
        $input['task_run_id'] = $context->taskRunId;
        $input['execution_lease_token'] = $context->executionLeaseToken();
        $attributes = [];
        if ($run->result) {
            try {
                $this->generation->assertSources($run);
            } catch (ValidationException) {
                $attributes['result'] = null;
            }
        }
        $run->update($attributes + ['input' => $input, 'task_run_id' => $context->taskRunId, 'expected_execution_lease_token' => $context->executionLeaseToken(), 'status' => 'pending', 'phase' => $run->phase === 'publishing' && ! isset($attributes['result']) ? 'publishing' : 'waiting', 'lease_token' => null]);

        return $run;
    }

    /** @return array<string,mixed> */
    public function execute(Task $task, ?AiExecutionContext $context): array
    {
        $this->topics->assertPublishingEnabled($task->target_site_key);
        if (! $context) {
            throw ValidationException::withMessages(['ai_model_id' => '请先配置可用的 AI 模型。']);
        }
        $actor = $this->access->assertCurrent($context);
        if ($task->target_site_key !== 'primary' && ! $actor->isSuperAdmin()) {
            abort(403);
        }
        if (($published = $this->publishDue($task, $context)) !== null) {
            return $published;
        }
        $existing = TopicBuildRun::query()->where('task_run_id', $context->taskRunId)->first();
        if (! $existing) {
            $existing = DB::transaction(function () use ($task, $context, $actor): ?TopicBuildRun {
                $current = Task::query()->lockForUpdate()->findOrFail($task->id);
                $this->access->assertCurrent($context);
                if ($retry = $this->requestedRetries($current)->orderBy('id')->lockForUpdate()->first()) {
                    return $this->attachRequestedRetry($retry, $context);
                }
                if ((($current->topic_settings ?? [])['auto_maintain'] ?? false)) {
                    $bound = Topic::query()->where('maintenance_task_id', $current->id)->whereNull('maintenance_paused_at')->whereNotNull('public_revision_id')->where(fn ($q) => $q->whereNull('next_maintenance_at')->orWhere('next_maintenance_at', '<=', now()))->lockForUpdate()->first();
                    if ($bound) {
                        $settings = $current->topic_settings ?? [];
                        $candidates = $this->generation->candidates($current->target_site_key, $bound->title, ['category_ids' => $settings['category_ids'] ?? [], 'matching_rules' => $settings['matching_rules'] ?? $bound->draft_payload['matching_rules'] ?? [], 'excluded_article_ids' => $bound->draft_payload['source_overrides']['excluded_article_ids'] ?? []]);
                        $signature = $this->maintenanceSignature($current, $candidates);
                        $bound->update(['next_maintenance_at' => now()->addSeconds(max(60, (int) $current->publish_interval))]);
                        if ($signature !== $bound->last_maintenance_signature) {
                            $input = array_replace($settings, ['filters' => ['category_ids' => $settings['category_ids'] ?? []], 'title' => $bound->title, 'task_run_id' => $context->taskRunId, 'execution_lease_token' => $context->executionLeaseToken(), 'model_id' => $current->ai_model_id, 'defer_publication' => true, 'suggestion_only' => (bool) ($settings['protect_manual'] ?? true) && (int) $bound->manual_edit_version > 0, 'maintenance_signature' => $signature]);

                            return $this->generation->prepare($actor, $current->target_site_key, $input, 'task-run:'.$context->taskRunId, $bound, $current);
                        }
                    }
                }
                $current->created_count = app(TopicTaskProgress::class)->forTasks([(int) $current->id])[$current->id]['created_topics'];
                if ((int) $current->created_count >= (int) $current->topic_limit) {
                    return null;
                }
                $title = $this->titlesFor($current)->whereNotIn('id', TopicBuildRun::query()->where('task_id', $task->id)->whereNotNull('title_id')->select('title_id'))->orderBy('id')->lockForUpdate()->first();
                if (! $title) {
                    $retry = TopicBuildRun::query()->where('task_id', $current->id)->whereIn('status', ['waiting_content', 'failed', 'publication_failed', 'cancelled'])->where(fn ($q) => $q->whereNull('topic_id')->orWhereHas('topic', fn ($q) => $q->whereColumn('topics.control_version', 'topic_build_runs.expected_control_version')->whereNull('withdrawn_at')))->where(fn ($q) => $q->where('updated_at', '<=', now()->subDay())->orWhere('status', 'cancelled'))->whereNotNull('title_id')->whereIn('title_id', $this->titlesFor($current)->select('id'))->orderBy('id')->lockForUpdate()->first();
                    if (! $retry) {
                        return null;
                    }
                    $settings = $current->topic_settings ?? [];
                    $retryTitle = $this->titlesFor($current)->find($retry->title_id);
                    if (! $retryTitle) {
                        return null;
                    }
                    $row = $this->titleRow($retryTitle, $settings['title_overrides'][$retryTitle->id] ?? []);
                    $input = array_replace(['defer_publication' => true], $settings, $row['payload'], ['title' => $row['title'], 'title_id' => $retryTitle->id, 'task_run_id' => $context->taskRunId, 'model_id' => $current->ai_model_id, 'execution_lease_token' => $context->executionLeaseToken(), 'filters' => array_replace(['category_ids' => $settings['category_ids'] ?? []], $row['filters'])]);
                    $bound = $retry->topic_id ? Topic::query()->find($retry->topic_id) : null;
                    $input['declared_scope'] = $this->generation->identityPayload($input, $bound)['summary']['scope'];
                    if ($bound) {
                        $input['suggestion_only'] = (bool) ($settings['protect_manual'] ?? true) && (int) $bound->manual_edit_version > 0;
                    }
                    $semanticKeys = array_flip(['model_id', 'after', 'template_key', 'target_count', 'rules', 'filters', 'matching_rules', 'freshness', 'fallback_model_ids']);
                    $configurationChanged = array_intersect_key($input, $semanticKeys) !== array_intersect_key($retry->input, $semanticKeys);
                    $attributes = $configurationChanged ? ['result' => null] : [];
                    if ($retry->result && ! $configurationChanged) {
                        try {
                            $this->generation->assertSources($retry);
                        } catch (ValidationException) {
                            $attributes['result'] = null;
                        }
                    }
                    $retry->update($attributes + ['model_id' => $current->ai_model_id, 'expected_execution_lease_token' => $context->executionLeaseToken(), 'phase' => $retry->status === 'publication_failed' && ! isset($attributes['result']) ? 'publishing' : 'waiting', 'task_run_id' => $context->taskRunId, 'execution_lease_token' => $context->executionLeaseToken(), 'input' => $input, 'config_version' => $current->topic_config_version, 'status' => 'pending', 'lease_token' => null]);

                    return $retry;
                }
                $settings = $current->topic_settings ?? [];

                $row = $this->titleRow($title, $settings['title_overrides'][$title->id] ?? []);
                $bound = null;
                if ($settings['update_existing'] ?? false) {
                    $bound = Topic::query()->where('site_key', $current->target_site_key)->where('normalized_title_key', TopicPayload::topicKey($row['title'], $this->generation->identityPayload(array_replace($settings, $row['payload'], ['filters' => array_replace(['category_ids' => $settings['category_ids'] ?? []], $row['filters'])]))))
                        ->where(fn ($query) => $query->where('task_id', $current->id)->orWhereIn('id', $settings['bound_topic_ids'] ?? []))
                        ->whereNull('withdrawn_at')->whereNull('maintenance_paused_at')->lockForUpdate()->first();
                }
                $input = array_replace($settings, $row['payload'], ['defer_publication' => true, 'title' => $row['title'], 'title_id' => $title->id, 'task_run_id' => $context->taskRunId, 'execution_lease_token' => $context->executionLeaseToken(), 'model_id' => $current->ai_model_id, 'filters' => array_replace(['category_ids' => $settings['category_ids'] ?? []], $row['filters'])]);
                if ($bound) {
                    $input['suggestion_only'] = (bool) ($settings['protect_manual'] ?? true) && (int) $bound->manual_edit_version > 0;
                }

                return $this->generation->prepare($actor, $current->target_site_key, $input, 'task-run:'.$context->taskRunId, $bound, $current);
            });
        }
        if (! $existing) {
            return ['article_id' => null, 'topic_id' => null, 'title' => '', 'message' => '等待补充标题或已达到专题目标', 'meta' => ['content_type' => 'topic', 'action' => 'noop', 'reason' => 'waiting_titles']];
        }
        $result = $this->generation->process((int) $existing->id);
        DB::transaction(function () use ($task, $context, $result): void {
            $current = Task::query()->whereKey($task->id)->lockForUpdate()->firstOrFail();
            $this->queue->lockRunningJobForWorker($context, (int) $task->id);
            $run = TaskRun::query()->lockForUpdate()->findOrFail($context->taskRunId);
            $meta = $run->meta ?? [];
            if (in_array($result->status, ['completed', 'needs_adoption'], true) && isset($result->input['maintenance_signature'])) {
                Topic::query()->whereKey($result->topic_id)->update(['last_maintenance_signature' => $result->input['maintenance_signature']]);
            }
            $generated = app(TopicTaskProgress::class)->forTasks([(int) $current->id])[$current->id]['created_topics'];
            $current->update(['created_count' => $generated, 'next_run_at' => now()->addSeconds(max(60, (int) $current->publish_interval))]);
            $run->update(['content_type' => 'topic', 'topic_id' => $result->topic_id, 'meta' => $meta + ['topic_build_run_id' => $result->id]]);
            $current->update(['published_count' => Topic::query()->where('task_id', $task->id)->whereNotNull('public_revision_id')->count()]);
        });
        if ($result->status === 'completed' && ($published = $this->publishDue($task->fresh(), $context)) !== null) {
            return $published;
        }

        return ['article_id' => null, 'topic_id' => $result->topic_id, 'title' => $result->input['title'], 'message' => $result->error ?? '专题生成已完成', 'meta' => ['content_type' => 'topic', 'topic_id' => $result->topic_id, 'topic_build_run_id' => $result->id, 'action' => $result->status === 'completed' ? 'generate_topic' : 'noop', 'reason' => $result->status]];
    }

    /** Source and effective generation settings jointly determine whether maintenance needs a new result. */
    private function maintenanceSignature(Task $task, array $candidates): string
    {
        $settings = array_intersect_key($task->topic_settings ?? [], array_flip(['after', 'template_key', 'target_count', 'rules', 'category_ids', 'matching_rules', 'freshness', 'fallback_model_ids', 'protect_manual']));
        $settings['model_id'] = (int) $task->ai_model_id;

        return hash('sha256', json_encode(IdempotencyService::normalizePayload(['settings' => $settings, 'sources' => array_column($candidates, 'source_hash', 'article_id')]), JSON_THROW_ON_ERROR));
    }

    private function titleRow(Title $title, array $metadata): array
    {
        return app(TopicImportRows::class)->normalize(['title' => $metadata['topic_title'] ?? $title->title, 'metadata' => array_diff_key($metadata, ['topic_title' => true])]);
    }

    private function titlesFor(Task $task): Builder
    {
        $query = Title::query()->where('library_id', $task->title_library_id);
        if (array_key_exists('title_ids', $task->topic_settings ?? [])) {
            $query->whereIn('id', $task->topic_settings['title_ids'] ?? []);
        }

        return $query;
    }

    public function hasWork(Task $task): bool
    {
        if (! app(TopicSiteSettings::class)->get($task->target_site_key)['enabled']) {
            return false;
        }
        if ($this->dueRun($task) || $this->requestedRetries($task)->exists()) {
            return true;
        }
        if (($task->topic_settings['auto_maintain'] ?? false) && Topic::query()->where('maintenance_task_id', $task->id)->whereNull('maintenance_paused_at')->whereNotNull('public_revision_id')->where(fn ($q) => $q->whereNull('next_maintenance_at')->orWhere('next_maintenance_at', '<=', now()))->exists()) {
            return true;
        }
        $created = app(TopicTaskProgress::class)->forTasks([(int) $task->id])[$task->id]['created_topics'];
        if ($created >= $task->topic_limit) {
            return false;
        }

        return $this->titlesFor($task)->whereNotIn('id', TopicBuildRun::query()->where('task_id', $task->id)->whereNotNull('title_id')->select('title_id'))->exists()
            || TopicBuildRun::query()->where('task_id', $task->id)->whereIn('status', ['waiting_content', 'failed', 'publication_failed', 'cancelled'])->where(fn ($q) => $q->whereNull('topic_id')->orWhereHas('topic', fn ($q) => $q->whereColumn('topics.control_version', 'topic_build_runs.expected_control_version')->whereNull('withdrawn_at')))->where(fn ($q) => $q->where('updated_at', '<=', now()->subDay())->orWhere('status', 'cancelled'))->whereNotNull('title_id')->whereIn('title_id', $this->titlesFor($task)->select('id'))->exists();
    }

    public function dueRun(Task $task): ?TopicBuildRun
    {
        if (! array_key_exists('target_site_key', $task->getAttributes()) || ! array_key_exists('topic_config_version', $task->getAttributes())) {
            $task = Task::query()->findOrFail($task->id);
        }
        if (! app(TopicSiteSettings::class)->get($task->target_site_key)['enabled']) {
            return null;
        }
        if (! in_array($task->topic_settings['after'] ?? '', ['auto_publish', 'review_then_publish'], true) || $task->next_publish_at?->isFuture()) {
            return null;
        }

        return TopicBuildRun::query()->where('task_id', $task->id)->where('config_version', $task->topic_config_version)->where('status', 'completed')->whereNull('publication_completed_at')->whereNotNull('topic_id')
            ->whereHas('topic', fn ($q) => $q->whereNull('withdrawn_at')->whereColumn('topics.control_version', 'topic_build_runs.expected_control_version')->where(fn ($q) => $q->whereNull('pending_revision_id')->orWhereNotNull('approved_revision_id')))
            ->with(['topic.pendingRevision'])->get()->first(fn (TopicBuildRun $run): bool => $this->freshnessDue($run) && in_array($run->input['after'] ?? 'draft_only', ['auto_publish', 'review_then_publish'], true) && ($run->input['defer_publication'] ?? false) && (($run->input['after'] ?? '') === 'auto_publish' || $run->topic?->approved_revision_id !== null));
    }

    private function freshnessDue(TopicBuildRun $run): bool
    {
        $topic = $run->topic;
        if (! $topic || $topic->draft_version !== (int) $run->expected_version) {
            return false;
        }
        $snapshot = $topic->approved_revision_id ? ($topic->pendingRevision?->freshness_snapshot_json ?? []) : ($topic->draft_payload['freshness'] ?? []);

        return app(TopicFreshnessService::class)->publicFromReached($snapshot);
    }

    private function publishDue(Task $task, AiExecutionContext $context): ?array
    {
        if ($this->dueRun($task)) {
            app(TopicThemeCompatibility::class)->ensure($task->target_site_key, Admin::query()->findOrFail($task->model_access_admin_id));
        }

        return DB::transaction(function () use ($task, $context): ?array {
            $fresh = Task::query()->lockForUpdate()->findOrFail($task->id);
            $this->access->assertCurrent($context);
            if ($fresh->status !== 'active' || ! $fresh->schedule_enabled) {
                return null;
            }
            $this->queue->lockRunningJobForWorker($context, (int) $fresh->id);
            $due = $this->dueRun($fresh);
            if (! $due) {
                return null;
            }
            $topic = Topic::query()->lockForUpdate()->findOrFail($due->topic_id);
            if ((int) $due->config_version !== (int) $fresh->topic_config_version) {
                $due->update(['status' => 'needs_adoption', 'error' => '任务设置已改变，请在专题编辑页核对后发布。']);

                return null;
            }
            if ((int) $topic->control_version !== (int) $due->expected_control_version || $topic->withdrawn_at !== null) {
                $due->update(['status' => 'cancelled', 'error' => '专题已撤回或维护状态已变更，请明确恢复后重新生成。']);

                return null;
            }
            if (! $topic->approved_revision_id && $topic->draft_version !== (int) $due->expected_version) {
                $due->update(['status' => 'needs_adoption', 'error' => '草稿已修改，请在专题编辑页发布。']);

                return null;
            }
            try {
                $this->generation->assertSources($due);
            } catch (ValidationException $failure) {
                $due->update(['status' => 'waiting_content', 'result' => null, 'phase' => 'waiting', 'error' => '计划发布的来源已变化，将重新匹配文章。']);

                return ['article_id' => null, 'topic_id' => $topic->id, 'title' => $topic->title, 'message' => '来源已变化，等待重新生成', 'meta' => ['content_type' => 'topic', 'topic_id' => $topic->id, 'action' => 'noop', 'reason' => 'waiting_content']];
            }
            if ($topic->approved_revision_id) {
                $revision = $this->topics->publishApproved($topic, (int) $topic->approved_revision_id, (int) $fresh->model_access_admin_id);
            } else {
                $revision = $this->topics->publish($topic, (int) $topic->draft_version, (int) $fresh->model_access_admin_id, ($fresh->topic_settings['after'] ?? '') === 'review_then_publish', 'task-publish:'.$due->id);
            }
            $topic->refresh();
            $isPublic = $topic->public_revision_id === $revision->id;
            if ($isPublic) {
                $due->update(['publication_completed_at' => now()]);
            }
            $fresh->update(['next_publish_at' => now()->addSeconds(max(60, (int) $fresh->publish_interval)), 'published_count' => Topic::query()->where('task_id', $fresh->id)->whereNotNull('public_revision_id')->count()]);
            TaskRun::query()->whereKey($context->taskRunId)->update(['content_type' => 'topic', 'topic_id' => $topic->id]);

            return ['article_id' => null, 'topic_id' => $topic->id, 'title' => $topic->title, 'message' => $isPublic ? '专题已按计划发布' : '专题已提交固定版本审核', 'meta' => ['content_type' => 'topic', 'topic_id' => $topic->id, 'action' => $isPublic ? 'publish_topic' : 'submit_topic_review']];
        });
    }

    /** @return array<string,mixed> */
    public function readiness(Task $task): array
    {
        if (! app(TopicSiteSettings::class)->get($task->target_site_key)['enabled']) {
            return ['status' => 'channel_disabled', 'can_save' => true, 'can_activate' => false, 'requires_acknowledgement' => false, 'issues' => [['field' => 'target_site_key', 'message' => '目标站点专题频道已关闭，请开启后启动任务。']], 'warnings' => []];
        }
        $freshnessIssues = [];
        try {
            app(TopicFreshnessService::class)->assertConfiguration($task->topic_settings['freshness'] ?? []);
        } catch (ValidationException $failure) {
            foreach ($failure->errors() as $key => $messages) {
                $freshnessIssues[] = ['field' => 'topic_settings.'.$key, 'message' => $messages[0]];
            }
        }
        $total = $this->titlesFor($task)->count();
        $available = $this->titlesFor($task)->whereNotIn('id', TopicBuildRun::query()->where('task_id', $task->id)->whereNotNull('title_id')->select('title_id'))->count();

        return ['status' => $available > 0 ? 'ready' : 'waiting_titles', 'can_save' => true, 'can_activate' => $freshnessIssues === [], 'requires_acknowledgement' => false, 'issues' => $freshnessIssues, 'library' => ['id' => (int) $task->title_library_id, 'name' => $task->titleLibrary?->name ?? '', 'total' => $total, 'available' => $available, 'used' => max(0, $total - $available)], 'task' => ['id' => $task->id, 'article_limit' => $task->topic_limit, 'remaining' => max(0, $task->topic_limit - $task->created_count)], 'suggested_article_limit' => $task->topic_limit, 'warnings' => []];
    }
}
