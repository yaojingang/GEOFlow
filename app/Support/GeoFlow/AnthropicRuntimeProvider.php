<?php

namespace App\Support\GeoFlow;

use App\Models\AiModel;

final class AnthropicRuntimeProvider
{
    public const VERSION_FLAG = 'anthropic-compatible';

    public static function isAnthropicCompatible(AiModel $model): bool
    {
        $version = mb_strtolower(trim((string) ($model->version ?? '')), 'UTF-8');
        if (str_contains($version, 'anthropic')) {
            return true;
        }

        $apiUrl = mb_strtolower(trim((string) ($model->api_url ?? '')), 'UTF-8');

        return str_ends_with($apiUrl, '/messages') || str_ends_with($apiUrl, '/v1/messages');
    }

    public static function resolveMessagesEndpoint(string $apiUrl): string
    {
        $normalized = trim($apiUrl);
        if ($normalized === '') {
            return '';
        }

        $normalized = rtrim($normalized, '/');
        if (preg_match('#/(v\d+/)?messages$#', $normalized) === 1) {
            return $normalized;
        }

        $path = (string) (parse_url($normalized, PHP_URL_PATH) ?? '');
        if ($path === '' || $path === '/') {
            return $normalized.'/v1/messages';
        }

        return $normalized.'/messages';
    }

    /**
     * @return array<string, string>
     */
    public static function headers(): array
    {
        return [
            'anthropic-version' => '2023-06-01',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function buildPayload(
        string $modelId,
        string $userPrompt,
        string $systemPrompt = '',
        int $maxTokens = 1200,
        float $temperature = 0.2
    ): array {
        $payload = [
            'model' => $modelId,
            'messages' => [
                [
                    'role' => 'user',
                    'content' => $userPrompt,
                ],
            ],
            'max_tokens' => $maxTokens,
            'temperature' => $temperature,
        ];

        if (trim($systemPrompt) !== '') {
            $payload['system'] = $systemPrompt;
        }

        return $payload;
    }

    public static function extractResponseText(mixed $json, string $body): string
    {
        if (is_array($json)) {
            $content = $json['content'] ?? null;
            if (is_string($content) || is_numeric($content)) {
                return trim((string) $content);
            }

            if (is_array($content)) {
                $segments = [];
                foreach ($content as $part) {
                    if (is_string($part) || is_numeric($part)) {
                        $segments[] = (string) $part;

                        continue;
                    }

                    if (is_array($part)) {
                        $segments[] = self::stringifyContentPart($part['text'] ?? $part['content'] ?? '');
                    }
                }

                $text = trim(implode('', array_filter($segments, static fn (string $segment): bool => $segment !== '')));
                if ($text !== '') {
                    return $text;
                }
            }

            if (isset($json['completion']) && (is_string($json['completion']) || is_numeric($json['completion']))) {
                return trim((string) $json['completion']);
            }
        }

        return OpenAiRuntimeProvider::normalizeGeneratedText($body);
    }

    private static function stringifyContentPart(mixed $content): string
    {
        if (is_string($content) || is_numeric($content)) {
            return (string) $content;
        }

        if (! is_array($content)) {
            return '';
        }

        $text = '';
        foreach ($content as $part) {
            if (is_string($part) || is_numeric($part)) {
                $text .= (string) $part;

                continue;
            }

            if (is_array($part)) {
                $text .= self::stringifyContentPart($part['text'] ?? $part['content'] ?? '');
            }
        }

        return $text;
    }
}
