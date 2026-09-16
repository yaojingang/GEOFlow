<?php

namespace App\Services\SystemUpdater;

use App\Exceptions\ApiException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Throwable;

/** Host-only evidence collection. Decisions never dispatch or release restored work. */
final class RecoveryReconciliation
{
    public function __construct(private readonly RecoveryState $state) {}

    public function inspect(string $transaction, int $after = 0, int $limit = 100): array
    {
        $state = $this->boundary($transaction);
        $report = $this->verifySources($state);
        $entries = DB::table('recovery_quarantines')->where('epoch', $state['epoch'])
            ->where('id', '>', max(0, $after))->orderBy('id')->limit(max(1, min(200, $limit)))->get();

        return $this->identity($state) + [
            'status' => 'pass', 'proof_scope' => 'core_only', 'background_status' => 'held',
            'quarantine_count' => $report['quarantine_count'], 'quarantine_sha256' => $report['quarantine_sha256'],
            'entries' => $entries->map(fn (object $entry): array => [
                'quarantine_id' => $entry->id, 'source_table' => $entry->source_table, 'source_id' => $entry->source_id,
                'source_sha256' => $entry->source_sha256, 'summary' => json_decode($entry->summary, true, 32, JSON_THROW_ON_ERROR),
                'hold_reason' => 'replay_adapter_required',
            ])->all(),
            'next_after' => $entries->isEmpty() ? null : $entries->last()->id,
            'host_checks_required' => ['source_recovery_point', 'redis_quarantine', 'fresh_runtime'],
        ];
    }

    /** Append a hash-only review; every disposition preserves the held source. */
    public function record(string $transaction, array $decision): array
    {
        $this->validateDecision($decision);
        $state = $this->boundary($transaction);

        return DB::transaction(function () use ($state, $transaction, $decision): array {
            $this->prepared($state, true);
            $this->verifySources($state);
            $entry = DB::table('recovery_quarantines')->where('epoch', $state['epoch'])
                ->where('source_table', $decision['source_table'])->where('source_id', $decision['source_id'])->first();
            if ($entry === null || ! hash_equals($entry->source_sha256, $decision['source_sha256'])) {
                throw new RuntimeException('recovery_reconciliation_source_conflict');
            }
            $this->assertRecoveryPoint($state, $decision['source_recovery_point_id'], $decision['source_recovery_point_sha256']);
            $request = $this->identity($state) + $decision;
            $requestHash = hash('sha256', RecoveryEvidence::json($request));
            $report = $request + [
                'quarantine_id' => $entry->id, 'status' => 'recorded', 'proof_scope' => 'core_only',
                'background_status' => 'held', 'execution_created' => false, 'request_sha256' => $requestHash,
            ];
            $existing = DB::table('recovery_reconciliation_decisions')->where('decision_id', $decision['decision_id'])->first();
            if ($existing !== null) {
                if ($existing->epoch !== $state['epoch'] || ! hash_equals($existing->request_sha256, $requestHash)
                    || RecoveryEvidence::json($this->decode($existing->report)) !== RecoveryEvidence::json($report)) {
                    throw new RuntimeException('recovery_reconciliation_decision_conflict');
                }
            } else {
                DB::table('recovery_reconciliation_decisions')->insert([
                    'decision_id' => $decision['decision_id'], 'epoch' => $state['epoch'], 'quarantine_id' => $entry->id,
                    'source_table' => $entry->source_table, 'source_id' => $entry->source_id,
                    'source_sha256' => $entry->source_sha256, 'disposition' => $decision['disposition'],
                    'request_sha256' => $requestHash, 'report' => RecoveryEvidence::json($report), 'created_at' => now(),
                ]);
            }
            $this->unchanged($state, $this->boundary($transaction));

            return $this->decode(RecoveryEvidence::json($report));
        });
    }

    /** Core evidence only. The host independently verifies its point, Redis and runtime. */
    public function proveEmpty(string $transaction, string $point, string $pointHash): array
    {
        $this->validatePoint($point, $pointHash);
        $state = $this->boundary($transaction);

        return DB::transaction(function () use ($state, $transaction, $point, $pointHash): array {
            $this->prepared($state, true);
            $this->verifyEmpty($state);
            $this->assertRecoveryPoint($state, $point, $pointHash);
            $report = $this->emptyReport($state, $point, $pointHash);
            $hash = hash('sha256', RecoveryEvidence::json($report));
            $existing = DB::table('recovery_reconciliations')->where('epoch', $state['epoch'])->lockForUpdate()->first();
            if ($existing !== null) {
                $this->verifyProof($state, $existing);
                if (! hash_equals($existing->proof_sha256, $hash)) {
                    throw new RuntimeException('recovery_reconciliation_proof_conflict');
                }
            } else {
                DB::table('recovery_reconciliations')->insert([
                    'epoch' => $state['epoch'], 'proof_sha256' => $hash,
                    'report' => RecoveryEvidence::json($report), 'activated_at' => null, 'created_at' => now(),
                ]);
            }
            $this->unchanged($state, $this->boundary($transaction));

            return $report + ['proof_sha256' => $hash];
        });
    }

