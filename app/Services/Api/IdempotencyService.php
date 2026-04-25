<?php

namespace App\Services\Api;

use App\Exceptions\ApiException;
use App\Models\ApiIdempotencyKey;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class IdempotencyService
{
    public static function normalizePayload(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        if (array_is_list($value)) {
            return array_map([self::class, 'normalizePayload'], $value);
        }

        ksort($value);
        foreach ($value as $key => $item) {
            $value[$key] = self::normalizePayload($item);
        }

        return $value;
    }

    /**
     * @param  array<string, mixed>  $body
     */
    public static function requestHash(array $body): string
    {
        $normalized = self::normalizePayload($body);

        return hash('sha256', json_encode($normalized, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    /**
     * @return array{payload: array<string, mixed>, status: int}|null
     */
    public static function loadReplay(string $idempotencyKey, string $routeKey, string $requestHash): ?array
    {
        $row = ApiIdempotencyKey::query()
            ->where('idempotency_key', $idempotencyKey)
            ->where('route_key', $routeKey)
            ->first();

        if (! $row) {
            return null;
        }

        if ($row->request_hash !== $requestHash) {
            throw new ApiException('idempotency_conflict', '同一个幂等键对应了不同的请求内容', 409);
        }

        $decoded = json_decode((string) $row->response_body, true);
        if (! is_array($decoded)) {
            throw new ApiException('idempotency_corrupted', '幂等缓存数据损坏', 500);
        }

        return [
            'status' => (int) $row->response_status,
            'payload' => $decoded,
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public static function store(string $idempotencyKey, string $routeKey, string $requestHash, array $payload, int $status): void
    {
        ApiIdempotencyKey::query()->upsert(
            [
                [
                    'idempotency_key' => $idempotencyKey,
                    'route_key' => $routeKey,
                    'request_hash' => $requestHash,
                    'response_body' => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                    'response_status' => $status,
                    'created_at' => now(),
                    'updated_at' => now(),
                ],
            ],
            ['idempotency_key', 'route_key'],
            ['request_hash', 'response_body', 'response_status', 'updated_at']
        );
    }

    public static function maybeReplayJson(Request $request, string $routeKey): ?JsonResponse
    {
        $key = $request->header('X-Idempotency-Key');
        if (! is_string($key) || $key === '' || ! in_array($request->method(), ['POST', 'PATCH'], true)) {
            return null;
        }

        $hash = self::requestHash($request->all());
        $replay = self::loadReplay($key, $routeKey, $hash);
        if ($replay === null) {
            return null;
        }

        return response()->json($replay['payload'], $replay['status']);
    }

    /**
     * @param  array<string, mixed>  $envelope
     */
    public static function remember(Request $request, string $routeKey, array $envelope, int $status): void
    {
        $key = $request->header('X-Idempotency-Key');
        if (! is_string($key) || $key === '' || ! in_array($request->method(), ['POST', 'PATCH'], true)) {
            return;
        }

        $hash = self::requestHash($request->all());
        self::store($key, $routeKey, $hash, $envelope, $status);
    }

    public static function rememberFromResponse(Request $request, string $routeKey, JsonResponse $response): void
    {
        $decoded = json_decode($response->getContent(), true);
        if (! is_array($decoded)) {
            return;
        }

        self::remember($request, $routeKey, $decoded, $response->getStatusCode());
    }
}
