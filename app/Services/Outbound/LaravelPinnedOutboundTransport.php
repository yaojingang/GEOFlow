<?php

namespace App\Services\Outbound;

use App\Contracts\Outbound\OutboundTransport;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;

final class LaravelPinnedOutboundTransport implements OutboundTransport
{
    public function __construct(private readonly object $fixedContextCapability) {}

    public function send(
        PendingRequest $request,
        string $method,
        ResolvedOutboundTarget $target,
        array $data,
        int $maxBytes,
        bool $crossOrigin = false,
    ): Response {
        if (! $request instanceof SecurePendingRequest) {
            throw new OutboundRequestBlockedException('secure_pending_request_required');
        }

        $trustedOptions = $request->getOptions();
        $streamingRequested = (bool) ($trustedOptions['stream'] ?? false);
        $pinnedRequest = (clone $request)->withOptions([
            'geoflow_response_max_bytes' => $maxBytes,
            'allow_redirects' => false,
            'decode_content' => false,
            'http_errors' => false,
            'proxy' => '',
            'stream' => false,
            'verify' => true,
            'force_ip_resolve' => str_contains($target->selectedIp, ':') ? 'v6' : 'v4',
        ]);

        try {
            $pinnedRequest = $pinnedRequest->withFixedSecurityContext(
                $target,
                $method,
                $crossOrigin,
                $maxBytes,
                $streamingRequested,
                $this->fixedContextCapability,
            );

            return match ($method) {
                'GET' => $data === [] ? $pinnedRequest->send('GET', $target->url) : $pinnedRequest->get($target->url, $data),
                'POST' => $data === [] ? $pinnedRequest->send('POST', $target->url) : $pinnedRequest->post($target->url, $data),
                'PUT' => $data === [] ? $pinnedRequest->send('PUT', $target->url) : $pinnedRequest->put($target->url, $data),
                'PATCH' => $data === [] ? $pinnedRequest->send('PATCH', $target->url) : $pinnedRequest->patch($target->url, $data),
                'DELETE' => $pinnedRequest->delete($target->url, $data),
                default => $pinnedRequest->send($method, $target->url),
            };
        } catch (\Throwable $exception) {
            if ($exception instanceof OutboundRequestBlockedException || $exception instanceof OutboundRequestFailedException) {
                throw $exception;
            }

            throw new OutboundRequestFailedException;
        }
    }
}
