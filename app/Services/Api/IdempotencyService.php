<?php

namespace App\Services\Api;

use App\Exceptions\ApiException;
use App\Http\ApiAuthContext;
use App\Models\ApiIdempotencyKey;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use JsonException;
use Throwable;

/**
 * API 写接口幂等缓存服务。
 */
class IdempotencyService
{
    private const LEASE_SECONDS = 300;

    private const FINGERPRINT_VERSION_V1 = 1;

    private const FINGERPRINT_VERSION_V2 = 2;

    /**
     * 递归规范化请求载荷，确保关联数组按键排序后生成稳定哈希。
     */
    public static function normalizePayload(mixed $value): mixed
    {
        if ($value instanceof UploadedFile) {
            $realPath = $value->getRealPath();
            $contentHash = is_string($realPath) && is_file($realPath) && is_readable($realPath)
                ? hash_file('sha256', $realPath)
                : false;

            return [
                'client_name' => $value->getClientOriginalName(),
                'client_type' => $value->getClientMimeType(),
                'size' => $value->getSize(),
                'error' => $value->getError(),
                'content_sha256' => is_string($contentHash) ? $contentHash : null,
            ];
        }

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

        $fingerprintVersion = (int) ($row->fingerprint_version ?? self::FINGERPRINT_VERSION_V1);
        $expectedHash = self::expectedHashForVersion($fingerprintVersion, $requestHash);

        if ($row->request_hash !== $expectedHash) {
            throw new ApiException('idempotency_conflict', '同一个幂等键对应了不同的请求内容', 409);
        }

        if ($row->state === 'in_progress') {
            if (! is_string($row->owner_token)
                || preg_match('/^[a-f0-9]{64}$/D', $row->owner_token) !== 1
                || $row->lease_expires_at === null
                || (int) $row->response_status !== 0) {
                throw new ApiException('idempotency_corrupted', '幂等预留数据损坏', 500);
            }
            if ($row->lease_expires_at->isPast()) {
                throw new ApiException('idempotency_stale', '幂等预留已过期，需要人工确认处理结果', 409, [
                    'retryable' => false,
                ]);
            }

            throw new ApiException('idempotency_in_progress', '相同幂等键的请求正在处理中', 409);
        }
        if ($row->state !== 'completed') {
            throw new ApiException('idempotency_corrupted', '幂等缓存状态损坏', 500);
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
            'fingerprint_version' => self::FINGERPRINT_VERSION_V2,
            'state' => 'completed',
            'owner_token' => null,
            'lease_expires_at' => null,
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

        if (! $row) {
            throw new ApiException('idempotency_corrupted', '幂等缓存数据丢失', 500);
        }

        $fingerprintVersion = (int) ($row->fingerprint_version ?? self::FINGERPRINT_VERSION_V1);
        if ($fingerprintVersion === self::FINGERPRINT_VERSION_V1) {
            throw new ApiException(
                'idempotency_result_uncertain',
                '业务操作可能已完成，幂等记录被旧版本进程占用，请人工确认结果后再处理',
                409,
                ['retryable' => false, 'use_new_key' => false],
            );
        }

        $expectedHash = self::expectedHashForVersion($fingerprintVersion, $requestHash);
        if ($row->request_hash !== $expectedHash) {
            throw new ApiException('idempotency_conflict', '同一个幂等键对应了不同的请求内容', 409);
        }
    }

    /**
     * 如果当前请求命中幂等缓存，则直接返回缓存中的 JSON 响应。
     */
    public static function maybeReplayJson(Request $request, string $routeKey): ?JsonResponse
    {
        $key = self::validatedKey($request);
        if ($key === null || ! in_array($request->method(), ['POST', 'PATCH'], true)) {
            return null;
        }

        $hash = self::requestHashFor($request, $routeKey);
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
        $key = self::validatedKey($request);
        if ($key === null || ! in_array($request->method(), ['POST', 'PATCH'], true)) {
            return;
        }

        $hash = self::requestHashFor($request, $routeKey);
        self::store($key, $routeKey, $hash, $envelope, $status);
    }

    /**
     * 先独立提交预留，再原子提交业务变更和最终响应；缓存锁仅提供快速互斥。
     */
    public static function executeJson(Request $request, string $routeKey, Closure $operation, ?Closure $operationGuard = null): JsonResponse
    {
        $runGuarded = static function (Closure $callback) use ($operationGuard): JsonResponse {
            return $operationGuard !== null ? $operationGuard($callback) : $callback();
        };
        $key = self::validatedKey($request);
        if ($key === null || ! in_array($request->method(), ['POST', 'PATCH'], true)) {
            return $runGuarded($operation);
        }

        self::requestHashFor($request, $routeKey);

        $lock = Cache::lock(self::lockName($key, $routeKey), self::LEASE_SECONDS);
        if (! $lock->get()) {
            throw new ApiException('idempotency_in_progress', '相同幂等键的请求正在处理中', 409);
        }

        try {
            $cached = self::maybeReplayJson($request, $routeKey);
            if ($cached !== null) {
                return $cached;
            }

            $requestHash = self::requestHashFor($request, $routeKey);
            $reservation = self::reserve($key, $routeKey, $requestHash);
            if ($reservation['replay'] !== null) {
                return response()->json(
                    $reservation['replay']['payload'],
                    $reservation['replay']['status'],
                );
            }

            try {
                return $runGuarded(fn (): JsonResponse => DB::transaction(function () use ($requestHash, $reservation, $operation): JsonResponse {
                    self::claimReservation($reservation['row_id'], $requestHash, $reservation['owner_token']);
                    $response = $operation();
                    self::completeReservation(
                        $reservation['row_id'],
                        $requestHash,
                        $reservation['owner_token'],
                        $response,
                    );

                    return $response;
                }));
            } catch (Throwable $exception) {
                self::releaseReservation($reservation['row_id'], $reservation['owner_token']);

                throw $exception;
            }
        } finally {
            $lock->release();
        }
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

    private static function requestHashFor(Request $request, string $routeKey): string
    {
        $attribute = 'geoflow.idempotency_hash.'.hash('sha256', $routeKey);
        $cached = $request->attributes->get($attribute);
        if (is_string($cached)) {
            return $cached;
        }

        $target = '/'.ltrim($request->getPathInfo(), '/');
        $auth = $request->attributes->get('api_auth');
        $tokenId = $auth instanceof ApiAuthContext ? ($auth->token['id'] ?? null) : null;
        $hash = self::requestHash([
            'request' => [
                'method' => strtoupper($request->method()),
                'target' => $target,
                'token_id' => is_numeric($tokenId) ? (int) $tokenId : null,
            ],
            'payload' => $request->all(),
        ]);
        $request->attributes->set($attribute, $hash);

        return $hash;
    }

    private static function expectedHashForVersion(
        int $fingerprintVersion,
        string $requestHash,
    ): string {
        return match ($fingerprintVersion) {
            self::FINGERPRINT_VERSION_V1 => throw new ApiException(
                'idempotency_upgrade_required',
                '历史幂等记录缺少调用方和目标绑定，请使用新的幂等键重试',
                409,
                ['retryable' => false, 'use_new_key' => true],
            ),
            self::FINGERPRINT_VERSION_V2 => $requestHash,
            default => throw new ApiException('idempotency_corrupted', '幂等指纹版本损坏', 500),
        };
    }

    private static function lockName(string $idempotencyKey, string $routeKey): string
    {
        return 'geoflow:idempotency:'.hash('sha256', $routeKey."\0".$idempotencyKey);
    }

    private static function validatedKey(Request $request): ?string
    {
        $key = $request->header('X-Idempotency-Key');
        if (! is_string($key) || $key === '') {
            return null;
        }
        if (strlen($key) > 120 || preg_match('/^[A-Za-z0-9][A-Za-z0-9._:-]*$/D', $key) !== 1) {
            throw new ApiException('invalid_idempotency_key', 'X-Idempotency-Key 格式无效', 422);
        }

        return $key;
    }

    /**
     * @return array{row_id:int,owner_token:string,replay:?array{payload:array<string,mixed>,status:int}}
     */
    private static function reserve(string $idempotencyKey, string $routeKey, string $requestHash): array
    {
        $now = now();
        $ownerToken = bin2hex(random_bytes(32));
        $inserted = ApiIdempotencyKey::query()->insertOrIgnore([
            'idempotency_key' => $idempotencyKey,
            'route_key' => $routeKey,
            'request_hash' => $requestHash,
            'response_body' => '{}',
            'response_status' => 0,
            'fingerprint_version' => self::FINGERPRINT_VERSION_V2,
            'state' => 'in_progress',
            'owner_token' => $ownerToken,
            'lease_expires_at' => $now->copy()->addSeconds(self::LEASE_SECONDS),
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $row = ApiIdempotencyKey::query()
            ->where('idempotency_key', $idempotencyKey)
            ->where('route_key', $routeKey)
            ->firstOrFail();

        if ($inserted === 0) {
            return [
                'row_id' => (int) $row->getKey(),
                'owner_token' => '',
                'replay' => self::loadReplay($idempotencyKey, $routeKey, $requestHash),
            ];
        }

        return [
            'row_id' => (int) $row->getKey(),
            'owner_token' => $ownerToken,
            'replay' => null,
        ];
    }

    private static function claimReservation(int $reservationId, string $requestHash, string $ownerToken): void
    {
        $reservation = ApiIdempotencyKey::query()->whereKey($reservationId)->lockForUpdate()->first();
        if (! $reservation
            || $reservation->request_hash !== $requestHash
            || (int) $reservation->fingerprint_version !== self::FINGERPRINT_VERSION_V2
            || $reservation->state !== 'in_progress'
            || ! hash_equals((string) $reservation->owner_token, $ownerToken)) {
            throw new ApiException('idempotency_claim_failed', '幂等预留所有权校验失败', 409);
        }
    }

    private static function completeReservation(int $reservationId, string $requestHash, string $ownerToken, JsonResponse $response): void
    {
        $payload = json_decode($response->getContent(), true);
        if (! is_array($payload)) {
            throw new ApiException('idempotency_encode_failed', '幂等响应无法持久化', 500);
        }

        $updated = ApiIdempotencyKey::query()
            ->whereKey($reservationId)
            ->where('request_hash', $requestHash)
            ->where('fingerprint_version', self::FINGERPRINT_VERSION_V2)
            ->where('state', 'in_progress')
            ->where('owner_token', $ownerToken)
            ->update([
                'response_body' => self::encodeJson($payload),
                'response_status' => $response->getStatusCode(),
                'state' => 'completed',
                'owner_token' => null,
                'lease_expires_at' => null,
                'updated_at' => now(),
            ]);

        if ($updated !== 1) {
            throw new ApiException('idempotency_finalize_failed', '幂等响应持久化失败', 500);
        }
    }

    private static function releaseReservation(int $reservationId, string $ownerToken): void
    {
        try {
            ApiIdempotencyKey::query()
                ->whereKey($reservationId)
                ->where('state', 'in_progress')
                ->where('owner_token', $ownerToken)
                ->delete();
        } catch (Throwable $exception) {
            Log::error('geoflow.idempotency_reservation_release_failed', [
                'reservation_id' => $reservationId,
                'error' => $exception->getMessage(),
            ]);
        }
    }
}
