<?php

namespace Tests\Unit;

use App\Support\AdminActivityLogger;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

class AdminActivityLoggerSanitizationTest extends TestCase
{
    public function test_package_password_is_redacted_from_admin_activity_payload(): void
    {
        $method = new ReflectionMethod(AdminActivityLogger::class, 'sanitizePayload');
        $method->setAccessible(true);

        $payload = $method->invoke(null, [
            'package_password' => 'secret-123',
            'name' => '官网主站',
        ]);

        $this->assertSame('[redacted]', $payload['package_password']);
        $this->assertSame('官网主站', $payload['name']);
    }

    public function test_nested_secret_fields_are_redacted_by_name_pattern(): void
    {
        $method = new ReflectionMethod(AdminActivityLogger::class, 'sanitizePayload');
        $method->setAccessible(true);

        $payload = $method->invoke(null, [
            'current_admin_password' => 'admin-secret',
            'channel' => [
                'wordpress_application_password' => 'wordpress-secret',
                'generic_secret' => 'distribution-secret',
                'display_name' => 'Production site',
                'max_tokens' => 4096,
                'token_count' => 128,
                'ack_credentials' => true,
            ],
        ]);

        $this->assertSame('[redacted]', $payload['current_admin_password']);
        $this->assertSame('[redacted]', $payload['channel']['wordpress_application_password']);
        $this->assertSame('[redacted]', $payload['channel']['generic_secret']);
        $this->assertSame('Production site', $payload['channel']['display_name']);
        $this->assertSame('4096', $payload['channel']['max_tokens']);
        $this->assertSame('128', $payload['channel']['token_count']);
        $this->assertTrue($payload['channel']['ack_credentials']);
    }
}
