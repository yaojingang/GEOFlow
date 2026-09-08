<?php

namespace App\Services\Deployment;

use Symfony\Component\Process\Process;

class GracefulScheduleWorker
{
    /** @var list<Process> */
    private array $executions = [];

    private ?int $lastMinute = null;

    private ?float $stopRequestedAt = null;

    private bool $failed = false;

    private bool $deadlineReported = false;

    public function requestStop(): void
    {
        $this->stopRequestedAt ??= microtime(true);
    }

    public function run(int $drainTimeout, callable $output): int
    {
        while (! $this->tick(time(), $drainTimeout, $output)) {
            usleep(100000);
        }

        return $this->failed ? 1 : 0;
    }

    public function tick(int $timestamp, int $drainTimeout, callable $output): bool
    {
        if ($this->stopRequestedAt === null && $timestamp % 60 === 0 && $this->lastMinute !== intdiv($timestamp, 60)) {
            $execution = $this->createProcess();
            $execution->start();
            $this->executions[] = $execution;
            $this->lastMinute = intdiv($timestamp, 60);
        }
        foreach ($this->executions as $index => $execution) {
            $output($execution->getIncrementalOutput().$execution->getIncrementalErrorOutput());
            if (! $execution->isRunning()) {
                $this->failed = $this->failed || ! $execution->isSuccessful();
                unset($this->executions[$index]);
            }
        }
        if ($this->stopRequestedAt !== null && $this->executions !== []
            && microtime(true) - $this->stopRequestedAt >= $drainTimeout && ! $this->deadlineReported) {
            $this->failed = true;
            $this->deadlineReported = true;
            $output("Scheduler drain deadline exceeded; waiting for active tasks to finish.\n");
        }

        return $this->stopRequestedAt !== null && $this->executions === [];
    }

    protected function createProcess(): Process
    {
        return new Process([PHP_BINARY, base_path('artisan'), 'schedule:run', '--no-interaction'], base_path(), null, null, null);
    }
}
