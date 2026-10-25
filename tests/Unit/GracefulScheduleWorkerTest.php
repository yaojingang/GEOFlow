<?php

namespace Tests\Unit;

use App\Services\Deployment\GracefulScheduleWorker;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

class GracefulScheduleWorkerTest extends TestCase
{
    public function test_stopping_waits_for_started_child_and_prevents_new_ticks(): void
    {
        $worker = new class extends GracefulScheduleWorker
        {
            public int $started = 0;

            protected function createProcess(): Process
            {
                $this->started++;

                return new Process([PHP_BINARY, '-r', 'usleep(200000);'], null, null, null, null);
            }
        };
        $output = static function (string $text): void {};
        $this->assertFalse($worker->tick(60, 10, $output));
        $worker->requestStop();
        $this->assertFalse($worker->tick(120, 10, $output));
        $this->assertSame(0, $worker->run(10, $output));
        $this->assertSame(1, $worker->started);
    }

    public function test_drain_deadline_fails_after_child_completes_without_killing_the_child(): void
    {
        $worker = new class extends GracefulScheduleWorker
        {
            protected function createProcess(): Process
            {
                return new Process([PHP_BINARY, '-r', 'usleep(1200000); echo "child-finished";'], null, null, null, null);
            }
        };
        $messages = '';
        $output = static function (string $text) use (&$messages): void {
            $messages .= $text;
        };
        $worker->tick(60, 1, $output);
        $worker->requestStop();
        $this->assertSame(1, $worker->run(1, $output));
        $this->assertStringContainsString('deadline exceeded', $messages);
        $this->assertStringContainsString('child-finished', $messages);
    }

    public function test_real_term_signal_keeps_the_scheduler_alive_until_child_finishes(): void
    {
        if (! extension_loaded('pcntl')) {
            $this->markTestSkipped('pcntl is required for scheduler signal verification.');
        }
        $autoload = dirname(__DIR__, 2).'/vendor/autoload.php';
        $script = <<<'PHP_SCRIPT'
require $argv[1];
$worker = new class extends \App\Services\Deployment\GracefulScheduleWorker {
    protected function createProcess(): \Symfony\Component\Process\Process {
        return new \Symfony\Component\Process\Process([PHP_BINARY, '-r', 'usleep(700000); echo "child-finished";'], null, null, null, null);
    }
};
pcntl_async_signals(true);
pcntl_signal(SIGTERM, fn () => $worker->requestStop());
$worker->tick(60, 10, fn ($output) => print($output));
echo "ready\n";
exit($worker->run(10, fn ($output) => print($output)));
PHP_SCRIPT;
        $process = new Process([PHP_BINARY, '-r', $script, $autoload], null, null, null, 5);
        $process->start();
        $ready = $process->waitUntil(static fn (string $type, string $output): bool => str_contains($output, 'ready'));
        $this->assertTrue($ready);
        $process->signal(SIGTERM);
        $this->assertTrue($process->isRunning());
        $this->assertSame(0, $process->wait());
        $this->assertStringContainsString('child-finished', $process->getOutput());
    }
}
