<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('distribution_channels') && ! Schema::hasColumn('distribution_channels', 'front_mode')) {
            Schema::table('distribution_channels', function (Blueprint $table): void {
                $table->string('front_mode', 30)->default('static')->after('channel_type');
            });

            DB::table('distribution_channels')
                ->whereNull('front_mode')
                ->orWhere('front_mode', '')
                ->update(['front_mode' => 'static']);
        }

        if (Schema::hasTable('tasks') && ! Schema::hasColumn('tasks', 'publish_scope')) {
            Schema::table('tasks', function (Blueprint $table): void {
                $table->string('publish_scope', 40)->default('local_and_distribution')->after('status');
            });

            DB::table('tasks')
                ->whereNull('publish_scope')
                ->orWhere('publish_scope', '')
                ->update(['publish_scope' => 'local_and_distribution']);
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('tasks') && Schema::hasColumn('tasks', 'publish_scope')) {
            Schema::table('tasks', function (Blueprint $table): void {
                $table->dropColumn('publish_scope');
            });
        }

        if (Schema::hasTable('distribution_channels') && Schema::hasColumn('distribution_channels', 'front_mode')) {
            Schema::table('distribution_channels', function (Blueprint $table): void {
                $table->dropColumn('front_mode');
            });
        }
    }
};
