<?php

namespace Tests\Feature;

use App\Providers\AppServiceProvider;
use Illuminate\Support\Facades\URL;
use Tests\TestCase;

class SubdirectoryDeploymentUrlTest extends TestCase
{
    protected function tearDown(): void
    {
        URL::forceRootUrl(null);
        URL::forceScheme(null);

        parent::tearDown();
    }

    public function test_admin_login_form_uses_public_app_url_with_subdirectory(): void
    {
        config(['app.url' => 'https://geo.example.com/wiki']);

        (new AppServiceProvider($this->app))->configureUrlGeneration();

        $loginPath = '/'.ltrim((string) app('router')->getRoutes()->getByName('admin.login')?->uri(), '/');
        $expectedLoginUrl = 'https://geo.example.com/wiki'.$loginPath;

        $this->assertSame($expectedLoginUrl, route('admin.login'));
        $this->assertSame($expectedLoginUrl, route('admin.login.attempt'));
        $this->assertSame('https://geo.example.com/wiki/js/tailwindcss.play-cdn.js', asset('js/tailwindcss.play-cdn.js'));
        $this->assertSame('https://geo.example.com/wiki', url('/'));
    }
}
