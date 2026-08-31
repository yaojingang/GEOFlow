<?php

namespace App\Services\Outbound;

use RuntimeException;
use Throwable;

final class OutboundRequestFailedException extends RuntimeException
{
    public readonly string $reasonCode;

    public readonly ?string $causeType;

    public readonly string $transportCategory;

    public readonly string $providerCategory;

    public readonly ?int $httpStatus;

    public readonly ?string $providerCode;

    public function __construct(
        ?Throwable $previous = null,
        ?int $httpStatus = null,
        ?string $providerCode = null,
    ) {
        [$derivedStatus, $derivedProviderCode, $providerCategory] = $this->providerFailureContext($previous);
        $this->httpStatus = $httpStatus ?? $derivedStatus;
        $this->providerCode = $this->safeProviderCode($providerCode ?? $derivedProviderCode);
        $this->reasonCode = 'outbound_request_failed';
        $this->causeType = $previous?->getPrevious() instanceof OutboundRequestCauseException
            ? $previous->getPrevious()->causeType
            : ($previous instanceof OutboundRequestCauseException ? $previous->causeType : ($previous ? $previous::class : null));
        $this->transportCategory = $this->classifyTransportFailure($previous, $this->httpStatus);
        $this->providerCategory = $providerCategory !== 'unknown'
            ? $providerCategory
            : $this->classifyProviderFailure($this->httpStatus, '', (string) $this->providerCode);
        $redactedPrevious = $previous instanceof OutboundRequestCauseException
            ? $previous
            : ($previous ? new OutboundRequestCauseException($previous::class) : null);

        parent::__construct('Outbound request failed.', 0, $redactedPrevious);
    }

    private function classifyTransportFailure(?Throwable $exception, ?int $httpStatus): string
    {
        if (in_array($httpStatus, [502, 503, 504], true)) {
            return 'gateway';
        }
        if ($httpStatus === 429) {
            return 'rate_limited';
        }

        $types = [];
        $messages = [];
        $current = $exception;
        for ($depth = 0; $depth < 6 && $current instanceof Throwable; $depth++) {
            $types[] = strtolower($current::class);
            $messages[] = strtolower($current->getMessage());
            $current = $current->getPrevious();
        }
        $typeText = implode("\n", $types);
        $messageText = implode("\n", $messages);

        if (str_contains($typeText, 'timeoutexception')
            || str_contains($messageText, 'curl error 28')
            || str_contains($messageText, 'timed out')
            || str_contains($messageText, 'timeout')) {
            return 'timeout';
        }
        if (str_contains($messageText, 'curl error 35')
            || str_contains($messageText, 'curl error 51')
            || str_contains($messageText, 'curl error 60')
            || str_contains($messageText, 'certificate')
            || str_contains($messageText, 'tls failed')
            || str_contains($messageText, 'ssl')) {
            return 'tls';
        }
        if (preg_match('/curl error 6(?:\D|$)/', $messageText) === 1
            || str_contains($messageText, 'could not resolve')
            || str_contains($messageText, 'name or service not known')) {
            return 'dns';
        }
        if (str_contains($typeText, 'connectexception')
            || str_contains($typeText, 'connectionexception')) {
            return 'connection';
        }

        return 'unknown';
    }

    /** @return array{?int,?string,string} */
    private function providerFailureContext(?Throwable $exception): array
    {
        $status = null;
        $providerCode = null;
        $providerCategory = 'unknown';
        $current = $exception;

        for ($depth = 0; $depth < 6 && $current instanceof Throwable; $depth++) {
            $response = null;
            if (isset($current->response) && is_object($current->response)) {
                $response = $current->response;
            } elseif (method_exists($current, 'getResponse')) {
                $candidate = $current->getResponse();
                $response = is_object($candidate) ? $candidate : null;
            }

            if ($response !== null) {
                if ($status === null && method_exists($response, 'status')) {
                    $candidateStatus = $response->status();
                    $status = is_int($candidateStatus) ? $candidateStatus : null;
                }
                if ($status === null && method_exists($response, 'getStatusCode')) {
                    $candidateStatus = $response->getStatusCode();
                    $status = is_int($candidateStatus) ? $candidateStatus : null;
                }

                $payload = method_exists($response, 'json') ? $response->json() : null;
                if (is_array($payload)) {
                    $error = is_array($payload['error'] ?? null) ? $payload['error'] : [];
                    $candidateCode = $error['code'] ?? $error['type'] ?? null;
                    if ($providerCode === null && is_string($candidateCode)) {
                        $providerCode = $candidateCode;
                    }
                    $candidateCategory = $this->classifyProviderFailure(
                        $status,
                        (string) ($error['message'] ?? ''),
                        (string) ($candidateCode ?? ''),
                    );
                    if ($candidateCategory !== 'unknown') {
                        $providerCategory = $candidateCategory;
                    }
                }
            }

            $current = $current->getPrevious();
        }

        if ($providerCategory === 'unknown') {
            $providerCategory = $this->classifyProviderFailure($status, '', (string) $providerCode);
        }

        return [$status, $providerCode, $providerCategory];
    }

    private function classifyProviderFailure(?int $status, string $message, string $code): string
    {
        $normalized = strtolower($message."\n".$code);

        return match (true) {
            $status === 402,
            str_contains($normalized, 'insufficient balance'),
            str_contains($normalized, 'quota'),
            str_contains($normalized, 'credit') => 'quota_exhausted',
            in_array($status, [401, 403], true),
            str_contains($normalized, 'authentication'),
            str_contains($normalized, 'invalid api key') => 'authentication',
            $status === 429,
            str_contains($normalized, 'rate limit') => 'rate_limited',
            default => 'unknown',
        };
    }

    private function safeProviderCode(?string $providerCode): ?string
    {
        $providerCode = trim((string) $providerCode);
        if ($providerCode === '' || strlen($providerCode) > 80) {
            return null;
        }

        return preg_match('/^[A-Za-z0-9_.:-]+$/', $providerCode) === 1 ? $providerCode : null;
    }
}
