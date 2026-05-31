<?php

namespace App\Services\GeoFlow;

class GenericHttpResponseMapper
{
    /**
     * @param  array<string,mixed>  $json
     * @param  array<string,mixed>  $config
     * @return array{remote_id:string,remote_url:string,remote_meta:array<string,mixed>}
     */
    public function map(array $json, array $config): array
    {
        $remoteId = $this->valueAtPath($json, (string) ($config['generic_remote_id_path'] ?? 'id'));
        $remoteUrl = $this->valueAtPath($json, (string) ($config['generic_remote_url_path'] ?? 'url'));

        return [
            'remote_id' => is_scalar($remoteId) ? (string) $remoteId : '',
            'remote_url' => is_scalar($remoteUrl) ? (string) $remoteUrl : '',
            'remote_meta' => [
                'generic_http_response' => $this->compactResponse($json),
            ],
        ];
    }

    /**
     * @param  array<string,mixed>  $json
     */
    public function valueAtPath(array $json, string $path): mixed
    {
        $path = trim($path);
        if ($path === '') {
            return null;
        }

        $current = $json;
        foreach (explode('.', $path) as $segment) {
            $segment = trim($segment);
            if ($segment === '' || ! is_array($current) || ! array_key_exists($segment, $current)) {
                return null;
            }
            $current = $current[$segment];
        }

        return $current;
    }

    /**
     * @param  array<string,mixed>  $json
     * @return array<string,mixed>
     */
    private function compactResponse(array $json): array
    {
        $encoded = json_encode($json, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (! is_string($encoded) || mb_strlen($encoded) <= 2000) {
            return $json;
        }

        return [
            'summary' => mb_substr($encoded, 0, 2000).'...',
        ];
    }
}
