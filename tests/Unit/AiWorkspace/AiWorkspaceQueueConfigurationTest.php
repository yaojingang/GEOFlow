<?php

namespace Tests\Unit\AiWorkspace;

use PHPUnit\Framework\TestCase;

final class AiWorkspaceQueueConfigurationTest extends TestCase
{
    public function test_all_docker_workers_consume_both_ai_workspace_queues_with_safe_timeouts(): void
    {
        $root = dirname(__DIR__, 3);

        foreach (['docker-compose.yml', 'docker-compose.prod.yml', 'docker-compose.prebuilt.yml'] as $composeFile) {
            $compose = file_get_contents($root.'/'.$composeFile);

            self::assertIsString($compose);
            self::assertStringContainsString(
                '--queue=ai-workspace-interactive,ai-workspace,geoflow,distribution,theme-replication,default',
                $compose,
                $composeFile.' must consume interactive and execution jobs.',
            );
            self::assertStringContainsString('--timeout=930', $compose, $composeFile.' must outlive the 900-second execution job.');
            self::assertStringContainsString('stop_grace_period: 960s', $compose, $composeFile.' must allow the longest job to stop safely.');
        }
    }

    public function test_horizon_keeps_ai_workspace_workloads_on_dedicated_supervisors(): void
    {
        $horizon = require dirname(__DIR__, 3).'/config/horizon.php';

        self::assertSame(['ai-workspace'], $horizon['defaults']['supervisor-ai-workspace']['queue'] ?? null);
        self::assertSame(930, $horizon['defaults']['supervisor-ai-workspace']['timeout'] ?? null);
        self::assertSame(['ai-workspace-interactive'], $horizon['defaults']['supervisor-ai-workspace-interactive']['queue'] ?? null);
        self::assertSame(150, $horizon['defaults']['supervisor-ai-workspace-interactive']['timeout'] ?? null);
    }
}
