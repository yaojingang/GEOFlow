<?php

namespace App\Services\GeoFlow\KnowledgeFacts;

use App\Data\Ai\KnowledgeFactGenerationRecoveryDispatch;
use App\Jobs\GenerateKnowledgeFactBatchJob;
use Illuminate\Bus\Batch;
use Illuminate\Support\Facades\Bus;

class KnowledgeFactGenerationRecoveryDispatcher
{
    public function dispatch(KnowledgeFactGenerationRecoveryDispatch $dispatch): ?string
    {
        if ($dispatch->finalizerOnly()) {
            app(KnowledgeFactGenerationFinalizerDispatcher::class)->dispatch(
                $dispatch->runId,
                $dispatch->executionAttempt,
                $dispatch->finalizerToken,
            );

            return null;
        }

        $jobs = collect($dispatch->batches)->map(function (array $batch) use ($dispatch): GenerateKnowledgeFactBatchJob {
            $job = new GenerateKnowledgeFactBatchJob(
                $dispatch->runId,
                $batch['sequence'],
                $batch['input_hash'],
                $batch['evidence'],
                $dispatch->executionAttempt,
                $batch['claim_token'],
            );
            $job->afterCommit();

            return $job;
        })->all();
        $runId = $dispatch->runId;
        $executionAttempt = $dispatch->executionAttempt;
        $finalizerToken = $dispatch->finalizerToken;
        $batch = Bus::batch($jobs)
            ->name("knowledge-facts:{$runId}:recovery:{$executionAttempt}")
            ->allowFailures()
            ->finally(static function (Batch $batch) use ($runId, $executionAttempt, $finalizerToken): void {
                app(KnowledgeFactGenerationFinalizerDispatcher::class)->dispatch(
                    $runId,
                    $executionAttempt,
                    $finalizerToken,
                );
            })
            ->onQueue('knowledge')
            ->dispatch();

        return (string) $batch->id;
    }
}
