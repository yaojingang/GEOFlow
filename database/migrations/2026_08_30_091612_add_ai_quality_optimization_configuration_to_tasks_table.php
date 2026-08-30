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
            $table->boolean('ai_quality_auto_optimize_enabled')
                ->default(false)
                ->after('ai_quality_timeout_sampling_enabled');
            $table->string('ai_quality_optimization_level', 20)
                ->default('excellent_80')
                ->after('ai_quality_auto_optimize_enabled');
            $table->index(
                ['ai_quality_auto_optimize_enabled', 'status'],
                'tasks_ai_quality_auto_optimize_status_idx',
            );
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('tasks', function (Blueprint $table) {
            $table->dropIndex('tasks_ai_quality_auto_optimize_status_idx');
            $table->dropColumn([
                'ai_quality_auto_optimize_enabled',
                'ai_quality_optimization_level',
            ]);
        });
    }
};
