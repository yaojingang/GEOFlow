<?php

namespace App\Services\Admin;

use Illuminate\Support\Facades\DB;

final class AiModelUsageLedgerSchema
{
    public static function install(): void
    {
        $statements = match (DB::getDriverName()) {
            'sqlite' => self::sqliteInstallStatements(),
            'pgsql' => self::postgresInstallStatements(),
            default => [],
        };

        foreach ($statements as $statement) {
            DB::statement($statement);
        }
    }

    public static function uninstall(): void
    {
        $statements = match (DB::getDriverName()) {
            'sqlite' => self::sqliteUninstallStatements(),
            'pgsql' => self::postgresUninstallStatements(),
            default => [],
        };

        foreach ($statements as $statement) {
            DB::statement($statement);
        }
    }

    /** @return list<string> */
    public static function postgresInstallStatements(): array
    {
        return [
            'ALTER TABLE ai_model_usage_events ADD CONSTRAINT ai_model_usage_values_nonnegative '
                .'CHECK ((input_tokens IS NULL OR input_tokens >= 0) '
                .'AND (output_tokens IS NULL OR output_tokens >= 0) '
                .'AND (total_tokens IS NULL OR total_tokens >= 0) '
                .'AND (estimated_cost IS NULL OR estimated_cost >= 0))',
            self::postgresAttributionConstraint(),
            'ALTER TABLE ai_model_usage_events ADD CONSTRAINT ai_model_usage_request_digest_valid '
                .'CHECK (request_payload_digest ~ \'^[a-f0-9]{64}$\')',
            'CREATE FUNCTION geoflow_reject_ai_model_usage_event_mutation() RETURNS trigger '
                .'LANGUAGE plpgsql AS $$ BEGIN '
                ."RAISE EXCEPTION 'AI model usage events are append-only'; "
                .'END; $$',
            'CREATE TRIGGER ai_model_usage_events_append_only '
                .'BEFORE UPDATE OR DELETE ON ai_model_usage_events FOR EACH ROW '
                .'EXECUTE FUNCTION geoflow_reject_ai_model_usage_event_mutation()',
        ];
    }

    /** @return list<string> */
    public static function postgresUninstallStatements(): array
    {
        return [
            'DROP TRIGGER IF EXISTS ai_model_usage_events_append_only ON ai_model_usage_events',
            'DROP FUNCTION IF EXISTS geoflow_reject_ai_model_usage_event_mutation()',
        ];
    }

    /** @return list<string> */
    private static function sqliteInstallStatements(): array
    {
        return [
            self::sqliteValuesInsertTrigger(self::sqliteAttributionExpression()),
            'CREATE TRIGGER ai_model_usage_events_append_only_update '
                .'BEFORE UPDATE ON ai_model_usage_events '
                ."BEGIN SELECT RAISE(ABORT, 'AI model usage events are append-only'); END",
            'CREATE TRIGGER ai_model_usage_events_append_only_delete '
                .'BEFORE DELETE ON ai_model_usage_events '
                ."BEGIN SELECT RAISE(ABORT, 'AI model usage events are append-only'); END",
        ];
    }

    /** @return list<string> */
    private static function sqliteUninstallStatements(): array
    {
        return [
            'DROP TRIGGER IF EXISTS ai_model_usage_values_insert',
            'DROP TRIGGER IF EXISTS ai_model_usage_events_append_only_update',
            'DROP TRIGGER IF EXISTS ai_model_usage_events_append_only_delete',
        ];
    }

    /** @return list<string> */
    public static function governanceAttributionUpgradeStatements(): array
    {
        return match (DB::getDriverName()) {
            'pgsql' => [
                'ALTER TABLE ai_model_usage_events DROP CONSTRAINT IF EXISTS ai_model_usage_attribution_valid',
                self::postgresAttributionConstraint(),
            ],
            'sqlite' => [
                'DROP TRIGGER IF EXISTS ai_model_usage_values_insert',
                self::sqliteValuesInsertTrigger(self::sqliteAttributionExpression()),
            ],
            default => [],
        };
    }

