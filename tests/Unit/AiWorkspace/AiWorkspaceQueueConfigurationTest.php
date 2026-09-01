<?php

namespace Tests\Unit\AiWorkspace;

use PHPUnit\Framework\TestCase;

final class AiWorkspaceQueueConfigurationTest extends TestCase
{
    public function test_docker_deployments_have_no_ai_workspace_queue_consumers(): void
    {
        $root = dirname(__DIR__, 3);

        foreach (['docker-compose.yml', 'docker-compose.prod.yml', 'docker-compose.prebuilt.yml'] as $composeFile) {
            $compose = (string) file_get_contents($root.'/'.$composeFile);

            self::assertStringNotContainsString('ai-workspace-interactive', $compose, $composeFile);
            self::assertStringNotContainsString('--queue=ai-workspace', $compose, $composeFile);
            self::assertStringNotContainsString('ai-workspace-queue:', $compose, $composeFile);
        }
    }

    public function test_queue_and_horizon_have_no_dedicated_workspace_runtime(): void
    {
        $queue = require dirname(__DIR__, 3).'/config/queue.php';
        $horizon = require dirname(__DIR__, 3).'/config/horizon.php';

        self::assertArrayNotHasKey('redis-interactive', $queue['connections']);
        self::assertArrayNotHasKey('supervisor-ai-workspace', $horizon['defaults']);
        self::assertArrayNotHasKey('supervisor-ai-workspace-interactive', $horizon['defaults']);
        self::assertArrayNotHasKey('redis:ai-workspace', $horizon['waits']);
        self::assertArrayNotHasKey('redis-interactive:ai-workspace-interactive', $horizon['waits']);
    }

    public function test_examples_and_runtime_docs_have_no_workflow_queue_variables(): void
    {
        $root = dirname(__DIR__, 3);
        $content = implode("\n", array_map(
            static fn (string $file): string => (string) file_get_contents($root.'/'.$file),
            ['.env.example', '.env.prod.example', 'README.md', 'docs/ai-workspace-runbook.md'],
        ));

        foreach ([
            'GEOFLOW_AI_WORKSPACE_QUEUE=',
            'GEOFLOW_AI_WORKSPACE_INTERACTIVE_QUEUE=',
            'GEOFLOW_AI_WORKSPACE_QUEUE_CONNECTION=',
            'GEOFLOW_AI_WORKSPACE_INTERACTIVE_QUEUE_CONNECTION=',
            'ai-workspace-interactive-queue',
        ] as $legacySetting) {
            self::assertStringNotContainsString($legacySetting, $content);
        }
    }

    public function test_recovery_schedule_is_disabled(): void
    {
        $schedule = (string) file_get_contents(dirname(__DIR__, 3).'/routes/console.php');

        self::assertStringNotContainsString('recover-ai-workspace', $schedule);
        self::assertStringContainsString('prune-ai-workspace', $schedule);
    }

    public function test_primary_full_stack_startup_removes_orphaned_services(): void
    {
        $root = dirname(__DIR__, 3);

        foreach ([
            'README.md' => [
                'docker compose up -d --remove-orphans',
                'docker compose --env-file .env.prod -f docker-compose.prod.yml up -d --remove-orphans app web queue ai-quality-queue ai-quality-backfill-queue ai-optimization-queue knowledge-queue scheduler reverb',
            ],
            'docs/deployment/DEPLOYMENT.md' => [
                '$COMPOSE_PROD up -d --remove-orphans app web queue ai-quality-queue ai-quality-backfill-queue ai-optimization-queue knowledge-queue scheduler reverb',
                'docker compose --env-file .env.prod -f docker-compose.prod.yml up -d --remove-orphans --build',
            ],
            'deploy-scripts/geoflow-docker-deploy.sh' => [
                '"${COMPOSE[@]}" up -d --remove-orphans app web queue ai-quality-queue ai-quality-backfill-queue ai-optimization-queue knowledge-queue scheduler reverb',
            ],
        ] as $file => $commands) {
            $content = (string) file_get_contents($root.'/'.$file);

            foreach ($commands as $command) {
                self::assertStringContainsString($command, $content, $file);
            }
        }
    }

    public function test_long_running_local_services_skip_repeated_bootstrap_work(): void
    {
        $compose = (string) file_get_contents(dirname(__DIR__, 3).'/docker-compose.yml');

        foreach (['queue', 'ai-quality-queue', 'ai-quality-backfill-queue', 'ai-optimization-queue', 'knowledge-queue', 'scheduler', 'reverb'] as $service) {
            $serviceBlock = $this->composeServiceBlock($compose, $service);

            self::assertStringContainsString('COMPOSER_ON_START: "false"', $serviceBlock, $service);
            self::assertStringContainsString('AUTO_MIGRATE: "false"', $serviceBlock, $service);
            self::assertStringContainsString("init:\n        condition: service_completed_successfully", $serviceBlock, $service);
        }

        self::assertStringContainsString('AUTO_INIT_ONCE: "true"', $this->composeServiceBlock($compose, 'init'));
        self::assertStringNotContainsString('AUTO_MIGRATE: "false"', $this->composeServiceBlock($compose, 'app'));
    }

    private function composeServiceBlock(string $compose, string $service): string
    {
        $marker = "\n  {$service}:\n";
        $start = strpos("\n".$compose, $marker);

        self::assertIsInt($start, "Missing Compose service: {$service}");

        $serviceBlock = substr("\n".$compose, $start + 1);

        if (preg_match('/\n  [a-z0-9-]+:\n/', $serviceBlock, $nextService, PREG_OFFSET_CAPTURE) === 1) {
            $serviceBlock = substr($serviceBlock, 0, $nextService[0][1]);
        }

        return $serviceBlock;
    }
}
