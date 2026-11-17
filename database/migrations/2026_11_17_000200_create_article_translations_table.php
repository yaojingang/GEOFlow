<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * 文章译文独立存储：articles 保留中文原文供人工审查，译文按语言分行存放。
     */
    public function up(): void
    {
        if (Schema::hasTable('article_translations')) {
            return;
        }

        Schema::create('article_translations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('article_id')->constrained('articles')->cascadeOnDelete();
            $table->string('locale', 10);
            $table->string('title', 500);
            $table->text('excerpt')->nullable();
            $table->longText('content');
            $table->string('meta_description', 255)->nullable();
            // 翻译时原文内容指纹，用于判断译文是否过期。
            $table->string('source_hash', 64);
            $table->string('translation_error', 120)->nullable();
            $table->timestamp('translated_at')->nullable();
            $table->timestamps();

            $table->unique(['article_id', 'locale'], 'article_translations_article_locale_unique');
            $table->index(['locale', 'translated_at'], 'article_translations_locale_index');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('article_translations');
    }
};