    /** Fail closed at every managed ready boundary, including after initial activation. */
    public function assertReady(array $state): void
    {
        try {
            if ($state['phase'] !== 'ready') {
                throw new RuntimeException('recovery_reconciliation_boundary_invalid');
            }
            $requiredTables = array_merge(
                ['recovery_preparations', 'recovery_quarantines', 'recovery_reconciliations', 'recovery_reconciliation_decisions'],
                RecoveryPreparation::INTENTS,
            );
            $prefix = DB::connection()->getTablePrefix();
            $tables = Schema::getTableListing(schema: Schema::getCurrentSchemaListing(), schemaQualified: false);
            if (array_diff(array_map(fn (string $table): string => $prefix.$table, $requiredTables), $tables) !== []) {
                throw new RuntimeException('recovery_reconciliation_schema_missing');
            }
            DB::transaction(function () use ($state): void {
                $prepared = DB::table('recovery_preparations')->where('epoch', $state['epoch'])->lockForUpdate()->first();
                if ($prepared === null && $state['transaction_id'] === null) {
                    if (DB::table('recovery_reconciliations')->where('epoch', $state['epoch'])->exists()
                        || DB::table('recovery_quarantines')->where('epoch', $state['epoch'])->exists()
                        || DB::table('recovery_reconciliation_decisions')->where('epoch', $state['epoch'])->exists()) {
                        throw new RuntimeException('recovery_reconciliation_preparation_missing');
                    }
                    $this->unchanged($state, $this->state->snapshot());

                    return;
                }
                $this->prepared($state);
                $proof = DB::table('recovery_reconciliations')->where('epoch', $state['epoch'])->lockForUpdate()->first();
                if ($proof === null) {
                    throw new RuntimeException('recovery_reconciliation_proof_missing');
                }
                $report = $this->verifyProof($state, $proof);
                $this->assertRecoveryPoint($state, $report['source_recovery_point_id'], $report['source_recovery_point_sha256']);
                if (DB::table('recovery_quarantines')->where('epoch', $state['epoch'])->exists()) {
                    throw new RuntimeException('recovery_quarantine_changed');
                }
                if ($proof->activated_at === null) {
                    $this->verifyEmpty($state);
                    $this->unchanged($state, $this->state->snapshot());
                    DB::table('recovery_reconciliations')->where('epoch', $state['epoch'])->update(['activated_at' => now()]);
                }
                $this->unchanged($state, $this->state->snapshot());
            });
        } catch (Throwable) {
            throw new ApiException('recovery_background_held', '恢复后的后台任务仍需对账，暂未恢复执行', 503);
        }
    }

    private function validateDecision(array $decision): void
    {
        $fields = ['schema_version', 'decision_id', 'source_table', 'source_id', 'source_sha256', 'disposition',
            'evidence_sha256', 'reviewer_sha256', 'source_recovery_point_id', 'source_recovery_point_sha256'];
        if (($decision['disposition'] ?? null) === 'reexecute_requested') {
            $fields[] = 'user_confirmation_sha256';
        }
        $keys = array_keys($decision);
        sort($keys);
        sort($fields);
        if ($keys !== $fields || ($decision['schema_version'] ?? null) !== 1) {
            throw new RuntimeException('recovery_reconciliation_decision_invalid');
        }
        foreach ($decision as $key => $value) {
            if ($key !== 'schema_version' && ! is_string($value)) {
                throw new RuntimeException('recovery_reconciliation_decision_invalid');
            }
            if (str_ends_with($key, '_sha256') && ! $this->isHash($value)) {
                throw new RuntimeException('recovery_reconciliation_decision_invalid');
            }
        }
        if (preg_match('/\A[a-f0-9]{8}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{12}\z/', $decision['decision_id']) !== 1
            || ! in_array($decision['source_table'], RecoveryPreparation::INTENTS, true)
            || preg_match('/\A[A-Za-z0-9][A-Za-z0-9._-]{0,127}\z/', $decision['source_id']) !== 1
            || ! in_array($decision['disposition'], ['hold', 'verified_no_replay', 'reexecute_requested'], true)) {
            throw new RuntimeException('recovery_reconciliation_decision_invalid');
        }
        $this->validatePoint($decision['source_recovery_point_id'], $decision['source_recovery_point_sha256']);
    }

