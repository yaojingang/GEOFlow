<?php

namespace Tests\Unit;

use App\Services\Admin\AiModelUsageAttemptStartLedgerSchema;
use App\Services\Admin\AiModelUsageLedgerSchema;
use PHPUnit\Framework\TestCase;

final class MySqlUsageLedgerCompatibilityTest extends TestCase
{
    public function test_mysql_usage_event_schema_uses_checks_and_signal_triggers(): void
    {
        $install = implode("\n", AiModelUsageLedgerSchema::mysqlInstallStatements());
        $uninstall = implode("\n", AiModelUsageLedgerSchema::mysqlUninstallStatements());

        self::assertStringContainsString('ai_model_usage_values_nonnegative', $install);
        self::assertStringContainsString('input_tokens IS NULL OR input_tokens >= 0', $install);
        self::assertStringContainsString('estimated_cost IS NULL OR estimated_cost >= 0', $install);
        self::assertStringContainsString('ai_model_usage_attribution_valid', $install);
        self::assertStringContainsString('execution_scope = \'interactive_admin\'', $install);
        self::assertStringContainsString('model_source IN (\'personal\', \'shared\', \'system\')', $install);
        self::assertStringContainsString('REGEXP_LIKE(request_payload_digest, \'^[a-f0-9]{64}$\', \'c\')', $install);
        self::assertStringContainsString('BEFORE UPDATE ON ai_model_usage_events', $install);
        self::assertStringContainsString('BEFORE DELETE ON ai_model_usage_events', $install);
        self::assertStringContainsString("SIGNAL SQLSTATE '45000'", $install);
        self::assertStringContainsString('DROP TRIGGER IF EXISTS ai_model_usage_events_append_only_update', $install);
        self::assertStringContainsString('DROP TRIGGER IF EXISTS ai_model_usage_events_append_only_delete', $install);
        self::assertStringContainsString('DROP TRIGGER IF EXISTS ai_model_usage_events_append_only_update', $uninstall);
        self::assertStringContainsString('DROP TRIGGER IF EXISTS ai_model_usage_events_append_only_delete', $uninstall);
    }

    public function test_mysql_attempt_start_schema_uses_attribution_digest_and_signal_triggers(): void
    {
        $install = implode("\n", AiModelUsageAttemptStartLedgerSchema::mysqlInstallStatements());
        $uninstall = implode("\n", AiModelUsageAttemptStartLedgerSchema::mysqlUninstallStatements());

        self::assertStringContainsString('ai_model_usage_attempt_start_attribution_valid', $install);
        self::assertStringContainsString('ai_model_usage_attempt_start_request_digest_valid', $install);
        self::assertStringContainsString('REGEXP_LIKE(request_payload_digest, \'^[a-f0-9]{64}$\', \'c\')', $install);
        self::assertStringContainsString('BEFORE UPDATE ON ai_model_usage_attempt_starts', $install);
        self::assertStringContainsString('BEFORE DELETE ON ai_model_usage_attempt_starts', $install);
        self::assertStringContainsString("SIGNAL SQLSTATE '45000'", $install);
        self::assertStringContainsString('DROP TRIGGER IF EXISTS ai_model_usage_attempt_starts_append_only_update', $uninstall);
        self::assertStringContainsString('DROP TRIGGER IF EXISTS ai_model_usage_attempt_starts_append_only_delete', $uninstall);
    }

    public function test_governance_migration_replaces_mysql_attribution_without_postgresql_json_syntax(): void
    {
        $ledger = $this->source('app/Services/Admin/AiModelUsageLedgerSchema.php');
        $migration = $this->source(
            'database/migrations/2026_09_03_030000_allow_governance_system_model_usage_attribution.php',
        );

        self::assertStringContainsString("'mysql' => self::mysqlGovernanceAttributionStatements(false)", $ledger);
        self::assertStringContainsString("'mysql' => self::mysqlGovernanceAttributionStatements(true)", $ledger);
        self::assertStringContainsString('INFORMATION_SCHEMA.TABLE_CONSTRAINTS', $ledger);
        self::assertStringContainsString('DROP CHECK ai_model_usage_attribution_valid', $ledger);
        self::assertStringContainsString('AiModelUsageLedgerSchema::governanceAttributionUpgradeStatements()', $migration);
        self::assertStringNotContainsString('::jsonb', $migration);
    }

    public function test_reconcile_job_keeps_mysql_json_extract_separate_from_postgresql_jsonb(): void
    {
        $job = $this->source('app/Jobs/ReconcileArticleAiQualityJob.php');
        $mysqlBranchOffset = strpos($job, "'mysql', 'mariadb'");

        self::assertNotFalse($mysqlBranchOffset);
        $mysqlBranch = substr($job, $mysqlBranchOffset, 220);
        self::assertStringContainsString('JSON_UNQUOTE(JSON_EXTRACT(', $mysqlBranch);
        self::assertStringNotContainsString('::jsonb', $mysqlBranch);
        self::assertStringContainsString("'pgsql' =>", $job);
        self::assertStringContainsString('advertising_rules_snapshot::jsonb', $job);
        self::assertStringContainsString('json_extract(article_ai_quality_checks.advertising_rules_snapshot', $job);
    }

    public function test_existing_postgresql_and_sqlite_ledger_paths_are_preserved(): void
    {
        $ledger = $this->source('app/Services/Admin/AiModelUsageLedgerSchema.php');
        $attemptStarts = $this->source('app/Services/Admin/AiModelUsageAttemptStartLedgerSchema.php');

        self::assertStringContainsString("'sqlite' => self::sqliteInstallStatements()", $ledger);
        self::assertStringContainsString("'pgsql' => self::postgresInstallStatements()", $ledger);
        self::assertStringContainsString('BEGIN SELECT RAISE(ABORT', $attemptStarts);
        self::assertStringContainsString("'pgsql' => [", $attemptStarts);
        self::assertStringContainsString('LANGUAGE plpgsql', $ledger);
        self::assertStringContainsString('LANGUAGE plpgsql', $attemptStarts);
        self::assertStringContainsString('BEGIN SELECT RAISE(ABORT', $ledger);
        self::assertStringContainsString('BEGIN SELECT RAISE(ABORT', $attemptStarts);
    }

    private function source(string $relativePath): string
    {
        $contents = file_get_contents(dirname(__DIR__, 2).'/'.$relativePath);

        self::assertIsString($contents);

        return $contents;
    }
}
