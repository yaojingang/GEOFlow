<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_quality_audit_events', function (Blueprint $table): void {
            $table->id();
            $table->uuid('event_uuid')->unique();
            $table->uuid('correlation_id')->nullable()->index();
            $table->string('event_type', 64)->index();
            $table->timestamp('occurred_at')->index();
            $table->unsignedBigInteger('task_id')->nullable();
            $table->unsignedBigInteger('article_id')->nullable();
            $table->unsignedBigInteger('article_ai_quality_check_id')->nullable();
            $table->unsignedBigInteger('admin_id')->nullable();
            $table->unsignedBigInteger('api_token_id')->nullable();
            $table->string('authorization_result', 24)->nullable();
            $table->unsignedBigInteger('policy_version')->nullable();
            $table->char('before_hash', 64)->nullable();
            $table->char('after_hash', 64)->nullable();
            $table->char('basis_hash', 64)->nullable();
            $table->string('reason_code', 80)->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['article_id', 'occurred_at'], 'ai_quality_audit_article_time_idx');
            $table->index(['task_id', 'occurred_at'], 'ai_quality_audit_task_time_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_quality_audit_events');
    }
};
