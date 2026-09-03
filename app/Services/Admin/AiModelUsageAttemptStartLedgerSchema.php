<?php

namespace App\Services\Admin;

use Illuminate\Support\Facades\DB;

final class AiModelUsageAttemptStartLedgerSchema
{
    public static function install(): void
    {
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
            default => [],
        };
    }
}
