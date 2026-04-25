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
        if (! Schema::hasTable('admins')) {
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
        } else {
            Schema::table('admins', function (Blueprint $table) {
                if (! Schema::hasColumn('admins', 'email')) {
                    $table->string('email', 100)->default('')->comment('联系邮箱');
                }
                if (! Schema::hasColumn('admins', 'display_name')) {
                    $table->string('display_name', 100)->default('')->comment('展示名称');
                }
                if (! Schema::hasColumn('admins', 'role')) {
                    $table->string('role', 20)->default('admin')->comment('角色标识');
                }
                if (! Schema::hasColumn('admins', 'status')) {
                    $table->string('status', 20)->default('active')->comment('active/disabled 等');
                }
                if (! Schema::hasColumn('admins', 'created_by')) {
                    $table->unsignedBigInteger('created_by')->nullable()->comment('创建人管理员 ID');
                }
                if (! Schema::hasColumn('admins', 'last_login')) {
                    $table->timestamp('last_login')->nullable()->comment('最后登录时间');
                }
                if (! Schema::hasColumn('admins', 'created_at')) {
                    $table->timestamp('created_at')->nullable();
                }
                if (! Schema::hasColumn('admins', 'updated_at')) {
                    $table->timestamp('updated_at')->nullable();
                }
            });
        }

        if (! Schema::hasTable('site_settings')) {
            Schema::create('site_settings', function (Blueprint $table) {
                $table->id()->comment('主键');
                $table->string('setting_key', 100)->comment('配置键，唯一');
                $table->text('setting_value')->nullable()->comment('配置值（文本/JSON 字符串）');
                $table->timestamps();

                $table->unique('setting_key');
            });
        } else {
            Schema::table('site_settings', function (Blueprint $table) {
                if (! Schema::hasColumn('site_settings', 'setting_key')) {
                    $table->string('setting_key', 100)->comment('配置键，唯一');
                }
                if (! Schema::hasColumn('site_settings', 'setting_value')) {
                    $table->text('setting_value')->nullable()->comment('配置值（文本/JSON 字符串）');
                }
                if (! Schema::hasColumn('site_settings', 'created_at')) {
                    $table->timestamp('created_at')->nullable();
                }
                if (! Schema::hasColumn('site_settings', 'updated_at')) {
                    $table->timestamp('updated_at')->nullable();
                }
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('site_settings');
        Schema::dropIfExists('admins');
    }
};
