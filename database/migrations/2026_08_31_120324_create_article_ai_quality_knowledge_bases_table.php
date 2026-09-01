<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('article_ai_quality_knowledge_bases', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('article_id')->constrained('articles')->cascadeOnDelete();
            $table->foreignId('knowledge_base_id')->constrained('knowledge_bases')->restrictOnDelete();
            $table->unsignedTinyInteger('sort_order')->default(0);
            $table->timestamps();

            $table->unique(['article_id', 'knowledge_base_id'], 'article_ai_quality_kb_unique');
            $table->index(['knowledge_base_id', 'article_id'], 'article_ai_quality_kb_reverse_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('article_ai_quality_knowledge_bases');
    }
};
