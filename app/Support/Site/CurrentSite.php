<?php

namespace App\Support\Site;

use App\Models\HostedSiteProfile;
use LogicException;

final class CurrentSite
{
    public const TYPE_PRIMARY = 'primary';

    public const TYPE_HOSTED = 'hosted';

    private ?string $type = null;

    private ?string $hostname = null;

    private ?HostedSiteProfile $profile = null;

    public function setPrimary(string $hostname): void
    {
        $this->type = self::TYPE_PRIMARY;
        $this->hostname = $hostname;
        $this->profile = null;
    }

    public function setHosted(HostedSiteProfile $profile): void
    {
        $this->type = self::TYPE_HOSTED;
        $this->hostname = $profile->hostname;
        $this->profile = $profile;
    }

    public function isResolved(): bool
    {
        return $this->type !== null;
    }

    public function isPrimary(): bool
    {
        return $this->type === self::TYPE_PRIMARY;
    }

    public function isHosted(): bool
    {
        return $this->type === self::TYPE_HOSTED;
    }

    public function type(): string
    {
        return $this->type ?? throw new LogicException('Current site has not been resolved.');
    }

    public function hostname(): string
    {
        return $this->hostname ?? throw new LogicException('Current site has not been resolved.');
    }

    public function profile(): ?HostedSiteProfile
    {
        return $this->profile;
    }

    public function profileId(): ?int
    {
        return $this->profile?->id;
    }

    public function channelId(): ?int
    {
        return $this->profile?->distribution_channel_id;
    }

    public function baseUrl(): string
    {
        if ($this->isHosted()) {
            return 'https://'.$this->hostname();
        }

        return rtrim((string) config('geoflow.site_url', config('app.url')), '/');
    }
}
