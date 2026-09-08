<?php

namespace App\Services\Deployment;

use App\Support\GeoFlow\SecurityUpgradeMigrationGate;
use RuntimeException;
use Throwable;

class UpgradeOrchestrator
{
    public function __construct(
        private readonly UpgradePlan $plans,
        private readonly UpgradeJournal $journals,
        private readonly UpgradeStepRunner $runner,
        private readonly DeploymentReadiness $readiness,
    ) {}

    /** @return array<string,mixed> */
    public function execute(string $phase, string $strategy, string $operation, int $source, string $path, string $hash): array
    {
        $report = [
            'schema_version' => 1, 'status' => 'fail', 'phase' => $phase,
            'plan_sha256' => '', 'version' => '', 'pending_migrations' => [],
            'eligible_online' => false, 'checks' => [], 'errors' => [],
        ];
        try {
            if (! in_array($phase, ['inspect', 'apply', 'verify'], true) || ! in_array($strategy, ['online', 'maintenance'], true) || $source < 0) {
                throw new RuntimeException('upgrade_options_invalid');
            }
            if ($phase === 'apply' && ($hash === '' || $source < 1)) {
                throw new RuntimeException('upgrade_apply_requires_plan_hash_and_source');
            }
            if ($phase === 'apply' || $operation !== '') {
                $this->journals->assertOperation($operation);
            }
            $loaded = $this->plans->load($path, $hash);
            $plan = $loaded['plan'];
            $report['plan_sha256'] = $loaded['sha256'];
            $report['version'] = $loaded['version'];
            $report['checks']['manifest'] = ['status' => 'pass'];
            $report['pending_migrations'] = $this->plans->pending($plan);
            $report['eligible_online'] = $this->eligibleOnline($plan, $report['pending_migrations'], $source);
            $report['checks']['online_eligibility'] = ['status' => $report['eligible_online'] ? 'pass' : 'fail', 'required' => $strategy === 'online'];
            if ($strategy === 'online' && ! $report['eligible_online']) {
                throw new RuntimeException('upgrade_online_ineligible');
            }
            $standaloneVerification = $phase === 'verify' && $operation === '' && $source === 0;
            if (! $standaloneVerification && $plan['allowed_sources'] !== [] && ! in_array($source, $plan['allowed_sources'], true)) {
                throw new RuntimeException('upgrade_source_not_allowed');
            }
            $report['checks'] += $this->readiness->check($loaded['version'], $phase === 'verify');
            $this->assertChecks($report['checks']);
            if ($phase === 'apply') {
                $this->apply($plan, $report, $strategy, $operation, $source, $path);
            } elseif ($phase === 'verify') {
                $this->verify($plan, $report);
                if ($operation !== '') {
                    $journal = $this->journals->read($operation, $loaded['sha256'], $loaded['version'], $strategy, $source);
                    if (($journal['status'] ?? null) !== 'completed') {
                        throw new RuntimeException('upgrade_operation_incomplete');
                    }
                    $report['checks']['journal'] = ['status' => 'pass'];
                }
            }
            $report['status'] = 'pass';
        } catch (Throwable $exception) {
            $message = $exception->getMessage();
            $report['errors'][] = preg_match('/\Aupgrade_[A-Za-z0-9_:.-]+\z/', $message) ? $message : 'upgrade_internal_failure';
        }

        return $report;
    }

