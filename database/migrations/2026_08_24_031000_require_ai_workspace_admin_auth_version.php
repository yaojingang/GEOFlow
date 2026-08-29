<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('ai_workspace_runs')->whereNull('admin_auth_version')->update([
            'admin_auth_version' => 0,
        ]);

        Schema::table('ai_workspace_runs', function (Blueprint $table): void {
            $table->unsignedInteger('admin_auth_version')->nullable(false)->change();
        });
    }

    public function down(): void
    {
        Schema::table('ai_workspace_runs', function (Blueprint $table): void {
            $table->unsignedInteger('admin_auth_version')->nullable()->change();
        });

        DB::table('ai_workspace_runs')->where('admin_auth_version', 0)->update([
            'admin_auth_version' => null,
        ]);
    }
};
