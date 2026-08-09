<?php

namespace App\Console\GeoFlowCli;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use JsonException;
use Psr\Http\Message\ResponseInterface;
use Throwable;

class ApiClient
{
    public const MAX_RESPONSE_BYTES = 5 * 1024 * 1024;

    private bool $responseLimitExceeded = false;

    private bool $unsupportedResponseEncoding = false;

    public function __construct(
        private readonly HttpFactory $httpFactory,
        private readonly string $baseUrl,
        private readonly ?string $token,
        private readonly int $timeout,
    ) {}

    /**
     * @param  array<string,int|string>  $pathParameters
     * @param  array<string,mixed>  $query
     * @param  array<string,mixed>|null  $body
     */
    public function send(
        string $operationName,
        array $pathParameters = [],
        array $query = [],
        ?array $body = null,
        ?string $idempotencyKey = null,
        ?string $uploadPath = null,
    ): ApiResult {
        $this->responseLimitExceeded = false;
        $this->unsupportedResponseEncoding = false;
        $operation = OperationRegistry::get($operationName);
        $secrets = array_values(array_filter(array_merge(
            [$this->token],
            SecretRedactor::sensitiveValues($body ?? []),
        ), static fn (mixed $value): bool => is_string($value) && $value !== ''));
        $path = $this->interpolatePath($operation['path'], $pathParameters);
        $url = rtrim($this->baseUrl, '/').'/api/v1/'.$path;
        $pendingRequest = $this->pendingRequest($operation['auth']);

        if ($operation['idempotent'] && is_string($idempotencyKey) && trim($idempotencyKey) !== '') {
            $pendingRequest->withHeader('X-Idempotency-Key', trim($idempotencyKey));
        }

        try {
            $response = $uploadPath === null
                ? $this->sendJson($pendingRequest, $operation['method'], $url, $query, $body)
                : $this->sendUpload($pendingRequest, $operation['method'], $url, $uploadPath);
        } catch (ConnectionException $exception) {
            if ($this->unsupportedResponseEncoding) {
                throw new CliException('服务端返回了压缩响应；为保证 5 MiB 安全上限，CLI 只接受 identity 编码');
            }
            if ($this->causedByResponseLimit($exception)) {
                throw new CliException('服务端响应超过 5 MiB 安全上限');
            }
            throw new CliException('请求失败: '.SecretRedactor::text($exception->getMessage(), ...$secrets));
        } catch (Throwable $exception) {
            if ($this->unsupportedResponseEncoding) {
                throw new CliException('服务端返回了压缩响应；为保证 5 MiB 安全上限，CLI 只接受 identity 编码');
            }
            if ($this->causedByResponseLimit($exception)) {
                throw new CliException('服务端响应超过 5 MiB 安全上限');
            }

            throw $exception;
        }

        return $this->parseResponse($response, $secrets);
    }

    private function pendingRequest(bool $requiresAuth): PendingRequest
    {
        $headers = [
            'X-Request-Id' => $this->requestId(),
            'Accept-Encoding' => 'identity',
        ];
        if ($requiresAuth) {
            if (! is_string($this->token) || trim($this->token) === '') {
                throw new CliException('当前请求需要认证，请先运行 geoflow login');
            }
            if (preg_match('/[\x00-\x1F\x7F]/', $this->token) === 1) {
                throw new CliException('token 包含禁止的控制字符');
            }
            $headers['Authorization'] = 'Bearer '.$this->token;
        }

        return $this->httpFactory
            ->acceptJson()
            ->withHeaders($headers)
            ->timeout($this->timeout)
            ->connectTimeout(min(10, $this->timeout))
            ->withOptions([
                'verify' => true,
                'decode_content' => false,
                'on_headers' => function (ResponseInterface $response): void {
                    $contentEncoding = strtolower(trim($response->getHeaderLine('Content-Encoding')));
                    if ($contentEncoding !== '' && $contentEncoding !== 'identity') {
                        $this->unsupportedResponseEncoding = true;
                        throw new ResponseSizeLimitException('encoded responses are not accepted');
                    }
                    $contentLength = trim($response->getHeaderLine('Content-Length'));
                    if ($contentLength !== ''
                        && preg_match('/^\d+$/', $contentLength) === 1
                        && (float) $contentLength > self::MAX_RESPONSE_BYTES) {
                        $this->responseLimitExceeded = true;
                        throw new ResponseSizeLimitException('response content length exceeds limit');
                    }
                },
                'progress' => function (
                    int|float $downloadTotal,
                    int|float $downloadedBytes,
                    int|float $uploadTotal,
                    int|float $uploadedBytes,
                ): void {
                    if ($downloadTotal > self::MAX_RESPONSE_BYTES || $downloadedBytes > self::MAX_RESPONSE_BYTES) {
                        $this->responseLimitExceeded = true;
                        throw new ResponseSizeLimitException('downloaded response bytes exceed limit');
                    }
                },
            ])
            ->withoutRedirecting();
    }

