<?php

namespace Tests\Unit;

use App\Support\AdminWeb;
use Tests\TestCase;

class AdminWebAppPathTest extends TestCase
{
    public function test_app_path_respects_configured_subdirectory(): void
    {
        config(['app.url' => 'http://www.example.com/doc']);

        $this->assertSame('/doc/broadcasting/auth', AdminWeb::appPath('/broadcasting/auth'));
    }

    public function test_app_path_without_subdirectory_stays_at_root(): void
    {
        config(['app.url' => 'http://www.example.com']);

        $this->assertSame('/broadcasting/auth', AdminWeb::appPath('/broadcasting/auth'));
    }

    public function test_app_path_does_not_prefix_twice(): void
    {
        config(['app.url' => 'http://www.example.com/doc']);

        $this->assertSame('/doc/broadcasting/auth', AdminWeb::appPath('/doc/broadcasting/auth'));
    }
}
