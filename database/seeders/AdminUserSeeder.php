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
    public function run(): void
    {
        $geoEnv = static fn (string $key, mixed $default = null): mixed => env('GEOAMPLIFY_'.$key, env('GEOFLOW_'.$key, $default));
        $username = trim((string) $geoEnv('ADMIN_USERNAME', 'admin')) ?: 'admin';
        $email = trim((string) $geoEnv('ADMIN_EMAIL', 'admin@example.com')) ?: 'admin@example.com';
        $exists = Admin::query()->where('username', $username)->exists();

        if ($exists) {
            $this->command?->info('GEOAmplify default admin already exists; seeding skipped without overwriting credentials.');

            return;
        }

        $password = (string) $geoEnv('ADMIN_PASSWORD', '');

        if ($password === '') {
            if (app()->environment('production')) {
                $password = Str::password(24);
                $this->command?->warn('GEOAmplify created default admin ['.$username.'] with a one-time generated password: '.$password);
                $this->command?->warn('Set GEOAMPLIFY_ADMIN_PASSWORD before production deployment, or change this password immediately after first login.');
                Log::warning('GEOAMPLIFY_ADMIN_PASSWORD is empty in production. A random password was generated for a newly seeded default admin.');
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
