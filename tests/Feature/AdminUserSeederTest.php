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

    public function test_admin_user_seeder_does_not_overwrite_existing_credentials(): void
    {
        $admin = Admin::query()->create([
            'username' => 'admin',
            'email' => 'original-admin@example.com',
            'password' => 'existing-secret',
            'display_name' => 'Original Admin',
            'role' => 'super_admin',
            'status' => 'active',
        ]);

        $originalPasswordHash = (string) $admin->password;

        $this->seed(AdminUserSeeder::class);

        $admin->refresh();

        $this->assertSame('original-admin@example.com', $admin->email);
        $this->assertSame('Original Admin', $admin->display_name);
        $this->assertSame($originalPasswordHash, (string) $admin->password);
        $this->assertTrue(Hash::check('existing-secret', (string) $admin->password));
        $this->assertSame(1, Admin::query()->where('username', 'admin')->count());
    }
}
