<?php

namespace App\Services\GeoFlow\AiVisibility;

use App\Services\Outbound\SafeOutboundHttpClient;
use App\Services\Outbound\SafeOutboundRequest;
use Illuminate\Support\Facades\Http;

final class AiVisibilityHttpClientFactory
{
    public function __construct(
        private readonly SafeOutboundHttpClient $safeHttp,
    ) {}

    public function jsonRequest(string $apiKey): SafeOutboundRequest
    {
        $request = Http::acceptJson()
            ->asJson()
            ->withToken($apiKey)
            ->timeout($this->timeoutSeconds())
            ->connectTimeout($this->connectTimeoutSeconds())
            ->retry($this->retryAttempts(), $this->retrySleepMs(), throw: false);

        return new SafeOutboundRequest(
            $this->safeHttp,
            $request,
            (int) config('geoflow.outbound_ai_max_bytes', 8 * 1024 * 1024),
        );
    }

    private function timeoutSeconds(): int
    {
        return max(5, (int) config('geoflow.ai_visibility.http_timeout_seconds', 60));
    }

    private function connectTimeoutSeconds(): int
    {
        return max(1, (int) config('geoflow.ai_visibility.http_connect_timeout_seconds', 10));
    }

    private function retryAttempts(): int
    {
        return max(1, (int) config('geoflow.ai_visibility.http_retry_attempts', 2));
    }

    private function retrySleepMs(): int
    {
        return max(0, (int) config('geoflow.ai_visibility.http_retry_sleep_ms', 300));
    }
}
