<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('tasks')) {
            return;
        }

        Schema::table('tasks', function (Blueprint $table): void {
            if (! Schema::hasColumn('tasks', 'article_limit')) {
                $table->integer('article_limit')->default(10)->after('draft_limit');
            }

            if (! Schema::hasColumn('tasks', 'next_publish_at')) {
                $table->timestamp('next_publish_at')->nullable()->after('next_run_at');
            }
        });

        $limitExpression = DB::getDriverName() === 'pgsql'
            ? 'GREATEST(COALESCE(article_limit, 10), COALESCE(draft_limit, 10), COALESCE(created_count, 0), 1)'
            : 'max(COALESCE(article_limit, 10), COALESCE(draft_limit, 10), COALESCE(created_count, 0), 1)';

        DB::table('tasks')
            ->update([
                'article_limit' => DB::raw($limitExpression),
            ]);
    }

    public function down(): void
    {
        if (! Schema::hasTable('tasks')) {
            return;
        }

        Schema::table('tasks', function (Blueprint $table): void {
            if (Schema::hasColumn('tasks', 'next_publish_at')) {
                $table->dropColumn('next_publish_at');
            }

            if (Schema::hasColumn('tasks', 'article_limit')) {
                $table->dropColumn('article_limit');
            }
        });
    }
};
