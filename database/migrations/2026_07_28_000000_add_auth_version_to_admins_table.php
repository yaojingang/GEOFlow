<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('admins', function (Blueprint $table): void {
            $table->unsignedBigInteger('auth_version')
                ->default(1)
                ->after('remember_token')
                ->comment('认证凭据版本，递增后使旧会话失效');
        });
    }

    public function down(): void
    {
        Schema::table('admins', function (Blueprint $table): void {
            $table->dropColumn('auth_version');
        });
    }
};
