<?php

use App\Services\Admin\AiModelUsageAttemptStartLedgerSchema;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_model_usage_attempt_starts', function (Blueprint $table): void {
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
            $table->timestamp('created_at');

            $table->unique(['request_id', 'call_key'], 'ai_model_attempt_start_request_call_unique');
            $table->index(['created_at', 'id'], 'ai_model_attempt_start_created_index');
        });

        AiModelUsageAttemptStartLedgerSchema::install();
    }

    public function down(): void
    {
        AiModelUsageAttemptStartLedgerSchema::uninstall();
        Schema::dropIfExists('ai_model_usage_attempt_starts');
    }
};
