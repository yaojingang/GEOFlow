<?php

namespace App\Services\SystemUpdater;

use RuntimeException;

class SystemUpdaterPlatform
{
    public function current(): string
    {
        if (PHP_OS_FAMILY !== 'Linux') {
            throw new RuntimeException('GEOFlow Updater currently supports Linux hosts only.');
        }

        return match (strtolower(php_uname('m'))) {
            'x86_64', 'amd64' => 'linux-amd64',
            'aarch64', 'arm64' => 'linux-arm64',
            default => throw new RuntimeException('This Linux CPU architecture is not supported.'),
        };
    }
}
