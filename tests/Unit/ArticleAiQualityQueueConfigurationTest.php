<?php

namespace Tests\Unit;

use App\Jobs\ProcessArticleAiQualityJob;
use Tests\TestCase;

class ArticleAiQualityQueueConfigurationTest extends TestCase
{
    public function test_online_quality_jobs_leave_time_for_sampled_fallback_and_persistence(): void
    {
        $job = new ProcessArticleAiQualityJob(10);

        $this->assertSame(1, $job->tries);
        $this->assertSame(245, $job->timeout);
        $this->assertTrue($job->failOnTimeout);
        $this->assertSame([], $job->middleware());
    }

    public function test_quality_runtime_allows_slow_models_to_finish_within_the_three_minute_budget(): void
    {
        $this->assertSame(160, config('geoflow.ai_quality_request_timeout_seconds'));
        $this->assertSame(180, config('geoflow.ai_quality_deadline_seconds'));
        $this->assertSame(45, config('geoflow.ai_quality_sampled_fallback_seconds'));
        $this->assertSame(35, config('geoflow.ai_quality_sampled_request_timeout_seconds'));
        $this->assertSame(6000, config('geoflow.ai_quality_sampled_max_characters'));
        $this->assertSame(12, config('geoflow.ai_quality_sampled_max_ranges'));
        $this->assertSame(2048, config('geoflow.ai_quality_max_output_tokens'));
        $this->assertSame(12, config('geoflow.ai_quality_max_evidence'));
        $this->assertSame(6000, config('geoflow.ai_quality_max_evidence_characters'));
        $this->assertSame(6, config('geoflow.ai_quality_max_fact_retrievals'));
        $this->assertSame('legacy', config('geoflow.ai_quality_execution_version'));
        $this->assertSame(0, config('geoflow.ai_quality_principle_v2_percent'));
        $this->assertSame(0, config('geoflow.ai_quality_scoring_v2_percent'));
        $this->assertSame(60, config('geoflow.ai_quality_recovery_stale_seconds'));
        $this->assertSame(10, config('geoflow.ai_quality_persistence_reserve_seconds'));
        $this->assertSame(245, config('geoflow.ai_quality_job_timeout_seconds'));
        $this->assertSame(250, config('geoflow.ai_quality_worker_timeout_seconds'));
        $this->assertSame(300, config('geoflow.ai_quality_worker_stale_seconds'));
    }

    public function test_horizon_reserves_workers_for_online_quality_and_backfill(): void
    {
        $horizon = require dirname(__DIR__, 2).'/config/horizon.php';

        $this->assertSame(['ai-quality'], $horizon['defaults']['supervisor-ai-quality']['queue']);
        $this->assertSame(2, $horizon['defaults']['supervisor-ai-quality']['maxProcesses']);
        $this->assertSame(1, $horizon['defaults']['supervisor-ai-quality']['tries']);
        $this->assertSame(250, $horizon['defaults']['supervisor-ai-quality']['timeout']);
        $this->assertTrue($horizon['defaults']['supervisor-ai-quality']['force']);
        $this->assertSame(['ai-quality-backfill'], $horizon['defaults']['supervisor-ai-quality-backfill']['queue']);
        $this->assertSame(1, $horizon['defaults']['supervisor-ai-quality-backfill']['maxProcesses']);
        $this->assertSame(1, $horizon['defaults']['supervisor-ai-quality-backfill']['tries']);
        $this->assertTrue($horizon['defaults']['supervisor-ai-quality-backfill']['force']);
        $this->assertSame(10, $horizon['waits']['redis:ai-quality']);
        $this->assertSame(45, $horizon['waits']['redis:ai-quality-backfill']);
        $this->assertSame(
            ['ai-content-optimization', 'ai-content-optimization-bulk'],
            $horizon['defaults']['supervisor-ai-quality-optimization']['queue'],
        );
        $this->assertSame(2, $horizon['defaults']['supervisor-ai-quality-optimization']['maxProcesses']);
        $this->assertSame(900, $horizon['defaults']['supervisor-ai-quality-optimization']['timeout']);
        $this->assertSame(15, $horizon['waits']['redis:ai-content-optimization']);
        $this->assertSame(60, $horizon['waits']['redis:ai-content-optimization-bulk']);
    }

