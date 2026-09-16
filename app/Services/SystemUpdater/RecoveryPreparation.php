<?php

namespace App\Services\SystemUpdater;

use App\Models\Admin;
use App\Models\SiteThemeBinding;
use App\Models\ThemeRelease;
use App\Models\ThemeRevision;
use App\Models\ThemeWorkspace;
use App\Services\Api\ThemeRevisionStorage;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;

/** Fixed host-only recovery steps. The host state remains the authority for reopening services. */
final class RecoveryPreparation
{
    public const INTENTS = [
        'jobs', 'failed_jobs', 'manual_publications', 'job_batches', 'site_theme_replications', 'ai_workspace_steps', 'ai_workspace_external_operations', 'task_runs', 'article_distributions', 'tasks',
        'url_import_jobs', 'ai_workspace_runs', 'article_ai_quality_checks', 'article_ai_optimization_runs',
        'title_generation_runs', 'knowledge_fact_generation_runs', 'ai_visibility_runs',
        'enterprise_knowledge_projects', 'knowledge_bases', 'url_change_requests',
        'hosted_site_allocation_requests', 'hosted_site_article_assignments',
    ];

    public function __construct(private readonly RecoveryState $state, private readonly ThemeRevisionStorage $themes) {}

    public function inspect(): array
    {
        return ['schema_version' => 1, 'status' => 'pass', 'admin_digest' => $this->adminDigest(), 'theme_revisions' => $this->verifyThemes()];
    }

    public function prepare(string $transaction, string $expectedAdminDigest): array
    {
        $state = $this->boundary($transaction, ['validating']);
        if (preg_match('/^[a-f0-9]{64}$/D', $expectedAdminDigest) !== 1) {
            throw new RuntimeException('recovery_admin_review_required');
        }

        return DB::transaction(function () use ($state, $transaction, $expectedAdminDigest): array {
            // The isolated host process has drained all writers. Row locks also serialize accidental duplicate preparation.
            Admin::query()->orderBy('id')->lockForUpdate()->get(['id']);
            $existing = DB::table('recovery_preparations')->where('epoch', $state['epoch'])->first();
            if ($existing !== null) {
                if ($existing->transaction_id !== $transaction || ! hash_equals($existing->admin_digest_before, $expectedAdminDigest)) {
                    throw new RuntimeException('recovery_preparation_identity_conflict');
                }
                $this->verify($transaction);

                return json_decode($existing->report, true, 32, JSON_THROW_ON_ERROR);
            }
            if (! hash_equals($expectedAdminDigest, $this->adminDigest())) {
                throw new RuntimeException('recovery_admin_review_required');
            }
            $revisions = $this->verifyThemes();
            $count = $this->quarantine($state['epoch']);
            DB::table('personal_access_tokens')->delete();
            DB::table('admins')->update(['remember_token' => null, 'remember_recovery_epoch' => null, 'auth_version' => DB::raw('auth_version + 1')]);
            ThemeWorkspace::query()->update(['code_token_id' => null, 'code_authorized_until' => null, 'code_recovery_epoch' => null, 'plan' => null]);
            $report = [
                'schema_version' => 1, 'status' => 'pass', 'transaction_id' => $transaction, 'epoch' => $state['epoch'],
                'credentials_invalidated' => true, 'administrators_verified' => true, 'theme_revisions' => $revisions,
                'background_status' => 'held', 'quarantine_count' => $count, 'quarantine_sha256' => $this->quarantineDigest($state['epoch'], false),
            ];
            DB::table('recovery_preparations')->insert([
                'epoch' => $state['epoch'], 'transaction_id' => $transaction,
                'admin_digest_before' => $expectedAdminDigest, 'admin_digest_after' => $this->adminDigest(),
                'report' => json_encode($report, JSON_THROW_ON_ERROR), 'created_at' => now(),
            ]);
            $this->boundary($transaction, ['validating']);

            return $report;
        });
    }

    public function verify(string $transaction): array
    {
        $state = $this->boundary($transaction, ['validating']);
        $prepared = DB::table('recovery_preparations')->where('epoch', $state['epoch'])->first();
        if ($prepared === null || $prepared->transaction_id !== $transaction
            || ! hash_equals($prepared->admin_digest_after, $this->adminDigest())
            || DB::table('personal_access_tokens')->exists()
            || DB::table('admins')->whereNotNull('remember_token')->exists()
            || ThemeWorkspace::query()->whereNotNull('code_token_id')->exists()) {
            throw new RuntimeException('recovery_preparation_unverified');
        }
        $this->verifyThemes();
        $report = json_decode($prepared->report, true, 32, JSON_THROW_ON_ERROR);
        if (DB::table('recovery_quarantines')->where('epoch', $state['epoch'])->where('status', 'held')->count() !== $report['quarantine_count']
            || ! hash_equals($report['quarantine_sha256'], $this->quarantineDigest($state['epoch'], true))) {
            throw new RuntimeException('recovery_quarantine_changed');
        }

        return $report;
    }

    private function boundary(string $transaction, array $phases): array
    {
        $state = $this->state->snapshot();
        if ($state === null || ! in_array($state['phase'], $phases, true)
            || ($state['transaction_id'] ?? null) !== $transaction
            || preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]{7,127}$/D', $transaction) !== 1) {
            throw new RuntimeException('recovery_prepare_boundary_invalid');
        }

