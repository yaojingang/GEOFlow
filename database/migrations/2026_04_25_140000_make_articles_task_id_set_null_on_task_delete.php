<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql' || ! $this->canUpdateConstraint()) {
            return;
        }

        DB::statement('ALTER TABLE articles DROP CONSTRAINT IF EXISTS articles_task_id_fkey');
        DB::statement('ALTER TABLE articles ADD CONSTRAINT articles_task_id_fkey FOREIGN KEY (task_id) REFERENCES tasks(id) ON DELETE SET NULL');
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'pgsql' || ! $this->canUpdateConstraint()) {
            return;
        }

        DB::statement('ALTER TABLE articles DROP CONSTRAINT IF EXISTS articles_task_id_fkey');
        DB::statement('ALTER TABLE articles ADD CONSTRAINT articles_task_id_fkey FOREIGN KEY (task_id) REFERENCES tasks(id)');
    }

    private function canUpdateConstraint(): bool
    {
        return Schema::hasTable('articles')
            && Schema::hasTable('tasks')
            && Schema::hasColumn('articles', 'task_id');
    }
};
