<?php

namespace App\Services\Security;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Throwable;

class SecurityAuditService
{
    private const AUDIT_CHUNK_SIZE = 100;

    private const SEVERITIES = ['critical', 'high', 'medium', 'low'];

    /**
     * @return array{
     *     schema_version:int,
     *     status:string,
     *     summary:array{critical:int,high:int,medium:int,low:int,total:int},
     *     findings:list<array{code:string,severity:string,count:int,message:string,remediation:string}>
     * }
     */
    public function audit(): array
    {
        try {
            $findings = [];
            $readinessBlockers = 0;

            $imagesReady = $this->checkSchema(
                $findings,
                'images',
                ['id', 'managed_path_hash'],
                'SECURITY_SCHEMA_IMAGES_INCOMPLETE',
                'The images security schema is incomplete.',
                'Run the v2.1.1 database migrations while following the documented drain protocol.',
            );
            $registryReady = $this->checkSchema(
                $findings,
                'managed_image_paths',
                ['id', 'path_hash', 'file_path', 'content_sha256', 'state', 'lock_version', 'created_at', 'updated_at'],
                'SECURITY_SCHEMA_MANAGED_IMAGE_PATHS_INCOMPLETE',
                'The managed image registry schema is incomplete.',
                'Run the v2.1.1 database migrations while physical image deletion remains disabled.',
            );
            $idempotencyReady = $this->checkSchema(
                $findings,
                'api_idempotency_keys',
                ['id', 'request_hash', 'response_status', 'fingerprint_version', 'state', 'owner_token', 'lease_expires_at'],
                'SECURITY_SCHEMA_IDEMPOTENCY_INCOMPLETE',
                'The API idempotency security schema is incomplete.',
                'Drain old processes and run the v2.1.1 database migrations before restoring write traffic.',
            );

            if ($imagesReady) {
                $missingHashes = DB::table('images')
                    ->where(static function ($query): void {
                        $query->whereNull('managed_path_hash')->orWhere('managed_path_hash', '');
                    })
                    ->count();
                $this->add(
                    $findings,
                    'IMAGE_MANAGED_HASH_MISSING',
                    'high',
                    $missingHashes,
                    'Image records are missing their managed path identity.',
                    'Keep deletion disabled and complete the managed image hash readiness procedure.',
                );
                $readinessBlockers += $missingHashes;
            }

            if ($imagesReady && $registryReady) {
                $missingRegistry = DB::table('images as images')
                    ->leftJoin('managed_image_paths as registry', 'registry.path_hash', '=', 'images.managed_path_hash')
                    ->whereNotNull('images.managed_path_hash')
                    ->where('images.managed_path_hash', '<>', '')
                    ->whereNull('registry.id')
                    ->count();
                $this->add(
                    $findings,
                    'IMAGE_REGISTRY_MISSING',
                    'high',
                    $missingRegistry,
                    'Image records reference identities that are absent from the managed image registry.',
                    'Reconcile the affected image identities before enabling physical deletion.',
                );
                $readinessBlockers += $missingRegistry;

                $unsafeReferences = DB::table('images as images')
                    ->join('managed_image_paths as registry', 'registry.path_hash', '=', 'images.managed_path_hash')
                    ->where(static function ($query): void {
                        $query->whereNull('registry.state')->orWhere('registry.state', '<>', 'present');
                    })
                    ->count();
                $this->add(
                    $findings,
                    'IMAGE_REGISTRY_UNSAFE_STATE',
                    'high',
                    $unsafeReferences,
                    'Image records reference registry entries that are not in the present state.',
                    'Resolve missing, deleting, unknown, or invalid registry states before enabling deletion.',
                );
                $readinessBlockers += $unsafeReferences;
            }

            if ($registryReady) {
                $deletingStaleBefore = now()->subMinutes(max(
                    1,
                    (int) config('geoflow.security_audit_deleting_stale_minutes', 15),
                ));
                $orphanBefore = now()->subHours(max(
                    1,
                    (int) config('geoflow.security_audit_orphan_age_hours', 24),
                ));

                $staleDeleting = DB::table('managed_image_paths')
                    ->where('state', 'deleting')
                    ->where('updated_at', '<=', $deletingStaleBefore)
                    ->count();
                $this->add(
                    $findings,
                    'MANAGED_REGISTRY_STALE_DELETING',
                    'high',
                    $staleDeleting,
                    'Managed image registry entries have remained in the deleting state beyond the safety threshold.',
                    'Investigate interrupted deletion operations and reconcile each registry entry manually.',
                );
                $readinessBlockers += $staleDeleting;

                $invalidState = DB::table('managed_image_paths')
                    ->where(static function ($query): void {
                        $query->whereNull('state')->orWhereNotIn('state', ['present', 'missing', 'deleting']);
                    })
                    ->count();
                $this->add(
                    $findings,
                    'MANAGED_REGISTRY_INVALID_STATE',
                    'high',
                    $invalidState,
                    'Managed image registry entries contain an unknown or invalid state.',
                    'Reconcile registry state using the managed image recovery procedure.',
                );
                $readinessBlockers += $invalidState;

                $missingContentHashes = DB::table('managed_image_paths')
                    ->where(static function ($query): void {
                        $query->whereNull('content_sha256')->orWhere('content_sha256', '');
                    })
                    ->count();
                $this->add(
                    $findings,
                    'MANAGED_REGISTRY_CONTENT_HASH_MISSING',
                    'high',
                    $missingContentHashes,
                    'Managed image registry entries are missing their content identity.',
                    'Reconcile the affected files and registry entries before enabling physical deletion.',
                );
                $readinessBlockers += $missingContentHashes;

                $invalidContentHashes = $this->countInvalidRegistryContentHashes();
                $this->add(
                    $findings,
                    'MANAGED_REGISTRY_INVALID_CONTENT_HASH',
                    'high',
                    $invalidContentHashes,
                    'Managed image registry entries contain a malformed content identity.',
                    'Reconcile the affected files and registry entries before enabling physical deletion.',
                );
                $readinessBlockers += $invalidContentHashes;

                if ($imagesReady) {
                    $orphans = DB::table('managed_image_paths as registry')
                        ->leftJoin('images', 'images.managed_path_hash', '=', 'registry.path_hash')
                        ->where('registry.state', 'present')
                        ->where('registry.updated_at', '<=', $orphanBefore)
                        ->whereNull('images.id')
                        ->count();
                    $this->add(
                        $findings,
                        'MANAGED_REGISTRY_ORPHAN',
                        'medium',
                        $orphans,
                        'Long-lived present registry entries have no image record reference.',
                        'Review the orphaned managed files and remove them through the guarded cleanup workflow.',
                    );
                }
            }

            if ($idempotencyReady) {
                $invalidStates = DB::table('api_idempotency_keys')
                    ->where(static function ($query): void {
                        $query->whereNull('state')->orWhereNotIn('state', ['completed', 'in_progress']);
                    })
                    ->count();
                $this->add(
                    $findings,
                    'IDEMPOTENCY_INVALID_STATE',
                    'high',
                    $invalidStates,
                    'API idempotency records contain an invalid state.',
                    'Quarantine and reconcile the affected records before accepting related retries.',
                );

                $rowAudit = $this->auditIdempotencyRows();
                $this->add(
                    $findings,
                    'IDEMPOTENCY_INVALID_FINGERPRINT',
                    'high',
                    $rowAudit['invalid_fingerprints'],
                    'API idempotency records contain an invalid request fingerprint.',
                    'Reconcile or remove corrupted records after confirming the business operation result.',
                );

                $legacyFingerprints = DB::table('api_idempotency_keys')
                    ->where('fingerprint_version', 1)
                    ->count();
                $this->add(
                    $findings,
                    'IDEMPOTENCY_LEGACY_FINGERPRINT',
                    'medium',
                    $legacyFingerprints,
                    'Legacy idempotency fingerprints remain and cannot be safely replayed by the current API.',
                    'Let legacy keys expire or reconcile them manually; clients must use new idempotency keys.',
                );

                $this->add(
                    $findings,
                    'IDEMPOTENCY_INVALID_RESERVATION',
                    'high',
                    $rowAudit['invalid_reservations'],
                    'API idempotency owner, lease, state, and response fields form an invalid combination.',
                    'Confirm the operation result before repairing or removing the affected reservation.',
                );

                $expiredReservations = DB::table('api_idempotency_keys')
                    ->where('state', 'in_progress')
                    ->whereNotNull('lease_expires_at')
                    ->where('lease_expires_at', '<=', now())
                    ->count();
                $this->add(
                    $findings,
                    'IDEMPOTENCY_EXPIRED_RESERVATION',
                    'medium',
                    $expiredReservations,
                    'API idempotency reservations have expired while still in progress.',
                    'Confirm whether each operation completed before retrying or clearing its reservation.',
                );
            }

            if ((bool) config('geoflow.legacy_image_path_input', false)) {
                $this->add(
                    $findings,
                    'LEGACY_IMAGE_PATH_INPUT_ENABLED',
                    'high',
                    1,
                    'Legacy client-supplied image path input is enabled.',
                    'Set GEOFLOW_LEGACY_IMAGE_PATH_INPUT=false and use managed multipart uploads.',
                );
            }

            $readinessIncomplete = ! $imagesReady || ! $registryReady || $readinessBlockers > 0;
            if ((bool) config('geoflow.managed_image_deletion_enabled', false) && $readinessIncomplete) {
                $this->add(
                    $findings,
                    'MANAGED_IMAGE_DELETION_NOT_READY',
                    'critical',
                    max(1, $readinessBlockers),
                    'Physical managed image deletion is enabled while identity or registry readiness checks fail.',
                    'Disable deletion immediately, drain old processes, reconcile findings, and rerun readiness checks.',
                );
            }

            $privateTargets = config('geoflow.outbound_private_targets', []);
            $privateTargetCount = count(array_filter(
                is_array($privateTargets) ? $privateTargets : [$privateTargets],
                static fn (mixed $target): bool => is_scalar($target) && trim((string) $target) !== '',
            ));
            $this->add(
                $findings,
                'OUTBOUND_PRIVATE_TARGETS_CONFIGURED',
                'medium',
                $privateTargetCount,
                'Private outbound target exceptions are configured and require security review.',
                'Review every exact host:port exception and remove entries that are no longer required.',
            );

            return $this->report($findings);
        } catch (Throwable) {
            return $this->report([[
                'code' => 'SECURITY_AUDIT_INCOMPLETE',
                'severity' => 'critical',
                'count' => 1,
                'message' => 'The security audit could not complete safely.',
                'remediation' => 'Verify database connectivity and schema access, then rerun the audit before deployment.',
            ]]);
        }
    }

