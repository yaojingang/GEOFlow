<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('task_trash_entries')) {
            Schema::create('task_trash_entries', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('task_id')->unique()->constrained('tasks')->cascadeOnDelete();
                $table->timestamp('deleted_at', 6)->index();
            });
        }

        DB::table('tasks')
            ->whereNotNull('deleted_at')
            ->orderBy('deleted_at')
            ->orderBy('id')
            ->select(['id', 'deleted_at'])
            ->each(function ($task): void {
                DB::table('task_trash_entries')->insertOrIgnore([
                    'task_id' => (int) $task->id,
                    'deleted_at' => $task->deleted_at,
                ]);
            });
    }

    public function down(): void
    {
        Schema::dropIfExists('task_trash_entries');
    }
};
