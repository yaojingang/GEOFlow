<?php

namespace App\Services\Admin;

use App\Models\AiModelUsageAttemptStart;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class AiModelUsageAttemptStartRecorder
{
    private const ALLOWED_FIELDS = [
        'call_key', 'operation', 'business_source', 'source_type', 'source_id',
    ];

    /** @param array<string, mixed> $identity */
    public function record(AiModelUsageAccessSnapshot $snapshot, array $identity): AiModelUsageAttemptStart
    {
        $whitelisted = Arr::only($identity, self::ALLOWED_FIELDS);
        if (is_int($whitelisted['source_id'] ?? null)) {
            $whitelisted['source_id'] = (string) $whitelisted['source_id'];
        }
        $attributes = Validator::make($whitelisted, [
            'call_key' => ['required', 'string', 'max:100', 'regex:/\A[a-z0-9_.:-]+\z/i'],
            'operation' => ['required', 'string', 'max:100', 'regex:/\A[a-z0-9_.:-]+\z/i'],
            'business_source' => ['required', 'string', 'max:80', 'regex:/\A[a-z0-9_.:-]+\z/i'],
            'source_type' => ['nullable', 'string', 'max:255', 'required_with:source_id', 'regex:/\A[A-Za-z0-9_.:\\\\-]+\z/'],
            'source_id' => ['nullable', 'string', 'max:120', 'required_with:source_type', 'regex:/\A[a-z0-9_.:-]+\z/i'],
        ])->validate();
        $attributes = [...$snapshot->toUsageAttributes(), ...$attributes];
        $fingerprint = $this->fingerprint($attributes);

        DB::table((new AiModelUsageAttemptStart)->getTable())->insertOrIgnore([
            'event_uuid' => (string) Str::uuid(),
            'payload_fingerprint' => $fingerprint,
            ...$attributes,
            'created_at' => now(),
        ]);

        $existing = AiModelUsageAttemptStart::query()
            ->where('request_id', $attributes['request_id'])
            ->where('call_key', $attributes['call_key'])
            ->first();
        if (! $existing instanceof AiModelUsageAttemptStart) {
            throw ValidationException::withMessages(['call_key' => ['ai_usage_attempt_start_insert_failed']]);
        }
        if (! hash_equals((string) $existing->payload_fingerprint, $fingerprint)) {
            throw ValidationException::withMessages(['call_key' => ['ai_usage_attempt_start_idempotency_conflict']]);
        }

        return $existing;
    }

    /** @param array<string, mixed> $attributes */
    private function fingerprint(array $attributes): string
    {
        ksort($attributes);

        return hash('sha256', json_encode(
            $attributes,
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
        ));
    }
}
