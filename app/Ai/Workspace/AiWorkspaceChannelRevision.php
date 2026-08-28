<?php

namespace App\Ai\Workspace;

use App\Models\DistributionChannel;
use App\Models\DistributionChannelSecret;

final class AiWorkspaceChannelRevision
{
    public static function make(DistributionChannel $channel): string
    {
        $activeSecret = DistributionChannelSecret::query()
            ->where('distribution_channel_id', (int) $channel->id)
            ->where('status', 'active')
            ->latest('id')
            ->first(['id', 'key_id', 'status']);

        return AiPayloadDigest::make([
            'status' => $channel->status,
            'domain' => $channel->domain,
            'endpoint_url' => $channel->endpoint_url,
            'channel_type' => $channel->channel_type,
            'channel_config' => $channel->channel_config,
            'site_settings' => $channel->site_settings,
            'active_secret' => $activeSecret instanceof DistributionChannelSecret ? [
                'id' => (int) $activeSecret->id,
                'key_id' => (string) $activeSecret->key_id,
                'status' => (string) $activeSecret->status,
            ] : null,
        ]);
    }
}
