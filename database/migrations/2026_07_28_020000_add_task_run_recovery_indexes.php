<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('task_runs', function (Blueprint $table): void {
            $table->index(
                ['status', 'started_at', 'id'],
                'task_runs_status_started_id_index'
            );
            $table->index(
                ['status', 'created_at', 'id'],
                'task_runs_status_created_id_index'
            );
        });
    }

    public function down(): void
    {
        Schema::table('task_runs', function (Blueprint $table): void {
            $table->dropIndex('task_runs_status_started_id_index');
            $table->dropIndex('task_runs_status_created_id_index');
        });
    }
};
