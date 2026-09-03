<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ai_workspace_runs', function (Blueprint $table): void {
            $table->foreignId('model_access_admin_id')
                ->nullable()
                ->after('admin_auth_version')
                ->constrained('admins')
                ->restrictOnDelete();
            $table->enum('model_access_admin_role', ['admin', 'super_admin'])
                ->nullable()
                ->after('model_access_admin_id');
            $table->unsignedBigInteger('ai_config_access_version')
                ->nullable()
                ->after('model_access_admin_role');
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
            $table->enum('resolved_model_source', ['personal', 'shared'])
                ->nullable()
                ->after('resolved_ai_model_id');
            $table->timestamp('model_resolved_at')->nullable()->after('resolved_model_source');
            $table->unsignedBigInteger('resolver_policy_version')->nullable()->after('model_resolved_at');
            $table->uuid('execution_lease_token')->nullable()->after('resolver_policy_version');
            $table->timestamp('execution_lease_expires_at')->nullable()->after('execution_lease_token');
            $table->boolean('retryable_failure')->default(true)->after('failure_message');

            $table->index(
                ['model_access_admin_id', 'state'],
                'ai_workspace_runs_model_admin_state_index',
            );
            $table->index(
                ['state', 'execution_lease_token'],
                'ai_workspace_runs_state_execution_lease_index',
            );
        });
    }

    public function down(): void
    {
        Schema::table('ai_workspace_runs', function (Blueprint $table): void {
            $table->dropIndex('ai_workspace_runs_model_admin_state_index');
            $table->dropIndex('ai_workspace_runs_state_execution_lease_index');
            $table->dropForeign(['model_access_admin_id']);
            $table->dropForeign(['requested_ai_model_id']);
            $table->dropForeign(['resolved_ai_model_id']);
            $table->dropColumn([
                'model_access_admin_id',
                'model_access_admin_role',
                'ai_config_access_version',
                'requested_ai_model_id',
                'resolved_ai_model_id',
                'resolved_model_source',
                'model_resolved_at',
                'resolver_policy_version',
                'execution_lease_token',
                'execution_lease_expires_at',
                'retryable_failure',
            ]);
        });
    }
};
