<?php

namespace App\Services\Topics;

use App\Data\Ai\AiExecutionContext;
use App\Models\Task;

/** Routes topic execution and readiness through the authoritative topic task service. */
final class TopicTaskRunner
{
    public function __construct(private readonly TopicTaskService $tasks) {}

    /** @return array<string,mixed> */
    public function readiness(Task $task): array
    {
        return $this->tasks->readiness($task);
    }

    /** @return array<string,mixed> */
    public function execute(Task $task, ?AiExecutionContext $context): array
    {
        return $this->tasks->execute($task, $context);
    }
}
