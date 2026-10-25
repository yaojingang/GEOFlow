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
        Schema::table('personal_access_tokens', function (Blueprint $table) {
            $table->string('recovery_epoch', 32)->nullable();
        });
        Schema::table('theme_workspaces', function (Blueprint $table) {
            $table->string('code_recovery_epoch', 32)->nullable();
        });
        Schema::table('admins', function (Blueprint $table) {
            $table->string('remember_recovery_epoch', 32)->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('personal_access_tokens', function (Blueprint $table) {
            $table->dropColumn('recovery_epoch');
        });
        Schema::table('theme_workspaces', function (Blueprint $table) {
            $table->dropColumn('code_recovery_epoch');
        });
        Schema::table('admins', function (Blueprint $table) {
            $table->dropColumn('remember_recovery_epoch');
        });
    }
};
