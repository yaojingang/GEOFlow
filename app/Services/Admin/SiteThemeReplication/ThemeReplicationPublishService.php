<?php

namespace App\Services\Admin\SiteThemeReplication;

use App\Models\SiteThemeReplication;
use RuntimeException;

class ThemeReplicationPublishService
{
    public function __construct(private readonly ThemeReplicationPackageService $packageService) {}

    /**
     * @return array{mode:string,message:string,package:array{name:string,relative_path:string,absolute_path:string,bytes:int}}
     */
    public function publish(SiteThemeReplication $replication): array
    {
        if (! $replication->canPublish()) {
            throw new RuntimeException(__('admin.theme_replication.message.publish_unavailable'));
        }

        return [
            'mode' => 'package',
            'message' => __('admin.theme_replication.message.package_ready'),
            'package' => $this->packageService->createPackage($replication),
        ];
    }
}
