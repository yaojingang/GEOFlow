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
        Schema::create('title_generation_runs', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('title_library_id')->constrained('title_libraries')->cascadeOnDelete();
            $table->foreignId('keyword_library_id')->nullable()->constrained('keyword_libraries')->nullOnDelete();
            $table->foreignId('ai_model_id')->nullable()->constrained('ai_models')->nullOnDelete();
            $table->foreignId('created_by_admin_id')->nullable()->constrained('admins')->nullOnDelete();
            $table->string('status', 20)->default('queued');
            $table->string('active_key')->nullable()->unique();
            $table->unsignedInteger('requested_count');
            $table->unsignedSmallInteger('batch_size')->default(50);
            $table->unsignedInteger('batch_sequence')->default(0);
            $table->unsignedInteger('requested_from_model_count')->default(0);
            $table->unsignedInteger('generated_count')->default(0);
            $table->unsignedInteger('saved_count')->default(0);
            $table->unsignedInteger('duplicate_count')->default(0);
            $table->unsignedInteger('invalid_count')->default(0);
            $table->unsignedInteger('batch_count')->default(0);
            $table->unsignedSmallInteger('consecutive_empty_batches')->default(0);
            $table->unsignedInteger('model_request_budget');
            $table->unsignedInteger('model_request_count')->default(0);
            $table->string('title_style', 30);
            $table->text('custom_prompt')->nullable();
            $table->string('locale', 10)->default('zh_CN');
            $table->json('keyword_snapshot');
            $table->timestamp('available_at')->nullable();
            $table->uuid('dispatch_token')->nullable();
            $table->uuid('lease_token')->nullable();
            $table->timestamp('lease_expires_at')->nullable();
            $table->unsignedSmallInteger('batch_attempt_count')->default(0);
            $table->string('failure_code', 50)->nullable();
            $table->text('last_error')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('failed_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->timestamps();

            $table->index(['title_library_id', 'created_at'], 'title_generation_runs_library_created_idx');
            $table->index(['status', 'available_at', 'id'], 'title_generation_runs_available_idx');
            $table->index(['status', 'lease_expires_at', 'id'], 'title_generation_runs_lease_idx');
        });

    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('title_generation_runs');
    }
};
