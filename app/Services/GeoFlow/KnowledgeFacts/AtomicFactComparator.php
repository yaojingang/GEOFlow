<?php

namespace App\Services\GeoFlow\KnowledgeFacts;

class AtomicFactComparator
{
    /** @param array<string,mixed> $claim @param array<string,mixed> $standard @return array{result:string,decision:string,reason:string,method:string} */
    public function compare(array $claim, array $standard): array
    {
        $claimValue = $this->decimal($claim['value'] ?? null);
        $standardValue = $this->decimal($standard['value'] ?? null);
        if ($claimValue !== null && $standardValue !== null) {
            $claimNormalized = $claimValue * $this->unitMultiplier((string) ($claim['unit'] ?? ''));
            $standardNormalized = $standardValue * $this->unitMultiplier((string) ($standard['unit'] ?? ''));
            $tolerance = max(0.0, (float) ($standard['tolerance'] ?? 0));
            $matches = abs($claimNormalized - $standardNormalized) <= $tolerance;

            return $this->result($matches ? 'match' : 'mismatch', $matches ? 'numeric_match' : 'numeric_mismatch', 'numeric');
        }

        $claimText = $this->normalizeText((string) ($claim['text'] ?? ''));
        $standardText = $this->normalizeText((string) ($standard['answer'] ?? ''));
        if ($claimText === '' || $standardText === '') {
            return $this->result('not_covered', 'insufficient_comparable_value', 'coverage');
        }

        $type = (string) ($claim['type'] ?? $standard['type'] ?? 'text');
        $typed = match ($type) {
            'date' => $this->compareDate($claimText, $standardText),
            'version' => $this->compareVersion($claimText, $standardText),
            'path', 'url' => $this->comparePath($claimText, $standardText),
            'range' => $this->compareRange($claimText, $standardText),
            'boolean' => $this->compareBoolean($claimText, $standardText),
            default => null,
        };
        if ($typed !== null) {
            return $this->result($typed ? 'match' : 'mismatch', $typed ? $type.'_match' : $type.'_mismatch', $type);
        }

        $matches = $claimText === $standardText || str_contains($claimText, $standardText) || str_contains($standardText, $claimText);
        if (! $matches && $this->textSimilarity($claimText, $standardText) >= 0.74) {
            return $this->result('match', 'text_similarity_match', 'text_similarity');
        }

        return $this->result($matches ? 'match' : 'ambiguous', $matches ? 'text_match' : 'semantic_review_required', 'text');
    }

    private function textSimilarity(string $left, string $right): float
    {
        $normalize = static fn (string $value): string => mb_strtolower((string) preg_replace('/[\s\p{P}\p{S}]+/u', '', $value));
        $bigrams = static function (string $value) use ($normalize): array {
            $value = $normalize($value);
            $length = mb_strlen($value);
            if ($length < 2) {
                return $value === '' ? [] : [$value];
            }

            $grams = [];
            for ($index = 0; $index < $length - 1; $index++) {
                $grams[mb_substr($value, $index, 2)] = true;
            }

            return array_keys($grams);
        };
        $leftGrams = $bigrams($left);
        $rightGrams = $bigrams($right);
        $total = count($leftGrams) + count($rightGrams);

        return $total === 0 ? 0.0 : (2 * count(array_intersect($leftGrams, $rightGrams))) / $total;
    }

    private function decimal(mixed $value): ?float
    {
        if (! is_string($value) || preg_match('/\A-?(?:0|[1-9]\d*)(?:\.\d+)?\z/', trim($value)) !== 1) {
            return null;
        }

        return (float) $value;
    }

    private function unitMultiplier(string $unit): float
    {
        return match (mb_strtolower(trim($unit))) {
            '千', '千件', 'k' => 1000.0,
            '万', '万件', 'w' => 10000.0,
            '百万', 'million', 'm' => 1000000.0,
            '亿' => 100000000.0,
            '%', 'percent', 'percentage' => 0.01,
            default => 1.0,
        };
    }

    private function normalizeText(string $text): string
    {
        return mb_strtolower(trim($text));
    }

    /** @return array{result:string,decision:string,reason:string,method:string} */
    private function result(string $result, string $reason, string $method): array
    {
        return [
            'result' => $result,
            'decision' => $result === 'mismatch' ? 'blocked' : ($result === 'ambiguous' ? 'needs_review' : 'passed'),
            'reason' => $reason,
            'method' => $method,
        ];
    }

    private function compareDate(string $claim, string $standard): ?bool
    {
        $normalize = static function (string $value): ?string {
            preg_match('/(\d{4})\D+(\d{1,2})(?:\D+(\d{1,2}))?/', $value, $matches);

            return isset($matches[2]) ? sprintf('%04d-%02d-%02d', (int) $matches[1], (int) $matches[2], (int) ($matches[3] ?? 1)) : null;
        };

        $left = $normalize($claim);
        $right = $normalize($standard);

        return $left !== null && $right !== null ? $left === $right : null;
    }

    private function compareVersion(string $claim, string $standard): ?bool
    {
        $version = static function (string $value): ?string {
            preg_match('/v?(\d+(?:\.\d+){1,3})/i', $value, $matches);

            return $matches[1] ?? null;
        };
        $left = $version($claim);
        $right = $version($standard);

        return $left !== null && $right !== null ? hash_equals($left, $right) : null;
    }

    private function comparePath(string $claim, string $standard): ?bool
    {
        return rtrim($claim, '/') === rtrim($standard, '/');
    }

    private function compareRange(string $claim, string $standard): ?bool
    {
        $range = static function (string $value): ?array {
            preg_match('/(-?\d+(?:\.\d+)?)\D+(-?\d+(?:\.\d+)?)/u', $value, $matches);

            return isset($matches[2]) ? [(float) $matches[1], (float) $matches[2]] : null;
        };

        return $range($claim) === $range($standard);
    }

    private function compareBoolean(string $claim, string $standard): ?bool
    {
        $boolean = static fn (string $value): ?bool => match (true) {
            preg_match('/^(是|支持|启用|true|yes|1)$/iu', $value) === 1 => true,
            preg_match('/^(否|不支持|禁用|false|no|0)$/iu', $value) === 1 => false,
            default => null,
        };
        $left = $boolean($claim);
        $right = $boolean($standard);

        return $left !== null && $right !== null ? $left === $right : null;
    }
}