    private function validatePoint(string $point, string $hash): void
    {
        if (preg_match('/\A[A-Za-z0-9][A-Za-z0-9._-]{7,127}\z/', $point) !== 1 || ! $this->isHash($hash)) {
            throw new RuntimeException('recovery_reconciliation_point_invalid');
        }
    }

    private function isHash(mixed $value): bool
    {
        return is_string($value) && preg_match('/\A[a-f0-9]{64}\z/', $value) === 1;
    }

    private function decode(string $json): array
    {
        $value = json_decode($json, true, 32, JSON_THROW_ON_ERROR);
        if (! is_array($value) || array_is_list($value)) {
            throw new RuntimeException('recovery_reconciliation_evidence_invalid');
        }

        return $value;
    }

    private function unchanged(array $expected, ?array $current): void
    {
        if ($current === null || RecoveryEvidence::json($expected) !== RecoveryEvidence::json($current)) {
            throw new RuntimeException('recovery_reconciliation_boundary_changed');
        }
    }

    private function prepared(array $state, bool $lock = false): array
    {
        $query = DB::table('recovery_preparations')->where('epoch', $state['epoch']);
        $prepared = ($lock ? $query->lockForUpdate() : $query)->first();
        if ($prepared === null || $prepared->transaction_id !== $state['transaction_id']) {
            throw new RuntimeException('recovery_reconciliation_preparation_missing');
        }
        $report = $this->decode($prepared->report);
        if (($report['schema_version'] ?? null) !== 1 || ($report['epoch'] ?? null) !== $state['epoch']
            || ($report['transaction_id'] ?? null) !== $state['transaction_id'] || ($report['status'] ?? null) !== 'pass'
            || ! is_int($report['quarantine_count'] ?? null) || $report['quarantine_count'] < 0
            || ! $this->isHash($report['quarantine_sha256'] ?? null)) {
            throw new RuntimeException('recovery_reconciliation_preparation_invalid');
        }

        return $report;
    }

    private function verifyEmpty(array $state): void
    {
        $report = $this->verifySources($state);
        if ($report['quarantine_count'] !== 0 || ! hash_equals(hash('sha256', ''), $report['quarantine_sha256'])) {
            throw new RuntimeException('recovery_reconciliation_nonempty');
        }
    }

    private function emptyReport(array $state, string $point, string $pointHash): array
    {
        return $this->identity($state) + [
            'status' => 'pass', 'proof_scope' => 'core_only', 'background_status' => 'held',
            'source_recovery_point_id' => $point, 'source_recovery_point_sha256' => $pointHash,
            'catalog_sha256' => hash('sha256', RecoveryEvidence::json(RecoveryPreparation::INTENTS)),
            'quarantine_count' => 0, 'quarantine_sha256' => hash('sha256', ''),
            'host_checks_required' => ['source_recovery_point', 'redis_quarantine', 'fresh_runtime'],
        ];
    }

    private function verifyProof(array $state, object $proof): array
    {
        $report = $this->decode($proof->report);
        $point = $report['source_recovery_point_id'] ?? null;
        $pointHash = $report['source_recovery_point_sha256'] ?? null;
        if (! is_string($point) || ! is_string($pointHash)) {
            throw new RuntimeException('recovery_reconciliation_proof_invalid');
        }
        $this->validatePoint($point, $pointHash);
        $expected = $this->emptyReport($state, $point, $pointHash);
        $prepared = $this->prepared($state);
        if ($proof->epoch !== $state['epoch'] || ! $this->isHash($proof->proof_sha256)
            || RecoveryEvidence::json($report) !== RecoveryEvidence::json($expected)
            || ! hash_equals($proof->proof_sha256, hash('sha256', RecoveryEvidence::json($expected)))
            || $prepared['quarantine_count'] !== 0 || $prepared['quarantine_sha256'] !== hash('sha256', '')) {
            throw new RuntimeException('recovery_reconciliation_proof_invalid');
        }

        return $report;
    }

