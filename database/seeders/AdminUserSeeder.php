<?php

/**
 * 写入默认后台管理员；重复执行不会覆盖已有账号。
 */

namespace Database\Seeders;

use App\Models\Admin;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class AdminUserSeeder extends Seeder
{
    /**
     * 根据配置写入首次安装默认管理员，已存在同名账号时不覆盖。
     */
    public function run(): void
    {
        $username = trim((string) config('geoflow.initial_admin_username', 'admin')) ?: 'admin';
        $email = trim((string) config('geoflow.initial_admin_email', 'admin@example.com')) ?: 'admin@example.com';
        $exists = Admin::query()->where('username', $username)->exists();

        if ($exists) {
            $this->command?->info('GEOFlow default admin already exists; seeding skipped without overwriting credentials.');

            return;
        }

        $password = (string) config('geoflow.initial_admin_password', '');

        if ($password === '') {
            if (app()->environment('production')) {
                $password = Str::password(24);
                $this->command?->warn('GEOFlow created default admin ['.$username.'] with a one-time generated password: '.$password);
                $this->command?->warn('Set GEOFLOW_ADMIN_PASSWORD before production deployment, or change this password immediately after first login.');
                Log::warning('GEOFLOW_ADMIN_PASSWORD is empty in production. A random password was generated for a newly seeded default admin.');
            } else {
                $password = 'password';
            }
        }

        Admin::query()->create([
            'username' => $username,
            'email' => $email,
            'password' => $password,
            'display_name' => 'Administrator',
            'role' => 'super_admin',
            'status' => 'active',
        ]);
    }
}
