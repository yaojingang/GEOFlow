<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('link_pool_theme_adaptations', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('site_key', 100);
            $table->string('active_site_key', 100)->nullable()->unique();
            $table->string('theme_id', 80);
            $table->unsignedBigInteger('admin_id');
            $table->string('status', 24)->default('queued');
            $table->string('phase', 32)->default('queued');
            $table->string('error_code', 80)->nullable();
            $table->text('error_message')->nullable();
            $table->uuid('workspace_id')->nullable();
            $table->uuid('candidate_revision_id')->nullable();
            $table->uuid('release_id')->nullable();
            $table->unsignedBigInteger('model_id')->nullable();
            $table->string('base_sha256', 64)->nullable();
            $table->string('article_url', 2048)->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();
            $table->index(['site_key', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('link_pool_theme_adaptations');
    }
};
