<?php

namespace App\Console\Commands;

use App\Models\AiVisibilityRun;
use App\Models\AiVisibilityTopicKeyword;
use App\Services\GeoFlow\AiVisibility\AiVisibilityKeywordNormalizer;
use Illuminate\Console\Command;

class GeoFlowBackfillAiVisibilityKeywordHashes extends Command
{
    protected $signature = 'geoflow:ai-visibility:backfill-keyword-hashes';

    protected $description = 'Backfill keyword hashes for existing AI visibility runs and auto-classify registered topic matches';

    public function handle(): int
    {
        $sentinelHash = AiVisibilityKeywordNormalizer::hash('');
        $updated = 0;
        $matched = 0;
        $scanned = 0;

        AiVisibilityRun::query()
            ->whereNull('keyword_hash')
            ->select(['id', 'keyword', 'keyword_hash', 'ai_visibility_topic_id'])
            ->chunkById(500, function ($runs) use (&$updated, &$matched, &$scanned, $sentinelHash): void {
                foreach ($runs as $run) {
                    $scanned++;

                    $hash = AiVisibilityKeywordNormalizer::hash((string) $run->keyword);
                    if ((string) $run->keyword_hash === $hash) {
                        continue;
                    }

                    $run->keyword_hash = $hash;

                    if ($hash !== $sentinelHash && $run->ai_visibility_topic_id === null) {
                        $topicId = AiVisibilityTopicKeyword::query()
                            ->where('keyword_hash', $hash)
                            ->value('ai_visibility_topic_id');
                        if ($topicId !== null) {
                            $run->ai_visibility_topic_id = (int) $topicId;
                            $matched++;
                        }
                    }

                    $run->save();
                    $updated++;
                }
            });

        $this->info(sprintf(
            '%d runs updated, %d auto-classified out of %d scanned',
            $updated,
            $matched,
            $scanned,
        ));

        return self::SUCCESS;
    }
}