    public function test_local_and_container_runtimes_consume_both_quality_queues(): void
    {
        $root = dirname(__DIR__, 2);
        $composer = json_decode((string) file_get_contents($root.'/composer.json'), true, flags: JSON_THROW_ON_ERROR);
        $dev = implode("\n", (array) data_get($composer, 'scripts.dev', []));
        $localCompose = (string) file_get_contents($root.'/docker-compose.yml');
        $productionCompose = (string) file_get_contents($root.'/docker-compose.prod.yml');
        $prebuiltCompose = (string) file_get_contents($root.'/docker-compose.prebuilt.yml');

        $this->assertStringContainsString('--queue=system-updates,geoflow,distribution,theme-replication,default --tries=1 --timeout=930', $dev);
        $this->assertStringContainsString('geoflow:work-ai-quality front', $dev);
        $this->assertStringContainsString('geoflow:work-ai-quality backfill', $dev);
        $this->assertStringContainsString('geoflow:work-ai-optimization', $dev);
        foreach ([$localCompose, $productionCompose, $prebuiltCompose] as $compose) {
            $this->assertStringContainsString('ai-quality-queue:', $compose);
            $this->assertStringContainsString('ai-quality-backfill-queue:', $compose);
            $this->assertStringContainsString('"geoflow:work-ai-quality", "front"', $compose);
            $this->assertStringContainsString('"geoflow:work-ai-quality", "backfill"', $compose);
            $this->assertStringContainsString('ai-optimization-queue:', $compose);
            $this->assertStringContainsString('"geoflow:work-ai-optimization"', $compose);
            $this->assertStringContainsString('stop_grace_period: ${GEOFLOW_AI_QUALITY_STOP_GRACE_PERIOD:-260s}', $compose);
            $this->assertStringContainsString('replicas: ${AI_QUALITY_QUEUE_REPLICAS:-2}', $compose);
        }
    }

    public function test_quality_worker_command_rejects_an_unsafe_timeout_chain_before_starting(): void
    {
        config()->set('geoflow.ai_quality_deadline_seconds', 180);
        config()->set('geoflow.ai_quality_sampled_fallback_seconds', 45);
        config()->set('geoflow.ai_quality_persistence_reserve_seconds', 10);
        config()->set('geoflow.ai_quality_job_timeout_seconds', 245);
        config()->set('geoflow.ai_quality_worker_timeout_seconds', 245);

        $this->artisan('geoflow:work-ai-quality', ['lane' => 'front', '--validate' => true])
            ->assertFailed();
    }

