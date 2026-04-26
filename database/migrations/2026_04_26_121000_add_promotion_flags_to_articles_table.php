<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('articles')) {
            return;
        }

        Schema::table('articles', function (Blueprint $table): void {
            if (! Schema::hasColumn('articles', 'is_hot')) {
                $table->boolean('is_hot')->default(false)->after('is_ai_generated');
            }

            if (! Schema::hasColumn('articles', 'is_featured')) {
                $table->boolean('is_featured')->default(false)->after('is_hot');
            }
        });

        Schema::table('articles', function (Blueprint $table): void {
            if (Schema::hasColumn('articles', 'is_hot')) {
                $table->index(['is_hot', 'status', 'published_at'], 'articles_hot_status_published_idx');
            }

            if (Schema::hasColumn('articles', 'is_featured')) {
                $table->index(['is_featured', 'status', 'published_at'], 'articles_featured_status_published_idx');
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('articles')) {
            return;
        }

        Schema::table('articles', function (Blueprint $table): void {
            $table->dropIndex('articles_hot_status_published_idx');
            $table->dropIndex('articles_featured_status_published_idx');
        });

        Schema::table('articles', function (Blueprint $table): void {
            if (Schema::hasColumn('articles', 'is_hot')) {
                $table->dropColumn('is_hot');
            }

            if (Schema::hasColumn('articles', 'is_featured')) {
                $table->dropColumn('is_featured');
            }
        });
    }
};
