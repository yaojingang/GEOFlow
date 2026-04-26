<?php

namespace Tests\Feature;

use Tests\TestCase;

class TrustedProxyConfigurationTest extends TestCase
{
    public function test_admin_login_urls_respect_forwarded_prefix_from_trusted_proxy(): void
    {
        config(['trustedproxy.proxies' => '*']);

        $loginPath = '/'.ltrim((string) app('router')->getRoutes()->getByName('admin.login')?->uri(), '/');
        $expectedLoginUrl = 'https://geo.example.com/docs'.$loginPath;

        $this->get($loginPath, [
            'HTTP_X_FORWARDED_PROTO' => 'https',
            'HTTP_X_FORWARDED_HOST' => 'geo.example.com',
            'HTTP_X_FORWARDED_PREFIX' => '/docs',
        ])
            ->assertOk()
            ->assertSee('action="'.$expectedLoginUrl.'"', false)
            ->assertSee('src="https://geo.example.com/docs/js/tailwindcss.play-cdn.js"', false);
    }
}