    /**
     * @param  list<array{code:string,severity:string,count:int,message:string,remediation:string}>  $findings
     * @param  list<string>  $requiredColumns
     */
    private function checkSchema(
        array &$findings,
        string $table,
        array $requiredColumns,
        string $code,
        string $message,
        string $remediation,
    ): bool {
        if (! Schema::hasTable($table)) {
            $this->add($findings, $code, 'critical', count($requiredColumns) + 1, $message, $remediation);

            return false;
        }

        $missingColumns = array_values(array_filter(
            $requiredColumns,
            static fn (string $column): bool => ! Schema::hasColumn($table, $column),
        ));
        $this->add($findings, $code, 'critical', count($missingColumns), $message, $remediation);

        return $missingColumns === [];
    }

    /**
     * @param  list<array{code:string,severity:string,count:int,message:string,remediation:string}>  $findings
     */
    private function add(
        array &$findings,
        string $code,
        string $severity,
        int $count,
        string $message,
        string $remediation,
    ): void {
        if ($count <= 0) {
            return;
        }

        $findings[] = compact('code', 'severity', 'count', 'message', 'remediation');
    }

    private function countInvalidRegistryContentHashes(): int
    {
        $count = 0;
        foreach (DB::table('managed_image_paths')
            ->whereNotNull('content_sha256')
            ->where('content_sha256', '<>', '')
            ->select(['id', 'content_sha256'])
            ->lazyById(self::AUDIT_CHUNK_SIZE) as $row) {
            if (preg_match('/^[a-f0-9]{64}$/D', (string) $row->content_sha256) !== 1) {
                $count++;
            }
        }

        return $count;
    }