    /** @param array<string,mixed> $query @param array<string,mixed>|null $body */
    private function sendJson(
        PendingRequest $request,
        string $method,
        string $url,
        array $query,
        ?array $body,
    ): Response {
        $query = array_filter($query, static fn (mixed $value): bool => $value !== null && $value !== '');

        return match ($method) {
            'GET' => $request->get($url, $query),
            'POST' => $request->post($this->urlWithQuery($url, $query), $body ?? []),
            'PATCH' => $request->patch($this->urlWithQuery($url, $query), $body ?? []),
            'DELETE' => $request->delete($this->urlWithQuery($url, $query), $body ?? []),
            default => throw new CliException("不支持的 HTTP 方法: {$method}"),
        };
    }

    private function sendUpload(PendingRequest $request, string $method, string $url, string $path): Response
    {
        if ($method !== 'POST') {
            throw new CliException('文件上传仅支持 POST 操作');
        }
        if (is_link($path)) {
            throw new CliException("图片文件不能是符号链接: {$path}");
        }
        if (! is_file($path) || ! is_readable($path)) {
            throw new CliException("图片文件不存在或不可读: {$path}");
        }

        $stream = @fopen($path, 'rb');
        if ($stream === false) {
            throw new CliException("无法打开图片文件: {$path}");
        }

        try {
            $handleStat = fstat($stream);
            $pathStat = @lstat($path);
            if ($handleStat === false
                || $pathStat === false
                || ($handleStat['mode'] & 0170000) !== 0100000
                || ($pathStat['mode'] & 0170000) !== 0100000
                || $handleStat['dev'] !== $pathStat['dev']
                || $handleStat['ino'] !== $pathStat['ino']) {
                throw new CliException("图片文件路径在打开时发生变化或不是普通文件: {$path}");
            }

            return $request->attach('image', $stream, basename($path))->post($url);
        } finally {
            fclose($stream);
        }
    }

    /** @param list<string> $secrets */
    private function parseResponse(Response $response, array $secrets): ApiResult
    {
        $raw = $response->body();
        if (strlen($raw) > self::MAX_RESPONSE_BYTES) {
            throw new CliException('服务端响应超过 5 MiB 安全上限');
        }
        if (trim($raw) === '') {
            throw new CliException('服务端返回了空响应');
        }

        try {
            $payload = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new CliException("服务端返回了非 JSON 响应（HTTP {$response->status()}）");
        }

        if (! is_array($payload) || ! str_starts_with(ltrim($raw), '{')) {
            throw new CliException("服务端返回的 JSON 不是对象（HTTP {$response->status()}）");
        }

        if ($response->successful() && ! array_key_exists('success', $payload)) {
            $payload['success'] = false;
            $payload['error'] = [
                'code' => 'invalid_api_envelope',
                'message' => 'API 2xx 响应缺少 success=true',
            ];
        }
        if ($response->successful() && ($payload['success'] ?? null) === false && ! isset($payload['error'])) {
            $payload['error'] = [
                'code' => 'invalid_api_envelope',
                'message' => 'API 2xx 响应包含 success=false 且缺少 error',
            ];
        }

        if (! $response->successful() || ($payload['success'] ?? null) !== true) {
            $payload = SecretRedactor::payload($payload, $secrets);
            $message = SecretRedactor::text(
                (string) ($payload['error']['message'] ?? "API 请求失败（HTTP {$response->status()}）"),
                ...$secrets,
            );
            $safeRaw = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
            throw new ApiException($message, $response->status(), $payload, $safeRaw);
        }

        return new ApiResult($raw, $payload, $response->status());
    }

    /** @param array<string,int|string> $parameters */
    private function interpolatePath(string $path, array $parameters): string
    {
        foreach ($parameters as $name => $value) {
            $path = str_replace('{'.$name.'}', rawurlencode((string) $value), $path);
        }

        if (preg_match('/\{[^}]+\}/', $path) === 1) {
            throw new CliException("API 路径参数不完整: {$path}");
        }

        return $path;
    }

    /** @param array<string,mixed> $query */
    private function urlWithQuery(string $url, array $query): string
    {
        if ($query === []) {
            return $url;
        }

        return $url.'?'.http_build_query($query, '', '&', PHP_QUERY_RFC3986);
    }

    private function requestId(): string
    {
        return 'cli_'.date('Ymd_His').'_'.bin2hex(random_bytes(4));
    }

    private function causedByResponseLimit(Throwable $exception): bool
    {
        if ($this->responseLimitExceeded) {
            return true;
        }

        do {
            if ($exception instanceof ResponseSizeLimitException) {
                return true;
            }
            $exception = $exception->getPrevious();
        } while ($exception instanceof Throwable);

        return false;
    }
}
