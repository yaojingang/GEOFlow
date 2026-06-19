<?php

namespace Tests\Feature;

use App\Models\Admin;
use App\Models\Article;
use App\Models\Category;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Tests\TestCase;

class DatabaseSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_database_seeder_does_not_seed_frontend_demo_content_by_default(): void
    {
        Config::set('geoflow.seed_frontend_demo', false);

        $this->seed(DatabaseSeeder::class);

        $this->assertSame(1, Admin::query()->where('username', 'admin')->count());
        $this->assertSame(0, Category::query()->count());
        $this->assertSame(0, Article::query()->count());
    }

    public function test_database_seeder_can_seed_frontend_demo_content_when_enabled(): void
    {
        Config::set('geoflow.seed_frontend_demo', true);

        $this->seed(DatabaseSeeder::class);

        $this->assertSame(1, Admin::query()->where('username', 'admin')->count());
        $this->assertGreaterThan(0, Category::query()->where('slug', 'mac')->count());
        $this->assertGreaterThan(0, Article::query()->where('slug', 'how-to-reinstall-macos')->count());
    }
}
