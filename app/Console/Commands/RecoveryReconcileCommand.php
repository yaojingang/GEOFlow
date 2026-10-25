<?php

namespace App\Console\Commands;

use App\Services\SystemUpdater\RecoveryReconciliation;
use Illuminate\Console\Command;
use RuntimeException;
use Throwable;

class RecoveryReconcileCommand extends Command
{
    protected $signature = 'geoflow:recovery-reconcile {--phase=inspect} {--transaction=} {--after=0} {--limit=100} {--decision-file=} {--recovery-point=} {--recovery-point-sha256=} {--json}';

    protected $description = 'Collect Core-only recovery evidence while preserving held work';

    public function handle(RecoveryReconciliation $reconciliation): int
    {
        try {
            $phase = $this->option('phase');
            $transaction = $this->option('transaction');
            if (! is_string($transaction) || preg_match('/\A[A-Za-z0-9][A-Za-z0-9._-]{7,127}\z/', $transaction) !== 1
                || ! in_array($phase, ['inspect', 'record', 'prove-empty'], true)) {
                throw new RuntimeException('recovery_reconciliation_parameters_invalid');
            }
            $allowed = match ($phase) {
                'inspect' => ['after', 'limit'],
                'record' => ['decision-file'],
                'prove-empty' => ['recovery-point', 'recovery-point-sha256'],
            };
            foreach (['after', 'limit', 'decision-file', 'recovery-point', 'recovery-point-sha256'] as $option) {
                if (! in_array($option, $allowed, true) && $this->input->hasParameterOption('--'.$option)) {
                    throw new RuntimeException('recovery_reconciliation_parameters_invalid');
                }
            }
            $report = match ($phase) {
                'inspect' => $reconciliation->inspect($transaction, $this->integerOption('after', 0, PHP_INT_MAX), $this->integerOption('limit', 1, 200)),
                'record' => $reconciliation->record($transaction, $this->readDecision()),
                'prove-empty' => $reconciliation->proveEmpty($transaction, $this->stringOption('recovery-point'), $this->stringOption('recovery-point-sha256')),
            };
            $this->line(json_encode($report, JSON_THROW_ON_ERROR));

            return self::SUCCESS;
        } catch (Throwable $exception) {
            $code = $exception instanceof RuntimeException && preg_match('/\Arecovery_[a-z_]+\z/', $exception->getMessage()) === 1
                ? $exception->getMessage() : 'recovery_reconciliation_failed';
            $this->line(json_encode(['schema_version' => 1, 'status' => 'fail', 'background_status' => 'held', 'error' => $code], JSON_THROW_ON_ERROR));

            return self::FAILURE;
        }
    }

    private function stringOption(string $name): string
    {
        $value = $this->option($name);
        if (! is_string($value) || $value === '') {
            throw new RuntimeException('recovery_reconciliation_parameters_invalid');
        }

        return $value;
    }

    private function integerOption(string $name, int $minimum, int $maximum): int
    {
        $value = $this->option($name);
        if ((! is_int($value) && ! is_string($value)) || preg_match('/\A(?:0|[1-9][0-9]*)\z/', (string) $value) !== 1
            || filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => $minimum, 'max_range' => $maximum]]) === false) {
            throw new RuntimeException('recovery_reconciliation_parameters_invalid');
        }

        return (int) $value;
    }

    private function readDecision(): array
    {
        $path = $this->stringOption('decision-file');
        clearstatcache(true, $path);
        if (! str_starts_with($path, '/') || realpath($path) !== $path || is_link($path) || ! is_file($path)) {
            throw new RuntimeException('recovery_reconciliation_decision_file_invalid');
        }
        $stream = @fopen($path, 'rb');
        if ($stream === false) {
            throw new RuntimeException('recovery_reconciliation_decision_file_invalid');
        }
        try {
            $opened = fstat($stream);
            $current = @lstat($path);
            if ($opened === false || $current === false || ($opened['mode'] & 0177777) !== 0100600
                || $opened['uid'] !== posix_geteuid() || $opened['nlink'] !== 1 || $opened['size'] > 16384
                || $opened['dev'] !== $current['dev'] || $opened['ino'] !== $current['ino'] || is_link($path)) {
                throw new RuntimeException('recovery_reconciliation_decision_file_invalid');
            }
            $bytes = stream_get_contents($stream, 16385);
        } finally {
            fclose($stream);
        }
        if (! is_string($bytes) || strlen($bytes) > 16384) {
            throw new RuntimeException('recovery_reconciliation_decision_file_invalid');
        }
        $decision = json_decode($bytes, true, 8, JSON_THROW_ON_ERROR);
        if (! is_array($decision) || array_is_list($decision)) {
            throw new RuntimeException('recovery_reconciliation_decision_invalid');
        }

        return $decision;
    }
}
