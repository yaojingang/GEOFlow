<?php

namespace App\Services\GeoFlow;

use App\Models\AiModel;
use App\Support\GeoFlow\OpenAiRuntimeProvider;

final class KnowledgeEmbeddingModelFingerprint
{
    public function forModel(AiModel $model): string
    {
        $baseUrl = OpenAiRuntimeProvider::resolveEmbeddingBaseUrl((string) ($model->api_url ?? ''));
        $provider = strtolower((string) (parse_url($baseUrl, PHP_URL_HOST) ?: ''));
        $driver = OpenAiRuntimeProvider::resolveEmbeddingDriver($baseUrl, (string) $model->model_id);
        $identifier = strtolower(trim((string) $model->model_id));

        if ($provider === '' || $identifier === '') {
            return '';
        }

        return hash('sha256', implode('|', [$driver, $provider, $identifier]));
    }

    public function provider(AiModel $model): string
    {
        $baseUrl = OpenAiRuntimeProvider::resolveEmbeddingBaseUrl((string) ($model->api_url ?? ''));

        return strtolower((string) (parse_url($baseUrl, PHP_URL_HOST) ?: ''));
    }
}