    /**
     * @param  array<string,mixed>  $plan
     * @param  list<array{name:string,sha256:string,online:bool}>  $pending
     */
    private function eligibleOnline(array $plan, array $pending, int $source): bool
    {
        if ($plan['strategy'] !== 'online' || ! in_array($source, $plan['allowed_sources'], true)
            || in_array(false, $plan['compatibility'], true)
            || array_intersect(array_column($pending, 'name'), UpgradePlan::PROTECTED_MIGRATIONS) !== []) {
            return false;
        }
        foreach ($pending as $migration) {
            if (! $migration['online']) {
                return false;
            }
        }
        foreach ($plan['steps'] as $step) {
            if (($step['kind'] !== 'migrate' || $pending !== []) && ! $step['online']) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param  array<string,mixed>  $plan
     * @param  array<string,mixed>  $report
     */
    private function apply(array $plan, array &$report, string $strategy, string $operation, int $source, string $path): void
    {
        if ($strategy === 'maintenance') {
            $confirmed = $_SERVER['GEOFLOW_SECURITY_UPGRADE_DRAIN_CONFIRMED'] ?? $_ENV['GEOFLOW_SECURITY_UPGRADE_DRAIN_CONFIRMED'] ?? getenv('GEOFLOW_SECURITY_UPGRADE_DRAIN_CONFIRMED');
            if (! is_string($confirmed) || filter_var($confirmed, FILTER_VALIDATE_BOOLEAN) !== true) {
                throw new RuntimeException('upgrade_host_drain_confirmation_required');
            }
        }
        $lock = $this->journals->acquire();
        $journal = null;
        $stepId = null;
        try {
            $this->plans->load($path, $report['plan_sha256']);
            $pending = $this->plans->pending($plan);
            if ($strategy === 'online' && ! $this->eligibleOnline($plan, $pending, $source)) {
                throw new RuntimeException('upgrade_online_ineligible');
            }
            if (array_intersect(array_column($pending, 'name'), UpgradePlan::PROTECTED_MIGRATIONS) !== []) {
                if ($strategy !== 'maintenance') {
                    throw new RuntimeException('upgrade_host_drain_confirmation_required');
                }
                SecurityUpgradeMigrationGate::assertReady();
            }
            $journal = $this->journals->read($operation, $report['plan_sha256'], $report['version'], $strategy, $source) ?? [
                'schema_version' => 1, 'operation' => $operation, 'plan_sha256' => $report['plan_sha256'],
                'version' => $report['version'], 'strategy' => $strategy, 'source_sequence' => $source,
                'status' => 'running', 'pending_migrations' => array_column($pending, 'name'), 'steps' => [],
            ];
            if (! is_array($journal['pending_migrations'] ?? null)
                || array_diff(array_column($pending, 'name'), $journal['pending_migrations']) !== []
                || array_diff(array_keys($journal['steps']), array_column($plan['steps'], 'id')) !== []) {
                throw new RuntimeException('upgrade_journal_migration_set_changed');
            }
            if ($journal['status'] === 'completed') {
                $this->verify($plan, $report);
                $report['checks']['journal'] = ['status' => 'pass'];

                return;
            }
            $journal['status'] = 'running';
            $this->journals->write($journal);
            foreach ($plan['steps'] as $step) {
                $stepId = $step['id'];
                $state = $journal['steps'][$stepId]['status'] ?? null;
                if ($state !== null && ! in_array($state, ['running', 'failed', 'completed'], true)) {
                    throw new RuntimeException('upgrade_journal_step_invalid');
                }
                if ($state === 'completed') {
                    if ($step['kind'] === 'migrate' && $this->plans->pending($plan) !== []) {
                        throw new RuntimeException('upgrade_completed_migrations_changed');
                    }

                    continue;
                }
                $journal['steps'][$stepId] = ['status' => 'running', 'started_at' => gmdate('c')];
                $this->journals->write($journal);
                $this->plans->assertMigrationManifest($plan);
                $this->runner->run($step, $this->plans->pending($plan), $strategy);
                if ($step['kind'] === 'migrate' && $this->plans->pending($plan) !== []) {
                    throw new RuntimeException('upgrade_migrations_incomplete');
                }
                $journal['steps'][$stepId]['status'] = 'completed';
                $journal['steps'][$stepId]['completed_at'] = gmdate('c');
                $this->journals->write($journal);
            }
            $stepId = null;
            $this->verify($plan, $report);
            $journal['status'] = 'completed';
            $journal['completed_at'] = gmdate('c');
            $this->journals->write($journal);
            $report['checks']['journal'] = ['status' => 'pass'];
        } catch (Throwable $exception) {
            if ($journal !== null) {
                $journal['status'] = 'failed';
                if ($stepId !== null && isset($journal['steps'][$stepId])) {
                    $journal['steps'][$stepId]['status'] = 'failed';
                }
                $this->journals->write($journal);
            }
            throw $exception;
        } finally {
            $this->journals->release($lock);
        }
    }

    /**
     * @param  array<string,mixed>  $plan
     * @param  array<string,mixed>  $report
     */
    private function verify(array $plan, array &$report): void
    {
        $this->plans->assertMigrationManifest($plan);
        $report['pending_migrations'] = $this->plans->pending($plan);
        if ($report['pending_migrations'] !== []) {
            throw new RuntimeException('upgrade_migrations_incomplete');
        }
        $report['checks']['migrations'] = ['status' => 'pass'];
        $report['checks'] = array_replace($report['checks'], $this->readiness->check($report['version'], true), $this->runner->verifyData());
        $this->assertChecks($report['checks']);
    }

    /** @param array<string,array<string,mixed>> $checks */
    private function assertChecks(array $checks): void
    {
        foreach ($checks as $name => $check) {
            if ($check['status'] !== 'pass' && ($check['required'] ?? true)) {
                throw new RuntimeException('upgrade_check_failed:'.$name);
            }
        }
    }
}
