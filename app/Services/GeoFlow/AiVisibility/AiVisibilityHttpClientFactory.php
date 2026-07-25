<?php

namespace App\Services\GeoFlow\AiVisibility;

use App\Support\GeoFlow\OutboundHttpProxy;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;

final class AiVisibilityHttpClientFactory
{
    public function jsonRequest(string $endpoint): PendingRequest
    {
        return Http::acceptJson()
            ->asJson()
            ->withOptions(OutboundHttpProxy::httpClientOptionsForUrl($endpoint))
            ->timeout($this->timeoutSeconds())
            ->connectTimeout($this->connectTimeoutSeconds())
            ->retry($this->retryAttempts(), $this->retrySleepMs(), throw: false);
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
