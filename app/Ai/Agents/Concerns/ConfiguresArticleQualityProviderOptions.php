<?php

namespace App\Ai\Agents\Concerns;

use Laravel\Ai\Enums\Lab;

trait ConfiguresArticleQualityProviderOptions
{
    private const ARTICLE_QUALITY_ISSUE_CODES = [
        'knowledge_contradiction', 'data_mismatch', 'unsupported_claim', 'citation_missing',
        'citation_scope_mismatch', 'ad_absolute_claim', 'ad_false_or_misleading',
        'ad_industry_specific', 'ad_identifiability', 'content_integrity',
        'source_declared_unverified',
    ];

    /** @return array<string, mixed> */
    public function providerOptions(Lab|string $provider): array
    {
        $providerKey = $provider instanceof Lab ? $provider->value : $provider;

        if ($providerKey !== Lab::DeepSeek->value) {
            return [];
        }

        return [
            'max_tokens' => $this->outputTokenLimit,
            'thinking' => ['type' => 'disabled'],
        ];
    }
}
