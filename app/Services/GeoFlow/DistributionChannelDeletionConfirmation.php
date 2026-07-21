<?php

namespace App\Services\GeoFlow;

final readonly class DistributionChannelDeletionConfirmation
{
    public function __construct(
        public string $impactFingerprint,
        public bool $ackRemoteContent,
        public bool $ackTaskChanges,
        public bool $ackCredentials,
        public bool $ackHistory,
        public bool $forceStaleSending = false,
        public bool $forceStaleOperations = false,
    ) {}
}
