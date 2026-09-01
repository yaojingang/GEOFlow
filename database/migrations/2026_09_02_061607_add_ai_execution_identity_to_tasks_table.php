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
        Schema::table('tasks', function (Blueprint $table) {
            $table->foreignId('model_access_admin_id')
                ->nullable()
                ->constrained('admins')
                ->restrictOnDelete();
            $table->enum('model_access_admin_role', ['admin', 'super_admin'])
                ->nullable();
            $table->unsignedBigInteger('model_access_policy_version')
                ->nullable();
            $table->index(
                ['model_access_admin_id', 'status', 'schedule_enabled'],
                'tasks_model_access_execution_index',
            );
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('tasks', function (Blueprint $table) {
            $table->dropIndex('tasks_model_access_execution_index');
            $table->dropConstrainedForeignId('model_access_admin_id');
            $table->dropColumn([
                'model_access_admin_role',
                'model_access_policy_version',
            ]);
        });
    }
};
