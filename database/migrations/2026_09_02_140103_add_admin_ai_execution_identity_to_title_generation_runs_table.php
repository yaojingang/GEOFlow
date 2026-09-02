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
        Schema::table('title_generation_runs', function (Blueprint $table) {
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
            $table->foreignId('resolved_ai_model_id')
                ->nullable()
                ->after('requested_ai_model_id')
                ->constrained('ai_models')
                ->nullOnDelete();
            $table->enum('resolved_model_source', ['personal', 'shared'])->nullable()->after('resolved_ai_model_id');
            $table->timestamp('model_resolved_at')->nullable()->after('resolved_model_source');
            $table->unsignedBigInteger('resolver_policy_version')->nullable()->after('model_resolved_at');
            $table->string('error_code', 100)->nullable()->after('failure_code');
            $table->boolean('retryable_failure')->default(true)->after('error_code');

            $table->index(
                ['model_access_admin_id', 'status'],
                'title_generation_runs_admin_status_index',
            );
            $table->index(
                ['status', 'retryable_failure'],
                'title_generation_runs_status_retryable_index',
            );
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('title_generation_runs', function (Blueprint $table) {
            $table->dropIndex('title_generation_runs_admin_status_index');
            $table->dropIndex('title_generation_runs_status_retryable_index');
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
                'error_code',
                'retryable_failure',
            ]);
        });
    }
};
