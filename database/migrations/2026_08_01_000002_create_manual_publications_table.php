<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('manual_publications', function (Blueprint $table): void {
            $table->id();
            $table->string('type', 20)->index();
            $table->foreignId('article_id')->nullable()->constrained('articles')->nullOnDelete();
            $table->foreignId('persona_id')->nullable()->constrained('manual_publication_personas')->nullOnDelete();
            $table->foreignId('account_id')->nullable()->constrained('manual_publication_accounts')->nullOnDelete();
            $table->foreignId('assigned_admin_id')->nullable()->constrained('admins')->nullOnDelete();
            $table->foreignId('created_by_admin_id')->nullable()->constrained('admins')->nullOnDelete();
            $table->string('platform', 60)->index();
            $table->string('custom_platform', 120)->nullable();
            $table->string('target_url', 1000)->nullable();
            $table->char('target_url_hash', 64)->nullable()->index();
            $table->text('target_context')->nullable();
            $table->text('content');
            $table->char('content_fingerprint', 64)->index();
            $table->json('source_snapshot')->nullable();
            $table->json('identity_snapshot');
            $table->text('disclosure_snapshot')->nullable();
            $table->string('risk_status', 20)->default('clean')->index();
            $table->json('risk_result')->nullable();
            $table->unsignedInteger('duplicate_warning_count')->default(0);
            $table->timestamp('scheduled_at')->nullable()->index();
            $table->string('status', 30)->default('draft')->index();
            $table->timestamp('status_changed_at')->nullable();
            $table->string('completion_url', 1000)->nullable();
            $table->text('result_note')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->unsignedInteger('revision')->default(1);
            $table->timestamps();

            $table->index(['status', 'scheduled_at']);
            $table->index(['assigned_admin_id', 'status', 'scheduled_at'], 'manual_publications_assignee_status_schedule');
            $table->index(['article_id', 'platform']);
            $table->index(['platform', 'target_url_hash']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('manual_publications');
    }
};
