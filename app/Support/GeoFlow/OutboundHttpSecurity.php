<?php

namespace App\Support\GeoFlow;

use App\Services\Outbound\OutboundRequestBlockedException;
use App\Services\Outbound\OutboundRequestFailedException;
use App\Services\Outbound\ResponseSizeLimitedStream;
use App\Services\Outbound\SafeOutboundHttpClient;
use Closure;
use GuzzleHttp\Promise\PromiseInterface;
use GuzzleHttp\Psr7\Uri;
use GuzzleHttp\Psr7\Utils;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

final class OutboundHttpSecurity
{
    /**
     * @param  Closure(): SafeOutboundHttpClient  $client
     * @param  (Closure(bool): bool)|null  $curlTransportAvailable
     */
    public static function middleware(Closure $client, ?Closure $curlTransportAvailable = null): callable
    {
        return static function (callable $handler) use ($client, $curlTransportAvailable): callable {
            return static function (RequestInterface $request, array $options) use ($client, $handler, $curlTransportAvailable) {
                $target = $client()->resolveTarget((string) $request->getUri());
                $ipLiteral = filter_var($target->host, FILTER_VALIDATE_IP) !== false;
                $requiresDnsPin = ! $ipLiteral;
                $transportAvailable = $curlTransportAvailable
                    ? (bool) $curlTransportAvailable($requiresDnsPin)
                    : self::hasPinnableCurlTransport($requiresDnsPin);
                if (! $transportAvailable) {
                    throw new OutboundRequestBlockedException('pinning_unavailable');
                }

                $maxBytes = self::responseLimit($request, $options);
                unset($options['geoflow_response_max_bytes']);
                $streamingRequested = (bool) ($options['stream'] ?? false);
                $limitExceeded = false;
                $policyFailureReason = null;
                $pinnedIp = str_contains($target->selectedIp, ':') ? '['.$target->selectedIp.']' : $target->selectedIp;
                $request = $request
                    ->withUri(new Uri($target->url), false)
                    ->withHeader('Accept-Encoding', 'identity');
                $options['allow_redirects'] = false;
                $options['decode_content'] = false;
                $options['http_errors'] = false;
                $options['proxy'] = '';
                $options['force_ip_resolve'] = str_contains($target->selectedIp, ':') ? 'v6' : 'v4';
                $options['headers'] = self::identityEncodingHeaders($options['headers'] ?? []);
                $options['stream'] = false;
                $callerOnHeaders = $options['on_headers'] ?? null;
                $options['on_headers'] = static function (ResponseInterface $response) use ($maxBytes, &$limitExceeded, &$policyFailureReason, $callerOnHeaders): void {
                    try {
                        self::assertIdentityEncodedResponse($response);
                    } catch (OutboundRequestBlockedException $exception) {
                        $policyFailureReason = $exception->reasonCode;

                        throw $exception;
                    }
                    $declared = $response->getHeaderLine('Content-Length');
                    if (is_numeric($declared) && (int) $declared > $maxBytes) {
                        $limitExceeded = true;
                        throw new OutboundRequestBlockedException('response_too_large');
                    }
                    if (is_callable($callerOnHeaders)) {
                        $callerOnHeaders($response);
                    }
                };
                $secureCurlOptions = [
                    CURLOPT_URL => $target->url,
                    CURLOPT_PORT => $target->port,
                    CURLOPT_PROXY => '',
                    CURLOPT_NOPROXY => '*',
                    CURLOPT_FOLLOWLOCATION => false,
                    CURLOPT_MAXREDIRS => 0,
                    CURLOPT_NOPROGRESS => false,
                    CURLOPT_XFERINFOFUNCTION => static function ($handle, float $downloadTotal, float $downloaded) use ($maxBytes, &$limitExceeded): int {
                        if ($downloadTotal > $maxBytes || $downloaded > $maxBytes) {
                            $limitExceeded = true;

                            return 1;
                        }

                        return 0;
                    },
                ];
                if (! $ipLiteral) {
                    $secureCurlOptions[CURLOPT_RESOLVE] = [$target->host.':'.$target->port.':'.$pinnedIp];
                }
                if (defined('CURLOPT_ENCODING')) {
                    $secureCurlOptions[CURLOPT_ENCODING] = 'identity';
                }
                $callerCurlOptions = is_array($options['curl'] ?? null) ? $options['curl'] : [];
                foreach ([
                    'CURLOPT_ALTSVC',
                    'CURLOPT_ALTSVC_CTRL',
                    'CURLOPT_ABSTRACT_UNIX_SOCKET',
                    'CURLOPT_CONNECT_TO',
                    'CURLOPT_RESOLVE',
                    'CURLOPT_UNIX_SOCKET_PATH',
                    'CURLOPT_HTTPHEADER',
                ] as $constant) {
                    if (defined($constant)) {
                        unset($callerCurlOptions[constant($constant)]);
                    }
                }
                $options['curl'] = array_replace(
                    $callerCurlOptions,
                    $secureCurlOptions,
                );

                try {
                    $result = $handler($request, $options);

                    return self::guardHandlerResult(
                        $result,
                        $maxBytes,
                        $streamingRequested,
                        $limitExceeded,
                        $policyFailureReason,
                    );
                } catch (\Throwable $exception) {
                    self::throwNormalizedFailure($exception, $limitExceeded, $policyFailureReason);
                }
            };
        };
    }

