<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('geo_article_drafts') || Schema::hasColumn('geo_article_drafts', 'article_id')) {
            return;
        }

        Schema::table('geo_article_drafts', function (Blueprint $table): void {
            $table->foreignId('article_id')
                ->nullable()
                ->after('geo_writing_task_id')
                ->constrained('articles')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('geo_article_drafts') || ! Schema::hasColumn('geo_article_drafts', 'article_id')) {
            return;
        }

        Schema::table('geo_article_drafts', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('article_id');
        });
    }
};
