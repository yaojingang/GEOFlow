<?php

/**
 * 为 `admins` 增加 `remember_token`，供 Laravel 会话 Guard「记住我」与 {@see User} 对齐。
 */

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Auth\User;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('admins', function (Blueprint $table) {
            $table->rememberToken();
        });
    }

    public function down(): void
    {
        Schema::table('admins', function (Blueprint $table) {
            $table->dropRememberToken();
        });
    }
};
