<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

class PromptTestServerConcurrencyTest extends TestCase
{
    public function test_local_server_can_process_controls_while_a_stream_is_open(): void
    {
        $compose = file_get_contents(dirname(__DIR__, 2).'/docker-compose.yml');
        preg_match('/^  app:\R(?<block>(?:(?!^  [a-zA-Z0-9_-]+:\R).)*)/ms', $compose, $service);
        $this->assertNotEmpty($service['block']);
        preg_match('/PHP_CLI_SERVER_WORKERS:\s*"?(\d+)/', $service['block'], $workers);
        $this->assertGreaterThanOrEqual(2, (int) ($workers[1] ?? 1), 'Streaming must leave a worker for cancel and status requests.');
        $this->assertStringContainsString('--no-reload', $service['block'], 'Laravel serve only honors multiple workers with no-reload.');
    }
}
