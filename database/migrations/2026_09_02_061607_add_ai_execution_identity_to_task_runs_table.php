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
        Schema::table('task_runs', function (Blueprint $table) {
            $table->foreignId('model_access_admin_id')
                ->nullable()
                ->constrained('admins')
                ->restrictOnDelete();
            $table->enum('model_access_admin_role', ['admin', 'super_admin'])
                ->nullable();
            $table->unsignedBigInteger('ai_config_access_version')->nullable();
            $table->foreignId('requested_ai_model_id')
                ->nullable()
                ->constrained('ai_models')
                ->nullOnDelete();
            $table->foreignId('resolved_ai_model_id')
                ->nullable()
                ->constrained('ai_models')
                ->nullOnDelete();
            $table->enum('resolved_model_source', ['personal', 'shared', 'system'])
                ->nullable();
            $table->timestamp('model_resolved_at')->nullable();
            $table->unsignedBigInteger('resolver_policy_version')->nullable();
            $table->string('error_code', 100)->nullable();

            $table->index(
                ['model_access_admin_id', 'status'],
                'task_runs_model_access_status_index',
            );
            $table->index(
                ['resolved_ai_model_id', 'model_resolved_at'],
                'task_runs_resolved_model_index',
            );
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('task_runs', function (Blueprint $table) {
            $table->dropIndex('task_runs_model_access_status_index');
            $table->dropIndex('task_runs_resolved_model_index');
            $table->dropConstrainedForeignId('model_access_admin_id');
            $table->dropConstrainedForeignId('requested_ai_model_id');
            $table->dropConstrainedForeignId('resolved_ai_model_id');
            $table->dropColumn([
                'model_access_admin_role',
                'ai_config_access_version',
                'resolved_model_source',
                'model_resolved_at',
                'resolver_policy_version',
                'error_code',
            ]);
        });
    }
};