    /** @return list<string> */
    public static function governanceAttributionDowngradeStatements(): array
    {
        return match (DB::getDriverName()) {
            'pgsql' => [
                'ALTER TABLE ai_model_usage_events DROP CONSTRAINT IF EXISTS ai_model_usage_attribution_valid',
                self::postgresLegacyAttributionConstraint(),
            ],
            'sqlite' => [
                'DROP TRIGGER IF EXISTS ai_model_usage_values_insert',
                self::sqliteValuesInsertTrigger(self::sqliteLegacyAttributionExpression()),
            ],
            default => [],
        };
    }

    private static function postgresAttributionConstraint(): string
    {
        return 'ALTER TABLE ai_model_usage_events ADD CONSTRAINT ai_model_usage_attribution_valid '
            .'CHECK ((execution_scope = \'system\' AND execution_admin_id IS NULL '
            .'AND model_source = \'system\' AND ai_config_access_version = 0) '
            .'OR (execution_scope = \'interactive_admin\' AND execution_admin_id IS NOT NULL '
            .'AND model_source IN (\'personal\', \'shared\', \'system\') AND ai_config_access_version >= 1) '
            .'OR (execution_scope = \'persisted_admin\' AND execution_admin_id IS NOT NULL '
            .'AND model_source IN (\'personal\', \'shared\') AND ai_config_access_version >= 1))';
    }

    private static function sqliteAttributionExpression(): string
    {
        return "((NEW.execution_scope = 'system' AND NEW.execution_admin_id IS NULL "
            ."AND NEW.model_source = 'system' AND NEW.ai_config_access_version = 0) "
            ."OR (NEW.execution_scope = 'interactive_admin' AND NEW.execution_admin_id IS NOT NULL "
            ."AND NEW.model_source IN ('personal', 'shared', 'system') AND NEW.ai_config_access_version >= 1) "
            ."OR (NEW.execution_scope = 'persisted_admin' AND NEW.execution_admin_id IS NOT NULL "
            ."AND NEW.model_source IN ('personal', 'shared') AND NEW.ai_config_access_version >= 1))";
    }

    private static function postgresLegacyAttributionConstraint(): string
    {
        return 'ALTER TABLE ai_model_usage_events ADD CONSTRAINT ai_model_usage_attribution_valid '
            .'CHECK ((execution_scope = \'system\' AND execution_admin_id IS NULL '
            .'AND model_source = \'system\' AND ai_config_access_version = 0) '
            .'OR (execution_scope IN (\'interactive_admin\', \'persisted_admin\') '
            .'AND execution_admin_id IS NOT NULL AND model_source IN (\'personal\', \'shared\') '
            .'AND ai_config_access_version >= 1))';
    }

    private static function sqliteLegacyAttributionExpression(): string
    {
        return "((NEW.execution_scope = 'system' AND NEW.execution_admin_id IS NULL "
            ."AND NEW.model_source = 'system' AND NEW.ai_config_access_version = 0) "
            ."OR (NEW.execution_scope IN ('interactive_admin', 'persisted_admin') "
            ."AND NEW.execution_admin_id IS NOT NULL AND NEW.model_source IN ('personal', 'shared') "
            .'AND NEW.ai_config_access_version >= 1))';
    }

    private static function sqliteValuesInsertTrigger(string $attribution): string
    {
        $nonnegative = '(NEW.input_tokens IS NULL OR NEW.input_tokens >= 0) '
            .'AND (NEW.output_tokens IS NULL OR NEW.output_tokens >= 0) '
            .'AND (NEW.total_tokens IS NULL OR NEW.total_tokens >= 0) '
            .'AND (NEW.estimated_cost IS NULL OR NEW.estimated_cost >= 0)';
        $digest = 'length(NEW.request_payload_digest) = 64 '
            ."AND NEW.request_payload_digest NOT GLOB '*[^0-9a-f]*'";

        return 'CREATE TRIGGER ai_model_usage_values_insert BEFORE INSERT ON ai_model_usage_events '
            .'WHEN NOT ('.$nonnegative.' AND '.$attribution.' AND '.$digest.') '
            ."BEGIN SELECT RAISE(ABORT, 'invalid AI model usage event'); END";
    }
}
