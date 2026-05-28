<?php

namespace App\Support\Analytics;

final class TrafficClassifier
{
    public const HUMAN = 'human';

    public const SEARCH_BOT = 'search_bot';

    public const AI_BOT = 'ai_bot';

    public const OTHER_BOT = 'other_bot';

    public const UNKNOWN = 'unknown';

    /**
     * @return list<string>
     */
    public static function aiBotPatterns(): array
    {
        return [
            'gptbot',
            'chatgpt-user',
            'chatgpt',
            'oai-searchbot',
            'openai',
            'claudebot',
            'claude-searchbot',
            'claude-user',
            'anthropic',
            'perplexitybot',
            'perplexity-user',
            'perplexity',
            'ccbot',
            'google-extended',
            'applebot-extended',
            'bytespider',
            'meta-externalagent',
            'cohere-ai',
            'youbot',
        ];
    }

    /**
     * @return list<string>
     */
    public static function searchBotPatterns(): array
    {
        return [
            'googlebot',
            'bingbot',
            'baiduspider',
            'yandexbot',
            'duckduckbot',
            'sogou',
            'slurp',
            '360spider',
            'semrushbot',
            'ahrefsbot',
        ];
    }

    /**
     * @return list<string>
     */
    public static function otherBotPatterns(): array
    {
        return [
            'bot',
            'spider',
            'crawler',
            'curl',
            'wget',
            'python-requests',
            'go-http-client',
            'okhttp',
            'httpclient',
            'headlesschrome',
            'postmanruntime',
            'axios',
            'java/',
            'libwww-perl',
            'scrapy',
            'facebookexternalhit',
            'telegrambot',
            'whatsapp',
        ];
    }

    /**
     * @return list<string>
     */
    public static function nonHumanPatterns(): array
    {
        return [
            ...self::aiBotPatterns(),
            ...self::searchBotPatterns(),
            ...self::otherBotPatterns(),
        ];
    }

    public static function classify(?string $userAgent): string
    {
        $normalized = mb_strtolower(trim((string) $userAgent));

        if ($normalized === '') {
            return self::UNKNOWN;
        }

        if (self::matches($normalized, self::aiBotPatterns())) {
            return self::AI_BOT;
        }

        if (self::matches($normalized, self::searchBotPatterns())) {
            return self::SEARCH_BOT;
        }

        if (self::matches($normalized, self::otherBotPatterns())) {
            return self::OTHER_BOT;
        }

        return self::HUMAN;
    }

    /**
     * @param  list<string>  $patterns
     */
    private static function matches(string $userAgent, array $patterns): bool
    {
        foreach ($patterns as $pattern) {
            if ($pattern !== '' && str_contains($userAgent, $pattern)) {
                return true;
            }
        }

        return false;
    }
}
