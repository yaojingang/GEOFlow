<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('knowledge_fact_generation_runs', function (Blueprint $table) {
            $table->foreignId('model_access_admin_id')
                ->nullable()
                ->after('created_by_admin_id')
                ->constrained('admins')
                ->restrictOnDelete();
            $table->string('model_access_admin_role', 24)->nullable()->after('model_access_admin_id');
            $table->unsignedBigInteger('ai_config_access_version')->nullable()->after('model_access_admin_role');
            $table->foreignId('requested_ai_model_id')
                ->nullable()
                ->after('ai_config_access_version')
                ->constrained('ai_models')
                ->nullOnDelete();
            $table->foreignId('resolved_ai_model_id')
                ->nullable()
                ->after('requested_ai_model_id')
                ->constrained('ai_models')
                ->nullOnDelete();
            $table->string('resolved_model_source', 16)->nullable()->after('resolved_ai_model_id');
            $table->timestamp('model_resolved_at')->nullable()->after('resolved_model_source');
            $table->unsignedBigInteger('resolver_policy_version')->nullable()->after('model_resolved_at');
            $table->boolean('retryable_failure')->default(true)->after('error_code');
            $table->unsignedInteger('execution_attempt')->default(1)->after('retryable_failure');
            $table->longText('batch_claims_json')->nullable()->after('batch_meta_json');
            $table->uuid('finalizer_lease_token')->nullable()->after('batch_claims_json');
            $table->timestamp('finalizer_lease_expires_at')->nullable()->after('finalizer_lease_token');

            $table->index(
                ['model_access_admin_id', 'status'],
                'knowledge_fact_runs_admin_status_index',
            );
            $table->index(
                ['status', 'retryable_failure'],
                'knowledge_fact_runs_status_retryable_index',
            );
            $table->index(
                ['status', 'execution_attempt'],
                'knowledge_fact_runs_status_attempt_index',
            );
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('knowledge_fact_generation_runs', function (Blueprint $table) {
            $table->dropIndex('knowledge_fact_runs_admin_status_index');
            $table->dropIndex('knowledge_fact_runs_status_retryable_index');
            $table->dropIndex('knowledge_fact_runs_status_attempt_index');
            $table->dropConstrainedForeignId('model_access_admin_id');
            $table->dropConstrainedForeignId('requested_ai_model_id');
            $table->dropConstrainedForeignId('resolved_ai_model_id');
            $table->dropColumn([
                'model_access_admin_role',
                'ai_config_access_version',
                'resolved_model_source',
                'model_resolved_at',
                'resolver_policy_version',
                'retryable_failure',
                'execution_attempt',
                'batch_claims_json',
                'finalizer_lease_token',
                'finalizer_lease_expires_at',
            ]);
        });
    }
};
