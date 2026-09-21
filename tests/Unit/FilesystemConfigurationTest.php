<?php

namespace Tests\Unit;

use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

final class FilesystemConfigurationTest extends TestCase
{
    public function test_oss_disk_is_available_in_the_production_dependency_graph(): void
    {
        config()->set('filesystems.disks.oss.key', 'test-key');
        config()->set('filesystems.disks.oss.secret', 'test-secret');
        config()->set('filesystems.disks.oss.region', 'cn-shenzhen');
        config()->set('filesystems.disks.oss.bucket', 'test-bucket');

        $disk = Storage::disk('oss');

        $this->assertInstanceOf(FilesystemAdapter::class, $disk);
        $this->assertSame('s3', config('filesystems.disks.oss.driver'));
        $this->assertSame('local', config('filesystems.default'));
    }
}
