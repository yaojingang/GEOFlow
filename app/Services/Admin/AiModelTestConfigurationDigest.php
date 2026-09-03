<?php

namespace App\Services\Admin;

use App\Models\AiModel;

final class AiModelTestConfigurationDigest
{
    public static function forModel(AiModel $model): string
    {
        return hash('sha256', json_encode([
            'owner_admin_id' => (int) $model->owner_admin_id,
            'access_scope' => (string) $model->access_scope,
            'status' => (string) $model->status,
            'archived_at' => $model->archived_at?->toISOString(),
            'version' => (string) $model->version,
            'model_type' => (string) $model->model_type,
            'api_url' => (string) $model->api_url,
            'model_id' => (string) $model->model_id,
            'api_key_digest' => hash('sha256', (string) $model->getRawOriginal('api_key')),
            'max_tokens' => $model->max_tokens === null ? null : (int) $model->max_tokens,
            'daily_limit' => (int) $model->daily_limit,
        ], JSON_THROW_ON_ERROR));
    }
}
