<?php

namespace Tests\Unit;

use App\Support\AdminBasePathManager;
use InvalidArgumentException;
use Tests\TestCase;

class AdminBasePathManagerTest extends TestCase
{
    public function test_normalizes_safe_admin_base_path(): void
    {
        $this->assertSame('geo_admin', AdminBasePathManager::normalize('/geo_admin/'));
        $this->assertSame('admin-panel', AdminBasePathManager::normalize(' admin-panel '));
    }

    public function test_rejects_unsafe_admin_base_path(): void
    {
        $this->expectException(InvalidArgumentException::class);

        AdminBasePathManager::normalize('../admin');
    }

    public function test_rejects_reserved_admin_base_path(): void
    {
        $this->expectException(InvalidArgumentException::class);

        AdminBasePathManager::normalize('api');
    }
}
