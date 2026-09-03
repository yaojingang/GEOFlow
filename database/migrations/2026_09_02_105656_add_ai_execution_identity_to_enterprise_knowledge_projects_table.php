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
        Schema::table('enterprise_knowledge_projects', function (Blueprint $table) {
            $table->foreignId('model_access_admin_id')
                ->nullable()
                ->after('created_by_admin_id')
                ->constrained('admins')
                ->restrictOnDelete();
            $table->enum('model_access_admin_role', ['admin', 'super_admin'])->nullable()->after('model_access_admin_id');
            $table->unsignedBigInteger('ai_config_access_version')->nullable()->after('model_access_admin_role');
            $table->foreignId('requested_ai_model_id')
                ->nullable()
                ->after('ai_config_access_version')
                ->constrained('ai_models')
                ->nullOnDelete();
            $table->text('requested_ai_model_snapshot')->nullable()->after('requested_ai_model_id');
            $table->unsignedBigInteger('resolver_policy_version')->nullable()->after('requested_ai_model_snapshot');
            $table->foreignId('resolved_ai_model_id')
                ->nullable()
                ->after('resolver_policy_version')
                ->constrained('ai_models')
                ->nullOnDelete();
            $table->text('resolved_ai_model_snapshot')->nullable()->after('resolved_ai_model_id');
            $table->enum('resolved_model_source', ['personal', 'shared'])->nullable()->after('resolved_ai_model_snapshot');
            $table->timestamp('model_resolved_at')->nullable()->after('resolved_model_source');
            $table->uuid('execution_lease_token')->nullable()->after('model_resolved_at');
            $table->timestamp('lease_expires_at')->nullable()->after('execution_lease_token');
            $table->string('error_code', 100)->nullable()->after('lease_expires_at');
            $table->boolean('retryable_failure')->default(true)->after('error_code');

            $table->index(
                ['model_access_admin_id', 'status'],
                'enterprise_knowledge_projects_admin_status_idx',
            );
            $table->index(
                ['status', 'retryable_failure'],
                'enterprise_knowledge_projects_retryable_status_idx',
            );
            $table->index(
                ['status', 'lease_expires_at'],
                'enterprise_knowledge_projects_lease_status_idx',
            );
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('enterprise_knowledge_projects', function (Blueprint $table) {
            $table->dropIndex('enterprise_knowledge_projects_admin_status_idx');
            $table->dropIndex('enterprise_knowledge_projects_retryable_status_idx');
            $table->dropIndex('enterprise_knowledge_projects_lease_status_idx');
            $table->dropForeign(['model_access_admin_id']);
            $table->dropForeign(['requested_ai_model_id']);
            $table->dropForeign(['resolved_ai_model_id']);
            $table->dropColumn([
                'model_access_admin_id',
                'model_access_admin_role',
                'ai_config_access_version',
                'requested_ai_model_id',
                'requested_ai_model_snapshot',
                'resolver_policy_version',
                'resolved_ai_model_id',
                'resolved_ai_model_snapshot',
                'resolved_model_source',
                'model_resolved_at',
                'execution_lease_token',
                'lease_expires_at',
                'error_code',
                'retryable_failure',
            ]);
        });
    }
};
