<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('hosted_site_article_assignments', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('article_id');
            $table->foreignId('hosted_site_profile_id')
                ->constrained('hosted_site_profiles')
                ->cascadeOnDelete();
            $table->string('status', 24)->default('reserved')->index();
            $table->char('content_fingerprint', 64);
            $table->date('capacity_date')->index();
            $table->timestamp('reservation_expires_at')->nullable()->index();
            $table->timestamp('assigned_at');
            $table->timestamp('published_at')->nullable();
            $table->timestamp('withdrawn_at')->nullable();
            $table->text('last_error_message')->nullable();
            $table->timestamps();

            $table->unique('article_id', 'hosted_assignments_article_unique');
            $table->unique('content_fingerprint', 'hosted_assignments_content_fingerprint_unique');
            $table->foreign('article_id')->references('id')->on('articles')->cascadeOnDelete();
            $table->index(
                ['hosted_site_profile_id', 'capacity_date', 'status'],
                'hosted_assignments_profile_capacity_status_idx'
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('hosted_site_article_assignments');
    }
};
