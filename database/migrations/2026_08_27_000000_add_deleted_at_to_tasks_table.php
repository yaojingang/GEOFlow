<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('tasks', 'deleted_at')) {
            Schema::table('tasks', function (Blueprint $table): void {
                $table->softDeletes('deleted_at', 6);
            });
        }

        if (! Schema::hasIndex('tasks', ['deleted_at'])) {
            Schema::table('tasks', function (Blueprint $table): void {
                $table->index('deleted_at');
            });
        }

        if (Schema::hasTable('articles')
            && Schema::hasColumn('articles', 'task_id')
            && ! Schema::hasIndex('articles', ['task_id'])) {
            Schema::table('articles', function (Blueprint $table): void {
                $table->index('task_id', 'articles_task_trash_task_id_index');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('articles') && Schema::hasIndex('articles', 'articles_task_trash_task_id_index')) {
            Schema::table('articles', function (Blueprint $table): void {
                $table->dropIndex('articles_task_trash_task_id_index');
            });
        }

        if (Schema::hasIndex('tasks', ['deleted_at'])) {
            Schema::table('tasks', function (Blueprint $table): void {
                $table->dropIndex(['deleted_at']);
            });
        }

        if (Schema::hasColumn('tasks', 'deleted_at')) {
            Schema::table('tasks', function (Blueprint $table): void {
                $table->dropSoftDeletes();
            });
        }
    }
};
