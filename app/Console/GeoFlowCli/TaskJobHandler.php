<?php

namespace App\Console\GeoFlowCli;

final class TaskJobHandler
{
    public function __construct(private readonly CommandRuntime $runtime) {}

    public function handle(string $command): int
    {
        return $command === 'job' ? $this->job() : $this->task();
    }

    private function task(): int
    {
        $arguments = $this->runtime->context->positionals;
        $action = $arguments[1];
        $taskId = fn (): int => $this->runtime->positiveId($arguments[2] ?? null, '任务 ID');

        if ($action === 'delete') {
            $id = $taskId();
            $this->runtime->confirmDeletion("任务 {$id}");

            return $this->runtime->send('task.delete', ['task' => $id]);
        }

        return match ($action) {
            'list' => $this->runtime->send('task.list', query: [
                'page' => $this->runtime->integerOption('page', 1),
                'per_page' => $this->runtime->integerOption('per-page', 20),
                'status' => $this->runtime->context->options['status'] ?? null,
                'search' => $this->runtime->context->options['search'] ?? null,
            ]),
            'create' => $this->runtime->send('task.create', body: $this->runtime->jsonBody(), idempotencyKey: $this->runtime->idempotencyKey()),
            'get' => $this->runtime->send('task.get', ['task' => $taskId()]),
            'update' => $this->runtime->send('task.update', ['task' => $taskId()], body: $this->runtime->jsonBody(), idempotencyKey: $this->runtime->idempotencyKey()),
            'start' => $this->runtime->send('task.start', ['task' => $taskId()], body: ['enqueue_now' => $this->runtime->flag('enqueue-now')], idempotencyKey: $this->runtime->idempotencyKey()),
            'stop' => $this->runtime->send('task.stop', ['task' => $taskId()], body: [], idempotencyKey: $this->runtime->idempotencyKey()),
            'enqueue' => $this->runtime->send('task.enqueue', ['task' => $taskId()], body: $this->enqueueBody(), idempotencyKey: $this->runtime->idempotencyKey()),
            'jobs' => $this->runtime->send('task.jobs', ['task' => $taskId()], query: [
                'status' => $this->runtime->context->options['status'] ?? null,
                'limit' => $this->runtime->integerOption('limit', 20),
            ]),
        };
    }

    private function job(): int
    {
        return $this->runtime->send('job.get', [
            'job' => $this->runtime->positiveId($this->runtime->context->positionals[2] ?? null, 'Job ID'),
        ]);
    }

    /** @return array<string,mixed> */
    private function enqueueBody(): array
    {
        $body = [];
        $options = $this->runtime->context->options;
        if (isset($options['payload-json']) && trim((string) $options['payload-json']) !== '') {
            $body = $this->runtime->loadJson((string) $options['payload-json']);
        }
        if (isset($options['job-type']) && trim((string) $options['job-type']) !== '') {
            $body['job_type'] = trim((string) $options['job-type']);
        }

        return $body;
    }
}
