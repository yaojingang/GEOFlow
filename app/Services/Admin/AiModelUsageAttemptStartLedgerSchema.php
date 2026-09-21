<?php

namespace App\Services\Admin;

use Illuminate\Support\Facades\DB;

final class AiModelUsageAttemptStartLedgerSchema
{
    /** @var list<string> */
    private const MYSQL_CHECK_CONSTRAINTS = [
        'ai_model_usage_attempt_start_attribution_valid',
        'ai_model_usage_attempt_start_request_digest_valid',
    ];

    public static function install(): void
    {
        if (DB::getDriverName() === 'mysql') {
            self::dropExistingMySqlCheckConstraints();
        }

        foreach (self::installStatements() as $statement) {
            DB::statement($statement);
        }
    }

    public static function uninstall(): void
    {
        foreach (self::uninstallStatements() as $statement) {
            DB::statement($statement);
        }
    }

    /** @return list<string> */
    public static function mysqlInstallStatements(): array
    {
        return [
            'ALTER TABLE ai_model_usage_attempt_starts ADD CONSTRAINT '
                .'ai_model_usage_attempt_start_attribution_valid '
                ."CHECK ((execution_scope = 'system' AND execution_admin_id IS NULL "
                ."AND model_source = 'system' AND ai_config_access_version = 0) "
                ."OR (execution_scope = 'interactive_admin' AND execution_admin_id IS NOT NULL "
                ."AND model_source IN ('personal', 'shared', 'system') AND ai_config_access_version >= 1) "
                ."OR (execution_scope = 'persisted_admin' AND execution_admin_id IS NOT NULL "
                ."AND model_source IN ('personal', 'shared') AND ai_config_access_version >= 1))",
            'ALTER TABLE ai_model_usage_attempt_starts ADD CONSTRAINT '
                .'ai_model_usage_attempt_start_request_digest_valid '
                ."CHECK (REGEXP_LIKE(request_payload_digest, '^[a-f0-9]{64}$', 'c'))",
            'DROP TRIGGER IF EXISTS ai_model_usage_attempt_starts_append_only_update',
            'DROP TRIGGER IF EXISTS ai_model_usage_attempt_starts_append_only_delete',
            'CREATE TRIGGER ai_model_usage_attempt_starts_append_only_update '
                .'BEFORE UPDATE ON ai_model_usage_attempt_starts FOR EACH ROW '
                ."SIGNAL SQLSTATE '45000' "
                ."SET MESSAGE_TEXT = 'AI model usage attempt starts are append-only'",
            'CREATE TRIGGER ai_model_usage_attempt_starts_append_only_delete '
                .'BEFORE DELETE ON ai_model_usage_attempt_starts FOR EACH ROW '
                ."SIGNAL SQLSTATE '45000' "
                ."SET MESSAGE_TEXT = 'AI model usage attempt starts are append-only'",
        ];
    }

    /** @return list<string> */
    public static function mysqlUninstallStatements(): array
    {
        return [
            'DROP TRIGGER IF EXISTS ai_model_usage_attempt_starts_append_only_update',
            'DROP TRIGGER IF EXISTS ai_model_usage_attempt_starts_append_only_delete',
        ];
    }

    /** @return list<string> */
    private static function installStatements(): array
    {
        return match (DB::getDriverName()) {
            'sqlite' => [
                'CREATE TRIGGER ai_model_usage_attempt_starts_append_only_update '
                    .'BEFORE UPDATE ON ai_model_usage_attempt_starts '
                    ."BEGIN SELECT RAISE(ABORT, 'AI model usage attempt starts are append-only'); END",
                'CREATE TRIGGER ai_model_usage_attempt_starts_append_only_delete '
                    .'BEFORE DELETE ON ai_model_usage_attempt_starts '
                    ."BEGIN SELECT RAISE(ABORT, 'AI model usage attempt starts are append-only'); END",
            ],
            'pgsql' => [
                'CREATE FUNCTION geoflow_reject_ai_model_usage_attempt_start_mutation() RETURNS trigger '
                    .'LANGUAGE plpgsql AS $$ BEGIN '
                    ."RAISE EXCEPTION 'AI model usage attempt starts are append-only'; END; $$",
                'CREATE TRIGGER ai_model_usage_attempt_starts_append_only '
                    .'BEFORE UPDATE OR DELETE ON ai_model_usage_attempt_starts FOR EACH ROW '
                    .'EXECUTE FUNCTION geoflow_reject_ai_model_usage_attempt_start_mutation()',
            ],
            'mysql' => self::mysqlInstallStatements(),
            default => [],
        };
    }

    /** @return list<string> */
    private static function uninstallStatements(): array
    {
        return match (DB::getDriverName()) {
            'sqlite' => [
                'DROP TRIGGER IF EXISTS ai_model_usage_attempt_starts_append_only_update',
                'DROP TRIGGER IF EXISTS ai_model_usage_attempt_starts_append_only_delete',
            ],
            'pgsql' => [
                'DROP TRIGGER IF EXISTS ai_model_usage_attempt_starts_append_only ON ai_model_usage_attempt_starts',
                'DROP FUNCTION IF EXISTS geoflow_reject_ai_model_usage_attempt_start_mutation()',
            ],
            'mysql' => self::mysqlUninstallStatements(),
            default => [],
        };
    }

    private static function dropExistingMySqlCheckConstraints(): void
    {
        foreach (self::MYSQL_CHECK_CONSTRAINTS as $constraint) {
            if (self::mysqlCheckConstraintExists($constraint)) {
                DB::statement('ALTER TABLE ai_model_usage_attempt_starts DROP CHECK '.$constraint);
            }
        }
    }

    private static function mysqlCheckConstraintExists(string $constraint): bool
    {
        return DB::selectOne(
            'SELECT 1 FROM INFORMATION_SCHEMA.TABLE_CONSTRAINTS '
                .'WHERE TABLE_SCHEMA = DATABASE() '
                .'AND TABLE_NAME = ? '
                .'AND CONSTRAINT_NAME = ? '
                ."AND CONSTRAINT_TYPE = 'CHECK' LIMIT 1",
            ['ai_model_usage_attempt_starts', $constraint],
        ) !== null;
    }
}
