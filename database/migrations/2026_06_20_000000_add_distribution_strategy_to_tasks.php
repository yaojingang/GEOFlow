<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('tasks')) {
            Schema::table('tasks', function (Blueprint $table): void {
                if (! Schema::hasColumn('tasks', 'distribution_strategy')) {
                    $table->string('distribution_strategy', 40)->default('broadcast')->after('publish_scope');
                }

                if (! Schema::hasColumn('tasks', 'distribution_cursor')) {
                    $table->unsignedInteger('distribution_cursor')->default(0)->after('distribution_strategy');
                }
            });

            DB::table('tasks')
                ->whereNull('distribution_strategy')
                ->orWhere('distribution_strategy', '')
                ->update(['distribution_strategy' => 'broadcast']);
        }

        if (Schema::hasTable('task_distribution_channels') && ! Schema::hasColumn('task_distribution_channels', 'sort_order')) {
            Schema::table('task_distribution_channels', function (Blueprint $table): void {
                $table->unsignedSmallInteger('sort_order')->default(0)->after('distribution_channel_id');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('task_distribution_channels') && Schema::hasColumn('task_distribution_channels', 'sort_order')) {
            Schema::table('task_distribution_channels', function (Blueprint $table): void {
                $table->dropColumn('sort_order');
            });
        }

        if (Schema::hasTable('tasks')) {
            Schema::table('tasks', function (Blueprint $table): void {
                foreach (['distribution_cursor', 'distribution_strategy'] as $column) {
                    if (Schema::hasColumn('tasks', $column)) {
                        $table->dropColumn($column);
                    }
                }
            });
        }
    }
};
