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
        Schema::table('url_import_jobs', function (Blueprint $table) {
            $table->foreignId('model_access_admin_id')
                ->nullable()
                ->after('created_by')
                ->constrained('admins')
                ->restrictOnDelete();
            $table->enum('model_access_admin_role', ['admin', 'super_admin'])->nullable()->after('model_access_admin_id');
            $table->unsignedBigInteger('ai_config_access_version')->nullable()->after('model_access_admin_role');
            $table->foreignId('requested_ai_model_id')
                ->nullable()
                ->after('ai_config_access_version')
                ->constrained('ai_models')
                ->restrictOnDelete();
            $table->unsignedBigInteger('resolver_policy_version')->nullable()->after('requested_ai_model_id');
            $table->foreignId('resolved_ai_model_id')
                ->nullable()
                ->after('resolver_policy_version')
                ->constrained('ai_models')
                ->restrictOnDelete();
            $table->enum('resolved_model_source', ['personal', 'shared'])->nullable()->after('resolved_ai_model_id');
            $table->timestamp('model_resolved_at')->nullable()->after('resolved_model_source');
            $table->uuid('execution_lease_token')->nullable()->after('model_resolved_at');
            $table->string('error_code', 100)->nullable()->after('error_message');
            $table->boolean('retryable_failure')->default(true)->after('error_code');

            $table->index(
                ['status', 'retryable_failure'],
                'url_import_jobs_status_retryable_index',
            );
            $table->index(
                ['model_access_admin_id', 'status'],
                'url_import_jobs_admin_status_index',
            );
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('url_import_jobs', function (Blueprint $table) {
            $table->dropIndex('url_import_jobs_status_retryable_index');
            $table->dropIndex('url_import_jobs_admin_status_index');
            $table->dropForeign(['model_access_admin_id']);
            $table->dropForeign(['requested_ai_model_id']);
            $table->dropForeign(['resolved_ai_model_id']);
            $table->dropColumn([
                'model_access_admin_id',
                'model_access_admin_role',
                'ai_config_access_version',
                'requested_ai_model_id',
                'resolver_policy_version',
                'resolved_ai_model_id',
                'resolved_model_source',
                'model_resolved_at',
                'execution_lease_token',
                'error_code',
                'retryable_failure',
            ]);
        });
    }
};
