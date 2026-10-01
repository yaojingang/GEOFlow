<?php

namespace App\Support;

use App\Models\Admin;
use App\Models\HostedSiteProfile;
use App\Services\Topics\TopicService;
use Illuminate\Http\Request;

final class TopicAdminContext
{
    public static function actor(): Admin
    {
        $actor = auth('admin')->user();
        abort_unless($actor instanceof Admin, 403);

        return $actor;
    }

    public static function site(Request $request, ?string $fixed = null): string
    {
        $key = $fixed ?? (string) $request->input('site', 'primary');
        abort_unless($key === 'primary' || self::actor()->canManageProtectedWorkflows(), 403);
        app(TopicService::class)->assertValidSite($key);

        return $key;
    }

    public static function sites(): array
    {
        $sites = ['primary' => '主站'];
        if (self::actor()->canManageProtectedWorkflows()) {
            foreach (HostedSiteProfile::query()->orderBy('id')->get(['id', 'hostname']) as $profile) {
                $sites['hosted:'.$profile->id] = $profile->hostname;
            }
        }

        return $sites;
    }

    public static function viewData(string $site): array
    {
        return ['pageTitle' => '专题管理', 'activeMenu' => 'articles', 'adminSiteName' => AdminWeb::siteName(), 'site' => $site, 'sites' => self::sites()];
    }
}
