<?php

namespace App\Jobs;

use App\Models\Admin;
use App\Models\BrandProfile;
use App\Models\Organization;
use App\Services\Geo\GeoTopicToPublishPackagePipeline;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use RuntimeException;

class GeoTopicPipelineJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 900;

    /**
     * @param  list<string>  $platformCodes
     */
    public function __construct(
        public readonly int $adminId,
        public readonly int $organizationId,
        public readonly int $brandProfileId,
        public readonly string $topic,
        public readonly array $platformCodes,
        public readonly int $maxReferences
    ) {}

    public function handle(GeoTopicToPublishPackagePipeline $pipeline): void
    {
        $admin = Admin::query()->find($this->adminId);
        $organization = Organization::query()->find($this->organizationId);
        $brandProfile = BrandProfile::query()
            ->where('organization_id', $this->organizationId)
            ->find($this->brandProfileId);

        if (! $admin instanceof Admin || ! $organization instanceof Organization || ! $brandProfile instanceof BrandProfile) {
            throw new RuntimeException('GEO 选题链路队列任务缺少管理员、组织或品牌资料');
        }

        $pipeline->run(
            $admin,
            $organization,
            $brandProfile,
            $this->topic,
            $this->platformCodes,
            $this->maxReferences
        );
    }
}