    private function assertRecoveryPoint(array $state, string $point, string $pointHash): void
    {
        $proof = DB::table('recovery_reconciliations')->where('epoch', $state['epoch'])->first();
        if ($proof !== null) {
            $report = $this->verifyProof($state, $proof);
            if ($report['source_recovery_point_id'] !== $point || $report['source_recovery_point_sha256'] !== $pointHash) {
                throw new RuntimeException('recovery_reconciliation_point_conflict');
            }
        }
        foreach (DB::table('recovery_reconciliation_decisions')->where('epoch', $state['epoch'])->cursor() as $row) {
            $report = $this->decode($row->report);
            if (($report['source_recovery_point_id'] ?? null) !== $point || ($report['source_recovery_point_sha256'] ?? null) !== $pointHash) {
                throw new RuntimeException('recovery_reconciliation_point_conflict');
            }
            $request = array_diff_key($report, array_flip(['quarantine_id', 'status', 'proof_scope', 'background_status', 'execution_created', 'request_sha256']));
            $decision = array_diff_key($request, array_flip(['host_id', 'instance_id', 'epoch', 'transaction_id']));
            $this->validateDecision($decision);
            if (! $this->isHash($row->request_sha256) || ($report['request_sha256'] ?? null) !== $row->request_sha256
                || ! hash_equals($row->request_sha256, hash('sha256', RecoveryEvidence::json($request)))
                || RecoveryEvidence::json(array_intersect_key($report, $this->identity($state))) !== RecoveryEvidence::json($this->identity($state))
                || ($report['decision_id'] ?? null) !== $row->decision_id || ($report['quarantine_id'] ?? null) !== $row->quarantine_id
                || $report['source_table'] !== $row->source_table || $report['source_id'] !== $row->source_id
                || $report['source_sha256'] !== $row->source_sha256 || $report['disposition'] !== $row->disposition
                || ($report['status'] ?? null) !== 'recorded' || ($report['proof_scope'] ?? null) !== 'core_only'
                || ($report['background_status'] ?? null) !== 'held' || ($report['execution_created'] ?? null) !== false) {
                throw new RuntimeException('recovery_reconciliation_decision_conflict');
            }
        }
    }

    private function boundary(string $transaction): array
    {
        $state = $this->state->snapshot();
        if ($state === null || ! in_array($state['phase'], ['validating', 'http_ready'], true)
            || $state['transaction_id'] !== $transaction
            || preg_match('/\A[A-Za-z0-9][A-Za-z0-9._-]{7,127}\z/', $transaction) !== 1) {
            throw new RuntimeException('recovery_reconciliation_boundary_invalid');
        }

        return $state;
    }

    private function identity(array $state): array
    {
        return ['schema_version' => 1, 'host_id' => $state['host_id'], 'instance_id' => $state['instance_id'],
            'epoch' => $state['epoch'], 'transaction_id' => $state['transaction_id']];
    }

    /** Recheck the complete source set, including additions after the preparation snapshot. */
    private function verifySources(array $state): array
    {
        $report = $this->prepared($state);
        foreach (RecoveryPreparation::INTENTS as $table) {
            if (DB::table($table)->count() !== DB::table('recovery_quarantines')->where('epoch', $state['epoch'])->where('source_table', $table)->count()) {
                throw new RuntimeException('recovery_quarantine_changed');
            }
        }
        $hash = hash_init('sha256');
        $count = 0;
        foreach (DB::table('recovery_quarantines')->where('epoch', $state['epoch'])->orderBy('source_table')->orderBy('source_id')->cursor() as $entry) {
            if (! in_array($entry->source_table, RecoveryPreparation::INTENTS, true) || $entry->status !== 'held') {
                throw new RuntimeException('recovery_quarantine_changed');
            }
            $source = DB::table($entry->source_table)->where('id', $entry->source_id)->first();
            if ($source === null) {
                throw new RuntimeException('recovery_quarantine_changed');
            }
            $fields = (array) $source;
            ksort($fields);
            if (! hash_equals($entry->source_sha256, hash('sha256', json_encode($fields, JSON_THROW_ON_ERROR)))) {
                throw new RuntimeException('recovery_quarantine_changed');
            }
            hash_update($hash, json_encode([$entry->source_table, $entry->source_id, $entry->source_sha256,
                json_decode($entry->summary, true, 32, JSON_THROW_ON_ERROR), $entry->status], JSON_THROW_ON_ERROR)."\n");
            $count++;
        }
        if ($count !== $report['quarantine_count'] || ! hash_equals($report['quarantine_sha256'], hash_final($hash))) {
            throw new RuntimeException('recovery_quarantine_changed');
        }

        return $report;
    }
}
