<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('hosted_site_allocation_requests', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('article_id')->unique()->constrained('articles')->cascadeOnDelete();
            $table->foreignId('task_id')->nullable()->constrained('tasks')->nullOnDelete();
            $table->foreignId('hosted_site_profile_id')
                ->nullable()
                ->constrained('hosted_site_profiles')
                ->cascadeOnDelete();
            $table->foreignId('hosted_site_article_assignment_id')
                ->nullable()
                ->unique('hosted_requests_assignment_unique')
                ->constrained('hosted_site_article_assignments')
                ->nullOnDelete();
            $table->string('status', 24)->default('pending');
            $table->unsignedInteger('attempt_count')->default(0);
            $table->timestamp('next_attempt_at')->nullable();
            $table->timestamp('last_attempt_at')->nullable();
            $table->string('last_error_code', 64)->nullable();
            $table->text('last_error_message')->nullable();
            $table->timestamps();

            $table->index(['task_id', 'status'], 'hosted_requests_task_status_idx');
            $table->index(['hosted_site_profile_id', 'status'], 'hosted_requests_profile_status_idx');
            $table->index(['status', 'next_attempt_at'], 'hosted_requests_status_next_attempt_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('hosted_site_allocation_requests');
    }
};
