<?php

namespace App\Services\Api;

use App\Exceptions\ApiException;
use App\Models\ApiIdempotencyKey;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use JsonException;

/**
 * API 写接口幂等缓存服务。
 */
class IdempotencyService
{
    /**
     * 递归规范化请求载荷，确保关联数组按键排序后生成稳定哈希。
     */
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
     * 生成请求体哈希，用于识别同一个幂等键是否对应相同请求内容。
     *
     * @param  array<string, mixed>  $body
     */
    public static function requestHash(array $body): string
    {
        $normalized = self::normalizePayload($body);

        return hash('sha256', self::encodeJson($normalized));
    }

    /**
     * 读取已缓存的幂等响应；若同键不同请求内容则抛出冲突异常。
     *
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
     * 首次保存幂等响应缓存；已存在相同请求时保留原响应，避免并发覆盖。
     *
     * @param  array<string, mixed>  $payload
     */
    public static function store(string $idempotencyKey, string $routeKey, string $requestHash, array $payload, int $status): void
    {
        $now = now();
        $inserted = ApiIdempotencyKey::query()->insertOrIgnore([
            'idempotency_key' => $idempotencyKey,
            'route_key' => $routeKey,
            'request_hash' => $requestHash,
            'response_body' => self::encodeJson($payload),
            'response_status' => $status,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        if ($inserted > 0) {
            return;
        }

        $row = ApiIdempotencyKey::query()
            ->where('idempotency_key', $idempotencyKey)
            ->where('route_key', $routeKey)
            ->first();

        if ($row?->request_hash !== $requestHash) {
            throw new ApiException('idempotency_conflict', '同一个幂等键对应了不同的请求内容', 409);
        }
    }

    /**
     * 如果当前请求命中幂等缓存，则直接返回缓存中的 JSON 响应。
     */
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
     * 在响应信封确定后写入幂等缓存。
     *
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

    /**
     * 从 JSON 响应中解析响应体，并在可缓存时记录幂等缓存。
     */
    public static function rememberFromResponse(Request $request, string $routeKey, JsonResponse $response): void
    {
        $decoded = json_decode($response->getContent(), true);
        if (! is_array($decoded)) {
            return;
        }

        self::remember($request, $routeKey, $decoded, $response->getStatusCode());
    }

    /**
     * 以 API 约定编码 JSON，编码失败时转换为统一异常。
     */
    private static function encodeJson(mixed $value): string
    {
        try {
            return json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new ApiException('idempotency_encode_failed', '幂等缓存数据编码失败', 500, [
                'json_error' => $exception->getMessage(),
            ]);
        }
    }
}