    /**
     * @return array{invalid_fingerprints:int,invalid_reservations:int}
     */
    private function auditIdempotencyRows(): array
    {
        $invalidFingerprints = 0;
        $invalidReservations = 0;
        foreach (DB::table('api_idempotency_keys')
            ->select(['id', 'state', 'fingerprint_version', 'request_hash', 'owner_token', 'lease_expires_at', 'response_status'])
            ->lazyById(self::AUDIT_CHUNK_SIZE) as $row) {
            if (! in_array((int) $row->fingerprint_version, [1, 2], true)
                || preg_match('/^[a-f0-9]{64}$/D', (string) $row->request_hash) !== 1) {
                $invalidFingerprints++;
            }

            $invalid = $row->state === 'in_progress'
                ? (int) $row->fingerprint_version !== 2
                    || preg_match('/^[a-f0-9]{64}$/D', (string) $row->owner_token) !== 1
                    || $row->lease_expires_at === null
                    || (int) $row->response_status !== 0
                : ($row->state === 'completed'
                    && ($row->owner_token !== null || $row->lease_expires_at !== null));

            if ($invalid) {
                $invalidReservations++;
            }
        }

        return [
            'invalid_fingerprints' => $invalidFingerprints,
            'invalid_reservations' => $invalidReservations,
        ];
    }

    /**
     * @param  list<array{code:string,severity:string,count:int,message:string,remediation:string}>  $findings
     * @return array{
     *     schema_version:int,
     *     status:string,
     *     summary:array{critical:int,high:int,medium:int,low:int,total:int},
     *     findings:list<array{code:string,severity:string,count:int,message:string,remediation:string}>
     * }
     */
    private function report(array $findings): array
    {
        usort($findings, static fn (array $left, array $right): int => $left['code'] <=> $right['code']);

        $summary = array_fill_keys(self::SEVERITIES, 0);
        foreach ($findings as $finding) {
            $summary[$finding['severity']]++;
        }
        $summary['total'] = count($findings);

        return [
            'schema_version' => 1,
            'status' => $findings === [] ? 'ok' : 'findings',
            'summary' => $summary,
            'findings' => $findings,
        ];
    }
}
