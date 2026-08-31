<?php

namespace App\Services\GeoFlow;

use App\Models\AiQualityAuditEvent;
use Illuminate\Support\Str;

class AiQualityAuditService
{
    /** @param array<string,mixed> $attributes */
    public function record(string $eventType, array $attributes = []): AiQualityAuditEvent
    {
        return AiQualityAuditEvent::query()->create([
            'event_uuid' => (string) Str::uuid(),
            'correlation_id' => $attributes['correlation_id'] ?? null,
            'event_type' => mb_substr($eventType, 0, 64),
            'occurred_at' => now(),
            'task_id' => $attributes['task_id'] ?? null,
            'article_id' => $attributes['article_id'] ?? null,
            'article_ai_quality_check_id' => $attributes['article_ai_quality_check_id'] ?? null,
            'admin_id' => $attributes['admin_id'] ?? null,
            'api_token_id' => $attributes['api_token_id'] ?? null,
            'authorization_result' => $attributes['authorization_result'] ?? 'allowed',
            'policy_version' => $attributes['policy_version'] ?? null,
            'before_hash' => $this->hash($attributes['before_hash'] ?? null),
            'after_hash' => $this->hash($attributes['after_hash'] ?? null),
            'basis_hash' => $this->hash($attributes['basis_hash'] ?? null),
            'reason_code' => isset($attributes['reason_code'])
                ? mb_substr((string) $attributes['reason_code'], 0, 80)
                : null,
            'metadata' => $this->safeMetadata((array) ($attributes['metadata'] ?? [])),
        ]);
    }

    private function hash(mixed $value): ?string
    {
        $value = trim((string) $value);

        return preg_match('/\A[a-f0-9]{64}\z/', $value) === 1 ? $value : null;
    }

    /** @param array<string,mixed> $metadata @return array<string,mixed> */
    private function safeMetadata(array $metadata): array
    {
        $safe = [];
        foreach (array_slice($metadata, 0, 24, true) as $key => $value) {
            if (! preg_match('/\A[a-z0-9_]{1,48}\z/', (string) $key)) {
                continue;
            }
            if (is_bool($value) || is_int($value) || is_float($value) || $value === null) {
                $safe[$key] = $value;
            } elseif (is_string($value)) {
                $safe[$key] = mb_substr($value, 0, 120);
            } elseif (is_array($value)) {
                $safe[$key] = array_slice(array_values(array_filter(
                    $value,
                    static fn (mixed $item): bool => is_scalar($item) || $item === null,
                )), 0, 20);
            }
        }

        return $safe;
    }
}
