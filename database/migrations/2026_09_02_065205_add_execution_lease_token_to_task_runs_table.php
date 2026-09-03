<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('task_runs', function (Blueprint $table) {
            $table->uuid('execution_lease_token')->nullable();
            $table->index(
                ['status', 'execution_lease_token'],
                'task_runs_status_execution_lease_index',
            );
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('task_runs', function (Blueprint $table) {
            $table->dropIndex('task_runs_status_execution_lease_index');
            $table->dropColumn('execution_lease_token');
        });
    }
};
