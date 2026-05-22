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
}
