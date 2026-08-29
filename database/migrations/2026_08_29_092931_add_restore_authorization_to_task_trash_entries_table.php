<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('task_trash_entries', function (Blueprint $table): void {
            $table->boolean('requires_super_admin_restore')->default(false)->after('deleted_at');
        });

        // Older trash rows no longer have their deleted channel pivot. Treat the
        // hosted-only publish scope conservatively so a regular admin cannot
        // regain control of a formerly protected task through restoration.
        DB::table('task_trash_entries')
            ->whereIn('task_id', DB::table('tasks')
                ->select('id')
                ->where('publish_scope', 'distribution_only'))
            ->update(['requires_super_admin_restore' => true]);
    }

    public function down(): void
    {
        Schema::table('task_trash_entries', function (Blueprint $table): void {
            $table->dropColumn('requires_super_admin_restore');
        });
    }
};
