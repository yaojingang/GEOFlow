<?php

namespace App\Services\GeoFlow\KnowledgeFacts;

use App\Ai\Agents\KnowledgeFactGeneratorAgent;
use App\Models\AiModel;
use App\Services\AiWorkspace\AiWorkspaceModelReadiness;
use App\Services\GeoFlow\AiUsageQuotaService;
use App\Support\GeoFlow\ApiKeyCrypto;
use App\Support\GeoFlow\OpenAiRuntimeProvider;
use RuntimeException;
use Throwable;

class KnowledgeFactAiGenerator
{
    public function __construct(private readonly ApiKeyCrypto $crypto, private readonly AiUsageQuotaService $quota, private readonly AiWorkspaceModelReadiness $readiness) {}

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
            $facts = array_values(array_filter($facts, static function (mixed $fact) use ($allowed): bool {
                if (! is_array($fact) || mb_strlen(json_encode($fact, JSON_UNESCAPED_UNICODE) ?: '') > 12000) {
                    return false;
                }
                $keys = is_array($fact['evidence_keys'] ?? null) ? $fact['evidence_keys'] : [];

                return $keys !== [] && array_diff($keys, $allowed) === [] && preg_match('/\A[a-z0-9][a-z0-9._-]{0,159}\z/', (string) ($fact['stable_key'] ?? '')) === 1;
            }));
            $this->quota->recordModelSuccess($reservation);
            $finalized = true;
            try {
                $profile = (array) $model->ai_workspace_readiness_profile;
                $profile['knowledge_fact_structured_output'] = ['status' => 'ready', 'observed' => true, 'last_success_at' => now()->toIso8601String(), 'configuration_fingerprint' => $this->readiness->configurationFingerprint($model)];
                $model->forceFill(['ai_workspace_readiness_profile' => $profile])->save();
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
}
