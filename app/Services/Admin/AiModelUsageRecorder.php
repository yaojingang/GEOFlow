<?php

namespace App\Services\Admin;

use App\Models\AiModel;
use App\Models\AiModelUsageEvent;
use Illuminate\Database\QueryException;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

final class AiModelUsageRecorder
{
    private const ALLOWED_FIELDS = [
        'request_id',
        'call_key',
        'operation',
        'ai_model_id',
        'config_owner_admin_id',
        'execution_admin_id',
        'execution_scope',
        'model_source',
        'business_source',
        'source_type',
        'source_id',
        'status',
        'error_code',
        'input_tokens',
        'output_tokens',
        'total_tokens',
        'estimated_cost',
    ];

    /** @param array<string, mixed> $payload */
    public function record(array $payload): AiModelUsageEvent
    {
        $whitelisted = Arr::only($payload, self::ALLOWED_FIELDS);
        if (is_int($whitelisted['source_id'] ?? null)) {
            $whitelisted['source_id'] = (string) $whitelisted['source_id'];
        }

        $attributes = Validator::make($whitelisted, [
            'request_id' => ['required', 'string', 'max:120'],
            'call_key' => ['required', 'string', 'max:100', 'regex:/\A[a-z0-9_.:-]+\z/i'],
            'operation' => ['required', 'string', 'max:100', 'regex:/\A[a-z0-9_.:-]+\z/i'],
            'ai_model_id' => ['required', 'integer', 'exists:ai_models,id'],
            'config_owner_admin_id' => ['required', 'integer', 'exists:admins,id'],
            'execution_admin_id' => ['nullable', 'integer', 'exists:admins,id'],
            'execution_scope' => ['required', Rule::in([
                AiModelUsageEvent::EXECUTION_SCOPE_INTERACTIVE_ADMIN,
                AiModelUsageEvent::EXECUTION_SCOPE_PERSISTED_ADMIN,
                AiModelUsageEvent::EXECUTION_SCOPE_SYSTEM,
            ])],
            'model_source' => ['required', Rule::in([
                AiModelUsageEvent::MODEL_SOURCE_PERSONAL,
                AiModelUsageEvent::MODEL_SOURCE_SHARED,
                AiModelUsageEvent::MODEL_SOURCE_SYSTEM,
            ])],
            'business_source' => ['required', 'string', 'max:80', 'regex:/\A[a-z0-9_.:-]+\z/i'],
            'source_type' => ['nullable', 'string', 'max:255', 'required_with:source_id'],
            'source_id' => ['nullable', 'string', 'max:120', 'required_with:source_type'],
            'status' => ['required', Rule::in([
                AiModelUsageEvent::STATUS_STARTED,
                AiModelUsageEvent::STATUS_SUCCEEDED,
                AiModelUsageEvent::STATUS_FAILED,
                AiModelUsageEvent::STATUS_DISCARDED,
                AiModelUsageEvent::STATUS_REVOKED,
            ])],
            'error_code' => ['nullable', 'string', 'max:100', 'regex:/\A[a-z0-9_.:-]+\z/i'],
            'input_tokens' => ['nullable', 'integer', 'min:0'],
            'output_tokens' => ['nullable', 'integer', 'min:0'],
            'total_tokens' => ['nullable', 'integer', 'min:0'],
            'estimated_cost' => ['nullable', 'numeric', 'min:0'],
        ])->validate();

        $this->assertOwnerMatchesModel($attributes);
        $this->assertAttributionIsConsistent($attributes);
        $payloadFingerprint = $this->payloadFingerprint($attributes);

        $existing = $this->findExisting($attributes);
        if ($existing instanceof AiModelUsageEvent) {
            $this->assertIdempotentMatch($existing, $payloadFingerprint);

            return $existing;
        }

        $event = new AiModelUsageEvent;
        $event->forceFill([
            'event_uuid' => (string) Str::uuid(),
            'payload_fingerprint' => $payloadFingerprint,
            ...$attributes,
        ]);

        try {
            $event->save();
        } catch (QueryException $exception) {
            $existing = $this->findExisting($attributes);
            if (! $existing instanceof AiModelUsageEvent) {
                throw $exception;
            }
            $this->assertIdempotentMatch($existing, $payloadFingerprint);

            return $existing;
        }

        return $event->refresh();
    }

    /** @param array<string, mixed> $attributes */
    private function assertOwnerMatchesModel(array $attributes): void
    {
        $matches = AiModel::query()
            ->whereKey((int) $attributes['ai_model_id'])
            ->where('owner_admin_id', (int) $attributes['config_owner_admin_id'])
            ->exists();

        if (! $matches) {
            throw ValidationException::withMessages([
                'config_owner_admin_id' => ['The configuration owner does not own the selected AI model.'],
            ]);
        }
    }

    /** @param array<string, mixed> $attributes */
    private function findExisting(array $attributes): ?AiModelUsageEvent
    {
        return AiModelUsageEvent::query()
            ->where('request_id', $attributes['request_id'])
            ->where('call_key', $attributes['call_key'])
            ->first();
    }

    /** @param array<string, mixed> $attributes */
    private function assertAttributionIsConsistent(array $attributes): void
    {
        $isSystem = $attributes['execution_scope'] === AiModelUsageEvent::EXECUTION_SCOPE_SYSTEM;
        $hasExecutor = $attributes['execution_admin_id'] !== null;
        $usesSystemModel = $attributes['model_source'] === AiModelUsageEvent::MODEL_SOURCE_SYSTEM;
        $ownerIsExecutor = $hasExecutor
            && (int) $attributes['execution_admin_id'] === (int) $attributes['config_owner_admin_id'];
        $valid = $isSystem
            ? ! $hasExecutor && $usesSystemModel
            : $hasExecutor
                && ! $usesSystemModel
                && ($attributes['model_source'] === AiModelUsageEvent::MODEL_SOURCE_PERSONAL
                    ? $ownerIsExecutor
                    : ! $ownerIsExecutor);

        if (! $valid) {
            throw ValidationException::withMessages([
                'execution_scope' => ['The execution identity does not match the selected model source.'],
            ]);
        }
    }

    /** @param array<string, mixed> $attributes */
    private function payloadFingerprint(array $attributes): string
    {
        ksort($attributes);

        return hash('sha256', json_encode(
            $attributes,
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
        ));
    }

    private function assertIdempotentMatch(AiModelUsageEvent $existing, string $payloadFingerprint): void
    {
        if (hash_equals((string) $existing->payload_fingerprint, $payloadFingerprint)) {
            return;
        }

        throw ValidationException::withMessages([
            'call_key' => ['The request and call key are already associated with different usage metadata.'],
        ]);
    }
}
