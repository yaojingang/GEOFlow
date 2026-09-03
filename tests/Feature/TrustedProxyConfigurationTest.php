<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;
use Laravel\Reverb\Application;
use Laravel\Reverb\Connection;
use Laravel\Reverb\Contracts\WebSocketConnection;
use Laravel\Reverb\Protocols\Pusher\Exceptions\InvalidOrigin;
use Laravel\Reverb\Protocols\Pusher\Server;
use ReflectionClass;
use ReflectionMethod;
use Symfony\Component\HttpFoundation\Exception\SuspiciousOperationException;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Tests\TestCase;

class TrustedProxyConfigurationTest extends TestCase
{
    public function test_admin_login_urls_respect_forwarded_prefix_from_trusted_proxy(): void
    {
        config(['trustedproxy.proxies' => '*']);
        config(['session.driver' => 'array']);
        config(['geoflow.hosted_sites.primary_hosts' => ['geo.example.com']]);

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

    public function test_trusted_public_scheme_and_port_generate_canonical_https_urls(): void
    {
        config(['trustedproxy.proxies' => '*']);
        config(['session.driver' => 'array']);
        config(['geoflow.hosted_sites.primary_hosts' => ['geo.example.com']]);
        $loginPath = '/'.ltrim((string) app('router')->getRoutes()->getByName('admin.login')?->uri(), '/');

        $this->get($loginPath, [
            'HTTP_X_FORWARDED_PROTO' => 'https',
            'HTTP_X_FORWARDED_HOST' => 'geo.example.com',
            'HTTP_X_FORWARDED_PORT' => '443',
        ])
            ->assertOk()
            ->assertSee('action="https://geo.example.com'.$loginPath.'"', false)
            ->assertDontSee('https://geo.example.com:80', false);

        $this->get($loginPath, [
            'HTTP_X_FORWARDED_PROTO' => 'https',
            'HTTP_X_FORWARDED_HOST' => 'geo.example.com',
            'HTTP_X_FORWARDED_PORT' => '8443',
        ])->assertSee('action="https://geo.example.com:8443'.$loginPath.'"', false);
    }

    public function test_wrapped_untrusted_host_exception_is_a_quiet_api_not_found(): void
    {
        config(['geoflow.hosted_sites.primary_hosts' => ['primary.test']]);
        Log::spy();
        Route::get('/api/_wrapped-host-rejection', static function (): never {
            throw new BadRequestHttpException(
                'Untrusted Host',
                new SuspiciousOperationException('Untrusted Host')
            );
        });

        $this->getJson('http://primary.test/api/_wrapped-host-rejection')
            ->assertNotFound()
            ->assertJsonPath('error.code', 'not_found');
        Log::shouldNotHaveReceived('error');
    }

    public function test_wrapped_untrusted_host_exception_is_a_quiet_web_not_found(): void
    {
        config(['geoflow.hosted_sites.primary_hosts' => ['localhost']]);
        Log::spy();
        Route::get('/_wrapped-host-rejection', static function (): never {
            throw new BadRequestHttpException(
                'Untrusted Host',
                new SuspiciousOperationException('Untrusted Host')
            );
        });

        $this->get('http://localhost/_wrapped-host-rejection')
            ->assertNotFound()
            ->assertHeader('X-Robots-Tag', 'noindex, nofollow');
        Log::shouldNotHaveReceived('error');
    }

    public function test_reverb_allowed_origins_are_normalized_to_hostnames(): void
    {
        foreach ((array) config('reverb.apps.apps.0.allowed_origins') as $origin) {
            $this->assertStringNotContainsString('://', (string) $origin);
            $this->assertStringNotContainsString('/', (string) $origin);
        }
    }

    public function test_reverb_origin_gate_accepts_the_primary_host_and_rejects_a_hosted_site(): void
    {
        $application = new Application(
            'app-id',
            'app-key',
            'app-secret',
            60,
            30,
            ['geo.example.com'],
            10_000,
        );
        $socket = new class implements WebSocketConnection
        {
            public function id(): int|string
            {
                return 1;
            }

            public function send(mixed $message): void {}

            public function close(mixed $message = null): void {}
        };
        $server = (new ReflectionClass(Server::class))->newInstanceWithoutConstructor();
        $verifyOrigin = new ReflectionMethod(Server::class, 'verifyOrigin');
        $verifyOrigin->invoke($server, new Connection($socket, $application, 'https://geo.example.com'));
        $this->assertTrue(true);

        $this->expectException(InvalidOrigin::class);
        $verifyOrigin->invoke($server, new Connection($socket, $application, 'https://alpha.sites.example.com'));
    }
}
