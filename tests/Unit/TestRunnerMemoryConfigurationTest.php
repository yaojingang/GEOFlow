<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

class TestRunnerMemoryConfigurationTest extends TestCase
{
    public function test_phpunit_uses_the_project_memory_limit(): void
    {
        $this->assertSame('512M', ini_get('memory_limit'));
    }
}
