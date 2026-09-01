<?php

namespace App\Services\GeoFlow\KnowledgeFacts;

use App\Ai\Agents\KnowledgeFactGeneratorAgent;
use App\Models\AiModel;
use App\Services\AiWorkspace\AiWorkspaceModelReadiness;
use App\Services\GeoFlow\AiUsageQuotaService;
use App\Support\GeoFlow\ApiKeyCrypto;
use App\Support\GeoFlow\OpenAiRuntimeProvider;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

class KnowledgeFactAiGenerator
{
    public function __construct(
        private readonly ApiKeyCrypto $crypto,
        private readonly AiUsageQuotaService $quota,
        private readonly AiWorkspaceModelReadiness $readiness,
        private readonly KnowledgeFactStableKeyPolicy $stableKeyPolicy,
    ) {}

    /** @param list<array<string,string>> $evidence @return list<array<string,mixed>> */
    public function generate(AiModel $model, array $evidence, int $count): array
    {
        if (data_get($model->ai_workspace_readiness_profile, 'knowledge_fact_structured_output.status') === 'unsupported') {
            throw new RuntimeException('knowledge_fact_structured_output_unsupported');
        }
        $reservation = $this->quota->reserveModel($model);
        if ($reservation === null) {
            throw new RuntimeException('ai_quota_exhausted');
        }
        $requested = false;
        $finalized = false;
        try {
            $baseUrl = OpenAiRuntimeProvider::resolveChatBaseUrl((string) $model->api_url);
            $key = $this->crypto->decrypt((string) $model->getRawOriginal('api_key'));
            if ($baseUrl === '' || $key === '') {
                $this->quota->releaseModel($reservation);
                throw new RuntimeException('ai_model_configuration_invalid');
            }
            $provider = OpenAiRuntimeProvider::registerProvider('knowledge_facts', OpenAiRuntimeProvider::resolveChatDriver($baseUrl, (string) $model->model_id), $baseUrl, $key);
            $prompt = "最多提取 {$count} 条事实。只使用以下 JSON 证据：\n".json_encode($evidence, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            $requested = true;
            $response = (new KnowledgeFactGeneratorAgent)->prompt($prompt, [], $provider, (string) $model->model_id, 150);
            $facts = is_array($response->structured['facts'] ?? null) ? array_slice($response->structured['facts'], 0, $count) : [];
            $allowed = array_column($evidence, 'evidence_key');
            $facts = array_values(array_filter(array_map(fn (mixed $fact): ?array => $this->normalizeCandidate($fact, $allowed), $facts)));
            $this->quota->recordModelSuccess($reservation);
            $finalized = true;
            try {
                DB::transaction(function () use ($model): void {
                    $current = AiModel::query()->whereKey($model->id)->lockForUpdate()->first();
                    if (! $current || ! hash_equals($this->readiness->configurationFingerprint($model), $this->readiness->configurationFingerprint($current))) {
                        return;
                    }
                    $profile = (array) $current->ai_workspace_readiness_profile;
                    $profile['knowledge_fact_structured_output'] = ['status' => 'ready', 'observed' => true, 'last_success_at' => now()->toIso8601String(), 'configuration_fingerprint' => $this->readiness->configurationFingerprint($current)];
                    $current->forceFill(['ai_workspace_readiness_profile' => $profile])->save();
                }, 3);
            } catch (Throwable $exception) {
                report($exception);
            }

            return $facts;
        } catch (Throwable $exception) {
            if ($requested && ! $finalized) {
                $this->quota->recordModelAttempt($reservation);
            }
            throw $exception;
        }
    }

    /** @param list<string> $allowed @return array<string,mixed>|null */
    private function normalizeCandidate(mixed $fact, array $allowed): ?array
    {
        if (! is_array($fact) || mb_strlen(json_encode($fact, JSON_UNESCAPED_UNICODE) ?: '') > 12000) {
            return null;
        }
        $limits = ['stable_key' => 160, 'label' => 255, 'subject' => 255, 'predicate' => 255, 'canonical_value' => 2000, 'canonical_answer' => 5000, 'unit' => 64];
        foreach ($limits as $field => $limit) {
            if (! is_string($fact[$field] ?? null) || mb_strlen($fact[$field]) > $limit) {
                return null;
            }
        }
        if (preg_match('/\A[a-z0-9][a-z0-9._-]{0,159}\z/', $fact['stable_key']) !== 1
            || ! in_array((string) ($fact['value_type'] ?? ''), ['string', 'integer', 'decimal', 'number', 'date', 'boolean', 'url'], true)) {
            return null;
        }
        $fact['stable_key'] = $this->stableKeyPolicy->normalize(
            $fact['stable_key'],
            $fact['subject'],
            $fact['predicate'],
            $fact['label'],
        );
        if (in_array($fact['value_type'], ['integer', 'decimal', 'number'], true)
            && preg_match('/\A-?(?:0|[1-9]\d*)(?:\.\d+)?\z/', $fact['canonical_value']) !== 1) {
            return null;
        }
        $keys = array_values(array_unique(array_filter((array) ($fact['evidence_keys'] ?? []), 'is_string')));
        if ($keys === [] || count($keys) > 20 || array_diff($keys, $allowed) !== []) {
            return null;
        }
        $fact['evidence_keys'] = $keys;
        $fact['temporal_kind'] = in_array((string) ($fact['temporal_kind'] ?? ''), ['timeless', 'observed', 'interval'], true) ? $fact['temporal_kind'] : 'timeless';
        foreach (['valid_from', 'valid_to', 'observed_at', 'scope_entity', 'scope_region', 'scope_channel', 'statistic_definition', 'comparison_tolerance'] as $field) {
            $fact[$field] = is_string($fact[$field] ?? null) ? mb_substr(trim($fact[$field]), 0, 255) : '';
        }
        foreach (['valid_from', 'valid_to'] as $field) {
            if ($fact[$field] !== '' && preg_match('/\A\d{4}-\d{2}-\d{2}\z/', $fact[$field]) !== 1) {
                return null;
            }
        }
        if ($fact['observed_at'] !== '' && strtotime($fact['observed_at']) === false) {
            return null;
        }

        return $fact;
    }
}
