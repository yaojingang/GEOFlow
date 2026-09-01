<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
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
            $table->string('request_id', 120);
            $table->string('call_key', 100);
            $table->char('payload_fingerprint', 64);
            $table->string('operation', 100);
            $table->unsignedBigInteger('ai_model_id')->nullable();
            $table->unsignedBigInteger('config_owner_admin_id')->nullable();
            $table->unsignedBigInteger('execution_admin_id')->nullable();
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
            $table->timestamp('created_at')->nullable();

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

        $this->addNonNegativeUsageConstraints();
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('ai_model_usage_events');
    }

    private function addNonNegativeUsageConstraints(): void
    {
        $check = '(input_tokens IS NULL OR input_tokens >= 0)'
            .' AND (output_tokens IS NULL OR output_tokens >= 0)'
            .' AND (total_tokens IS NULL OR total_tokens >= 0)'
            .' AND (estimated_cost IS NULL OR estimated_cost >= 0)';

        if (DB::getDriverName() === 'sqlite') {
            $sqliteCheck = str_replace(
                ['input_tokens', 'output_tokens', 'total_tokens', 'estimated_cost'],
                ['NEW.input_tokens', 'NEW.output_tokens', 'NEW.total_tokens', 'NEW.estimated_cost'],
                $check,
            );
            DB::statement(
                'CREATE TRIGGER ai_model_usage_nonnegative_insert '
                .'BEFORE INSERT ON ai_model_usage_events '
                .'WHEN NOT ('.$sqliteCheck.') '
                ."BEGIN SELECT RAISE(ABORT, 'negative AI model usage value'); END",
            );
            DB::statement(
                'CREATE TRIGGER ai_model_usage_nonnegative_update '
                .'BEFORE UPDATE OF input_tokens, output_tokens, total_tokens, estimated_cost '
                .'ON ai_model_usage_events '
                .'WHEN NOT ('.$sqliteCheck.') '
                ."BEGIN SELECT RAISE(ABORT, 'negative AI model usage value'); END",
            );

            return;
        }

        if (DB::getDriverName() === 'pgsql') {
            DB::statement(
                'ALTER TABLE ai_model_usage_events '
                .'ADD CONSTRAINT ai_model_usage_values_nonnegative CHECK ('.$check.')',
            );
        }
    }
};
