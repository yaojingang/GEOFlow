<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('geo_publish_retests')) {
            return;
        }

        Schema::create('geo_publish_retests', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained('organizations')->cascadeOnDelete();
            $table->foreignId('article_id')->constrained('articles')->cascadeOnDelete();
            $table->foreignId('geo_article_draft_id')->constrained('geo_article_drafts')->cascadeOnDelete();
            $table->integer('before_score')->default(0);
            $table->integer('after_score')->default(0);
            $table->string('status', 30)->default('completed')->index();
            $table->string('article_url', 1000)->default('');
            $table->text('summary')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamp('tested_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('geo_publish_retests');
    }
};
