<?php

namespace App\Services\Topics;

use App\Jobs\ProcessTopicBatchJob;
use App\Models\Admin;
use App\Models\Topic;
use App\Models\TopicBuildRun;
use App\Models\TopicImportBatch;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class TopicBatchService
{
    public function __construct(private readonly TopicService $topics, private readonly TopicGenerationService $generation) {}

    /** @param list<string|array<string,mixed>> $titles @param array<string,mixed> $settings */
    public function create(Admin $actor, string $siteKey, array $titles, array $settings, string $requestKey): TopicImportBatch
    {
        $this->topics->assertValidSite($siteKey);
        abort_unless($actor->status === 'active' && ($siteKey === 'primary' || $actor->canManageProtectedWorkflows()), 403);
        if (count($titles) > 100 || count($titles) === 0) {
            throw ValidationException::withMessages(['titles' => '请填写 1 至 100 个标题，较大清单可以分批生成。']);
        }
        $rows = [];
        $first = [];
        foreach (array_values($titles) as $index => $value) {
            $input = is_string($value) ? ['title' => $value] : $value;
            if (($settings['mode'] ?? '') === 'existing') {
                $topic = Topic::withTrashed()->where('site_key', $siteKey)->find($input['topic_id'] ?? null);
                $error = ! $topic ? '专题不存在或不属于当前站点。' : ($topic->trashed() ? '专题已移入回收站，请先恢复。' : null);
                $normalized = ['title' => $topic?->title ?? '专题 #'.(int) ($input['topic_id'] ?? 0), 'metadata' => [], 'payload' => [], 'filters' => [], 'bound_topic_id' => (int) ($input['topic_id'] ?? 0), 'topic_id' => $topic?->id, 'status' => $error ? 'failed' : 'pending', 'error' => $error];
            } else {
                try {
                    $normalized = app(TopicImportRows::class)->normalize($input);
                    app(TopicTemplateCatalog::class)->assertAvailableForSite($siteKey, $normalized['payload']['template_key'] ?? $settings['template_key'] ?? 'default', 'metadata.template');
                } catch (ValidationException $failure) {
                    $normalized = ['title' => is_string($input['title'] ?? null) ? $input['title'] : '未填写标题', 'metadata' => is_array($input['metadata'] ?? null) ? $input['metadata'] : [], 'payload' => [], 'filters' => [], 'status' => 'failed', 'validation_failed' => true, 'error' => collect($failure->errors())->flatten()->implode('；')];
                }
            }
            $identity = [];
            if (empty($normalized['validation_failed'])) {
                try {
                    $identity = $this->generation->identityPayload(array_replace($settings, $normalized['payload'], ['filters' => array_replace(['category_ids' => $settings['category_ids'] ?? []], $normalized['filters'])]));
                } catch (ValidationException $failure) {
                    $normalized = array_replace($normalized, ['payload' => [], 'filters' => [], 'status' => 'failed', 'validation_failed' => true, 'error' => collect($failure->errors())->flatten()->implode('；')]);
                }
            }
            if (empty($normalized['validation_failed']) && ($settings['mode'] ?? '') !== 'existing') {
                $normalized['payload'] = array_replace($normalized['payload'], $identity);
            }
            $key = ($settings['mode'] ?? '') === 'existing' ? 'topic:'.$normalized['bound_topic_id'] : TopicPayload::topicKey($normalized['title'], $identity);
            $valid = empty($normalized['validation_failed']) && ($normalized['status'] ?? 'pending') !== 'failed';
            $duplicate = $valid ? ($first[$key] ?? null) : null;
            if ($valid) {
                $first[$key] ??= $index + 1;
            }
            $rows[] = $normalized + ['number' => $index + 1, 'status' => $duplicate ? 'duplicate' : 'pending', 'duplicate_of' => $duplicate, 'run_id' => null, 'topic_id' => $input['topic_id'] ?? null, 'expected_version' => $input['expected_version'] ?? null, 'error' => null];
        }
        $batch = TopicImportBatch::firstOrCreate(['request_key' => $requestKey], ['owner_admin_id' => $actor->id, 'site_key' => $siteKey, 'settings' => $settings, 'rows' => $rows, 'status' => 'pending', 'generation' => 1]);
        if ((int) $batch->owner_admin_id !== (int) $actor->id || $batch->site_key !== $siteKey || $batch->settings !== $settings || $this->inputRows($batch->rows) !== $this->inputRows($rows)) {
            throw ValidationException::withMessages(['request_key' => '该请求已经用于其他批次，请刷新后提交。']);
        }
        $this->dispatch($batch);

        return $batch;
    }

    private function inputRows(array $rows): array
    {
        return array_map(fn (array $row): array => ['title' => $row['title'], 'metadata' => $row['metadata'] ?? [], 'topic_id' => $row['bound_topic_id'] ?? null, 'expected_version' => $row['expected_version'] ?? null], $rows);
    }

    public function processNext(int $id, int $version): void
    {
        $claim = DB::transaction(function () use ($id, $version): ?array {
            $batch = TopicImportBatch::query()->lockForUpdate()->findOrFail($id);
            if ($batch->generation !== $version || in_array($batch->status, ['cancelled', 'completed'], true)) {
                return null;
            }
            $rows = $batch->rows;
            foreach ($rows as $index => $row) {
                if ($row['status'] === 'running') {
                    return null;
                }
            }
            foreach ($rows as $index => $row) {
                if ($row['status'] !== 'pending') {
                    continue;
                }
                $rows[$index]['status'] = 'running';
                $rows[$index]['claimed_at'] = now()->toIso8601String();
                $batch->update(['rows' => $rows, 'status' => 'running']);

                return [$batch, $index, $row];
            }
            $unfinished = collect($rows)->contains(fn (array $row): bool => in_array($row['status'], ['failed', 'waiting_content', 'publication_failed'], true));
            $batch->update(['status' => $unfinished ? 'needs_attention' : 'completed']);

            return null;
        });
        if (! $claim) {
            return;
        }
        [$batch, $index, $row] = $claim;
        try {
            $actor = Admin::query()->findOrFail($batch->owner_admin_id);
            if (($batch->settings['mode'] ?? 'ai') === 'draft') {
                $out = DB::transaction(function () use ($id, $version, $row, $actor): array {
                    $current = TopicImportBatch::query()->lockForUpdate()->findOrFail($id);
                    if ($current->generation !== $version || $current->status === 'cancelled') {
                        return ['status' => 'cancelled'];
                    }
                    abort_unless($actor->status === 'active' && ($current->site_key === 'primary' || $actor->canManageProtectedWorkflows()), 403);
                    $duplicate = Topic::withTrashed()->where('site_key', $current->site_key)->where('normalized_title_key', TopicPayload::topicKey($row['title'], $row['payload'] ?? []))->first();
                    if ($duplicate) {
                        return ['status' => 'duplicate', 'topic_id' => $duplicate->id, 'error' => '同主题专题已存在，已保留其状态。'];
                    }
                    $topic = $this->topics->create($current->site_key, array_replace(['title' => $row['title'], 'template_key' => $current->settings['template_key'] ?? 'default'], $row['payload'] ?? []), $actor->id);

                    return ['status' => 'completed', 'topic_id' => $topic->id, 'error' => null];
                });
            } else {
                $run = (isset($row['run_id']) ? TopicBuildRun::query()->find($row['run_id']) : null) ?? TopicBuildRun::query()->where('request_key', 'batch:'.$id.':row:'.$index)->first();
                if ($run && ! in_array($run->status, ['completed', 'duplicate', 'needs_adoption'], true)) {
                    $run = DB::transaction(function () use ($id, $version, $run): TopicBuildRun {
                        $current = TopicImportBatch::query()->lockForUpdate()->findOrFail($id);
                        if ($current->generation !== $version || $current->status === 'cancelled') {
                            throw ValidationException::withMessages(['batch' => '本批执行已取消或恢复，旧执行已停止。']);
                        }
                        $run = TopicBuildRun::query()->lockForUpdate()->findOrFail($run->id);
                        if ($run->status === 'running' && ! $this->generation->canRetry($run)) {
                            return $run;
                        }
                        $input = $run->input;
                        $input['batch_generation'] = $version;
                        $changes = ['input' => $input, 'status' => 'pending', 'phase' => $run->phase === 'publishing' || $run->status === 'publication_failed' ? 'publishing' : 'waiting', 'lease_token' => null, 'lease_expires_at' => null, 'finished_at' => null];
                        if ($run->result) {
                            try {
                                $this->generation->assertSources($run);
                            } catch (ValidationException) {
                                $changes['result'] = null;
                                $changes['phase'] = 'waiting';
                            }
                        }
                        $run->update($changes);

                        return $run;
                    });
                } elseif (! $run) {
                    $input = array_replace($batch->settings, $row['payload'] ?? [], ['title' => $row['title'], 'filters' => array_replace(['category_ids' => $batch->settings['category_ids'] ?? []], $row['filters'] ?? []), 'batch_id' => $batch->id, 'row_number' => $row['number'], 'batch_generation' => $version]);
                    if (array_key_exists('scope', $row['payload']['summary'] ?? [])) {
                        $input['declared_scope'] = $row['payload']['summary']['scope'];
                    }
                    $bound = null;
                    if (($batch->settings['mode'] ?? '') === 'existing') {
                        $bound = Topic::query()->where('site_key', $batch->site_key)->findOrFail($row['topic_id']);
                        if ((int) $bound->draft_version !== (int) $row['expected_version']) {
                            throw ValidationException::withMessages(['draft_version' => '工作稿已更新，请从专题列表重新获取建议。']);
                        }
                        $input['suggestion_only'] = true;
                        $input['after'] = 'draft_only';
                        $input['template_key'] = $bound->draft_payload['template_key'];
                    }
                    $metadataRules = [];
                    foreach ($row['metadata'] ?? [] as $key => $value) {
                        if (in_array($key, ['audience', 'sourcecoverage', 'tags'], true)) {
                            $metadataRules[] = $key.'：'.$value;
                        }
                    }
                    $input['rules'] = ($input['rules'] ?? '').($metadataRules === [] ? '' : "\n".implode("\n", $metadataRules));
                    $run = DB::transaction(function () use ($id, $index, $version, $actor, $input, $bound): TopicBuildRun {
                        $b = TopicImportBatch::query()->lockForUpdate()->findOrFail($id);
                        if ($b->generation !== $version || $b->status === 'cancelled') {
                            throw ValidationException::withMessages(['batch' => '本批执行已取消或恢复，旧执行已停止。']);
                        }
                        $run = $this->generation->prepare($actor, $b->site_key, $input, 'batch:'.$id.':row:'.$index, $bound);
                        $rows = $b->rows;
                        $rows[$index]['run_id'] = $run->id;
                        $b->update(['rows' => $rows]);

                        return $run;
                    });
                }
                $run = $this->generation->process((int) $run->id);
                $out = ['status' => $run->status, 'topic_id' => $run->topic_id, 'run_id' => $run->id, 'error' => $run->error];
            }
        } catch (\Throwable $exception) {
            $out = ['status' => 'failed', 'error' => $exception instanceof ValidationException ? collect($exception->errors())->flatten()->first() : '本行处理暂未完成，已保留输入和结果，可以重试。'];
        }
        DB::transaction(function () use ($id, $version, $index, $out): void {
            $batch = TopicImportBatch::query()->lockForUpdate()->findOrFail($id);
            if ($batch->generation !== $version || $batch->status === 'cancelled') {
                return;
            }
            $rows = $batch->rows;
            $rows[$index] = array_replace($rows[$index], $out);
            foreach ($rows as &$row) {
                if (($row['duplicate_of'] ?? null) === $index + 1) {
                    $row['topic_id'] = $rows[$index]['topic_id'];
                }
            }
            unset($row);
            $batch->update(['rows' => $rows]);
            $this->dispatch($batch);
        });
    }

    public function cancel(TopicImportBatch $batch): void
    {
        DB::transaction(function () use ($batch): void {
            $fresh = TopicImportBatch::query()->lockForUpdate()->findOrFail($batch->id);
            $fresh->update(['status' => 'cancelled', 'generation' => $fresh->generation + 1]);
            TopicBuildRun::query()->where('batch_id', $batch->id)->whereIn('status', ['pending', 'running'])->update(['status' => 'cancelled', 'lease_token' => null, 'finished_at' => now()]);
        });
    }

    public function canRecoverRow(TopicImportBatch $batch, array $row, ?TopicBuildRun $run): bool
    {
        if ($row['status'] !== 'running') {
            return false;
        }
        if ($run && (in_array($run->status, ['completed', 'needs_adoption', 'duplicate', 'failed', 'waiting_content', 'publication_failed', 'cancelled'], true) || $this->generation->canRetry($run))) {
            return true;
        }

        return (! $run || $run->status === 'pending') && Carbon::parse($row['claimed_at'] ?? $batch->updated_at)->lessThanOrEqualTo(now()->subMinutes(7));
    }

    public function retry(TopicImportBatch $batch, bool $remainingOnly = false, ?array $rowNumbers = null): void
    {
        DB::transaction(function () use ($batch, $remainingOnly, $rowNumbers): void {
            $b = TopicImportBatch::query()->lockForUpdate()->findOrFail($batch->id);
            $runs = TopicBuildRun::query()->where('batch_id', $b->id)->orderBy('id')->lockForUpdate()->get()->keyBy('row_number');
            $rows = $b->rows;
            abort_if(collect($rows)->contains(fn ($row) => $row['status'] === 'running' && ! $this->canRecoverRow($b, $row, $runs[$row['number']] ?? null)), 409, '当前项仍在执行，请等待完成或执行租约到期后恢复。');
            $changed = false;
            foreach ($rows as &$row) {
                $run = $runs[$row['number']] ?? null;
                $recoverable = $this->canRecoverRow($b, $row, $run);
                if (empty($row['validation_failed']) && ($rowNumbers === null || in_array($row['number'], $rowNumbers, true)) && ($recoverable || in_array($row['status'], $remainingOnly ? ['pending', 'cancelled'] : ['failed', 'waiting_content', 'publication_failed'], true))) {
                    if ($run && ! in_array($run->status, ['completed', 'needs_adoption', 'duplicate'], true)) {
                        $run->update(['dispatch_key' => (string) Str::uuid(), 'status' => 'pending', 'phase' => $run->status === 'publication_failed' || $run->phase === 'publishing' ? 'publishing' : 'waiting', 'lease_token' => null, 'lease_expires_at' => null, 'finished_at' => null]);
                    }
                    $row['status'] = 'pending';
                    $row['error'] = null;
                    if ($run) {
                        $row['run_id'] = $run->id;
                    }
                    $changed = true;
                }
            }
            unset($row);
            if ($changed) {
                // Unselected interrupted rows keep their output and must not block selected retries.
                foreach ($rows as &$row) {
                    if ($row['status'] !== 'running') {
                        continue;
                    }
                    $run = $runs[$row['number']] ?? null;
                    if ($run && in_array($run->status, ['completed', 'needs_adoption', 'duplicate'], true)) {
                        $row = array_replace($row, ['status' => $run->status, 'topic_id' => $run->topic_id, 'run_id' => $run->id, 'error' => $run->error]);
                    } else {
                        $row['status'] = 'failed';
                        $row['error'] = '本项执行已中断，结果保持未采用，可以单独恢复。';
                        $run?->update(['status' => 'failed', 'lease_token' => null, 'lease_expires_at' => null]);
                    }
                }
                unset($row);
                $b->update(['rows' => $rows, 'status' => 'pending', 'generation' => $b->generation + 1]);
                $this->dispatch($b);
            }
        });
    }

    public function failRunning(int $id, int $version): void
    {
        DB::transaction(function () use ($id, $version): void {
            $b = TopicImportBatch::query()->lockForUpdate()->find($id);
            if (! $b || $b->generation !== $version || $b->status !== 'running') {
                return;
            }
            $runs = TopicBuildRun::query()->where('batch_id', $b->id)->orderBy('id')->lockForUpdate()->get()->keyBy('row_number');
            $rows = $b->rows;
            $changed = false;
            foreach ($rows as &$row) {
                // A delayed failed hook cannot own a replacement row's live lease.
                if ($this->canRecoverRow($b, $row, $runs[$row['number']] ?? null)) {
                    $row['status'] = 'failed';
                    $row['error'] = '当前项执行中断，重试会找回已保存结果。';
                    $changed = true;
                }
            }
            unset($row);
            if ($changed) {
                $b->update(['status' => 'needs_attention', 'rows' => $rows]);
            }
        });
    }

    private function dispatch(TopicImportBatch $batch): void
    {
        ProcessTopicBatchJob::dispatch((int) $batch->id, (int) $batch->generation)->onQueue('geoflow')->afterCommit();
    }
}
