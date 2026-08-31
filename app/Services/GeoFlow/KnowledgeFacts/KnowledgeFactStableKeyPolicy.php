<?php

namespace App\Services\GeoFlow\KnowledgeFacts;

final class KnowledgeFactStableKeyPolicy
{
    public function normalize(string $stableKey, string $subject, string $predicate, string $label = ''): string
    {
        $stableKey = mb_strtolower(trim($stableKey));
        if (preg_match('/\A(?:fact|item|atomic)[._-]?\d+\z/i', $stableKey) !== 1) {
            return $stableKey;
        }

        $identity = implode('|', [
            $this->semanticIdentity($subject),
            $this->semanticIdentity($predicate),
            $this->semanticIdentity($label),
        ]);

        return 'fact.'.substr(hash('sha256', $identity), 0, 24);
    }

    private function semanticIdentity(string $value): string
    {
        return mb_strtolower((string) preg_replace('/[\s\p{P}\p{S}]+/u', '', trim($value)));
    }
}