    public function test_production_deploy_and_healthcheck_require_quality_workers(): void
    {
        $root = dirname(__DIR__, 2);
        $deploy = (string) file_get_contents($root.'/deploy-scripts/geoflow-docker-deploy.sh');
        $healthcheck = (string) file_get_contents($root.'/deploy-scripts/geoflow-healthcheck.sh');
        $deploymentGuide = (string) file_get_contents($root.'/docs/deployment/DEPLOYMENT.md');

        $this->assertStringContainsString(
            'up -d --remove-orphans app web queue ai-quality-queue ai-quality-backfill-queue ai-optimization-queue knowledge-queue scheduler reverb',
            $deploy,
        );
        $this->assertStringContainsString(
            'stop web app queue ai-quality-queue ai-quality-backfill-queue ai-optimization-queue knowledge-queue scheduler reverb',
            $deploy,
        );
        $this->assertStringNotContainsString(
            'knowledge-queue system-update-queue scheduler',
            $deploy,
        );
        $this->assertStringContainsString(
            'required=(postgres redis app web queue ai-quality-queue ai-quality-backfill-queue ai-optimization-queue knowledge-queue scheduler reverb)',
            $healthcheck,
        );
        $this->assertStringContainsString(
            'logs --tail=80 app queue ai-quality-queue ai-quality-backfill-queue ai-optimization-queue knowledge-queue scheduler web',
            $healthcheck,
        );
        $this->assertStringContainsString('read_env_value AI_QUALITY_QUEUE_REPLICAS', $healthcheck);
        $this->assertStringContainsString('ps --status running -q ai-quality-queue', $healthcheck);
        $this->assertStringContainsString('geoflow:ai-quality-health --json --probe --wait=10', $healthcheck);
        $this->assertStringContainsString('geoflow:converge-ai-quality --json', $healthcheck);
        $this->assertStringContainsString(
            '$COMPOSE_PROD stop web queue ai-quality-queue ai-quality-backfill-queue ai-optimization-queue knowledge-queue scheduler reverb',
            $deploymentGuide,
        );

        $routes = (string) file_get_contents($root.'/routes/console.php');
        $this->assertStringContainsString("Schedule::command('geoflow:converge-ai-quality')", $routes);
        $this->assertStringContainsString('->everyFiveSeconds()', $routes);

        foreach (['README_en.md', 'README_es.md', 'README_ja.md', 'README_ru.md'] as $readme) {
            $contents = (string) file_get_contents($root.'/docs/readme/'.$readme);
            $this->assertStringContainsString(
                'up -d app web queue ai-quality-queue ai-quality-backfill-queue ai-optimization-queue knowledge-queue scheduler reverb',
                $contents,
            );
        }
    }

    public function test_example_environments_document_the_fast_path_defaults(): void
    {
        $root = dirname(__DIR__, 2);

        foreach (['.env.example', '.env.prod.example'] as $file) {
            $contents = (string) file_get_contents($root.'/'.$file);

            $this->assertStringContainsString('GEOFLOW_AI_QUALITY_REQUEST_TIMEOUT_SECONDS=160', $contents);
            $this->assertStringContainsString('GEOFLOW_AI_QUALITY_DEADLINE_SECONDS=180', $contents);
            $this->assertStringContainsString('GEOFLOW_AI_QUALITY_PRINCIPLE_V2_PERCENT=0', $contents);
            $this->assertStringContainsString('GEOFLOW_AI_QUALITY_SAMPLED_FALLBACK_SECONDS=45', $contents);
            $this->assertStringContainsString('GEOFLOW_AI_QUALITY_SAMPLED_REQUEST_TIMEOUT_SECONDS=35', $contents);
            $this->assertStringContainsString('GEOFLOW_AI_QUALITY_SAMPLED_MAX_CHARACTERS=6000', $contents);
            $this->assertStringContainsString('GEOFLOW_AI_QUALITY_SAMPLED_MAX_RANGES=12', $contents);
            $this->assertStringContainsString('GEOFLOW_AI_QUALITY_MAX_OUTPUT_TOKENS=2048', $contents);
            $this->assertStringContainsString('GEOFLOW_AI_QUALITY_MAX_EVIDENCE=12', $contents);
            $this->assertStringContainsString('GEOFLOW_AI_QUALITY_MAX_FACT_RETRIEVALS=6', $contents);
            $this->assertStringContainsString('GEOFLOW_AI_QUALITY_JOB_TIMEOUT_SECONDS=245', $contents);
            $this->assertStringContainsString('GEOFLOW_AI_QUALITY_WORKER_TIMEOUT_SECONDS=250', $contents);
            $this->assertStringContainsString('GEOFLOW_AI_QUALITY_STOP_GRACE_PERIOD=260s', $contents);
            $this->assertStringContainsString('GEOFLOW_AI_QUALITY_EXECUTION_VERSION=legacy', $contents);
            $this->assertStringContainsString('GEOFLOW_AI_QUALITY_QUEUE=ai-quality', $contents);
            $this->assertStringContainsString('GEOFLOW_AI_QUALITY_BACKFILL_QUEUE=ai-quality-backfill', $contents);
            $this->assertStringContainsString('AI_QUALITY_QUEUE_REPLICAS=2', $contents);
            $this->assertStringContainsString('GEOFLOW_AI_QUALITY_RECOVERY_STALE_SECONDS=60', $contents);
        }
    }
}
