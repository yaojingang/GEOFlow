<?php

namespace App\Contracts\Outbound;

use App\Services\Outbound\ResolvedOutboundTarget;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;

interface OutboundTransport
{
    /**
     * PendingRequest remains the interface type for Laravel compatibility.
     * Production transports must require a capability-aware SecurePendingRequest
     * at runtime and reject ordinary pending requests before executing callbacks.
     *
     * @param  array<string, mixed>  $data
     */
    public function send(
        PendingRequest $request,
        string $method,
        ResolvedOutboundTarget $target,
        array $data,
        int $maxBytes,
        bool $crossOrigin = false,
    ): Response;
}
