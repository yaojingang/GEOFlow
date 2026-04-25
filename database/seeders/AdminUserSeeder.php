<?php

/**
 * 写入默认 Filament 管理员（`admins.username = admin`），便于首次登录；重复执行不会覆盖已有账号。
 */

namespace Database\Seeders;

use App\Models\Admin;
use Illuminate\Database\Seeder;

class AdminUserSeeder extends Seeder
{
    /**
     * 默认后台账号（仅本地/首次初始化使用，生产环境请修改密码）。
     */
    public function run(): void
    {
        Admin::query()->firstOrCreate(
            ['username' => 'admin'],
            [
                'email' => 'admin@example.com',
                'password' => 'password',
                'display_name' => 'Administrator',
                'role' => 'super_admin',
                'status' => 'active',
            ],
        );
    }
}
