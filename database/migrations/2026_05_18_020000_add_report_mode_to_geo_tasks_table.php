<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('geo_tasks') || Schema::hasColumn('geo_tasks', 'report_mode')) {
            return;
        }

        Schema::table('geo_tasks', function (Blueprint $table): void {
            $table->string('report_mode', 40)->default('with_recommendations')->after('points_cost')->index();
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('geo_tasks') || ! Schema::hasColumn('geo_tasks', 'report_mode')) {
            return;
        }

        Schema::table('geo_tasks', function (Blueprint $table): void {
            $table->dropColumn('report_mode');
        });
    }
};
