<?php

namespace App\Services\GeoFlow;

use App\Exceptions\DistributionChannelDeletionBlocked;
use App\Models\DistributionChannel;
use App\Models\DistributionChannelOperation;
use Closure;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class DistributionChannelOperationLeaseService
{
    public const LEASE_SECONDS = 300;

    public function run(DistributionChannel $channel, string $operation, Closure $callback): mixed
    {
        [$lease, $lockedChannel] = DB::transaction(function () use ($channel, $operation): array {
            $lockedChannel = DistributionChannel::query()
                ->whereKey((int) $channel->id)
                ->lockForUpdate()
                ->first();

            if (! $lockedChannel || (string) $lockedChannel->status === DistributionChannel::STATUS_DELETING) {
                throw new DistributionChannelDeletionBlocked('operation_blocked');
            }

            $lease = DistributionChannelOperation::query()->create([
                'distribution_channel_id' => (int) $lockedChannel->id,
                'token' => (string) Str::uuid(),
                'operation' => $operation,
                'started_at' => now(),
                'expires_at' => now()->addSeconds(self::LEASE_SECONDS),
            ]);

            return [$lease, $lockedChannel];
        });

        try {
            return $callback($lockedChannel);
        } finally {
            DistributionChannelOperation::query()
                ->where('token', (string) $lease->token)
                ->delete();
        }
    }
}
