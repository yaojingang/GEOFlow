<?php

use App\Services\Admin\AiModelUsageLedgerSchema;
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
        Schema::create('ai_model_usage_events', function (Blueprint $table): void {
            $table->id();
            $table->uuid('event_uuid')->unique();
            $table->string('request_id', 36);
            $table->char('request_payload_digest', 64);
            $table->string('call_key', 100);
            $table->char('payload_fingerprint', 64);
            $table->string('operation', 100);
            $table->unsignedBigInteger('ai_model_id');
            $table->unsignedBigInteger('config_owner_admin_id');
            $table->unsignedBigInteger('execution_admin_id')->nullable();
            $table->unsignedBigInteger('ai_config_access_version');
            $table->enum('execution_scope', ['interactive_admin', 'persisted_admin', 'system']);
            $table->enum('model_source', ['personal', 'shared', 'system']);
            $table->string('business_source', 80);
            $table->string('source_type')->nullable();
            $table->string('source_id', 120)->nullable();
            $table->enum('status', ['started', 'succeeded', 'failed', 'discarded', 'revoked']);
            $table->string('error_code', 100)->nullable();
            $table->unsignedBigInteger('input_tokens')->nullable();
            $table->unsignedBigInteger('output_tokens')->nullable();
            $table->unsignedBigInteger('total_tokens')->nullable();
            $table->decimal('estimated_cost', 20, 8)->nullable();
            $table->timestamp('created_at');

            $table->unique(['request_id', 'call_key'], 'ai_model_usage_request_call_unique');
            $table->index(['ai_model_id', 'created_at'], 'ai_model_usage_model_created_index');
            $table->index(
                ['config_owner_admin_id', 'created_at'],
                'ai_model_usage_owner_created_index',
            );
            $table->index(
                ['execution_admin_id', 'created_at'],
                'ai_model_usage_executor_created_index',
            );
            $table->index(
                ['business_source', 'status', 'created_at'],
                'ai_model_usage_business_status_created_index',
            );
            $table->index(
                ['source_type', 'source_id', 'created_at'],
                'ai_model_usage_source_created_index',
            );
            $table->index(
                ['model_source', 'status', 'created_at'],
                'ai_model_usage_model_source_status_created_index',
            );
        });

        AiModelUsageLedgerSchema::install();
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        AiModelUsageLedgerSchema::uninstall();
        Schema::dropIfExists('ai_model_usage_events');
    }
};
