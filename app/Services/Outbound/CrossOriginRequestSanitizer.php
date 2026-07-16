<?php

namespace App\Services\Outbound;

use GuzzleHttp\Psr7\Uri;
use Illuminate\Http\Client\PendingRequest;
use Psr\Http\Message\RequestInterface;

final class CrossOriginRequestSanitizer
{
    public static function pendingRequest(PendingRequest $request, string $method, string $targetUrl): PendingRequest
    {
        $headers = $request->getOptions()['headers'] ?? [];
        $cleared = [];
        foreach (is_array($headers) ? $headers : [] as $name => $value) {
            if (! in_array(strtolower((string) $name), self::allowedHeaders($method), true)) {
                $cleared[(string) $name] = '';
            }
        }

        $query = parse_url($targetUrl, PHP_URL_QUERY);

        return $request
            ->withOptions([
                'auth' => null,
                'cookies' => false,
                'cert' => null,
                'ssl_key' => null,
                'query' => is_string($query) ? $query : '',
            ])
            ->replaceHeaders($cleared);
    }

    public static function finalRequest(
        RequestInterface $request,
        string $method,
        string $targetUrl,
        bool $crossOrigin,
    ): RequestInterface {
        $uri = new Uri($targetUrl);
        if (! $crossOrigin) {
            $uri = $uri->withQuery($request->getUri()->getQuery());
        }
        $request = $request
            ->withMethod($method)
            ->withUri($uri, false);

        if ($crossOrigin) {
            foreach ($request->getHeaders() as $name => $values) {
                if (! in_array(strtolower($name), self::allowedHeaders($method), true)) {
                    $request = $request->withoutHeader($name);
                }
            }
        }

        return $request
            ->withHeader('Host', $uri->getAuthority())
            ->withHeader('Accept-Encoding', 'identity');
    }

    /** @return list<string> */
    private static function allowedHeaders(string $method): array
    {
        $allowed = ['accept', 'accept-encoding', 'accept-language', 'host', 'user-agent'];
        if (! in_array(strtoupper($method), ['GET', 'HEAD'], true)) {
            $allowed = [
                ...$allowed,
                'content-encoding',
                'content-language',
                'content-length',
                'content-type',
                'expect',
                'transfer-encoding',
            ];
        }

        return $allowed;
    }
}
