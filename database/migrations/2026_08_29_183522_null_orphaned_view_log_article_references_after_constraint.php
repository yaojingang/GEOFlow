<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('view_logs') || ! Schema::hasColumn('view_logs', 'article_id')) {
            return;
        }

        DB::table('view_logs')
            ->whereNotNull('article_id')
            ->whereNotExists(function ($query): void {
                $query->selectRaw('1')
                    ->from('articles')
                    ->whereColumn('articles.id', 'view_logs.article_id');
            })
            ->update(['article_id' => null]);
    }

    public function down(): void {}
};
