<?php

namespace App\Services\Outbound;

use Closure;
use GuzzleHttp\Promise\PromiseInterface;
use GuzzleHttp\Psr7\Utils;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

final class FinalOutboundSecurityPolicy
{
    /** @param (Closure(bool): bool)|null $transportAvailable */
    public function __construct(private readonly ?Closure $transportAvailable = null) {}

    /**
     * @param  array<string, mixed>  $trustedOptions
     * @param  Closure(string): ResolvedOutboundTarget  $resolveTarget
     */
    public function dynamicMiddleware(array $trustedOptions, Closure $resolveTarget): callable
    {
        return function (callable $handler) use ($trustedOptions, $resolveTarget): callable {
            return function (RequestInterface $request, array $options) use ($handler, $trustedOptions, $resolveTarget) {
                $target = $resolveTarget((string) $request->getUri());
                $maxBytes = $this->responseLimit($request, $options);

                return $this->handle(
                    $handler,
                    $request,
                    $options,
                    $trustedOptions,
                    $target,
                    $request->getMethod(),
                    false,
                    $maxBytes,
                    (bool) ($options['stream'] ?? false),
                );
            };
        };
    }

    /** @param array<string, mixed> $trustedOptions */
    public function fixedTargetMiddleware(
        array $trustedOptions,
        ResolvedOutboundTarget $target,
        string $method,
        bool $crossOrigin,
        int $maxBytes,
        bool $streamingRequested,
    ): callable {
        return function (callable $handler) use (
            $trustedOptions,
            $target,
            $method,
            $crossOrigin,
            $maxBytes,
            $streamingRequested,
        ): callable {
            return function (RequestInterface $request, array $options) use (
                $handler,
                $trustedOptions,
                $target,
                $method,
                $crossOrigin,
                $maxBytes,
                $streamingRequested,
            ) {
                return $this->handle(
                    $handler,
                    $request,
                    $options,
                    $trustedOptions,
                    $target,
                    $method,
                    $crossOrigin,
                    $maxBytes,
                    $streamingRequested,
                );
            };
        };
    }

    /**
     * @param  array<string, mixed>  $options
     * @param  array<string, mixed>  $trustedOptions
     */
    private function handle(
        callable $handler,
        RequestInterface $request,
        array $options,
        array $trustedOptions,
        ResolvedOutboundTarget $target,
        string $method,
        bool $crossOrigin,
        int $maxBytes,
        bool $streamingRequested,
    ): mixed {
        $this->assertPinnableTransport($target);
        $limitExceeded = false;
        $policyFailureReason = null;
        $timeout = $this->safeTimeout($trustedOptions['timeout'] ?? 30, 30, 300);
        $connectTimeout = $this->safeTimeout($trustedOptions['connect_timeout'] ?? 10, 10, 60);
        $callerOnHeaders = is_callable($trustedOptions['on_headers'] ?? null) ? $trustedOptions['on_headers'] : null;
        $callerProgress = is_callable($trustedOptions['progress'] ?? null) ? $trustedOptions['progress'] : null;
        $onHeaders = static function (ResponseInterface $response) use (
            $maxBytes,
            &$limitExceeded,
            &$policyFailureReason,
            $callerOnHeaders,
        ): void {
            $encoding = strtolower(trim($response->getHeaderLine('Content-Encoding')));
            if ($encoding !== '' && $encoding !== 'identity') {
                $policyFailureReason = 'encoded_response_forbidden';
                throw new OutboundRequestBlockedException('encoded_response_forbidden');
            }
            $declared = $response->getHeaderLine('Content-Length');
            if (is_numeric($declared) && (int) $declared > $maxBytes) {
                $limitExceeded = true;
                throw new OutboundRequestBlockedException('response_too_large');
            }
            if ($callerOnHeaders !== null) {
                $callerOnHeaders($response);
            }
        };
        $request = CrossOriginRequestSanitizer::finalRequest(
            $request,
            $method,
            $target->url,
            $crossOrigin,
        );
        $curl = $this->secureCurlOptions(
            $target,
            (string) $request->getUri()->withFragment(''),
            $maxBytes,
            $callerProgress,
            $limitExceeded,
        );
        $options = $this->secureHandlerOptions(
            $options,
            $trustedOptions,
            $target,
            $crossOrigin,
            $timeout,
            $connectTimeout,
            $onHeaders,
            $curl,
        );

        try {
            $result = $handler($request, $options);

            return $this->guardHandlerResult(
                $result,
                $maxBytes,
                $streamingRequested,
                $limitExceeded,
                $policyFailureReason,
            );
        } catch (\Throwable $exception) {
            $this->throwNormalizedFailure($exception, $limitExceeded, $policyFailureReason);
        }
    }