        return $state;
    }

    private function adminDigest(): string
    {
        $hash = hash_init('sha256');
        foreach (DB::table('admins')->orderBy('id')->select(['id', 'username', 'email', 'password', 'role', 'status', 'auth_version', 'created_at'])->cursor() as $admin) {
            hash_update($hash, json_encode((array) $admin, JSON_THROW_ON_ERROR)."\n");
        }

        return hash_final($hash);
    }

    private function quarantine(string $epoch): int
    {
        $count = 0;
        foreach (self::INTENTS as $table) {
            if (! Schema::hasTable($table)) {
                throw new RuntimeException('recovery_intent_schema_missing');
            }
            $columns = Schema::getColumnListing($table);
            $safe = array_values(array_intersect(['id', 'status', 'state', 'chunk_sync_status', 'schedule_enabled', 'next_run_at', 'next_publish_at', 'article_id', 'distribution_channel_id', 'task_id', 'idempotency_key', 'remote_id'], $columns));
            $query = DB::table($table)->orderBy('id');
            // Completed rows can still contain unapplied follow-up intents. Preserve every source identity.
            foreach ($query->cursor() as $intent) {
                DB::table('recovery_quarantines')->insert([
                    'epoch' => $epoch, 'source_table' => $table, 'source_id' => (string) $intent->id, 'status' => 'held',
                    'summary' => json_encode(array_intersect_key((array) $intent, array_flip($safe)), JSON_THROW_ON_ERROR),
                    'source_sha256' => $this->sourceDigest($intent),
                ]);
                $count++;
            }
        }

        return $count;
    }

    private function sourceDigest(object $source): string
    {
        $fields = (array) $source;
        ksort($fields);

        return hash('sha256', json_encode($fields, JSON_THROW_ON_ERROR));
    }

    private function quarantineDigest(string $epoch, bool $verifySources): string
    {
        $hash = hash_init('sha256');
        if ($verifySources) {
            foreach (self::INTENTS as $table) {
                if (DB::table($table)->count() !== DB::table('recovery_quarantines')->where('epoch', $epoch)->where('source_table', $table)->count()) {
                    throw new RuntimeException('recovery_quarantine_changed');
                }
            }
        }
        foreach (DB::table('recovery_quarantines')->where('epoch', $epoch)->orderBy('source_table')->orderBy('source_id')->cursor() as $entry) {
            if (! in_array($entry->source_table, self::INTENTS, true)) {
                throw new RuntimeException('recovery_quarantine_changed');
            }
            if ($verifySources) {
                $source = DB::table($entry->source_table)->where('id', $entry->source_id)->first();
                if ($source === null || ! hash_equals($entry->source_sha256, $this->sourceDigest($source))) {
                    throw new RuntimeException('recovery_quarantine_changed');
                }
            }
            hash_update($hash, json_encode([$entry->source_table, $entry->source_id, $entry->source_sha256, json_decode($entry->summary, true, 32, JSON_THROW_ON_ERROR), $entry->status], JSON_THROW_ON_ERROR)."\n");
        }

        return hash_final($hash);
    }

    private function verifyThemes(): int
    {
        foreach (ThemeWorkspace::query()->where('state', 'draft')->cursor() as $workspace) {
            if ($workspace->revision_id === null || ! ThemeRevision::query()->whereKey($workspace->revision_id)->where('workspace_id', $workspace->id)->where('theme_id', $workspace->theme_id)->where('state', 'ready')->exists()) {
                throw new RuntimeException('recovery_theme_reference_invalid');
            }
        }
        foreach (SiteThemeBinding::query()->whereNotNull('revision_id')->cursor() as $binding) {
            if (! ThemeRevision::query()->whereKey($binding->revision_id)->where('theme_id', $binding->theme_id)->where('state', 'ready')->exists()) {
                throw new RuntimeException('recovery_theme_reference_invalid');
            }
        }
        foreach (ThemeRelease::query()->cursor() as $release) {
            if (! ThemeRevision::query()->whereKey($release->revision_id)->where('workspace_id', $release->workspace_id)->where('state', 'ready')->exists()
                || ($release->previous_revision_id !== null && ! ThemeRevision::query()->whereKey($release->previous_revision_id)->where('state', 'ready')->exists())) {
                throw new RuntimeException('recovery_theme_reference_invalid');
            }
        }
        $count = 0;
        foreach (ThemeRevision::query()->where('state', 'ready')->cursor() as $revision) {
            $expected = ['id' => $revision->id, 'files' => $revision->files, 'content_sha256' => $revision->content_sha256];
            $manifest = $this->themes->read($this->themes->storage->path('revisions/'.$revision->id.'/revision.json'), 8388608);
            if (json_decode($manifest, true) !== $expected
                || ! hash_equals($revision->content_sha256, hash('sha256', json_encode([$revision->files, $revision->settings], JSON_THROW_ON_ERROR)))
                || array_sum(array_column($revision->files, 'bytes')) !== $revision->total_bytes) {
                throw new RuntimeException('recovery_theme_manifest_invalid');
            }
            $this->themes->contents($revision);
            $count++;
        }

        return $count;
    }
}
