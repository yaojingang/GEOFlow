<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('geo_article_audits')) {
            return;
        }

        Schema::create('geo_article_audits', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained('organizations')->cascadeOnDelete();
            $table->foreignId('geo_article_draft_id')->constrained('geo_article_drafts')->cascadeOnDelete();
            $table->foreignId('article_id')->nullable()->constrained('articles')->nullOnDelete();
            $table->integer('score')->default(0);
            $table->json('passed_checks')->nullable();
            $table->json('failed_checks')->nullable();
            $table->json('suggestions')->nullable();
            $table->string('status', 30)->default('ready')->index();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('geo_article_audits');
    }
};
