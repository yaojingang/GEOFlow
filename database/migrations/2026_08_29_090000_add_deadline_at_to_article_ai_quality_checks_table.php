<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('article_ai_quality_checks')
            || Schema::hasColumn('article_ai_quality_checks', 'deadline_at')) {
            return;
        }

        Schema::table('article_ai_quality_checks', function (Blueprint $table): void {
            $table->timestamp('deadline_at')->nullable()->after('started_at');
            $table->index(['status', 'deadline_at'], 'article_ai_quality_status_deadline_idx');
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('article_ai_quality_checks')
            || ! Schema::hasColumn('article_ai_quality_checks', 'deadline_at')) {
            return;
        }

        Schema::table('article_ai_quality_checks', function (Blueprint $table): void {
            $table->dropIndex('article_ai_quality_status_deadline_idx');
            $table->dropColumn('deadline_at');
        });
    }
};
