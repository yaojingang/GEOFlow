<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * 标记文章原文语言。原文永远是审查基准，译文存放在 article_translations。
     */
    public function up(): void
    {
        if (Schema::hasColumn('articles', 'locale')) {
            return;
        }

        Schema::table('articles', function (Blueprint $table): void {
            $table->string('locale', 10)->default('zh');
            $table->index(['task_id', 'locale'], 'articles_task_locale_index');
        });

        // 历史文章按标题实际语言回填：标题是审查入口，也是分发主字段。
        // 只有标题为中文的文章才视为中文原文，避免把俄语标题的文章重复翻译。
        DB::table('articles')
            ->whereNull('locale')
            ->orWhere('locale', 'zh')
            ->lazyById(200)
            ->each(function ($article): void {
                $title = (string) ($article->title ?? '');
                $cyrillic = preg_match_all('/\p{Cyrillic}/u', $title) ?: 0;
                $han = preg_match_all('/\p{Han}/u', $title) ?: 0;
                $locale = $cyrillic >= 3 && $cyrillic > $han ? 'ru' : 'zh';

                DB::table('articles')->where('id', $article->id)->update(['locale' => $locale]);
            });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (! Schema::hasColumn('articles', 'locale')) {
            return;
        }

        Schema::table('articles', function (Blueprint $table): void {
            $table->dropIndex('articles_task_locale_index');
            $table->dropColumn('locale');
        });
    }
};
