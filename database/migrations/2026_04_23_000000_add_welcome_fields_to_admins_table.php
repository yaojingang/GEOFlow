<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 为管理员增加欢迎/更新弹窗的已读版本键与关闭时间字段。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('admins', function (Blueprint $table) {
            if (! Schema::hasColumn('admins', 'welcome_seen_version')) {
                $table->string('welcome_seen_version', 120)->nullable()->after('last_login')->comment('已展示的欢迎/更新弹窗版本键');
            }
            if (! Schema::hasColumn('admins', 'welcome_dismissed_at')) {
                $table->timestamp('welcome_dismissed_at')->nullable()->after('welcome_seen_version')->comment('用户主动关闭欢迎弹窗时间');
            }
        });
    }

    public function down(): void
    {
        Schema::table('admins', function (Blueprint $table) {
            $dropColumns = [];
            if (Schema::hasColumn('admins', 'welcome_seen_version')) {
                $dropColumns[] = 'welcome_seen_version';
            }
            if (Schema::hasColumn('admins', 'welcome_dismissed_at')) {
                $dropColumns[] = 'welcome_dismissed_at';
            }
            if ($dropColumns !== []) {
                $table->dropColumn($dropColumns);
            }
        });
    }
};
