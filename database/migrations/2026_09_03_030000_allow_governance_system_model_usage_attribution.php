<?php

use App\Services\Admin\AiModelUsageLedgerSchema;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        foreach (AiModelUsageLedgerSchema::governanceAttributionUpgradeStatements() as $statement) {
            DB::statement($statement);
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('ai_model_usage_events')) {
            return;
        }
        $hasImmutableGovernanceRows = DB::table('ai_model_usage_events')
            ->where('execution_scope', 'interactive_admin')
            ->where('model_source', 'system')
            ->exists();
        if ($hasImmutableGovernanceRows) {
            throw new RuntimeException(
                'Cannot restore the legacy AI usage attribution constraint while immutable governance system events exist.',
            );
        }

        foreach (AiModelUsageLedgerSchema::governanceAttributionDowngradeStatements() as $statement) {
            DB::statement($statement);
        }
    }
};
