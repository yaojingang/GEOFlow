<?php

namespace Tests\Feature;

use App\Models\Admin;
use Database\Seeders\AdminUserSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class AdminUserSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_user_seeder_creates_default_admin(): void
    {
        $this->seed(AdminUserSeeder::class);

        $admin = Admin::query()->where('username', 'admin')->first();

        $this->assertNotNull($admin);
        $this->assertSame('admin@example.com', $admin->email);
        $this->assertSame('Administrator', $admin->display_name);
        $this->assertSame('super_admin', $admin->role);
        $this->assertSame('active', $admin->status);
        $this->assertTrue(Hash::check('password', (string) $admin->password));
    }

    public function test_admin_user_seeder_does_not_overwrite_existing_admin(): void
    {
        $existingAdmin = Admin::query()->create([
            'username' => 'admin',
            'password' => 'custom-secret-123',
            'email' => 'custom-admin@example.com',
            'display_name' => 'Custom Admin',
            'role' => 'admin',
            'status' => 'inactive',
        ]);

        $this->seed(AdminUserSeeder::class);

        $existingAdmin->refresh();

        $this->assertSame('custom-admin@example.com', $existingAdmin->email);
        $this->assertSame('Custom Admin', $existingAdmin->display_name);
        $this->assertSame('admin', $existingAdmin->role);
        $this->assertSame('inactive', $existingAdmin->status);
        $this->assertTrue(Hash::check('custom-secret-123', (string) $existingAdmin->password));
    }
}
