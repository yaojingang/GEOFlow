<?php

namespace App\Services\Admin;

use App\Models\AiModelUsageEvent;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class AiModelUsageAttemptReconciler
{
    public function reconcile(int $olderThanSeconds = 900, int $batchSize = 200): int
    {
        $reconciled = 0;
        DB::table('ai_model_usage_attempt_starts as starts')
            ->leftJoin('ai_model_usage_events as outcomes', function ($join): void {
                $join->on('outcomes.request_id', '=', 'starts.request_id')
                    ->on('outcomes.call_key', '=', 'starts.call_key');
            })
            ->whereNull('outcomes.id')
            ->where('starts.created_at', '<=', now()->subSeconds(max(1, $olderThanSeconds)))
            ->select('starts.*')
            ->orderBy('starts.id')
            ->chunkById(max(1, $batchSize), function ($starts) use (&$reconciled): void {
                foreach ($starts as $start) {
                    $attributes = [
                        'request_id' => $start->request_id,
                        'request_payload_digest' => $start->request_payload_digest,
                        'ai_model_id' => $start->ai_model_id,
                        'config_owner_admin_id' => $start->config_owner_admin_id,
                        'execution_admin_id' => $start->execution_admin_id,
                        'ai_config_access_version' => $start->ai_config_access_version,
                        'execution_scope' => $start->execution_scope,
                        'model_source' => $start->model_source,
                        'call_key' => $start->call_key,
                        'operation' => $start->operation,
                        'business_source' => $start->business_source,
                        'source_type' => $start->source_type,
                        'source_id' => $start->source_id,
                        'status' => AiModelUsageEvent::STATUS_FAILED,
                        'error_code' => 'ai_usage_outcome_missing',
                        'input_tokens' => null,
                        'output_tokens' => null,
                        'total_tokens' => null,
                        'estimated_cost' => null,
                    ];
                    ksort($attributes);
                    $reconciled += DB::table('ai_model_usage_events')->insertOrIgnore([
                        'event_uuid' => (string) Str::uuid(),
                        'payload_fingerprint' => hash('sha256', json_encode(
                            $attributes,
                            JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
                        )),
                        ...$attributes,
                        'created_at' => now(),
                    ]);
                }
            }, 'starts.id', 'id');

        return $reconciled;
    }
}