    private function guardHandlerResult(
        mixed $result,
        int $maxBytes,
        bool $streaming,
        bool &$limitExceeded,
        ?string &$policyFailureReason,
    ): mixed {
        if ($result instanceof PromiseInterface) {
            return $result->then(
                function (mixed $response) use ($maxBytes, $streaming): mixed {
                    try {
                        return $response instanceof ResponseInterface
                            ? $this->guardResponse($response, $maxBytes, $streaming)
                            : $response;
                    } catch (\Throwable $exception) {
                        $this->throwNormalizedFailure($exception, false, null);
                    }
                },
                function (mixed $reason) use (&$limitExceeded, &$policyFailureReason): never {
                    $this->throwNormalizedFailure($reason, $limitExceeded, $policyFailureReason);
                },
            );
        }

        return $result instanceof ResponseInterface
            ? $this->guardResponse($result, $maxBytes, $streaming)
            : $result;
    }

    private function guardResponse(ResponseInterface $response, int $maxBytes, bool $streaming): ResponseInterface
    {
        $encoding = strtolower(trim($response->getHeaderLine('Content-Encoding')));
        if ($encoding !== '' && $encoding !== 'identity') {
            throw new OutboundRequestBlockedException('encoded_response_forbidden');
        }
        $body = $response->getBody();
        $size = $body->getSize();
        if (is_int($size) && $size > $maxBytes) {
            throw new OutboundRequestBlockedException('response_too_large');
        }
        if ($streaming) {
            return $response->withBody(new ResponseSizeLimitedStream($body, $maxBytes));
        }

        $contents = (string) $body;
        if (strlen($contents) > $maxBytes) {
            throw new OutboundRequestBlockedException('response_too_large');
        }

        return $response->withBody(Utils::streamFor($contents));
    }

    /**
     * @param  array<string, mixed>  $current
     * @param  array<string, mixed>  $trusted
     * @param  array<int, mixed>  $curl
     * @return array<string, mixed>
     */
    private function secureHandlerOptions(
        array $current,
        array $trusted,
        ResolvedOutboundTarget $target,
        bool $crossOrigin,
        int|float $timeout,
        int|float $connectTimeout,
        callable $onHeaders,
        array $curl,
    ): array {
        $secure = [
            'allow_redirects' => false,
            'connect_timeout' => $connectTimeout,
            'crypto_method' => STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT,
            'curl' => $curl,
            'decode_content' => false,
            'force_ip_resolve' => str_contains($target->selectedIp, ':') ? 'v6' : 'v4',
            'http_errors' => false,
            'on_headers' => $onHeaders,
            'proxy' => '',
            'stream' => false,
            'timeout' => $timeout,
            'verify' => true,
        ];
        foreach (['sink', 'on_stats', 'laravel_data'] as $key) {
            if (array_key_exists($key, $current)) {
                $secure[$key] = $current[$key];
            }
        }
        if (isset($current['delay']) && is_numeric($current['delay'])) {
            $secure['delay'] = max(0, min(300000, (int) $current['delay']));
        }
        if (array_key_exists('synchronous', $current)) {
            $secure['synchronous'] = (bool) $current['synchronous'];
        }
        if (! $crossOrigin) {
            foreach (['cert', 'ssl_key'] as $key) {
                if (array_key_exists($key, $trusted)) {
                    $secure[$key] = $trusted[$key];
                }
            }
        }

        return $secure;
    }

    /** @return array<int, mixed> */
    private function secureCurlOptions(
        ResolvedOutboundTarget $target,
        string $url,
        int $maxBytes,
        ?callable $callerProgress,
        bool &$limitExceeded,
    ): array {
        $ipLiteral = filter_var($target->host, FILTER_VALIDATE_IP) !== false;
        $options = [
            CURLOPT_URL => $url,
            CURLOPT_PORT => $target->port,
            CURLOPT_PROXY => '',
            CURLOPT_NOPROXY => '*',
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_MAXREDIRS => 0,
            CURLOPT_NOPROGRESS => false,
            CURLOPT_IPRESOLVE => str_contains($target->selectedIp, ':') ? CURL_IPRESOLVE_V6 : CURL_IPRESOLVE_V4,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_XFERINFOFUNCTION => static function (
                $handle,
                float $downloadTotal,
                float $downloaded,
                float $uploadTotal,
                float $uploaded,
            ) use ($maxBytes, $callerProgress, &$limitExceeded): int {
                if ($callerProgress !== null) {
                    $callerProgress($downloadTotal, $downloaded, $uploadTotal, $uploaded);
                }
                if ($downloadTotal > $maxBytes || $downloaded > $maxBytes) {
                    $limitExceeded = true;

                    return 1;
                }

                return 0;
            },
        ];
        if (! $ipLiteral) {
            $pinnedIp = str_contains($target->selectedIp, ':') ? '['.$target->selectedIp.']' : $target->selectedIp;
            $options[CURLOPT_RESOLVE] = [$target->host.':'.$target->port.':'.$pinnedIp];
        }
        $options[CURLOPT_ENCODING] = 'identity';
        if (defined('CURLOPT_PROTOCOLS')) {
            $options[CURLOPT_PROTOCOLS] = CURLPROTO_HTTP | CURLPROTO_HTTPS;
        }
        if (defined('CURLOPT_REDIR_PROTOCOLS')) {
            $options[CURLOPT_REDIR_PROTOCOLS] = CURLPROTO_HTTP | CURLPROTO_HTTPS;
        }

        return $options;
    }

