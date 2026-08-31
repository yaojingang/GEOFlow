<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('tasks', 'ai_quality_config_version')) {
            Schema::table('tasks', function (Blueprint $table): void {
                $table->unsignedBigInteger('ai_quality_config_version')
                    ->default(1)
                    ->after('ai_quality_policy_version');
            });
        }

        DB::table('tasks')->orderBy('id')->chunkById(500, function ($tasks): void {
            foreach ($tasks as $task) {
                DB::table('tasks')->where('id', $task->id)->update([
                    'ai_quality_config_version' => max(
                        1,
                        (int) ($task->ai_quality_config_version ?? 1),
                        (int) ($task->ai_quality_policy_version ?? 1),
                    ),
                ]);
            }
        }, 'id', 'id');
    }

    public function down(): void
    {
        if (Schema::hasColumn('tasks', 'ai_quality_config_version')) {
            Schema::table('tasks', function (Blueprint $table): void {
                $table->dropColumn('ai_quality_config_version');
            });
        }
    }
};