    private static function guardHandlerResult(
        mixed $result,
        int $maxBytes,
        bool $streaming,
        bool &$limitExceeded,
        ?string &$policyFailureReason,
    ): mixed {
        if ($result instanceof PromiseInterface) {
            return $result->then(
                static function (mixed $response) use ($maxBytes, $streaming): mixed {
                    try {
                        return $response instanceof ResponseInterface
                            ? self::guardResponse($response, $maxBytes, $streaming)
                            : $response;
                    } catch (\Throwable $exception) {
                        self::throwNormalizedFailure($exception, false, null);
                    }
                },
                static function (mixed $reason) use (&$limitExceeded, &$policyFailureReason): never {
                    self::throwNormalizedFailure($reason, $limitExceeded, $policyFailureReason);
                },
            );
        }

        return $result instanceof ResponseInterface
            ? self::guardResponse($result, $maxBytes, $streaming)
            : $result;
    }

    private static function guardResponse(ResponseInterface $response, int $maxBytes, bool $streaming): ResponseInterface
    {
        self::assertIdentityEncodedResponse($response);
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

    private static function assertIdentityEncodedResponse(ResponseInterface $response): void
    {
        $encoding = strtolower(trim($response->getHeaderLine('Content-Encoding')));
        if ($encoding !== '' && $encoding !== 'identity') {
            throw new OutboundRequestBlockedException('encoded_response_forbidden');
        }
    }

    /** @return array<string, mixed> */
    private static function identityEncodingHeaders(mixed $headers): array
    {
        $safeHeaders = [];
        foreach (is_array($headers) ? $headers : [] as $name => $value) {
            if (strcasecmp((string) $name, 'Accept-Encoding') !== 0) {
                $safeHeaders[(string) $name] = $value;
            }
        }
        $safeHeaders['Accept-Encoding'] = 'identity';

        return $safeHeaders;
    }

    private static function throwNormalizedFailure(mixed $exception, bool $limitExceeded, ?string $policyFailureReason): never
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

    private static function hasPinnableCurlTransport(bool $requiresDnsPin): bool
    {
        if (
            ! defined('CURLOPT_CUSTOMREQUEST')
            || ! defined('CURLOPT_XFERINFOFUNCTION')
            || ($requiresDnsPin && ! defined('CURLOPT_RESOLVE'))
            || ! function_exists('curl_version')
        ) {
            return false;
        }

        $curlInfo = curl_version();
        $version = is_array($curlInfo) ? ($curlInfo['version'] ?? '0') : '0';

        return version_compare((string) $version, '7.21.2', '>=')
            && (function_exists('curl_exec') || function_exists('curl_multi_exec'));
    }

    /** @param array<string, mixed> $options */
    private static function responseLimit(RequestInterface $request, array $options): int
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
}
