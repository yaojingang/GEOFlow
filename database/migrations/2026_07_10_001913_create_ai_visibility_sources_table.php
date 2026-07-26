<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('ai_visibility_sources')) {
            return;
        }

        Schema::create('ai_visibility_sources', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('ai_visibility_run_id')->constrained('ai_visibility_runs')->cascadeOnDelete();
            $table->string('source_type', 80)->default('web');
            $table->string('citation_key', 40)->nullable();
            $table->string('title', 500)->nullable();
            $table->text('url')->nullable();
            $table->string('domain', 255)->nullable();
            $table->string('site_name', 255)->nullable();
            $table->text('snippet')->nullable();
            $table->longText('summary')->nullable();
            $table->longText('content_excerpt')->nullable();
            $table->timestamp('published_at')->nullable();
            $table->unsignedInteger('rank')->nullable();
            $table->double('rank_score')->nullable();
            $table->string('authority_level', 80)->nullable();
            $table->json('metadata_json')->nullable();
            $table->timestamps();

            $table->index(['ai_visibility_run_id', 'rank'], 'ai_visibility_sources_run_rank_idx');
            $table->index(['ai_visibility_run_id', 'domain'], 'ai_visibility_sources_run_domain_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_visibility_sources');
    }
};
