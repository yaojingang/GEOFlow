<?php

/**
 * GEOFlow 后台管理员与站点键值设置表（先于业务大表迁移，供外键引用 admins）。
 */

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('admins', function (Blueprint $table) {
            $table->id()->comment('主键');
            $table->string('username', 50)->unique()->comment('登录账号，唯一');
            $table->string('password', 255)->comment('password_hash 存储');
            $table->string('email', 100)->default('')->comment('联系邮箱');
            $table->string('display_name', 100)->default('')->comment('展示名称');
            $table->string('role', 20)->default('admin')->comment('角色标识');
            $table->string('status', 20)->default('active')->comment('active/disabled 等');
            $table->foreignId('created_by')->nullable()->comment('创建人管理员 ID')->constrained('admins');
            $table->timestamp('last_login')->nullable()->comment('最后登录时间');
            $table->timestamps();
        });

        Schema::create('site_settings', function (Blueprint $table) {
            $table->id()->comment('主键');
            $table->string('setting_key', 100)->comment('配置键，唯一');
            $table->text('setting_value')->nullable()->comment('配置值（文本/JSON 字符串）');
            $table->timestamps();

            $table->unique('setting_key');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('site_settings');
        Schema::dropIfExists('admins');
    }
};