    private function responseLimit(RequestInterface $request, array $options): int
    {
        $globalMaximum = max(1, (int) config('geoflow.update_archive_max_bytes', 50 * 1024 * 1024));
        $explicit = $options['geoflow_response_max_bytes'] ?? null;
        if (is_numeric($explicit) && (int) $explicit > 0) {
            $globalMaximum = min($globalMaximum, (int) $explicit);
        }
        $path = strtolower($request->getUri()->getPath());
        if (preg_match('~(?:chat/completions|/responses|/embeddings|embedcontents|generatecontent|/models)~', $path) === 1) {
            return min($globalMaximum, max(1, (int) config('geoflow.outbound_ai_max_bytes', 8 * 1024 * 1024)));
        }

        return $globalMaximum;
    }

    private function safeTimeout(mixed $value, int|float $default, int|float $maximum): int|float
    {
        if (! is_numeric($value) || (float) $value <= 0) {
            return $default;
        }
        $value = min((float) $value, (float) $maximum);

        return floor($value) === $value ? (int) $value : $value;
    }

    private function assertPinnableTransport(ResolvedOutboundTarget $target): void
    {
        $requiresDnsPin = filter_var($target->host, FILTER_VALIDATE_IP) === false;
        if ($this->transportAvailable !== null) {
            if (! ($this->transportAvailable)($requiresDnsPin)) {
                throw new OutboundRequestBlockedException('pinning_unavailable');
            }
        }
        $requiredConstants = [
            'CURL_IPRESOLVE_V4',
            'CURL_IPRESOLVE_V6',
            'CURLOPT_CUSTOMREQUEST',
            'CURLOPT_ENCODING',
            'CURLOPT_FOLLOWLOCATION',
            'CURLOPT_IPRESOLVE',
            'CURLOPT_MAXREDIRS',
            'CURLOPT_NOPROGRESS',
            'CURLOPT_NOPROXY',
            'CURLOPT_PORT',
            'CURLOPT_PROXY',
            'CURLOPT_SSL_VERIFYHOST',
            'CURLOPT_SSL_VERIFYPEER',
            'CURLOPT_URL',
            'CURLOPT_XFERINFOFUNCTION',
            'STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT',
        ];
        if (
            ! $this->constantsDefined($requiredConstants)
            || ($requiresDnsPin && ! defined('CURLOPT_RESOLVE'))
            || (defined('CURLOPT_PROTOCOLS') && (! defined('CURLPROTO_HTTP') || ! defined('CURLPROTO_HTTPS')))
            || (defined('CURLOPT_REDIR_PROTOCOLS') && (! defined('CURLPROTO_HTTP') || ! defined('CURLPROTO_HTTPS')))
            || ! function_exists('curl_version')
            || (! function_exists('curl_exec') && ! function_exists('curl_multi_exec'))
        ) {
            throw new OutboundRequestBlockedException('pinning_unavailable');
        }
        $curlInfo = curl_version();
        $version = is_array($curlInfo) ? ($curlInfo['version'] ?? '0') : '0';
        if (! version_compare((string) $version, '7.21.2', '>=')) {
            throw new OutboundRequestBlockedException('pinning_unavailable');
        }
    }

    /** @param list<string> $constants */
    private function constantsDefined(array $constants): bool
    {
        foreach ($constants as $constant) {
            if (! defined($constant)) {
                return false;
            }
        }

        return true;
    }

    private function throwNormalizedFailure(mixed $exception, bool $limitExceeded, ?string $policyFailureReason): never
    {
        if ($limitExceeded) {
            throw new OutboundRequestBlockedException('response_too_large');
        }
        if (is_string($policyFailureReason) && $policyFailureReason !== '') {
            throw new OutboundRequestBlockedException($policyFailureReason);
        }
        if ($exception instanceof OutboundRequestBlockedException || $exception instanceof OutboundRequestFailedException) {
            throw $exception;
        }

        throw new OutboundRequestFailedException;
    }
}
